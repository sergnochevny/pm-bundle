<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace PMB\PMBundle\Command;

use PHPPM\ProcessClient;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StopCommand extends ContainerAwareCommand
{
    use ConfigTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('pmb:stop')
            ->setDescription('Stops the server')
            ->addOption('socket-path', null, InputOption::VALUE_REQUIRED, 'Path to a folder where socket files will be placed. Relative to working-directory or cwd()', '.ppm/run/')
            ->addArgument('working-directory', InputArgument::OPTIONAL, 'Working directory', './')
        ;

        $this->configurePPMOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->initializeConfig($input, $output, false);

        $handler = new ProcessClient();
        $handler->setSocketPath($config['socket-path']);

        $handler->stopProcessManager(function ($status) use ($output) {
            $output->writeln('Requested process manager to stop.');
        });

        return null;
    }
}
