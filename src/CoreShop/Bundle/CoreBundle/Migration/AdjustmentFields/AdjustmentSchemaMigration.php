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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

final class AdjustmentSchemaMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ViewSqlRewriter $views = new ViewSqlRewriter(),
    ) {
    }

    /** @param list<string> $classIds
     * @param list<string> $languages
     *
     * @return array{sql: list<string>, tables: array<string, array>, views: array<string, array>, changed: bool, fingerprints?: array<string, string>}
     */
    public function plan(array $classIds, array $languages = []): array
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            throw new \RuntimeException('Adjustment field migration requires MySQL/MariaDB with RENAME COLUMN support.');
        }
        $plan = ['sql' => [], 'tables' => [], 'views' => [], 'changed' => false];
        $sourceFields = [];
        $standardViews = [];
        foreach ($classIds as $classId) {
            if (1 !== preg_match('/^[a-zA-Z0-9_]+$/D', $classId)) {
                throw new \RuntimeException('Unsupported OpenDXP class identifier.');
            }
            $standardViews['object_' . $classId] = true;
            foreach ($languages as $language) {
                $standardViews['object_localized_' . $classId . '_' . $language] = true;
            }
            foreach (['object_store_', 'object_query_'] as $prefix) {
                $table = $prefix . $classId;
                $columns = $this->columns($table);
                if ([] === $columns) {
                    throw new \RuntimeException(sprintf('Missing storage/query table %s.', $table));
                }
                $plan['tables'][$table] = $columns;
                $sourceFields[$table] = AdjustmentFieldNames::MAP;
                foreach (AdjustmentFieldNames::MAP as $old => $new) {
                    if (isset($columns[$old], $columns[$new])) {
                        throw new \RuntimeException(sprintf('Both %s and %s exist in %s. Reconcile explicitly; no values were overwritten.', $old, $new, $table));
                    }
                    if (!isset($columns[$old]) && !isset($columns[$new])) {
                        throw new \RuntimeException(sprintf('Neither %s nor %s exists in %s.', $old, $new, $table));
                    }
                    if (isset($columns[$old])) {
                        $plan['sql'][] = sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $this->quote($table), $this->quote($old), $this->quote($new));
                        $plan['changed'] = true;
                    }
                }
            }
        }
        $database = (string) $this->connection->getDatabase();
        do {
            $added = false;
            $last = '';
            do {
                $names = $this->connection->fetchFirstColumn(
                    'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME > ? ORDER BY TABLE_NAME LIMIT 100',
                    [$database, $last],
                );
                foreach ($names as $name) {
                    $name = (string) $name;
                    $last = $name;
                    if (isset($plan['views'][$name])) {
                        continue;
                    }
                    $row = $this->connection->fetchAssociative('SHOW CREATE VIEW ' . $this->quote($name));
                    if (false === $row || !isset($row['Create View'])) {
                        throw new \RuntimeException('Cannot inspect view ' . $name . '.');
                    }
                    $before = (string) $row['Create View'];
                    $references = $this->views->references($before, $database, false);
                    if ([] === array_intersect(array_values($references), array_keys($sourceFields))) {
                        continue;
                    }
                    if (count($plan['views']) >= 100) {
                        throw new \RuntimeException('More than 100 dependent views; prepare a project-specific bounded migration.');
                    }
                    $after = $this->views->rewrite($before, $database, $sourceFields, isset($standardViews[$name]));
                    $plan['views'][$name] = [
                        'before' => $before,
                        'after' => $after,
                        'characterSetClient' => (string) $row['character_set_client'],
                        'collationConnection' => (string) $row['collation_connection'],
                        'native' => isset($standardViews[$name]),
                    ];
                    $sourceFields[$name] = isset($standardViews[$name]) ? AdjustmentFieldNames::MAP : [];
                    $added = true;
                }
            } while (100 === count($names));
        } while ($added);
        $ordered = [];
        $remaining = $plan['views'];
        while ([] !== $remaining) {
            $progress = false;
            foreach ($remaining as $name => $view) {
                $dependencies = array_values($this->views->references($view['before'], $database, false));
                if ([] !== array_intersect($dependencies, array_keys($remaining))) {
                    continue;
                }
                $view['after'] = $this->views->rewrite($view['before'], $database, $sourceFields, isset($standardViews[$name]));
                $ordered[$name] = $view;
                unset($remaining[$name]);
                $progress = true;
            }
            if (!$progress) {
                throw new \RuntimeException('Cyclic dependent views require a project-specific migration.');
            }
        }
        $plan['views'] = $ordered;
        foreach ($plan['views'] as $view) {
            if ('CREATE OR REPLACE ' . substr($view['before'], 7) !== $view['after']) {
                $plan['changed'] = true;
            }
        }
        if ($plan['changed']) {
            $this->assertNoLegacyColumnGrants([...array_keys($plan['tables']), ...array_keys($plan['views'])]);
            $session = $this->connection->fetchAssociative('SELECT @@session.character_set_client AS charset, @@session.collation_connection AS collation');
            foreach ($plan['views'] as $view) {
                $encoded = $this->encodeViewSql($view['after'], $view['characterSetClient']);
                $plan['sql'][] = 'SET SESSION character_set_client = ' . $this->connection->quote($view['characterSetClient']);
                $plan['sql'][] = 'SET SESSION collation_connection = ' . $this->connection->quote($view['collationConnection']);
                $plan['sql'][] = 'SET @coreshop_adjustment_view_sql = 0x' . bin2hex($encoded);
                $plan['sql'][] = 'PREPARE coreshop_adjustment_view FROM @coreshop_adjustment_view_sql';
                $plan['sql'][] = 'EXECUTE coreshop_adjustment_view';
                $plan['sql'][] = 'DEALLOCATE PREPARE coreshop_adjustment_view';
                $plan['sql'][] = 'SET SESSION character_set_client = ' . $this->connection->quote($session['charset']);
                $plan['sql'][] = 'SET SESSION collation_connection = ' . $this->connection->quote($session['collation']);
            }
            $plan['fingerprints'] = $this->fingerprints($plan['tables']);
        }

        return $plan;
    }

    private function encodeViewSql(string $sql, string $target): string
    {
        $source = (string) $this->connection->fetchOne('SELECT @@session.character_set_results');
        foreach ([$source, $target] as $charset) {
            if (1 !== preg_match('/^[a-zA-Z0-9_]+$/D', $charset) || 'binary' === strtolower($charset)) {
                throw new \RuntimeException('View SQL requires an explicit supported client/results character set.');
            }
        }

        $encoded = (string) $this->connection->fetchOne(
            'SELECT CAST(CONVERT(CONVERT(0x' . bin2hex($sql) . ' USING ' . $source . ') USING ' . $target . ') AS BINARY)',
        );
        $roundTrip = (string) $this->connection->fetchOne(
            'SELECT CAST(CONVERT(CONVERT(0x' . bin2hex($encoded) . ' USING ' . $target . ') USING ' . $source . ') AS BINARY)',
        );
        if ($sql !== $roundTrip) {
            throw new \RuntimeException('View SQL cannot be converted losslessly to its original creation character set.');
        }

        return $encoded;
    }

    /** @param list<string> $tables */
    private function assertNoLegacyColumnGrants(array $tables): void
    {
        $tableParameters = implode(', ', array_fill(0, count($tables), '?'));
        $columnParameters = implode(', ', array_fill(0, count(AdjustmentFieldNames::MAP), '?'));

        try {
            $found = $this->connection->fetchOne(
                'SELECT 1 FROM mysql.columns_priv WHERE Db = ? AND Table_name IN (' . $tableParameters . ') AND Column_name IN (' . $columnParameters . ') LIMIT 1',
                [$this->connection->getDatabase(), ...$tables, ...array_keys(AdjustmentFieldNames::MAP)],
            );
        } catch (\Doctrine\DBAL\Exception $exception) {
            throw new \RuntimeException('Cannot audit column-specific grants before adjustment migration. Run with an authorized DBA account or grant the migration account SELECT on mysql.columns_priv; no schema changes have been executed.', 0, $exception);
        }
        if (false !== $found) {
            throw new \RuntimeException('Column-specific grants reference legacy adjustment fields. Have the DBA back up and explicitly map these grants before migrating; privileges will not be silently widened or discarded.');
        }
    }

    /** @param array{tables: array<string, array>, views: array<string, array>, fingerprints?: array<string, string>} $plan */
    public function verify(array $plan): void
    {
        foreach ($plan['tables'] as $table => $before) {
            $after = $this->columns($table);
            foreach ($before as $name => $properties) {
                $target = AdjustmentFieldNames::MAP[$name] ?? $name;
                $properties['COLUMN_NAME'] = $target;
                if (!isset($after[$target]) || $properties !== $after[$target]) {
                    throw new \RuntimeException(sprintf('Column properties changed unexpectedly in %s.%s. Keep the shop stopped and restore or repair from backup.', $table, $target));
                }
                if (isset(AdjustmentFieldNames::MAP[$name], $after[$name])) {
                    throw new \RuntimeException('Legacy column still exists in ' . $table . '.');
                }
            }
            if (count($before) !== count($after)) {
                throw new \RuntimeException('Unexpected column count change in ' . $table . '.');
            }
        }
        foreach ($plan['views'] as $name => $view) {
            $this->connection->executeQuery('SELECT * FROM ' . $this->quote($name) . ' LIMIT 0')->free();
            if ($view['native']) {
                $columns = $this->columns($name);
                foreach (AdjustmentFieldNames::MAP as $old => $new) {
                    if (!isset($columns[$new]) || isset($columns[$old])) {
                        throw new \RuntimeException(sprintf('Native view %s must expose %s and must not expose %s.', $name, $new, $old));
                    }
                }
            }
            $actual = $this->connection->fetchAssociative('SHOW CREATE VIEW ' . $this->quote($name));
            if ($actual['character_set_client'] !== $view['characterSetClient'] || $actual['collation_connection'] !== $view['collationConnection']) {
                throw new \RuntimeException('View character set or collation changed unexpectedly: ' . $name);
            }
        }
        if (isset($plan['fingerprints']) && $plan['fingerprints'] !== $this->fingerprints($plan['tables'], true)) {
            throw new \RuntimeException('Stored/query values changed unexpectedly. Keep the shop stopped and recover from backup.');
        }
    }

    /** @param array<string, array> $tables
     * @return array<string, string>
     */
    private function fingerprints(array $tables, bool $renamed = false): array
    {
        $result = [];
        foreach ($tables as $table => $columns) {
            if (!isset($columns['oo_id'])) {
                throw new \RuntimeException('Missing bounded pagination key oo_id in ' . $table);
            }
            $select = [];
            foreach (array_keys($columns) as $name) {
                $target = AdjustmentFieldNames::MAP[$name] ?? $name;
                $select[] = $this->quote($renamed ? $target : $name) . ' AS ' . $this->quote($target);
            }
            $hash = hash_init('sha256');
            $last = null;
            do {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT ' . implode(', ', $select) . ' FROM ' . $this->quote($table)
                    . (null === $last ? '' : ' WHERE oo_id > ?') . ' ORDER BY oo_id LIMIT 100',
                    null === $last ? [] : [$last],
                );
                foreach ($rows as $row) {
                    hash_update($hash, serialize($row));
                    $last = $row['oo_id'];
                }
            } while (100 === count($rows));
            $result[$table] = hash_final($hash);
        }

        return $result;
    }

    /** @return array<string, array> */
    private function columns(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_COMMENT '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION LIMIT 4096',
            [$this->connection->getDatabase(), $table],
        );
        if (4096 === count($rows)) {
            throw new \RuntimeException('Column inspection safety limit exceeded in ' . $table . '.');
        }
        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['COLUMN_NAME']] = $row;
        }

        return $columns;
    }

    private function quote(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
