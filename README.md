**CoreShop - OpenDxp enhanced eCommerce**

[![Software License](https://img.shields.io/badge/license-GPLv3-brightgreen.svg?style=flat)](LICENSE.md)
[![Latest Pre-Release](https://img.shields.io/packagist/vpre/in-square/opendxp-ecommerce.svg)](https://packagist.org/packages/in-square/opendxp-ecommerce)

[![CoreShop](etc/illustration.png 'CoreShop')](https://www.coreshop.com)

CoreShop harnesses OpenDxp's advanced features for unmatched efficiency and customization in your online store. Dive
into a seamless blend of cutting-edge technology and user-friendly interfaces, crafting an eCommerce experience that
transcends ordinary transactions.

# Further Information

- [Website](https://www.coreshop.com)
- [Documentation](https://docs.coreshop.com/latest)
- [OpenDxp Github](https://github.com/open-dxp/opendxp)

# Requirements

- PHP `^8.3`
- OpenDXP `^1.3`
- Symfony `^7.4`

# Installation

This repository is the OpenDXP port of CoreShop 4.1.10, published as
`in-square/opendxp-ecommerce`. Install this fork from your configured Composer VCS
repository or a published fork release. The upstream CoreShop installation
guide describes the original Pimcore package and its dependency commands
should not be copied into an OpenDXP project.

For an existing installation, follow the
[persisted-data upgrade procedure](docs/OpenDXP-Persisted-Data-Upgrade.md)
before rebuilding classes. Standalone installation and tests are documented below.

# Demo

Discover the full potential of CoreShop through our interactive demos. Read more about this [here](https://docs.coreshop.com/CoreShop/Getting_Started/Demo)

## Copyright and license

Copyright: [CoreShop GmbH](https://www.coreshop.com)
For licensing details please visit [LICENSE.md](LICENSE.md)

## Standalone development and tests

Run the standalone suite using its separate Docker project. Its database and
network are separate from the application hosting the bundle; the checkout is
mounted read-only, and dependencies, generated classes and caches stay in a
dedicated volume. From the host project directory:

```bash
docker compose -f bundles/InSquare/CoreShop/tests/docker-compose.yaml up -d
docker compose -f bundles/InSquare/CoreShop/tests/docker-compose.yaml exec -T php sh -c 'tar -C /source --exclude=.git --exclude=vendor --exclude=var --exclude=.env.local --exclude=.env.test.local --exclude=public/bundles --exclude=public/var -cf - . | tar -C /app -xf -'
docker compose -f bundles/InSquare/CoreShop/tests/docker-compose.yaml exec -T php composer install --no-scripts --no-interaction
docker compose -f bundles/InSquare/CoreShop/tests/docker-compose.yaml exec -T php php vendor/bin/opendxp-install --env=test --no-interaction --skip-database-config
docker compose -f bundles/InSquare/CoreShop/tests/docker-compose.yaml exec -T php php bin/console coreshop:install --env=test --no-interaction
docker compose -f bundles/InSquare/CoreShop/tests/docker-compose.yaml exec -T php vendor/bin/phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/Unit tests/Integration/Doctrine
docker compose -f bundles/InSquare/CoreShop/tests/docker-compose.yaml exec -T php php -d memory_limit=512M vendor/bin/behat --strict --no-interaction --format=progress
```

The fixed credentials in this Compose file are only for its disposable test
database; no port is published. To discard that project's generated data before
a fresh installation, use `docker compose -f
bundles/InSquare/CoreShop/tests/docker-compose.yaml down --volumes`.

The package console uses this repository's `vendor/`, project root and `Kernel`.
`OPENDXP_PROJECT_ROOT` and `OPENDXP_KERNEL_CLASS` may override the defaults.
For a CoreShop bundle embedded in another application, use that application's
`bin/console`; the package console deliberately does not fall back to its vendor.
Set `OPENDXP_TEST_DB_DSN` to a dedicated test database before running Behat.
Never point the test suite at a production or shared development database.
