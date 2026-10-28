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

namespace CoreShop\Bundle\CoreBundle\Migration;


final class VersionSeoFieldsConverter
{
    private const FIELDS = ['pimcoreMetaTitle' => 'opendxpMetaTitle', 'pimcoreMetaDescription' => 'opendxpMetaDescription'];

    private string $payload = '';

    private int $offset = 0;

    /** @var array<int, string> */
    private array $replacements = [];

    /** @var list<string> */
    private array $classes = [];

    /** @param list<string> $classes */
    public function convert(string $payload, array $classes): ?string
    {
        $this->payload = $payload;
        $this->offset = 0;
        $this->replacements = [];
        $this->classes = $classes;
        if (!str_starts_with($payload, 'O:')) {
            throw new \RuntimeException('Expected a native serialized object payload.');
        }
        $this->value('root', 0);
        if ($this->offset !== strlen($payload)) {
            throw new \RuntimeException('Unexpected trailing serialized data.');
        }

        /** @var array<int, string> $replacements */
        $replacements = $this->replacements;
        if ([] === $replacements) {
            return null;
        }
        foreach ($replacements as $offset => $replacement) {

            $payload = substr_replace($payload, $replacement, $offset, strlen($replacement));
        }

        return $payload;
    }

    private function value(string $context, int $depth): void
    {
        if ($depth > 512) {
            throw new \RuntimeException('Serialized payload exceeds the supported nesting depth.');
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
        if (in_array($type, ['r', 'R'], true) && in_array($context, ['localized', 'locales', 'fields'], true)) {
            throw new \RuntimeException('Referenced SEO field containers require a separate migration.');
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
            [$class] = $this->stringBody(':');
            if ('root' === $context && !in_array($class, $this->classes, true)) {
                throw new \RuntimeException('Version class does not match the configured OpenDXP models; run the native namespace migration first.');
            }
            if ('localized' === $context && 'OpenDxp\\Model\\DataObject\\Localizedfield' !== $class) {
                throw new \RuntimeException('Unexpected localized-field payload class.');
            }
        } elseif ('a' !== $type) {
            throw new \RuntimeException('Unsupported serialized token; custom serialization requires a separate migration.');
        }
        $count = $this->number();
        $this->expect(':{');
        $keys = [];
        for ($index = 0; $index < $count; ++$index) {
            [$key, $start] = $this->key();
            $name = is_string($key) ? substr($key, (int) strrpos("\0" . $key, "\0")) : '';
            $scoped = 'root' === $context || 'fields' === $context;
            if ($scoped && (isset(self::FIELDS[$name]) || in_array($name, self::FIELDS, true))) {
                $target = self::FIELDS[$name] ?? $name;
                if (isset($keys[$target])) {
                    throw new \RuntimeException('Both historical and OpenDXP SEO keys exist in one field container.');
                }
                $keys[$target] = true;
                if (isset(self::FIELDS[$name])) {
                    $this->replacements[$start + strlen((string) $key) - strlen($name)] = $target;
                }
            }
            $child = match (true) {
                'root' === $context && 'localizedfields' === $name => 'localized',
                'localized' === $context && 'items' === $name => 'locales',
                'locales' === $context => 'fields',
                default => 'other',
            };
            $this->value($child, $depth + 1);
        }
        $this->expect('}');
    }

    /** @return array{string|int, int} */
    private function key(): array
    {
        $type = $this->take(1);
        $this->expect(':');
        if ('s' === $type) {
            return $this->stringBody(';');
        }
        if ('i' === $type) {
            $start = $this->offset;
            $end = strpos($this->payload, ';', $start);
            if (false === $end || 1 !== preg_match('/^-?[0-9]+$/D', substr($this->payload, $start, $end - $start))) {
                throw new \RuntimeException('Invalid serialized numeric key.');
            }
            $this->offset = $end + 1;

            return [(int) substr($this->payload, $start, $end - $start), $start];
        }

        throw new \RuntimeException('Invalid serialized key.');
    }

    /** @return array{string, int} */
    private function stringBody(string $terminator): array
    {
        $length = $this->number();
        $this->expect(':"');
        $start = $this->offset;
        $string = $this->take($length);
        $this->expect('"' . $terminator);

        return [$string, $start];
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
            throw new \RuntimeException('Malformed serialized payload.');
        }
    }

    private function take(int $length): string
    {
        if ($length > strlen($this->payload) - $this->offset) {
            throw new \RuntimeException('Truncated serialized payload.');
        }
        $value = substr($this->payload, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }
}
