<?php
namespace CustomersBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use RuntimeException;
use InvalidArgumentException;

use Phase\AmoCRM_API;
use Psr\Log\LoggerInterface;

use Amo\Element;
use Phase\CustomGenerator;

/**
 * Обновление контактов в полкупателе при измении пользователей
 *
 */
class ZendeskController extends BaseController
{
    /** @var AmoCRM_API $api */
    protected $api;

    protected $cf_field;

    public function __construct(LoggerInterface $logger,AmoCRM_API $api, array $custom_fields)
    {
        parent::__construct($logger);
        $this->api = $api;

        if(!isset($custom_fields['customers']['zendesk_phase'])){
            throw new RuntimeException('zendesk custom field not configured');
        }
        $this->cf_field =  $custom_fields['customers']['zendesk_phase'];
    }

    public function statusAction(Request $request)
    {

        try {
            $event = $request->getContent();
            $data = json_decode($event,true);

            if (empty($data['event'])) {
                throw new \InvalidArgumentException('Empty event');
            }

            if('status' !== $data['event'] ){
                throw new \InvalidArgumentException('Wrong event type:'.$data['event']);
            }

            $this->handleStatusEvent($data);
        }catch(InvalidArgumentException $e){
            $this->logger->error($e->getMessage(), ['request'=>$event]);
        }catch(RuntimeException $e){
            $this->logger->error($e->getMessage(), ['event'=>$data]);
        }

        return new Response(null,200);
    }

    protected function handleStatusEvent(array $data)
    {
        $logger = $this->logger;
        $api = $this->api;

        if (!$api->auth()) {
            $logger->info(var_export($data, TRUE));
            $logger->error('action: zendesk status, error: Unathorized');
            throw new \RuntimeException('Unauthorized');
        }

        if (!empty($data['email'])) {
            $logger->info('Search by email ' . $data['email']);
            $customers = $api->find(AmoCRM_API::ELEMENT_CUSTOMERS, $data['email']);
        } else {
            $logger->warning('Empty email!');
        }

        if (!empty($customers)) {

            $fields = [
                'id' => $this->cf_field['id'],
                'value' => $this->cf_field['enums']['need_answer'],
            ];

            $logger->info(var_export($fields, TRUE));

            $customers_to_update = [];

            if (!empty($fields)) {
                foreach ($customers as $customer) {
                    $customers_to_update[] = (new CustomGenerator(Element::CUSTOMERS_TYPE, $customer['id']))->generate($fields);
                    $logger->info('Customer ID: ' . $customer['id']);
                }
            } else {
                $logger->warning('Field not found in customers');
            }

            if (!empty($customers_to_update)) {
                if ($api->update('customers', $customers_to_update)) {
                    $logger->info('Customer status changed');
                } else {
                    $logger->error('Customer status not changed!');
                }
            }
        } else {
            $logger->info('Customer is not found.');
        }

    }

}


