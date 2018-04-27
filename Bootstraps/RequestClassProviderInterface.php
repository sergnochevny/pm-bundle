<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\Bootstraps;

/**
 * Implement this interface if HttpKernel bridge needs to return a specialized request class
 */
interface RequestClassProviderInterface
{
    public function requestClass();
}