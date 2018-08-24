<?php

namespace CustomersBundle\Task\Sync;

use Amo\Entity_Element;
use CustomersBundle\Task\BaseTask;
use Phase\AmoCRM_API;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use \Amo\Element;
use \Amo\Note;
use Ramsey\Uuid\Uuid;
use Amo\Crm\SupportApiClient;
use CustomersBundle\Task\Sync\Exceptions\SyncContactsException;

class SyncContactsTask extends BaseTask
{
    const QUEUE_NAME = 'sync_contacts_tasks';
    const QUEUE_FULL_SYNC_NAME = 'full_sync_contacts';

    const ACTION_USER_UPDATE = 'user_update';
    const ACTION_USER_ADD = 'user_add';
    const ACTION_USER_DELETE = 'user_delete';

    const FIELD_NAME = 'name';
    const FIELD_PROFILES = 'profiles';

    const NAME_LEAD = 'Новый аккаунт: ';
//    const NAME_LEAD = 'New Account: ';

    const AMO_SHARD_TYPE_RU = 1;
    const AMO_SHARD_TYPE_COM = 2;

    const TAG_FOR_COM = 'ONLY_COM';
    const CUSTOMERS_TAG_OUTDATED_HASH = 'outdated_hash';

    const FLAG_NAME_CHANGED = 1;
    const FLAG_AVATAR_CHANGED = 2;
    const FLAG_API_RELATED_HASH_SWITCHED = 4;

    const TYPE_AMOCRM_PROFILE = 'amocrm';

    /** @var array  */
    protected $_access_actions = [
        self::ACTION_USER_UPDATE,
        self::ACTION_USER_ADD,
        self::ACTION_USER_DELETE,
        self::QUEUE_FULL_SYNC_NAME,
    ];

    protected $_lid_by_langs = [
        'en' => [
            's3',
            's4',
            'sp',
            'es',
        ],
        'ru' => [
            's2',
            'kz',
            'ua',
            's1',
        ],
    ];

    /** @var \Phase\AmoCRM_API $api */
    protected $api;
    /** @var \Monolog\Logger $logger */
    protected $logger;

    /** @var \Amo\Crm\GsoApiClient  */
    protected $gso_api;

	/** @var SupportApiClient */
    protected $supportApi;

    protected $cf_contacts_user_id;

    protected $cf_contacts_email;
    protected $cf_contacts_email_enum_work;

    protected $cf_contacts_phone;
    protected $cf_contacts_phone_enum_work;
    protected $cf_contacts_phone_enum_mob;

    protected $cf_customers_account_id;
    protected $cf_leads_account_id;

    protected $leads_status_no_qualified;
    protected $leads_status_win;
    protected $leads_status_lost;
    /** @var int  */
    protected $size_chunks = 20;

    /**
     * {@inheritDoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);

        if($this->container){
            $handler = $this->container->get('monolog.handler.customers_account_user');
            $this->logger = new Logger('customers_account_user', [
                    new StreamHandler('php://stdout'),
                    $handler,
                ]
            );
            $this->api = $this->container->get('amocrm.customers_api');
//            $this->api = $this->container->get('amocrm.customersus_api');
            $this->gso_api = $this->container->get('amocrm.gso_api');
            $this->supportApi = $container->get('amocrm.support_api');
            //маппинг id свойств
            $statuses = $this->container->getParameter('customers.leads_statuses_map');

            $this->leads_status_no_qualified = $statuses['first']['no_qualified']['id'];
            $this->leads_status_win = $statuses['first']['win']['id'];
            $this->leads_status_lost = $statuses['first']['lost']['id'];

            $fields = $this->container->getParameter('customers.custom_fields_map');
//            $fields = $this->container->getParameter('customersus.custom_fields_map');

            $this->cf_contacts_user_id = $fields['contacts']['user_id']['id'];

            $this->cf_contacts_email = $fields['contacts']['email']['id'];
            $this->cf_contacts_email_enum_work = $fields['contacts']['email']['enums']['work'];

            $this->cf_contacts_phone = $fields['contacts']['phone']['id'];
            $this->cf_contacts_phone_enum_work = $fields['contacts']['phone']['enums']['work'];
            $this->cf_contacts_phone_enum_mob = $fields['contacts']['phone']['enums']['mob'];

            $this->cf_customers_account_id = $fields['customers']['account_id']['id'];
            $this->cf_leads_account_id = $fields['leads']['account_id']['id'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function execute(array $data)
    {
        $api = $this->api;
        $logger = $this->logger;

        if(!empty($data['action']) && in_array($data['action'], $this->_access_actions)) {
            /** @var \Monolog\Logger $logger */
            $logger->info('Start sync', ['class' => 'ContactsSync' , 'action' => $data['action']]);
            /** @var \Phase\AmoCRM_API $api */

            if($api->auth()) {
                $this->{$data['action']}($data['data']);
            } else {
                $logger->error('cant auth in customers', $api->get_full_request_info());
            }
        }
    }

    /**
     * @param array $data
     * @param array|NULL $contact
     * @param bool $only_return
     *
     * @return array
     */
    protected function user_update(array $data, array $contact = NULL, $only_return = FALSE)
    {
        $api = $this->api;
        $logger = $this->logger;
        $data_for_update = [];
        if((int)$data['id'] > 0) {
            $contact = $contact ? $contact : $this->find_by_cf($data['id'], $this->cf_contacts_user_id, $api::ELEMENT_CONTACTS);
            if($contact !== FALSE) {
                $logger->info('find contact: '. $contact['id']);
                $need_update = [
                    $this->cf_contacts_email => [
                        $this->cf_contacts_email_enum_work => TRUE,
                    ],
                    $this->cf_contacts_phone => [
                        $this->cf_contacts_phone_enum_mob  => TRUE,
                        $this->cf_contacts_phone_enum_work => TRUE,
                    ],
                    self::FIELD_NAME => !empty($data['flags']) &&
                        in_array(self::FLAG_NAME_CHANGED, $data['flags']) &&
                        $data['name'] !== $contact['name'],
                    self::FIELD_PROFILES => !empty($data['flags']) &&
                        in_array(self::FLAG_AVATAR_CHANGED, $data['flags']) &&
                        !empty($data['avatar']),
                ];
                $contact_fields = [];
                foreach ($contact['custom_fields'] as $custom_field) {
                    if($custom_field['id'] == $this->cf_contacts_email) {
                        foreach ($custom_field['values'] as $value) {
                            if($value['value'] === $data['email']) {
                                $need_update[$this->cf_contacts_email][$this->cf_contacts_email_enum_work] = FALSE;
                                $logger->info('no need update email');
                            }
                            $contact_fields[$this->cf_contacts_email][] = [
                                'value' => $value['value'],
                                'enum'  => $value['enum'],
                            ];
                        }
                    }
                    if($custom_field['id'] == $this->cf_contacts_phone) {
                        foreach ($custom_field['values'] as $value) {
                            if($value['value'] === $data['work_phone'] &&
                                (int)$value['enum'] === $this->cf_contacts_phone_enum_work) {
                                $need_update[$this->cf_contacts_phone][$this->cf_contacts_phone_enum_work] = FALSE;
                                $logger->info('no need update work phone');
                            }

                            if ($value['value'] === $data['mobile_phone'] &&
                                (int)$value['enum'] === $this->cf_contacts_phone_enum_mob) {
                                $need_update[$this->cf_contacts_phone][$this->cf_contacts_phone_enum_mob] = FALSE;
                                $logger->info('no need update mob phone');
                            }
                            $contact_fields[$this->cf_contacts_phone][] = [
                                'value' => $value['value'],
                                'enum'  => $value['enum'],
                            ];
                        }
                    }
                }
                foreach ($need_update as $type => $subtype) {
                    if($type == $this->cf_contacts_email && $subtype[$this->cf_contacts_email_enum_work]) {
                        $contact_fields[$this->cf_contacts_email][] = [
                            'value' => $data['email'],
                            'enum' => $this->cf_contacts_email_enum_work
                        ];
                        $data_for_update['custom_fields'][] = [
                            'id' => $this->cf_contacts_email,
                            'values' => $contact_fields[$this->cf_contacts_email],
                        ];
                    } elseif($type == $this->cf_contacts_phone && ($subtype[$this->cf_contacts_phone_enum_mob] || $subtype[$this->cf_contacts_phone_enum_work])) {
                        if($subtype[$this->cf_contacts_phone_enum_mob]) {
                            $contact_fields[$this->cf_contacts_phone][] = [
                                'value' => $data['mobile_phone'],
                                'enum' => $this->cf_contacts_phone_enum_mob
                            ];
                        }
                        if ($subtype[$this->cf_contacts_phone_enum_work]) {
                            $contact_fields[$this->cf_contacts_phone][] = [
                                'value' => $data['work_phone'],
                                'enum' => $this->cf_contacts_phone_enum_work
                            ];
                        }
                        $data_for_update['custom_fields'][] = [
                            'id'     => $this->cf_contacts_phone,
                            'values' => $contact_fields[$this->cf_contacts_phone],
                        ];
                    } elseif($type === self::FIELD_NAME && $subtype) {
                        $data_for_update['name'] = $data['name'];
                    } elseif ($type === self::FIELD_PROFILES && $subtype) {
                        $data_for_update[self::FIELD_PROFILES] = [
                            self::TYPE_AMOCRM_PROFILE => [
                                'profile_avatar' => $data['avatar'],
                                'hidden'         => 1,
                                'profile_id'     => $data['id'],
                            ]
                        ];
                    }
                }

                if(!empty($data_for_update)) {
                    $data_for_update['id'] = $contact['id'];
                    $data_for_update['last_modified'] = $contact['last_modified'] > time() ? $contact['last_modified'] + 100 : time();
                    if(!$only_return) {
                        $res = $api->update($api::ELEMENT_CONTACTS, [$data_for_update]);
                        if(!$res) {
                            $logger->error('error update', [$data_for_update, $api->get_full_request_info()]);
                        } else {
                            $data_for_update['id'] = $contact['id'];
                            $data_for_update['last_modified'] = $contact['last_modified'] > time() ? $contact['last_modified'] + 100 : time();
                            $logger->info('success update contact');
                        }
                    }
                } else {
                    $logger->info('nothing update', $data);
                }

                /**
                 * Обработаем смену режима генерации апи ключа для пользователя
                 */
                try {
                    $this->handle_api_key_switch($data, $logger, $api);
                } catch (SyncContactsException $exception) {
                    $logger->warning($exception->getMessage());
                }

            } else {
                $logger->info('not found');
            }
        } else {
            $logger->error('wrong data', $data);
        }

        return $data_for_update;
    }

    /**
     * @param array $data
     */
    protected function user_add(array $data)
    {
        $api = $this->api;
        $logger = $this->logger;

        $customers = [];
        $leads = [];
        $responsible_user_id = 0;
        $find_ru_account = FALSE;
        foreach($data['accounts'] as $account_info) {
            $account_id = !empty($account_info['id']) ? $account_info['id'] : $account_info;

            if(empty($account_info['shard_type']) || !$find_ru_account && $account_info['shard_type'] == self::AMO_SHARD_TYPE_RU) {
                $find_ru_account = TRUE;
            }
            $customer = $this->find_by_cf($account_id, $this->cf_customers_account_id, $api::ELEMENT_CUSTOMERS);
            if(!empty($customer)) {
                $customers[] = $customer['id'];
                if($responsible_user_id == 0) {
                    $responsible_user_id = (int)$customer['main_user_id'];
                }
            }
            $lead = [];
            $finded_leads = $api->find($api::ELEMENT_LEADS, self::NAME_LEAD.$account_id);
            if(!empty($finded_leads)) {
                foreach($finded_leads as $key => $lead_info) {
                    if($lead_info['name'] === self::NAME_LEAD.$account_id) {
                        $lead[] = $finded_leads[$key];
                    }
                }
                if(!empty($lead)) {
                    if(count($lead) > 1) {
                        $logger->warning('Too many leads for one account', [self::NAME_LEAD.$account_id]);
                    }
                    $lead = reset($lead);
                    if($responsible_user_id == 0) {
                        $responsible_user_id = (int)$lead['responsible_user_id'];
                    }
                    $leads[] = $lead['id'];
                }
            }
        }
        if($find_ru_account) {
            $this->logger->info('find RU account');
        } else {
            $this->logger->info('find only EN account');
        }

        $logger->info('find customers: ', $customers);
        $logger->info('find leads: ', $leads);

        if(empty($leads) && empty($customers)) {
            $logger->warning('Lead and Customer not found', $data['accounts']);
        }
        $logger->info('user_field_id: ', [$this->cf_contacts_user_id]);
        $contact = $this->find_by_cf($data['id'], $this->cf_contacts_user_id, $api::ELEMENT_CONTACTS);
        if($contact !== FALSE) {
            $logger->info('find contact: ' . $contact['id']);

            $this->update_links_deals($leads, $contact);
        } else {
            foreach($this->_lid_by_langs as $lang => $lids) {
                if(in_array($data['lid'], $lids)) {
                    if($lang === 'ru') {
                        $find_ru_account = TRUE;
                    }
                    break;
                }
            }
            $logger->info('Not found');
//			$find_ru_account = TRUE;
            if($find_ru_account) {
                $logger->info('Need create');
                $data_for_create = [
                    'name'                => $data['name'],
                    'linked_leads_id'     => $leads,
                    'date_create'         => $data['date_register'],
                    'responsible_user_id' => $responsible_user_id,
                    'custom_fields'       => [
                        [
                            'id'     => $this->cf_contacts_email,
                            'values' => [
                                [
                                    'value' => $data['email'],
                                    'enum'  => $this->cf_contacts_email_enum_work,
                                ]
                            ]
                        ],
                        [
                            'id'     => $this->cf_contacts_phone,
                            'values' => [
                                [
                                    'value' => $data['work_phone'],
                                    'enum'  => $this->cf_contacts_phone_enum_work,
                                ],
                                [
                                    'value' => $data['mobile_phone'],
                                    'enum'  => $this->cf_contacts_phone_enum_mob,
                                ]
                            ]
                        ],
                        [
                            'id'     => $this->cf_contacts_user_id,
                            'values' => [
                                [
                                    'value' => $data['id']
                                ]
                            ]
                        ]
                    ]
                ];
                $res = $api->add($api::ELEMENT_CONTACTS, [$data_for_create]);
                if(!$res) {
                    $logger->error('error add', [$data_for_create, $api->get_full_request_info()]);
                } else {
                    $contact['id'] = reset($api->get_response()['response']['contacts']['add'])['id'];
                    $logger->info('success add contact: ' . $contact['id']);
                }
            } else{
                $logger->info('User has only com account, skip');
            }
        }

        if((int)$contact['id'] > 0) {
            $this->update_links_customers($customers, $contact['id']);
            $this->logger->info('add info to GSO');
            $human = [
                'contact_id'  => $contact['id'],
                'visitor_uid' => Uuid::uuid5(Uuid::NAMESPACE_OID, $data['id'])->toString(),
            ];
            $this->gso_api->set_human($human);
        }
    }

    /**
     * @param array $data
     */
    protected function user_delete(array $data)
    {
        $api = $this->api;
        $logger = $this->logger;
        $customers = [];
        $leads = [];
        $find_ru_account = FALSE;
        foreach($data['accounts'] as $account_info) {
            $account_id = !empty($account_info['id']) ? $account_info['id'] : $account_info;
            if(empty($account_info['shard_type']) || !$find_ru_account && $account_info['shard_type'] == self::AMO_SHARD_TYPE_RU) {
                $find_ru_account = TRUE;
            }
            $customer = $this->find_by_cf($account_id, $this->cf_customers_account_id, $api::ELEMENT_CUSTOMERS);
            if(!empty($customer)) {
                $customers[] = $customer['id'];
            }

            $lead = [];
            $finded_leads = $api->find($api::ELEMENT_LEADS, self::NAME_LEAD.$account_id);
            $logger->info('finded_leads: ', [$finded_leads]);
            if(!empty($finded_leads)) {
                foreach($finded_leads as $lead_info) {
                    if($lead_info['name'] === self::NAME_LEAD.$account_id) {
                        $lead[] = $lead_info;
                    }
                }
                if(!empty($lead)) {
                    if(count($lead) > 1) {
                        $logger->error('Too many leads for one account', [self::NAME_LEAD.$account_id]);
                    }
                    $lead = reset($lead);
                    $leads[] = $lead['id'];
                }
            }
        }

        $logger->info('find customers: ', $customers);
        $logger->info('find leads: ', $leads);

        $contact = $this->find_by_cf($data['id'], $this->cf_contacts_user_id, $api::ELEMENT_CONTACTS);
        if($contact !== FALSE) {
            $logger->info('find contact: ' . $contact['id']);
            $additional_info = $this->get_tags($contact, $find_ru_account, $data);
            $logger->info('additional info: ', [$additional_info]);
            $this->update_links_deals($leads, $contact, $additional_info);
            if((int)$contact['id'] > 0) {
                $this->update_links_customers($customers, $contact['id']);
            }
        } else {
            $logger->error('contact not found', $data);
        }
    }

    /**
     * @param array $contact
     * @param bool $find_ru_account
     * @param array $data
     *
     * @return array
     */
    protected function get_tags(array $contact, $find_ru_account, array $data) {
        $need_update_tag = FALSE;
        $additional_info = [];
        $tags = [];

        foreach($contact['tags'] as $tag) {
            $tags[$tag['name']] = $tag['name'];
        }

        if($find_ru_account && isset($tags[self::TAG_FOR_COM])) {
            unset($tags[self::TAG_FOR_COM]);
            $need_update_tag = TRUE;

        } elseif(!$find_ru_account) {
            if(empty($data['accounts'])) {
                foreach($this->_lid_by_langs as $lang => $lids) {
                    if(in_array($data['lid'], $lids)) {
                        if($lang === 'en') {
                            $need_update_tag = TRUE;
                        }
                        break;
                    }
                }
            } else {
                $need_update_tag = TRUE;
            }
            if($need_update_tag) {
                $tags[self::TAG_FOR_COM] = self::TAG_FOR_COM;
            }
        }

        if($need_update_tag) {
            $additional_info = [
                'tags' => implode(', ', $tags),
            ];
        }
        $this->logger->info('tags for add', $additional_info);
        return $additional_info;
    }

    /**
     * @param int $contact_id
     *
     * @return array
     */
    protected function get_links_customers($contact_id)
    {
        $api = $this->api;

        $params = [
            'links' => [
                [
                    'from'    => $api::ELEMENT_CONTACTS,
                    'from_id' => $contact_id,
                ],
            ],
        ];

        $links = $api->find($api::ELEMENT_LINKS, $params);
        $links = !empty($links) ? $this->filter_links_by_type($links, $api::ELEMENT_CUSTOMERS) : [];

        return $links;
    }

    /**
     * @param array $customers
     * @param int $contact_id
     * @param array $links
     * @param bool $only_return
     *
     * @return array
     */
    protected function update_links_customers(array $customers, $contact_id, $links = [], $only_return = FALSE)
    {
        $return = [
            'link' => [],
            'unlink' => [],
        ];
        $api = $this->api;
        $logger = $this->logger;

        if(empty($links)) {
            $links = $this->get_links_customers($contact_id);
        }

        $customers_for_link = array_diff($customers, $links);
        if(!empty($customers_for_link)){
            $data_for_links = [];
            foreach($customers_for_link as $customer_id) {
                $data_for_links[] = [
                    'from'          => $api::ELEMENT_CUSTOMERS,
                    'from_id'       => $customer_id,
                    'to'            => $api::ELEMENT_CONTACTS,
                    'to_id'         => $contact_id,
                ];
            }
            if(!$only_return) {
                $res = $api->action('link', $api::ELEMENT_LINKS, $data_for_links);
                if(!$res) {
                    $logger->error('error links customer', [$data_for_links, $api->get_full_request_info()]);
                } else {
                    $logger->info('success links customer', $data_for_links);
                }
            }
            $return['link'] = $data_for_links;
        }

        $customers_for_unlink = array_diff($links, $customers);
        if(!empty($customers_for_unlink)) {
            $data_for_unlinks = [];
            foreach($customers_for_unlink as $customer_id) {
                $data_for_unlinks[] = [
                    'from'          => $api::ELEMENT_CUSTOMERS,
                    'from_id'       => $customer_id,
                    'to'            => $api::ELEMENT_CONTACTS,
                    'to_id'         => $contact_id,
                ];
            }
            if(!$only_return) {
                $res = $api->action('unlink', $api::ELEMENT_LINKS, $data_for_unlinks);
                if(!$res) {
                    $logger->error('error unlinks customer', [$data_for_unlinks, $api->get_full_request_info()]);
                } else {
                    $logger->info('success unlinks customer', $data_for_unlinks);
                }
            }
            $return['unlink'] = $data_for_unlinks;
        }
         return $return;
    }

    /**
     * @param array $leads
     * @param array $contact
     * @param array $additional_info
     */
    protected function update_links_deals(array $leads, array $contact, array $additional_info = [])
    {
        $api = $this->api;
        $logger = $this->logger;


        $data_for_update = [
            'id'              => $contact['id'],
            'last_modified'   => $contact['last_modified'] > time() ? $contact['last_modified'] + 100 : time(),
            'linked_leads_id' => $leads,
        ] + $additional_info;

        $res = $api->update($api::ELEMENT_CONTACTS, [$data_for_update]);
        if(!$res) {
            $logger->error('error links leads', [$data_for_update, $api->get_full_request_info()]);
        } else {
            $logger->info('success links leads', $data_for_update['linked_leads_id']);
        }
    }

    /**
     * @param array $links
     * @param string $type
     *
     * @return array
     */
    protected function filter_links_by_type(array $links, $type)
    {
        $result = [];

        foreach ($links as $link) {
            if (isset($link['to'], $link['to_id']) && $link['to'] === $type) {
                $result[$link['to_id']] = $link['to_id'];
            }
        }

        return $result;
    }


    /**
     * @param $data
     * @param $cf_id
     * @param $entity
     *
     * @return array|false|mixed
     */
    protected function find_by_cf($data, $cf_id, $entity)
    {
        $api = $this->api;
        $logger = $this->logger;

        $find = [
            'filter' => [
                'custom_fields' => [
                    $cf_id => [
                        'from'  => $data,
                        'to'    => $data
                    ]
                ]
            ]
        ];
        $element = $api->find($entity, $find);

        if(empty($element)) {
            $logger->info('Element not found', [$data]);
            return false;
        }
        if(count($element) > 1) {
            $logger->warning('Too many elements', [$data]);
        }
        $element = reset($element);

        return $element;
    }

    /**
     * @param array $users = [
     *                 [
     *                  'id'            => int,
     *                    'email'         => string,
     *                    'work_phone'    => string,
     *                    'mobile_phone'  => string,
     *                    'accounts'      => [
     *                        (int)$account_id => [
     *                             'need_create' => bool,
     *                             'date_create' => int,
     *                             'pay_type'    => string
     *                         ],
     *                     ],
     *                    'name'          => string,
     *                    'date_register' => int,
     *                 ]
     *             ]
     */
    public function full_sync_contacts(array $users) {
        $api     = $this->api;
        $logger = $this->logger;

        $customers           = [];
        $leads_for_update = [];
        $notes_for_add      = [];
        $notes_for_update = [];
        $contacts  = [
            'update' => [],
            'add'     => [],
        ];

        foreach($users as $user) {
            $leads_for_add = [];
            $leads = [];
            $responsible_user_id = 0;
            foreach($user['accounts'] as $account_id => $account_info) {
                if(empty($customers[$account_id])) {
                    $customer = $this->find_by_cf($account_id, $this->cf_customers_account_id, $api::ELEMENT_CUSTOMERS);
                    if(!empty($customer)) {
                        $customers[$account_id] = $customer['id'];
                        if($responsible_user_id == 0) {
                            $responsible_user_id = (int)$customer['main_user_id'];
                        }
                    }
                }

                $lead = [];
                $finded_leads = $api->find($api::ELEMENT_LEADS, self::NAME_LEAD.$account_id);
                if(!empty($finded_leads)) {
                    foreach($finded_leads as $lead_info) {
                        if($lead_info['name'] === self::NAME_LEAD.$account_id) {
                            $lead[] = $lead_info;
                        }
                    }
                    if(!empty($lead)) {
                        if(count($lead) > 1) {
                            $logger->warning('Too many leads for one account', [self::NAME_LEAD.$account_id]);
                        }
                        $lead = reset($lead);
                        if($responsible_user_id == 0) {
                            $responsible_user_id = (int)$lead['responsible_user_id'];
                        }
                        $leads[] = $lead['id'];
                    }
                }

                if(empty($lead) && !empty($account_info['need_create'])) {
                    switch($account_info['pay_type']) {
                        case 'blocked':
                            $status_id = $status_new = $this->leads_status_lost;
                            break;
                        case 'paid':
                            $status_id = $this->leads_status_no_qualified;
                            $status_new = $this->leads_status_win;
                            break;
                        case 'trial':
                        default:
                            $status_id = $status_new = $this->leads_status_no_qualified;
                            break;
                    }

                    $leads_for_add[] = [
                        'name'               => self::NAME_LEAD.$account_id,
                        'date_create'     => $account_info['date_create'],
                        'status_id'       => $status_id,
                        'status_new'    => $status_new,
                        'customer_id'    => !empty($customers[$account_id]) ? $customers[$account_id] : NULL,
                        'tags'              => 'New lead',
                        'custom_fields' => [
                            [
                                'id' => $this->cf_leads_account_id,
                                'values' => [
                                    [
                                        'value' => $account_id
                                    ]
                                ],
                            ]
                        ],
                    ];
                }
            }
            if(!empty($leads_for_add)) {
                $chunks = array_chunk($leads_for_add, $this->size_chunks);
                foreach($chunks as $chunk) {
                    $res = $api->add($api::ELEMENT_LEADS, $chunk);
                    if(!$res) {
                        $logger->error('error add lead', [$chunk, $api->get_full_request_info()]);
                    } else {
                        foreach($api->get_response()['response'][$api::ELEMENT_LEADS]['add'] as $key => $lead) {
                            $logger->info('success add lead: ' . $lead['id']);
                            $leads[] = $lead['id'];

                                $leads_for_update[] = [
                                    'id' => $lead['id'],
                                    'last_modified' => time() + 100,
                                    'status_id' => $chunk[$key]['status_new'],
                                ];
                            if(!empty($chunk[$key]['customer_id'])) {
                                $note_text_data = [
                                    'lead_id'     => $lead['id'],
                                    'customer_id' => $chunk[$key]['customer_id'],
                                ];
                                $notes_for_add[] = [
                                    'element_id'   => $lead['id'],
                                    'element_type' => Element::LEADS_TYPE,
                                    'note_type'       => Note::CUSTOMER_CREATED_TYPE,
                                    'text'           => json_encode($note_text_data),
                                    'date_create'  => $chunk[$key]['date_create'],
                                ];

                                $note = $this->find_customer_creation_note($chunk[$key]['customer_id']);
                                $note_data = json_decode($note['text'], TRUE);
                                foreach($note_text_data as $k => $v) {
                                    if(empty($note_data[$k])) {
                                        $note_data[$k] = $v;
                                    }
                                }
                                if(!empty($note)) {
                                    $notes_for_update[] = [
                                        'id'            => $note['id'],
                                        'last_modified' => $note['last_modified'] + 100,
                                        'text'          => json_encode($note_data),
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            $logger->info('find customers: ', $customers);
            $logger->info('find leads: ', $leads);

            if(empty($leads) && empty($customers)) {
                $logger->warning('Lead and Customer not found', $user['accounts']);
            }

            $contact = $this->find_by_cf($user['id'], $this->cf_contacts_user_id, $api::ELEMENT_CONTACTS);

            if($contact !== FALSE) {
                $contact_for_update = $this->user_update($user, $contact, TRUE);
                $contact_for_update['linked_leads_id'] = $leads;
                $contact_for_update['account_ids'] = array_keys($user['accounts']);
                $contact_for_update['last_modified'] = !empty($contact_for_update['last_modified']) ?
                    $contact_for_update['last_modified'] : time();
                $contact_for_update['id'] =  !empty($contact_for_update['id']) ?
                    $contact_for_update['id'] : $contact['id'];
                $contacts['update'][] = $contact_for_update;
            } else {
                $logger->info('not found... need create');
                $contacts['add'][] = [
                    'name'                => $user['name'],
                    'linked_leads_id'     => $leads,
                    'date_create'         => strtotime($user['date_register']),
                    'tags'                  => 'New contact',
                    'responsible_user_id' => $responsible_user_id,
                    'account_ids'          => array_keys($user['accounts']),
                    'custom_fields'       => [
                        [
                            'id'     => $this->cf_contacts_email,
                            'values' => [
                                [
                                    'value' => $user['email'],
                                    'enum'  => $this->cf_contacts_email_enum_work,
                                ]
                            ]
                        ],
                        [
                            'id'     => $this->cf_contacts_phone,
                            'values' => [
                                [
                                    'value' => $user['work_phone'],
                                    'enum'  => $this->cf_contacts_phone_enum_work,
                                ],
                                [
                                    'value' => $user['mobile_phone'],
                                    'enum'  => $this->cf_contacts_phone_enum_mob,
                                ]
                            ]
                        ],
                        [
                            'id'     => $this->cf_contacts_user_id,
                            'values' => [
                                [
                                    'value' => $user['id']
                                ]
                            ]
                        ]
                    ]
                ];
            }
        }
        $contacts_ids  = [];
        foreach($contacts as $action => $contacts_by_action) {
            $logger->info('start ' . $action . ' contact', $contacts_by_action);
            $chunks = array_chunk($contacts_by_action, $this->size_chunks);
            foreach($chunks as $chunk) {
                $res = $api->{$action}($api::ELEMENT_CONTACTS, $chunk);
                if(!$res) {
                    $logger->error('error ' . $action . ' contact', [$chunk, $api->get_full_request_info()]);
                } else {
                    $response = $api->get_response()['response'][$api::ELEMENT_CONTACTS][$action];
                    foreach($response as $key => $contact) {
                        $logger->info('success '. $action .' contact: ' . $contact['id']);
                        $account_ids = $chunk[$key]['account_ids'];

                        foreach($account_ids as $account_id){
                            if($account_id > 0) {
                                $contacts_ids[$contact['id']][] = $account_id;
                            } else {
                                $logger->error('no found account id');
                            }
                        }
                    }
                }
            }
        }
        if(!empty($notes_for_add)) {
            $chunks = array_chunk($notes_for_add, $this->size_chunks);
            foreach($chunks as $chunk) {
                $res = $api->add($api::ELEMENT_NOTES, $chunk);
                if(!$res) {
                    $logger->error('error create notes');
                } else {
                    $logger->info('success add notes');
                }
            }
        }

        if(!empty($notes_for_update)) {
            $chunks = array_chunk($notes_for_update, $this->size_chunks);
            foreach($chunks as $chunk) {
                $res = $api->update($api::ELEMENT_NOTES, $chunk);
                if(!$res) {
                    $logger->error('error update notes');
                } else {
                    $logger->info('success update notes');
                }
            }
        }

        if(!empty($leads_for_update)) {
            $chunks = array_chunk($leads_for_update, $this->size_chunks);
            foreach($chunks as $chunk) {
                $res = $api->update($api::ELEMENT_LEADS, $chunk);
                if(!$res) {
                    $logger->error('error update notes');
                } else {
                    $logger->info('success update leads');
                }
            }
        }
        if(!empty($contacts_ids)) {
            $this->update_group_links_customers($customers, $contacts_ids);
        }
    }

    protected function update_group_links_customers(array $customers, array $contacts) {
        $api = $this->api;
        $logger = $this->logger;
        $links = [
            'link'      => [],
            'unlink' => [],
        ];
        foreach($contacts as $contact_id => $account_ids) {
            $customers_for_user = [];
            foreach($account_ids as $account_id) {
                if(!empty($customers[$account_id])) {
                    $customers_for_user[] = $customers[$account_id];
                }
            }
            $links = array_merge_recursive(
                $links,
                $this->update_links_customers($customers_for_user, $contact_id, [], TRUE)
            );
        }
        foreach($links as $action => $data) {
            if(empty($data)) {
                $logger->info('empty '.$action);
            }

            $chunks = array_chunk($data, $this->size_chunks);
            foreach($chunks as $chunk) {
                $res = $api->action($action, $api::ELEMENT_LINKS, $chunk);
                if(!$res) {
                    $logger->error('error links customer', [$chunk, $api->get_full_request_info()]);
                } else {
                    $logger->info('success links customer', $chunk);
                }
            }
        }
    }

    protected function find_customer_creation_note($customer_id) {
        $result = NULL;
        $api = $this->api;
        $notes = $api->find($api::ELEMENT_NOTES, [
            'type'       => 'customer',
            'element_id' => $customer_id,
            'note_type'  => Note::CUSTOMER_CREATED_TYPE,
        ]);

        if (!empty(reset($notes)['id'])) {
            $result = reset($notes);
        }

        return $result;
    }

    /**
     * Обработчик события ручной смены генерации апи ключа сотрудником ТП
     *
     * @param array $data
     * @param LoggerInterface $logger
     * @param AmoCRM_API $api
     *
     * @throws SyncContactsException;
     */
    protected function handle_api_key_switch(array $data, LoggerInterface $logger, AmoCRM_API $api)
    {
        if (isset($data['flags']) && in_array(self::FLAG_API_RELATED_HASH_SWITCHED, $data['flags'])
            && !empty($account_id = $data['api_key_mode']['account_id'])
        ) {
            $logger->info('User\'s api key mode was switched. Event processing started...');

            $entity = [];
            if ($customer = $this->find_by_cf($account_id, $this->cf_customers_account_id, $api::ELEMENT_CUSTOMERS)) {
                $logger->info(sprintf('Customer %d found by account id %s', $customer['id'], $account_id));

                $entity = $customer;
                $entity['type'] = Element::CUSTOMERS_TYPE;
            } elseif ($leads = $api->find($api::ELEMENT_LEADS, self::NAME_LEAD . $account_id)) {
                if (count($leads) > 1) {
                    $logger->warning('Too many leads ', var_export($leads, true));
                }

                $lead = reset($leads);
                $logger->info(sprintf('Lead %d found by account id %s', $lead['id'], $account_id));

                $entity = $lead;
                $entity['type'] = Element::LEADS_TYPE;
            }

            if (empty($entity)) {
                throw new SyncContactsException('Entity not found.');
            }
            /**
             * Получим информацию о сотруднике
             */
            $customers_account_id = $this->container->getParameter('customers.account_id')['ru'];
            $manager = [];
            $default_manager = [
                'name' => 'Пользователь удалён',
            ];

            if (empty($manager_id = $data['api_key_mode']['manager_id'])) {
                $logger->warning('Not passed manager id. Default manager\'s name used');
            } elseif (empty($manager = $this->supportApi->getAccountUser($customers_account_id, $manager_id))) {
                $logger->warning(sprintf('User %d not found in account Customers. Default manager\'s name used', $manager_id));
            }

            $logger->info('Manager\'s data received ' . var_export($manager, true));

            /**
             * Создадим и добавим примечание
             */
            $manager = !empty($manager) ? reset($manager) : $default_manager;
            $text = sprintf('Сотрудник %s %s поддержку старого API-ключа для пользователя %d',
                $manager['name'],
                $data['api_key_mode']['use_account_related_hash'] === false ? 'включил' : 'отключил',
                $data['id']
            );

            $note = [
                'element_id' => $entity['id'],
                'element_type' => $entity['type'],
                'text' => json_encode(['text' => $text, 'service' => 'Робот']),
                'note_type' => Note::SERVICE_MESSAGE_TYPE
            ];

            if (!$this->api->add(AmoCRM_API::ELEMENT_NOTES, [$note])) {
                $this->logger->info('Adding note error. ' . var_export($note, true));
            } else {
                $this->logger->info('Note added.');
            }

            /**
             * Проверим пользователей аккаунта и присвоим тег, если надо
             */
            if (empty($users = $this->supportApi->getAccountUsers($account_id))) {
                $logger->warning(sprintf('Users not received. Account %d', $account_id));

            } else {
                $update = false;
                $has_api_new_key = empty(array_filter($users, function ($user) {
                    return empty($user['account_related_hash']);
                }));

                $entity_data = [];
                $entity_data['id'] = $entity['id'];
                if (!empty($entity['last_modified'])) {
                    $entity_data['last_modified'] = AmoCRM_API::update_last_modified($entity['last_modified']);
                } elseif (!empty($entity['date_modify'])) {
                    $entity_data['date_modify'] = AmoCRM_API::update_last_modified($entity['date_modify']);
                }

                if ($entity['type'] === Element::LEADS_TYPE) {
                    $entity_data['modified_user_id'] = AmoCRM_API::BOT_USER_ID;
                } else {
                    $entity_data['modified_by'] = AmoCRM_API::BOT_USER_ID;
                }

                $tags = array_column($entity['tags'], 'name');
                if ($has_api_new_key === false) {
                    if (!in_array(self::CUSTOMERS_TAG_OUTDATED_HASH, $tags)) {
                        $tags[] = self::CUSTOMERS_TAG_OUTDATED_HASH;
                        $update = true;
                    }
                } else {
                    if (in_array(self::CUSTOMERS_TAG_OUTDATED_HASH, $tags)) {
                        foreach ($tags as $key => $tag) {
                            if ($tag === self::CUSTOMERS_TAG_OUTDATED_HASH) {
                                unset($tags[$key]);
                                $update = true;
                                break;
                            }
                        }
                    }
                }

                $entity_data['tags'] = $tags;

                if ($update) {
                    if ($this->api->update(Entity_Element::get_entity_by_type($entity['type']), [$entity_data])) {
                        $logger->info('Entity tags updated.');
                    } else {
                        $logger->warning('Entity updating error');
                    }
                }
            }

            $logger->info('Event processing completed.');
        }
    }
}
