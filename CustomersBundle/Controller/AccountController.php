<?php
namespace CustomersBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


use RuntimeException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

use CustomersBundle\Task\Customer\AccountUpdateTask;
use CustomersBundle\Task\Customer\AccountPaymentTask;

class AccountController extends BaseController
{

    public function accountUpdateAction(Request $request)
    {
        try {
            $event = $request->getContent();
            $data = $this->parseRequestData($event);

            $this->logger->info('Recieve: ',['data'=>$data]);

            $workload = $data;
            $gm_client = $this->container->get('gearman_client');
            $prefix = $this->container->getParameter('customers_worker_prefix');
            $job_id = $gm_client->doBackground($prefix.AccountUpdateTask::QUEUE_NAME, json_encode($workload));
            $this->logger->info('success add in queue', $workload);

        }catch(InvalidArgumentException $e){
            $this->logger->error($e->getMessage(), ['request'=>$event]);
        }catch(RuntimeException $e){
            $this->logger->error($e->getMessage(), ['event'=>$data]);
        }

        return new Response(null,202);
    }

    public function accountPaymentAction(Request $request)
    {

        try {
            $event = $request->getContent();
            $data = $this->parseRequestData($event);

            $this->logger->info('Recieve: ',['data'=>$data]);

            $workload = $data;
            $gm_client = $this->container->get('gearman_client');
            $prefix = $this->container->getParameter('customers_worker_prefix');
            $job_id = $gm_client->doBackground($prefix.AccountPaymentTask::QUEUE_NAME, json_encode($workload));
            $this->logger->info('success add in queue', $workload);
        }catch(InvalidArgumentException $e){
            $this->logger->error($e->getMessage(), ['request'=>$event]);
        }

        return new Response(null,202);
    }
}

