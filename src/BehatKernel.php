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

use OpenDxp\Kernel as OpenDxpKernel;

class BehatKernel extends OpenDxpKernel
{
    public function registerBundlesToCollection(\OpenDxp\HttpKernel\BundleCollection\BundleCollection $collection): void
    {
        $collection->addBundle(new \CoreShop\Bundle\CoreBundle\CoreShopCoreBundle(), 1000);
        $collection->addBundle(new \CoreShop\Bundle\FrontendBundle\CoreShopFrontendBundle(), 1800);
        $collection->addBundle(new \FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle());
        $collection->addBundle(new \CoreShop\Bundle\TestBundle\CoreShopTestBundle(), 0);
    }

    protected function getEnvironmentsForDevBundles(): array
    {
        return array_merge(
            ['test_precision'],
            parent::getEnvironmentsForDevBundles(),
        );
    }

    public function boot(): void
    {
        \OpenDxp::setKernel($this);

        parent::boot();
    }

    public function shutdown(): void
    {
        \OpenDxp::setKernel($this);

        parent::shutdown();
    }
    protected function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        parent::build($container);

        $container->setParameter('opendxp.geoip.db_file', $container->getParameter('kernel.project_dir') . '/var/config/GeoLite2-City.mmdb');
    }
}
