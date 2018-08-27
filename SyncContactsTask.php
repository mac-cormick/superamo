<?php

namespace CustomersBundle\Task\Sync;

use CustomersBundle\Task\BaseTask;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use CustomersBundle\Sync\SyncContacts;
use CustomersBundle\Tool\CustomerUpdateTool;

class SyncContactsTask extends BaseTask
{
	const QUEUE_NAME = 'sync_contacts_tasks';
	const QUEUE_FULL_SYNC_NAME = 'full_sync_contacts';

	const ACTION_USER_UPDATE = 'user_update';
	const ACTION_USER_ADD = 'user_add';
	const ACTION_USER_DELETE = 'user_delete';

	/** @var array */
	protected $_access_actions = [
		self::ACTION_USER_UPDATE,
		self::ACTION_USER_ADD,
		self::ACTION_USER_DELETE,
		self::QUEUE_FULL_SYNC_NAME,
	];

	/** @var \Monolog\Logger $_logger */
	protected $_logger;

	/** @var \Amo\Crm\GsoApiClient $_gso_api */
	protected $_gso_api;

	/** @var \Amo\Crm\SupportApiClient $_support_api */
	protected $_support_api;

	/** @var \Phase\Service\Helper $_phase_helper */
	protected $_phase_helper;

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
			$this->_phase_helper = $this->container->get('phase.helper');
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute(array $data)
	{
		if (!empty($data['action']) && in_array($data['action'], $this->_access_actions)) {
			// для full_sync_contacts формат $data отличается. выделим в отдельное условие
			if ($data['action'] === self::QUEUE_FULL_SYNC_NAME) {
				$users_info = $data['data'];

				// разделим данные по shard_type аккаунтов
				$users_info_com = [];
				$users_info_ru = [];

				foreach ($users_info as $user_info) {
					$user_all_accounts = $user_info['accounts'];
					$user_com_accounts = [];
					foreach ($user_all_accounts as $account_id => $account_info) {
						$account_shard_type = $this->_phase_helper->getAccountShardType($account_id);
						if ($account_shard_type === CustomerUpdateTool::AMO_SHARD_TYPE_COM) {
							$user_com_accounts[$account_id] = $account_info; // собираем com аккаунты пользователя
							unset($user_all_accounts[$account_id]); // оставляем только не com аккаунты
						}
					}

                    $user_info_com = $user_info_ru = $user_info;
					if (!empty($user_com_accounts)) {
                        $user_info_com['accounts'] = $user_com_accounts;
                        $users_info_com[] = $user_info_com;
					}
					if (!empty($user_all_accounts)) {
                        $user_info_ru['accounts'] = $user_all_accounts;
                        $users_info_ru[] = $user_info_ru;
					}
				}

				if (!empty($users_info_com)) {
					$data['data'] = $users_info_com;
					$this->start_sync($data, CustomerUpdateTool::AMO_SHARD_TYPE_COM);
				}

				if (!empty($users_info_ru)) {
					$data['data'] = $users_info_ru;
					$this->start_sync($data, CustomerUpdateTool::AMO_SHARD_TYPE_RU);
				}
			} else {
				$accounts_info = $data['data']['accounts'];
				$com_accounts = [];

				// Разделим приходящие аккаунты по shard_type
				if (!empty($accounts_info)) {
					foreach ($accounts_info as $account_id => $account_info) {
						if (!empty($account_info['shard_type']) && $account_info['shard_type'] === CustomerUpdateTool::AMO_SHARD_TYPE_COM) {
							$com_accounts[$account_id] = $account_info; // собираем com аккаунты
							unset($accounts_info[$account_id]); // оставляем только не com аккаунты
						}
					}
				}

				// для русского кастомерс
				if ($data['action'] === self::ACTION_USER_DELETE || !empty($accounts_info)) {
					$data['data']['accounts'] = $accounts_info;
					$this->start_sync($data, CustomerUpdateTool::AMO_SHARD_TYPE_RU);
				}

				// для американского кастомерс
				if ($data['action'] === self::ACTION_USER_DELETE || !empty($com_accounts)) {
					$data['data']['accounts'] = $com_accounts;
					$this->start_sync($data, CustomerUpdateTool::AMO_SHARD_TYPE_COM);
				}
			}
		}
	}

	/**
	 * @param array $data
	 * @param int $shard_type
	 */
	protected function start_sync(array $data, $shard_type)
	{
		$logger = $this->_logger;
		$gso_api = $this->_gso_api;
		$support_api = $this->_support_api;

		if ($shard_type === CustomerUpdateTool::AMO_SHARD_TYPE_COM) {
			$customers_api = $this->container->get('amocrm.customersus_api');
			$fields = $this->container->getParameter('customersus.custom_fields_map');
			$customers_account_id = $this->container->getParameter('customers.account_id')['en'];
			$statuses = $this->container->getParameter('customersus.leads_statuses_map');
			$lang_manager = $this->container->get('phase.i18n_manager')->getLangManager('en');
		} else {
			$customers_api = $this->container->get('amocrm.customers_api');
			$fields = $this->container->getParameter('customers.custom_fields_map');
			$customers_account_id = $this->container->getParameter('customers.account_id')['ru'];
			$statuses = $this->container->getParameter('customers.leads_statuses_map');
			$lang_manager = $this->container->get('phase.i18n_manager')->getLangManager('ru');
		}
		$this->_logger->info('Start sync', ['class' => 'ContactsSync', 'action' => $data['action']]);

		if ($customers_api->auth()) {
			$syncContacts = new SyncContacts($customers_api, $gso_api, $support_api, $statuses, $fields, $logger, $customers_account_id, $lang_manager);
			$syncContacts->{$data['action']}($data['data']);
		} else {
			$this->_logger->error('cant auth in customers', $customers_api->get_full_request_info());
		}
	}
}
