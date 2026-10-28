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
use CoreShop\Component\Resource\OpenDxp\Model\AbstractOpenDxpFieldcollection;

abstract class Adjustment extends AbstractOpenDxpFieldcollection implements AdjustmentInterface
{
    public function getId(): ?int
    {
        return null;
    }

    public function getAdjustable(): ?AdjustableInterface
    {
        $object = $this->getObject();

        if ($object instanceof AdjustableInterface) {
            return $object;
        }

        return null;
    }

    public function getAmount(bool $withTax = true): int
    {
        return $withTax ? $this->getOpenDxpAmountGross() : $this->getOpenDxpAmountNet();
    }

    public function setAmount(int $grossAmount, int $netAmount)
    {
        $this->setOpenDxpAmountGross($grossAmount);
        $this->setOpenDxpAmountNet($netAmount);

        if (!$this->getNeutral()) {
            $this->recalculateAdjustable();
        }
    }

    public function getNeutral(): bool
    {
        return (bool) $this->getOpenDxpNeutral();
    }

    public function setNeutral(bool $neutral)
    {
        if ($this->getOpenDxpNeutral() !== $neutral) {
            $this->setOpenDxpNeutral($neutral);

            $this->recalculateAdjustable();
        }
    }

    public function isCharge(): bool
    {
        return 0 > $this->getAmount();
    }

    public function isCredit(): bool
    {
        return 0 < $this->getAmount();
    }

    public function getTypeIdentifier(): ?string
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setTypeIdentifier(?string $typeIdentifier)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function getLabel(): ?string
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setLabel(?string $label)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setOpenDxpAmountNet(int $opendxpAmountNet)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function getOpenDxpAmountNet(): int
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setOpenDxpAmountGross(int $opendxpAmountGross)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function getOpenDxpAmountGross(): int
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setOpenDxpNeutral(?bool $opendxpNeutral)
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function getOpenDxpNeutral(): ?bool
    {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    private function recalculateAdjustable(): void
    {
        $adjustable = $this->getAdjustable();
        if (null !== $adjustable) {
            $adjustable->recalculateAdjustmentsTotal();
        }
    }
}
