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
use CoreShop\Component\Order\Model\OrderDocumentInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Repository\OrderDocumentRepositoryInterface;
use Webmozart\Assert\Assert;

abstract class AbstractOrderDocumentRepository extends OpenDxpRepository implements OrderDocumentRepositoryInterface
{
    public function getDocuments(OrderInterface $order): array
    {
        return $this->findBy(['order__id' => $order->getId()], ['id' => 'DESC']);
    }

    public function getDocumentsInState(OrderInterface $order, string $state): array
    {
        $list = $this->getList();
        $list->setCondition('order__id = ? AND state = ?', [$order->getId(), $state]);

        $objects = $list->getObjects();
        Assert::allIsInstanceOf($objects, OrderDocumentInterface::class);

        return $objects;
    }

    public function getDocumentsNotInState(OrderInterface $order, string $state): array
    {
        $list = $this->getList();
        $list->setCondition('order__id = ? AND state <> ?', [$order->getId(), $state]);

        $objects = $list->getObjects();
        Assert::allIsInstanceOf($objects, OrderDocumentInterface::class);

        return $objects;
    }
}
