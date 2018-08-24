<?php

namespace CustomersBundle\Command;

use Phase\AmoCRM_API;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

use RuntimeException;
use Phase\Customers\Export\Transactions;

class ExportTransactionsCommand extends ContainerAwareCommand
{
    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('customers:transactions:export')
            ->setDescription('Отправка на почту список транзакций')
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var AmoCRM_API $amocrm_api */
        $amocrm_api = $this->getContainer()->get('amocrm.customers_api');
        $config = $this->getContainer()->getParameter('customers_export');

        $module = new Transactions($amocrm_api,$output);
        $module->init($config['recipient'],$config['tmp_dir']);
    }

}

