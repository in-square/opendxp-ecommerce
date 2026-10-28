# OpenDxp Entities for Bundles

## Step 1: Add a New OpenDxp Class in OpenDxp

1. Create a new class in OpenDxp.
2. Add a Parent Class to your OpenDxp Entity.
3. Export Class Definition to YourBundle/Resources/install/opendxp/classes/OpenDxpEntity.json.

## Step 2: Create Parent Class

### OpenDxpEntityInterface

Create OpenDxpEntityInterface.php in the YourBundle/Model directory.

```php
<?php
// YourBundle/Model/OpenDxpEntityInterface.php

interface OpenDxpEntityInterface extends ResourceInterface {
    public function getName($language = null);
    public function setName($name, $language = null);
}
```

### OpenDxpEntity

Create OpenDxpEntity.php in the YourBundle/Model directory.

```php
<?php
// YourBundle/Model/OpenDxpEntity.php

class OpenDxpEntity extends AbstractOpenDxpModel implements OpenDxpEntityInterface, OpenDxpModelInterface {
    public function getName($language = null) {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }

    public function setName($name, $language = null) {
        throw new ImplementedByOpenDxpException(__CLASS__, __METHOD__);
    }
}
```

## Step 3: Create Dependency Injection Configuration

### Configuration.php

Create Configuration.php in YourBundle/DependencyInjection.

```php
<?php
//YourBundle/DependencyInjection/Configuration.php

namespace YourBundle\DependencyInjection;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('your_bundle');

        $this->addModelsSection($rootNode);

        return $treeBuilder;
    }

    private function addModelsSection(ArrayNodeDefinition $node)
    {
        $node
            ->children()
                ->arrayNode('opendxp')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('opendxp_entity')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('OpenDxpEntity')->end()
                                ->arrayNode('options')
                                    ->scalarNode('path')->defaultValue('path/within/opendxp')->end()
                                    ->scalarNode('permission')->defaultValue('opendxp_entity')->cannotBeOverwritten()->end()
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue('OpenDxp\Model\DataObject\OpenDxpEntity')->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(OpenDxpEntity::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(OpenDxpFactory::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->cannotBeEmpty()->end()
                                        ->scalarNode('admin_controller')->cannotBeEmpty()->end()
                                        ->scalarNode('install_file')->defaultValue('@YourBundle/Resources/install/opendxp/classes/OpenDxpEntity.json')->end()
                                        ->scalarNode('type')->defaultValue(CoreShopResourceBundle::OPENDXP_MODEL_TYPE_OBJECT)->cannotBeOverwritten(true)->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
```

### YourBundleExtension.php

Create YourBundleExtension.php in the same directory.

```php
<?php
//YourBundle/DependencyInjection/YourBundleExtension.php

namespace YourBundle\DependencyInjection;

final class YourBundleExtension extends AbstractModelExtension
{
    public function load(array $config, ContainerBuilder $container)
    {
        $config = $this->processConfiguration($this->getConfiguration([], $container), $config);
        $this->registerOpenDxpModels('app', $config['opendxp'], $container);
    }
}
```

## Step 4: Use Your OpenDxp Entity

You can either use OpenDxp Listing Classes or the automatically generated Factory/Repository Classes.

### Using OpenDxp Listing Classes

```php
$list = new OpenDxp\Model\DataObject\OpenDxpEntity\Listing();
```

### Using Factory/Repository Classes

```php
$opendxpEntityObject = $container->get('app.repository.opendxp_entity')->findBy($id);

$list = $container->get('app.repository.opendxp_entity')->getList();
```
