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

namespace CoreShop\Tests\Integration\Doctrine;

use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\AdjustmentFieldNames;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\AdjustmentSchemaMigration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AdjustmentFieldsSchemaTest extends TestCase
{
    private const IDS = ['adjustment_custom_order', 'adjustment_custom_item', 'adjustment_custom_invoice'];

    private Connection $connection;

    protected function setUp(): void
    {
        $dsn = getenv('CORESHOP_MIGRATION_TEST_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Requires the isolated coreshop_migration_test database.');
        }
        $this->connection = DriverManager::getConnection(['url' => $dsn]);
        self::assertSame('coreshop_migration_test', $this->connection->getDatabase());
        $this->cleanup();
        foreach (self::IDS as $id) {
            foreach (['object_store_', 'object_query_'] as $prefix) {
                $fields = [];
                foreach (AdjustmentFieldNames::MAP as $old => $new) {
                    $fields[] = $this->quote($old) . " BIGINT NULL DEFAULT NULL COMMENT 'preserve money'";
                }
                $table = $prefix . $id;
                $this->connection->executeStatement('CREATE TABLE ' . $this->quote($table)
                    . ' (oo_id INT NOT NULL PRIMARY KEY, customNote VARCHAR(90) NOT NULL DEFAULT \'custom\', '
                    . implode(', ', $fields) . ', INDEX custom_money_index (pimcoreAdjustmentTotalNet))');
                foreach ([null, 0, -987654321, 9000000000000000000] as $index => $amount) {
                    $row = ['oo_id' => $index + 1, 'customNote' => $table . ' — Łódź'];
                    foreach (array_keys(AdjustmentFieldNames::MAP) as $fieldIndex => $old) {
                        $row[$old] = null === $amount ? null : $amount + $fieldIndex + ('object_query_' === $prefix ? 11 : 0);
                    }
                    $this->connection->insert($table, $row);
                }
            }
        }
        $this->connection->executeStatement('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW object_adjustment_custom_order AS '
            . 'SELECT oo_id, pimcoreAdjustmentTotalNet, pimcoreAdjustmentTotalGross, convertedPimcoreAdjustmentTotalNet, convertedPimcoreAdjustmentTotalGross FROM object_query_adjustment_custom_order');
        $this->connection->executeStatement('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW z_adjustment_parent AS '
            . 'SELECT oo_id, pimcoreAdjustmentTotalNet AS pimcoreAdjustmentTotalNet, \'pimcoreAdjustmentTotalNet\' AS literal_name FROM object_query_adjustment_custom_order');
        $this->connection->executeStatement('CREATE SQL SECURITY INVOKER VIEW a_adjustment_consumer AS '
            . 'SELECT q.oo_id, q.pimcoreAdjustmentTotalNet AS public_total, p.pimcoreAdjustmentTotalNet AS parent_total, p.literal_name '
            . 'FROM object_query_adjustment_custom_order AS q JOIN z_adjustment_parent AS p ON p.oo_id=q.oo_id');
        $this->connection->executeStatement('CREATE VIEW b_adjustment_indirect AS '
            . 'SELECT oo_id, pimcoreAdjustmentTotalNet AS legacy_public_alias FROM object_adjustment_custom_order');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->cleanup();
            $this->connection->close();
        }
    }

    public function testPreservesAllValuesPropertiesIndexesAndDependentViews(): void
    {
        $rows = $this->rows();
        $views = [];
        foreach (['z_adjustment_parent', 'a_adjustment_consumer', 'b_adjustment_indirect'] as $name) {
            $views[$name] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $this->quote($name) . ' ORDER BY oo_id');
        }
        $beforeView = $this->connection->fetchAssociative('SHOW CREATE VIEW z_adjustment_parent');
        $migration = new AdjustmentSchemaMigration($this->connection);
        $plan = $migration->plan(self::IDS);
        self::assertTrue($plan['changed']);
        self::assertSame($rows, $this->rows(), 'Planning must not write data or columns.');
        foreach ($plan['sql'] as $sql) {
            $this->connection->executeStatement($sql);
        }
        $migration->verify($plan);
        foreach ($rows as $table => $before) {
            $after = $this->connection->fetchAllAssociative('SELECT * FROM ' . $this->quote($table) . ' ORDER BY oo_id');
            foreach ($before as &$row) {
                foreach (AdjustmentFieldNames::MAP as $old => $new) {
                    $row[$new] = $row[$old];
                    unset($row[$old]);
                }
            }
            unset($row);
            self::assertEquals($before, $after, $table);
            $indexes = $this->connection->createSchemaManager()->listTableIndexes($table);
            self::assertSame(['opendxpAdjustmentTotalNet'], $indexes['custom_money_index']->getColumns());
        }
        foreach ($views as $name => $before) {
            self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM ' . $this->quote($name) . ' ORDER BY oo_id'));
        }
        $afterView = $this->connection->fetchAssociative('SHOW CREATE VIEW z_adjustment_parent');
        self::assertStringContainsString('ALGORITHM=MERGE', $afterView['Create View']);
        self::assertStringContainsString('SQL SECURITY INVOKER', $afterView['Create View']);
        preg_match('/DEFINER=([^ ]+)/', $beforeView['Create View'], $beforeDefiner);
        preg_match('/DEFINER=([^ ]+)/', $afterView['Create View'], $afterDefiner);
        self::assertSame($beforeDefiner, $afterDefiner);
        self::assertSame($beforeView['character_set_client'], $afterView['character_set_client']);
        self::assertSame($beforeView['collation_connection'], $afterView['collation_connection']);
        self::assertArrayHasKey('opendxpAdjustmentTotalNet', $this->connection->fetchAssociative('SELECT * FROM object_adjustment_custom_order LIMIT 1'));
        $again = $migration->plan(self::IDS);
        self::assertFalse($again['changed']);
        self::assertSame([], $again['sql']);
    }

    public function testConflictInLastTableStopsAllChanges(): void
    {
        $this->connection->executeStatement('ALTER TABLE object_query_adjustment_custom_invoice ADD opendxpAdjustmentTotalGross BIGINT NULL');
        $before = $this->rows();

        try {
            (new AdjustmentSchemaMigration($this->connection))->plan(self::IDS);
            self::fail('A conflicting column must abort planning.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Both pimcoreAdjustmentTotalGross and opendxpAdjustmentTotalGross', $exception->getMessage());
        }
        self::assertSame($before, $this->rows());
        self::assertArrayHasKey('pimcoreadjustmenttotalnet', $this->connection->createSchemaManager()->listTableColumns('object_store_adjustment_custom_order'));
    }

    public function testResumesAfterOnePhysicalRename(): void
    {
        $this->connection->executeStatement('ALTER TABLE object_store_adjustment_custom_order RENAME COLUMN pimcoreAdjustmentTotalNet TO opendxpAdjustmentTotalNet');
        $migration = new AdjustmentSchemaMigration($this->connection);
        $plan = $migration->plan(self::IDS);
        foreach ($plan['sql'] as $sql) {
            $this->connection->executeStatement($sql);
        }
        $migration->verify($plan);
        self::assertFalse($migration->plan(self::IDS)['changed']);
    }

    public function testNewColumnsAndStaleViewsAreRepaired(): void
    {
        foreach (self::IDS as $id) {
            foreach (['object_store_', 'object_query_'] as $prefix) {
                foreach (AdjustmentFieldNames::MAP as $old => $new) {
                    $this->connection->executeStatement('ALTER TABLE ' . $this->quote($prefix . $id) . ' RENAME COLUMN ' . $this->quote($old) . ' TO ' . $this->quote($new));
                }
            }
        }
        $migration = new AdjustmentSchemaMigration($this->connection);
        $plan = $migration->plan(self::IDS);
        self::assertTrue($plan['changed']);
        foreach ($plan['sql'] as $sql) {
            self::assertStringStartsNotWith('ALTER TABLE ', $sql);
            $this->connection->executeStatement($sql);
        }
        $migration->verify($plan);
        self::assertFalse($migration->plan(self::IDS)['changed']);
    }

    public function testViewCreationContextSurvivesDifferentMigrationConnection(): void
    {
        $session = $this->connection->fetchAssociative('SELECT @@session.character_set_client AS charset, @@session.collation_connection AS collation');
        $this->connection->executeStatement("SET SESSION character_set_client = 'latin1'");
        $this->connection->executeStatement("SET SESSION collation_connection = 'latin1_swedish_ci'");
        $this->connection->executeStatement("CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW z_adjustment_parent AS SELECT oo_id, pimcoreAdjustmentTotalNet AS pimcoreAdjustmentTotalNet, 'caf" . chr(233) . "' AS literal_name FROM object_query_adjustment_custom_order");
        $this->connection->executeStatement('SET SESSION character_set_client = ' . $this->connection->quote($session['charset']));
        $this->connection->executeStatement('SET SESSION collation_connection = ' . $this->connection->quote($session['collation']));
        $before = $this->connection->fetchOne('SELECT literal_name FROM z_adjustment_parent LIMIT 1');
        $migration = new AdjustmentSchemaMigration($this->connection);
        $plan = $migration->plan(self::IDS);
        foreach ($plan['sql'] as $sql) {
            $this->connection->executeStatement($sql);
        }
        $migration->verify($plan);
        $view = $this->connection->fetchAssociative('SHOW CREATE VIEW z_adjustment_parent');
        self::assertSame('latin1', $view['character_set_client']);
        self::assertSame('latin1_swedish_ci', $view['collation_connection']);
        self::assertSame($before, $this->connection->fetchOne('SELECT literal_name FROM z_adjustment_parent LIMIT 1'));
        self::assertSame($session, $this->connection->fetchAssociative('SELECT @@session.character_set_client AS charset, @@session.collation_connection AS collation'));
    }

    public function testUnrelatedViewWithRepeatedAliasesDoesNotBlockMigration(): void
    {
        $this->connection->executeStatement('CREATE VIEW unrelated_adjustment_aliases AS '
            . 'SELECT x.oo_id FROM object_store_adjustment_custom_order x WHERE EXISTS (SELECT 1 FROM object_store_adjustment_custom_item x WHERE x.oo_id > 0)');

        try {
            $migration = new AdjustmentSchemaMigration($this->connection);
            $plan = $migration->plan(['adjustment_custom_invoice']);
            self::assertArrayNotHasKey('unrelated_adjustment_aliases', $plan['views']);
            foreach ($plan['sql'] as $sql) {
                $this->connection->executeStatement($sql);
            }
            self::assertSame([], $migration->plan(['adjustment_custom_invoice'])['sql']);
        } finally {
            $this->connection->executeStatement('DROP VIEW unrelated_adjustment_aliases');
        }
    }

    public function testVerificationDetectsUnexpectedValueChange(): void
    {
        $migration = new AdjustmentSchemaMigration($this->connection);
        $plan = $migration->plan(self::IDS);
        foreach ($plan['sql'] as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->connection->executeStatement('UPDATE object_store_adjustment_custom_order SET opendxpAdjustmentTotalNet = 42 WHERE oo_id = 1');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('values changed unexpectedly');
        $migration->verify($plan);
    }

    public function testColumnSpecificGrantsStopBeforeAnySchemaChange(): void
    {
        $dsn = getenv('CORESHOP_GRANT_TEST_DSN');
        if (!$dsn) {
            self::markTestSkipped('Requires the dedicated disposable grant-test DBA connection.');
        }
        $admin = DriverManager::getConnection(['url' => $dsn]);
        self::assertSame('coreshop_migration_test', $admin->getDatabase());
        $user = 'adjustment_grant_' . bin2hex(random_bytes(6));
        $account = $admin->quote($user) . "@'%'";
        $admin->executeStatement('CREATE USER ' . $account);

        try {
            $admin->executeStatement('GRANT SELECT (pimcoreAdjustmentTotalNet) ON coreshop_migration_test.object_query_adjustment_custom_order TO ' . $account);
            $before = $this->rows();

            try {
                (new AdjustmentSchemaMigration($this->connection))->plan(self::IDS);
                self::fail('Column grants must stop migration before changes.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Column-specific grants reference legacy adjustment fields', $exception->getMessage());
            }
            self::assertSame($before, $this->rows());
        } finally {
            $admin->executeStatement('DROP USER ' . $account);
            $admin->close();
        }
    }

    /** @return array<string, array> */
    private function rows(): array
    {
        $rows = [];
        foreach (self::IDS as $id) {
            foreach (['object_store_', 'object_query_'] as $prefix) {
                $table = $prefix . $id;
                $rows[$table] = $this->connection->fetchAllAssociative('SELECT * FROM ' . $this->quote($table) . ' ORDER BY oo_id');
            }
        }

        return $rows;
    }

    private function cleanup(): void
    {
        foreach (['a_adjustment_consumer', 'b_adjustment_indirect', 'z_adjustment_parent', 'object_adjustment_custom_order'] as $name) {
            $this->connection->executeStatement('DROP VIEW IF EXISTS ' . $this->quote($name));
        }
        foreach (self::IDS as $id) {
            foreach (['object_store_', 'object_query_'] as $prefix) {
                $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->quote($prefix . $id));
            }
        }
    }

    private function quote(string $name): string
    {
        return $this->connection->quoteIdentifier($name);
    }
}
