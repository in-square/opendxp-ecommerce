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

namespace CoreShop\Component\StorageList\Manager;

use CoreShop\Component\OpenDxp\DataObject\VersionHelper;
use CoreShop\Component\Resource\OpenDxp\Model\AbstractOpenDxpModel;
use CoreShop\Component\Resource\Service\FolderCreationServiceInterface;
use CoreShop\Component\StorageList\Model\StorageListInterface;
use CoreShop\Component\StorageList\StorageListManagerInterface;

final class StorageListOpenDxpModelManager implements StorageListManagerInterface
{
    public function __construct(
        private FolderCreationServiceInterface $folderCreationService,
    ) {
    }

    public function persist(StorageListInterface $storageList): void
    {
        if (!$storageList instanceof AbstractOpenDxpModel) {
            throw new \Exception('StorageList implementation needs to be a OpenDxp Model');
        }

        $folder = $this->folderCreationService->createFolderForResource($storageList, [
            'suffix' => date('Y/m/d'),
            'path' => 'storage-list',
        ]);

        VersionHelper::useVersioning(function () use ($storageList, $folder) {
            $tempItems = $storageList->getItems();

            if (!$storageList->getId()) {
                $storageList->setItems([]);

                /**
                 * @psalm-suppress DocblockTypeContradiction
                 */
                if (!$storageList->getParent()) {
                    $storageList->setParent($folder);
                }

                $storageList->save();
            }

            /**
             * @var AbstractOpenDxpModel $item
             */
            foreach ($tempItems as $index => $item) {
                $item->setParent(
                    $this->folderCreationService->createFolderForResource(
                        $item,
                        ['prefix' => $storageList->getFullPath()],
                    ),
                );
                $item->setPublished(true);
                $item->setKey(uniqid((string) ((int) $index + 1), true));
                $item->save();
            }

            $storageList->setItems($tempItems);

            /**
             * @var AbstractOpenDxpModel $storageListItem
             */
            foreach ($storageList->getItems() as $storageListItem) {
                $storageListItem->save();
            }

            $storageList->save();
        }, false);
    }
}
