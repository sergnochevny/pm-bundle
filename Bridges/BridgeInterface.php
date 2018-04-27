<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\Bridges;

use Interop\Http\Server\RequestHandlerInterface;

interface BridgeInterface extends RequestHandlerInterface{

    /**
     * Bootstrap an application
     *
     * @param $appKernel
     * @return
     */
    public function bootstrap($appKernel);
}
