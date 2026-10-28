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

namespace CoreShop\Bundle\WishlistBundle\OpenDxp\Repository;

use CoreShop\Bundle\ResourceBundle\OpenDxp\OpenDxpRepository;
use CoreShop\Component\Wishlist\Model\WishlistItemInterface;
use CoreShop\Component\Wishlist\Repository\WishlistItemRepositoryInterface;
use Webmozart\Assert\Assert;

class WishlistItemRepository extends OpenDxpRepository implements WishlistItemRepositoryInterface
{
    public function findWishlistItemsByProductId(int $productId): array
    {
        $list = $this->getList();
        $list->setCondition('product__id = ?', [$productId]);
        $list->load();

        $objects = $list->getObjects();
        Assert::allIsInstanceOf($objects, WishlistItemInterface::class);

        return $objects;
    }
}
