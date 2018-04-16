<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigCommand extends ContainerAwareCommand
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
            ->setName('pmb:config')
            ->addOption('show-option', null, InputOption::VALUE_REQUIRED, 'Instead of writing the config, only show the given option.', '')
            ->setDescription('Configure config file, default - ppm.json');

        $this->configurePPMOptions($this);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|null
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configPath = $this->getConfigPath($input, true);
        if (!$configPath) {
            $configPath = $this->file;
        }
        $config = $this->loadConfig($input, $output);

        if ($input->getOption('show-option')) {
            echo $config[$input->getOption('show-option')];
            exit(0);
        }

        $this->renderConfig($output, $config);

        $newContent = json_encode($config, JSON_PRETTY_PRINT);
        if (file_exists($configPath) && $newContent === file_get_contents($configPath)) {
            $output->writeln(sprintf('No changes to %s file.', realpath($configPath)));
            return null;
        }

        file_put_contents($configPath, $newContent);
        $output->writeln(sprintf('<info>%s file written.</info>', realpath($configPath)));

        return null;
    }
}
