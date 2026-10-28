# Extending CoreShop

CoreShop Data Objects (like CoreShopProduct or CoreShopOrder) can be changed directly within OpenDxp.

## Replace CoreShop Object Classes with your own Classes

CoreShop uses OpenDxp Parameters to determine the OpenDxp Object Class. To change it, simply add this to
your `config.yml`:

```yaml
core_shop_order:
  opendxp:
    order:
      classes:
        model: 'OpenDxp\Model\DataObject\MyOrderClass'
        install_file: 'config/Resources/install/opendxp/classes/MyOrderClass.json'
        repository: App\Repository\OrderRepository
        factory: App\Factory\OrderFactory
```