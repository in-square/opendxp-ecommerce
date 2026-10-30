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

use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\AdjustmentFieldNames;
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\AdjustmentSchemaMigration;
use OpenDxp;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Localizedfields;
use OpenDxp\Model\DataObject\ClassDefinition\Layout\Panel;
use OpenDxp\Model\DataObject\Listing\Concrete;
use OpenDxp\Model\DataObject\Localizedfield;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AdjustmentLocalizedListingTest extends TestCase
{
    public function testNativeGeneratedViewsAndLocalizedListing(): void
    {
        if ('1' !== getenv('CORESHOP_ADJUSTMENT_EXECUTION_TESTS')) {
            self::markTestSkipped('Requires the explicitly enabled disposable installed coreshop_test application.');
        }
        $code = 'require "vendor/autoload.php"; require $argv[1]; '
            . '(new \\CoreShop\\Tests\\Integration\\Doctrine\\AdjustmentLocalizedListingTest("testNativeGeneratedViewsAndLocalizedListing"))->verifyNativeFixture(); '
            . 'echo "Native localized listing passed\\n";';
        $process = new Process([\PHP_BINARY, '-r', $code, __FILE__], dirname(__DIR__, 3));
        $process->setTimeout(120);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('Native localized listing passed', $process->getOutput());
    }

    public function verifyNativeFixture(): void
    {
        define('OPENDXP_PROJECT_ROOT', dirname(__DIR__, 3));
        define('OPENDXP_KERNEL_CLASS', 'Kernel');
        (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(OPENDXP_PROJECT_ROOT . '/.env');
        OpenDxp\Bootstrap::startupCli();
        $connection = OpenDxp\Db::get();
        self::assertSame('coreshop_test', $connection->getDatabase());
        $languages = OpenDxp\Tool::getValidLanguages();
        self::assertGreaterThanOrEqual(2, count($languages));
        $id = 'adjustment_native_locale';
        $definition = (new ClassDefinition())->setId($id)->setName('AdjustmentNativeLocale');
        $localized = (new Localizedfields())->setName('localizedfields');
        $localized->setChildren([(new Input())->setName('merchantTitle')]);
        $definition->setLayoutDefinitions((new Panel())->setChildren([$localized]));
        $quote = $connection->quoteIdentifier(...);
        $objectIds = [];

        try {
            $definition->generateClassFiles(true);
            $fields = [];
            foreach (array_keys(AdjustmentFieldNames::MAP) as $old) {
                $fields[] = $quote($old) . ' BIGINT NULL DEFAULT NULL';
            }
            foreach (['object_store_', 'object_query_'] as $prefix) {
                $connection->executeStatement('CREATE TABLE ' . $quote($prefix . $id) . ' (oo_id INT PRIMARY KEY, ' . implode(', ', $fields) . ')');
            }
            foreach ($languages as $language) {
                $connection->executeStatement('CREATE TABLE ' . $quote('object_localized_query_' . $id . '_' . $language)
                    . ' (ooo_id INT PRIMARY KEY, language VARCHAR(20), merchantTitle VARCHAR(190))');
            }
            foreach ([0, -123] as $index => $value) {
                $connection->insert('objects', ['parentId' => 1, 'type' => 'object', '`key`' => $id . $index, 'path' => '/', 'published' => 1, 'classId' => $id, 'className' => 'AdjustmentNativeLocale']);
                $objectId = (int) $connection->lastInsertId();
                $objectIds[] = $objectId;
                $row = ['oo_id' => $objectId];
                foreach (array_keys(AdjustmentFieldNames::MAP) as $old) {
                    $row[$old] = $value;
                }
                foreach (['object_store_', 'object_query_'] as $prefix) {
                    $connection->insert($prefix . $id, $row);
                }
                foreach ($languages as $language) {
                    $connection->insert('object_localized_query_' . $id . '_' . $language, ['ooo_id' => $objectId, 'language' => $language, 'merchantTitle' => $language . ' Łódź ' . $index]);
                }
            }
            (new Localizedfield())->setClass($definition)->getDao()->createLocalizedViews();
            $migration = new AdjustmentSchemaMigration($connection);
            $plan = $migration->plan([$id], $languages);
            self::assertCount(count($languages), $plan['views']);
            foreach ($plan['sql'] as $sql) {
                $connection->executeStatement($sql);
            }
            $migration->verify($plan);
            foreach ($languages as $language) {
                foreach (AdjustmentFieldNames::MAP as $new) {
                    $listing = new class($definition) extends Concrete {
                        public function __construct(
                            private readonly ClassDefinition $definition,
                        ) {
                            parent::__construct();
                            $this->setClassId($definition->getId());
                            $this->setClassName($definition->getName());
                        }

                        public function getClass(): ClassDefinition
                        {
                            return $this->definition;
                        }
                    };
                    $listing->setLocale($language);
                    $listing->setCondition($quote($new) . ' <= ?', [0]);
                    $listing->setOrderKey($new);
                    $listing->setOrder('ASC');
                    $listing->setLimit(2);
                    self::assertSame(array_reverse($objectIds), $listing->loadIdList());
                }
            }
            self::assertSame([], $migration->plan([$id], $languages)['sql']);
        } finally {
            foreach ([$definition->getDefinitionFile(), $definition->getPhpClassFile(), $definition->getPhpListingClassFile()] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ($languages as $language) {
                $connection->executeStatement('DROP VIEW IF EXISTS ' . $quote('object_localized_' . $id . '_' . $language));
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $quote('object_localized_query_' . $id . '_' . $language));
            }
            foreach (['object_store_', 'object_query_'] as $prefix) {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $quote($prefix . $id));
            }
            foreach ($objectIds as $objectId) {
                $connection->delete('objects', ['id' => $objectId]);
            }
        }
    }
}
