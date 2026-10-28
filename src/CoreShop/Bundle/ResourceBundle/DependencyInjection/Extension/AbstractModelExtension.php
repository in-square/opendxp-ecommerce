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

namespace CoreShop\Bundle\ResourceBundle\DependencyInjection\Extension;

use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Extension\AbstractOpenDxpExtension;
use CoreShop\Bundle\ResourceBundle\CoreShopResourceBundle;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Driver\DriverProvider;
use CoreShop\Component\Resource\Metadata\Metadata;
use Symfony\Component\DependencyInjection\ContainerBuilder;

abstract class AbstractModelExtension extends AbstractOpenDxpExtension
{
    /**
     * @param string           $applicationName
     * @param string           $driver
     */
    protected function registerResources($applicationName, $driver, array $resources, ContainerBuilder $container): void
    {
        $container->setParameter(sprintf('%s.driver.%s', $this->getAlias(), $driver), true);
        $container->setParameter(sprintf('%s.driver', $this->getAlias()), $driver);

        foreach ($resources as $resourceName => $resourceConfig) {
            $alias = $applicationName . '.' . $resourceName;
            $resourceConfig = array_merge(['driver' => $driver], $resourceConfig);

            $resources = $container->hasParameter('coreshop.resources') ? $container->getParameter('coreshop.resources') : [];
            $resources = array_merge($resources, [$alias => $resourceConfig]);
            $container->setParameter('coreshop.resources', $resources);

            $metadata = Metadata::fromAliasAndConfiguration($alias, $resourceConfig);

            DriverProvider::get($metadata)->load($container, $metadata);

            if ($metadata->hasParameter('translation')) {
                $alias = $alias . '_translation';
                $resourceConfig = array_merge(['driver' => $driver], $resourceConfig['translation']);

                $resources = $container->hasParameter('coreshop.resources') ? $container->getParameter('coreshop.resources') : [];
                $resources = array_merge($resources, [$alias => $resourceConfig]);
                $container->setParameter('coreshop.resources', $resources);

                $metadata = Metadata::fromAliasAndConfiguration($alias, $resourceConfig);

                DriverProvider::get($metadata)->load($container, $metadata);
            }
        }
    }

    /**
     * @param string           $applicationName
     */
    protected function registerOpenDxpModels($applicationName, array $models, ContainerBuilder $container): void
    {
        $container->setParameter(sprintf('%s.driver.%s', $this->getAlias(), 'opendxp'), true);
        $container->setParameter(sprintf('%s.driver', $this->getAlias()), 'opendxp');

        foreach ($models as $modelName => $modelConfig) {
            $alias = $applicationName . '.' . $modelName;
            $modelConfig = array_merge(['driver' => 'opendxp', 'alias' => $this->getAlias()], $modelConfig);

            $modelConfig['opendxp_class'] = match ($modelConfig['classes']['type']) {
                CoreShopResourceBundle::OPENDXP_MODEL_TYPE_FIELD_COLLECTION => str_replace(
                    'OpenDxp\Model\DataObject\Fieldcollection\Data\\',
                    '',
                    $modelConfig['classes']['model'],
                ),
                CoreShopResourceBundle::OPENDXP_MODEL_TYPE_BRICK => str_replace(
                    'OpenDxp\Model\DataObject\Objectbrick\Data\\',
                    '',
                    $modelConfig['classes']['model'],
                ),
                default => str_replace(
                    'OpenDxp\Model\DataObject\\',
                    '',
                    $modelConfig['classes']['model'],
                ),
            };

            foreach (['coreshop.all.opendxp_classes', sprintf('%s.opendxp_classes', $applicationName)] as $parameter) {
                $models = $container->hasParameter($parameter) ? $container->getParameter($parameter) : [];
                $models = array_merge($models, [$alias => $modelConfig]);
                $container->setParameter($parameter, $models);
            }

            $metadata = Metadata::fromAliasAndConfiguration($alias, $modelConfig);

            DriverProvider::get($metadata)->load($container, $metadata);
        }
    }

    public function registerStack(string $applicationName, array $stack, ContainerBuilder $container): void
    {
        $appParameterName = sprintf('%s.stack', $applicationName);
        $globalParameterName = 'coreshop.all.stack';

        foreach ([$appParameterName, $globalParameterName] as $parameterName) {
            /**
             * @var array $stackConfig
             */
            $stackConfig = $container->hasParameter($parameterName) ? $container->getParameter($parameterName) : [];

            foreach ($stack as $key => $interface) {
                $key = sprintf('%s.%s', $applicationName, $key);

                if (array_key_exists($key, $stackConfig)) {
                    throw new \RuntimeException(sprintf('Stack Key %s found twice', $key));
                }

                $stackConfig[$key] = $interface;
            }

            $container->setParameter($parameterName, $stackConfig);
        }
    }

    protected function registerOpenDxpResources($applicationName, $bundleResources, ContainerBuilder $container): void
    {
        parent::registerOpenDxpResources($applicationName, $bundleResources, $container);

        if (array_key_exists('install', $bundleResources)) {
            foreach ($bundleResources['install'] as $type => $value) {
                $applicationParameter = sprintf('%s.opendxp.admin.install.%s', $applicationName, $type);
                //$aliasParameter = sprintf('%s.opendxp.admin.install.%s', $this->getAlias(), $type);
                $globalParameter = sprintf('coreshop.all.opendxp.admin.install.%s', $type);

                foreach ([$applicationParameter, $globalParameter] as $containerParameter) {
                    $resources = [];

                    if ($container->hasParameter($containerParameter)) {
                        $resources = $container->getParameter($containerParameter);
                    }

                    $container->setParameter($containerParameter, array_merge($resources, array_values($value)));
                }
            }
        }

        if (array_key_exists('permissions', $bundleResources)) {
            $applicationPermissions = [];
            $applicationParameter = sprintf('%s.permissions', $applicationName);
            $resourcePermissions = [];
            $globalParameter = 'coreshop.all.permissions';
            $globalPermissions = [];

            if ($container->hasParameter($applicationParameter)) {
                /**
                 * @var array $applicationPermissions
                 */
                $applicationPermissions = $container->getParameter($applicationParameter);
            }

            if ($container->hasParameter($globalParameter)) {
                /**
                 * @var array $globalPermissions
                 */
                $globalPermissions = $container->getParameter($globalParameter);
            }

            $permissions = [];

            foreach ($bundleResources['permissions'] as $permission) {
                $identifier = sprintf('%s_permission_%s', $applicationName, $permission);

                $permissions[] = $identifier;
                $resourcePermissions[] = $identifier;
            }

            $globalApplicationPermissions = array_key_exists($applicationName, $globalPermissions) ? $globalPermissions[$applicationName] : [];
            $globalApplicationPermissions = array_merge($globalApplicationPermissions, $resourcePermissions);
            $globalPermissions[$applicationName] = $globalApplicationPermissions;

            $container->setParameter($globalParameter, $globalPermissions);
            $container->setParameter($applicationParameter, array_merge($applicationPermissions, $permissions));
        }
    }

    /**
     * @param string           $applicationName
     * @param array            $bundles
     */
    public function registerDependantBundles($applicationName, $bundles, ContainerBuilder $container): void
    {
        $appParameterName = sprintf('%s.dependant.bundles', $applicationName);
        $globalParameterName = 'coreshop.all.dependant.bundles';

        foreach ([$appParameterName, $globalParameterName] as $parameterName) {
            /**
             * @var array $bundleConfig
             */
            $bundleConfig = $container->hasParameter($parameterName) ? $container->getParameter($parameterName) : [];

            foreach ($bundles as $bundleName) {
                $bundleConfig[] = $bundleName;
            }

            $container->setParameter($parameterName, $bundleConfig);
        }
    }
}
