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

final class ViewSqlRewriter
{
    /** @return list<array{value: string, raw: string, type: string}> */
    private function tokens(string $sql): array
    {
        $tokens = [];
        $offset = 0;
        $pattern = '/\G(?:`((?:``|[^`])*)`|\'((?:\\\\.|\'\'|[^\'\\\\])*)\'|"((?:\\\\.|""|[^"\\\\])*)"|([a-zA-Z_][a-zA-Z0-9_$]*)|(\s+)|(.))/As';
        while ($offset < strlen($sql)) {
            if (1 !== preg_match($pattern, $sql, $match, \PREG_UNMATCHED_AS_NULL, $offset)) {
                throw new \RuntimeException('Cannot tokenize view SQL safely.');
            }
            $raw = $match[0];
            $tokens[] = [
                'value' => null !== $match[1] ? str_replace('``', '`', $match[1]) : ($match[4] ?? $raw),
                'raw' => $raw,
                'type' => null !== $match[1] ? 'identifier' : (null !== $match[2] || null !== $match[3] ? 'string' : (null !== $match[5] ? 'space' : (null !== ($match[4] ?? null) ? 'word' : 'symbol'))),
            ];
            $offset += strlen($raw);
        }

        return $tokens;
    }

    /** @return array<string, string> */
    public function references(string $sql, string $database, bool $strict = true): array
    {
        $tokens = array_values(array_filter($this->tokens($sql), static fn (array $token): bool => 'space' !== $token['type']));
        $references = [];
        foreach ($tokens as $index => $token) {
            if ('word' !== $token['type'] || !in_array(strtoupper($token['value']), ['FROM', 'JOIN'], true)) {
                continue;
            }
            $position = $index + 1;
            while ('(' === ($tokens[$position]['value'] ?? null)) {
                ++$position;
            }
            if ('SELECT' === strtoupper($tokens[$position]['value'] ?? '')) {
                continue;
            }
            $first = $tokens[$position] ?? null;
            if (null === $first || !in_array($first['type'], ['identifier', 'word'], true)) {
                throw new \RuntimeException('Unsupported relation in view SQL.');
            }
            $table = $first['value'];
            ++$position;
            if ('.' === ($tokens[$position]['value'] ?? null)) {
                $table = $table === $database ? ($tokens[$position + 1]['value'] ?? '') : $table . '.' . ($tokens[$position + 1]['value'] ?? '');
                $position += 2;
            }
            if ($strict && isset($references[$table]) && $references[$table] !== $table) {
                throw new \RuntimeException('Ambiguous view table qualifier.');
            }
            $references[$table] = $table;
            if ('AS' === strtoupper($tokens[$position]['value'] ?? '')) {
                ++$position;
            }
            if ('identifier' === ($tokens[$position]['type'] ?? null)) {
                $alias = $tokens[$position]['value'];
                if ($strict && isset($references[$alias]) && $references[$alias] !== $table) {
                    throw new \RuntimeException('Reused view alias across scopes requires an explicit project migration.');
                }
                $references[$alias] = $table;
            }
        }

        return $references;
    }

    /** @param array<string, array<string, string>> $sourceFields */
    public function rewrite(string $sql, string $database, array $sourceFields, bool $standardObjectView = false): string
    {
        $references = $this->references($sql, $database);
        $tokens = $this->tokens($sql);
        $significant = [];
        foreach ($tokens as $index => $token) {
            if ('space' !== $token['type']) {
                $significant[] = $index;
            }
        }
        foreach ($significant as $position => $index) {
            $token = $tokens[$index];
            if (!in_array($token['type'], ['identifier', 'word'], true) || !isset(AdjustmentFieldNames::MAP[$token['value']])) {
                continue;
            }
            $previous = $position > 0 ? $tokens[$significant[$position - 1]]['value'] : '';
            if ('.' === $previous) {
                $qualifier = $position >= 2 ? $tokens[$significant[$position - 2]]['value'] : '';
                $source = $references[$qualifier] ?? null;
                if (null === $source) {
                    throw new \RuntimeException('Cannot resolve view column qualifier ' . $qualifier . '.');
                }
                if (isset($sourceFields[$source][$token['value']])) {
                    $tokens[$index]['raw'] = '`' . $sourceFields[$source][$token['value']] . '`';
                }
            } elseif ('AS' === strtoupper($previous)) {
                $expression = $position >= 2 ? $tokens[$significant[$position - 2]] : [];
                if ($standardObjectView && ($expression['raw'] ?? '') === '`' . AdjustmentFieldNames::MAP[$token['value']] . '`' &&
                    $position >= 3 && '.' === $tokens[$significant[$position - 3]]['value']) {
                    $tokens[$index]['raw'] = '`' . AdjustmentFieldNames::MAP[$token['value']] . '`';
                }
            } else {
                throw new \RuntimeException(sprintf('Unqualified legacy identifier %s in view SQL; resolve it explicitly before migrating.', $token['value']));
            }
        }
        $rewritten = implode('', array_column($tokens, 'raw'));
        if (!str_starts_with(strtoupper($rewritten), 'CREATE ')) {
            throw new \RuntimeException('Expected SHOW CREATE VIEW output.');
        }

        return 'CREATE OR REPLACE ' . substr($rewritten, 7);
    }
}
