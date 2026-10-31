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
use OpenDxp\Cache;
use OpenDxp\Cache\RuntimeCache;
use OpenDxp\Config;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\CustomLayout;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\Layout;
use OpenDxp\Model\DataObject\ClassDefinition\Service as DefinitionService;
use OpenDxp\Model\DataObject\Service;

final class DefinitionUpdater
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /** @param list<string> $names
     * @return array<string, array{definition: ClassDefinition, changed: bool, layouts: array<string, CustomLayout>, before: string, files: array<string, string>}>
     *
     * @psalm-suppress InternalMethod
     */
    public function definitions(array $names): array
    {
        $result = [];
        foreach (array_unique($names) as $name) {
            $original = ClassDefinition::getByName($name);
            if (null === $original) {
                throw new \RuntimeException('Configured adjustment model has no class definition: ' . $name . '.');
            }
            $definition = Service::cloneDefinition($original);
            $definition->setLayoutDefinitions($definition->getLayoutDefinitions());
            $fields = $definition->getFieldDefinitions(['suppressEnrichment' => true]);
            $changed = false;
            foreach (AdjustmentFieldNames::MAP as $old => $new) {
                if (isset($fields[$old], $fields[$new])) {
                    throw new \RuntimeException(sprintf('Both %s and %s exist in definition %s.', $old, $new, $name));
                }
                $field = $fields[$old] ?? $fields[$new] ?? null;
                if (null === $field) {
                    throw new \RuntimeException(sprintf('Neither %s nor %s exists in definition %s.', $old, $new, $name));
                }
                if (!$field instanceof Data\ResourcePersistenceAwareInterface || !$field instanceof Data\QueryResourcePersistenceAwareInterface ||
                    !is_string($field->getColumnType()) || !is_string($field->getQueryColumnType())) {
                    throw new \RuntimeException('Adjustment field requires direct scalar storage/query columns: ' . $name . '.' . $field->getName());
                }
                if (isset($fields[$old])) {
                    $field->setName($new);
                    $changed = true;
                }
            }
            if ($changed) {
                $definition->setLayoutDefinitions($definition->getLayoutDefinitions());
                $definition->setDeletedDataComponents([]);
                $indices = $definition->getCompositeIndices();
                foreach ($indices as &$index) {
                    foreach ($index['index_columns'] as &$column) {
                        $column = AdjustmentFieldNames::MAP[$column] ?? $column;
                    }
                    unset($column);
                }
                unset($index);
                $definition->setCompositeIndices($indices);
                if (!$definition->isWritable()) {
                    throw new \RuntimeException('Class definition is not writable: ' . $name . '.');
                }
            }
            $files = [];
            foreach ([$original->getDefinitionFile(), $original->getPhpClassFile(), $original->getPhpListingClassFile()] as $file) {
                if (is_file($file)) {
                    $contents = file_get_contents($file);
                    if (false === $contents) {
                        throw new \RuntimeException('Cannot back up definition/generated file: ' . $file);
                    }
                    $files[$file] = base64_encode($contents);
                }
                if ($changed && ((!is_file($file) && !is_writable(dirname($file))) || (is_file($file) && !is_writable($file)))) {
                    throw new \RuntimeException('Cannot publish definition/generated file: ' . $file);
                }
            }
            $result[(string) $definition->getId()] = [
                'definition' => $definition,
                'changed' => $changed,
                'layouts' => [],
                'before' => DefinitionService::generateClassDefinitionJson($original),
                'files' => $files,
            ];
        }
        $this->customLayouts($result);

        return $result;
    }

    /** @param array<string, array> $definitions */
    public function publish(array $definitions): void
    {
        foreach ($definitions as $entry) {
            $definition = $entry['definition'];
            if ($entry['changed']) {
                $definition->setModificationDate(time());
                $definition->generateClassFiles(true);
                $this->connection->update('classes', ['definitionModificationDate' => $definition->getModificationDate()], ['id' => $definition->getId()]);
                Cache::clearTag('class_' . $definition->getId());
                RuntimeCache::set('class_' . $definition->getId(), $definition);
            }
            foreach ($entry['layouts'] as $layout) {
                $layout->save();
                RuntimeCache::set('customlayout_' . $layout->getId(), $layout);
            }
        }
    }

    /** @param array<string, array> $definitions
     * @psalm-suppress InternalMethod
     */
    private function customLayouts(array &$definitions): void
    {
        $configuration = Config::getSystemConfiguration();
        $storage = $configuration['config_location']['object_custom_layouts'] ?? [];
        $readTarget = $storage['read_target']['type'] ?? null;
        if (!in_array($readTarget, [null, 'symfony-config', 'settings-store', 'disabled'], true)) {
            throw new \RuntimeException('Unsupported custom-layout read target; migrate layouts explicitly before adjustment fields.');
        }
        if ('disabled' === $readTarget) {
            return;
        }
        $configured = $configuration['objects']['custom_layouts']['definitions'] ?? [];
        if (count($configured) > 1000) {
            throw new \RuntimeException('More than 1000 configured custom layouts; prepare a project-specific bounded layout migration.');
        }
        $ids = [];
        foreach ('settings-store' === $readTarget ? [] : $configured as $id => $layout) {
            if (isset($definitions[(string) ($layout['classId'] ?? '')])) {
                $ids[(string) $id] = true;
            }
        }
        $parameters = ['opendxp_object_custom_layout', ...array_keys($definitions)];
        $placeholders = implode(', ', array_fill(0, count($definitions), '?'));
        $last = '';
        do {
            $rows = 'symfony-config' === $readTarget ? [] : $this->connection->fetchAllAssociative(
                'SELECT id FROM settings_store WHERE scope = ? AND JSON_VALID(data) AND JSON_UNQUOTE(JSON_EXTRACT(data, \'$.classId\')) IN (' . $placeholders . ') AND id > ? ORDER BY id LIMIT 100',
                [...$parameters, $last],
            );
            foreach ($rows as $row) {
                $last = (string) $row['id'];
                if (null === $readTarget && isset($configured[$last])) {
                    continue;
                }
                $ids[$last] = true;
                if (count($ids) > 100) {
                    throw new \RuntimeException('More than 100 affected custom layouts; prepare a project-specific bounded layout migration.');
                }
            }
        } while (100 === count($rows));
        if (count($ids) > 100) {
            throw new \RuntimeException('More than 100 affected custom layouts; prepare a project-specific bounded layout migration.');
        }
        foreach (array_keys($ids) as $id) {
            $original = CustomLayout::getById($id);
            if (null === $original) {
                throw new \RuntimeException('Cannot load custom layout ' . $id . '.');
            }
            $layout = Service::cloneDefinition($original);
            foreach (AdjustmentFieldNames::MAP as $old => $new) {
                if (null !== $layout->getFieldDefinition($old) && null !== $layout->getFieldDefinition($new)) {
                    throw new \RuntimeException(sprintf('Both %s and %s exist in custom layout %s.', $old, $new, $id));
                }
            }
            if (!$this->renameLayout($layout->getLayoutDefinitions())) {
                continue;
            }
            if (!$original->isWriteable()) {
                throw new \RuntimeException('Custom layout is not writable: ' . $id . '.');
            }
            $classId = $layout->getClassId();
            $definitions[$classId]['layouts'][$id] = $layout;
            $definitions[$classId]['layoutBackups'][$id] = base64_encode(serialize($original));
        }
    }

    private function renameLayout(Layout|Data|null $node): bool
    {
        if (null === $node) {
            return false;
        }
        $changed = false;
        if ($node instanceof Data && isset(AdjustmentFieldNames::MAP[$node->getName()])) {
            $node->setName(AdjustmentFieldNames::MAP[$node->getName()]);
            $changed = true;
        }
        if ($node instanceof Layout) {
            foreach ($node->getChildren() as $child) {
                $changed = $this->renameLayout($child) || $changed;
            }
        }

        return $changed;
    }
}
