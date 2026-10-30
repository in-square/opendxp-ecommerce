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

namespace CoreShop\Tests\Unit\Migration;

use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\HistoryFieldsMigrator;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\SerializedAdjustmentFieldsConverter;
use Doctrine\DBAL\Connection;
use OpenDxp;
use OpenDxp\Model\Version;
use OpenDxp\Model\Version\Adapter\VersionStorageAdapterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\KernelInterface;

final class AdjustmentHistoryMigratorTest extends TestCase
{
    private mixed $previousKernel;

    private string $directory;

    protected function setUp(): void
    {
        $this->previousKernel = OpenDxp::getKernel();
        $this->directory = sys_get_temp_dir() . '/coreshop-adjustment-history-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(OpenDxp::class, 'kernel'))->setValue(null, $this->previousKernel);
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
    }

    public function testValidationDoesNotWriteAndApplyBacksUpBeforeSaving(): void
    {
        $storage = $this->createMock(VersionStorageAdapterInterface::class);
        $this->installStorage($storage);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $row = $this->row(1);
        $connection->expects(self::exactly(4))->method('fetchAllAssociative')->with(self::stringContains('LIMIT 100'), ['object', 'custom_order', 0])->willReturn([$row]);
        $original = serialize((object) ['pimcoreAdjustmentTotalNet' => -12]);
        $payload = $original;
        $storage->method('loadMetaData')->willReturnCallback(static function () use (&$payload): string { return $payload; });
        $storage->method('loadBinaryData')->willReturn(null);
        $storage->expects(self::once())->method('save')->willReturnCallback(function (Version $version, string $converted, mixed $binary) use (&$payload, $original, $row): void {
            self::assertSame($original, file_get_contents($this->directory . '/1.serialized'));
            self::assertSame($row, json_decode(file_get_contents($this->directory . '/1.json'), true, 512, \JSON_THROW_ON_ERROR));
            self::assertSame(0600, fileperms($this->directory . '/1.serialized') & 0777);
            self::assertSame(1, $version->getId());
            self::assertSame('fs', $version->getStorageType());
            self::assertNull($binary);
            self::assertStringContainsString('opendxpAdjustmentTotalNet', $converted);
            $payload = $converted;
        });
        $migrator = new HistoryFieldsMigrator($connection, $storage, new SerializedAdjustmentFieldsConverter());
        self::assertSame(1, $migrator->validate(['custom_order'], [\stdClass::class]));
        self::assertDirectoryDoesNotExist($this->directory);
        self::assertSame(1, $migrator->migrate(['custom_order'], [\stdClass::class], $this->directory));
        self::assertSame(0, $migrator->migrate(['custom_order'], [\stdClass::class], $this->directory));
    }

    public function testAllNewHistoryIsNoOpWithoutBackupDirectory(): void
    {
        $storage = $this->createMock(VersionStorageAdapterInterface::class);
        $this->installStorage($storage);
        $storage->method('loadMetaData')->willReturn(serialize((object) ['opendxpAdjustmentTotalNet' => null]));
        $storage->expects(self::never())->method('save');
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturn([$this->row(1)]);
        self::assertSame(0, (new HistoryFieldsMigrator($connection, $storage, new SerializedAdjustmentFieldsConverter()))->migrate(['custom_order'], [\stdClass::class], $this->directory));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testConflictOnLaterPagePreventsEveryWrite(): void
    {
        $storage = $this->createMock(VersionStorageAdapterInterface::class);
        $this->installStorage($storage);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->expects(self::exactly(2))->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(array_map($this->row(...), range(1, 100)), [$this->row(101)]);
        $storage->method('loadMetaData')->willReturnCallback(static fn (Version $version): string => serialize((object) ($version->getId() === 101 ? ['pimcoreAdjustmentTotalNet' => 1, 'opendxpAdjustmentTotalNet' => 2] : ['pimcoreAdjustmentTotalNet' => 1])));
        $storage->method('loadBinaryData')->willReturn(null);
        $storage->expects(self::never())->method('save');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('version 101');
        (new HistoryFieldsMigrator($connection, $storage, new SerializedAdjustmentFieldsConverter()))->migrate(['custom_order'], [\stdClass::class], $this->directory);
    }

    public function testOrphanVersionStopsBeforePayloadScan(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->with(self::stringContains('o.id IS NULL LIMIT 1'), ['object'])->willReturn(99);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $storage = $this->createMock(VersionStorageAdapterInterface::class);
        $storage->expects(self::never())->method('save');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Orphan object versions');
        (new HistoryFieldsMigrator($connection, $storage, new SerializedAdjustmentFieldsConverter()))->validate(['custom_order'], [\stdClass::class]);
    }

    public function testExistingBackupIsNeverOverwritten(): void
    {
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/1.serialized', 'previous backup');
        $storage = $this->createMock(VersionStorageAdapterInterface::class);
        $this->installStorage($storage);
        $storage->method('loadMetaData')->willReturn(serialize((object) ['pimcoreAdjustmentTotalNet' => 1]));
        $storage->method('loadBinaryData')->willReturn(null);
        $storage->expects(self::never())->method('save');
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturn([$this->row(1)]);

        try {
            (new HistoryFieldsMigrator($connection, $storage, new SerializedAdjustmentFieldsConverter()))->migrate(['custom_order'], [\stdClass::class], $this->directory);
            self::fail('Conflicting backup was overwritten.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Existing history backup does not match', $exception->getMessage());
            self::assertSame('previous backup', file_get_contents($this->directory . '/1.serialized'));
        }
    }

    public function testUnexpectedObjectBinaryDataStopsWithoutWriting(): void
    {
        $storage = $this->createMock(VersionStorageAdapterInterface::class);
        $this->installStorage($storage);
        $storage->method('loadMetaData')->willReturn(serialize((object) ['pimcoreAdjustmentTotalNet' => 1]));
        $stream = tmpfile();
        $storage->method('loadBinaryData')->willReturn($stream);
        $storage->expects(self::never())->method('save');
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('fetchAllAssociative')->willReturn([$this->row(1)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected binary data');
        (new HistoryFieldsMigrator($connection, $storage, new SerializedAdjustmentFieldsConverter()))->validate(['custom_order'], [\stdClass::class]);
    }

    private function installStorage(VersionStorageAdapterInterface $storage): void
    {
        $container = new Container();
        $container->set(VersionStorageAdapterInterface::class, $storage);
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);
        OpenDxp::setKernel($kernel);
    }

    /** @return array{id: int, cid: int, serialized: int, storageType: string, binaryFileId: null} */
    private function row(int $id): array
    {
        return ['id' => $id, 'cid' => 15, 'serialized' => 1, 'storageType' => 'fs', 'binaryFileId' => null];
    }
}
