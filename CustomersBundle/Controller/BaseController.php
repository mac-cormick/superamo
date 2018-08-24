<?php

namespace CustomersBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Psr\Log\LoggerInterface;

abstract class BaseController extends Controller
{
    /** @var \Monolog\Logger $logger */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function parseRequestData($data)
    {

        if (empty($data)) {
            throw new \InvalidArgumentException('Empty data');
        }

        $data = json_decode($data, TRUE);

        if (!$data) {
            throw new \InvalidArgumentException('Bad request');
        }

        if (!isset($data['event'])) {
            throw new \InvalidArgumentException('Empty event info');
        }

        if (!isset($data['data'])) {
            throw new \InvalidArgumentException('Empty event data');
        }

        return $data;
    }
}
