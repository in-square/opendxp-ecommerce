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

namespace CoreShop\Bundle\OrderBundle\OpenDxp\Repository;

use CoreShop\Bundle\ResourceBundle\OpenDxp\OpenDxpRepository;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Order\Repository\CartItemRepositoryInterface;
use Webmozart\Assert\Assert;

class CartItemRepository extends OpenDxpRepository implements CartItemRepositoryInterface
{
    public function findCartItemsByProductId(int $productId): array
    {
        $list = $this->getList();
        $list->setCondition('product__id = ?', [$productId]);
        $list->load();

        $objects = $list->getObjects();
        Assert::allIsInstanceOf($objects, OrderItemInterface::class);

        return $objects;
    }
}
