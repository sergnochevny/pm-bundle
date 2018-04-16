<?php

/**
 * Copyright (c) 2018. AIT
 */

namespace Other\Bundle\PMBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Other\Bundle\PMBundle\DependencyInjection\DoctrineCompilerPass;

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
class PMBundle extends Bundle
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
