<?php

namespace PHPPM\Commands;

use PHPPM\ProcessClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class ReloadCommand extends Command
{
    use ConfigTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('reload')
            ->setDescription('Reloads the server')
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
        $handler->reloadProcessManager(function ($status) use ($output) {
            $output->writeln("Requested a worker reload.");
        });

        return null;
    }
}
