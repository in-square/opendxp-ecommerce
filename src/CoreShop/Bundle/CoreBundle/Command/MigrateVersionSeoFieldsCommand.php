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

namespace CoreShop\Bundle\CoreBundle\Command;

use CoreShop\Bundle\CoreBundle\Migration\VersionSeoFieldsConverter;
use Doctrine\DBAL\Connection;
use OpenDxp\Model\Version;
use OpenDxp\Model\Version\Adapter\VersionStorageAdapterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class MigrateVersionSeoFieldsCommand extends Command
{
    public function __construct(
        private Connection $connection,
        private VersionStorageAdapterInterface $storage,
        private VersionSeoFieldsConverter $converter,
        private string $productClass,
        private string $categoryClass,
    ) {
        parent::__construct('coreshop:migrate:version-seo-fields');
    }

    protected function configure(): void
    {
        $this->setDescription('Migrate historical product/category SEO field keys after native OpenDXP version namespace migration.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write converted payloads. Without this option only validate and report.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $classNames = [$this->productClass, $this->categoryClass];
        $shortNames = array_map(static fn (string $class): string => basename(str_replace('\\', '/', $class)), $classNames);
        $lastId = 0;
        $checked = 0;
        $changed = 0;
        $apply = (bool) $input->getOption('apply');


        for ($pass = 0; $pass < ($apply ? 2 : 1); ++$pass) {
            $lastId = 0;
            $checked = 0;
            $changed = 0;
            do {

                $rows = $this->connection->fetchAllAssociative(
                    'SELECT v.* FROM versions v INNER JOIN objects o ON o.id = v.cid '
                    . 'INNER JOIN classes c ON c.id = o.classId '
                    . 'WHERE v.ctype = ? AND c.name IN (?, ?) AND v.id > ? ORDER BY v.id LIMIT 100',
                    ['object', ...$shortNames, $lastId],
                );

                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $version = (new Version())->setId($lastId)->setCid((int) $row['cid'])->setCtype('object')
                        ->setSerialized((bool) $row['serialized'])
                    ;
                    $version->setStorageType((string) $row['storageType']);

                    try {
                        if (!$version->getSerialized()) {
                            throw new \RuntimeException('Object version is not serialized.');
                        }
                        $payload = $this->storage->loadMetaData($version);
                        if (null === $payload || '' === $payload) {
                            throw new \RuntimeException('Version payload is missing.');
                        }
                        $converted = $this->converter->convert($payload, $classNames);
                        ++$checked;
                        if (null !== $converted) {
                            ++$changed;
                            if ($apply && 1 === $pass) {

                                $this->storage->save($version, $converted, null);
                            }
                        }
                    } catch (\RuntimeException $exception) {
                        $io->error(sprintf('Version %d: %s', $lastId, $exception->getMessage()));

                        return Command::FAILURE;
                    }
                }
            } while (100 === count($rows));
        }

        $io->success(sprintf('%s: checked %d versions; %d payloads %s.', $apply ? 'Applied' : 'Dry run', $checked, $changed, $apply ? 'converted' : 'would change'));

        return Command::SUCCESS;
    }
}
