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

namespace CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields;

use CoreShop\Bundle\CoreBundle\Migrations\Version20261030120000;
use Doctrine\Migrations\Event\MigrationsVersionEventArgs;

final class MigrationExecutionListener
{
    public function onMigrationsVersionExecuting(MigrationsVersionEventArgs $event): void
    {
        $migration = $event->getPlan()->getMigration();
        if ($migration instanceof Version20261030120000) {
            $migration->armExecution($event->getMigratorConfiguration()->isDryRun());
        }
    }
}
