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

namespace CoreShop\Bundle\ResourceBundle\Attribute;

use CoreShop\Component\Resource\Factory\OpenDxpFactory;
use CoreShop\Component\Resource\Model\ResourceInterface;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsOpenDxpModel
{
    public function __construct(
        private string $opendxpModel,
        private string $type = 'object',
        private string $interface = ResourceInterface::class,
        private string $factory = OpenDxpFactory::class,
        private ?string $alias = null,
        private ?string $adminController = null,
        private ?string $repository = null,
        private ?string $installFile = null,
        private ?array $opendxpController = [],
        private ?array $options = [],
        private ?string $path = null,
        private ?bool $slug = null,
        private array $route = [],
    ) {
    }

    public function getOpenDxpModel(): string
    {
        return $this->opendxpModel;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getInterface(): string
    {
        return $this->interface;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    public function getAdminController(): ?string
    {
        return $this->adminController;
    }

    public function getFactory(): string
    {
        return $this->factory;
    }

    public function getRepository(): ?string
    {
        return $this->repository;
    }

    public function getInstallFile(): ?string
    {
        return $this->installFile;
    }

    public function getOpenDxpController(): ?array
    {
        return $this->opendxpController;
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getSlug(): ?bool
    {
        return $this->slug;
    }

    public function getRoute(): array
    {
        return $this->route;
    }
}
