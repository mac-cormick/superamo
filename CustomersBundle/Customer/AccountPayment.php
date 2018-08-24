<?php
/**
 * Created by PhpStorm.
 * User: sromanenko
 * Date: 08.08.18
 * Time: 15:36
 */

namespace CustomersBundle\Customer;

use CustomersBundle\Tool\CustomerUpdateTool;
use Phase\AmoCRM_API;
use Amo\Crm\SupportApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Amo\Crm\ApiException;
use DateTime;

Class AccountPayment {

    /** @var OutputInterface */
    private $output;

    /** @var AmoCRM_API $api*/
    private $api;

    /** @var SupportApiClient $supportApi*/
    private $supportApi;

    /** @var CustomerUpdateTool $updateTool*/
    private $updateTool;

    /** @var LoggerInterface $logger*/
    private $logger;

    /**
     * AccountPayment constructor.
     * @param AmoCRM_API $api
     * @param SupportApiClient $supportApi
     * @param CustomerUpdateTool $updateTool
     * @param $logger
     */
    public function __construct(
        AmoCRM_API $api,
        SupportApiClient $supportApi,
        CustomerUpdateTool $updateTool,
        LoggerInterface $logger,
        OutputInterface $output
    ) {
        $this->api = $api;
        $this->supportApi = $supportApi;
        $this->updateTool = $updateTool;
        $this->logger = $logger;
        $this->output = $output;
    }

    public function handleStatusChange($data, $event)
    {
        if(empty($event['account_id'])){
            $this->logger->error('Empty account_id key in event',$event);
            return;
        }
        $account_id = $event['account_id'];

        $customer = $this->updateTool->findCustomerByAccountId($account_id);

        if(empty($customer)){
            $this->logger->debug('Customer not found');
            $this->log('Customer not found');
            return;
        }

        $this->log('Customer found:',$customer);

        try{
            $orders = $this->supportApi->getOrders($account_id);
        }catch(ApiException $e){
            $this->logger->critical('Cannot retrieve account orders: '.$e->getMessage());
            throw $e;
        }

        $payed_in_advance = array_filter($orders,function($order){
            return $order['status_id'] === 'P';
        });

        $this->log(sprintf('Account is %s in advance',empty($payed_in_advance) ? 'not payed' : 'payed'));
        $changed_fields = [
            'payed_in_advance' => !empty($payed_in_advance),
        ];

        $update = $this->updateTool->makeCustomerCFUpdate($changed_fields,@$customer['custom_fields']);
        list($calc, $order) = $this->calculateNextBuy($event['account_id'], $event['order_id']);
        $customer_tags = $this->updateTool->makeCustomerTagsUpdate($customer, $calc);
        if(empty($update) && empty($customer_tags)){
            $this->log('No changes in customer need');
            return;
        }

        $element = array(
            'id' =>$customer['id'],
            'custom_fields' => $update,
        );

        if (!empty($customer_tags)) {
            $element['tags'] = $customer_tags;
        }

        $res = $this->api->update('customers',[$element]);
        $this->logger->info(sprintf('Customer %s updated',$customer['id']),['event' => $event,'customer'=>$element]);
        $this->log('Customer updated');
    }

    public function handlePayment($data,$event)
    {
        if (empty($data['order'])) {
            $this->logger->error('Empty order key in data', $event);
            return;
        }

        $order = $data['order'];
        if (!$order['payed']) {
            $this->logger->warning('Order is not payed',$order);
            return;
        }

        if(empty($event['account_id'])){
            $this->logger->error('Empty account_id key in event',$event);
            return;
        }
        $accountId = $event['account_id'];

        $orders = $this->supportApi->getOrders($accountId);

        //Если это первый оплаченный заказ, то обработать и заполнить поля в карточке покупателя должен CustomerFullUpdateTas
        //Он запускается по вебхуку на событие создания покупателя.
        if ($this->isFirstOrder($orders)) {
            $this->logger->debug('It\'s first order. It will handle by other worker');
            $this->log('It\'s first order. It will handle by other worker');

            return;
        }

        $customer = $this->updateTool->findCustomerByAccountId($accountId);

        if(empty($customer)){
            $this->logger->debug('Customer not found');
            $this->log('Customer not found');
            return;
        }

        $this->log('Customer found:', $customer);

        list($calc, $order) = $this->calculateNextBuy($accountId, $event['order_id'], $orders);

        $customer_tags = $this->updateTool->makeCustomerTagsUpdate($customer, $calc);
        if (!empty($customer_tags)) {
            $element = [
                'id' => $customer['id'],
                'tags' => $customer_tags
            ];

            $this->api->update('customers',[$element]);
            $this->logger->info(sprintf('Customer %s updated', $customer['id']), ['event' => $event,'customer' => $element]);
        }

        $transactions = [];

        $transaction = [
            'customer_id' => $customer['id'],
            'date' => $order['date_payed'],
            'price' => (int)$order['price'],
            'comment' => $this->updateTool->makeTransactionComment($order, $accountId)
        ];

        $this->log('Calculate next buy:', $calc);

        if (isset($calc['next_price'])) {
            $transaction['next_price'] = $calc['next_price'];
        }

        if ($calc['prolongation']) {
            $base_date = $order['date_payed'];
            $base_date = new DateTime('@'.$base_date);
            $base_date->modify('+'.$calc['months'].' months');

            try {
                $account = $this->supportApi->getAccount($accountId);
            } catch(ApiException $e) {
                $this->logger->critical('Cannot retrieve account : ' . $e->getMessage());
                throw $e;
            }

            if (!empty($account['pay_end'])) {
                $base_date = $account['pay_end'];
                $base_date = new DateTime('@'.$base_date);
            }

            $transaction['next_date'] = $base_date->getTimestamp();
        }

        $transactions[] = $transaction;

        if (empty($transactions)) {
            $this->log('Nothing to update');
            $this->logger->error('Nothing to update', $event);
            return;
        }


        $request = [];
        if(!empty($transactions)){
            $request["add"] = $transactions;
        }


        $request = [
            'request'=> [
                'transactions'=>$request,
            ]
        ];

        $this->logger->debug('Update',$request);
        $response = $this->api->post_request('transactions',$request,true);

        $this->logger->debug(sprintf('Response: [%d]',$this->api->get_response_code()),['response'=>$response]);

        $this->log('Transaction added ',$transactions);
    }

    /**
     * @param int $account_id
     * @param int $order_id
     * @param array $orders
     * @return array
     */
    protected function calculateNextBuy($account_id, $order_id, $orders = [])
    {
        try{
            //в аккаунте сожержится купон на скидку
            $account = $this->supportApi->getAccount($account_id,['coupon_discount']);

            if (empty($orders)){
                $orders = $this->supportApi->getOrders($account_id);
            }
        }catch(ApiException $e){
            $this->logger->critical('Cannot retrieve account orders: ' . $e->getMessage());
            throw $e;
        }

        if(empty($account)){
            $this->logger->error('Account data not found');
            return [];
        }

        if(empty($orders)){
            $this->logger->error('No orders found');
            return [];
        }

        $orders = $this->updateTool->prepareOrdersForNextBuy($orders);

        if(empty($orders)){
            $this->logger->error('No payed orders found');
            return [];
        }
        $this->logger->debug(sprintf('Found %d payed orders',count($orders)),$orders);

        $order = null;
        foreach($orders as $item){
            if($item['id'] == $order_id){
                $order = $item;
                break;
            }
        }

        if(empty($order)){
            $this->logger->error(sprintf('Current order: %d not found in payed orders', $order_id));
            return [];
        }

        return [
            $this->updateTool->calculateNextBuy($orders, $account['coupon_discount'], $order),
            $order
        ];
    }

    protected function log($message,$context = null,$err = false)
    {
        $this->output->writeln(date('Y-m-d H:i:s').': '.$message);

        if(!is_null($context)){
            $this->output->writeln(json_encode($context));
        }
    }

    /**
     * @param array $orders
     * @return bool
     */
    protected function isFirstOrder($orders) {
        $payed_orders = $this->updateTool->prepareOrdersForNextBuy($orders);

        return count($payed_orders) === 1;
    }
}
