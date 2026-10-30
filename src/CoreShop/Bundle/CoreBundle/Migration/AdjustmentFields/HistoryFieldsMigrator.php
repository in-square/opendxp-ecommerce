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

namespace CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields;

use Doctrine\DBAL\Connection;
use OpenDxp\Model\Version;
use OpenDxp\Model\Version\Adapter\VersionStorageAdapterInterface;

final class HistoryFieldsMigrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VersionStorageAdapterInterface $storage,
        private readonly SerializedAdjustmentFieldsConverter $converter,
    ) {
    }

    /** @param list<string> $classIds
     * @param list<string> $classNames
     */
    public function validate(array $classIds, array $classNames): int
    {
        if ($this->connection->fetchOne('SELECT v.id FROM versions v LEFT JOIN objects o ON o.id = v.cid WHERE v.ctype = ? AND o.id IS NULL LIMIT 1', ['object'])) {
            throw new \RuntimeException('Orphan object versions cannot be classified safely. Resolve or separately migrate their history before migrating adjustment fields.');
        }

        return $this->scan($classIds, $classNames, null);
    }

    /** @param list<string> $classIds
     * @param list<string> $classNames
     */
    public function migrate(array $classIds, array $classNames, string $backupDirectory): int
    {
        $changes = $this->validate($classIds, $classNames);
        if (0 === $changes) {
            return 0;
        }
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
            throw new \RuntimeException('Cannot create the private history backup directory.');
        }
        if (!is_writable($backupDirectory)) {
            throw new \RuntimeException('The history backup directory is not writable.');
        }

        return $this->scan($classIds, $classNames, $backupDirectory);
    }

    /** @param list<string> $classIds
     * @param list<string> $classNames
     */
    private function scan(array $classIds, array $classNames, ?string $backupDirectory): int
    {
        $classIds = array_values(array_unique($classIds));
        if ([] === $classIds || count($classIds) > 3 || [] === $classNames || count($classNames) > 3) {
            throw new \RuntimeException('Expected one to three configured adjustment model classes.');
        }
        $placeholders = implode(', ', array_fill(0, count($classIds), '?'));
        $lastId = 0;
        $changes = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT v.id, v.cid, v.serialized, v.storageType, v.binaryFileId FROM versions v INNER JOIN objects o ON o.id = v.cid '
                . 'WHERE v.ctype = ? AND o.classId IN (' . $placeholders . ') AND v.id > ? ORDER BY v.id LIMIT 100',
                ['object', ...$classIds, $lastId],
            );
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];

                try {
                    if (!(bool) $row['serialized']) {
                        throw new \RuntimeException('Object version is not serialized.');
                    }
                    $version = (new Version())->setId($lastId)->setCid((int) $row['cid'])->setCtype('object')->setSerialized(true);
                    $version->setStorageType((string) $row['storageType']);
                    $payload = $this->storage->loadMetaData($version);
                    if (null === $payload || '' === $payload) {
                        throw new \RuntimeException('Version payload is missing.');
                    }
                    $converted = $this->converter->convert($payload, $classNames);
                    if (null === $converted) {
                        continue;
                    }
                    if (null !== ($row['binaryFileId'] ?? null)) {
                        throw new \RuntimeException('Object version has an unexpected binary reference; a separately tested migration is required.');
                    }
                    $binaryData = 'db' === $version->getStorageType()
                        ? (bool) $this->connection->fetchOne('SELECT binaryData IS NOT NULL FROM versionsData WHERE id = ? AND cid = ? AND ctype = ?', [$lastId, $version->getCid(), 'object'])
                        : $this->storage->loadBinaryData($version);
                    if (null !== $binaryData && false !== $binaryData) {
                        if (is_resource($binaryData)) {
                            fclose($binaryData);
                        }

                        throw new \RuntimeException('Object version has unexpected binary data; a separately tested migration is required.');
                    }
                    ++$changes;
                    if (null !== $backupDirectory) {
                        $this->backup($backupDirectory . '/' . $lastId . '.serialized', $payload);
                        $this->backup($backupDirectory . '/' . $lastId . '.json', json_encode($row, \JSON_THROW_ON_ERROR));
                        if ($this->storage->loadMetaData($version) !== $payload) {
                            throw new \RuntimeException('Version changed while migrating; keep all application writers stopped.');
                        }
                        $this->storage->save($version, $converted, null);
                    }
                } catch (\Throwable $exception) {
                    throw new \RuntimeException(sprintf('Adjustment history version %d: %s', $lastId, $exception->getMessage()), 0, $exception);
                }
            }
        } while (100 === count($rows));

        return $changes;
    }

    private function backup(string $path, string $contents): void
    {
        if (is_file($path)) {
            if (file_get_contents($path) !== $contents) {
                throw new \RuntimeException('Existing history backup does not match the source payload; inspect recovery state before retrying.');
            }

            return;
        }
        $stream = fopen($path, 'x+b');
        if (false === $stream) {
            throw new \RuntimeException('Cannot exclusively create the history backup.');
        }

        try {
            if (!chmod($path, 0600)) {
                throw new \RuntimeException('Cannot restrict history backup permissions.');
            }
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($stream, substr($contents, $offset));
                if (false === $written || 0 === $written) {
                    throw new \RuntimeException('Cannot write the complete history backup.');
                }
                $offset += $written;
            }
            if (!fflush($stream)) {
                throw new \RuntimeException('Cannot flush the history backup.');
            }
        } finally {
            fclose($stream);
        }
    }
}
