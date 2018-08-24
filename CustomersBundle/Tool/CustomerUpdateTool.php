<?php

namespace CustomersBundle\Tool;

use Amo\Crm\ApiException;
use Phase\AmoCRM_API;
use Amo\Crm\SupportApiClient;

use Psr\Log\LoggerInterface;
use RuntimeException;
use DateTimeZone;
use DateTime;
use CustomersBundle\Helpers\Helper;

class CustomerUpdateTool
{
    const PARTNER_ORDER_TAG = 'Клиент партнера';
    const AMO_SHARD_TYPE_RU = 1;
    const AMO_SHARD_TYPE_COM = 2;

    protected $langs = ['en','ru','es'];

    /**
     *  список полей с ключами по коду
     *
     * @var array
     */
    protected $cfMap = [];

    //cached values
    protected $dictionaries;
    protected $customersAccount;
    protected $customersFields;
    protected $prices;

    protected static $extendableFields = [
        'currency',
        'timezone',
        'country',
        'time_format',
        'date_format',
    ];

    //мэппинг полей
    protected $syncFields = array(
        //'id' => 'account_id', // ID аккаунта
        'name' => 'account_name', // Название аккаунта
        'subdomain' => 'account_subdomain', // Субдомен аккаунта
        'trial_start' => 'account_trial_start', // Начало триального периода
        'trial_end' => 'account_trial_end', // Окончание триального периода
        'pay_start' => 'account_pay_start', // Начало платного периода
        'pay_end' => 'account_pay_end', // Окончание платного периода
        //'tariff_id' => 'tariff_id', // ID тарифа
        'tariff_name' => 'account_tariff_name', // Тарифный план
        'lang' => 'account_lang', // Язык аккаунта
        'paid_users' => 'account_paid_users', // Количество пользователей

        'currency_name' => 'account_currency', // Валюта
        //'phone_number' => 'account_phone_number', // Телефон
        'timezone_name' => 'account_timezone', // Часовой пояс
        'coupon_code' => 'account_coupon_code', // Купон в настройках аккаунта
        'first_coupon' => 'account_first_coupon', // Купон, по которому идут отчисления
        'first_coupon_date' => 'account_first_coupon_date', // Дата привязки Купона к аккаунту
        'reg_coupon' => 'account_reg_coupon', // Купон, указанный при регистрации
        'paid_access_balance' => 'account_paid_access_balance', // Оплаченный остаток (дней)
        'file_size' => 'account_file_size', // Исп. место пространства
        //'tp_comment' => 'account_tp_comment', // Примечание о перерасчетах
        'date_format_name' => 'account_date_format', // Формат даты
        'time_format_name' => 'account_time_format', // Формат времени
        'country_name' => 'account_country', // Страна
        'created_partner' => 'account_created_partner', // ID партнера
        'unsorted_on' => 'account_unsorted_on', // Неразобранное активно
        'webhooks_on' => 'account_webhooks_on', // Webhooks
        'customers_enabled' => 'account_customers_enabled', // Периодические покупки
        'salesbot_enabled' => 'account_salesbot_enabled', // SalesBot
        'version' => 'account_version', // Версия аккаунта
        'by_user_tariffing' => 'account_by_user_tariffing' ,// Поюзерная тарификация
        'payed_in_advance' => 'account_payed_in_advance', // Оплачен в аванс
    );

    protected $radioFieldsAlias = array(
        'account_unsorted_on', // Неразобранное активно
        'account_webhooks_on', // Webhooks
        'account_customers_enabled', // Периодические покупки
        'account_salesbot_enabled', // SalesBot
        'account_by_user_tariffing' ,// Поюзерная тарификация
        'account_payed_in_advance', // Оплачен в аванс

        'key_client',
        'rework',
        'integration',
    );

    protected $api;
    protected $supportApi;
    protected $logger;

    /** @var int $shard_type*/
    protected $shard_type;

    /**
     * CustomerUpdateTool constructor.
     * @param LoggerInterface $logger
     * @param AmoCRM_API $api
     * @param SupportApiClient $supportApi
     * @param array $cfFieldsMap
     * @param int $shard_type
     */
    public function __construct(LoggerInterface $logger, AmoCRM_API $api, SupportApiClient $supportApi, array $cfFieldsMap, $shard_type)
    {
        $this->logger = $logger;
        $this->api = $api;
        $this->supportApi = $supportApi;
        $this->shard_type = $shard_type;

        foreach($cfFieldsMap['customers'] as $name => $field){
            $this->cfMap[$name] = $field['id'];
        }
    }

    public function findCustomerById($customerId)
    {
        $query = array(
            'filter'=>array('id'=>array($customerId)),
        );

        $this->ensureApiAuthorized();

        $customers = $this->api->find('customers',$query);

        if(empty($customers)){
            return null;
        }

        if(count($customers) > 1 ){
            $ids = [];
            foreach($customers as $item){
                $ids[] = $item['id'];
            }
            $this->logger->debug("Found several customers with id: ".implode($ids));
            throw new RuntimeException('Too many customers: '.count($customers));
        }

        return  reset($customers);
    }

    public function findCustomerByAccountId($accountId)
    {
        $cfAccountId = $this->cfMap['account_id'];
        $query = array(
            'filter'=>array('custom_fields'=>array(
               $cfAccountId => $accountId,
            )),
        );

        $this->ensureApiAuthorized();

        $customers = $this->api->find('customers',$query);

        if(empty($customers)){
            return null;
        }

        if(count($customers) > 1 ){
            $ids = [];
            foreach($customers as $item){
                $ids[] = $item['id'];
            }
            $this->logger->debug("Found several customers with id: ".implode($ids));
            throw new RuntimeException('Too many customers for one account: '.count($customers));
        }

        return  reset($customers);
    }

    public function getCustomersAccount()
    {
        $this->preCacheCustomers();
        return $this->customersAccount;
    }

    public function getCustomersFields()
    {
        $this->preCacheCustomers();
        return $this->customersFields;
    }

    public function makeCustomerUpdate(array $account,array $customer)
    {
        $update = [
            'id' =>$customer['id'],
        ];
        if(mb_stripos($customer['name'],"новый") === 0){
            $update['name'] = 'Аккаунт: '.$account['id'];
        }
        if(mb_stripos($customer['name'], "New") === 0){
            $update['name'] = 'Account: ' . $account['id'];
        }
        return $update;
    }

    /**
     * Формирует строку обновления тегов покупателя аккаунта
     *
     * @param array $customer данные покупателя
     * @param array $calculation
     * @return string
     * @author nbessudnov
     */
    public function makeCustomerTagsUpdate(array $customer, array $calculation = [])
    {
        $newTags = [];
        $oldTags = [];
        $tagsToRemove = [];
        $result = '';

        if (!empty($calculation) && isset($calculation['partner_order']) && isset($calculation['currency'])) {
            $newTags = $calculation['partner_order'] ? [$calculation['currency'], self::PARTNER_ORDER_TAG] : [$calculation['currency']];
            $tagsToRemove = $calculation['partner_order'] ? [] : [self::PARTNER_ORDER_TAG];
        }

        if (isset($customer['tags']) || !empty($tagsToRemove) || !empty($newTags)) {
            foreach ($customer['tags'] as $tag) {
                $oldTags[] = $tag['name'];
            }

            $tagsForUpdate = array_unique(array_merge($oldTags, $newTags));
            $tagsForUpdate = array_diff($tagsForUpdate, $tagsToRemove);

            if ($oldTags !== $tagsForUpdate) {
                $result = implode(',', array_merge($tagsForUpdate));
            }
        }

        return $result;
    }

    /**
     * Формирует строку для транзакции покупателя аккаунта
     *
     * @param array $order данные покупателя
     * @param int $accountId ID аккаунта
     * @return string
     * @author nbessudnov
     */
    public function makeTransactionComment(array $order, $accountId) {
        $comment = '';

        if (isset($order['id'])) {
            $view = $this->shard_type === self::AMO_SHARD_TYPE_COM ? 'transactionCommentEn.twig' : 'transactionCommentRu.twig';
            $comment = Helper::render($view, ['order' => $order]);
        }

        return $comment;
    }

    /**
     * Находим заказ в аккаунте по ID заказа
     *
     * @param int $accountId ID аккаунта
     * @param int $orderId ID заказа
     * @return array
     * @author nbessudnov
     */
    public function getOrderByID($accountId, $orderId) {
        try {
            $orders = $this->supportApi->getOrders($accountId);
        } catch(ApiException $e) {
            $this->logger->critical('Cannot retrieve account orders: '.$e->getMessage());
            throw $e;
        }

        if(empty($orders)){
            $this->logger->error('No orders found');
            return [];
        }

        $order = null;
        foreach($orders as $item){
            if($item['id'] == $orderId){
                $order = $item;
                break;
            }
        }

        if(empty($order)){
            $this->logger->error(sprintf('Current order: %d not found in account orders', $orderId));
            return [];
        }

        return $order;
    }

    /**
     * @param array $customFields
     * @param int $cfId
     * @return mixed
     */
    public function findCF(array $customFields, $cfId)
    {
        $value = null;
        foreach ($customFields as $customField) {
            if ($customField['id'] === $cfId && isset($customField['values'][0]['value'])) {
                $value = $customField['values'][0]['value'];
            }
        }

        return $value;
    }
    /**
     * Формирует массив обновления кастомных полей покупателя из данных аккаунта
     *
     * @param array $account данные аккаунта
     * @param array $customerCF массив кастомных полей покупателя
     * @return array
     * @author skoryukin
     */
    public function makeCustomerCFUpdate(array $account,array $customerCF = [])
    {

        $customerAccount = $this->getCustomersAccount();
        $fields = $this->getCustomersFields();

        $customerCF = empty($customerCF)? []:$customerCF;

        $cf = [];
        foreach($customerCF as $item){
            $cf[$item['id']] = $item;
        }

        $tz = new DateTimeZone($customerAccount['timezone']); //зона customers.amocrm.ru
        $update = [];
        foreach($this->syncFields as $code => $cf_code){
            if(!array_key_exists($code,$account) || !isset($fields[$cf_code])){
                continue;
            }

            $field = $fields[$cf_code];
            $value = $account[$code];
            if(is_null($value)){
                //nothing
            }elseif(in_array($code,['trial_start','trial_end','pay_start','pay_end'])){
                $d = new DateTime("@$value"); //utc
                $d->setTimeZone($tz); //customers zone
                $value = $d->format('d.m.Y');
            }elseif($code === 'lang'){
                switch ($value) {
                    case 'ru':
                        $value = $this->shard_type === self::AMO_SHARD_TYPE_COM ? 'Russian' : 'Русский';
                        break;
                    case 'en':
                        $value = $this->shard_type === self::AMO_SHARD_TYPE_COM ? 'English' : 'Английский';
                        break;
                    case 'es':
                        $value = $this->shard_type === self::AMO_SHARD_TYPE_COM ? 'Spanish' : 'Испанский';
                        break;
                }
            }elseif($code === 'salesbot_enabled'){
                $value = $value?1:null;
            }elseif($code === 'file_size'){
                $value = ceil($value/(1024*1024)); //Mb
            }

            if($field['type_id'] == 3){ //checkbox
                $value = $value?1:0;
            }elseif(!is_null($value)){
                $value = $this->escapeValue($value);
            }

            if(isset($field['enums'])){

                $enum_id = null;
                foreach($field['enums'] as $id => $enum_value){
                    if($enum_value === $value){
                        $enum_id = $id;
                        break;
                    }
                }

                if(is_null($enum_id)){
                    //cannot find enum for field: $code, $value
                    continue;
                }

                if(isset($cf[$field['id']])){
                    $cv = reset($cf[$field['id']]['values']);
                    if($cv['enum'] != $enum_id){
                        $update[] = array(
                            'id' => $field['id'],
                            'values' => array(
                                [ 'value'  => $enum_id]
                            )
                        );
                    }
                }else{
                    $update[] = array(
                        'id' => $field['id'],
                        'values' => array(
                            [ 'value'  => $enum_id]
                        )
                    );
                }
            }else{

                if(isset($cf[$field['id']])){
                    $cv = reset($cf[$field['id']]['values']);
                    if($cv['value'] != $value){
                        $update[] = array(
                            'id' => $field['id'],
                            'values' => array(
                                [ 'value'  => $value]
                            )
                        );
                    }
                }else{
                    $update[] = array(
                        'id' => $field['id'],
                        'values' => array(
                            [ 'value'  => $value]
                        )
                    );
                }
            }

        }

        if(empty($update)){
            return $update;
        }
        //Из-за глючной реализации нашего АПИ - если не передать текущее состояние полей типа чекбоксов и
        //мульти-списка, то они будут затерты
        $update = $this->fixApiFieldAbsense($update,$customerCF);

        return $update;
    }

    public function extendAccountFields(array $fields)
    {
        $names = array_intersect(self::$extendableFields,$fields);
        foreach($names as $name){
            $fields[] = "{$name}_name";
        }

        if(in_array('tariff_id',$fields)){
            $fields[] = 'tariff_name';
        }

        return $fields;
    }

    public function extendAccountData(array $data,$lang)
    {

        foreach(self::$extendableFields as $name){
            if(isset($data[$name])){
                $dict = $this->getDictionary($lang,$name);
                if(isset($dict[$data[$name]])){
                    $data["{$name}_name"] = $dict[$data[$name]]['name'];
                }

            }
        }

        return $data;
    }

    /**
     * @param array $orders
     * @return array|null
     */
    public function getProlongationOrder(array $orders) {
        $prolongationOrder = NULL;

        if (!empty($orders)) {
            $prolongationOrder = reset($orders);

            foreach ($orders as $item) {
                if (!is_null($item['prolongation'])) {
                    $prolongationOrder = $item;
                    break;
                }
            }
        }

        return $prolongationOrder;
    }

    /**
     * @param array $order
     * @return int|null
     */
    public function getUsersCount(array $order) {
        $usersCount = NULL;

        //посчитаем пользователей
        if($order['tariff']['by_user']){
            if(!is_null($order['add_users'])){
                $usersCount = $order['add_users']['additional_users_count'] + $order['add_users']['current_users_count'];
            }elseif(!is_null($order['prolongation'])){
                //current_users_count может быть равен 0 при смене тарифа
                $usersCount = $order['prolongation']['additional_users_count'] + $order['prolongation']['current_users_count'];
            }else{
                $this->logger->error('incorrect order, cannot count users');
            }
        } else {
            $usersCount = 1;
        }

        return $usersCount;
    }

    /**
     * Расчитывает дату и сумму следующей покупки
     * @param  $orders - массив оплаченных заказов в порядке от последнего к самому раннему
     * @param  $discount - коэфициент скидки
     * @param  $order - текущий заказ для расчета
     * @return array
     * @author skoryukin
     */
    public function calculateNextBuy(array $orders,$discount = 1,$order = null)
    {
        if(empty($orders)){
            return [];
        }

        if(is_null($order)){
            $order = reset($orders);
        }

        $prolongation_order = $this->getProlongationOrder($orders);

        if(is_null($prolongation_order)){
            $this->logger->error('No prolongation order found');
            return [];
        }

        if($prolongation_order['tariff']['is_free']){
            return [];
        }


        if(empty($prolongation_order['tariff'])){
            $this->logger->error('No tariff in prolongation order');
            return [];
        }

        $users_count = $this->getUsersCount($prolongation_order);
        if (is_null($users_count)) {
            return [];
        }


        /**
         * Решеили всё-таки брать стоимость не по тарифу, а фактическую (24.08.2017 Бессуднов)
         *
        $price = $prolongation_order['tariff']['month_price'] ; //стоимость по тарифу для указанного числа месецев
         *
         **/

        $price = $prolongation_order['prolongation']['price']; //Стоимость по заказу

        /**
         * Убрал, потому что это свойство не проставляется партнерским заказам, которых очень много (21.08.2017 Бессуднов)
         *
        if($order['need_this_price']){
            $price = $prolongation_order['prolongation']['price'];
        }
        **/

        $months_count = (int)$prolongation_order['prolongation']['months'];
        $discount_value = $prolongation_order['prolongation']['discount_price'];

        /**
         * Задел на будущее, решили пока не добавлять такую логику (24.08.2017 nbessudnov)
         * Скидка 100% может быть только при первой продаже партнером
         * Поэтому добавим сделаем двойную цену, так как скидка потом вычтеться.
         *
        if ($price - $discount_value === (float)0) {
            $price = $price + $discount_value;
        }
         **/

        $result = [
            'months' => $months_count,
        ];

        $result['currency'] = $prolongation_order['currency'];
        $result['partner_order'] = !is_null($prolongation_order['partner_id']) || !is_null($order['partner_id']);
        $result['next_price'] = round(($price - $discount_value) * $users_count * $months_count * $discount);

        //если заказ на докупку пользователей отметим что обновить нужно только сумму
        $result['prolongation'] = !is_null($order['prolongation']);


        return $result;
    }

    /**
     * Фильтрует и сортирует в нужном порядке заказы аккаунта для последующего расчета
     *
     * @param $orders - массив заказов
     * @return array
     * @author skoryukin
     */
    public function prepareOrdersForNextBuy(array $orders)
    {
        $payed = array_filter($orders,function($order){
            return $order['payed'];
        });

        usort($payed,function($a,$b){
            if($a['date_payed'] === $b['date_payed']){
                return 0;
            }

            //reverse order newest first
            return ($a['date_payed'] < $b['date_payed']) ? 1 : -1;
        });

        return $payed;
    }

    /**
     * Исправляет косяк с обнуление полей выключателей
     *
     * @param array $update - массив обновляемых сввойств
     * @param array $customerCF -  массив существующих свойств
     *
     * @return array
     * @author skoryukin
     */
    protected function fixApiFieldAbsense(array $update,array $customerCF)
    {

        $fields = $this->getCustomersFields();
        $fix = [];
        foreach($this->radioFieldsAlias as $cf_code){
            if(!isset($fields[$cf_code])){
                continue;
            }
            $field = $fields[$cf_code];

            $update_key = false;
            foreach($update as $k =>  $cf){
                if($cf['id'] == $field['id']){
                    $update_key = $k;
                    break;
                }
            }

            if($update_key !== false){
                //поле будет обновлено, нечего исправлять
                continue;
            }

            $existing_key = false;
            foreach($customerCF as $k => $cf){
                if($cf['id'] == $field['id']){
                    $existing_key = $k;
                    break;
                }
            }
            if($existing_key === false){
                //поле еще не заполнено
                continue;
            }

            $fix[] = array(
                'id' => $field['id'],
                'values' => $customerCF[$existing_key]['values'],
            );
        }

        return array_merge($update,$fix);
    }

    public function getPrice($tariffId, $monthCount)
    {
        $key = sprintf('%s_%s', $tariffId, $monthCount);

        if(is_null($this->prices) || !isset($this->prices[$key])){
            try {
                $tariff = $this->supportApi->getTariffInfo($tariffId);
                if(empty($tariff) && empty($tariff['prices'])){
                    throw new RuntimeException(sprintf('Cannot retrieve tariff prices for %s', $tariffId));
                }

                foreach ($tariff['prices'] as $price) {
                    if ($price['months_from'] <= $monthCount && ($price['months_to'] >= $monthCount || $price['months_to'] === 0))  {
                        $this->prices[$key] = (float)$price['price'];
                        break;
                    }
                }

            } catch(ApiException $e) {
                throw new RuntimeException('Cannot retrieve tariff price: '.$e->getMessage(),$e->getCode(),$e);
            }
        }

        return $this->prices[$key];
    }

    protected function getDictionary($lang,$name)
    {
        if(is_null($this->dictionaries)){
            try{
                foreach($this->langs as $lang_code){

                    $dictionaries = $this->supportApi->getDictionary($lang_code);
                    if(empty($dictionaries)){
                        throw new RuntimeException('Cannot retrieve support dictionaries');
                    }

                    $dicts = [];
                    foreach($dictionaries as $code => $items){
                        $dicts[$code] = [];
                        foreach($items as $item){
                            $dicts[$code][$item['code']] = $item;
                        }
                    }
                    $this->dictionaries[$lang_code] = $dicts;
                }


            }catch(ApiException $e){

                throw new RuntimeException('Cannot retrieve support dictionaries: '.$e->getMessage(),$e->getCode(),$e);
            }
        }

        if(!isset($this->dictionaries[$lang][$name])){
            return [];
        }

        return $this->dictionaries[$lang][$name];
    }


    protected function preCacheCustomers()
    {
        if($this->customersAccount){
            return;
        }
        $this->ensureApiAuthorized();

        $customers_account = $this->api->get_account();

        if(empty($customers_account)){
            throw new RuntimeException('Cannot retrive customers account');
        }
        if(!isset($customers_account['account']['custom_fields']['customers'])){
            throw new RuntimeException('No customers custom fields');
        }

        $fields = [];
        foreach($customers_account['account']['custom_fields']['customers'] as $field){
            if($code = array_search($field['id'],$this->cfMap,false)){
                if(isset($field['enums'])){
                    foreach($field['enums'] as $k => $name){
                        $field['enums'][$k] = html_entity_decode($name);
                    }
                }
                $fields[$code] = $field;
            }
        }

        if(!isset($fields['account_id'])){
            throw new RuntimeException('account_id custom field not found');
            return;
        }

        $this->customersAccount = $customers_account['account'];
        $this->customersFields = $fields;
    }


    /**
     * @deprecated
     *
     * @return void
     * @author skoryukin
     */
    protected function ensureApiAuthorized()
    {
        if(!$this->api->is_authorized()){
            $this->api->auth();
        }

        if(!$this->api->is_authorized()){
            throw new RuntimeException('Authorization failed in customers account');
        }

    }


    protected function escapeValue($value) {
        $replace = [
            '\'' => ' ',
            '"' => ' ',
            ';' => ',',
            "\n" => ' ',
            PHP_EOL => ' ',
            "\r" => ' ',
        ];

        return trim(str_replace(array_keys($replace), array_values($replace), $value));
    }
}

