<?php

declare(strict_types=1);

namespace CoreShop\Tests\Unit\Tracking;

use CoreShop\Bundle\TrackingBundle\Tracker\Google\GA4Ecommerce;
use CoreShop\Bundle\TrackingBundle\Tracker\Google\TagManager\CodeTracker;
use CoreShop\Bundle\TrackingBundle\Tracker\Google\TagManager\TagManagerClassicEcommerce;
use CoreShop\Component\Core\Model\OrderInterface;
use CoreShop\Component\Core\Tracking\Extractor\OrderExtractor;
use CoreShop\Component\Currency\Model\CurrencyInterface;
use CoreShop\Component\Order\Model\AdjustmentInterface;
use CoreShop\Component\Tracking\Extractor\TrackingExtractorInterface;
use OpenDxp\Bundle\GoogleMarketingBundle\Tracker\TrackerInterface;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class OrderExtractorTest extends TestCase
{
    public function testOrderMetadataPreservesFractionalAmounts(): void
    {
        self::assertSame([
            'id' => 1,
            'affiliation' => 70.68,
            'total' => 70.68,
            'subtotal' => 393.48,
            'totalTax' => 0.0,
            'shipping' => 15.49,
            'discount' => -15.49,
            'currency' => 'PLN',
            'items' => [],
        ], $this->extractOrderMetadata(0));
    }

    public function testPurchaseTrackersRenderFractionalAmountsAsNumbers(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(
            dirname(__DIR__, 3) . '/src/CoreShop/Bundle/TrackingBundle/Resources/views',
            'CoreShopTracking',
        );
        $twig = new Environment($loader);
        $metadata = $this->extractOrderMetadata(401);

        $tracker = $this->createMock(TrackerInterface::class);
        $tracker->expects(self::once())->method('addCodePart')->with(
            'gtag(\'event\', \'purchase\', {"transaction_id":1,"value":70.68,"tax":4.01,"shipping":15.49,'
            . '"currency":"PLN","items":[]});',
        );
        $ga4 = new GA4Ecommerce($twig);
        $ga4->setTracker($tracker);
        $ga4->trackCheckoutComplete($metadata);

        $codeTracker = new CodeTracker();
        $gtm = new TagManagerClassicEcommerce($twig);
        $gtm->setCodeTracker($codeTracker);
        $gtm->trackCheckoutComplete($metadata);

        self::assertSame(
            'dataLayer.push({"transactionId":1,"transactionAffiliation":70.68,"transactionTotal":70.68,'
            . '"transactionTax":4.01,"transactionShipping":15.49,"transactionCurrency":"PLN","transactionProducts":[]});',
            $codeTracker->getBlocks()[1],
        );
    }

    private function extractOrderMetadata(int $tax): array
    {
        $currency = $this->createStub(CurrencyInterface::class);
        $currency->method('getIsoCode')->willReturn('PLN');

        $order = $this->createStub(OrderInterface::class);
        $order->method('getId')->willReturn(1);
        $order->method('getItems')->willReturn([]);
        $order->method('getCurrency')->willReturn($currency);
        $order->method('getTotal')->willReturn(7068);
        $order->method('getSubtotal')->willReturn(39348);
        $order->method('getTotalTax')->willReturn($tax);
        $order->method('getAdjustmentsTotal')->willReturnMap([
            [AdjustmentInterface::SHIPPING, true, 1549],
            [AdjustmentInterface::CART_PRICE_RULE, true, -1549],
        ]);

        $extractor = new OrderExtractor($this->createStub(TrackingExtractorInterface::class), 100, 2);

        return $extractor->updateMetadata($order);
    }
}
