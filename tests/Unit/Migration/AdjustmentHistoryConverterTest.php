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

use CoreShop\Bundle\CoreBundle\Migration\AdjustmentFields\SerializedAdjustmentFieldsConverter;
use PHPUnit\Framework\TestCase;

final class AdjustmentHistoryConverterTest extends TestCase
{
    public function testPreservesValuesReferencesAndUnrelatedContent(): void
    {
        $amount = -987654321012345;
        $object = new \stdClass();
        $object->pimcoreAdjustmentTotalNet = null;
        $object->pimcoreAdjustmentTotalGross = 0;
        $object->convertedPimcoreAdjustmentTotalNet = &$amount;
        $object->convertedPimcoreAdjustmentTotalGross = \PHP_INT_MAX;
        $object->shared = &$amount;
        $object->literal = "pimcoreAdjustmentTotalNet\0\xff";
        $object->custom = (object) ['pimcoreAdjustmentTotalNet' => -4];
        $source = serialize($object);
        $converter = new SerializedAdjustmentFieldsConverter();
        $converted = $converter->convert($source, [\stdClass::class]);
        self::assertNotNull($converted);
        self::assertSame(strlen($source), strlen($converted));
        self::assertStringContainsString('s:25:"pimcoreAdjustmentTotalNet";i:-4;', $converted);
        self::assertStringContainsString("pimcoreAdjustmentTotalNet\0\xff", $converted);
        self::assertSame(preg_match_all('/[rR]:[0-9]+;/', $source), preg_match_all('/[rR]:[0-9]+;/', $converted));
        $restored = unserialize($converted, ['allowed_classes' => [\stdClass::class]]);
        self::assertNull($restored->opendxpAdjustmentTotalNet);
        self::assertSame(0, $restored->opendxpAdjustmentTotalGross);
        self::assertSame(-987654321012345, $restored->convertedOpenDxpAdjustmentTotalNet);
        self::assertSame(\PHP_INT_MAX, $restored->convertedOpenDxpAdjustmentTotalGross);
        $restored->shared = 12;
        self::assertSame(12, $restored->convertedOpenDxpAdjustmentTotalNet);
        self::assertNull($converter->convert($converted, [\stdClass::class]));
    }

    public function testProtectedAndPrivatePropertyPrefixesArePreserved(): void
    {
        foreach (["\0*\0", "\0Vendor\\CustomOrder\0"] as $prefix) {
            $key = $prefix . 'convertedPimcoreAdjustmentTotalGross';
            $newKey = $prefix . 'convertedOpenDxpAdjustmentTotalGross';
            $payload = 'O:8:"stdClass":1:{s:' . strlen($key) . ':"' . $key . '";i:-7;}';
            $expected = 'O:8:"stdClass":1:{s:' . strlen($newKey) . ':"' . $newKey . '";i:-7;}';
            self::assertSame($expected, (new SerializedAdjustmentFieldsConverter())->convert($payload, [\stdClass::class]));
        }
    }

    public function testCollisionFailsInBothOrdersAndAcrossVisibilityPrefixes(): void
    {
        foreach ([
            ['pimcoreAdjustmentTotalNet', 'opendxpAdjustmentTotalNet'],
            ['opendxpAdjustmentTotalNet', 'pimcoreAdjustmentTotalNet'],
            ["\0*\0pimcoreAdjustmentTotalNet", 'opendxpAdjustmentTotalNet'],
            ['pimcoreAdjustmentTotalNet', 'pimcoreAdjustmentTotalNet'],
        ] as $keys) {
            $payload = 'O:8:"stdClass":2:{';
            foreach ($keys as $key) {
                $payload .= 's:' . strlen($key) . ':"' . $key . '";N;';
            }

            try {
                (new SerializedAdjustmentFieldsConverter())->convert($payload . '}', [\stdClass::class]);
                self::fail('Conflicting keys accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Both historical and OpenDXP adjustment keys', $exception->getMessage());
            }
        }
    }

    public function testMalformedAndUnsupportedPayloadsFailClosed(): void
    {
        foreach ([
            'O:8:"stdClass":1:{s:24:"pimcoreAdjustmentTotalNet";s:999:"bad";}',
            'O:8:"stdClass":0:{}trailing',
            'O:8:"stdClass":1:{s:1:"x";C:1:"X":0:{}}',
            'O:8:"stdClass":1:{s:1:"x";i:no;}',
            'a:0:{}',
            'O:7:"Pimcore":0:{}',
        ] as $payload) {
            try {
                (new SerializedAdjustmentFieldsConverter())->convert($payload, [\stdClass::class]);
                self::fail('Unsupported payload accepted.');
            } catch (\RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testRootClassMustMatchConfiguredModel(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('native version namespace migration first');
        (new SerializedAdjustmentFieldsConverter())->convert(serialize((object) ['pimcoreAdjustmentTotalNet' => 1]), ['OpenDxp\\Model\\DataObject\\CustomOrder']);
    }
}
