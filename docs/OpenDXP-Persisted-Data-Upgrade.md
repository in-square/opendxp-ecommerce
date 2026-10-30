# Upgrading persisted CoreShop data

Existing order, order-item and invoice adjustment fields also require
[`Version20261030120000`](Adjustment-Fields-Upgrade.md). Read that procedure
before any class rebuild: it preserves the existing shop definitions and does
not use the general resource installer. Native historical version namespace
conversion must precede its history validation.

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

For the historical SEO column migration only, execute its specific version
through the host application's console **before any step that rebuilds object
classes**. Do not run the whole CoreShop migration set at this phase: the
adjustment migration also validates native historical version namespaces.

```bash
docker compose exec -T php php bin/console doctrine:migrations:execute 'CoreShop\Bundle\CoreBundle\Migrations\Version20261028120000' --up --no-interaction
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

Now run the [adjustment-field migration](Adjustment-Fields-Upgrade.md), after
its own dry-run and acceptance on the copy. Complete other pending CoreShop
migrations through the normal host migration runner only in the sequence verified
for that installation.

Update existing SEO definitions through a targeted, project-reviewed field
change after their columns and historical payloads are secured. Preserve custom
fields and layouts. Do not replace existing shop classes with the package's
installation JSON definitions using the general resource installer. The adjustment
migration updates its four fields and generated methods itself. Inspect any
remaining schema diff:

```bash
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
