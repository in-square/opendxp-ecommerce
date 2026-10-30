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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AdjustmentMigrationExecutionTest extends TestCase
{
    private mixed $fixtureLock = null;

    protected function setUp(): void
    {
        if ('1' !== getenv('CORESHOP_ADJUSTMENT_EXECUTION_TESTS')) {
            self::markTestSkipped('Set CORESHOP_ADJUSTMENT_EXECUTION_TESTS=1 only in the disposable installed coreshop_test application.');
        }
        $this->fixtureLock = fopen(sys_get_temp_dir() . '/coreshop-adjustment-execution-tests.lock', 'c');
        self::assertIsResource($this->fixtureLock);
        self::assertTrue(flock($this->fixtureLock, \LOCK_EX));
        $this->execute('cleanup');
        $this->execute('setup');
    }

    protected function tearDown(): void
    {
        if ('1' === getenv('CORESHOP_ADJUSTMENT_EXECUTION_TESTS')) {
            try {
                $this->execute('cleanup');
            } finally {
                if (is_resource($this->fixtureLock)) {
                    flock($this->fixtureLock, \LOCK_UN);
                    fclose($this->fixtureLock);
                }
            }
        }
    }

    public function testRealRunnerDryRunMigrationFreshProcessGettersAndIdempotence(): void
    {
        $before = $this->execute('snapshot');
        $preview = $this->execute('dry-run');
        self::assertGreaterThanOrEqual(24, $preview['sqlCount']);
        self::assertSame($before, $this->execute('snapshot'), 'Doctrine dry-run changed schema, values or generated files.');
        $result = $this->execute('migrate');
        self::assertSame(0, $result['objectEvents']);
        self::assertGreaterThanOrEqual(24, $result['sqlCount']);
        $migrated = $this->execute('snapshot');
        self::assertSame($before['versions'], $migrated['versions'], 'Migration changed version metadata.');
        foreach ($before['rows'] as $table => $rows) {
            foreach ($rows as &$row) {
                foreach (\CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\AdjustmentFieldNames::MAP as $old => $new) {
                    $row[$new] = $row[$old];
                    unset($row[$old]);
                }
            }
            unset($row);
            self::assertEquals($rows, $migrated['rows'][$table], $table . ' values or unrelated columns changed.');
        }
        $verified = $this->execute('verify');
        self::assertSame(3, $verified['models']);
        self::assertSame(12, $verified['getterChecks']);
        self::assertSame(12, $verified['setterChecks']);
        self::assertSame(12, $verified['persistedSetterChecks']);
        self::assertSame(12, $verified['historyGetterChecks']);
        self::assertSame(12, $verified['calculationChecks']);
        $after = $this->execute('snapshot');
        $again = $this->execute('migrate');
        self::assertSame(0, $again['sqlCount']);
        self::assertSame($after, $this->execute('snapshot'), 'Repeat migration changed state.');
    }

    public function testConflictStopsBeforeAnyRenameOrDefinitionPublication(): void
    {
        $this->execute('conflict');
        $before = $this->execute('snapshot');
        $result = $this->execute('expect-conflict');
        self::assertStringContainsString('Both pimcoreAdjustmentTotalGross and opendxpAdjustmentTotalGross', $result['exception']);
        self::assertSame($before, $this->execute('snapshot'));
    }

    public function testDirectExecutionFailsClosedAndDownIsIrreversible(): void
    {
        $before = $this->execute('snapshot');
        $result = $this->execute('unarmed');
        self::assertStringContainsString('execution context is missing', $result['exception']);
        $result = $this->execute('down');
        self::assertStringContainsString('not one reversible transaction', $result['exception']);
        self::assertSame($before, $this->execute('snapshot'));
    }

    public function testDoctrineConsoleRegistersExecutionListener(): void
    {
        $process = new Process([\PHP_BINARY, 'bin/console', 'doctrine:migrations:execute',
            'CoreShop\\Bundle\\CoreBundle\\Migrations\\Version20261030120000', '--up', '--dry-run', '--no-interaction', '--env=test'], dirname(__DIR__, 3));
        $process->setTimeout(120);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
    }

    public function testSqlFailurePreventsDefinitionPublicationAndRerunCompletes(): void
    {
        $before = $this->execute('snapshot');
        $failure = $this->execute('expect-ddl-error');
        self::assertStringContainsString('missing_adjustment_execution_table', $failure['exception']);
        $partial = $this->execute('snapshot');
        self::assertSame($before['files'], $partial['files'], 'Definitions were published despite a failed SQL queue.');
        self::assertSame($before['versions'], $partial['versions'], 'History changed despite a failed SQL queue.');
        self::assertArrayHasKey('opendxpAdjustmentTotalNet', $partial['rows']['object_store_aeo'][0]);
        $this->execute('migrate');
        self::assertSame(12, $this->execute('verify')['historyGetterChecks']);
    }

    /** @return array<string, mixed> */
    private function execute(string $action): array
    {
        $process = new Process([\PHP_BINARY, __DIR__ . '/support/adjustment_execution.php', $action], dirname(__DIR__, 3));
        $process->setTimeout(120);
        $process->run();
        self::assertTrue($process->isSuccessful(), $action . ': ' . $process->getOutput() . $process->getErrorOutput());

        return json_decode(trim($process->getOutput()), true, 512, \JSON_THROW_ON_ERROR);
    }
}
