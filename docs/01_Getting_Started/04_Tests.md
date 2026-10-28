# Tests

CoreShop comes with a full test-suite. We use [Behat](https://docs.behat.org/en/latest/) for our Domain-Tests and
Frontend Tests. It also comes with [Psalm](https://psalm.dev/) and [PHPStan](https://phpstan.org/) for Static Analysis.

## Running Tests Locally

### Psalm

```
vendor/bin/psalm
```

### PHPStan

```
SYMFONY_ENV=test vendor/bin/phpstan analyse -c phpstan.neon src -l 3 --memory-limit=-1
```

### BEHAT

- create database `coreshop4__behat`

#### Install OpenDxp and CoreShop in Test Env

```
APP_ENV=test OPENDXP_TEST_DB_DSN=mysql://root:ROOT@coreshop4.1-db/coreshop4_1___behat OPENDXP_INSTALL_ADMIN_USERNAME=admin OPENDXP_INSTALL_ADMIN_PASSWORD=coreshop OPENDXP_INSTALL_MYSQL_HOST_SOCKET=coreshop4.1-db OPENDXP_INSTALL_MYSQL_USERNAME=root OPENDXP_INSTALL_MYSQL_PASSWORD=ROOT OPENDXP_INSTALL_MYSQL_DATABASE=coreshop4_1___behat OPENDXP_INSTALL_MYSQL_PORT=3306 OPENDXP_KERNEL_CLASS=Kernel vendor/bin/opendxp-install --env=test --skip-database-config -n
APP_ENV=test OPENDXP_TEST_DB_DSN=mysql://root:ROOT@coreshop4.1-db/coreshop4_1___behat bin/console coreshop:install
```

#### BEHAT Domain

```
CORESHOP_SKIP_DB_SETUP=1 OPENDXP_TEST_DB_DSN=mysql://root:ROOT@coreshop4.1-db/coreshop4_1___behat vendor/bin/behat -c behat.yml.dist -p default
```

#### BEHAT UI

```
vendor/bin/bdi detect drivers

# OUTSIDE CONTAINER
# Run the PHP test server
APP_ENV=test OPENDXP_TEST_DB_DSN=mysql://root:ROOT@127.0.0.1:3306/coreshop4_1___behat php -S 127.0.0.1:9080 -t public tests/http-router.php

# Run Behat
CORESHOP_SKIP_DB_SETUP=1 PANTHER_EXTERNAL_BASE_URI=http://127.0.0.1:9080/index_test.php PANTHER_NO_HEADLESS=0 OPENDXP_TEST_DB_DSN=mysql://root:ROOT@127.0.0.1:3306/coreshop4_1___behat php -d memory_limit=-1 vendor/bin/behat -c behat.yml.dist -p ui -vvv features/ui/frontend/wishlist/adding_product_to_wishlist.feature
```