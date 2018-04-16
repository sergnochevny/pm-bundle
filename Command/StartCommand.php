<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\Command;

use Other\PmBundle\PM\ProcessManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends ContainerAwareCommand
{
    use ConfigTrait;

    /**
     * {@inheritdoc}
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('pmb:start')
            ->setDescription('Starts the server')
            ->addArgument('working-directory', InputArgument::OPTIONAL, 'Working directory', './')
        ;

        $this->configurePPMOptions($this);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|null
     * @throws \InvalidArgumentException
     * @throws \ReflectionException
     * @throws \RuntimeException
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->initializeConfig($input, $output);

        $handler = new ProcessManager($output, $config['port'], $config['host'], $config['workers']);

        $handler->setDebug((boolean)$config['debug']);
        $handler->setLogging((boolean)$config['logging']);
        $handler->setMaxRequests($config['max-requests']);
        $handler->setPhpCgiExecutable($config['cgi-path']);
        $handler->setSocketPath($config['socket-path']);
        $handler->setSocketScheme($config['socket-scheme']);
        $handler->setPIDFile($config['pidfile']);
        $handler->run();

        return null;
    }
}
