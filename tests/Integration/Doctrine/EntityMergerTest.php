<?php

declare(strict_types=1);

namespace CoreShop\Tests\Integration\Doctrine;

use CoreShop\Bundle\ResourceBundle\Doctrine\ORM\EntityMerger;
use CoreShop\Bundle\ResourceBundle\EventListener\ORMMappedSuperClassSubscriber;
use CoreShop\Bundle\ResourceBundle\EventListener\ORMRepositoryClassSubscriber;
use CoreShop\Bundle\ResourceBundle\EventListener\ORMTranslatableListener;
use CoreShop\Component\Core\Model\Country;
use CoreShop\Component\Core\Model\Currency;
use CoreShop\Component\Core\Model\Store;
use CoreShop\Component\Product\Model\ProductSpecificPriceRule;
use CoreShop\Component\Product\Model\ProductSpecificPriceRuleTranslation;
use CoreShop\Component\Product\Model\ProductUnit;
use CoreShop\Component\Product\Model\ProductUnitDefinition;
use CoreShop\Component\Product\Model\ProductUnitDefinitions;
use CoreShop\Component\Resource\Metadata\Registry;
use CoreShop\Component\Resource\Metadata\RegistryInterface;
use CoreShop\Component\Resource\Model\TranslatableInterface;
use CoreShop\Component\Resource\Translation\TranslatableEntityLocaleAssignerInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\ClassMetadata as PersistenceClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\ContainerAwareEventManager;
use Symfony\Bridge\Doctrine\DependencyInjection\CompilerPass\RegisterEventListenersAndSubscribersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;

final class EntityMergerTest extends TestCase
{
    private EntityManager $em;

    private EntityMerger $merger;

    protected function setUp(): void
    {
        $registry = new Registry();
        $registry->addFromAliasAndConfiguration('test.price_rule', [
            'driver' => 'doctrine/orm',
            'classes' => ['model' => ProductSpecificPriceRule::class],
            'translation' => [],
        ]);
        $registry->addFromAliasAndConfiguration('test.price_rule_translation', [
            'driver' => 'doctrine/orm',
            'classes' => ['model' => ProductSpecificPriceRuleTranslation::class],
        ]);

        $container = new ContainerBuilder();
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection']);
        $container->register(RegistryInterface::class)->setSynthetic(true);
        $container->register(TranslatableEntityLocaleAssignerInterface::class)->setSynthetic(true);
        $container->register('doctrine.dbal.default_connection.event_manager', ContainerAwareEventManager::class)
            ->setArguments([new Reference('service_container')])
            ->setPublic(true);

        foreach (['doctrine/orm.yml', 'translation.yml'] as $file) {
            $services = Yaml::parseFile(self::bundleRoot() . '/src/CoreShop/Bundle/ResourceBundle/Resources/config/services/integrations/' . $file)['services'];

            foreach ([ORMMappedSuperClassSubscriber::class, ORMRepositoryClassSubscriber::class, ORMTranslatableListener::class] as $listener) {
                if (!isset($services[$listener])) {
                    continue;
                }
                $definition = $container->register($listener)->setArguments([new Reference(RegistryInterface::class)]);

                if (ORMTranslatableListener::class === $listener) {
                    $definition->addArgument(new Reference(TranslatableEntityLocaleAssignerInterface::class));
                }

                foreach ($services[$listener]['tags'] as $tag) {
                    $name = $tag['name'];
                    unset($tag['name']);
                    $definition->addTag($name, $tag);
                }
            }
        }

        $container->addCompilerPass(new RegisterEventListenersAndSubscribersPass(
            'doctrine.connections',
            'doctrine.dbal.%s_connection.event_manager',
            'doctrine',
        ));
        $container->compile();
        $container->set(RegistryInterface::class, $registry);
        $container->set(TranslatableEntityLocaleAssignerInterface::class, new class implements TranslatableEntityLocaleAssignerInterface {
            public function assignLocale(TranslatableInterface $translatableEntity): void
            {
                $translatableEntity->setCurrentLocale('en');
            }
        });

        $configuration = ORMSetup::createConfiguration(true);
        $configuration->setMetadataDriverImpl(new MergerTestMappingDriver());
        $this->em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $configuration,
            $container->get('doctrine.dbal.default_connection.event_manager'),
        );
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        (new SchemaTool($this->em))->createSchema($metadata);

        $mergeAssociations = [];

        foreach (['CoreBundle', 'ProductBundle'] as $bundle) {
            $config = Yaml::parseFile(self::bundleRoot() . '/src/CoreShop/Bundle/' . $bundle . '/Resources/config/opendxp/config.yml');
            $mergeAssociations += $config['core_shop_resource']['orm_cascade_merge_associations'];
        }
        $this->merger = new EntityMerger($this->em, $mergeAssociations);
    }

    protected function tearDown(): void
    {
        $this->em->close();
    }

    public function testDoctrineBridgeRegistersAllMetadataListenersAndTranslations(): void
    {
        $listeners = array_map(static fn (object $listener): string => $listener::class, $this->em->getEventManager()->getListeners(Events::loadClassMetadata));
        self::assertContains(ORMMappedSuperClassSubscriber::class, $listeners);
        self::assertContains(ORMRepositoryClassSubscriber::class, $listeners);
        self::assertContains(ORMTranslatableListener::class, $listeners);
        self::assertTrue($this->em->getClassMetadata(ProductSpecificPriceRule::class)->hasAssociation('translations'));
    }

    public function testTranslationsSurviveNewAndDetachedRuleMergeAndReload(): void
    {
        $rule = new ProductSpecificPriceRule();
        $rule->setLabel('Original', 'en');
        $rule->setLabel('Do usuniecia', 'pl');
        $this->merger->merge($rule);
        $this->em->flush();
        $id = $rule->getId();
        $this->em->clear();

        $loaded = $this->em->find(ProductSpecificPriceRule::class, $id);
        self::assertSame('Original', $loaded->getLabel());
        $loaded->getTranslations()->initialize();
        $detached = unserialize(serialize($loaded));
        $detached->setLabel('Edited', 'en');
        $detached->setLabel('Neu', 'de');
        $detached->removeTranslation($detached->getTranslation('pl', false));
        $this->merger->merge($detached);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(ProductSpecificPriceRule::class, $id);
        self::assertSame('Edited', $reloaded->getLabel('en'));
        self::assertSame('Neu', $reloaded->getLabel('de'));
        self::assertCount(2, $reloaded->getTranslations());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_price_translation'));
    }

    public function testConfiguredDefaultUnitMergeAppliesToCustomModelSubclass(): void
    {
        $definitions = new CustomProductUnitDefinitions();
        $definition = new ProductUnitDefinition();
        $unit = new ProductUnit();
        $unit->setName('piece');
        $definition->setUnit($unit);
        $definition->setConversionRate(1.0);
        $definitions->setDefaultUnitDefinition($definition);
        $this->merger->merge($definitions);
        $this->em->flush();
        $id = $definitions->getId();
        $this->em->clear();

        $loaded = $this->em->find(CustomProductUnitDefinitions::class, $id);
        $loaded->getUnitDefinitions()->initialize();
        $loaded->getDefaultUnitDefinition()->getConversionRate();
        $this->em->clear();
        $detached = $loaded;
        $detached->getDefaultUnitDefinition()->setConversionRate(2.5);
        $this->merger->merge($detached);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(2.5, $this->em->find(CustomProductUnitDefinitions::class, $id)->getDefaultUnitDefinition()->getConversionRate());
    }

    public function testConfiguredStoreCountryAndInheritedCountryCurrencyMergeSurviveReload(): void
    {
        $currency = new Currency();
        $currency->setName('Original currency');
        $country = new CustomCountry();
        $country->setCurrency($currency);
        $store = new Store();
        $store->addCountry($country);

        $this->em->persist($currency);
        $this->em->persist($country);
        $this->em->persist($store);
        $this->em->flush();
        $id = $store->getId();
        $this->em->clear();

        $loaded = $this->em->find(Store::class, $id);
        $loaded->getCountries()->initialize();
        $loaded->getCountries()->first()->getCurrency()->getName();
        $this->em->clear();
        $detached = $loaded;
        $detached->getCountries()->first()->getCurrency()->setName('Edited currency');
        $this->merger->merge($detached);
        self::assertSame('Edited currency', $detached->getCountries()->first()->getCurrency()->getName());
        $this->em->flush();
        $this->em->clear();

        self::assertSame('Edited currency', $this->em->find(Store::class, $id)->getCountries()->first()->getCurrency()->getName());
    }

    public function testExplicitEmptySubclassOverrideDisablesInheritedCurrencyMerge(): void
    {
        $currency = new Currency();
        $currency->setName('Original currency');
        $country = new CustomCountry();
        $country->setCurrency($currency);
        $this->em->persist($country);
        $this->em->flush();
        $id = $country->getId();
        $this->em->clear();

        $detached = $this->em->find(CustomCountry::class, $id);
        $detached->getCurrency()->getName();
        $this->em->clear();
        $detached->getCurrency()->setName('Ignored edit');
        $merger = new EntityMerger($this->em, [
            CustomCountry::class => ['associations' => []],
            Country::class => ['associations' => ['currency']],
        ]);
        $merger->merge($detached);
        $this->em->flush();
        $this->em->clear();

        self::assertSame('Original currency', $this->em->find(CustomCountry::class, $id)->getCurrency()->getName());
    }

    private static function bundleRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}

class CustomProductUnitDefinitions extends ProductUnitDefinitions
{
}

class CustomCountry extends Country
{
}


final class MergerTestMappingDriver implements MappingDriver
{
    public function loadMetadataForClass(string $className, PersistenceClassMetadata $metadata): void
    {
        assert($metadata instanceof ClassMetadata);
        $metadata->mapField(['fieldName' => 'id', 'type' => 'integer', 'id' => true]);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);

        switch ($className) {
            case ProductSpecificPriceRule::class:
                $metadata->setPrimaryTable(['name' => 'test_price']);

                break;
            case ProductSpecificPriceRuleTranslation::class:
                $metadata->setPrimaryTable(['name' => 'test_price_translation']);
                $metadata->mapField(['fieldName' => 'label', 'type' => 'string']);

                break;
            case CustomProductUnitDefinitions::class:
                $metadata->setPrimaryTable(['name' => 'test_units']);
                $metadata->mapOneToOne(['fieldName' => 'defaultUnitDefinition', 'targetEntity' => ProductUnitDefinition::class, 'cascade' => ['persist']]);
                $metadata->mapOneToMany(['fieldName' => 'unitDefinitions', 'targetEntity' => ProductUnitDefinition::class, 'mappedBy' => 'productUnitDefinitions', 'cascade' => ['all'], 'orphanRemoval' => true]);

                break;
            case ProductUnitDefinition::class:
                $metadata->setPrimaryTable(['name' => 'test_unit']);
                $metadata->mapField(['fieldName' => 'conversionRate', 'type' => 'float']);
                $metadata->mapManyToOne(['fieldName' => 'productUnitDefinitions', 'targetEntity' => CustomProductUnitDefinitions::class, 'inversedBy' => 'unitDefinitions']);

                break;
            case Store::class:
                $metadata->setPrimaryTable(['name' => 'test_store']);
                $metadata->mapManyToMany(['fieldName' => 'countries', 'targetEntity' => CustomCountry::class, 'joinTable' => ['name' => 'test_store_country']]);

                break;
            case CustomCountry::class:
                $metadata->setPrimaryTable(['name' => 'test_country']);
                $metadata->mapManyToOne(['fieldName' => 'currency', 'targetEntity' => Currency::class, 'cascade' => ['persist']]);

                break;
            case Currency::class:
                $metadata->setPrimaryTable(['name' => 'test_currency']);
                $metadata->mapField(['fieldName' => 'name', 'type' => 'string']);

                break;
        }
    }

    public function getAllClassNames(): array
    {
        return [ProductSpecificPriceRule::class, ProductSpecificPriceRuleTranslation::class, CustomProductUnitDefinitions::class, ProductUnitDefinition::class, Store::class, CustomCountry::class, Currency::class];
    }

    public function isTransient(string $className): bool
    {
        return !in_array($className, $this->getAllClassNames(), true);
    }
}
