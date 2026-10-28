<?php

declare(strict_types=1);

namespace CoreShop\Tests\Unit\Repository;

use CoreShop\Bundle\CoreBundle\OpenDxp\Repository\CustomerRepository;
use CoreShop\Bundle\OrderBundle\OpenDxp\Repository\OrderItemRepository;
use CoreShop\Bundle\OrderBundle\OpenDxp\Repository\OrderRepository;
use CoreShop\Component\Core\Model\CustomerInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Order\Model\OrderItemInterface;
use CoreShop\Component\Store\Model\StoreInterface;
use OpenDxp\Model\DataObject\Listing;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

final class OpenDxpRepositoryResultTest extends TestCase
{
    public function testItemQueryReturnsTheConfiguredDomainObjects(): void
    {
        $item = $this->createStub(OrderItemInterface::class);
        $list = $this->getMockBuilder(Listing::class)
            ->onlyMethods(['getObjects'])
            ->addMethods(['load'])
            ->getMock();
        $list->method('getObjects')->willReturn([$item]);
        $repository = $this->getMockBuilder(OrderItemRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getList'])
            ->getMock();
        $repository->method('getList')->willReturn($list);

        self::assertSame([$item], $repository->findOrderItemsByProductId(12));
        self::assertSame('product__id = ?', $list->getCondition());
        self::assertSame([12], $list->getConditionVariables());
    }

    public function testItemQueryRejectsAnInvalidConfiguredModel(): void
    {
        $list = $this->getMockBuilder(Listing::class)
            ->onlyMethods(['getObjects'])
            ->addMethods(['load'])
            ->getMock();
        $list->method('getObjects')->willReturn([new \stdClass()]);
        $repository = $this->getMockBuilder(OrderItemRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getList'])
            ->getMock();
        $repository->method('getList')->willReturn($list);

        $this->expectException(InvalidArgumentException::class);
        $repository->findOrderItemsByProductId(12);
    }

    public function testTokenLookupFetchesOneObjectAndUsesDatabaseTotalForUniqueness(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $list = $this->getMockBuilder(Listing::class)
            ->onlyMethods(['getObjects'])
            ->addMethods(['load', 'getTotalCount'])
            ->getMock();
        $list->method('getObjects')->willReturn([$order]);
        $list->expects(self::once())->method('getTotalCount')->willReturn(1);
        $repository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getList'])
            ->getMock();
        $repository->method('getList')->willReturn($list);

        self::assertSame($order, $repository->findByToken('token'));
        self::assertSame(1, $list->getLimit());
    }

    public function testTokenLookupStillRejectsDuplicateTokensWithOneFetchedObject(): void
    {
        $list = $this->getMockBuilder(Listing::class)
            ->addMethods(['load', 'getTotalCount'])
            ->getMock();
        $list->expects(self::once())->method('getTotalCount')->willReturn(2);
        $repository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getList'])
            ->getMock();
        $repository->method('getList')->willReturn($list);

        self::assertNull($repository->findByToken('duplicate'));
        self::assertSame(1, $list->getLimit());
    }

    public function testCustomerLookupOnlyFetchesItsSingleResult(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $list = $this->getMockBuilder(Listing::class)
            ->onlyMethods(['getObjects'])
            ->addMethods(['load'])
            ->getMock();
        $list->method('getObjects')->willReturn([$customer]);
        $repository = $this->getMockBuilder(CustomerRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getList'])
            ->getMock();
        $repository->method('getList')->willReturn($list);

        self::assertSame($customer, $repository->findOneByEmailWithoutUser('customer@example.test'));
        self::assertSame(1, $list->getLimit());
    }

    public function testLatestCartLookupOnlyFetchesTheNewestResult(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(17);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $order = $this->createStub(OrderInterface::class);
        $list = $this->getMockBuilder(Listing::class)
            ->onlyMethods(['getObjects', 'setOrderKey'])
            ->addMethods(['load'])
            ->getMock();
        $list->method('getObjects')->willReturn([$order]);
        $list->expects(self::once())->method('setOrderKey')->with('creationDate')->willReturnSelf();
        $repository = $this->getMockBuilder(OrderRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getList'])
            ->getMock();
        $repository->method('getList')->willReturn($list);

        self::assertSame($order, $repository->findLatestCartByStoreAndCustomer($store, $customer));
        self::assertSame(1, $list->getLimit());
        self::assertSame(['DESC'], $list->getOrder());
    }
}
