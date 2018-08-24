<?php

namespace CustomersBundle\Command;


use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;

use RuntimeException;

use GearmanWorker;
use Swd\Command\GearmanWorkerCommand;
use CustomersBundle\Task\TaskExecuter;
use CustomersBundle\Task\Sync\SyncContactsTaskNew;
use CustomersBundle\Task\Customer\AccountUpdateTask;
use CustomersBundle\Task\Customer\AccountPaymentTask;
use CustomersBundle\Task\Customer\CustomerFullUpdateTask;

class WorkerCommand extends GearmanWorkerCommand implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var array
     */
    protected $roles = [
        SyncContactsTaskNew::QUEUE_NAME,
        SyncContactsTaskNew::QUEUE_FULL_SYNC_NAME,
        AccountUpdateTask::QUEUE_NAME,
        AccountPaymentTask::QUEUE_NAME,
        CustomerFullUpdateTask::QUEUE_NAME,
    ];

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('customers:worker')
            ->addOption('role','r',InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED)
            ->addOption('task-limit',null,InputOption::VALUE_REQUIRED,'Сколько задач обработать до рестарта')

            ->addOption('mem-limit',null,InputOption::VALUE_REQUIRED,'Лимит памяти 10M')
            ->addOption('time-limit',null,InputOption::VALUE_REQUIRED,'Лимит времени set_time_limit, можно указать время на задачу и в режиме ожидания 60:12')
            ->setDescription('Запускает воркеры обработки аккаунта "customers" согласно выбранным ролям');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $handlers  = $input->getOption('role');

        $executer = $this->registerTaskExecuter($output);

        if (in_array('all', $handlers, true)) {
            $handlers = $this->roles;
        } else {
            $handlers = array_intersect($this->roles, $handlers);
        }

        $exitCode = null;
        if (empty($handlers)) {
            $output->writeln('No handler selected. Exiting.');
            $output->writeln('Choose one of: ' . implode(' | ', $this->roles));
        } else {
            $this->setup($output, $executer);

            $this->registerSignals();
            $this->registerShutdown();

            /** @var GearmanWorker $worker */
            $worker = $this->container->get('gearman_worker');
            # Make the worker non-blocking
            $worker->addOptions(GEARMAN_WORKER_NON_BLOCKING);
            $executer->registerHandlers($worker, $handlers);

            $this->setLimits($this->getLimits($input));
            $result = $this->runWorker($worker);

            $exitCode = $this->getExitCode($result);

            $output->writeln('Exit');
            $this->setExited(true);
        }

        return $exitCode;
    }

    /**
     * @param OutputInterface $output
     * @param array $options
     *
     * @return TaskExecuter
     */
    protected function registerTaskExecuter(OutputInterface $output, array $options = [])
    {
        $options['prefix'] = $this->container->getParameter('customers_worker_prefix');

        $executer = $this->makeExecuter($options, $output);

        $executer->addTask(SyncContactsTaskNew::QUEUE_NAME, new SyncContactsTaskNew());
        $executer->addTask(SyncContactsTaskNew::QUEUE_FULL_SYNC_NAME, new SyncContactsTaskNew());
        $executer->addTask(AccountUpdateTask::QUEUE_NAME, new AccountUpdateTask());
        $executer->addTask(AccountPaymentTask::QUEUE_NAME, new AccountPaymentTask());
        $executer->addTask(CustomerFullUpdateTask::QUEUE_NAME, new CustomerFullUpdateTask());

        return $executer;
    }

    /**
     * @param array $executerOptions
     * @param OutputInterface $output
     *
     * @return TaskExecuter
     */
    protected function makeExecuter(array $executerOptions, OutputInterface $output)
    {
        $executer = new TaskExecuter($executerOptions, $output);
        $executer->setContainer($this->container);

        return $executer;
    }

    /**
     * Собирает лимиты с ввода в масив
     *
     * @param InputInterface $input
     * @return array
     * @author skoryukin
     */
    protected function getLimits(InputInterface $input)
    {
        $limits = array(
            'task' => 0,
        );

        $limits['task'] = (int)$input->getOption('task-limit');

        $memLimit = $input->getOption('mem-limit');
        if($memLimit){
            $limits['memory'] = $memLimit;
        }

        $timeLimit = $input->getOption('time-limit');
        if($timeLimit){
            $timeLimit = explode(':',$timeLimit);
            if(count($timeLimit) >1){
               $limits['max_wait_time'] = (int)$timeLimit[1];
            }
            $limits['max_task_time'] = (int)$timeLimit[0];
        }

        return $limits;
    }

    /**
     * {@inheritdoc}
     */
    protected function wait(GearmanWorker $worker, $waitCount)
    {
        if($this->container) {
            $this->container->get('amocrm.support_api')->clearAuth();
            $this->container->get('amocrm.customers_api')->clear_auth();
            $this->container->get('amocrm.customersus_api')->clear_auth();
        }
        //закроем все коннекты что бы не отваливались сами
            //if($conn->isConnected()){
                //$conn->close();
            //}
        //}

        return parent::wait($worker,$waitCount);
    }

    /**
     * {@inheritdoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }
}

