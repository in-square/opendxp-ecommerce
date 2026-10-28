<?php

declare(strict_types=1);

namespace CoreShop\Bundle\CoreBundle\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;

final class Version20261028120000 extends AbstractContainerAwareMigration
{
    private const FIELD_NAMES = [
        'pimcoreMetaTitle' => 'opendxpMetaTitle',
        'pimcoreMetaDescription' => 'opendxpMetaDescription',
    ];

    public function getDescription(): string
    {
        return 'Preserve product and category SEO values when renaming their persisted OpenDXP fields.';
    }

    public function isTransactional(): bool
    {

        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'This migration requires MySQL or MariaDB with RENAME COLUMN support.');

        $classNames = [];

        foreach (['coreshop.model.product.class', 'coreshop.model.category.class'] as $parameter) {
            $className = $this->container->getParameter($parameter);
            $this->abortIf(!is_string($className) || '' === $className, sprintf('Missing configured model: %s.', $parameter));
            $classNames[] = basename(str_replace('\\', '/', $className));
        }


        $classIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM classes WHERE name IN (?, ?) ORDER BY id LIMIT 2',
            $classNames,
        );

        foreach ($classIds as $classId) {
            $this->renameClassFields($schema, (string) $classId);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore the pre-upgrade backup and class definitions to revert the SEO field names.');
    }

    private function renameClassFields(Schema $schema, string $classId): void
    {
        $localizedPrefix = 'object_localized_query_' . $classId . '_';
        $lastTable = '';

        do {

            $tables = $this->connection->fetchFirstColumn(
                'SELECT TABLE_NAME FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? AND TABLE_NAME > ? '
                . 'AND (TABLE_NAME IN (?, ?, ?) OR LEFT(TABLE_NAME, CHAR_LENGTH(?)) = ?) '
                . 'ORDER BY TABLE_NAME LIMIT 100',
                [
                    $this->connection->getDatabase(),
                    'BASE TABLE',
                    $lastTable,
                    'object_store_' . $classId,
                    'object_query_' . $classId,
                    'object_localized_data_' . $classId,
                    $localizedPrefix,
                    $localizedPrefix,
                ],
            );

            foreach ($tables as $tableName) {
                $tableName = (string) $tableName;
                $table = $schema->getTable($tableName);

                foreach (self::FIELD_NAMES as $oldName => $newName) {
                    if (!$table->hasColumn($oldName)) {
                        continue;
                    }

                    $this->abortIf(
                        $table->hasColumn($newName),
                        sprintf('Both %s and %s exist in %s. Reconcile their values before running this migration.', $oldName, $newName, $tableName),
                    );


                    $this->addSql(sprintf(
                        'ALTER TABLE %s RENAME COLUMN %s TO %s',
                        $this->connection->quoteIdentifier($tableName),
                        $this->connection->quoteIdentifier($oldName),
                        $this->connection->quoteIdentifier($newName),
                    ));
                }

                $lastTable = $tableName;
            }
        } while (100 === count($tables));
    }
}
