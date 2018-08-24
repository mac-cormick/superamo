<?php

namespace CustomersBundle\Task\Customer;

use CustomersBundle\Task\BaseTask;
use CustomersBundle\Tool\CustomerUpdateTool;
use Symfony\Component\DependencyInjection\ContainerInterface;


use Phase\AmoCRM_API;
use DateTime;
use RuntimeException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Amo\Crm\ApiException;
use Amo\Crm\SupportApiClient;

class CustomerFullUpdateTask extends BaseTask
{
    const QUEUE_NAME = 'customer_fullupdate';

    /** @var AmoCRM_API $api */
    protected $api;
    /** @var SupportApiClient */
    protected $supportApi;
    /** @var CustomerUpdateTool $ut */
    protected $updateTool;

    /**
     * {@inheritDoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);

        $this->supportApi = $this->container->get('amocrm.support_api');

    }

    /**
     * {@inheritDoc}
     */
    public function execute(array $data)
    {
        $logger = $this->getLogger();

        if(isset($data['subdomain']) && $data['subdomain'] === 'customersus') {
            $this->api = $this->container->get('amocrm.customersus_api');
            $this->updateTool = $this->container->get('customersus.customer_update_tool');
        } else {
            $this->api = $this->container->get('amocrm.customers_api');
            $this->updateTool = $this->container->get('customers.customer_update_tool');
        }

        try{
            if(isset($data['customer_id'])){
                $customer = $this->updateTool->findCustomerById($data['customer_id']);
                if(empty($customer)){
                    $this->log('Customer not found');
                    return;
                }
                $this->log('Found customer:',$customer);

                $accountId = $this->findAccountId($customer);
            }elseif(isset($data['account_id'])){
                $customer = $this->updateTool->findCustomerByAccountId($data['account_id']);
                if(empty($customer)){
                    $this->log('Customer not found');
                    return;
                }
                $this->log('Found customer:',$customer);

                $accountId = $data['account_id'];
            }else{

                $this->log('Broken event');
                return;
            }

            $this->handleCustomerUpdate($accountId,$customer);
        }catch(RuntimeException $e){

            $logger->error($e->getMessage(), $data);
            //throw $e;
        }
    }


    protected function getLogger()
    {
        return  $this->container->get('logger');
    }

    protected function findAccountId(array $customer)
    {
        if(!isset($customer['custom_fields'])){
            throw new RuntimeException('No custom fields in customer');
        }


        $cfAccountId = $this->updateTool->getCustomersFields()['account_id']['id'];
        $cf= null;
        foreach($customer['custom_fields'] as $field){
            if($field['id'] == $cfAccountId){
                $cf = $field;
                break;
            }
        }

        if(empty($cf)){
            throw new RuntimeException('No account_id custom field in customer');
        }
        $item = reset($cf['values']);

        return (int)$item['value'];
    }

    /*
     * @param int $accountId
     * @param array $customer
     */
    protected function handleCustomerUpdate($accountId, array $customer)
    {
        $logger = $this->getLogger();

        $account = $this->supportApi->getAccount($accountId,['version','tariff_name','coupon_discount']);
        if(!$account){
            $this->log('Account data not found');
            $logger->error('Account data not found');
            return;
        }

        $orders = array();
        try{
            $orders = $this->supportApi->getOrders($accountId);
        }catch(ApiException $e){
            $logger->error('Cannot retrieve account orders: '.$e->getMessage());
        }

        $account = $this->updateTool->extendAccountData($account,$account['lang']);

        $logger->debug('Account data found',$account);

        $payed_in_advance = array_filter($orders,function($order){
            return $order['status_id'] === 'P';
        });

        $this->log(sprintf('Account is %s in advance',empty($payed_in_advance)?'not payed':'payed'));
        $account['payed_in_advance'] = !empty($payed_in_advance);

        $update = $this->updateTool->makeCustomerCFUpdate($account,@$customer['custom_fields']);

        $element = $this->updateTool->makeCustomerUpdate($account,$customer);

        $payedOrders = $this->updateTool->prepareOrdersForNextBuy($orders);

        $calc = $this->updateTool->calculateNextBuy($payedOrders,$account['coupon_discount']);

        if (isset($calc['currency'])) {
            $tags = $this->updateTool->makeCustomerTagsUpdate($customer, $calc);

            if (!empty($tags)) {
                $element['tags'] = $tags;
            }
        }

        $this->log('Calculate next buy:',$calc);

        $element['next_date'] = $account['pay_end'];
        //$baseDate = null;
        //if(!empty($account['pay_end'])){
            //$baseDate = new DateTime('@'.$account['pay_end']);
        //}elseif(!empty($payedOrders)){
            //$order = reset($payedOrders);
            //$baseDate = new DateTime('@'.$order['date_payed']);
        //}

        //if($baseDate && isset($calc['months'])){
            //$baseDate->modify('+'.$calc['months'].' months');
            //$element['next_date'] = $baseDate->getTimestamp();
        //}

        if(isset($calc['next_price'])){
            $element['next_price'] = $calc['next_price'];
        }

        $element['custom_fields'] = $update;

        $this->log('Customer update:',$element);

        $res = $this->api->update('customers',[$element]);
        $logger->debug(sprintf('Customer %s updated',$customer['id']));
        $this->log('Customer updated');

        $this->updateTransactions($customer,$payedOrders);
    }

    protected function updateTransactions(array $customer, array $orders)
    {
        $query = array(
            'filter'=>array('customer_id'=>$customer['id']),
        );

        $deleteIds = [];
        $oldTransactions = (array)$this->api->find('transactions',$query);
        $this->log('Found transactions',$oldTransactions);
        foreach($oldTransactions as $item){
            $deleteIds [] = (int)$item['id'];
        }

        $transactions = [];
        foreach($orders as $order){
            $transactions[] = [
                'customer_id' => $customer['id'],
                'date' => $order['date_payed'],
                'price' => (int)$order['price'],
                'comment' => $this->updateTool->makeTransactionComment($order, $this->findAccountId($customer)),
            ];
        }

        $request = [];
        if(!empty($transactions)){
            $request["add"] = $transactions;
        }

        if(!empty($deleteIds)){
            $request["delete"] = $deleteIds;
        }

        if(empty($request)){
            $this->log('Nothing update in transactions');
            return;
        }

        $request = [
            'request'=> [
                'transactions'=>$request,
            ]
        ];

        $logger = $this->getLogger();
        if(!empty($deleteIds)){
            $this->log('Transaction deleted',implode(',',$deleteIds));
        }
        if(!empty($transactions)){
            $this->log('Transaction added ',$transactions);
        }

        $this->log('Update transactions',$request);
        $response = $this->api->post_request('transactions',$request,true);


    }


}

