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

namespace CoreShop\Tests\Unit\Migration;

use CoreShop\Bundle\CoreBundle\Migration\VersionSeoFieldsConverter;
use PHPUnit\Framework\TestCase;

final class VersionSeoFieldsConverterTest extends TestCase
{
    public function testOnlyScopedKeysChangeAndReferencesAndContentRemainUntouched(): void
    {
        $shared = new \stdClass();
        $shared->text = "Zażółć\0pimcoreMetaTitle";
        $root = new \stdClass();
        $root->localizedfields = new \stdClass();
        $root->localizedfields->items = ['en' => ['pimcoreMetaTitle' => 'pimcoreMetaTitle', 'pimcoreMetaDescription' => "Treść\0\xff", 'other' => ['pimcoreMetaTitle' => 'unchanged']], 3 => ['pimcoreMetaTitle' => null]];
        $root->pimcoreMetaTitle = 'Direct title';
        $root->relation = ['pimcoreMetaTitle' => 'unchanged', $shared, $shared];
        $source = serialize($root);

        $source = preg_replace('/O:8:"stdClass":1:/', 'O:39:"OpenDxp\\Model\\DataObject\\Localizedfield":1:', $source, 1);
        $converter = new VersionSeoFieldsConverter();
        $converted = $converter->convert($source, [\stdClass::class]);
        self::assertNotNull($converted);
        self::assertSame(strlen($source), strlen($converted));
        self::assertStringContainsString('s:16:"opendxpMetaTitle";s:16:"pimcoreMetaTitle";', $converted);
        self::assertStringContainsString('s:16:"pimcoreMetaTitle";s:9:"unchanged";', $converted);
        self::assertStringContainsString('s:16:"opendxpMetaTitle";s:12:"Direct title";', $converted);
        self::assertStringContainsString("Zażółć\0pimcoreMetaTitle", $converted);
        self::assertStringContainsString("Treść\0\xff", $converted);
        self::assertSame(preg_match_all('/[rR]:[0-9]+;/', $source), preg_match_all('/[rR]:[0-9]+;/', $converted));
        self::assertNull($converter->convert($converted, [\stdClass::class]));
    }

    public function testReferencedFieldContainerFailsClosed(): void
    {
        $fields = new \stdClass();
        $fields->items = ['en' => ['pimcoreMetaTitle' => 'SEO']];
        $root = (object) ['earlier' => $fields, 'localizedfields' => $fields];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Referenced SEO field containers');
        (new VersionSeoFieldsConverter())->convert(serialize($root), [\stdClass::class]);
    }

    public function testProtectedDirectKeysKeepVisibility(): void
    {
        $old = "\0*\0pimcoreMetaTitle";
        $source = 'O:8:"stdClass":1:{s:19:"' . $old . '";s:3:"SEO";}';
        self::assertSame(str_replace($old, "\0*\0opendxpMetaTitle", $source), (new VersionSeoFieldsConverter())->convert($source, [\stdClass::class]));
    }

    public function testCollisionFailsRegardlessOfKeyOrder(): void
    {
        foreach ([['pimcoreMetaTitle', 'opendxpMetaTitle'], ['opendxpMetaTitle', 'pimcoreMetaTitle']] as $keys) {
            $root = new \stdClass();
            foreach ($keys as $key) {
                $root->$key = null;
            }

            try {
                (new VersionSeoFieldsConverter())->convert(serialize($root), [\stdClass::class]);
                self::fail('Collision was accepted');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Both historical and OpenDXP', $e->getMessage());
            }
        }
    }

    public function testRejectsMalformedAndUnsupportedPayloads(): void
    {
        foreach (['O:8:"stdClass":1:{s:16:"pimcoreMetaTitle";s:99:"bad";}', 'O:8:"stdClass":1:{s:1:"x";C:1:"X":0:{}}', 'O:8:"stdClass":0:{}garbage', 'O:7:"Pimcore":0:{}'] as $payload) {
            try {
                (new VersionSeoFieldsConverter())->convert($payload, [\stdClass::class]);
                self::fail('Invalid payload was accepted');
            } catch (\RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }
}
