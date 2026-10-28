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

namespace CoreShop\Bundle\ResourceBundle;

use Composer\InstalledVersions;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Compiler\DoctrineTargetEntitiesResolverPass;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Compiler\RegisterInstallersPass;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Compiler\RegisterOpenDxpRepositoriesPass;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Compiler\RegisterOpenDxpResourcesPass;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Compiler\RegisterResourcesPass;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Compiler\StackClassesPass;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Compiler\StackRepositoryPass;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Compiler\ValidatorAutoMappingFixPass;
use JMS\SerializerBundle\JMSSerializerBundle;
use OpenDxp\Bundle\AdminBundle\OpenDxpAdminBundle;
use OpenDxp\Bundle\ApplicationLoggerBundle\OpenDxpApplicationLoggerBundle;
use OpenDxp\Bundle\SimpleBackendSearchBundle\OpenDxpSimpleBackendSearchBundle;
use OpenDxp\Bundle\StaticRoutesBundle\OpenDxpStaticRoutesBundle;
use OpenDxp\Extension\Bundle\AbstractOpenDxpBundle;
use OpenDxp\HttpKernel\Bundle\DependentBundleInterface;
use OpenDxp\HttpKernel\BundleCollection\BundleCollection;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CoreShopResourceBundle extends AbstractOpenDxpBundle implements DependentBundleInterface
{
    public const DRIVER_DOCTRINE_ORM = 'doctrine/orm';

    public const DRIVER_OPENDXP = 'opendxp';

    public const OPENDXP_MODEL_TYPE_OBJECT = 'object';

    public const OPENDXP_MODEL_TYPE_FIELD_COLLECTION = 'fieldcollection';

    public const OPENDXP_MODEL_TYPE_BRICK = 'brick';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterResourcesPass());
        $container->addCompilerPass(new RegisterOpenDxpResourcesPass());
        $container->addCompilerPass(new DoctrineTargetEntitiesResolverPass());
        $container->addCompilerPass(new RegisterInstallersPass());
        $container->addCompilerPass(new StackClassesPass());
        $container->addCompilerPass(new StackRepositoryPass());
        $container->addCompilerPass(new RegisterOpenDxpRepositoriesPass());
        $container->addCompilerPass(new ValidatorAutoMappingFixPass());
    }

    public static function registerDependentBundles(BundleCollection $collection): void
    {
        $collection->addBundle(new JMSSerializerBundle(), 3900);
        $collection->addBundle(new \CoreShop\Bundle\OpenDxpBundle\CoreShopOpenDxpBundle(), 3850);
        $collection->addBundle(new \CoreShop\Bundle\OptimisticEntityLockBundle\CoreShopOptimisticEntityLockBundle(), 3800);
        $collection->addBundle(new \CoreShop\Bundle\LocaleBundle\CoreShopLocaleBundle(), 3850);
        $collection->addBundle(new \Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle(), 1200);
        $collection->addBundle(new OpenDxpAdminBundle(), 10);
        $collection->addBundle(new OpenDxpApplicationLoggerBundle(), 10);
        $collection->addBundle(new OpenDxpStaticRoutesBundle(), 10);
        $collection->addBundle(new OpenDxpSimpleBackendSearchBundle(), 10);
    }

    public function getNiceName(): string
    {
        return 'CoreShop - Resource';
    }

    public function getDescription(): string
    {
        return 'CoreShop - Resource Bundle';
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
        $bundleName = 'coreshop/resource-bundle';

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

    public static function getAvailableDrivers(): array
    {
        return [
            self::DRIVER_DOCTRINE_ORM,
        ];
    }
}
