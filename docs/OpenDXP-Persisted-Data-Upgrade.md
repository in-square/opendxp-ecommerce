# Upgrading persisted CoreShop data

Prepare the host application's OpenDXP code and dependencies without rebuilding
existing CoreShop class definitions. Take a database and version-storage backup
and keep the application in maintenance mode throughout the upgrade.

Follow the native OpenDXP migration procedure for migration history and bundle
installation markers. Rename both the native bundle identifier and its
`settings_store.scope` from `pimcore` to `opendxp`. Keeping the old scope makes
an installed native bundle appear uninstalled and can cause duplicate reports,
tables or permissions. Do not mark unverified migrations as executed.

CoreShop registers the historical `pimcoreObject`, `pimcoreDocument`,
`pimcoreAsset` and `pimcoreSite` DBAL comment names as aliases of its OpenDXP
types. They allow native migrations to introspect a schema created by CoreShop
for Pimcore. The runtime models and new mappings remain OpenDXP types.

Run CoreShop migrations through the host application's console **before any
native or CoreShop step that rebuilds object classes**:

```bash
docker compose exec -T php php bin/console doctrine:migrations:migrate --prefix='CoreShop\Bundle\CoreBundle' --no-interaction
```

Then complete the native OpenDXP and dependent-bundle migrations for the host
application, including its version-storage namespace migration. After that,
validate and migrate the historical SEO field keys:

```bash
docker compose exec -T php php bin/console coreshop:migrate:version-seo-fields
docker compose exec -T php php bin/console coreshop:migrate:version-seo-fields --apply
```

The first command validates without writing. `--apply` first validates all
selected versions, then writes through the native version-storage adapter in
bounded pages. It selects existing objects of the configured product/category
classes. It changes serialized field keys without instantiating old objects or
changing user content. Repeat execution is safe. Collision, malformed or
unsupported payloads stop the command. An I/O failure during writing may leave
some versions migrated; fix the storage problem and rerun. This operation is
not transactional. Keep the version-storage backup and maintenance mode until
the upgrade has been checked. Recycle-bin data remains part of the native host
migration and is outside this command's object-class selection.

Finally update the CoreShop classes and inspect the schema diff:

```bash
docker compose exec -T php php bin/console coreshop:resources:install --application-name=coreshop --update-classes --no-interaction
docker compose exec -T php php bin/console coreshop:resources:create-tables coreshop --dump-sql
```

`Version20261028120000` renames the persisted `pimcoreMetaTitle` and
`pimcoreMetaDescription` columns to their OpenDXP names for the configured product
and category models. It covers their storage and localized query tables, including
each locale, without rewriting values. Updating the class definitions afterwards
rebuilds the generated views and getters. Importing definitions first can delete
the old SEO columns before their contents are migrated.

The migration is a no-op for an already renamed schema. If a table contains both
the old and new column names it stops before executing changes; reconcile the
values explicitly. The MySQL/MariaDB database must support
`ALTER TABLE ... RENAME COLUMN`; the regression suite uses MariaDB 10.11.
Existing views may reference old column names until the class-definition update
completes. Review the dumped SQL before applying any remaining schema changes.

Native installation and version-history migration are host upgrade operations;
never run a fresh OpenDXP installer against an existing application database.
