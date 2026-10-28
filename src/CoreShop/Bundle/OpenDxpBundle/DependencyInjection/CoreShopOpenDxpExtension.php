<?php

declare(strict_types=1);

/*
 * CoreShop
 *
 * This source file is available under two different licenses:
 *  - GNU General Public License version 3 (GPLv3)
 *  - CoreShop Commercial License (CCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CoreShop GmbH (https://www.coreshop.com)
 * @license    https://www.coreshop.com/license     GPLv3 and CCL
 *
 */

namespace CoreShop\Bundle\OpenDxpBundle\DependencyInjection;

use CoreShop\Bundle\OpenDxpBundle\Attribute\AsGridAction;
use CoreShop\Bundle\OpenDxpBundle\Attribute\AsGridFilter;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\RegisterGridActionPass;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\RegisterGridFilterPass;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Extension\AbstractOpenDxpExtension;
use CoreShop\Component\OpenDxp\DataObject\Grid\GridActionInterface;
use CoreShop\Component\OpenDxp\DataObject\Grid\GridFilterInterface;
use CoreShop\Component\Registry\Autoconfiguration;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class CoreShopOpenDxpExtension extends AbstractOpenDxpExtension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configs = $this->processConfiguration($this->getConfiguration([], $container), $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $bundles = $container->getParameter('kernel.bundles');

        if (array_key_exists('OpenDxpDataHubBundle', $bundles)) {
            $loader->load('services/data_hub.yml');
        }

        $this->registerOpenDxpResources('coreshop', $configs['opendxp_admin'], $container);

        $loader->load('services.yml');

        Autoconfiguration::registerForAutoConfiguration(
            $container,
            GridActionInterface::class,
            RegisterGridActionPass::GRID_ACTION_TAG,
            AsGridAction::class,
            $configs['autoconfigure_with_attributes'],
        );

        Autoconfiguration::registerForAutoConfiguration(
            $container,
            GridFilterInterface::class,
            RegisterGridFilterPass::GRID_FILTER_TAG,
            AsGridFilter::class,
            $configs['autoconfigure_with_attributes'],
        );
    }
}
