<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace PMB\PMBundle\Command;

use PHPPM\ProcessClient;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends ContainerAwareCommand
{
    use ConfigTrait;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('pmb:status')
            ->setDescription('Status of all processes')
            ->addOption('socket-path', null, InputOption::VALUE_REQUIRED, 'Path to a folder where socket files will be placed. Relative to working-directory or cwd()', '.ppm/run/')
            ->addArgument('working-directory', null, 'Working directory', './')
        ;

        $this->configurePPMOptions($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->initializeConfig($input, $output, false);

        $handler = new ProcessClient();
        $handler->setSocketPath($config['socket-path']);
        $handler->getStatus(function ($status) use ($output) {
            $output->writeln($this->parseStatus($status));
        });

        return null;
    }

    /**
     * @param array|string $status
     * @param int $indentLevel
     * @return string
     */
    private function parseStatus($status, $indentLevel = 0)
    {
        if (is_array($status)) {
            $p = PHP_EOL;
            foreach ($status as $key => $value) {
                $p .= sprintf('%s%s: %s', str_repeat("\t", $indentLevel), $key, $this->parseStatus($value, $indentLevel + 1));
            }
        } else {
            $p = $status . PHP_EOL;
        }
        return $p;
    }
}
