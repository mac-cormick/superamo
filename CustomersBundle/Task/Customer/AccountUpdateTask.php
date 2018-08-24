<?php

namespace CustomersBundle\Task\Customer;

use Amo\Crm\SupportApiClient;
use CustomersBundle\Helpers\Helper;
use CustomersBundle\Task\BaseTask;
use CustomersBundle\Tool\CustomerUpdateTool;
use Symfony\Component\DependencyInjection\ContainerInterface;


use Phase\AmoCRM_API;
use RuntimeException;
use Psr\Log\LoggerInterface;
use CustomersBundle\Customer\AccountUpdate;

class AccountUpdateTask extends BaseTask
{
    const QUEUE_NAME = 'account_update';
    protected $amo_shard_type_ru;
    protected $amo_shard_type_com;

    /**
     * {@inheritDoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);
        $this->amo_shard_type_ru = $container->getParameter('amo_shard_type_ru');
        $this->amo_shard_type_com = $container->getParameter('amo_shard_type_com');
    }

    /**
     * {@inheritDoc}
     */
    public function execute(array $data)
    {
        $logger = $this->getLogger();

        try{

            if (empty($data['event']['account_id'])) {
                $logger->debug('Account id is empty');
                $this->log('Account id is empty');
                return;
            }

            $account_id = $data['event']['account_id'];
            $shard_type = Helper::getAccountShardType($account_id);

            /** @var SupportApiClient $supportApi */
            $supportApi = $this->container->get('amocrm.support_api');

            /** @var CustomerUpdateTool $updateTool */
            $updateTool = $shard_type === $this->amo_shard_type_com ?
                $this->container->get('customersus.customer_update_tool') :
                $this->container->get('customers.customer_update_tool');

            /** @var AmoCRM_API $api */
            $api = $shard_type === $this->amo_shard_type_com ?
                $this->container->get('amocrm.customersus_api') :
                $this->container->get('amocrm.customers_api');

            $accountUpdate = new AccountUpdate($api, $supportApi, $updateTool, $logger, $this->getOutput(), $shard_type);

            $accountUpdate->handleAccountUpdate($data['data'],$data['event']);
        }catch(RuntimeException $e){

            $logger->error($e->getMessage(), $data);
            throw $e;
        }
    }


    /**
     * @return LoggerInterface
     */
    protected function getLogger()
    {
        return  $this->container->get('monolog.logger.account_update');
    }

}

