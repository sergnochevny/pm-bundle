<?php
/**
 * Copyright (c) 2018. AIT
 */

declare(ticks=1);

namespace Other\PmBundle\Logger;

use Psr\Log\AbstractLogger;
use React\Socket\ConnectionInterface;

class ProcessSlaveLogger extends AbstractLogger{

    /**
     * ProcessManager master process connection
     *
     * @var ConnectionInterface
     */
    protected $controller;

    public function __construct(ConnectionInterface $conn){
        $this->controller = $conn;
    }

    /**
     * Sends a message through $conn.
     *
     * @param string $command
     * @param array $message
     */
    protected function sendMessage(array $message = []){
        $this->controller->write(json_encode($message) . PHP_EOL);
    }

    public function log($level, $message, array $context = []){
        $this->sendMessage(['cmd' => 'log', 'level' => $level, 'message' => $message, 'context' => $context]);
    }

}
