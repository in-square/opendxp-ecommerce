<?php

declare(strict_types=1);

namespace CoreShop\Tests\Unit\Installer;

use CoreShop\Bundle\CoreBundle\Installer\Executor\CommandExecutor;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\RuntimeException;

final class CommandExecutorTest extends TestCase
{
    #[DataProvider('exitCodes')]
    public function testRestoresAutoExitAndKeepsOriginalCommandOutput(int $exitCode, bool $autoExit): void
    {
        $application = new Application();
        $application->setAutoExit($autoExit);
        $application->getDefinition()->addOption(new InputOption('no-debug', null, InputOption::VALUE_NONE));
        $command = new Command('audit:step');
        $command->setCode(static function (InputInterface $input, OutputInterface $output) use ($exitCode): int {
            $output->writeln('Original diagnostic from installation step');

            return $exitCode;
        });
        $application->addCommand($command);
        $output = new BufferedOutput();
        $executor = new CommandExecutor(new ArrayInput([]), $output, $application);

        try {
            self::assertSame($executor, $executor->runCommand('audit:step'));
            self::assertSame(0, $exitCode);
        } catch (RuntimeException $exception) {
            self::assertNotSame(0, $exitCode);
            self::assertSame($exitCode, $exception->getCode());
            self::assertSame(sprintf('Command "audit:step" failed with exit code %d.', $exitCode), $exception->getMessage());
        }

        self::assertSame($autoExit, $application->isAutoExitEnabled());
        self::assertStringContainsString('Original diagnostic from installation step', $output->fetch());
    }

    public static function exitCodes(): iterable
    {
        foreach ([0, 1, 2] as $code) {
            yield [$code, true];
            yield [$code, false];
        }
    }

    public function testRestoresAutoExitWhenCommandThrows(): void
    {
        $application = new Application();
        $application->setCatchExceptions(false);
        $application->getDefinition()->addOption(new InputOption('no-debug', null, InputOption::VALUE_NONE));
        $command = new Command('audit:step');
        $failure = new LogicException('Step could not start');
        $command->setCode(static function () use ($failure): never {
            throw $failure;
        });
        $application->addCommand($command);
        $executor = new CommandExecutor(new ArrayInput([]), new BufferedOutput(), $application);

        try {
            $executor->runCommand('audit:step');
            self::fail('Expected the original exception.');
        } catch (LogicException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertTrue($application->isAutoExitEnabled());
    }
}
