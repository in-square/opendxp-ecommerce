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

namespace CoreShop\Behat\Service;

use CoreShop\Bundle\CoreBundle\Installer;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

class Setup
{
    protected static bool $opendxpSetupDone = false;

    protected static bool $coreShopSetupDone = false;

    public static function setupOpenDxp(): void
    {
        if (getenv('CORESHOP_SKIP_DB_SETUP')) {
            return;
        }

        if (static::$opendxpSetupDone) {
            return;
        }

        $container = \OpenDxp::getContainer();
        if (!in_array($container->getParameter('kernel.environment'), ['test', 'test_precision'], true)) {
            throw new \LogicException('CoreShop database setup is only available in a test environment.');
        }

        $testDsn = $_SERVER['OPENDXP_TEST_DB_DSN'] ?? $_ENV['OPENDXP_TEST_DB_DSN'] ?? getenv('OPENDXP_TEST_DB_DSN');
        if (!is_string($testDsn) || '' === $testDsn) {
            throw new \LogicException('Set OPENDXP_TEST_DB_DSN to a dedicated disposable database before running CoreShop database setup.');
        }

        $connection = $container->get('database_connection');
        $testParams = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($testDsn);
        $connectionParams = $connection->getParams();
        foreach (['host', 'port', 'dbname', 'user'] as $parameter) {
            if ((string) ($testParams[$parameter] ?? '') !== (string) ($connectionParams[$parameter] ?? '')) {
                throw new \LogicException('The active connection does not match OPENDXP_TEST_DB_DSN. Refusing to reset the database.');
            }
        }

        if (null === $connection) {
            throw new \Exception('Database connection not found');
        }

        $dbName = $connection->getParams()['dbname'];
        $params = $connection->getParams();
        $config = $connection->getConfiguration();

        unset($params['url'], $params['dbname']);

        // use a dedicated setup connection as the framework connection is bound to the DB and will
        // fail if the DB doesn't exist
        $setupConnection = DriverManager::getConnection($params, $config);
        $schemaManager = $setupConnection->createSchemaManager();

        $databases = $schemaManager->listDatabases();
        if (in_array($dbName, $databases, true)) {
            $schemaManager->dropDatabase($connection->quoteIdentifier($dbName));
        }

        $schemaManager->createDatabase($connection->quoteIdentifier($dbName));

        if (!$connection->isConnected()) {
            $connection->connect();
        }

        $installer = new \OpenDxp\Bundle\InstallBundle\Installer(
            \OpenDxp::getContainer()->get('monolog.logger.opendxp'),
            \OpenDxp::getContainer()->get('event_dispatcher'),
        );

        $errors = $installer->setupDatabase($connection, [
            'username' => 'admin',
            'password' => 'coreshop',
        ]);
        if ([] !== $errors) {
            throw new \RuntimeException('OpenDXP test database setup failed.');
        }
        static::$opendxpSetupDone = true;
    }

    public static function setupDone(): bool
    {
        return getenv('CORESHOP_SKIP_DB_SETUP') || (static::$opendxpSetupDone && static::$coreShopSetupDone);
    }

    public static function setupCoreShop(): void
    {
        if (getenv('CORESHOP_SKIP_DB_SETUP')) {
            return;
        }

        if (static::$coreShopSetupDone) {
            return;
        }

        $installer = \OpenDxp::getContainer()->get(Installer::class);
        $installer->install();

        static::$coreShopSetupDone = true;
    }
}
