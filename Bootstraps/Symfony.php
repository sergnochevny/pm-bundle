<?php
/**
 * Copyright (c) 2018. AIT
 */

namespace Other\PmBundle\Bootstraps;

use Other\PmBundle\PM\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * A default bootstrap for the Symfony framework
 */
class Symfony implements BootstrapInterface{

    /**
     * Create a Symfony application
     *
     * @return \AppKernel
     * @throws \Symfony\Component\Dotenv\Exception\FormatException
     * @throws \Symfony\Component\Dotenv\Exception\PathException
     */
    public function getApplication(){
        // include applications autoload
        $appAutoLoader = './app/autoload.php';
        if(file_exists($appAutoLoader)) {
            require $appAutoLoader;
        } else {
            require './vendor/autoload.php';
        }

        // environment loading as of Symfony 3.3
        if(!getenv('APP_ENV') && class_exists('Symfony\Component\Dotenv\Dotenv') && file_exists(realpath('.env'))) {
            (new \Symfony\Component\Dotenv\Dotenv())->load(realpath('.env'));
        }

        $class = class_exists('\AppKernel') ? '\AppKernel' : '\App\Kernel';

        //since we need to change some services, we need to manually change some services
        $app = new $class($this->appenv, $this->debug);

        // We need to change some services, before the boot, because they would 
        // otherwise be instantiated and passed to other classes which makes it 
        // impossible to replace them.

        Utils::bindAndCall(function() use ($app){
            // init bundles
            $app->initializeBundles();

            // init container
            $app->initializeContainer();
        }, $app);

        Utils::bindAndCall(function() use ($app){
            foreach($app->getBundles() as $bundle) {
                $bundle->setContainer($app->container);
                $bundle->boot();
            }

            $app->booted = true;
        }, $app);

        return $app;
    }

}
