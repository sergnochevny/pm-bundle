<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\Command;

use Other\PmBundle\Bridge\RequestListener;
use function Other\PmBundle\pcntl_enabled;
use Other\PmBundle\PM\ProcessSlave;
use React\EventLoop\LoopInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class SlaveCommand extends ContainerAwareCommand{

    protected $loop;
    protected $requestListener;

    use ConfigTrait;

    public function __construct(
        RequestListener $requestListener,
        LoopInterface $loop
    ){
        $this->requestListener = $requestListener;
        $this->loop = $loop;
        parent::__construct();
    }

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

        if (!pcntl_enabled()) {
            throw new \RuntimeException('Some of required pcntl functions are disabled. Check `disable_functions` setting in `php.ini`.');
        }

        $config = $this->initializeConfig($input, $output);

        $handler = new ProcessSlave($this->loop, $this->requestListener, $config);
        $handler->run();

        return null;
    }
}
