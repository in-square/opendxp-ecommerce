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

namespace CoreShop\Bundle\CustomerBundle\OpenDxp\Repository;

use CoreShop\Bundle\ResourceBundle\OpenDxp\OpenDxpRepository;
use CoreShop\Component\Customer\Model\CompanyInterface;
use CoreShop\Component\Customer\Repository\CompanyRepositoryInterface;

class CompanyRepository extends OpenDxpRepository implements CompanyRepositoryInterface
{
    public function findCompanyByName(string $name): ?CompanyInterface
    {
        $list = $this->getList();
        $list->setCondition('name = ?', [$name]);
        $objects = $list->load();

        if (count($objects) === 1 && $objects[0] instanceof CompanyInterface) {
            return $objects[0];
        }

        return null;
    }
}
