<?php

namespace CustomersBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

use RuntimeException;
use CustomersBundle\Task\Customer\CustomerFullUpdateTask;

class SyncCustomerCommand extends ContainerAwareCommand
{
    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('customers:sync')
            ->addOption('account',null,InputOption::VALUE_REQUIRED)
            ->addOption('customer',null,InputOption::VALUE_REQUIRED)
            ->setDescription('Обновляет поля и транзакции покупателя согласно актуальным данным из АПИ')
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ids = null;
        if($ids = $input->getOption('account')){
            $key = 'account_id';
        }elseif($ids = $input->getOption('customer')){
            $key = 'customer_id';
        }

        if($ids) {
            $ids = explode(',', $ids);
            $gm_client = $this->getContainer()->get('gearman_client');
            $prefix = $this->getContainer()->getParameter('customers_worker_prefix');
            $output->writeln(sprintf('%d Tasks added: ' ,count($ids)));
            foreach ($ids as $id) {
                $workload = [$key => (int)$id];
                $gm_client->doBackground($prefix . CustomerFullUpdateTask::QUEUE_NAME, json_encode($workload));
                $output->writeln(json_encode($workload));
            }
        }else{

            $output->writeln('No options specified');
        }
    }

}

