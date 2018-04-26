<?php

/**
 * ReactPHP Symfony Bridge.
 *
 * LICENSE
 *
 * This source file is subject to the MIT license and the version 3 of the GPL3
 * license that are bundled with this package in the folder licences
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to richarddeloge@gmail.com so we can send you a copy immediately.
 *
 *
 * @copyright   Copyright (c) 2009-2017 Richard Déloge (richarddeloge@gmail.com)
 *
 * @link        http://teknoo.software/reactphp/symfony Project website
 *
 * @license     http://teknoo.software/license/mit         MIT License
 * @author      Richard Déloge <richarddeloge@gmail.com>
 */

namespace Other\PmBundle\Bridge;

use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response as ReactResponse;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Class RequestBridge.
 *
 * @copyright   Copyright (c) 2009-2017 Richard Déloge (richarddeloge@gmail.com)
 *
 * @link        http://teknoo.software/symfony-react Project website
 *
 * @license     http://teknoo.software/license/mit         MIT License
 * @author      Richard Déloge <richarddeloge@gmail.com>
 */
class RequestBridge{

    protected $debug = false;
    /**
     * Symfony Kernel to use to handle and execute HTTP Request.
     *
     * @var KernelInterface
     */
    protected $kernel;

    /**
     * To convert PSR7 Request to Symfony Request.
     *
     * @var HttpFoundationFactoryInterface
     */
    protected $httpFoundationFcty;

    /**
     * To convert Symfony Response to PSR7 Response.
     *
     * @var DiactorosFactory
     */
    protected $diactorosFactory;

    /**
     * To log requests result, as Apache and errors.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * RequestBridge constructor.
     *
     * @param KernelInterface $kernel
     * @param HttpFoundationFactoryInterface $foundationFactory
     * @param DiactorosFactory $diactorosFactory
     */
    public function __construct(
        KernelInterface $kernel,
        HttpFoundationFactoryInterface $foundationFactory,
        DiactorosFactory $diactorosFactory
    ){
        $this->kernel = $kernel;
        $this->httpFoundationFcty = $foundationFactory;
        $this->diactorosFactory = $diactorosFactory;
    }

    /**
     * If the Kernel support Terminate behavior, execute it.
     *
     * @param SymfonyRequest $request
     * @param SymfonyResponse $response
     *
     * @return self
     */
    protected function terminate(SymfonyRequest $request, SymfonyResponse $response): RequestBridge{
        if($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }

        return $this;
    }

    /**
     * To add in the log system an error durring the request.
     * If no logger has been defined, this operation is ignored.
     *
     * @param ServerRequestInterface $request
     * @param \Throwable $error
     */
    protected function logError(ServerRequestInterface $request, \Throwable $error){
        if(!$this->logger instanceof LoggerInterface) {
            return;
        }

        $server = $request->getServerParams();

        $message = \sprintf(
            '%s - [%] %s in %s (%s)',
            $server['REMOTE_ADDR'],
            date('d/M/Y H:i:s O'),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        );

        $this->logger->error($message);
    }

    /**
     * Called by method run, when the Symfony Request is ready to execute it with the Symfony Kernel.
     * The response must be passed to ReactPHP Http via $resolve callable after be converted to PSR7 Response by
     * Diactoros factory.
     *
     *
     * @param SymfonyRequest $request
     * @param callable $resolve
     *
     * @return RequestBridge
     * @throws \Exception
     */
    protected function executePreparedRequest(SymfonyRequest $request, callable $resolve): RequestBridge{

        $sfResponse = $this->kernel->handle($request);
        $resolve($this->diactorosFactory->createResponse($sfResponse));
        $this->terminate($request, $sfResponse);

        return $this;
    }

    /**
     * To register a logger into the bridge to register request summary and errors.
     *
     * @param LoggerInterface $logger
     *
     * @return self
     */
    public function setLogger($logger): RequestBridge{
        if($this->logger instanceof LoggerInterface) {
            $this->logger = $logger;
        }

        return $this;
    }

    /**
     * @param bool $debug
     * @return \Other\PmBundle\Bridge\RequestBridge
     */
    public function setDebug(bool $debug): RequestBridge{
        $this->debug = $debug;

        return $this;
    }

    /**
     * Magic method to clone the Symfony Kernel when this RequestBridge instance is cloned by the listener.
     */
    public function __clone(){
        $this->kernel = clone $this->kernel;
    }

    /**
     * Called by the RequestListener or when ReactPHP emit the data event to convert the ReactPHP Request to a Symfony
     * Request and execute it with Symfony before send result to ReactPHP.
     *
     * The method convert, thanks to Http Foundation factory all PSR7 request to Symfony request. Errors and exception
     * are catched by this method, to generate an Error response (40* ou 50* responses) from the exception instance.
     *
     * @param ServerRequestInterface $request
     * @param callable $resolve
     *
     * @return RequestBridge
     */
    public function run(ServerRequestInterface $request, callable $resolve): RequestBridge{
        try {
            $sfRequest = $this->httpFoundationFcty->createRequest($request);

            return $this->executePreparedRequest($sfRequest, $resolve);
        } catch(HttpException $error) {
            $this->logError($request, $error);
            $resolve(new ReactResponse($error->getStatusCode(), $error->getHeaders(), $error->getMessage()));
        } catch(\Throwable $error) {
            $this->logError($request, $error);
            $resolve(new ReactResponse(500, [], $error->getMessage()));
        }

        return $this;
    }
}
