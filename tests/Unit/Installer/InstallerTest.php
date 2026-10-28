<?php

declare(strict_types=1);

namespace CoreShop\Tests\Unit\Installer;

use CoreShop\Bundle\CoreBundle\Installer;
use OpenDxp;
use OpenDxp\Tool\MaintenanceModeHelperInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class InstallerTest extends TestCase
{
    #[DataProvider('exitCodes')]
    public function testBundleInstallerChecksCommandResultBeforeMarkingInstalled(int $exitCode): void
    {
        $container = new Container();
        $container->set('event_dispatcher', new EventDispatcher());
        $maintenance = $this->createMock(MaintenanceModeHelperInterface::class);
        $maintenance->method('isActive')->willReturn(false);
        $container->set(MaintenanceModeHelperInterface::class, $maintenance);
        $command = new Command('coreshop:install');
        $command->setCode(static function (InputInterface $input, OutputInterface $output) use ($exitCode): int {
            self::assertFalse($input->isInteractive());
            $output->writeln('Original installer diagnostic');

            return $exitCode;
        });
        $container->set('audit.install_command', $command);
        $container->setParameter('console.command.ids', ['audit.install_command']);
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);
        $kernel->method('getEnvironment')->willReturn('test');
        $kernel->method('getBundles')->willReturn([]);
        $kernel->method('getBundle')->with('CoreShopCoreBundle')->willReturn($this->createMock(BundleInterface::class));
        $installer = new class($kernel) extends Installer {
            public int $markedInstalled = 0;

            protected function markInstalled(): void
            {
                ++$this->markedInstalled;
            }
        };
        $previousKernel = OpenDxp::getKernel();
        OpenDxp::setKernel($kernel);

        try {
            try {
                $installer->install();
                self::assertSame(0, $exitCode);
                self::assertSame(1, $installer->markedInstalled);
            } catch (RuntimeException $exception) {
                self::assertNotSame(0, $exitCode);
                self::assertSame($exitCode, $exception->getCode());
                self::assertSame(0, $installer->markedInstalled);
                self::assertStringContainsString('CoreShop installation failed', $exception->getMessage());
            }

            self::assertStringContainsString('Original installer diagnostic', $installer->getOutput()->fetch());
        } finally {
            (new ReflectionProperty(OpenDxp::class, 'kernel'))->setValue(null, $previousKernel);
        }
    }

    public static function exitCodes(): iterable
    {
        yield [0];
        yield [1];
        yield [2];
    }
}
