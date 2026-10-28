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

namespace CoreShop\Bundle\ResourceBundle\DependencyInjection\Driver\OpenDxp;

use CoreShop\Bundle\ResourceBundle\Controller\AdminController;
use CoreShop\Bundle\ResourceBundle\Controller\ViewHandlerInterface;
use CoreShop\Bundle\ResourceBundle\CoreShopResourceBundle;
use CoreShop\Bundle\ResourceBundle\DependencyInjection\Driver\AbstractDriver;
use CoreShop\Bundle\ResourceBundle\OpenDxp\ObjectManager;
use CoreShop\Bundle\ResourceBundle\OpenDxp\OpenDxpRepository;
use CoreShop\Component\Resource\Factory\OpenDxpRepositoryFactory;
use CoreShop\Component\Resource\Metadata\MetadataInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DependencyInjection\Reference;

final class OpenDxpDriver extends AbstractDriver
{
    public function getType(): string
    {
        return CoreShopResourceBundle::DRIVER_DOCTRINE_ORM;
    }

    public function load(ContainerBuilder $container, MetadataInterface $metadata): void
    {
        parent::load($container, $metadata);

        if ($metadata->hasClass('opendxp_controller')) {
            if (is_array($metadata->getClass('opendxp_controller'))) {
                foreach ($metadata->getClass('opendxp_controller') as $suffix => $class) {
                    $this->addOpenDxpController($container, $metadata, $class, $suffix);
                }
            } else {
                $this->addDefaultOpenDxpController($container, $metadata);
            }
        }

        if ($metadata->hasParameter('path')) {
            $this->addOpenDxpClass($container, $metadata);
        }

        $this->addRepositoryFactory($container, $metadata);
    }

    protected function setClassesParameters(ContainerBuilder $container, MetadataInterface $metadata): void
    {
        parent::setClassesParameters($container, $metadata);

        if ($metadata->hasParameter('opendxp_class')) {
            $container->setParameter(sprintf('%s.model.%s.opendxp_class_name', $metadata->getApplicationName(), $metadata->getName()), $metadata->getParameter('opendxp_class'));
        }
    }

    protected function addDefaultOpenDxpController(ContainerBuilder $container, MetadataInterface $metadata): void
    {
        $this->addOpenDxpController($container, $metadata, $metadata->getClass('opendxp_controller'));
    }

    protected function addOpenDxpController(ContainerBuilder $container, MetadataInterface $metadata, string $classValue, string $suffix = null): void
    {
        $parents = array_values(class_parents($classValue));

        if (in_array(AdminController::class, $parents, true)) {
            $definition = new ChildDefinition(AdminController::class);
        } else {
            $definition = new Definition();
        }

        $definition
            ->setClass($classValue)
            ->setPublic(true)
            ->setArguments([
                '$metadata' => $this->getMetadataDefinition($metadata),
                '$repository' => new Reference($metadata->getServiceId('repository')),
                '$factory' => new Reference($metadata->getServiceId('factory')),
                '$viewHandler' => new Reference(ViewHandlerInterface::class),
                '$parameterBag' => new Reference(ParameterBagInterface::class),
            ])
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber')
        ;

        $serviceId = $metadata->getServiceId('opendxp_controller');

        if (null !== $suffix && 'default' !== $suffix) {
            $serviceId .= '_' . $suffix;
        }

        $container->setDefinition($serviceId, $definition);
    }

    protected function addOpenDxpClass(ContainerBuilder $container, MetadataInterface $metadata): void
    {
        $folder = $metadata->getParameter('path');

        if (!is_array($folder)) {
            $folders = [$metadata->getName() => $folder];
        } else {
            $folders = $folder;
        }

        $parameterNameForAllAppPaths = sprintf('%s.folders', $metadata->getApplicationName());
        $parameterNameForAllPaths = 'coreshop.resource.folders';

        foreach ($folders as $folderType => $folder) {
            $paramName = sprintf('%s.folder.%s', $metadata->getApplicationName(), $folderType);
            $container->setParameter($paramName, $folder);

            foreach ([$parameterNameForAllPaths, $parameterNameForAllAppPaths] as $parameterName) {
                $allPaths = [];

                if ($container->hasParameter($parameterName)) {
                    /**
                     * @var array $allPaths
                     */
                    $allPaths = $container->getParameter($parameterName);
                }

                $allPaths[$paramName] = $folder;

                $container->setParameter($parameterName, $allPaths);
            }
        }
    }

    protected function addRepository(ContainerBuilder $container, MetadataInterface $metadata): void
    {
        $repositoryClassParameterName = sprintf('%s.repository.%s.class', $metadata->getApplicationName(), $metadata->getName());
        $repositoryClass = OpenDxpRepository::class;

        if ($container->hasParameter($repositoryClassParameterName)) {
            /** @var string $repositoryClass */
            $repositoryClass = $container->getParameter($repositoryClassParameterName);
        }

        if ($metadata->hasClass('repository')) {
            /** @var string $repositoryClass */
            $repositoryClass = $metadata->getClass('repository');
        }

        $definition = new Definition($repositoryClass);
        $definition->setPublic(true);
        $definition->setArguments([
            $this->getMetadataDefinition($metadata),
            new Reference('doctrine.dbal.default_connection'),
        ]);
        $definition->addTag('coreshop.opendxp.repository', ['alias' => $metadata->getAlias()]);

        $container->setDefinition($metadata->getServiceId('repository'), $definition);

        foreach (class_implements($repositoryClass) as $typehintClass) {
            $container->registerAliasForArgument(
                $metadata->getServiceId('repository'),
                $typehintClass,
                $metadata->getHumanizedName() . ' repository',
            );
        }
    }

    protected function addRepositoryFactory(ContainerBuilder $container, MetadataInterface $metadata): void
    {
        $repositoryFactoryClassParameterName = sprintf('%s.repository.factory.%s.class', $metadata->getApplicationName(), $metadata->getName());
        $repositoryFactoryClass = OpenDxpRepositoryFactory::class;
        $repositoryClass = OpenDxpRepository::class;

        if ($container->hasParameter($repositoryFactoryClassParameterName)) {
            /** @var string $repositoryFactoryClass */
            $repositoryFactoryClass = $container->getParameter($repositoryFactoryClassParameterName);
        }

        if ($metadata->hasClass('repository')) {
            /** @var string $repositoryClass */
            $repositoryClass = $metadata->getClass('repository');
        }

        $definition = new Definition($repositoryFactoryClass);
        $definition->setPublic(true);
        $definition->setArguments([
            $repositoryClass,
            $this->getMetadataDefinition($metadata),
            new Reference('doctrine.dbal.default_connection'),
        ]);

        $container->setDefinition($metadata->getServiceId('repository.factory'), $definition);

        foreach (class_implements($repositoryClass) as $typehintClass) {
            $container->registerAliasForArgument(
                $metadata->getServiceId('repository.factory'),
                $typehintClass,
                $metadata->getHumanizedName() . ' repository factory',
            );
        }
    }

    protected function addManager(ContainerBuilder $container, MetadataInterface $metadata): void
    {
        $alias = new Alias(ObjectManager::class);
        $alias->setPublic(true);

        $container->setAlias(
            $metadata->getServiceId('manager'),
            $alias,
        );

        $container->registerAliasForArgument(
            $metadata->getServiceId('manager'),
            ObjectManager::class,
            $metadata->getHumanizedName() . ' manager',
        );
    }
}
