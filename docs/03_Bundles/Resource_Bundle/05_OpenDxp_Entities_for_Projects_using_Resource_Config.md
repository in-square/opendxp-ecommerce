# OpenDxp for Projects using Resource Config

## Step 1: Add a New OpenDxp Class in OpenDxp

1. Create a new class in OpenDxp.
2. Add a Parent Class to your OpenDxp Entity.

## Step 2: Create Parent Class

### OpenDxpEntity

Create OpenDxpEntity.php in the App/Model directory.

```php
<?php
// App/Model/OpenDxpEntity.php

abstract class OpenDxpEntity extends AbstractOpenDxpModel implements OpenDxpModelInterface 
{
    
}
```

## Step 3: Create the Resource Configuration

### config/config.yaml

```yaml
core_shop_resource:
  opendxp:
    app.opendxp_entity:
      classes:
        model: OpenDxp\Model\DataObject\OpenDxpEntity
        interface: CoreShop\Component\Resource\Model\ResourceInterface
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
