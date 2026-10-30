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

use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\AdjustmentFieldNames;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\AdjustmentSchemaMigration;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\DefinitionUpdater;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\HistoryFieldsMigrator;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\MigrationBackup;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\MigrationExecutionListener;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\SerializedAdjustmentFieldsConverter;
use CoreShop\Bundle\CoreBundle\Migrations\Version20261030120000;
use Doctrine\Common\EventManager;
use Doctrine\Migrations\EventDispatcher;
use Doctrine\Migrations\Events;
use Doctrine\Migrations\InlineParameterFormatter;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Provider\DBALSchemaDiffProvider;
use Doctrine\Migrations\Provider\SchemaDiffProvider;
use Doctrine\Migrations\Version\DbalExecutor;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\ClassDefinition\Layout;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Service;
use OpenDxp\Model\Version\Adapter\VersionStorageAdapterInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Stopwatch\Stopwatch;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
define('OPENDXP_PROJECT_ROOT', getcwd());
define('OPENDXP_KERNEL_CLASS', 'Kernel');
$kernel = OpenDxp\Bootstrap::startupCli();
$connection = OpenDxp\Db::get();
if ('coreshop_test' !== $connection->getDatabase() || '1' !== getenv('CORESHOP_ADJUSTMENT_EXECUTION_TESTS')) {
    throw new RuntimeException('This fixture can run only in the explicitly enabled disposable coreshop_test database.');
}
$resources = [
    'order' => ['source' => 'CoreShopOrder', 'name' => 'AdjustmentExecutionOrder', 'id' => 'aeo'],
    'order_item' => ['source' => 'CoreShopOrderItem', 'name' => 'AdjustmentExecutionItem', 'id' => 'aei'],
    'order_invoice' => ['source' => 'CoreShopOrderInvoice', 'name' => 'AdjustmentExecutionInvoice', 'id' => 'aev'],
];
$quote = $connection->quoteIdentifier(...);
$action = $argv[1] ?? '';
$output = [];

if ('cleanup' === $action) {
    $storage = $kernel->getContainer()->get(VersionStorageAdapterInterface::class);
    $orphanVersions = $connection->fetchAllAssociative('SELECT v.id FROM versions v LEFT JOIN objects o ON o.id=v.cid WHERE v.ctype = ? AND o.id IS NULL ORDER BY v.id LIMIT 100', ['object']);
    foreach ($orphanVersions as $row) {
        $version = OpenDxp\Model\Version::getById((int) $row['id']);
        $payload = $storage->loadMetaData($version);
        foreach ($resources as $resource) {
            $class = 'OpenDxp\\Model\\DataObject\\' . $resource['name'];
            if (is_string($payload) && str_starts_with($payload, 'O:' . strlen($class) . ':"' . $class . '":')) {
                $version->delete();
            }
        }
    }
    foreach ($resources as $resource) {
        $ids = $connection->fetchFirstColumn('SELECT id FROM objects WHERE classId = ? ORDER BY id LIMIT 100', [$resource['id']]);
        if (count($ids) >= 100) {
            throw new RuntimeException('Unexpected fixture object count.');
        }
        foreach ($ids as $id) {
            $versionIds = $connection->fetchFirstColumn('SELECT id FROM versions WHERE cid = ? AND ctype = ? ORDER BY id LIMIT 100', [$id, 'object']);
            if (count($versionIds) >= 100) {
                throw new RuntimeException('Unexpected fixture version count.');
            }
            foreach ($versionIds as $versionId) {
                OpenDxp\Model\Version::getById((int) $versionId)->delete();
            }
            $connection->delete('objects', ['id' => $id]);
        }
        $definition = ClassDefinition::getByName($resource['name']);
        if (null !== $definition) {
            $definition->delete();
        }
    }
} elseif ('setup' === $action) {
    foreach ($resources as $resource) {
        $source = ClassDefinition::getByName($resource['source']);
        if (null === $source) {
            throw new RuntimeException('Install CoreShop in the disposable application before this test.');
        }
        $definition = Service::cloneDefinition($source);
        $definition->setId($resource['id']);
        $definition->setName($resource['name']);
        $definition->setLayoutDefinitions($definition->getLayoutDefinitions());
        $definition->setTitle('Custom migrated ' . $resource['name']);
        $definition->setAllowInherit(true);
        $fields = $definition->getFieldDefinitions();
        foreach (AdjustmentFieldNames::MAP as $old => $new) {
            $fields[$new]->setName($old);
        }
        $layout = $definition->getLayoutDefinitions();
        $custom = (new Input())->setName('migrationCustomNote')->setTitle('Keep this custom field')->setColumnLength(190);
        $children = $layout->getChildren();
        $children[] = (new Layout\Panel())->setName('migrationCustomPanel')->setChildren([$custom]);
        $layout->setChildren($children);
        $definition->setLayoutDefinitions($layout);
        $definition->setDeletedDataComponents([]);
        $definition->save();
        foreach ([null, 0, -12345, 9000000000000000000] as $index => $value) {
            $connection->insert('objects', [
                'parentId' => 1, 'type' => 'object', '`key`' => 'adjustment-' . $resource['id'] . '-' . $index,
                'path' => '/', 'published' => 1, 'creationDate' => 1700000000, 'modificationDate' => 1700000000,
                'classId' => $resource['id'], 'className' => $resource['name'],
            ]);
            $id = (int) $connection->lastInsertId();
            $row = ['oo_id' => $id, 'migrationCustomNote' => 'Keep Łódź ' . $index];
            foreach (AdjustmentFieldNames::MAP as $old => $new) {
                $row[$old] = $value;
            }
            $connection->insert('object_store_' . $resource['id'], $row);
            $connection->insert('object_query_' . $resource['id'], $row + ['oo_classId' => $resource['id'], 'oo_className' => $resource['name']]);
            if (2 === $index) {
                $object = Concrete::getById($id, ['force' => true]);
                $version = (new OpenDxp\Model\Version())->setCid($id)->setCtype('object')->setData($object)
                    ->setNote('adjustment execution historical fixture')->setDate(1700000000)
                ;
                $version->save();
            }
        }
    }
} elseif ('snapshot' === $action) {
    foreach ($resources as $resource) {
        $definition = ClassDefinition::getByName($resource['name']);
        foreach ([$definition->getDefinitionFile(), $definition->getPhpClassFile(), $definition->getPhpListingClassFile()] as $file) {
            $output['files'][$file] = hash_file('sha256', $file);
        }
        foreach (['object_store_', 'object_query_'] as $prefix) {
            $table = $prefix . $resource['id'];
            $output['rows'][$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $quote($table) . ' ORDER BY oo_id LIMIT 100');
            $output['tables'][$table] = $connection->fetchAssociative('SHOW CREATE TABLE ' . $quote($table));
        }
        $output['views'][$resource['id']] = $connection->fetchAssociative('SHOW CREATE VIEW ' . $quote('object_' . $resource['id']));
        $output['metadata'][$resource['id']] = $connection->fetchAssociative('SELECT * FROM classes WHERE id = ?', [$resource['id']]);
        $output['versions'][$resource['id']] = $connection->fetchAllAssociative('SELECT v.* FROM versions v INNER JOIN objects o ON o.id=v.cid WHERE o.classId = ? ORDER BY v.id LIMIT 100', [$resource['id']]);
    }
} elseif ('conflict' === $action) {
    $connection->executeStatement('ALTER TABLE object_query_aev ADD opendxpAdjustmentTotalGross BIGINT NULL');
} elseif ('verify' === $action) {
    $output = ['models' => 0, 'getterChecks' => 0, 'setterChecks' => 0, 'persistedSetterChecks' => 0, 'historyGetterChecks' => 0, 'calculationChecks' => 0];
    foreach ($resources as $resource) {
        $definition = ClassDefinition::getByName($resource['name']);
        if (!$definition->getAllowInherit() || 'Custom migrated ' . $resource['name'] !== $definition->getTitle() ||
            null === $definition->getFieldDefinition('migrationCustomNote')) {
            throw new RuntimeException('Custom definition settings were lost.');
        }
        $row = $connection->fetchAssociative('SELECT * FROM ' . $quote('object_store_' . $resource['id']) . ' WHERE migrationCustomNote = ?', ['Keep Łódź 2']);
        $object = Concrete::getById((int) $row['oo_id'], ['force' => true]);
        if (null === $object) {
            throw new RuntimeException('Cannot read migrated generated object.');
        }
        $versionId = $connection->fetchOne('SELECT id FROM versions WHERE cid = ? AND note = ? ORDER BY id LIMIT 1', [$object->getId(), 'adjustment execution historical fixture']);
        $historical = OpenDxp\Model\Version::getById((int) $versionId)->loadData(false);
        foreach (AdjustmentFieldNames::MAP as $old => $new) {
            $getter = 'get' . ucfirst($new);
            if (-12345 !== $historical->$getter()) {
                throw new RuntimeException('Historical getter value mismatch: ' . $getter);
            }
            ++$output['historyGetterChecks'];
        }
        foreach (AdjustmentFieldNames::MAP as $old => $new) {
            $getter = 'get' . ucfirst($new);
            $setter = 'set' . ucfirst($new);
            if (-12345 !== $object->$getter()) {
                throw new RuntimeException('Getter value mismatch: ' . $getter);
            }
            ++$output['getterChecks'];
            $object->$setter(-54321);
            if (-54321 !== $object->$getter()) {
                throw new RuntimeException('Setter mismatch: ' . $setter);
            }
            ++$output['setterChecks'];
        }
        $object->save();
        $after = $connection->fetchAssociative('SELECT * FROM ' . $quote('object_store_' . $resource['id']) . ' WHERE oo_id = ?', [$object->getId()]);
        foreach (AdjustmentFieldNames::MAP as $new) {
            if (-54321 !== (int) $after[$new]) {
                throw new RuntimeException('Persisted setter mismatch: ' . $new);
            }
            ++$output['persistedSetterChecks'];
        }
        $adjustments = [];
        $convertedAdjustments = [];
        foreach ([[-123, -100, false], [20, 10, false], [500, 400, true]] as [$gross, $net, $neutral]) {
            $adjustment = new OpenDxp\Model\DataObject\Fieldcollection\Data\CoreShopAdjustment();
            $adjustment->setOpendxpAmountGross($gross);
            $adjustment->setOpendxpAmountNet($net);
            $adjustment->setOpendxpNeutral($neutral);
            $adjustment->setTypeIdentifier('migration-test');
            $adjustments[] = $adjustment;
            $converted = clone $adjustment;
            $converted->setOpendxpAmountGross($gross * 2);
            $converted->setOpendxpAmountNet($net * 2);
            $convertedAdjustments[] = $converted;
        }
        $object->setAdjustmentItems(new OpenDxp\Model\DataObject\Fieldcollection($adjustments));
        $object->setConvertedAdjustmentItems(new OpenDxp\Model\DataObject\Fieldcollection($convertedAdjustments));
        for ($pass = 0; $pass < 2; ++$pass) {
            $object->recalculateAdjustmentsTotal();
            $object->recalculateConvertedAdjustmentsTotal();
            foreach ([[true, -103, -206], [false, -90, -180]] as [$tax, $expected, $convertedExpected]) {
                if ($expected !== $object->getAdjustmentsTotal(null, $tax) ||
                    $expected !== $object->getAdjustmentsTotal('migration-test', $tax) ||
                    $convertedExpected !== $object->getConvertedAdjustmentsTotal(null, $tax) ||
                    $convertedExpected !== $object->getConvertedAdjustmentsTotal('migration-test', $tax)) {
                    throw new RuntimeException('Adjustment calculation mismatch or double counting.');
                }
                ++$output['calculationChecks'];
            }
        }
        ++$output['models'];
    }
} else {
    $container = new ContainerBuilder();
    $container->setParameter('kernel.project_dir', getcwd());
    foreach ($resources as $key => $resource) {
        $container->setParameter('coreshop.model.' . $key . '.opendxp_class_name', $resource['name']);
        $container->setParameter('coreshop.model.' . $key . '.class', 'OpenDxp\\Model\\DataObject\\' . $resource['name']);
    }
    $history = new HistoryFieldsMigrator($connection, $kernel->getContainer()->get(VersionStorageAdapterInterface::class), new SerializedAdjustmentFieldsConverter());
    $migration = new Version20261030120000($connection, new NullLogger(), $container, new DefinitionUpdater($connection), new AdjustmentSchemaMigration($connection), new MigrationBackup(), $history);
    if ('unarmed' === $action || 'down' === $action) {
        try {
            if ('down' === $action) {
                $migration->down($connection->createSchemaManager()->introspectSchema());
            } else {
                $migration->up($connection->createSchemaManager()->introspectSchema());
            }

            throw new RuntimeException('Expected failure did not occur.');
        } catch (Doctrine\Migrations\Exception\MigrationException $exception) {
            $output['exception'] = $exception->getMessage();
        }
    } else {
        $metadata = new class() implements MetadataStorage {
            public function ensureInitialized(): void
            {
            }

            public function getExecutedMigrations(): ExecutedMigrationsList
            {
                return new ExecutedMigrationsList([]);
            }

            public function complete(ExecutionResult $result): void
            {
            }

            public function reset(): void
            {
            }
        };
        $events = new EventManager();
        $events->addEventListener([Events::onMigrationsVersionExecuting], new MigrationExecutionListener());
        $provider = new DBALSchemaDiffProvider($connection->createSchemaManager(), $connection->getDatabasePlatform());
        if ('expect-ddl-error' === $action) {
            $provider = new class($provider) implements SchemaDiffProvider {
                public function __construct(
                    private readonly SchemaDiffProvider $delegate,
                ) {
                }

                public function createFromSchema(): Doctrine\DBAL\Schema\Schema
                {
                    return $this->delegate->createFromSchema();
                }

                public function createToSchema(Doctrine\DBAL\Schema\Schema $fromSchema): Doctrine\DBAL\Schema\Schema
                {
                    return $this->delegate->createToSchema($fromSchema);
                }

                public function getSqlDiffToMigrate(Doctrine\DBAL\Schema\Schema $fromSchema, Doctrine\DBAL\Schema\Schema $toSchema): array
                {
                    return ['ALTER TABLE missing_adjustment_execution_table RENAME COLUMN old_value TO new_value'];
                }
            };
        }
        $executor = new DbalExecutor(
            $metadata,
            new EventDispatcher($connection, $events),
            $connection,
            $provider,
            new NullLogger(),
            new InlineParameterFormatter($connection),
            new Stopwatch(),
        );
        $objectEvents = 0;
        foreach ([OpenDxp\Event\DataObjectEvents::PRE_ADD, OpenDxp\Event\DataObjectEvents::PRE_UPDATE, OpenDxp\Event\DataObjectEvents::POST_ADD, OpenDxp\Event\DataObjectEvents::POST_UPDATE] as $event) {
            $kernel->getContainer()->get('event_dispatcher')->addListener($event, static function () use (&$objectEvents): void { ++$objectEvents; });
        }

        try {
            $result = $executor->execute(new MigrationPlan(new Version(Version20261030120000::class), $migration, Direction::UP), (new MigratorConfiguration())->setDryRun('dry-run' === $action));
            if (in_array($action, ['expect-conflict', 'expect-ddl-error'], true)) {
                throw new RuntimeException('Expected migration failure did not occur.');
            }
            $output = ['sqlCount' => count($executor->getSql()), 'objectEvents' => $objectEvents];
        } catch (Throwable $exception) {
            if (!in_array($action, ['expect-conflict', 'expect-ddl-error'], true)) {
                throw $exception;
            }
            $output['exception'] = $exception->getMessage();
        }
    }
}
echo json_encode($output, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), "\n";
