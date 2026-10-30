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

namespace CoreShop\Bundle\CoreBundle\Migrations;

use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\AdjustmentSchemaMigration;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\DefinitionUpdater;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\HistoryFieldsMigrator;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\MigrationBackup;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use OpenDxp\Tool;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class Version20261030120000 extends AbstractContainerAwareMigration
{
    private ?bool $dryRun = null;

    private array $plan = [];

    private array $definitions = [];

    private ?string $backupDirectory = null;

    private ?string $lock = null;

    private array $modelClasses = [];

    private int $historyChanges = 0;

    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        ContainerInterface $container,
        private readonly DefinitionUpdater $definitionUpdater,
        private readonly AdjustmentSchemaMigration $schemaMigration,
        private readonly MigrationBackup $backup,
        private readonly HistoryFieldsMigrator $history,
    ) {
        parent::__construct($connection, $logger, $container);
    }

    public function getDescription(): string
    {
        return 'Preserve existing adjustment totals, views and custom definitions when upgrading configured order models to OpenDXP field names.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function armExecution(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(null === $this->dryRun, 'Adjustment migration execution context is missing. Register MigrationExecutionListener and use the Doctrine runner; do not call up() directly.');

        try {
            if (!$this->dryRun) {
                $this->lock = 'coreshop-adjustment-fields-' . substr(hash('sha256', (string) $this->connection->getDatabase()), 0, 24);
                $this->abortIf(1 !== (int) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [$this->lock]), 'Another adjustment field migration is running.');
            }
            $names = [];
            foreach (['order', 'order_item', 'order_invoice'] as $resource) {
                $parameter = 'coreshop.model.' . $resource . '.opendxp_class_name';
                $name = $this->container->getParameter($parameter);
                $this->abortIf(!is_string($name) || '' === $name, 'Missing configured class definition: ' . $parameter . '.');
                $names[] = $name;
                $model = $this->container->getParameter('coreshop.model.' . $resource . '.class');
                $this->abortIf(!is_string($model) || '' === $model, 'Missing configured PHP model for ' . $resource . '.');
                $this->modelClasses[] = $model;
            }
            $this->definitions = $this->definitionUpdater->definitions($names);
            $this->plan = $this->schemaMigration->plan(array_map('strval', array_keys($this->definitions)), Tool::getValidLanguages());
            $this->historyChanges = $this->history->validate(array_map('strval', array_keys($this->definitions)), array_values(array_unique($this->modelClasses)));
            $changed = $this->plan['changed'] || $this->historyChanges > 0;
            foreach ($this->definitions as $entry) {
                $changed = $changed || $entry['changed'] || [] !== $entry['layouts'];
            }
            if (!$this->dryRun && $changed) {
                $this->backupDirectory = $this->backup->save((string) $this->container->getParameter('kernel.project_dir'), $this->plan, $this->definitions);
                $this->write('Recovery manifest: ' . $this->backupDirectory . '/manifest.json. Keep the shop and workers stopped until verification completes.');
            }
            foreach ($this->plan['sql'] as $sql) {
                $this->addSql($sql);
            }
        } catch (\Throwable $exception) {
            $this->releaseLock();

            throw $exception;
        }
    }

    public function postUp(Schema $schema): void
    {
        if (true === $this->dryRun) {
            return;
        }
        $this->abortIf(false !== $this->dryRun || [] === $this->plan, 'Adjustment migration execution context is missing.');

        try {
            $this->schemaMigration->verify($this->plan);
            $this->definitionUpdater->publish($this->definitions);
            if ($this->historyChanges > 0) {
                $this->abortIf(null === $this->backupDirectory, 'Missing recovery backup for adjustment history.');
                $this->history->migrate(array_map('strval', array_keys($this->definitions)), array_values(array_unique($this->modelClasses)), $this->backupDirectory . '/history');
            }
        } finally {
            $this->releaseLock();
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('DDL, definitions and version files are not one reversible transaction. Stop the shop and restore the coordinated pre-upgrade database, definitions, generated classes and version-storage backup.');
    }

    public function __destruct()
    {
        $this->releaseLock();
    }

    private function releaseLock(): void
    {
        if (null !== $this->lock) {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$this->lock]);
            $this->lock = null;
        }
    }
}
