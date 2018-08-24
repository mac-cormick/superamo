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
use Amo\Element;
use Amo\Note;

Class AccountUpdate {
    
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

    /** @var int $shard_type*/
    private $shard_type;

    const AMO_SHARD_TYPE_RU = 1;
    const AMO_SHARD_TYPE_COM = 2;

    private $textPattern = [
        self::AMO_SHARD_TYPE_RU => [
            //переносы строк не трогать, это скрытый \n для переноса строк в системном примечании
            'subscription_date_changed' => 'Дата окончания подписки изменилась с %s на %s
',
            'users_number_changed' => 'Колличество пользователей изменилось с %s на %s
',
            'plan_changed' => 'Колличество пользователей изменилось с %s на %s
',
        ],
        self::AMO_SHARD_TYPE_COM => [
            'subscription_date_changed' => 'The subscription expiration date was changed from %s to %s
',
            'users_number_changed' => 'The number of users was changed from %s to %s.
',
            'plan_changed' => 'The plan was changed from %s to %s.
',
        ],
    ];

    /**
     * AccountPayment constructor.
     * @param AmoCRM_API $api
     * @param SupportApiClient $supportApi
     * @param CustomerUpdateTool $updateTool
     * @param $logger
     * @param OutputInterface $output
     * @param int $shard_type
     */
    public function __construct(
        AmoCRM_API $api,
        SupportApiClient $supportApi,
        CustomerUpdateTool $updateTool,
        LoggerInterface $logger,
        OutputInterface $output,
        $shard_type
    ) {
        $this->api = $api;
        $this->supportApi = $supportApi;
        $this->updateTool = $updateTool;
        $this->logger = $logger;
        $this->output = $output;
        $this->shard_type = $shard_type;
    }


    /**
     * @param array $data
     *   $data = array(
     *       'fields' => ['name'...]
     *   )
     * @param $event
     *   $event = array(
     *       'name' => '',
     *       'source' => '',
     *       'account_id' => '',
     *       'user_id' => '', //opt
     *       'server_time' => 12312312
     *   )
     * @return void
     */
    public function handleAccountUpdate($data,$event) {

        if (empty($data['fields'])) {
            $this->logger->info('No fields changed in data', $event);

            return;
        }

        $customer = $this->updateTool->findCustomerByAccountId($event['account_id']);

        if (empty($customer)) {
            $this->logger->debug('Customer not found.');
            $this->log('Customer not found.');

            return;
        }


        $account = $this->supportApi->getAccount($event['account_id'],['version','tariff_name', 'coupon_discount']);
        if(!$account){
            $this->log('Account data not found');
            $this->logger->error('Account data not found');
            return;
        }

        $account = $this->updateTool->extendAccountData($account,$account['lang']);

        $this->logger->debug('Account data found',$account);


        if(in_array('update_all_fields',$data['fields'],true)){
            $changedFields = $account;
        }else{
            $data['fields'] = $this->updateTool->extendAccountFields($data['fields']);
            $changedFields = array_intersect_key($account,array_flip($data['fields']));
        }

        if(empty($changedFields)){
            $this->log('Changed fields empty. Nothing to update');

            return;
        }

        $this->log('Apply changes',$changedFields);
        $this->logger->debug('Apply changes',$changedFields);

        $update = $this->updateTool->makeCustomerCFUpdate($changedFields,@$customer['custom_fields']);
        $fields = $this->updateTool->getCustomersFields();
        $oldTariffName = $this->updateTool->findCF($customer['custom_fields'], $fields['account_tariff_name']['id']);
        $oldPaidUsers = $this->updateTool->findCF($customer['custom_fields'], $fields['account_paid_users']['id']);

        if(empty($update)){
            $this->log('No changes in customer need');
            return;
        }

        $element = $this->updateTool->makeCustomerUpdate($account,$customer);

        $element['custom_fields'] = $update;

        $noteText = '';
        if (isset($changedFields['pay_end'])) {
            $customerAccount = $this->updateTool->getCustomersAccount();
            $tzCustomers = new \DateTimeZone($customerAccount['timezone']);
            $payEndOld = new \DateTime('@' . $customer['next_date']);
            $payEnd = new \DateTime('@' . $changedFields['pay_end']);
            $payEndOld->setTimeZone($tzCustomers);
            $payEnd->setTimeZone($tzCustomers);

            $element['next_date'] = $changedFields['pay_end'];
            $noteText .= sprintf(
                $this->textPattern[$this->shard_type]['subscription_date_changed'],
                $payEndOld->format('d.m.Y'),
                $payEnd->format('d.m.Y')
            );
        }

        if (isset($changedFields['tariff_id']) || isset($changedFields['paid_users'])) {
            //Если кол-во пользователей изменилось, берем цену за месяц из последнего счета для 1 юзера и пересчитываем
            //Если изменился тариф, берем цену тарифа за месяц и пересчитываем

            $orders = [];
            try {
                $orders = $this->supportApi->getOrders($account['id']);
            } catch (ApiException $e) {
                $this->logger->error('Cannot retrieve account orders: ' . $e->getMessage());
            }

            $payedOrders = $this->updateTool->prepareOrdersForNextBuy($orders);
            $prolongationOrder = $this->updateTool->getProlongationOrder($payedOrders);
            if (is_null($prolongationOrder)) {
                $this->logger->error('No prolongation order');
            } else {

                $monthCount = $prolongationOrder['prolongation']['months'];

                $usersCount = isset($changedFields['paid_users']) ?
                    $changedFields['paid_users'] :
                    $account['paid_users'];

                if ($usersCount < 1) {
                    $usersCount = 1;
                }

                if (!is_null($prolongationOrder['partner_id'])) {
                    //Высчитываем скидку, если последний заказ на продление от партнера
                    $discount = 1 - number_format($prolongationOrder['prolongation']['discount_price'] / $prolongationOrder['prolongation']['price'], 2);
                } else {
                    $discount = 1;
                }

                //Если тариф изменился, или текущий тариф не соответствует тарифу из последнего счета на продление, узнаем цену
                //Если изменилось кол-во пользователей возьмем из последнего заказа на продление
                $tariff_price = (isset($changedFields['tariff_id']) && $changedFields['tariff_id'] !== $account['tariff_id']) ||
                $account['tariff_id'] !== $prolongationOrder['tariff']['id'] ?
                    $this->updateTool->getPrice($account['tariff_id'], $monthCount) :
                    (int)$prolongationOrder['prolongation']['price'];


                $element['next_price'] = (int)$monthCount * $usersCount * $tariff_price * $account['coupon_discount'] * $discount;

                if (isset($changedFields['paid_users'])) {
                    $noteText .= sprintf(
                        $this->textPattern[$this->shard_type]['users_number_changed'],
                        $oldPaidUsers,
                        $changedFields['paid_users']
                    );
                }

                if (isset($changedFields['tariff_id'])) {
                    $noteText .= sprintf(
                        $this->textPattern[$this->shard_type]['plan_changed'],
                        $oldTariffName,
                        $changedFields['tariff_name']
                    );
                }
            }
        }

        if (!empty($noteText)) {
            $note = [
                [
                    'element_id' => $element['id'],
                    'text' => json_encode([
                        'text' => $noteText,
                        'service' => $this->shard_type === self::AMO_SHARD_TYPE_COM ? 'Robot' : 'Робот',
                    ]),
                    'element_type' => Element::CUSTOMERS_TYPE,
                    'note_type' => Note::SERVICE_MESSAGE_TYPE,
                ]
            ];
            $this->api->add('note', $note);
            $this->logger->info('Notes added',['event' => $event,'customer'=>$element]);
        }

        $res = $this->api->update('customers',[$element]);
        $this->logger->info(sprintf('Customer %s updated',$customer['id']),['event' => $event,'customer'=>$element]);
        $this->log('Customer updated');
    }

    protected function log($message, $context = null, $err = false) {
        $this->output->writeln(date('Y-m-d H:i:s').': '.$message);

        if(!is_null($context)){
            $this->output->writeln(json_encode($context));
        }
    }

}
