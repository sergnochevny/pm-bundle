<?php
/**
 * Copyright (c) 2018. AIT
 */

declare(ticks=1);

namespace Other\PmBundle\PM;

use Evenement\EventEmitterInterface;
use Other\PmBundle\Bridges\BridgeInterface;
use function Other\PmBundle\console_log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;
use React\Socket\UnixConnector;
use React\Socket\UnixServer;
use React\Stream\ReadableResourceStream;
use ReactPCNTL\PCNTL;
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
     * @var LoopInterface
     */
    protected $loop;
    /**
     * ProcessManager master process connection
     *
     * @var ConnectionInterface
     */
    protected $controller;
    /**
     * @var string
     */
    protected $bridgeName;
    /**
     * @var BridgeInterface
     */
    protected $bridge;
    /**
     * @var string
     */
    protected $appKernel;
    /**
     * @var bool
     */
    protected $inShutdown = false;
    /**
     * @var BufferingLogger
     */
    protected $errorLogger;

    protected $logFormat = '[$time_local] $remote_addr - $remote_user "$request" $status $bytes_sent "$http_referer"';
    /**
     * Contains some configuration options.
     *
     * 'port' => int (server port)
     * 'logging' => boolean (false) (If it should log all requests)
     * ...
     *
     * @var array
     */
    protected $config;

    protected $bootstrapReadyTimeout = 0.5;

    public function __construct($appKernel, array $config = []){
        $this->setSocketPath($config['socket-path']);
        $this->bridgeName = $config['bridge'];
        $this->appKernel = $appKernel;
        $this->config = $config;

        if($this->config['session_path']) {
            session_save_path($this->config['session_path']);
        }
    }

    /**
     * Attempt a connection to the unix socket.
     *
     * @throws \RuntimeException
     */
    private function doConnect(){
        $connector = new UnixConnector($this->loop);
        $unixSocket = $this->getControllerSocketPath(false);

        $connector->connect($unixSocket)->done(
            function($controller){
                $this->controller = $controller;

                $pcntl = new PCNTL($this->loop);
                $pcntl->on(SIGTERM, [$this, 'shutdown']);
                $pcntl->on(SIGINT, [$this, 'shutdown']);
                register_shutdown_function([$this, 'shutdown']);

                $this->bindProcessMessage($this->controller);
                $this->controller->on('close', [$this, 'shutdown']);

                // port is the slave identifier
                $port = $this->config['port'];
                $socketPath = $this->getSlaveSocketPath($port, true);
                $this->server = new UnixServer($socketPath, $this->loop);

                $httpServer = new HttpServer([$this, 'onRequest']);
                $httpServer->listen($this->server);

                $this->sendMessage($this->controller, 'register', ['pid' => getmypid(), 'port' => $port]);
            }
        );
    }

    /**
     * Attempt a connection through the unix socket until it succeeds.
     * This is a workaround for an issue where the (hardcoded) 1s socket timeout is triggered due to a busy socket.
     */
    private function tryConnect(){
        try {
            $this->doConnect();
        } catch(\RuntimeException $ex) {
            // Failed to connect to the controller, there was probably a timeout accessing the socket...
            $this->loop->addTimer(1, function(){
                $this->tryConnect();
            });
        }
    }

    /**
     * @return BridgeInterface
     */
    protected function getBridge(){
        if(null === $this->bridge && $this->bridgeName) {
            if(true === class_exists($this->bridgeName)) {
                $bridgeClass = $this->bridgeName;
            } else {
                $bridgeClass = sprintf('Other\\PmBundle\\Bridges\\%s', ucfirst($this->bridgeName));
            }

            $this->bridge = new $bridgeClass;
        }

        return $this->bridge;
    }

    /**
     * Bootstraps the actual application.
     *
     * @param $appKernel
     */
    protected function bootstrap($appKernel){
        if($bridge = $this->getBridge()) {
            $bridge->bootstrap($appKernel);
            $this->sendMessage($this->controller, 'ready');
        }
    }

    /**
     * Handle a redirected request from master.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function handleRequest(ServerRequestInterface $request){

        if($bridge = $this->getBridge()) {
            $response = $bridge->handle($request);
        } else {
            $response = new Response(404, [], 'No Bridge defined');
        }

        if(headers_sent()) {
            //when a script sent headers the cgi process needs to die because the second request
            //trying to send headers again will fail (headers already sent fatal). Its best to not even
            //try to send headers because this break the whole approach of php-pm using php-cgi.
            error_log(
                'Headers have been sent, but not redirected to client. Force restart of a worker. ' .
                'Make sure your application does not send headers on its own.'
            );
            $this->shutdown();
        }

        return $response;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param string $timeLocal
     * @param string $remoteIp
     * @throws \InvalidArgumentException
     */
    protected function logResponse(ServerRequestInterface $request, ResponseInterface $response, $timeLocal, $remoteIp){
        $logFunction = function($size) use ($request, $response, $timeLocal, $remoteIp){
            $requestString = $request->getMethod() . ' ' . $request->getUri()->getPath() . ' HTTP/' . $request->getProtocolVersion();
            $statusCode = $response->getStatusCode();

            if($statusCode < 400) {
                $requestString = "<info>$requestString</info>";
                $statusCode = "<info>$statusCode</info>";
            }

            $message = str_replace(
                [
                    '$remote_addr',
                    '$remote_user',
                    '$time_local',
                    '$request',
                    '$status',
                    '$bytes_sent',
                    '$http_referer',
                    '$http_user_agent',
                ],
                [
                    $remoteIp,
                    '-', //todo remote_user
                    $timeLocal,
                    $requestString,
                    $statusCode,
                    $size,
                    $request->hasHeader('Referer') ? $request->getHeaderLine('Referer') : '-',
                    $request->hasHeader('User-Agent') ? $request->getHeaderLine('User-Agent') : '-'
                ],
                $this->logFormat
            );

            if($response->getStatusCode() >= 400) {
                $message = "<error>$message</error>";
            }

            $this->sendMessage($this->controller, 'log', ['message' => $message]);
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

        if($this->controller && $this->controller->isWritable()) {
            $this->controller->close();
        }
        if($this->server) {
            @$this->server->close();
        }
        if($this->loop) {
            @$this->loop->stop();
        }

        exit;
    }

    /**
     * Connects to ProcessManager, master process.
     */
    public function run(){
        $this->loop = Factory::create();

        $this->errorLogger = new BufferingLogger();
        ErrorHandler::register(new ErrorHandler($this->errorLogger));

        $this->tryConnect();
        $this->loop->run();
    }

    /**
     * @param array $data
     * @param \React\Socket\ConnectionInterface $conn
     * @throws \Exception
     */
    public function commandBootstrap(array $data, ConnectionInterface $conn){
        $this->bootstrap($this->appKernel);
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

        $catchLog = function($e){
            console_log((string)$e);

            return new Response(500);
        };

        try {
            $response = $this->handleRequest($request);
        } catch(\Throwable $t) {
            // PHP >= 7.0
            $response = $catchLog($t);
        } catch(\Exception $e) {
            // PHP < 7.0
            $response = $catchLog($e);
        }

        $promise = new Promise(function($resolve) use ($response){
            return $resolve($response);
        });

        $promise = $promise->then(function(ResponseInterface $response) use ($request, $logTime, $remoteIp){
            if($this->isLogging()) {
                $this->logResponse($request, $response, $logTime, $remoteIp);
            }

            return $response;
        });

        return $promise;
    }
}
