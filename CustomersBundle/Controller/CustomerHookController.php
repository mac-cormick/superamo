<?php
namespace CustomersBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


use RuntimeException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

use CustomersBundle\Task\Customer\CustomerFullUpdateTask;

class CustomerHookController extends BaseController
{

    /*
     * Приходит такая байда в POST
     *array (
     *  'customers' =>
     *  array (
     *    'add' =>
     *    array (
     *      0 =>
     *      array (
     *        'id' => '147958',
     *        'date_create' => '1486651915',
     *        'date_modify' => '1486651915',
     *        'created_by' => '939264',
     *        'modified_by' => '939264',
     *        'account_id' => '9394762',
     *        'main_user_id' => '939264',
     *        'name' => '1364321813643218',
     *        'deleted' => '0',
     *        'next_price' => '0',
     *        'periodicity' => '1',
     *        'next_date' => '1486674000',
     *      ),
     *    ),
     *  ),
     *  'account' =>
     *  array (
     *    'subdomain' => 'customers',
     *  ),
     *)
     */
    public function handleCustomerAddAction(Request $request)
    {
        $customers = $request->request->get('customers');
        $account = $request->request->get('account');

        /** Для любого субдомена !== customersus, или если вдруг субдомен не пришёл, считаем, что пришло из customers
         * что бы в нём же и искать покупателя, иначе будем искать в customersus. Это нужно для уверенности, что не
         * сломаю текущую логику.
         */
        $subdomain = isset($account['subdomain']) && $account['subdomain'] !== 'customersus' ? 'customers' : 'customersus';

        $this->logger->debug('Recieve customer hook: '.json_encode($customers));
        if(isset($customers['add'])){

            $item = reset($customers['add']);

            if(!empty($item['id'])){
                $workload = [
                    'customer_id'=> (int)$item['id'],
                    'subdomain'=> $subdomain,
                ];

                $gm_client = $this->container->get('gearman_client');
                $prefix = $this->container->getParameter('customers_worker_prefix');
                $this->logger->debug('Add customer full sync task',$workload);
                $gm_client->doBackground($prefix.CustomerFullUpdateTask::QUEUE_NAME, json_encode($workload));

            }
        }


        return new Response(null,202);
    }
}

