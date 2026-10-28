<?php

declare(strict_types=1);

namespace CoreShop\Tests\Unit\Form;

use CoreShop\Bundle\CoreBundle\Form\Type\Payment\Rule\Action\AdditionAmountActionConfigurationType;
use CoreShop\Bundle\CoreBundle\Form\Type\ProductPriceRule\Condition\QuantityConfigurationType;
use CoreShop\Bundle\IndexBundle\Form\Type\Column\IndexColumnTypeClassificationStoreType;
use CoreShop\Bundle\IndexBundle\Form\Type\Filter\FilterConditionRangeType;
use CoreShop\Bundle\OrderBundle\Form\Type\Rule\Action\SurchargeAmountConfigurationType;
use CoreShop\Bundle\ProductBundle\Form\Type\Rule\Action\PriceConfigurationType;
use CoreShop\Bundle\ShippingBundle\Form\Type\Rule\Condition\DimensionConfigurationType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Validation;

final class RuleNumericConstraintTest extends TestCase
{
    /** @param class-string<FormTypeInterface> $class */
    #[DataProvider('ruleForms')]
    public function testNumericValidationRemainsBoundToItsConfiguredGroup(string $class, ?array $groups, string $group, int $expectedConstraints): void
    {
        $type = null === $groups ? new $class() : new $class($groups);
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('get')->willReturn($builder);
        $constraints = [];
        $builder->method('add')->willReturnCallback(static function (string $name, ?string $fieldType, array $options) use (&$constraints, $builder): FormBuilderInterface {
            foreach ($options['constraints'] ?? [] as $constraint) {
                if ($constraint instanceof Type) {
                    $constraints[] = $constraint;
                }
            }

            return $builder;
        });

        $type->buildForm($builder, []);

        self::assertCount($expectedConstraints, $constraints);
        $validator = Validation::createValidator();

        foreach ($constraints as $constraint) {
            self::assertCount(0, $validator->validate('12.5', $constraint, [$group]));
            self::assertCount(1, $validator->validate('not numeric', $constraint, [$group]));
            self::assertCount(0, $validator->validate('not numeric', $constraint, ['another_group']));
        }
    }

    public static function ruleForms(): iterable
    {
        yield 'payment amount' => [AdditionAmountActionConfigurationType::class, ['rule_group'], 'rule_group', 1];
        yield 'product quantity' => [QuantityConfigurationType::class, ['rule_group'], 'rule_group', 2];
        yield 'classification store' => [IndexColumnTypeClassificationStoreType::class, null, 'coreshop', 2];
        yield 'index range' => [FilterConditionRangeType::class, ['rule_group'], 'rule_group', 3];
        yield 'order surcharge' => [SurchargeAmountConfigurationType::class, ['rule_group'], 'rule_group', 1];
        yield 'product price' => [PriceConfigurationType::class, ['rule_group'], 'rule_group', 1];
        yield 'shipping dimensions' => [DimensionConfigurationType::class, ['rule_group'], 'rule_group', 3];
    }
}
