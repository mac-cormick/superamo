<?php

namespace CustomersBundle\Task\Sync;

use CustomersBundle\Task\BaseTask;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use CustomersBundle\Sync\SyncContacts;
use Amo\Crm\SupportApiClient;
use Phase\AmoCRM_API;

class SyncContactsTaskNew extends BaseTask
{
	const QUEUE_NAME = 'sync_contacts_tasks';
	const QUEUE_FULL_SYNC_NAME = 'full_sync_contacts';

	const ACTION_USER_UPDATE = 'user_update';
	const ACTION_USER_ADD = 'user_add';
	const ACTION_USER_DELETE = 'user_delete';

	const AMO_SHARD_TYPE_RU = 1;
	const AMO_SHARD_TYPE_COM = 2;

	/** @var array */
	protected $_access_actions = [
		self::ACTION_USER_UPDATE,
		self::ACTION_USER_ADD,
		self::ACTION_USER_DELETE,
		self::QUEUE_FULL_SYNC_NAME,
	];

	/** @var \Monolog\Logger $logger */
	protected $_logger;
	/** @var \Amo\Crm\GsoApiClient  */
	protected $_gso_api;
	/** @var SupportApiClient */
	protected $_support_api;

	protected $_statuses;

	/**
	 * {@inheritDoc}
	 */
	public function setContainer(ContainerInterface $container = NULL)
	{
		parent::setContainer($container);

		if ($this->container) {
			$handler = $this->container->get('monolog.handler.customers_account_user');
			$this->_logger = new Logger('customers_account_user', [
					new StreamHandler('php://stdout'),
					$handler,
				]
			);
			$this->_gso_api = $this->container->get('amocrm.gso_api');
			$this->_support_api = $this->container->get('amocrm.support_api');
			$this->_statuses = $this->container->getParameter('customers.leads_statuses_map');
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute(array $data)
	{
		$accounts_info = $data['data']['accounts'];
		$com_accounts = [];

		// Разделим приходящие аккаунты по shard_type
		if (!empty($accounts_info)) {
			foreach ($accounts_info as $account_id => $account_info) {
				if ($account_info['shard_type'] === self::AMO_SHARD_TYPE_COM) {
					$com_accounts[$account_id] = $account_info; // собираем com аккаунты
					unset($accounts_info[$account_id]); // оставляем только не com аккаунты
				}
			}
		}

		// для русского кастомерс
		$data['data']['accounts'] = $accounts_info;
		$customers_api = $this->container->get('amocrm.customers_api');
		$fields = $this->container->getParameter('customers.custom_fields_map');
		$customers_account_id = $this->container->getParameter('customers.account_id')['ru'];

		$this->start_sync($data, $customers_api, $fields, $customers_account_id);

		// для американского кастомерс
		$data['data']['accounts'] = $com_accounts;
		$customers_api = $this->container->get('amocrm.customersus_api');
		$fields = $this->container->getParameter('customersus.custom_fields_map');
		$customers_account_id = $this->container->getParameter('customers.account_id')['en'];

		$this->start_sync($data, $customers_api, $fields, $customers_account_id);
	}

	/**
	 * @param array $data
	 * @param AmoCRM_API $customers_api
	 * @param array $fields
	 * @param int $customers_account_id
	 */
	protected function start_sync(array $data, AmoCRM_API $customers_api, array $fields, $customers_account_id)
	{
		if (!empty($data['action']) && in_array($data['action'], $this->_access_actions)) {
			$logger = $this->_logger;
			$gso_api = $this->_gso_api;
			$support_api = $this->_support_api;
			$statuses = $this->_statuses;
			$this->_logger->info('Start sync', ['class' => 'ContactsSync', 'action' => $data['action']]);

			if ($customers_api->auth()) {
				$syncContacts = new SyncContacts($customers_api, $gso_api, $support_api, $statuses, $fields, $logger, $customers_account_id);
				$syncContacts->{$data['action']}($data['data']);
			} else {
				$this->_logger->error('cant auth in customers', $customers_api->get_full_request_info());
			}
		}
	}
}
