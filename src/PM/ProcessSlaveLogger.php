<?php
/**
 * Copyright (c) 2018. AIT
 */

declare(ticks=1);

namespace PMB\PMBundle\PM;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
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
        $levels = [
            LogLevel::EMERGENCY => 'emergency',
            LogLevel::ALERT => 'alert',
            LogLevel::CRITICAL => 'critical',
            LogLevel::ERROR => 'error',
            LogLevel::WARNING => 'warning',
            LogLevel::NOTICE => 'notice',
            LogLevel::INFO => 'info',
            LogLevel::DEBUG => 'debug',
        ];

        $level = $levels[$level];

        $this->sendMessage(['cmd' => 'log', 'level' => $level, 'message' => $message, 'context' => $context]);
    }

}
