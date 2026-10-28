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

namespace CoreShop\Component\Product\Model;

use CoreShop\Component\OpenDxp\Slug\KeyableSluggableInterface;
use CoreShop\Component\OpenDxp\Slug\SluggableInterface;
use CoreShop\Component\Resource\OpenDxp\Model\OpenDxpModelInterface;
use OpenDxp\Model\DataObject\Listing;

interface CategoryInterface extends OpenDxpModelInterface, SluggableInterface, KeyableSluggableInterface
{
    public function getId(): ?int;

    public function getName(?string $language = null): ?string;

    public function setName(?string $name, ?string $language = null);

    public function getDescription(?string $language = null): ?string;

    public function setDescription(?string $description, ?string $language = null);

    public function getParentCategory(): ?self;

    public function setParentCategory(?self $parentCategory);

    public function getChildCategories(): Listing;

    public function hasChildCategories(): bool;

    /**
     * @return CategoryInterface[]
     */
    public function getHierarchy(): array;
}
