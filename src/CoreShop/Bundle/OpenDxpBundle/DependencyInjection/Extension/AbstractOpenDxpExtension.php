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

namespace CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Extension;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

abstract class AbstractOpenDxpExtension extends Extension
{
    protected function registerOpenDxpResources(string $applicationName, array $bundleResources, ContainerBuilder $container): void
    {
        $resourceTypes = ['js', 'css', 'editmode_js', 'editmode_css'];

        foreach ($resourceTypes as $resourceType) {
            $applicationParameter = sprintf('%s.opendxp.admin.%s', $applicationName, $resourceType);
            //$aliasParameter = sprintf('%s.opendxp.admin.%s', $this->getAlias(), $resourceType);
            $globalParameter = sprintf('coreshop.all.opendxp.admin.%s', $resourceType);

            $parameters = [
                $applicationParameter,
                $globalParameter,
            ];

            foreach ($parameters as $containerParameter) {
                $resources = [];
                $bundleTypeResources = [];

                if (array_key_exists($resourceType, $bundleResources)) {
                    $bundleTypeResources = array_values($bundleResources[$resourceType]);
                }

                if ($container->hasParameter($containerParameter)) {
                    $resources = $container->getParameter($containerParameter);
                }

                $container->setParameter($containerParameter, array_merge($resources, $bundleTypeResources));
            }
        }
    }
}
