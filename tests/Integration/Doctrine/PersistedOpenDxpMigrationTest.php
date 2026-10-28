<?php

declare(strict_types=1);

namespace CoreShop\Tests\Integration\Doctrine;

use CoreShop\Bundle\CoreBundle\Migrations\Version20261028120000;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Exception\AbortMigration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

final class PersistedOpenDxpMigrationTest extends TestCase
{
    private const TABLES = [
        'object_store_migration_product',
        'object_query_migration_product',
        'object_localized_data_migration_product',
        'object_localized_query_migration_product_en',
        'object_localized_query_migration_product_pl',
        'object_localized_query_migration_category_en',
        'object_localized_data_ignored',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $dsn = getenv('CORESHOP_MIGRATION_TEST_DSN');

        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set CORESHOP_MIGRATION_TEST_DSN to the dedicated coreshop_migration_test database.');
        }

        $this->connection = DriverManager::getConnection(['url' => $dsn]);

        self::assertSame('coreshop_migration_test', $this->connection->getDatabase());
        $this->cleanFixtures();
        $this->connection->executeStatement('CREATE TABLE classes (id VARCHAR(50) PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE)');

        foreach ([['migration_product', 'CustomMigrationProduct'], ['migration_category', 'CustomMigrationCategory'], ['ignored', 'UnrelatedModel']] as [$id, $name]) {
            $this->connection->insert('classes', ['id' => $id, 'name' => $name]);
        }

        foreach (self::TABLES as $table) {
            $this->connection->executeStatement(sprintf(
                'CREATE TABLE %s (id INT PRIMARY KEY, pimcoreMetaTitle VARCHAR(190) NOT NULL DEFAULT \'Untitled\', pimcoreMetaDescription TEXT DEFAULT NULL, INDEX seo_title (pimcoreMetaTitle))',
                $this->connection->quoteIdentifier($table),
            ));
            $this->connection->insert($table, ['id' => 1, 'pimcoreMetaTitle' => $table . ' — Łódź', 'pimcoreMetaDescription' => null]);
        }


        $this->connection->executeStatement('CREATE VIEW object_localized_query_migration_product_view AS SELECT id FROM object_localized_data_migration_product');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->cleanFixtures();
            $this->connection->close();
        }
    }

    public function testRenamesValuesAcrossStorageAndLocaleTablesWithoutChangingDefinitions(): void
    {
        $migration = $this->createMigration();
        $migration->up($this->connection->createSchemaManager()->introspectSchema());
        self::assertCount(12, $migration->getSql());
        $this->execute($migration);

        foreach (array_slice(self::TABLES, 0, 6) as $table) {
            $columns = $this->connection->createSchemaManager()->listTableColumns($table);
            self::assertArrayNotHasKey('pimcoremetatitle', $columns);
            self::assertArrayHasKey('opendxpmetatitle', $columns);
            self::assertSame('Untitled', $columns['opendxpmetatitle']->getDefault());
            self::assertTrue($columns['opendxpmetatitle']->getNotnull());
            self::assertSame(190, $columns['opendxpmetatitle']->getLength());
            self::assertSame([$table . ' — Łódź', null], array_values($this->connection->fetchAssociative('SELECT opendxpMetaTitle, opendxpMetaDescription FROM ' . $this->connection->quoteIdentifier($table) . ' WHERE id = 1')));
            self::assertSame(['opendxpMetaTitle'], $this->connection->createSchemaManager()->listTableIndexes($table)['seo_title']->getColumns());
        }

        self::assertArrayHasKey('pimcoremetatitle', $this->connection->createSchemaManager()->listTableColumns('object_localized_data_ignored'));
        $again = $this->createMigration();
        $again->up($this->connection->createSchemaManager()->introspectSchema());
        self::assertSame([], $again->getSql());
    }

    public function testConflictingColumnNamesAbortWithoutExecutingAnyRename(): void
    {
        $this->connection->executeStatement('ALTER TABLE object_localized_query_migration_product_pl ADD opendxpMetaTitle VARCHAR(190) DEFAULT NULL');
        $migration = $this->createMigration();

        try {
            $migration->up($this->connection->createSchemaManager()->introspectSchema());
            self::fail('Expected conflicting SEO columns to abort the migration.');
        } catch (AbortMigration $exception) {
            self::assertStringContainsString('Both pimcoreMetaTitle and opendxpMetaTitle', $exception->getMessage());
        }

        foreach (self::TABLES as $table) {
            self::assertArrayHasKey('pimcoremetatitle', $this->connection->createSchemaManager()->listTableColumns($table));
        }
    }

    public function testDiscoversLocaleTablesInMoreThanOneBoundedPage(): void
    {
        for ($index = 0; $index < 101; ++$index) {
            $this->connection->executeStatement(sprintf(
                'CREATE TABLE object_localized_query_migration_product_page_%03d (id INT PRIMARY KEY, pimcoreMetaTitle VARCHAR(190) DEFAULT NULL)',
                $index,
            ));
        }

        $migration = $this->createMigration();
        $migration->up($this->connection->createSchemaManager()->introspectSchema());
        self::assertCount(113, $migration->getSql());
    }

    public function testLegacyDoctrineCommentsResolveToCanonicalOpenDxpTypes(): void
    {
        $configuration = Yaml::parseFile(dirname(__DIR__, 3) . '/src/CoreShop/Bundle/ResourceBundle/Resources/config/opendxp/config.yml');
        $types = $configuration['doctrine']['dbal']['types'];
        $columns = [];

        foreach (['Object', 'Document', 'Asset', 'Site'] as $suffix) {
            $legacy = 'pimcore' . $suffix;
            $canonical = 'opendxp' . $suffix;
            self::assertSame($types[$canonical]['class'], $types[$legacy]['class']);

            if (!Type::hasType($legacy)) {
                Type::addType($legacy, $types[$legacy]['class']);
            }
            $columns[] = sprintf('%s INT DEFAULT NULL COMMENT \'(DC2Type:%s)\'', $this->connection->quoteIdentifier($legacy), $legacy);
        }

        $this->connection->executeStatement('CREATE TABLE coreshop_legacy_types (' . implode(', ', $columns) . ')');
        $metadata = $this->connection->createSchemaManager()->listTableColumns('coreshop_legacy_types');

        foreach (['Object', 'Document', 'Asset', 'Site'] as $suffix) {
            self::assertSame('opendxp' . $suffix, $metadata[strtolower('pimcore' . $suffix)]->getType()->getName());
        }
    }

    private function createMigration(): Version20261028120000
    {
        $container = new ContainerBuilder();
        $container->setParameter('coreshop.model.product.class', 'OpenDxp\\Model\\DataObject\\CustomMigrationProduct');
        $container->setParameter('coreshop.model.category.class', 'OpenDxp\\Model\\DataObject\\CustomMigrationCategory');

        return new Version20261028120000($this->connection, new NullLogger(), $container);
    }

    private function execute(Version20261028120000 $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function cleanFixtures(): void
    {
        $this->connection->executeStatement('DROP VIEW IF EXISTS object_localized_query_migration_product_view');

        foreach ([...self::TABLES, 'coreshop_legacy_types', 'classes'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->connection->quoteIdentifier($table));
        }

        for ($index = 0; $index < 101; ++$index) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS object_localized_query_migration_product_page_%03d', $index));
        }
    }
}
