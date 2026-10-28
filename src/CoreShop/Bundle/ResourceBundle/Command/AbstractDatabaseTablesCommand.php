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

namespace CoreShop\Bundle\ResourceBundle\Command;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Command\Command;

abstract class AbstractDatabaseTablesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    /**
     * @param list<ClassMetadata> $metadatas
     *
     * @return list<string>
     */
    protected function createDiffSchemaSqls(array $metadatas, bool $down): array
    {
        $schemaTool = new SchemaTool($this->entityManager);
        $coreShopSchema = $schemaTool->getSchemaFromMetadata($metadatas);
        $tableNames = array_map(
            static fn (Table $table): string => $table->getName(),
            $coreShopSchema->getTables(),
        );

        $connection = $this->entityManager->getConnection();
        $configuration = $connection->getConfiguration();
        $previousFilter = $configuration->getSchemaAssetsFilter();

        $configuration->setSchemaAssetsFilter(
            static function (mixed $tableName) use ($tableNames): bool {
                if ($tableName instanceof AbstractAsset) {
                    $tableName = $tableName->getName();
                }

                if (!is_string($tableName)) {
                    throw new \TypeError(sprintf(
                        'The table name must be an instance of "%s" or a string ("%s" given).',
                        AbstractAsset::class,
                        get_debug_type($tableName),
                    ));
                }

                return in_array($tableName, $tableNames, true);
            },
        );

        try {
            $schemaManager = $connection->createSchemaManager();
            $comparator = $schemaManager->createComparator();
            $deployedSchema = $schemaManager->introspectSchema();
            $schemaDiff = $down
                ? $comparator->compareSchemas($deployedSchema, new Schema())
                : $comparator->compareSchemas($deployedSchema, $coreShopSchema);

            return $connection->getDatabasePlatform()->getAlterSchemaSQL($schemaDiff);
        } finally {
            $configuration->setSchemaAssetsFilter($previousFilter);
        }
    }
}
