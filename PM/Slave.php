<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\PM;

use React\Socket\ConnectionInterface;
use React\ChildProcess\Process;

class Slave
{
    /*
     * Slave state model
     *
     * 1. created (slave pid not yet available)
     * 2. registered (slave pid available)
     * 3. ready (application bootstrapped)
     * 4. busy (handling request)
     * 5. closed (awaiting termination)
     * 6. locked (busy, but gracefully awaiting termination)
     */

    const ANY = 0;
    const CREATED = 1;
    const REGISTERED = 2;
    const READY = 3;
    const BUSY = 4;
    const CLOSED = 5;
    const LOCKED = 6;

    protected $socketPath;

    protected $socketScheme;

    /**
     * Slave status
     *
     * @var int
     */
    private $status;

    /**
     * Slave port - this is an identifier mapped to a socket path
     */
    private $port;

    private $host;

    private $process;
    private $pid;
    private $connection; // slave incoming

    /**
     * Maximum number of requests a slave can handle
     *
     * @var int
     */
    private $maxRequests = 0;

    /**
     * Number of handled requests
     *
     * @var int
     */
    private $handledRequests = 0;

    public function __construct($host, $port, $maxRequests)
    {
        $this->host = $host;
        $this->port = $port;
        $this->maxRequests = $maxRequests;

        $this->status = self::CREATED;
    }

    /**
     * Attach a slave to a running process
     *
     * @param Process $process
     */
    public function attach(Process $process)
    {
        $this->process = $process;
    }

    /**
     * Register a slave after it's process started
     *
     * @param int $pid
     * @param ConnectionInterface $connection
     *
     * @return void
     * @throws \LogicException
     */
    public function register($pid, ConnectionInterface $connection)
    {
        if ($this->status !== self::CREATED) {
            throw new \LogicException('Cannot register a slave that is not in created state');
        }

        $this->pid = $pid;
        $this->connection = $connection;

        $this->status = self::REGISTERED;
    }

    /**
     * Ready a slave after bootstrap completed
     *
     * @return void
     * @throws \LogicException
     */
    public function ready()
    {
        if ($this->status !== self::REGISTERED) {
            throw new \LogicException('Cannot ready a slave that is not in registered state');
        }

        $this->status = self::READY;
    }

    /**
     * Occupies a slave for request handling
     *
     * @return void
     * @throws \LogicException
     */
    public function occupy()
    {
        if ($this->status !== self::READY) {
            throw new \LogicException('Cannot occupy a slave that is not in ready state');
        }

        $this->status = self::BUSY;
    }

    /**
     * Releases a slave from request handling
     *
     * @return void
     * @throws \LogicException
     */
    public function release()
    {
        if ($this->status !== self::BUSY) {
            throw new \LogicException('Cannot release a slave that is not in busy state');
        }

        $this->status = self::READY;
        $this->handledRequests++;
    }

    /**
     * Close slave
     *
     * Closed slaves don't accept connections anymore and are awaiting termination.
     * Closing is unconditional and does not verify slave status before closing.
     *
     * @return void
     */
    public function close()
    {
        $this->status = self::CLOSED;
    }

    /**
     * Lock slave
     *
     * Locked slaves are closed for new requests, but is finishing the current
     * request gracefully as to not interrupt the response lifecycle.
     *
     * @return void
     * @throws \LogicException
     */
    public function lock()
    {
        if ($this->status !== self::BUSY) {
            throw new \LogicException('Cannot lock a slave that is not in busy state');
        }

        $this->status = self::LOCKED;
    }

    /**
     * Get slave status
     *
     * @return int status
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Get slave port
     *
     * @return int port
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     *
     * @return string host
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Get slave incoming connection
     *
     * @return ConnectionInterface slave connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Get slave pid
     *
     * @return int slave pid
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * Get slave process
     *
     * @return Process slave process
     */
    public function getProcess()
    {
        return $this->process;
    }

    /**
     * Get number of request handled by slave
     *
     * @return int handled requests
     */
    public function getHandledRequests()
    {
        return $this->handledRequests;
    }

    /**
     * Get maximum number of request slave can handle
     *
     * @return int handled requests
     */
    public function getMaxRequests()
    {
        return $this->maxRequests;
    }

    /**
     * String conversion for debugging
     *
     * @return string
     */
    public function __toString()
    {
        switch ($this->status) {
            case self::CREATED:
                $status = 'CREATED';
                break;
            case self::REGISTERED:
                $status = 'REGISTERED';
                break;
            case self::READY:
                $status = 'READY';
                break;
            case self::BUSY:
                $status = 'BUSY';
                break;
            case self::CLOSED:
                $status = 'CLOSED';
                break;
            default:
                $status = 'INVALID';
        }

        return print_r([
            'status' => $status,
            'host' => $this->host,
            'port' => $this->port,
            'pid' => $this->pid
        ], 1);
    }
}
