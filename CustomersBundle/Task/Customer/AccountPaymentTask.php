<?php

namespace CustomersBundle\Task\Customer;

use CustomersBundle\Tool\CustomerUpdateTool;
use Phase\AmoCRM_API;
use RuntimeException;
use Psr\Log\LoggerInterface;
use Amo\Crm\SupportApiClient;
use CustomersBundle\Customer\AccountPayment;
use CustomersBundle\Helpers\Helper;

class AccountPaymentTask extends AccountUpdateTask
{
    const QUEUE_NAME = 'account_payment';

    /**
     * {@inheritDoc}
     */
    public function execute(array $data)
    {
        $logger = $this->getLogger();

        try{
            if(empty($data['event']['account_id'])){
                throw new RuntimeException('Empty account_id key in event');
            }

            $account_id = $data['event']['account_id'];

            $shard_type = Helper::getAccountShardType($account_id);

            /** @var CustomerUpdateTool $updateTool */
            $updateTool = $shard_type === $this->amo_shard_type_com ?
                $this->container->get('customersus.customer_update_tool') :
                $this->container->get('customers.customer_update_tool');

            /** @var AmoCRM_API $api */
            $api = $shard_type === $this->amo_shard_type_com ?
                $this->container->get('amocrm.customersus_api') :
                $this->container->get('amocrm.customers_api');

            /** @var SupportApiClient $supportApi */
            $supportApi = $this->container->get('amocrm.support_api');

            $accountPayment = new AccountPayment($api, $supportApi, $updateTool, $logger, $this->getOutput());

            if($data['event']['action'] === 'pay'){
                $accountPayment->handlePayment($data['data'],$data['event']);
            }elseif($data['event']['action'] === 'status_change'){
                $accountPayment->handleStatusChange($data['data'],$data['event']);
            }else{

                $logger->debug('Skipping event',$data);
                $this->log('Skipping event');
            }

        }catch(RuntimeException $e){

            $logger->error($e->getMessage(), $data);
            return;
        }
    }

    /**
     * @return object|LoggerInterface
     */
    protected function getLogger()
    {
        return  $this->container->get('monolog.logger.customers_account_payment');
    }
}

