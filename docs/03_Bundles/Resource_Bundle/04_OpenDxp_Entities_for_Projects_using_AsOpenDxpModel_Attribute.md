# OpenDxp for Projects using AsOpenDxpModel Attribute

## Step 1: Add a New OpenDxp Class in OpenDxp

1. Create a new class in OpenDxp.
2. Add a Parent Class to your OpenDxp Entity.

## Step 2: Create Parent Class

### OpenDxpEntity

Create OpenDxpEntity.php in the App/Model directory.

```php
<?php
// App/Model/OpenDxpEntity.php

namespace App\Model;

use CoreShop\Bundle\ResourceBundle\Attribute\AsOpenDxpModel;
use CoreShop\Component\Resource\OpenDxp\Model\AbstractOpenDxpModel;
use CoreShop\Component\Resource\OpenDxp\Model\OpenDxpModelInterface;

#[AsOpenDxpModel(
    opendxpModel: 'OpenDxp\Model\DataObject\OpenDxpEntity',
    type: 'object',
    // optional: Resource Identifier
    alias: 'app.opendxp_entity'
)]
abstract class OpenDxpEntity extends AbstractOpenDxpModel implements OpenDxpModelInterface 
{
    
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
