# Product Configuration

```yaml
core_shop_product:
    opendxp:
        product:
            path: coreshop/products
            classes:
                repository: CoreShop\Bundle\CoreBundle\OpenDxp\Repository\ProductRepository
                install_file: '@CoreShopCoreBundle/Resources/install/opendxp/classes/CoreShopProductBundle/CoreShopProduct.json'
                model: OpenDxp\Model\DataObject\CoreShopProduct
                interface: CoreShop\Component\Product\Model\ProductInterface
                factory: CoreShop\Component\Resource\Factory\OpenDxpFactory
                type: object
```