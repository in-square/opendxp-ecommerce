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

final class MigrationBackup
{
    /** @param array<string, array> $definitions */
    public function save(string $projectDirectory, array $plan, array $definitions): string
    {
        $directory = $projectDirectory . '/var/migrations/adjustment-fields/' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create adjustment migration recovery directory.');
        }
        if (!chmod($directory, 0700)) {
            throw new \RuntimeException('Cannot restrict adjustment migration recovery directory permissions.');
        }
        $snapshots = [];
        foreach ($definitions as $id => $entry) {
            $snapshots[$id] = [
                'definitionJson' => $entry['before'],
                'filesBase64' => $entry['files'],
                'customLayoutsSerializedBase64' => $entry['layoutBackups'] ?? [],
            ];
        }
        $manifest = json_encode(['schema' => $plan, 'definitions' => $snapshots], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $file = $directory . '/manifest.json';
        $stream = fopen($file, 'xb');
        if (false === $stream) {
            throw new \RuntimeException('Cannot create adjustment migration recovery manifest.');
        }

        try {
            if (!chmod($file, 0600)) {
                throw new \RuntimeException('Cannot restrict adjustment migration recovery manifest permissions.');
            }
            $offset = 0;
            while ($offset < strlen($manifest)) {
                $written = fwrite($stream, substr($manifest, $offset));
                if (false === $written || 0 === $written) {
                    throw new \RuntimeException('Cannot write the complete adjustment migration recovery manifest.');
                }
                $offset += $written;
            }
            if (!fflush($stream)) {
                throw new \RuntimeException('Cannot persist adjustment migration recovery manifest.');
            }
            if (function_exists('fsync') && !fsync($stream)) {
                throw new \RuntimeException('Cannot sync adjustment migration recovery manifest.');
            }
        } finally {
            fclose($stream);
        }

        return $directory;
    }
}
