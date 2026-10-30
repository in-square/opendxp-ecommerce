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

final class AdjustmentLocalizedViewsTest extends TestCase
{
    private const IDS = ['localized_merchant_order', 'localized_merchant_item', 'localized_merchant_invoice'];

    private const LANGUAGES = ['de', 'fr_CA'];

    private const OBJECTS = 'adjustment_localized_fixture_objects';

    private const PROJECT = 'z_merchant_localized_export';

    private const DEPENDENT = 'a_merchant_localized_report';

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
        $this->connection->executeStatement('CREATE TABLE ' . $this->quote(self::OBJECTS) . ' (id INT PRIMARY KEY, merchantStatus VARCHAR(30) NOT NULL)');
        foreach (range(1, 4) as $id) {
            $this->connection->insert(self::OBJECTS, ['id' => $id, 'merchantStatus' => 'retained-' . $id]);
        }
        foreach (self::IDS as $id) {
            foreach (['object_store_', 'object_query_'] as $prefix) {
                $fields = [];
                foreach (array_keys(AdjustmentFieldNames::MAP) as $field) {
                    $fields[] = $this->quote($field) . " BIGINT NULL DEFAULT NULL COMMENT 'merchant money'";
                }
                $this->connection->executeStatement('CREATE TABLE ' . $this->quote($prefix . $id)
                    . ' (oo_id INT PRIMARY KEY, merchantNote VARCHAR(90) NOT NULL DEFAULT \'preserve\', ' . implode(', ', $fields) . ')');
                foreach ([null, 0, -23456, 9000000000000000000] as $index => $amount) {
                    $row = ['oo_id' => $index + 1, 'merchantNote' => 'Łódź ' . $id . ' ' . $index];
                    foreach (array_keys(AdjustmentFieldNames::MAP) as $fieldIndex => $field) {
                        $row[$field] = null === $amount ? null : $amount + $fieldIndex;
                    }
                    $this->connection->insert($prefix . $id, $row);
                }
            }
            foreach (self::LANGUAGES as $language) {
                $table = 'object_localized_query_' . $id . '_' . $language;
                $this->connection->executeStatement('CREATE TABLE ' . $this->quote($table)
                    . ' (ooo_id INT PRIMARY KEY, language VARCHAR(10) NOT NULL, merchantTitle VARCHAR(90) NULL)');
                foreach (range(1, 4) as $objectId) {
                    $title = 'fr_CA' === $language ? 'Fallback Łódź ' . $objectId : match ($objectId) {
                        1 => null,
                        2 => '',
                        default => 'Deutsch ' . $objectId,
                    };
                    $this->connection->insert($table, ['ooo_id' => $objectId, 'language' => $language, 'merchantTitle' => $title]);
                }
            }
            $this->connection->executeStatement('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW ' . $this->quote('object_' . $id)
                . ' AS SELECT * FROM ' . $this->quote('object_query_' . $id));
            foreach (self::LANGUAGES as $language) {
                $this->createLocalizedView($id, $language);
            }
        }
        $fields = [];
        foreach (AdjustmentFieldNames::MAP as $old => $new) {
            $alias = 'pimcoreAdjustmentTotalNet' === $old ? $old : 'merchant_' . $new;
            $fields[] = 'l.' . $this->quote($old) . ' AS ' . $this->quote($alias);
        }
        $this->connection->executeStatement('CREATE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW ' . $this->quote(self::PROJECT)
            . ' AS SELECT l.oo_id, l.merchantTitle, ' . implode(', ', $fields) . ', \'pimcoreAdjustmentTotalNet\' AS literalName FROM '
            . $this->quote($this->localizedName(self::IDS[1], self::LANGUAGES[0])) . ' AS l');
        $this->connection->executeStatement('CREATE SQL SECURITY INVOKER VIEW ' . $this->quote(self::DEPENDENT)
            . ' AS SELECT p.oo_id, p.pimcoreAdjustmentTotalNet AS reportNet, p.merchantTitle, p.literalName FROM ' . $this->quote(self::PROJECT) . ' AS p');
        $this->connection->executeStatement('CREATE VIEW ' . $this->quote($this->localizedName(self::IDS[2], 'merchant_custom'))
            . ' AS SELECT q.oo_id, q.pimcoreAdjustmentTotalNet AS pimcoreAdjustmentTotalNet FROM ' . $this->quote('object_query_' . self::IDS[2]) . ' AS q');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->cleanup();
            $this->connection->close();
        }
    }

    public function testLocalizedNativeColumnsAndProjectAliasesPreserveValuesAndProperties(): void
    {
        $before = [];
        foreach ($this->nativeNames() as $name) {
            $before[$name] = ['rows' => $this->rows($name), 'columns' => $this->columns($name), 'view' => $this->view($name)];
        }
        $projectNames = [self::PROJECT, self::DEPENDENT, $this->localizedName(self::IDS[2], 'merchant_custom')];
        $projects = [];
        foreach ($projectNames as $name) {
            $projects[$name] = $this->rows($name);
        }
        $migration = new AdjustmentSchemaMigration($this->connection);
        $plan = $migration->plan(self::IDS, self::LANGUAGES);
        $this->execute($plan);
        $migration->verify($plan);
        foreach ($before as $name => $snapshot) {
            self::assertEquals($this->renamedRows($snapshot['rows']), $this->rows($name), $name);
            $columns = $snapshot['columns'];
            foreach ($columns as &$column) {
                $column['COLUMN_NAME'] = AdjustmentFieldNames::MAP[$column['COLUMN_NAME']] ?? $column['COLUMN_NAME'];
            }
            unset($column);
            self::assertSame($columns, $this->columns($name), $name . ' column properties changed.');
            $this->assertNativeContractAndQueries($name);
            $after = $this->view($name);
            self::assertSame(explode(' VIEW ', $snapshot['view']['Create View'])[0], explode(' VIEW ', $after['Create View'])[0]);
            self::assertSame($snapshot['view']['character_set_client'], $after['character_set_client']);
            self::assertSame($snapshot['view']['collation_connection'], $after['collation_connection']);
            self::assertSame(2, substr_count($after['Create View'], 'left join'));
            self::assertStringContainsString('if(', $after['Create View']);
        }
        foreach ($projects as $name => $rows) {
            self::assertSame($rows, $this->rows($name), $name . ' lost its deliberate project aliases.');
        }
        self::assertSame('Fallback Łódź 1', $this->connection->fetchOne('SELECT merchantTitle FROM '
            . $this->quote($this->localizedName(self::IDS[0], self::LANGUAGES[0])) . ' WHERE oo_id = 1'));
        self::assertSame('Fallback Łódź 2', $this->connection->fetchOne('SELECT merchantTitle FROM '
            . $this->quote($this->localizedName(self::IDS[0], self::LANGUAGES[0])) . ' WHERE oo_id = 2'));
        $again = $migration->plan(self::IDS, self::LANGUAGES);
        self::assertFalse($again['changed']);
        self::assertSame([], $again['sql']);
        $migration->verify($again);
    }

    public function testRepairsLocalizedAliasesLeftByAnEarlierMigrationRun(): void
    {
        $migration = new AdjustmentSchemaMigration($this->connection);
        $this->execute($migration->plan(self::IDS));
        $name = $this->localizedName(self::IDS[1], self::LANGUAGES[0]);
        self::assertArrayHasKey('pimcoreAdjustmentTotalNet', $this->rows($name)[0]);
        $before = [];
        foreach ($this->nativeNames() as $native) {
            $before[$native] = $this->rows($native);
        }
        $plan = $migration->plan(self::IDS, self::LANGUAGES);
        self::assertTrue($plan['changed']);
        foreach ($plan['sql'] as $sql) {
            self::assertStringStartsNotWith('ALTER TABLE ', $sql);
        }
        $this->execute($plan);
        $migration->verify($plan);
        foreach ($before as $native => $rows) {
            self::assertEquals($this->renamedRows($rows), $this->rows($native));
            $this->assertNativeContractAndQueries($native);
        }
        self::assertSame([], $migration->plan(self::IDS, self::LANGUAGES)['sql']);
    }

    public function testVerificationRejectsLegacyOutputAliasesEvenWhenTheViewCanBeQueried(): void
    {
        $migration = new AdjustmentSchemaMigration($this->connection);
        $plan = $migration->plan(self::IDS, self::LANGUAGES);
        $this->execute($plan);
        $name = $this->localizedName(self::IDS[1], self::LANGUAGES[0]);
        $definition = $this->view($name)['Create View'];
        foreach (AdjustmentFieldNames::MAP as $old => $new) {
            $definition = str_replace(' AS ' . $this->quote($new), ' AS ' . $this->quote($old), $definition);
        }
        $this->connection->executeStatement('CREATE OR REPLACE ' . substr($definition, 7));
        $this->connection->executeQuery('SELECT * FROM ' . $this->quote($name) . ' LIMIT 0')->free();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($name);
        $migration->verify($plan);
    }

    private function createLocalizedView(string $id, string $language): void
    {
        $fields = [];
        foreach (array_keys(AdjustmentFieldNames::MAP) as $old) {
            $fields[] = 'q.' . $this->quote($old) . ' AS ' . $this->quote($old);
        }
        $fallback = self::LANGUAGES[0] === $language ? self::LANGUAGES[1] : self::LANGUAGES[0];
        $this->connection->executeStatement('CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW ' . $this->quote($this->localizedName($id, $language))
            . ' AS SELECT q.oo_id, q.merchantNote, o.merchantStatus, ' . implode(', ', $fields)
            . ', l.language AS language, IF(l.merchantTitle IS NULL OR STRCMP(l.merchantTitle, \'\') = 0, f.merchantTitle, l.merchantTitle) AS merchantTitle '
            . 'FROM ' . $this->quote('object_query_' . $id) . ' AS q JOIN ' . $this->quote(self::OBJECTS) . ' AS o ON o.id = q.oo_id '
            . 'LEFT JOIN ' . $this->quote('object_localized_query_' . $id . '_' . $language) . ' AS l ON l.ooo_id = q.oo_id '
            . 'LEFT JOIN ' . $this->quote('object_localized_query_' . $id . '_' . $fallback) . ' AS f ON f.ooo_id = q.oo_id');
    }

    private function assertNativeContractAndQueries(string $name): void
    {
        $names = array_column($this->columns($name), 'COLUMN_NAME');
        foreach (AdjustmentFieldNames::MAP as $old => $new) {
            self::assertNotContains($old, $names, $name);
            self::assertContains($new, $names, $name);
            $column = $this->quote($new);
            self::assertCount(4, $this->connection->fetchFirstColumn('SELECT ' . $column . ' FROM ' . $this->quote($name) . ' ORDER BY oo_id'));
            self::assertSame([4, 2], array_map('intval', $this->connection->fetchFirstColumn('SELECT oo_id FROM '
                . $this->quote($name) . ' WHERE ' . $column . ' >= ? ORDER BY ' . $column . ' DESC, oo_id', [0])));
            self::assertSame([1], array_map('intval', $this->connection->fetchFirstColumn('SELECT oo_id FROM '
                . $this->quote($name) . ' WHERE ' . $column . ' IS NULL ORDER BY ' . $column)));
        }
    }

    /** @return list<string> */
    private function nativeNames(): array
    {
        $names = [];
        foreach (self::IDS as $id) {
            foreach (self::LANGUAGES as $language) {
                $names[] = $this->localizedName($id, $language);
            }
        }

        return $names;
    }

    private function localizedName(string $id, string $language): string
    {
        return 'object_localized_' . $id . '_' . $language;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $name): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM ' . $this->quote($name) . ' ORDER BY oo_id');
    }

    /** @return list<array<string, mixed>> */
    private function columns(string $name): array
    {
        return $this->connection->fetchAllAssociative('SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, COLUMN_COMMENT '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$this->connection->getDatabase(), $name]);
    }

    /** @return array<string, mixed> */
    private function view(string $name): array
    {
        return $this->connection->fetchAssociative('SHOW CREATE VIEW ' . $this->quote($name));
    }

    /** @param array<string, mixed> $plan */
    private function execute(array $plan): void
    {
        foreach ($plan['sql'] as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function renamedRows(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (AdjustmentFieldNames::MAP as $old => $new) {
                $row[$new] = $row[$old];
                unset($row[$old]);
            }
        }
        unset($row);

        return $rows;
    }

    private function cleanup(): void
    {
        foreach ([self::DEPENDENT, self::PROJECT, $this->localizedName(self::IDS[2], 'merchant_custom'), ...$this->nativeNames(), ...array_map(static fn (string $id): string => 'object_' . $id, self::IDS)] as $view) {
            $this->connection->executeStatement('DROP VIEW IF EXISTS ' . $this->quote($view));
        }
        foreach (self::IDS as $id) {
            foreach (self::LANGUAGES as $language) {
                $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->quote('object_localized_query_' . $id . '_' . $language));
            }
            foreach (['object_store_', 'object_query_'] as $prefix) {
                $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->quote($prefix . $id));
            }
        }
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->quote(self::OBJECTS));
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
