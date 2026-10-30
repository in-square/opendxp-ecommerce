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

final class SerializedAdjustmentFieldsConverter
{
    private string $payload = '';

    private int $offset = 0;

    /** @var array<int, array{int, string}> */
    private array $replacements = [];

    /** @param list<string> $classes */
    public function convert(string $payload, array $classes): ?string
    {
        $this->payload = $payload;
        $this->offset = 0;
        $this->replacements = [];
        if (!str_starts_with($payload, 'O:')) {
            throw new \RuntimeException('Expected a native serialized object version.');
        }

        $this->value(0, $classes);
        if ($this->offset !== strlen($payload)) {
            throw new \RuntimeException('Unexpected trailing serialized version data.');
        }
        if ([] === $this->replacements) {
            return null;
        }

        krsort($this->replacements);
        foreach ($this->replacements as $offset => [$length, $replacement]) {
            $payload = substr_replace($payload, $replacement, $offset, $length);
        }

        return $payload;
    }

    /** @param list<string> $classes */
    private function value(int $depth, array $classes): void
    {
        if ($depth > 512) {
            throw new \RuntimeException('Serialized version exceeds the supported nesting depth.');
        }
        $type = $this->take(1);
        if ('N' === $type) {
            $this->expect(';');

            return;
        }
        $this->expect(':');
        if ('s' === $type || 'E' === $type) {
            $this->stringBody(';');

            return;
        }
        if (in_array($type, ['i', 'b', 'd', 'r', 'R'], true)) {
            $end = strpos($this->payload, ';', $this->offset);
            if (false === $end) {
                throw new \RuntimeException('Truncated serialized scalar.');
            }
            $scalar = substr($this->payload, $this->offset, $end - $this->offset);
            $pattern = match ($type) {
                'b' => '/^[01]$/D',
                'r', 'R' => '/^[1-9][0-9]*$/D',
                'd' => '/^(?:-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:E[+-]?[0-9]+)?|NAN|-?INF)$/iD',
                default => '/^-?[0-9]+$/D',
            };
            if (1 !== preg_match($pattern, $scalar)) {
                throw new \RuntimeException('Invalid serialized scalar.');
            }
            $this->offset = $end + 1;

            return;
        }
        if ('O' === $type) {
            $class = $this->stringBody(':');
            if (0 === $depth && !in_array($class, $classes, true)) {
                throw new \RuntimeException('Version class does not match the configured OpenDXP models; complete the native version namespace migration first.');
            }
        } elseif ('a' !== $type) {
            throw new \RuntimeException('Unsupported serialized token; custom version serialization needs a separately tested migration.');
        }

        $count = $this->number();
        $this->expect(':{');
        $keys = [];
        for ($index = 0; $index < $count; ++$index) {
            $start = $this->offset;
            $key = $this->key();
            $length = $this->offset - $start;
            $name = is_string($key) ? substr($key, (int) strrpos("\0" . $key, "\0")) : '';
            if (0 === $depth && (isset(AdjustmentFieldNames::MAP[$name]) || in_array($name, AdjustmentFieldNames::MAP, true))) {
                $target = AdjustmentFieldNames::MAP[$name] ?? $name;
                if (isset($keys[$target])) {
                    throw new \RuntimeException('Both historical and OpenDXP adjustment keys, or duplicate adjustment keys, exist in one version.');
                }
                $keys[$target] = true;
                if (isset(AdjustmentFieldNames::MAP[$name])) {
                    $newKey = substr($key, 0, strlen($key) - strlen($name)) . $target;
                    $this->replacements[$start] = [$length, 's:' . strlen($newKey) . ':"' . $newKey . '";'];
                }
            }
            $this->value($depth + 1, $classes);
        }
        $this->expect('}');
    }

    private function key(): string|int
    {
        $type = $this->take(1);
        $this->expect(':');
        if ('s' === $type) {
            return $this->stringBody(';');
        }
        if ('i' === $type) {
            $end = strpos($this->payload, ';', $this->offset);
            if (false === $end || 1 !== preg_match('/^-?[0-9]+$/D', substr($this->payload, $this->offset, $end - $this->offset))) {
                throw new \RuntimeException('Invalid serialized numeric key.');
            }
            $key = (int) substr($this->payload, $this->offset, $end - $this->offset);
            $this->offset = $end + 1;

            return $key;
        }

        throw new \RuntimeException('Invalid serialized key.');
    }

    private function stringBody(string $terminator): string
    {
        $length = $this->number();
        $this->expect(':"');
        $string = $this->take($length);
        $this->expect('"' . $terminator);

        return $string;
    }

    private function number(): int
    {
        $end = strpos($this->payload, ':', $this->offset);
        if (false === $end) {
            throw new \RuntimeException('Truncated serialized length.');
        }
        $number = substr($this->payload, $this->offset, $end - $this->offset);
        if (1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $number) || strlen($number) > 9) {
            throw new \RuntimeException('Invalid serialized length.');
        }
        $this->offset = $end;

        return (int) $number;
    }

    private function expect(string $expected): void
    {
        if ($this->take(strlen($expected)) !== $expected) {
            throw new \RuntimeException('Malformed serialized version payload.');
        }
    }

    private function take(int $length): string
    {
        if ($length > strlen($this->payload) - $this->offset) {
            throw new \RuntimeException('Truncated serialized version payload.');
        }
        $value = substr($this->payload, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }
}
