<?php
/**
 * Copyright (c) 2018. AIT
 */

declare(ticks=1);

namespace Other\PmBundle\PM;

use Psr\Log\LogLevel;
use ReactPCNTL\PCNTL;
use React\ChildProcess\Process;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Server;
use React\Socket\ServerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\ProcessUtils;
use Symfony\Component\VarDumper\VarDumper;

class ProcessManager{

    use ProcessCommunicationTrait;

    /*
     * Load balander started, waiting for slaves to come up
     */
    const ERROR = 'error';

    /*
     * Slaves started and registered
     */
    const INFO = 'info';

    /*
     * In emergency mode we need to close all workers due a fatal error
     * and wait for file changes to be able to restart workers
     */
    const STATE_EMERGENCY = 2;

    /*
     * Load balancer is in shutdown
     */
    const STATE_RUNNING = 1;
    const STATE_SHUTDOWN = 3;
    const STATE_STARTING = 0;
    private static $formatLevelMap = [
        LogLevel::EMERGENCY => self::ERROR,
        LogLevel::ALERT => self::ERROR,
        LogLevel::CRITICAL => self::ERROR,
        LogLevel::ERROR => self::ERROR,
        LogLevel::WARNING => self::INFO,
        LogLevel::NOTICE => self::INFO,
        LogLevel::INFO => self::INFO,
        LogLevel::DEBUG => self::INFO,
    ];

    /**
     * Load balancer status
     */
    protected $status = self::STATE_STARTING;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * Maximum requests per worker before it's recycled
     *
     * @var int
     */
    protected $maxRequests = 2000;

    /**
     * @var SlavePool
     */
    protected $slaves;

    /**
     * @var string
     */
    protected $controllerHost;

    /**
     * @var ServerInterface
     */
    protected $controller;

    /**
     * @var int
     */
    protected $slaveCount;

    /**
     * @var string
     */
    protected $bridge;

    /**
     * @var string
     */
    protected $appBootstrap;

    /**
     * @var string|null
     */
    protected $appenv;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * @var bool
     */
    protected $logging = true;

    /**
     * @var string
     */
    protected $staticDirectory = '';

    /**
     * @var string
     */
    protected $host = '127.0.0.1';

    /**
     * @var int
     */
    protected $port = 5500;

    /**
     * Whether the server is in the reload phase.
     *
     * @var bool
     */
    protected $inReload = false;

    /**
     * Full path to the php-cgi executable. If not set, we try to determine the
     * path automatically.
     *
     * @var string
     */
    protected $phpCgiExecutable = '';

    /**
     * @var null|int
     */
    protected $lastWorkerErrorPrintBy;

    protected $filesToTrack = [];
    protected $filesLastMTime = [];
    protected $filesLastMd5 = [];

    /**
     * Counter of handled clients
     *
     * @var int
     */
    protected $handledRequests = 0;

    /**
     * Flag controlling populating $_SERVER var for older applications (not using full request-response flow)
     *
     * @var bool
     */
    protected $populateServer = true;

    /**
     * Location of the file where we're going to store the PID of the master process
     */
    protected $pidfile;

    /**
     * ProcessManager constructor.
     *
     * @param OutputInterface $output
     * @param int $port
     * @param string $host
     * @param int $slaveCount
     */
    public function __construct(OutputInterface $output, $port = 8080, $host = '127.0.0.1', $slaveCount = 8){
        $this->output = $output;
        $this->host = $host;
        $this->port = $port;

        $this->slaveCount = $slaveCount;
        $this->slaves = new SlavePool(); // create early, used during shutdown

        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * @param Slave $slave
     * @throws \Exception
     */
    private function terminateSlave($slave){
        // set closed and remove from pool
        $slave->close();
        $this->slaves->remove($slave);

        /** @var Process */
        $process = $slave->getProcess();
        if($process->isRunning()) {
            $process->terminate();
        }

        $pid = $slave->getPid();
        if(is_int($pid)) {
            posix_kill($pid, SIGKILL); // make sure it's really dead
        }
    }

    /**
     * A slave sent a `status` command.
     *
     * @param array $data
     * @param ConnectionInterface $conn
     */
    protected function commandStatus(array $data, ConnectionInterface $conn){
        // remove nasty info about worker's bootstrap fail
        $conn->removeAllListeners('close');
        if($this->output->isVeryVerbose()) {
            $conn->on('close', function(){
                $this->output->writeln('Status command requested');
            });
        }

        // create port -> requests map
        $requests = array_reduce(
            $this->slaves->getByStatus(Slave::ANY),
            function($carry, Slave $slave){
                $carry[$slave->getPort()] = 0 + $slave->getHandledRequests();

                return $carry;
            },
            []
        );

        switch($this->status) {
            case self::STATE_STARTING:
                $status = 'starting';
                break;
            case self::STATE_RUNNING:
                $status = 'healthy';
                break;
            case self::STATE_EMERGENCY:
                $status = 'offline';
                break;
            default:
                $status = 'unknown';
        }

        $conn->end(json_encode([
            'status' => $status,
            'workers' => $this->slaveCount,
            'handled_requests' => $this->handledRequests,
            'handled_requests_per_worker' => $requests
        ]));
    }

    /**
     * A slave sent a `stop` command.
     *
     * @param array $data
     * @param ConnectionInterface $conn
     */
    protected function commandStop(array $data, ConnectionInterface $conn){
        if($this->output->isVeryVerbose()) {
            $conn->on('close', function(){
                $this->output->writeln('Stop command requested');
            });
        }

        $conn->end(json_encode([]));

        $this->shutdown(true);
    }

    /**
     * A slave sent a `register` command.
     *
     * @param array $data
     * @param ConnectionInterface $conn
     */
    protected function commandRegister(array $data, ConnectionInterface $conn){
        $pid = (int)$data['pid'];
        $port = (int)$data['port'];

        try {
            $slave = $this->slaves->getByPort($port);
            $slave->register($pid, $conn);
        } catch(\Exception $e) {
            $this->output->writeln(sprintf(
                '<error>Worker #%d wanted to register on master which was not expected.</error>',
                $port
            ));
            $conn->close();

            return;
        }

        if($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d registered. Waiting for application bootstrap ... ', $port));
        }

        $this->sendMessage($conn, 'bootstrap');
    }

    /**
     * A slave sent a `ready` commands which basically says that the slave bootstrapped successfully the
     * application and is ready to accept connections.
     *
     * @param array $data
     * @param ConnectionInterface $conn
     */
    protected function commandReady(array $data, ConnectionInterface $conn){
        try {
            $slave = $this->slaves->getByConnection($conn);
        } catch(\Exception $e) {
            $this->output->writeln($e->getMessage());

            return;
        }

        $slave->ready();

        if($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d ready.', $slave->getPort()));
        }

        if($this->allSlavesReady()) {
            if($this->status === self::STATE_EMERGENCY) {
                $this->output->writeln("<info>Emergency survived. Workers up and running again.</info>");
            } else {
                $this->output->writeln(
                    sprintf(
                        "<info>%d workers up and ready. Application is ready at http://%s/</info>",
                        $this->slaveCount,
                        $this->host
                    )
                );
            }

            $this->status = self::STATE_RUNNING;
        }
    }

    /**
     * Prints logs.
     *
     * @Todo, integrate Monolog.
     *
     * @param array $data
     * @param ConnectionInterface $conn
     */
    protected function commandLog(array $data, ConnectionInterface $conn){
        $level = static::$formatLevelMap[LogLevel::INFO];
        if(!empty($data['level'])) {
            $level = static::$formatLevelMap[$data['level']];
        }
        $this->output->writeln(sprintf('<%1$s>%2$s</%1$s>', $level, $data['message']));
        if(!empty($data['context'])) {
            $this->output->writeln(sprintf('<%1$s>%2$s</%1$s>', $level, VarDumper::dump($data['context'])));
        }
    }

    /**
     * Handles failed application bootstraps.
     *
     * @param $host
     * @param int $port
     * @throws \Exception
     */
    protected function bootstrapFailed($host, $port){
        if($this->isDebug()) {
            $this->output->writeln('');

            if($this->status !== self::STATE_EMERGENCY) {
                $this->status = self::STATE_EMERGENCY;

                $this->output->writeln(
                    sprintf(
                        '<error>Application bootstrap failed. We are entering emergency mode now. All offline. ' .
                        'Waiting for file changes ...</error>'
                    )
                );
            } else {
                $this->output->writeln(
                    sprintf(
                        '<error>Application bootstrap failed. We are still in emergency mode. All offline. ' .
                        'Waiting for file changes ...</error>'
                    )
                );
            }

            $this->closeSlaves();
        } else {
            $this->output->writeln(
                sprintf(
                    '<error>Application bootstrap failed. Restarting worker #%d ...</error>',
                    $port
                )
            );

            $this->newSlaveInstance($host, $port);
        }
    }

    /**
     * Check if all slaves have become available
     */
    protected function allSlavesReady(){
        if($this->status === self::STATE_STARTING || $this->status === self::STATE_EMERGENCY) {
            $readySlaves = $this->slaves->getByStatus(Slave::READY);

            return count($readySlaves) === $this->slaveCount;
        }

        return false;
    }

    /**
     * Creates a new ProcessSlave instance.
     *
     * @param $host
     * @param int $port
     * @throws \Exception
     */
    protected function newSlaveInstance($host, $port){
        if($this->status === self::STATE_SHUTDOWN) {
            // during shutdown phase all connections are closed and as result new
            // instances are created - which is forbidden during this phase
            return;
        }

        if($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf("Start new worker #%s %d", $host, $port));
        }

        $executableFinder = new PhpExecutableFinder();
        if(false === $phpCgiExecutable = $executableFinder->find(false)) {
            $phpCgiExecutable = $this->phpCgiExecutable;
        }

        // slave php file
        $file = getcwd() . "/bin/console";
        $args = ["pmb:slave --port " . $port, getcwd()];

        //For version 2.x and 3.x of \Symfony\Component\Process\Process package
        if(method_exists('\Symfony\Component\Process\ProcessUtils', 'escapeArgument')) {
            $commandline = 'exec ' . $phpCgiExecutable . ' ' . ProcessUtils::escapeArgument($file . " " . implode(' ', $args));
        } else {
            //For version 4.x of \Symfony\Component\Process\Process package
            $commandline = implode(' ', array_merge([$phpCgiExecutable, $file], $args));
            $processInstance = new \Symfony\Component\Process\Process($commandline);
            $commandline = 'exec ' . $processInstance->getCommandLine();
        }

        // use exec to omit wrapping shell
        $process = new Process($commandline);

        $slave = new Slave($host, $port, $this->maxRequests);
        $slave->attach($process);
        $this->slaves->add($slave);

        $process->start($this->loop);
        $process->stderr->on(
            'data',
            function($data) use ($port){
                if($this->lastWorkerErrorPrintBy !== $port) {
                    $this->output->writeln("<info>--- Worker $port stderr ---</info>");
                    $this->lastWorkerErrorPrintBy = $port;
                }
                $this->output->write("<error>$data</error>");
            }
        );
        $process->stdout->on(
            'data',
            function($data) use ($port){
                if($this->output->isVeryVerbose()) {
                    if($this->lastWorkerErrorPrintBy !== $port) {
                        $this->output->writeln("<info>--- Worker $port stdout ---</info>");
                        $this->lastWorkerErrorPrintBy = $port;
                    }
                    $this->output->write("<info>$data</info>");
                }
            }
        );

        if($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf("Worker pid %d has been started", $process->getPid()));
        }
    }

    /**
     * Handles termination signals, so we can gracefully stop all servers.
     */
    public function shutdown($graceful = false){
        if($this->status === self::STATE_SHUTDOWN) {
            return;
        }

        $this->status = self::STATE_SHUTDOWN;

        $this->output->writeln(
            $graceful
                ? '<info>Shutdown received, exiting.</info>'
                : '<error>Termination received, exiting.</error>'
        );

        // this method is also called during startup when something crashed, so
        // make sure we don't operate on nulls.
        if(!empty($this->controller)) {
            @$this->controller->close();
        }
        if($this->loop instanceof LoopInterface) {
            $this->loop->stop();
        }

        foreach($this->slaves->getByStatus(Slave::ANY) as $slave) {
            $this->terminateSlave($slave);
        }
        if(file_exists($this->pidfile)) {
            unlink($this->pidfile);
        }
        exit;
    }

    /**
     * @param int $maxRequests
     */
    public function setMaxRequests($maxRequests){
        $this->maxRequests = $maxRequests;
    }

    /**
     * @param string $phpCgiExecutable
     */
    public function setPhpCgiExecutable($phpCgiExecutable){
        $this->phpCgiExecutable = $phpCgiExecutable;
    }

    /**
     * @param string|null $appenv
     */
    public function setAppEnv($appenv){
        $this->appenv = $appenv;
    }

    /**
     * @return ?string
     */
    public function getAppEnv(){
        return $this->appenv;
    }

    /**
     * @return boolean
     */
    public function isLogging(){
        return $this->logging;
    }

    /**
     * @param boolean $logging
     */
    public function setLogging($logging){
        $this->logging = $logging;
    }

    public function setPIDFile($pidfile){
        $this->pidfile = $pidfile;
    }

    /**
     * @return boolean
     */
    public function isDebug(){
        return $this->debug;
    }

    /**
     * @param boolean $debug
     */
    public function setDebug($debug){
        $this->debug = $debug;
    }

    /**
     * Starts the main loop. Blocks.
     * @throws \InvalidArgumentException
     * @throws \ReflectionException
     * @throws \RuntimeException
     * @throws \Exception
     */
    public function run(){
        Debug::enable();

        // make whatever is necessary to disable all stuff that could buffer output
        ini_set('zlib.output_compression', 0);
        ini_set('output_buffering', 0);
        ini_set('implicit_flush', 1);
        ob_implicit_flush(1);

        $this->loop = Factory::create();
        $this->controller = new Server($this->getControllerSocketPath(), $this->loop);
        $this->controller->on('connection', [$this, 'onSlaveConnection']);

        $pcntl = new PCNTL($this->loop);
        $pcntl->on(SIGTERM, [$this, 'shutdown']);
        $pcntl->on(SIGINT, [$this, 'shutdown']);
        $pcntl->on(SIGCHLD, [$this, 'handleSigchld']);
        $pcntl->on(SIGUSR1, [$this, 'restartSlaves']);

        $loopClass = (new \ReflectionClass($this->loop))->getShortName();

        $this->output->writeln("<info>Starting PM with {$this->slaveCount} workers, using {$loopClass} ...</info>");
        $this->writePid();

        $this->createSlaves();

        $this->loop->run();
    }

    /**
     * Handling zombie processes on SIGCHLD
     */
    public function handleSigchld(){
        $pid = pcntl_waitpid(-1, $status, WNOHANG);
    }

    public function writePid(){
        $pid = getmypid();
        file_put_contents($this->pidfile, $pid);
    }

    /**
     * Handles data communication from slave -> master
     *
     * @param ConnectionInterface $connection
     */
    public function onSlaveConnection(ConnectionInterface $connection){
        $this->bindProcessMessage($connection);
        $connection->on('close', function() use ($connection){
            $this->onSlaveClosed($connection);
        });
    }

    /**
     * Handle slave closed
     *
     * @param ConnectionInterface $connection
     * @return void
     * @throws \Exception
     */
    public function onSlaveClosed(ConnectionInterface $connection){
        if($this->status === self::STATE_SHUTDOWN) {
            return;
        }

        try {
            $slave = $this->slaves->getByConnection($connection);
        } catch(\Exception $e) {
            // this connection is not registered, so it died during the ProcessSlave constructor.
            $this->output->writeln(
                '<error>Worker permanently closed during PHP-PM bootstrap. Not so cool. ' .
                'Not your fault, please create a ticket at github.com/php-pm/php-pm with ' .
                'the output of `ppm start -vv`.</error>'
            );

            return;
        }

        // get status before terminating
        $status = $slave->getStatus();
        $port = $slave->getPort();
        $host = $slave->getHost();

        if($this->output->isVeryVerbose()) {
            $this->output->writeln(sprintf('Worker #%d closed after %d handled requests', $port, $slave->getHandledRequests()));
        }

        // kill slave and remove from pool
        $this->terminateSlave($slave);

        /*
         * If slave is in registered state it died during bootstrap.
         * In this case new instances should only be created:
         * - in debug mode after file change detection via restartSlaves()
         * - in production mode immediately
         */
        if($status === Slave::REGISTERED) {
            $this->bootstrapFailed($host, $port);
        } else {
            // recreate
            $this->newSlaveInstance($host, $port);
        }
    }

    /**
     * Populate slave pool
     *
     * @return void
     * @throws \Exception
     */
    public function createSlaves(){
        for($i = 0; $i < $this->slaveCount; $i++) {
            $this->newSlaveInstance($this->host, $this->port + $i);
        }
    }

    /**
     * Close all slaves
     *
     * @return void
     * @throws \Exception
     */
    public function closeSlaves(){
        foreach($this->slaves->getByStatus(Slave::ANY) as $slave) {
            $slave->close();
            $this->slaves->remove($slave);

            if(!empty($slave->getConnection())) {
                /** @var ConnectionInterface */
                $connection = $slave->getConnection();
                $connection->removeAllListeners('close');
                $connection->close();
            }
        }
    }

    /**
     * Restart all slaves. Necessary when watched files have changed.
     * @throws \Exception
     */
    public function restartSlaves(){
        if($this->inReload) {
            return;
        }

        $this->inReload = true;
        $this->output->writeln('Restarting all workers');

        $this->closeSlaves();
        $this->createSlaves();

        $this->inReload = false;
    }
}