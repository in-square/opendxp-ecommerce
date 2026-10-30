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

use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\HistoryFieldsMigrator;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\SerializedAdjustmentFieldsConverter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use OpenDxp;
use OpenDxp\Model\Version;
use OpenDxp\Model\Version\Adapter\DatabaseVersionStorageAdapter;
use OpenDxp\Model\Version\Adapter\FileSystemVersionStorageAdapter;
use OpenDxp\Model\Version\Adapter\VersionStorageAdapterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\KernelInterface;

final class AdjustmentHistoryStorageTest extends TestCase
{
    private Connection $connection;

    private VersionStorageAdapterInterface $storage;

    private mixed $previousKernel;

    private string $backup;

    private ?string $filesystemDirectory = null;

    protected function setUp(): void
    {
        $dsn = getenv('CORESHOP_MIGRATION_TEST_DSN');
        if (!$dsn) {
            self::markTestSkipped('Requires the isolated coreshop_migration_test database.');
        }
        $this->connection = DriverManager::getConnection(['url' => $dsn]);
        self::assertSame('coreshop_migration_test', $this->connection->getDatabase());
        $this->previousKernel = OpenDxp::getKernel();
        $this->backup = sys_get_temp_dir() . '/adjustment-history-db-' . bin2hex(random_bytes(6));
        $this->storage = new DatabaseVersionStorageAdapter($this->connection);
        $container = new Container();
        $container->set(VersionStorageAdapterInterface::class, $this->storage);
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);
        OpenDxp::setKernel($kernel);
        $this->cleanup();
        $this->connection->executeStatement('CREATE TABLE objects (id INT PRIMARY KEY, classId VARCHAR(50))');
        $this->connection->executeStatement('CREATE TABLE versions (id INT PRIMARY KEY, cid INT, ctype VARCHAR(20), serialized TINYINT, storageType VARCHAR(20), binaryFileId INT NULL, note VARCHAR(90), date BIGINT)');
        $this->connection->executeStatement('CREATE TABLE versionsData (id INT PRIMARY KEY, cid INT, ctype VARCHAR(20), metaData LONGBLOB, binaryData LONGBLOB NULL)');
        $this->connection->insert('objects', ['id' => 15, 'classId' => 'project_order']);
        for ($id = 1; $id <= 101; ++$id) {
            $this->connection->insert('versions', ['id' => $id, 'cid' => 15, 'ctype' => 'object', 'serialized' => 1, 'storageType' => 'db', 'binaryFileId' => null, 'note' => 'historical note', 'date' => 1700000000 + $id]);
            $version = (new Version())->setId($id)->setCid(15)->setCtype('object')->setSerialized(true);
            $version->setStorageType('db');
            $this->storage->save($version, serialize((object) ['pimcoreAdjustmentTotalNet' => [null, 0, -99, 9000000000000000000][$id % 4], 'convertedPimcoreAdjustmentTotalGross' => -$id, 'custom' => 'pimcoreAdjustmentTotalNet']), null);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->cleanup();
            $this->connection->close();
            (new \ReflectionProperty(OpenDxp::class, 'kernel'))->setValue(null, $this->previousKernel);
            if (is_dir($this->backup)) {
                foreach (glob($this->backup . '/*') as $path) {
                    unlink($path);
                }
                rmdir($this->backup);
            }
            if (null !== $this->filesystemDirectory) {
                (new \Symfony\Component\Filesystem\Filesystem())->remove($this->filesystemDirectory);
            }
        }
    }

    public function testDatabaseAdapterAcrossPagesPreservesMetadataAndValues(): void
    {
        $before = $this->connection->fetchAllAssociative('SELECT * FROM versions ORDER BY id');
        $payloads = $this->connection->fetchAllKeyValue('SELECT id, metaData FROM versionsData ORDER BY id');
        $migrator = new HistoryFieldsMigrator($this->connection, $this->storage, new SerializedAdjustmentFieldsConverter());
        self::assertSame(101, $migrator->validate(['project_order'], [\stdClass::class]));
        self::assertSame($payloads, $this->connection->fetchAllKeyValue('SELECT id, metaData FROM versionsData ORDER BY id'));
        self::assertDirectoryDoesNotExist($this->backup);
        self::assertSame(101, $migrator->migrate(['project_order'], [\stdClass::class], $this->backup));
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM versions ORDER BY id'));
        foreach ($payloads as $id => $payload) {
            self::assertSame($payload, file_get_contents($this->backup . '/' . $id . '.serialized'));
            $old = (array) unserialize($payload, ['allowed_classes' => [\stdClass::class]]);
            $new = (array) unserialize($this->connection->fetchOne('SELECT metaData FROM versionsData WHERE id = ?', [$id]), ['allowed_classes' => [\stdClass::class]]);
            self::assertSame($old['pimcoreAdjustmentTotalNet'], $new['opendxpAdjustmentTotalNet']);
            self::assertSame($old['convertedPimcoreAdjustmentTotalGross'], $new['convertedOpenDxpAdjustmentTotalGross']);
            self::assertSame($old['custom'], $new['custom']);
        }
        self::assertSame(0, $migrator->migrate(['project_order'], [\stdClass::class], $this->backup));
    }

    public function testConflictOnSecondPageDoesNotWriteFirstPage(): void
    {
        $this->connection->update('versionsData', ['metaData' => serialize((object) ['pimcoreAdjustmentTotalNet' => 1, 'opendxpAdjustmentTotalNet' => 2])], ['id' => 101]);
        $before = $this->connection->fetchAllAssociative('SELECT * FROM versionsData ORDER BY id');

        try {
            (new HistoryFieldsMigrator($this->connection, $this->storage, new SerializedAdjustmentFieldsConverter()))->migrate(['project_order'], [\stdClass::class], $this->backup);
            self::fail('Conflict must abort before any write.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('version 101', $exception->getMessage());
        }
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM versionsData ORDER BY id'));
        self::assertDirectoryDoesNotExist($this->backup);
    }

    public function testNativeFilesystemStoragePreservesMetadataAndPayloadValues(): void
    {
        $this->filesystemDirectory = sys_get_temp_dir() . '/adjustment-history-fs-' . bin2hex(random_bytes(6));
        $storage = new class(new Filesystem(new LocalFilesystemAdapter($this->filesystemDirectory))) extends FileSystemVersionStorageAdapter {
            public function __construct(
                FilesystemOperator $filesystem,
            ) {
                $this->storage = $filesystem;
            }
        };
        $this->connection->executeStatement("UPDATE versions SET storageType = 'fs'");
        $rows = $this->connection->fetchAllAssociative('SELECT id, cid, metaData FROM versionsData ORDER BY id');
        foreach ($rows as $row) {
            $version = (new Version())->setId((int) $row['id'])->setCid((int) $row['cid'])->setCtype('object')->setSerialized(true);
            $version->setStorageType('fs');
            $storage->save($version, $row['metaData'], null);
        }
        $before = $this->connection->fetchAllAssociative('SELECT * FROM versions ORDER BY id');
        $migrator = new HistoryFieldsMigrator($this->connection, $storage, new SerializedAdjustmentFieldsConverter());
        self::assertSame(101, $migrator->migrate(['project_order'], [\stdClass::class], $this->backup));
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM versions ORDER BY id'));
        foreach ($rows as $row) {
            $version = (new Version())->setId((int) $row['id'])->setCid((int) $row['cid'])->setCtype('object');
            $expected = (new SerializedAdjustmentFieldsConverter())->convert($row['metaData'], [\stdClass::class]);
            self::assertSame($expected, $storage->loadMetaData($version));
            self::assertSame($row['metaData'], file_get_contents($this->backup . '/' . $row['id'] . '.serialized'));
        }
        self::assertSame(0, $migrator->migrate(['project_order'], [\stdClass::class], $this->backup));
    }

    private function cleanup(): void
    {
        foreach (['versionsData', 'versions', 'objects'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $this->connection->quoteIdentifier($table));
        }
    }
}
