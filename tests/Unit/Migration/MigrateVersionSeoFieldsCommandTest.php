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

use CoreShop\Bundle\CoreBundle\Command\MigrateVersionSeoFieldsCommand;
use CoreShop\Bundle\CoreBundle\Migration\VersionSeoFieldsConverter;
use Doctrine\DBAL\Connection;
use OpenDxp;
use OpenDxp\Model\Version;
use OpenDxp\Model\Version\Adapter\VersionStorageAdapterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\KernelInterface;

final class MigrateVersionSeoFieldsCommandTest extends TestCase
{
    private mixed $previousKernel;

    protected function setUp(): void
    {
        $this->previousKernel = OpenDxp::getKernel();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(OpenDxp::class, 'kernel'))->setValue(null, $this->previousKernel);
    }

    public function testDryRunDoesNotWriteAndApplyValidatesBeforeWriting(): void
    {
        foreach ([false, true] as $apply) {
            $storage = $this->createMock(VersionStorageAdapterInterface::class);
            $this->installStorage($storage);
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::exactly($apply ? 2 : 1))->method('fetchAllAssociative')
                ->with(self::stringContains('LIMIT 100'), ['object', 'stdClass', 'stdClass', 0])
                ->willReturn([$this->row(1)])
            ;
            $payload = serialize((object) ['pimcoreMetaTitle' => 'SEO']);
            $storage->expects(self::exactly($apply ? 2 : 1))->method('loadMetaData')->willReturn($payload);
            $storage->expects(self::exactly($apply ? 1 : 0))->method('save')->with(
                self::callback(static fn (Version $version): bool => 1 === $version->getId() && 'fs' === $version->getStorageType()),
                str_replace('pimcoreMetaTitle', 'opendxpMetaTitle', $payload),
                null,
            );
            $tester = new CommandTester(new MigrateVersionSeoFieldsCommand($connection, $storage, new VersionSeoFieldsConverter(), \stdClass::class, \stdClass::class));
            self::assertSame(0, $tester->execute($apply ? ['--apply' => true] : []));
            self::assertStringContainsString('checked 1 versions; 1 payloads', $tester->getDisplay());
        }
    }

    public function testCollisionOnSecondPagePreventsAllWrites(): void
    {
        $storage = $this->createMock(VersionStorageAdapterInterface::class);
        $this->installStorage($storage);
        $connection = $this->createMock(Connection::class);
        $rows = array_map($this->row(...), range(1, 100));
        $connection->expects(self::exactly(2))->method('fetchAllAssociative')->willReturnOnConsecutiveCalls($rows, [$this->row(101)]);
        $old = serialize((object) ['pimcoreMetaTitle' => 'SEO']);
        $collision = serialize((object) ['pimcoreMetaTitle' => 'SEO', 'opendxpMetaTitle' => 'Other']);
        $storage->method('loadMetaData')->willReturnCallback(static fn (Version $version): string => $version->getId() === 101 ? $collision : $old);
        $storage->expects(self::never())->method('save');
        $tester = new CommandTester(new MigrateVersionSeoFieldsCommand($connection, $storage, new VersionSeoFieldsConverter(), \stdClass::class, \stdClass::class));
        self::assertSame(1, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('Version 101:', $tester->getDisplay());
    }

    public function testMissingStoragePayloadFailsInsteadOfSkipping(): void
    {
        $storage = $this->createMock(VersionStorageAdapterInterface::class);
        $this->installStorage($storage);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([$this->row(1)]);
        $storage->method('loadMetaData')->willReturn(null);
        $storage->expects(self::never())->method('save');
        $tester = new CommandTester(new MigrateVersionSeoFieldsCommand($connection, $storage, new VersionSeoFieldsConverter(), \stdClass::class, \stdClass::class));
        self::assertSame(1, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('payload is missing', $tester->getDisplay());
    }

    private function installStorage(VersionStorageAdapterInterface $storage): void
    {
        $container = new Container();
        $container->set(VersionStorageAdapterInterface::class, $storage);
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);
        OpenDxp::setKernel($kernel);
    }

    private function row(int $id): array
    {
        return ['id' => $id, 'cid' => 9, 'serialized' => 1, 'storageType' => 'fs'];
    }
}
