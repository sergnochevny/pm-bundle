<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\PM;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\UnixConnector;

class ProcessClient
{
    use ProcessCommunicationTrait;

    /**
     * @var LoopInterface
     */
    protected $loop;

    public function __construct()
    {
        $this->loop = Factory::create();
    }

    /**
     * @param $command
     * @param $options
     * @param $callback
     * @throws \RuntimeException
     */
    protected function request($command, $options, $callback)
    {
        $data = [
            'cmd' => $command,
            'options' => $options
        ];

        $connector = new UnixConnector($this->loop);
        $unixSocket = $this->getControllerSocketPath(false);

        $connector->connect($unixSocket)->done(
            function (ConnectionInterface $connection) use ($data, $callback) {
                $result = '';

                $connection->on('data', function ($data) use (&$result) {
                    $result .= $data;
                });

                $connection->on('close', function () use ($callback, &$result) {
                    $callback($result);
                });

                $connection->write(json_encode($data) . PHP_EOL);
            }
        );
    }

    /**
     * @param callable $callback
     * @throws \RuntimeException
     */
    public function getStatus(callable $callback)
    {
        $this->request('status', [], function ($result) use ($callback) {
            $callback(json_decode($result, true));
        });
        $this->loop->run();
    }

    /**
     * @param callable $callback
     * @throws \RuntimeException
     */
    public function stopProcessManager(callable $callback)
    {
        $this->request('stop', [], function ($result) use ($callback) {
            $callback(json_decode($result, true));
        });
        $this->loop->run();
    }
}