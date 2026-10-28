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

namespace CoreShop\Bundle\CoreBundle\OpenDxp\Repository;

use CoreShop\Bundle\CustomerBundle\OpenDxp\Repository\CustomerRepository as BaseCustomerRepository;
use CoreShop\Component\Core\Model\CustomerInterface;
use CoreShop\Component\Core\Repository\CustomerRepositoryInterface;
use Webmozart\Assert\Assert;

class CustomerRepository extends BaseCustomerRepository implements CustomerRepositoryInterface
{
    public function findOneByEmailWithoutUser(string $email): ?CustomerInterface
    {
        $list = $this->getList();

        $list->setCondition('email = ? AND user__id IS NULL', [$email]);
        $list->setLimit(1);
        $list->load();

        $users = $list->getObjects();

        if (count($users) > 0) {
            Assert::isInstanceOf($users[0], CustomerInterface::class);

            return $users[0];
        }

        return null;
    }
}
