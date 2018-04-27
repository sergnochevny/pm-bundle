<?php
/**
 * Copyright (c) 2018. AIT
 */

/**
 * Date: 18.04.2018
 * Time: 12:05
 */

namespace Other\PmBundle\PM;

use Evenement\EventEmitter;
use InvalidArgumentException;
use React\Http\Io\IniUtil;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Http\StreamingServer;
use React\Socket\ServerInterface;

final class HttpServer extends EventEmitter{

    /**
     * @internal
     */
    const MAXIMUM_CONCURRENT_REQUESTS = 100;

    /**
     * @var StreamingServer
     */
    private $streamingServer;

    private $maxConcurrentRequests = 0;

    /**
     * @see StreamingServer::__construct()
     * @param $requestHandler
     * @param int $maxConcurrentRequests
     * @throws \InvalidArgumentException
     */
    public function __construct($requestHandler, int $maxConcurrentRequests = 0){
        if(!is_callable($requestHandler) && !is_array($requestHandler)) {
            throw new InvalidArgumentException('Invalid request handler given');
        }

        $middleware = [];
        $this->setMaxConcurrentRequests($maxConcurrentRequests);
//        $middleware[] = new LimitConcurrentRequestsMiddleware($this->getConcurrentRequestsLimit());
        $middleware[] = new RequestBodyBufferMiddleware();
        // Checking for an empty string because that is what a boolean
        // false is returned as by ini_get depending on the PHP version.
        // @link http://php.net/manual/en/ini.core.php#ini.enable-post-data-reading
        // @link http://php.net/manual/en/function.ini-get.php#refsect1-function.ini-get-notes
        // @link https://3v4l.org/qJtsa
        $enablePostDataReading = ini_get('enable_post_data_reading');
        if($enablePostDataReading !== '') {
            $middleware[] = new RequestBodyParserMiddleware();
        }

        if(is_callable($requestHandler)) {
            $middleware[] = $requestHandler;
        } else {
            $middleware = array_merge($middleware, $requestHandler);
        }

        $this->streamingServer = new StreamingServer($middleware);

        $that = $this;
        $this->streamingServer->on('error', function($error) use ($that){
            $that->emit('error', [$error]);
        });
    }

    /**
     * @return int
     * @codeCoverageIgnore
     * @throws \InvalidArgumentException
     */
    private function getConcurrentRequestsLimit(){
        if(!empty($this->getMaxConcurrentRequests())) {
            return $this->getMaxConcurrentRequests();
        }
        if(ini_get('memory_limit') == -1) {
            return self::MAXIMUM_CONCURRENT_REQUESTS;
        }

        $availableMemory = IniUtil::iniSizeToBytes(ini_get('memory_limit')) / 4;
        $concurrentRequests = ceil($availableMemory / IniUtil::iniSizeToBytes(ini_get('post_max_size')));

        if($concurrentRequests >= self::MAXIMUM_CONCURRENT_REQUESTS) {
            return self::MAXIMUM_CONCURRENT_REQUESTS;
        }

        return $concurrentRequests;
    }

    /**
     * @return mixed
     */
    public function getMaxConcurrentRequests(){
        return $this->maxConcurrentRequests;
    }

    /**
     * @param int $maxRequests
     */
    public function setMaxConcurrentRequests(int $maxRequests): void{
        if(!empty($maxRequests)) {
            $this->maxConcurrentRequests = $maxRequests;
        }
    }

    /**
     * @see StreamingServer::listen()
     * @param \React\Socket\ServerInterface $server
     */
    public function listen(ServerInterface $server){
        $this->streamingServer->listen($server);
    }

}