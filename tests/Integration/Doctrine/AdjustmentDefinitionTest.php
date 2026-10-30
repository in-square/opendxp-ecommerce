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
use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\DefinitionUpdater;
use OpenDxp;
use OpenDxp\Cache\RuntimeCache;
use OpenDxp\Config;
use OpenDxp\Db;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\CustomLayout;
use OpenDxp\Model\DataObject\ClassDefinition\Data\Input;
use OpenDxp\Model\DataObject\ClassDefinition\Layout\Panel;
use OpenDxp\Model\DataObject\ClassDefinition\Service as DefinitionService;
use OpenDxp\Model\DataObject\Service;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AdjustmentDefinitionTest extends TestCase
{
    public function testPreservesCustomizedDefinitionAndLayoutWithoutSynchronizingSchema(): void
    {
        if (!getenv('OPENDXP_TEST_DB_DSN')) {
            self::markTestSkipped('Requires an installed, disposable CoreShop test application.');
        }
        $code = 'require "vendor/autoload.php"; require $argv[1]; '
            . '(new \\CoreShop\\Tests\\Integration\\Doctrine\\AdjustmentDefinitionTest("testPreservesCustomizedDefinitionAndLayoutWithoutSynchronizingSchema"))->verifyDefinitionFixture(); '
            . 'echo "Definition fixture passed\\n";';
        $process = new Process([\PHP_BINARY, '-r', $code, __FILE__], dirname(__DIR__, 3));
        $process->setTimeout(120);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('Definition fixture passed', $process->getOutput());
    }

    public function verifyDefinitionFixture(): void
    {
        if (!defined('OPENDXP_CONSOLE')) {
            define('OPENDXP_CONSOLE', true);
        }
        if (!defined('OPENDXP_PROJECT_ROOT')) {
            define('OPENDXP_PROJECT_ROOT', dirname(__DIR__, 3));
        }
        if (!defined('OPENDXP_KERNEL_CLASS')) {
            define('OPENDXP_KERNEL_CLASS', 'Kernel');
        }
        (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(OPENDXP_PROJECT_ROOT . '/.env');
        OpenDxp\Bootstrap::startupCli();
        $connection = Db::get();
        self::assertSame('coreshop_test', $connection->getDatabase());
        $config = Config::getSystemConfiguration();
        $testConfig = $config;
        $testConfig['config_location']['object_custom_layouts'] = ['write_target' => ['type' => 'settings-store']];
        Config::setSystemConfiguration($testConfig);
        $source = ClassDefinition::getByName('CoreShopOrder');
        self::assertNotNull($source);
        $definition = Service::cloneDefinition($source);
        $definition->setLayoutDefinitions($definition->getLayoutDefinitions());
        $definition->setId('adjdef');
        $definition->setName('AdjustmentDefinitionFixture');
        $definition->setAllowInherit(true);
        $definition->setAllowVariants(true);
        $definition->setDescription('Customized merchant order definition');
        $definition->setImplementsInterfaces('\\' . \CoreShop\Component\Core\Model\OrderInterface::class);
        $fields = $definition->getFieldDefinitions();
        foreach (AdjustmentFieldNames::MAP as $old => $new) {
            $fields[$new]->setName($old);
        }
        $input = (new Input())->setName('merchantReference')->setTitle('Merchant reference')->setColumnLength(190);
        $panel = (new Panel())->setName('merchantPanel')->setTitle('Merchant settings');
        $panel->setChildren([$input]);
        $root = $definition->getLayoutDefinitions();
        $root->setChildren([...$root->getChildren(), $panel]);
        $definition->setLayoutDefinitions($root);
        $definition->setDeletedDataComponents([]);
        $layout = null;

        try {
            $definition->save();
            $layout = CustomLayout::create(['name' => 'Adjustment merchant layout', 'classId' => $definition->getId(), 'userOwner' => 0, 'userModification' => 0]);
            $layout->setLayoutDefinitions(Service::cloneDefinition($definition->getLayoutDefinitions()));
            $layout->save();
            RuntimeCache::clear();
            $beforeJson = json_decode(DefinitionService::generateClassDefinitionJson($definition), true, 512, \JSON_THROW_ON_ERROR);
            $table = 'object_store_' . $definition->getId();
            $connection->executeStatement('ALTER TABLE ' . $connection->quoteIdentifier($table) . ' ADD unmanaged_custom_column VARCHAR(30) DEFAULT \'retained\'');
            $updater = new DefinitionUpdater($connection);
            $prepared = $updater->definitions([$definition->getName()]);
            $entry = $prepared[$definition->getId()];
            self::assertTrue($entry['changed']);
            self::assertCount(1, $entry['layouts']);
            self::assertArrayHasKey($layout->getId(), $entry['layoutBackups']);
            self::assertSame($beforeJson, json_decode(DefinitionService::generateClassDefinitionJson(ClassDefinition::getById($definition->getId(), true)), true, 512, \JSON_THROW_ON_ERROR));
            $schema = new AdjustmentSchemaMigration($connection);
            $plan = $schema->plan([$definition->getId()]);
            foreach ($plan['sql'] as $sql) {
                $connection->executeStatement($sql);
            }
            $schema->verify($plan);
            $viewBefore = $connection->fetchAssociative('SHOW CREATE VIEW object_' . $definition->getId());
            $updater->publish($prepared);
            self::assertSame($viewBefore, $connection->fetchAssociative('SHOW CREATE VIEW object_' . $definition->getId()));
            self::assertArrayHasKey('unmanaged_custom_column', $connection->createSchemaManager()->listTableColumns($table));
            RuntimeCache::clear();
            $reloaded = ClassDefinition::getById($definition->getId(), true);
            self::assertNotNull($reloaded);
            self::assertTrue($reloaded->getAllowInherit());
            self::assertTrue($reloaded->getAllowVariants());
            self::assertSame($definition->getParentClass(), $reloaded->getParentClass());
            self::assertSame($definition->getImplementsInterfaces(), $reloaded->getImplementsInterfaces());
            self::assertSame('Customized merchant order definition', $reloaded->getDescription());
            self::assertSame('Merchant reference', $reloaded->getFieldDefinition('merchantReference')->getTitle());
            foreach (AdjustmentFieldNames::MAP as $old => $new) {
                self::assertNull($reloaded->getFieldDefinition($old));
                self::assertNotNull($reloaded->getFieldDefinition($new));
            }
            $afterJson = json_decode(DefinitionService::generateClassDefinitionJson($reloaded), true, 512, \JSON_THROW_ON_ERROR);
            $this->mapDefinition($beforeJson);
            unset($beforeJson['modificationDate'], $afterJson['modificationDate']);
            self::assertSame($beforeJson, $afterJson);
            $reloadedLayout = CustomLayout::getById($layout->getId());
            self::assertSame('Adjustment merchant layout', $reloadedLayout->getName());
            foreach (AdjustmentFieldNames::MAP as $old => $new) {
                self::assertNotNull($reloadedLayout->getFieldDefinition($new));
                self::assertNull($reloadedLayout->getFieldDefinition($old));
            }
            $class = 'OpenDxp\\Model\\DataObject\\AdjustmentDefinitionFixture';
            $object = new $class();
            $object->setOpenDxpAdjustmentTotalNet(-41);
            $object->setOpenDxpAdjustmentTotalGross(0);
            $object->setConvertedOpenDxpAdjustmentTotalNet(9000000000000000);
            $object->setConvertedOpenDxpAdjustmentTotalGross(-52);
            self::assertSame(-41, $object->getOpenDxpAdjustmentTotalNet());
            self::assertSame(0, $object->getOpenDxpAdjustmentTotalGross());
            self::assertSame(9000000000000000, $object->getConvertedOpenDxpAdjustmentTotalNet());
            self::assertSame(-52, $object->getConvertedOpenDxpAdjustmentTotalGross());
            $again = $updater->definitions([$definition->getName()]);
            self::assertFalse($again[$definition->getId()]['changed']);
            self::assertSame([], $again[$definition->getId()]['layouts']);
        } finally {
            if (null !== $layout) {
                $layout->delete();
            }
            $definition->delete();
            Config::setSystemConfiguration($config);
        }
    }

    private function mapDefinition(array &$node): void
    {
        foreach ($node as $key => &$value) {
            if (is_array($value)) {
                $this->mapDefinition($value);
            } elseif ('name' === $key && is_string($value)) {
                $value = AdjustmentFieldNames::MAP[$value] ?? $value;
            }
        }
    }
}
