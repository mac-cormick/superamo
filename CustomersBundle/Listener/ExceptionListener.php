<?php

namespace CustomersBundle\Listener;

use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;


/**
 * Фильтрует ошибки для prod окружения
 *
**/
class ExceptionListener
{
    protected $showExceptions;

    public function __construct($showExceptions = false)
    {
        $this->showException = $showExceptions;
    }

    public function setShowException($showExceptions )
    {
        $this->showException = $showExceptions;
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        if(!$event->isMasterRequest()){
            return;
        }

        if($this->showException){
            return;
        }
        $exception = $event->getException();

        $response =  new Response(null,200);

        $accessDeniedResponse = new JsonResponse(array(
            "message"=>"Access Denied",
        ),403);

        if($exception instanceOf InsufficientAuthenticationException){
            $response =  $accessDeniedResponse;

        }elseif($exception instanceOf AccessDeniedException){
            $response =  $accessDeniedResponse;

        }elseif($exception instanceOf AuthenticationException){
            $response = new JsonResponse(array(
                "message"=>"Authorization need",
            ),403);
        }

        // You get the exception object from the received event

        // HttpExceptionInterface is a special type of exception that
        // holds status code and header details
        //if ($exception instanceof HttpExceptionInterface) {
            //$response->setStatusCode($exception->getStatusCode());
            //$response->headers->replace($exception->getHeaders());
        //} else {
            //$response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
        //}

        // Send the modified response object to the event
        $event->setResponse($response);
    }
}
