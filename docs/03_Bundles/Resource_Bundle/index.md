# Resource Bundle

The Resource Bundle is a central component of CoreShop, handling the creation, reading, updating, and deleting (CRUD) of
CoreShop models. It manages Doctrine ORM mappings, translations, routing, event dispatching, serialization, and CRUD
operations. This bundle also facilitates the installation of various OpenDxp definitions.

## Installation Process

To install the Resource Bundle, use Composer:

```bash
$ composer require coreshop/resource-bundle:^4.0
```

### Integrating with the Kernel

Enable the bundle in the kernel by updating the `AppKernel.php` file:

```php
<?php

// app/AppKernel.php

public function registerBundlesToCollection(BundleCollection $collection)
{
    $collection->addBundles([
        new \CoreShop\Bundle\ResourceBundle\CoreShopResourceBundle(),
    ]);
}
```

## Adding Custom Doctrine Entities

To create custom entities or extend existing CoreShop Doctrine entities:

#### 1. Enable Doctrine ORM

```yaml
# app/config/config.yml
doctrine:
  orm:
    mappings:
      App:
        is_bundle: false
        dir: '%kernel.project_dir%/src/Entity'
        prefix: 'App\Entity'
        alias: App
```

#### 2. Create Your Entity
```php
# src/Entity/CustomEntity.php
<?php 

declare(strict_types=1);

namespace App\Entity;

// Entity implementation
```

#### 3. Register as a CoreShop Resource

```yaml
# app/config/config.yml
core_shop_resource:
  resources:
    app.custom_entity:
      classes:
        model: App\Entity\CustomEntity
        interface: CoreShop\Component\Resource\Model\ResourceInterface
        repository: CoreShop\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository
```

#### 4. Configure Routes

```yaml
# config/routes.yaml
coreshop_admin_custom_entity:
    type: coreshop.resources
    resource: |
      alias: app.custom_entity
      # additional routes
```

#### Configure Serializer

```yaml
# app/config/config.yml
jms_serializer:
    metadata:
        directories:
            app:
                namespace_prefix: "App\\Entity"
                path: "%kernel.project_dir%/config/jms_serializer"
```

```yaml
# config/jms_serializer/CustomEntity.yml
App\Model\CustomEntity:
  exclusion_policy: ALL
  xml_root_name: custom_entity
  properties:
    id:
      expose: true
      type: integer
      groups: [List, Detailed]
    name:
      expose: true
      type: array
      groups: [List, Detailed]
```

### Adding Custom OpenDxp Entities

Similar to Doctrine entities, you can register OpenDxp DataObject Classes:

#### 1. Model Implementation:

Extend from `CoreShop\Component\Resource\OpenDxp\Model\AbstractOpenDxpModel` or
implement `CoreShop\Component\Resource\Model\ResourceInterface`.

#### 2. Register as CoreShop Resource:

```yaml
# app/config/config.yml
core_shop_resource:
  opendxp:
    app.my_data_object_class:
      classes:
        model: OpenDxp\Model\DataObject\MyDataObjectClass
        interface: CoreShop\Component\Resource\Model\ResourceInterface
```
#### 2.1. Register as a CoreShop Resource with PHP Attributes:

Since CoreShop 4.1.0, it is also possible to use PHP Attributes to register new Entities.

You can do this by adding the following Attribute to your Entity Class:

```php

use CoreShop\Bundle\ResourceBundle\Attribute\AsOpenDxpModel;
use CoreShop\Component\Resource\OpenDxp\Model\AbstractOpenDxpModel;

#[AsOpenDxpModel(
    opendxpModel: 'OpenDxp\Model\DataObject\MyDataObjectClass',
    type: 'object'
)]
class MyDataObjectClass extends AbstractOpenDxpModel
{

}
```

For this to work, you have to make sure that your `core_shop_resource` Mapping Path is set.
Per default, it points to `%kernel.project_dir%/src/Model`.

#### 3. Usage

Utilize CoreShop's repository service or OpenDxp's listing classes for data manipulation and retrieval.

The Resource Bundle is the backbone of CoreShop, enhancing its capabilities and providing a robust framework for
managing models and data in OpenDxp.
