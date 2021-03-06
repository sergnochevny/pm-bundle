<?php

/**
 * Copyright (c) 2018. sn
 */

namespace Other\PmBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Other\PmBundle\DependencyInjection\DoctrineCompilerPass;

/**
 * Class ReactPHPBundle.
 *
 * @copyright   Copyright (c) 2009-2017 Richard Déloge (richarddeloge@gmail.com)
 *
 * @link        http://teknoo.software/symfony-react Project website
 *
 * @license     http://teknoo.software/license/mit         MIT License
 * @author      Richard Déloge <richarddeloge@gmail.com>
 */
class OtherPmBundle extends Bundle
{
    /**
     * To enable Compiler pass to register Request Parser entities into Request Builder
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new DoctrineCompilerPass());
    }
}
