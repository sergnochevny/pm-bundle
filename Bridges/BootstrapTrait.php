<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\Bridges;

trait BootstrapTrait
{
    private $middleware;

    /**
     * @param string|null $appBootstrap The environment your application will use to bootstrap (if any)
     * @return string
     * @throws \RuntimeException
     */
    private function normalizeBootstrapClass($appBootstrap)
    {
        $appBootstrap = str_replace('\\\\', '\\', $appBootstrap);

        $bootstraps = [
            $appBootstrap,
            '\\' . $appBootstrap,
            '\\Other\\PmBundle\\Bootstraps\\' . ucfirst($appBootstrap)
        ];

        foreach ($bootstraps as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return $appBootstrap;
    }
}
