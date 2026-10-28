<?php

declare(strict_types=1);

namespace CoreShop\Tests\Unit\Installer;

use CoreShop\Bundle\CoreBundle\Command\InstallCommand;
use CoreShop\Bundle\CoreBundle\Installer;
use CoreShop\Bundle\CoreBundle\Installer\Checker\CommandDirectoryChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class InstallCommandTest extends TestCase
{
    #[DataProvider('failedSteps')]
    public function testStopsBeforeLaterStepsAndDoesNotMarkFailedInstallation(int $failedStep, int $exitCode): void
    {
        $executed = [];
        $tester = $this->createTester($executed, $failedStep, $exitCode);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(array_slice(['resources', 'database', 'folders'], 0, $failedStep + 1), $executed);
        self::assertStringContainsString('Original failed step diagnostic', $tester->getDisplay());
        self::assertStringNotContainsString('successfully installed', $tester->getDisplay());
    }

    public static function failedSteps(): iterable
    {
        yield [0, 1];
        yield [1, 2];
        yield [2, 1];
    }

    public function testMarksInstallationOnlyAfterEveryStepSucceeds(): void
    {
        $executed = [];
        $tester = $this->createTester($executed);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['resources', 'database', 'folders'], $executed);
        self::assertStringContainsString('CoreShop has been successfully installed.', $tester->getDisplay());
    }

    private function createTester(array &$executed, ?int $failedStep = null, int $exitCode = 0): CommandTester
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getCacheDir')->willReturn(sys_get_temp_dir());
        $installer = $this->getMockBuilder(Installer::class)->disableOriginalConstructor()->onlyMethods(['markAllMigrationsInstalled'])->getMock();
        $installer->expects(null === $failedStep ? self::once() : self::never())->method('markAllMigrationsInstalled');
        $command = new InstallCommand($kernel, new CommandDirectoryChecker(new Filesystem()), $installer);
        $application = new Application();
        $application->setAutoExit(false);
        $application->getDefinition()->addOption(new InputOption('no-debug', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        foreach (['resources', 'database', 'folders'] as $index => $step) {
            $subcommand = new Command('coreshop:install:' . $step);
            $subcommand->setCode(static function (InputInterface $input, OutputInterface $output) use (&$executed, $step, $index, $failedStep, $exitCode): int {
                $executed[] = $step;

                if ($index === $failedStep) {
                    $output->writeln('Original failed step diagnostic');

                    return $exitCode;
                }

                return Command::SUCCESS;
            });
            $application->addCommand($subcommand);
        }

        return new CommandTester($command);
    }
}
