<?php
/**
 * Copyright (c) 2018. AIT
 */

declare(ticks=1);

namespace Other\PmBundle\PM;

use Evenement\EventEmitterInterface;
use Other\PmBundle\Logger\StdLogger;
use ReactPCNTL\PCNTL;
use Other\PmBundle\Bridge\RequestListener;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\Server;
use React\Socket\ServerInterface;
use React\Socket\UnixConnector;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Debug\BufferingLogger;
use Symfony\Component\Debug\ErrorHandler;

class ProcessSlave{

    use ProcessCommunicationTrait;

    /**
     * The HTTP Server.
     *
     * @var ServerInterface
     */
    protected $server;

    /**
     * @var \Other\PmBundle\Bridge\RequestListener
     */
    protected $requestListener;
    /**
     * @var LoopInterface
     */
    protected $loop;
    /**
     * @var OutputInterface
     */
    protected $output;
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;
    /**
     * ProcessManager master process connection
     *
     * @var ConnectionInterface
     */
    protected $controller;

    /**
     * @var bool
     */
    protected $inShutdown = false;
    /**
     * @var \Symfony\Component\Debug\BufferingLogger
     */
    protected $errorLogger;
    /**
     * Copy of $_SERVER during bootstrap.
     *
     * @var array
     */
    protected $logFormat = '[$time_local] $remote_addr - $remote_user "$request" $status $bytes_sent "$http_referer"';
    /**
     * Contains some configuration options.
     *
     * 'port' => int (server port)
     * 'appenv' => string (App environment)
     * 'logging' => boolean (false) (If it should log all requests)
     * ...
     *
     * @var array
     */
    protected $config;

    /**
     * Socket scheme.
     *
     * @var string
     */
    protected $socketScheme = 'tcp';

    protected $maxConcurrentRequests = 0;
    /**
     * Current instance, used by global functions.
     *
     * @var ProcessSlave
     */
    public static $slave;

    public function __construct(LoopInterface $loop, RequestListener $requestListener, array $config = [], OutputInterface $output){

        $this->loop = $loop;
        $this->output = $output;
        $this->requestListener = $requestListener;

        $this->setMaxConcurrentRequests((int)$config['max-requests']);
        $this->setSocketPath($config['socket-path']);
        $this->setSocketScheme($config['socket-scheme']);

        $this->config = $config;
    }

    /**
     * Bootstraps the actual application.
     *
     */
    protected function bootstrap(){
        usleep(500000);
        $this->sendMessage($this->controller, 'ready');
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param string $timeLocal
     * @throws \InvalidArgumentException
     */
    protected function logResponse(ServerRequestInterface $request, ResponseInterface $response, $timeLocal){
        $logFunction = function($size) use ($request, $response, $timeLocal){
            $requestString = $request->getMethod() . ' ' . $request->getUri()->getPath() . ' HTTP/' . $request->getProtocolVersion();
            $remoteIp = $request->getServerParams()['REMOTE_ADDR'];
            $statusCode = $response->getStatusCode();

            $message = str_replace(
                [
                    '$remote_addr', '$remote_user', '$time_local', '$request', '$status',
                    '$bytes_sent', '$http_referer', '$http_user_agent',
                ],
                [
                    $remoteIp, '-', $timeLocal, $requestString, $statusCode, $size,
                    $request->hasHeader('Referer') ? $request->getHeaderLine('Referer') : '-',
                    $request->hasHeader('User-Agent') ? $request->getHeaderLine('User-Agent') : '-'
                ],
                $this->logFormat
            );

            if($response->getStatusCode() >= 400) {
                $message = "<error>$message</error>";
            } else {
                $message = "<info>$message</info>";
            }

            $this->logger->info($message);
        };

        if($response->getBody() instanceof EventEmitterInterface) {
            /** @var EventEmitterInterface $body */
            $body = $response->getBody();
            $size = strlen(\RingCentral\Psr7\str($response));
            $body->on('data', function($data) use (&$size){
                $size += strlen($data);
            });
            //using `close` event since `end` is not fired for files
            $body->on('close', function() use (&$size, $logFunction){
                $logFunction($size);
            });
        } else {
            $logFunction(strlen(\RingCentral\Psr7\str($response)));
        }
    }

    /**
     *
     * @param string $affix
     * @return string
     */
    protected function getSock($affix){

        return $this->socketScheme . '://' . $affix;
    }

    /**
     * @param $host
     * @param int $port
     *
     * @return string
     */
    protected function getSlaveSocketPath($host, $port){
        $affix = (!empty($host) ? $host . ':' : '') . $port;

        return $this->getSock($affix);
    }

    /**
     * @return null
     */
    public function getMaxConcurrentRequests(){
        return $this->maxConcurrentRequests;
    }

    /**
     * @param int $maxConcurrentRequests
     */
    public function setMaxConcurrentRequests(int $maxConcurrentRequests = 0): void{
        if(!empty($maxConcurrentRequests)) {
            $this->maxConcurrentRequests = $maxConcurrentRequests;
        }
    }

    /**
     * @return boolean
     */
    public function isDebug(){
        return $this->config['debug'];
    }

    /**
     * @return boolean
     */
    public function isLogging(){
        return $this->config['logging'];
    }

    /**
     * Shuts down the event loop. This basically exits the process.
     */
    public function shutdown(){
        if($this->inShutdown) {
            return;
        }

        if($this->errorLogger && $logs = $this->errorLogger->cleanLogs()) {
            $messages = array_map(
                function($item){
                    //array($level, $message, $context);
                    $message = $item[1];
                    $context = $item[2];

                    if(isset($context['file'])) {
                        $message .= ' in ' . $context['file'] . ':' . $context['line'];
                    }

                    if(isset($context['stack'])) {
                        foreach($context['stack'] as $idx => $stack) {
                            $message .= PHP_EOL . sprintf(
                                    "#%d: %s%s %s%s",
                                    $idx,
                                    isset($stack['class']) ? $stack['class'] . '->' : '',
                                    $stack['function'],
                                    isset($stack['file']) ? 'in' . $stack['file'] : '',
                                    isset($stack['line']) ? ':' . $stack['line'] : ''
                                );
                        }
                    }

                    return $message;
                },
                $logs
            );
            error_log(implode(PHP_EOL, $messages));
        }

        $this->inShutdown = true;

        if(($this->controller instanceof ConnectionInterface) && $this->controller->isWritable()) {
            $this->controller->close();
        }
        if($this->server instanceof ServerInterface) {
            @$this->server->close();
        }
        if($this->loop instanceof LoopInterface) {
            @$this->loop->stop();
        }

        exit;
    }

    /**
     * Connects to ProcessManager, master process.
     * @throws \RuntimeException
     */
    public function run(){

        $this->errorLogger = new BufferingLogger();
        ErrorHandler::register(new ErrorHandler($this->errorLogger));

        $connector = new UnixConnector($this->loop);
        $unixSocket = $this->getControllerSocketPath(false);

        $connector->connect($unixSocket)->done(
        /**
         * @param $controller
         */
            function($controller){
                $this->controller = $controller;

                $this->logger = new StdLogger();

                //Configure logger
                $this->logger->setStdOutput($this->output);
                if($this->output instanceof ConsoleOutputInterface) {
                    $this->logger->setStdError($this->output->getErrorOutput());
                }

                $this->requestListener->getBridge()
                    ->setDebug((bool)$this->isDebug())
                    ->setLogger($this->logger);

                $pcntl = new PCNTL($this->loop);
                $pcntl->on(SIGTERM, [$this, 'shutdown']);
                $pcntl->on(SIGINT, [$this, 'shutdown']);
                register_shutdown_function([$this, 'shutdown']);

                $this->bindProcessMessage($this->controller);
                $this->controller->on('close', [$this, 'shutdown']);

                $socketPath = $this->getSlaveSocketPath($this->config['host'], $this->config['port']);
                $this->server = new Server($socketPath, $this->loop, $this->config);

                $httpServer = new HttpServer([$this, 'onRequest'], $this->getMaxConcurrentRequests());
                $httpServer->listen($this->server);

                $this->sendMessage($this->controller, 'register', ['pid' => getmypid(), 'port' => $this->config['port']]);
            }
        );

        $this->loop->run();
    }

    /**
     * @param array $data
     * @param \React\Socket\ConnectionInterface $conn
     * @throws \Exception
     */
    public function commandBootstrap(array $data, ConnectionInterface $conn){
        $this->bootstrap();
    }

    /**
     * Handles incoming requests and transforms a $request into a $response by reference.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface|Promise
     * @throws \Exception
     */
    public function onRequest(ServerRequestInterface $request){

        $logTime = date('d/M/Y:H:i:s O');
        $promise = $this->requestListener->handle($request)
            ->then(function(ResponseInterface $response) use ($request, $logTime){
                if($this->isLogging()) {
                    $this->logResponse($request, $response, $logTime);
                }

                return $response;
            });

        return $promise;
    }

}
