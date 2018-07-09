<?php
/**
 * Copyright (c) 2018. sn
 */

namespace Other\PmBundle\Command;

use Other\PmBundle\PM\ProcessSlave;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Other\PmBundle\pcntl_enabled;

class SlaveCommand extends ContainerAwareCommand{

    use ConfigTrait;

    /**
     * {@inheritdoc}
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure(){
        parent::configure();

        $this
            ->setName('pmb:slave')
            ->setDescription('Starts the slave process')
            ->addArgument('working-directory', InputArgument::OPTIONAL, 'Working directory', './');

        $this->configurePPMOptions($this);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|null
     * @throws \RuntimeException
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output){

        if(!pcntl_enabled()) {
            throw new \RuntimeException('Some of required pcntl functions are disabled. Check `disable_functions` setting in `php.ini`.');
        }

        $config = $this->initializeConfig($input, $output);

        $application = $this->getApplication();
        $handler = new ProcessSlave($application->getKernel(), $config);
        $handler->run();

        return null;
    }
}
