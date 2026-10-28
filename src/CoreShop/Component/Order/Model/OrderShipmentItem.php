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

namespace CoreShop\Component\Order\Model;

use CoreShop\Component\Resource\Exception\ImplementedByOpenDxpException;
use CoreShop\Component\Resource\OpenDxp\Model\AbstractOpenDxpModel;

abstract class OrderShipmentItem extends AbstractOpenDxpModel implements OrderShipmentItemInterface
{
    public function getDocument(): OrderDocumentInterface
    {
        $parent = $this->getParent();

        do {
            if ($parent instanceof OrderDocumentInterface) {
                return $parent;
            }

            $parent = $parent->getParent();
        } while ($parent !== null);

        throw new \InvalidArgumentException('Order Shipment could not be found!');
    }

    public function getTotal(bool $withTax = true): int
    {
        return $withTax ? $this->getTotalGross() : $this->getTotalNet();
    }

    public function setTotal(int $total, bool $withTax = true)
    {
        $withTax ? $this->setTotalGross($total) : $this->setTotalNet($total);
    }

    public function getConvertedTotal(bool $withTax = true): int
    {
        return $withTax ? $this->getConvertedTotalGross() : $this->getConvertedTotalNet();
    }

    public function setConvertedTotal(int $convertedTotal, bool $withTax = true)
    {
        $withTax ? $this->setConvertedTotalGross($convertedTotal) : $this->setConvertedTotalNet($convertedTotal);
    }

    public function getTotalNet(): int
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setTotalNet(int $totalNet)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function getTotalGross(): int
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setTotalGross(int $totalGross)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function getConvertedTotalNet(): int
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setConvertedTotalNet(int $convertedTotalNet)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function getConvertedTotalGross(): int
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setConvertedTotalGross(int $convertedTotalGross)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }
}
