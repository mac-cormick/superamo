<?php
namespace CustomersBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use RuntimeException;
use InvalidArgumentException;
use CustomersBundle\Task\Sync\SyncContactsTaskNew;

/**
 * Обновление контактов в полкупателе при измении пользователей
 *
 */
class AccountUserController extends BaseController
{

    public function userChangeAction(Request $request)
    {

        try {
            $event = $request->getContent();
            $data = $this->parseRequestData($event);

            $this->addWorkload($data);
        }catch(InvalidArgumentException $e){
            $this->logger->error($e->getMessage(), ['request'=>$event]);
        }catch(RuntimeException $e){
            $this->logger->error($e->getMessage(), ['event'=>$data]);
        }

        return new Response(null,202);
    }

    protected function addWorkload(array $data)
    {
        $gm_client = $this->container->get('gearman_client');

        $user = $data['data']['user'];

        $workload = [
            'action' => $data['event']['name'],
            'data'   => [
                'id'            => $user['id'],
                'email'         => $user['login'],
                'work_phone'    => $user['work_phone'],
                'mobile_phone'  => $user['personal_mobile'],
                'accounts'      => $user['accounts'],
                'name'          => $user['username'],
                'date_register' => $user['date_register'],
				'lid'			=> $user['lid'],
                'avatar'        => $user['photo_path'],
            ],
        ];

        if(isset($data['data']['flags'])) {
            $workload['data']['flags'] = $data['data']['flags'];
        }

        //Изменение режима генерации апи ключа
        if (!empty($data['event']['api_key_mode'])) {
            $workload['data']['api_key_mode'] = $data['event']['api_key_mode'];
            $workload['data']['api_key_mode']['manager_id'] = $data['event']['user_id'];
        }

        $prefix = $this->container->getParameter('customers_worker_prefix');
        $job_id = $gm_client->doBackground($prefix.SyncContactsTaskNew::QUEUE_NAME, json_encode($workload));
        $this->logger->info('success add in queue', $workload);
    }
}
