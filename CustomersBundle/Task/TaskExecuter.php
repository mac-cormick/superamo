<?php

namespace CustomersBundle\Task;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Swd\Task\TaskExecuterInterface;
use Swd\Task\TaskInterface;

use GearmanWorker;
use GearmanJob;
use Exception;
use ReflectionClass;
use RuntimeException;

class TaskExecuter implements ContainerAwareInterface, TaskExecuterInterface
{
    protected $container;

    protected $options;
    protected $output;

    protected $terminateHandler;
    protected $tasks = [];

    public function __construct($options = array(),OutputInterface $output)
    {
        $this->options = array_merge([
            'prefix'=>null
        ],$options);

        $this->output = $output;
    }

    public function registerHandlers(GearmanWorker $worker, array $handlers)
    {
        $prefix = $this->options['prefix'];

        foreach($handlers as $name){
            if(!isset($this->tasks[$name])){
                throw new RuntimeException(sprintf('Task for "%s" not found',$name));
            }

            $fn = function($job)use($name){
                $this->runTask($job,$name);
            };
            $fn->bindTo($this);
            $worker->addFunction($prefix.$name,$fn);
        }
    }

    public function getHandlers()
    {
        return array_keys($this->tasks);
    }

    public function addTask($name,TaskInterface $task)
    {
        $task->setOutput($this->output);

        if ($task instanceOf ContainerAwareInterface){
            $task->setContainer($this->container);
        }

        $this->tasks[$name] = $task;
    }

    public function terminate()
    {
        $this->output->writeln(sprintf("Executer terminated"));

        if(is_callable($this->terminateHandler)){
            $args = func_get_args();
            call_user_func_array($this->terminateHandler,$args);
        }
    }

    public function shutdown()
    {
        $this->log(sprintf("Executer shutdowned, terminating task"));

        if(is_callable($this->terminateHandler)){
            $args = func_get_args();
            call_user_func_array($this->terminateHandler,$args);
        }
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function runTask(GearmanJob $job,$name)
    {
        $json = $job->workload();
        $this->container->get('monolog.group_procesor')->resetGroupId(); //split log prefixes

        $this->log('Task: ');
        $this->dump($json);

        $payload = json_decode($json,true);
        if(is_null($payload)){
            $this->log('Task payload is broken',null,true);
            return json_encode(false);
        }

        if(!isset($this->tasks[$name])){
            $this->log(sprintf('Task for "%s" not found',$name),null,true);
            return json_encode(false);
        }

        try{
            $result = $this->tasks[$name]->execute($payload);

        }catch(TaskAbortException $e){
            $result =  false;
            $this->terminateHandler = null;

            $this->log('Task aborted: '.$e->getMessage());
            if($prev = $e->getPrevious()){
                $this->log('Exception: '.$prev->getMessage(),[get_class($prev)],true);
                $this->dump($e->getTraceAsString());
            }
        }catch(Exception $e){
            $result =  false;
            $this->terminateHandler = null;

            $this->log('Catch exception: '.$e->getMessage(),[get_class($e)],true);
            $this->dump($e->getTraceAsString());
            //throw $e;
        }

        $this->terminateHandler = null;

        $result  = json_encode($result);
        return $result;
    }


    protected function dump($message)
    {
        $this->output->writeln($message);
    }

    protected function log($message,$context = null,$err = false)
    {
        $this->output->writeln(date('Y-m-d H:i:s').': '.$message);

        if(!is_null($context)){
            $this->output->writeln(json_encode($context));
        }
    }
}

