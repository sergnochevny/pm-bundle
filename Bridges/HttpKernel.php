<?php
/**
 * Copyright (c) 2018. sn
 */

namespace Other\PmBundle\Bridges;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use RingCentral\Psr7;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyFile;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse as SymfonyStreamedResponse;
use Symfony\Component\HttpKernel\TerminableInterface;

class HttpKernel implements BridgeInterface{

    /**
     * An application implementing the HttpKernelInterface
     *
     * @var \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    protected $application;

    /**
     * @var string[]
     */
    protected $tempFiles = [];

    /**
     * Convert React\Http\Request to Symfony\Component\HttpFoundation\Request
     *
     * @param ServerRequestInterface $psrRequest
     *
     * @return SymfonyRequest $syRequest
     */
    protected function mapRequest(ServerRequestInterface $psrRequest){
        $method = $psrRequest->getMethod();
        $query = $psrRequest->getQueryParams();

        // cookies
        $cookies = [];

        foreach($psrRequest->getHeader('Cookie') as $cookieHeader) {
            $cookiesStrs = explode(';', $cookieHeader);

            foreach($cookiesStrs as $cookie) {
                if(strpos($cookie, '=') == false) {
                    continue;
                }
                list($name, $value) = explode('=', trim($cookie));
                $cookies[$name] = $value;

                if($name === session_name()) {
                    session_id($value);
                }
            }
        }

        /** @var \React\Http\Io\UploadedFile $file */
        $uploadedFiles = $psrRequest->getUploadedFiles();

        $mapFiles = function(&$files) use (&$mapFiles){
            foreach($files as &$file) {
                if(is_array($file)) {
                    $mapFiles($file);
                } else if($file instanceof UploadedFileInterface) {
                    $tmpname = tempnam(sys_get_temp_dir(), 'upload');
                    $this->tempFiles[] = $tmpname;

                    if(UPLOAD_ERR_NO_FILE == $file->getError()) {
                        $file = null;
                    } else {
                        if(UPLOAD_ERR_OK == $file->getError()) {
                            file_put_contents($tmpname, (string)$file->getStream());
                        }
                        $file = new SymfonyFile(
                            $tmpname,
                            $file->getClientFilename(),
                            $file->getClientMediaType(),
                            $file->getSize(),
                            $file->getError(),
                            true
                        );
                    }
                }
            }
        };
        $mapFiles($uploadedFiles);

        // @todo check howto handle additional headers
        // @todo check howto support other HTTP methods with bodies
        $post = $psrRequest->getParsedBody() ?: [];

        $server = [];
        $uri = $psrRequest->getUri();

        if($uri instanceof UriInterface) {
            $server['SERVER_NAME'] = $uri->getHost();
            $server['SERVER_PORT'] = $uri->getPort();
            $server['REQUEST_URI'] = $uri->getPath();
            $server['QUERY_STRING'] = $uri->getQuery();
        }

        $server['REQUEST_METHOD'] = $psrRequest->getMethod();

        $server = array_replace($server, $psrRequest->getServerParams());
        $attributes = $psrRequest->getAttributes() ?: [];

        /** @var SymfonyRequest $syRequest */
        $syRequest = new SymfonyRequest($query, $post, $attributes, $cookies, $uploadedFiles, $server, $psrRequest->getBody());
        $syRequest->setMethod($method);
        $syRequest->headers->replace($psrRequest->getHeaders());

        return $syRequest;
    }

    /**
     * Convert Symfony\Component\HttpFoundation\Response to React\Http\Response
     *
     * @param SymfonyResponse $syResponse
     * @param string $stdout Additional stdout that was catched during handling a request.
     *
     * @return ResponseInterface
     * @throws \InvalidArgumentException
     */
    protected function mapResponse(SymfonyResponse $syResponse, $stdout = ''){
        // end active session
        if(PHP_SESSION_ACTIVE === session_status()) {
            // make sure open session are saved to the storage
            // in case the framework hasn't closed it correctly.
            session_write_close();
        }

        // reset session_id in any case to something not valid, for next request
        session_id('');

        //reset $_SESSION
        session_unset();
        unset($_SESSION);

        $nativeHeaders = [];

        foreach(headers_list() as $header) {
            if(false !== $pos = strpos($header, ':')) {
                $name = substr($header, 0, $pos);
                $value = trim(substr($header, $pos + 1));

                if(isset($nativeHeaders[$name])) {
                    if(!is_array($nativeHeaders[$name])) {
                        $nativeHeaders[$name] = [$nativeHeaders[$name]];
                    }

                    $nativeHeaders[$name][] = $value;
                } else {
                    $nativeHeaders[$name] = $value;
                }
            }
        }

        // after reading all headers we need to reset it, so next request
        // operates on a clean header.
        header_remove();

        $headers = array_merge($nativeHeaders, $syResponse->headers->allPreserveCase());
        $cookies = [];

        /** @var Cookie $cookie */
        foreach($syResponse->headers->getCookies() as $cookie) {
            $cookieHeader = sprintf('%s=%s', $cookie->getName(), $cookie->getValue());

            if($cookie->getPath()) {
                $cookieHeader .= '; Path=' . $cookie->getPath();
            }
            if($cookie->getDomain()) {
                $cookieHeader .= '; Domain=' . $cookie->getDomain();
            }

            if($cookie->getExpiresTime()) {
                $cookieHeader .= '; Expires=' . gmdate('D, d-M-Y H:i:s', $cookie->getExpiresTime()) . ' GMT';
            }

            if($cookie->isSecure()) {
                $cookieHeader .= '; Secure';
            }
            if($cookie->isHttpOnly()) {
                $cookieHeader .= '; HttpOnly';
            }

            $cookies[] = $cookieHeader;
        }

        if(isset($headers['Set-Cookie'])) {
            $headers['Set-Cookie'] = array_merge((array)$headers['Set-Cookie'], $cookies);
        } else {
            $headers['Set-Cookie'] = $cookies;
        }

        $psrResponse = new Psr7\Response($syResponse->getStatusCode(), $headers);

        // get contents
        ob_start();
        if($syResponse instanceof SymfonyStreamedResponse) {
            $syResponse->sendContent();
            $content = @ob_get_clean();
        } else {
            ob_start();
            $content = $syResponse->getContent();
            @ob_end_flush();
        }

        if($stdout) {
            $content = $stdout . $content;
        }

        if(!isset($headers['Content-Length'])) {
            $psrResponse = $psrResponse->withAddedHeader('Content-Length', strlen($content));
        }

        $psrResponse = $psrResponse->withBody(Psr7\stream_for($content));

        foreach($this->tempFiles as $tmpname) {
            if(file_exists($tmpname)) {
                unlink($tmpname);
            }
        }

        return $psrResponse;
    }

    /**
     * Bootstrap an application implementing the HttpKernelInterface.
     *
     * In the process of bootstrapping we decorate our application with any number of
     * *middlewares* using StackPHP's Stack\Builder.
     *
     * The app bootstraping itself is actually proxied off to an object implementing the
     * PHPPM\Bridges\BridgeInterface interface which should live within your app itself and
     * be able to be autoloaded.
     *
     * @param $appKernel
     */
    public function bootstrap($appKernel){
        $this->application = $appKernel;
    }

    /**
     * {@inheritdoc}
     * @throws \InvalidArgumentException
     */
    public function handle(ServerRequestInterface $request){
        if(null === $this->application) {
            // internal server error
            return new Psr7\Response(500, ['Content-type' => 'text/plain'], 'Application not configured during bootstrap');
        }

        $syRequest = $this->mapRequest($request);

        // start buffering the output, so cgi is not sending any http headers
        // this is necessary because it would break session handling since
        // headers_sent() returns true if any unbuffered output reaches cgi stdout.
        ob_start();

        try {

            $syResponse = $this->application->handle($syRequest);
        } catch(\Exception $exception) {
            // internal server error
            error_log((string)$exception);
            $response = new Psr7\Response(500, ['Content-type' => 'text/plain'], 'Unexpected error');

            // end buffering if we need to throw
            @ob_end_clean();

            return $response;
        }

        $out = ob_get_clean();
        $response = $this->mapResponse($syResponse, $out);

        if($this->application instanceof TerminableInterface) {
            $this->application->terminate($syRequest, $syResponse);
        }

        return $response;
    }
}
