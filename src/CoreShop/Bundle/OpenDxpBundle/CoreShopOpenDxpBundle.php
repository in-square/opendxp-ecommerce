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

namespace CoreShop\Bundle\OpenDxpBundle;

use Composer\InstalledVersions;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\ExpressionLanguageServicePass;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\LinkGeneratorPass;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\RegisterGridActionPass;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\RegisterGridFilterPass;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\RegisterOpenDxpDocumentTagImplementationLoaderPass;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\RegisterOpenDxpDocumentTagPass;
use CoreShop\Bundle\OpenDxpBundle\DependencyInjection\Compiler\RegisterTypeHintRegistriesPass;
use OpenDxp\Extension\Bundle\AbstractOpenDxpBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CoreShopOpenDxpBundle extends AbstractOpenDxpBundle
{
    public function getNiceName(): string
    {
        return 'CoreShop - OpenDxp';
    }

    public function getDescription(): string
    {
        return 'CoreShop - OpenDxp Bundle';
    }

    public function getVersion(): string
    {
        if (class_exists('\\CoreShop\\Bundle\\CoreBundle\\Application\\Version')) {
            return \CoreShop\Bundle\CoreBundle\Application\Version::getVersion() . ' (' . $this->getComposerVersion() . ')';
        }

        return $this->getComposerVersion();
    }

    public function getComposerVersion(): string
    {
        $bundleName = 'coreshop/opendxp-bundle';

        if (class_exists(InstalledVersions::class)) {
            if (InstalledVersions::isInstalled('in-square/opendxp-ecommerce')) {
                return InstalledVersions::getPrettyVersion('in-square/opendxp-ecommerce');
            }

            if (InstalledVersions::isInstalled('coreshop/core-shop')) {
                return InstalledVersions::getPrettyVersion('coreshop/core-shop');
            }

            if (InstalledVersions::isInstalled($bundleName)) {
                return InstalledVersions::getPrettyVersion($bundleName);
            }
        }

        return '';
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterGridActionPass());
        $container->addCompilerPass(new RegisterGridFilterPass());
        $container->addCompilerPass(new RegisterOpenDxpDocumentTagImplementationLoaderPass());
        $container->addCompilerPass(new RegisterOpenDxpDocumentTagPass());
        $container->addCompilerPass(new ExpressionLanguageServicePass());
        $container->addCompilerPass(new RegisterTypeHintRegistriesPass());
        $container->addCompilerPass(new LinkGeneratorPass());
    }
}
