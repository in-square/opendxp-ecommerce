<?php

declare(strict_types=1);

namespace CoreShop\Tests\Unit\Form;

use CoreShop\Bundle\CoreBundle\Form\Type\Checkout\PaymentType;
use CoreShop\Bundle\ResourceBundle\Form\Registry\FormTypeRegistryInterface;
use CoreShop\Component\Order\Model\OrderInterface;
use CoreShop\Component\Payment\Repository\PaymentProviderRepositoryInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class PaymentConfigurationTypeTest extends TestCase
{
    public function testRejectsRegisteredClassesThatAreNotFormTypes(): void
    {
        $registry = $this->createMock(FormTypeRegistryInterface::class);
        $registry->method('has')->willReturn(true);
        $registry->method('get')->willReturn(stdClass::class);
        $type = $this->createType($registry);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement FormTypeInterface');
        $type->buildForm($this->createMock(FormBuilderInterface::class), []);
    }

    public function testBuildsPrototypeUsingTheRegisteredFormClass(): void
    {
        $registry = $this->createMock(FormTypeRegistryInterface::class);
        $registry->method('has')->willReturn(true);
        $registry->method('get')->willReturn(TextType::class);
        $type = $this->createType($registry);
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnSelf();
        $builder->method('addEventListener')->willReturnSelf();
        $builder->expects(self::once())->method('create')->with('paymentSettings', TextType::class)->willReturn($builder);

        $type->buildForm($builder, []);
    }

    private function createType(FormTypeRegistryInterface $registry): PaymentType
    {
        return new PaymentType(
            OrderInterface::class,
            ['coreshop'],
            $registry,
            $this->createMock(PaymentProviderRepositoryInterface::class),
            ['offline' => true],
        );
    }
}
