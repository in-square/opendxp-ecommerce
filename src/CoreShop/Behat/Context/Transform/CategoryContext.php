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

namespace CoreShop\Behat\Context\Transform;

use Behat\Behat\Context\Context;
use CoreShop\Bundle\TestBundle\Service\SharedStorageInterface;
use CoreShop\Component\Core\Model\CategoryInterface;
use CoreShop\Component\Core\Repository\CategoryRepositoryInterface;
use Webmozart\Assert\Assert;

final class CategoryContext implements Context
{
    public function __construct(
        private SharedStorageInterface $sharedStorage,
        private CategoryRepositoryInterface $categoryRepository,
    ) {
    }

    /**
     * @Transform /^category(?:|s) "([^"]+)"$/
     */
    public function getCategoryByName($categoryName): CategoryInterface
    {
        /**
         * @var \OpenDxp\Model\DataObject\Listing\Concrete $list
         */
        $list = $this->categoryRepository->getList();
        $list->setLocale('en');
        $list->setCondition('name = ?', [$categoryName]);
        $list->setLimit(1);
        $list->load();

        Assert::eq(
            $list->getTotalCount(),
            1,
            sprintf('%d categories has been found with name "%s".', $list->getTotalCount(), $categoryName),
        );

        $objects = $list->getObjects();

        $category = reset($objects);
        Assert::isInstanceOf($category, CategoryInterface::class);

        return $category;
    }

    /**
     * @Transform /^categories "([^"]+)", "([^"]+)"$/
     */
    public function getCategoriesByName($category1, $category2): array
    {
        $categories = [];

        foreach ([$category1, $category2] as $cat) {
            $categories[] = $this->getCategoryByName($cat);
        }

        return $categories;
    }

    /**
     * @Transform /^category$/
     */
    public function category(): CategoryInterface
    {
        return $this->sharedStorage->get('category');
    }
}
