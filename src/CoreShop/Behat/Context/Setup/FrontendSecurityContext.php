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

namespace CoreShop\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use CoreShop\Bundle\TestBundle\Service\SecurityServiceInterface;
use CoreShop\Bundle\TestBundle\Service\SharedStorageInterface;
use CoreShop\Component\Core\Model\UserInterface;
use CoreShop\Component\Resource\Factory\FactoryInterface;
use CoreShop\Component\User\Repository\UserRepositoryInterface;
use OpenDxp\File;
use OpenDxp\Model\DataObject\Folder;
use Webmozart\Assert\Assert;

final class FrontendSecurityContext implements Context
{
    public function __construct(
        private SharedStorageInterface $sharedStorage,
        private SecurityServiceInterface $securityService,
        private UserRepositoryInterface $userRepository,
        private FactoryInterface $customerFactory,
        private FactoryInterface $userFactory,
    ) {
    }

    /**
     * @Given I am logged in as :email
     */
    public function iAmLoggedInAs($email): void
    {
        $user = $this->userRepository->findByLoginIdentifier($email);
        Assert::notNull($user);

        $this->securityService->logIn($user);
    }

    /**
     * @Given I am a logged in customer
     */
    public function iAmLoggedInCustomer(): void
    {
        $customer = $this->customerFactory->createNew();
        $customer->setKey(File::getValidFilename('coreshop@opendxp.org'));
        $customer->setParent(Folder::getByPath('/'));
        $customer->setPublished(true);
        $customer->setEmail('coreshop@opendxp.org');
        $customer->setFirstname('coreshop');
        $customer->setLastname('opendxp');
//        $customer->setPassword('coreshop');
        $customer->save();

        /**
         * @var UserInterface $user
         */
        $user = $this->userFactory->createNew();
        $user->setPassword('coreshop');
        $user->setLoginIdentifier('coreshop@opendxp.org');
        $user->setKey(File::getValidFilename('coreshop@opendxp.org-user'));
        $user->setParent(Folder::getByPath('/'));
        $user->setPublished(true);
        $user->setCustomer($customer);
        $user->save();

        $customer->setUser($user);
        $customer->save();

        $this->securityService->logIn($user);

        $this->sharedStorage->set('customer', $customer);
        $this->sharedStorage->set('user', $user);
    }
}
