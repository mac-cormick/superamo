<?php

namespace CustomersBundle\Task;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Swd\Task\TaskExecuterInterface;
use Swd\Task\Exception\TaskAbortException;
use Swd\Task\TaskInterface;
use Exception;

abstract class BaseTask  implements ContainerAwareInterface,TaskInterface
{
    private $task;
    private $output;
    private $aborted = false;

    /**
     * @var ContainerInterface
     */
    protected $container;

    protected function getOutput()
    {
        return $this->output;
    }

    /**
     * {@inheritDoc}
     */
    protected function abortTask($message,Exception $e = null)
    {
        $this->aborted = true;

        throw new TaskAbortException($message,0,$e);
    }

    /**
     * {@inheritDoc}
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * {@inheritDoc}
     */
    public function isTaskAborted()
    {
        return $this->aborted ;
    }

    /**
     * {@inheritDoc}
     */
    public function abort($message,Exception $e = null)
    {
        $this->abortTask($message,$e);
    }

    public function terminate()
    {
        $this->log("Task terminated");
        try{
            $this->abortTask('Task terminated');
        }catch(TaskAbortException $e ){
            //noop
        }

    }

    /**
     * {@inheritDoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    protected function log($message,$context = null,$err = false)
    {
        $this->output->writeln(date('Y-m-d H:i:s').': '.$message);

        if(!is_null($context)){
            $this->output->writeln(json_encode($context));
        }
    }

    protected function dump($message)
    {
        $this->output->writeln($message);
    }

    protected function handleException(Exception $e)
    {
        if($this->container->getParameter('kernel.debug')){
            throw $e;
        }

        $this->log(sprintf("Handle error:  %s ", $e->getMessage()),[get_class($e)],true);
        $this->dump($e->getTraceAsString());
        //throw $e;
    }
}
