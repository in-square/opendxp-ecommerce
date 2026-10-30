# Existing-shop adjustment total upgrade

Recorded test evidence and its limits are in
[Adjustment-Fields-Verification.md](Adjustment-Fields-Verification.md).

`CoreShop\Bundle\CoreBundle\Migrations\Version20261030120000` migrates the
configured `order`, `order_item` and `order_invoice` resources. It reads their
`coreshop.model.<resource>.opendxp_class_name` and `.class` parameters. Class
names, IDs and physical table names are resolved from that installation.

| Previous field | Target field |
| --- | --- |
| `pimcoreAdjustmentTotalNet` | `opendxpAdjustmentTotalNet` |
| `pimcoreAdjustmentTotalGross` | `opendxpAdjustmentTotalGross` |
| `convertedPimcoreAdjustmentTotalNet` | `convertedOpenDxpAdjustmentTotalNet` |
| `convertedPimcoreAdjustmentTotalGross` | `convertedOpenDxpAdjustmentTotalGross` |

## Before upgrading

1. Rehearse the complete procedure on a restorable copy of the existing shop.
   A fresh installation or synthetic tables do not demonstrate a real-shop upgrade.
2. Stop frontend/admin writes, scheduled jobs and all queue consumers for the
   entire operation. The migration's database advisory lock only prevents a second
   migration instance; it does not stop application writers.
3. Back up the database, installed code/dependencies, native class-definition
   and generated PHP files, custom layouts/configuration, and every configured
   version-storage backend, including remote object storage. Verify restoration.
   Keep a coordinated snapshot and do not allow new orders during recovery.
4. Prepare OpenDXP dependencies and complete the native historical version
   namespace conversion before running this migration. Existing object-class
   definitions must retain their fields until this migration has run. Do not
   run the general CoreShop installer, fixtures, resource class importer or class
   rebuild to replace existing definitions with package installation JSON files.
   If the native upgrade procedure rebuilds classes, split those steps and
   rehearse their ordering on the copy first.
5. The database user needs permission to rename columns and recreate dependent
   views with their existing definer. Existing definitions, generated PHP files
   and custom layouts must be writable. The project `var/migrations` directory
   must be private and outside the web root.
   For a changed schema, the migration account also needs `SELECT` on
   `mysql.columns_priv` to audit column-specific privileges for all users. A DBA
   may run the migration with an authorized account instead. No access to password
   tables or global write privileges is required.

## Run through Doctrine

There is no separate adjustment migration command and no general installer step.
Use the host application's normal CoreShop Doctrine migration runner. To inspect
or execute this migration alone in the appropriate Docker environment:

```bash
docker compose exec -T php php bin/console doctrine:migrations:execute 'CoreShop\Bundle\CoreBundle\Migrations\Version20261030120000' --up --dry-run --no-interaction
docker compose exec -T php php bin/console doctrine:migrations:execute 'CoreShop\Bundle\CoreBundle\Migrations\Version20261030120000' --up --no-interaction
```

Do not execute these against production before the copy has passed acceptance.
The normal `coreshop:migrate` / `doctrine:migrations:migrate` path also discovers
the migration. It requires the registered `MigrationExecutionListener`, which
passes the actual runner's dry-run flag. Direct calls to `up()` without that
context fail before mutation. Doctrine dry-run executes the preflight but does
not write schema, recovery files, definitions or historical payloads.

The non-transactional sequence is:

1. Resolve definitions, inspect every target storage/query column and dependent
   view, validate history, and reject all conflicts before queuing SQL.
2. Save the original definitions/generated files/custom-layout snapshots and
   exact schema/view plan to a private recovery manifest. Compute ordered hashes
   of all table values in bounded pages, preserving NULL and integer precision.
3. Let Doctrine execute queued `RENAME COLUMN` statements, retaining column
   types/defaults/nullability/comments and stored values. Recreate dependent
   views in dependency order using their original logic, project aliases,
   ALGORITHM, DEFINER, SQL SECURITY, CHECK OPTION, creation charset and collation.
   Existing standard object-view adjustment output names become the target names.
   Native localized views `object_localized_<classId>_<language>` are included for
   the installation's configured valid languages. Their four output column names
   and references from dependent project views are migrated as well; deliberate
   project output aliases remain unchanged. Verification explicitly checks the
   native output columns, beyond checking whether the views can be queried.
4. In `postUp()`, verify actual columns, properties, data hashes and queryable
   views. Only now publish modified copies of the existing definitions and
   generated PHP classes. The publisher deliberately avoids the full native
   `ClassDefinition::save()` schema synchronization, which can delete columns
   and overwrite project views. It uses the isolated native class-file dumper API.
5. Map historical serialized field keys through the configured version-storage
   adapter, with per-version original-payload backups before each write.

No totals are recalculated, adjustment entries added, or order/invoice objects
saved. New definitions are derived from the merchant's actual definitions;
custom fields, layout structure, parent classes, interfaces, traits and inheritance
settings remain. Only the four field names and direct composite-index/custom-layout
references change. Definition/custom-layout modification timestamps may advance.

Restart PHP workers/consumers and invalidate the deployment's OPcache as needed
before reopening the shop. Generated PHP classes already loaded in a running
process cannot be replaced in that process.

## Supported states and deliberate stops

- Old names only: migrate. New names only, including history and view references:
  no-op without rewriting definitions, amounts or payloads.
- Both old and new columns in any affected physical table, or both field names
  in a definition/payload: stop. NULL/zero target values do not permit automatic
  overwriting or merging. Reconcile explicitly using the backup and shop rules.
- Missing required classes, fields or tables: stop with their names.
- Required fields must use direct scalar storage/query columns. Localized,
  collection, brick or other customized persistence needs a separately reviewed
  migration; the migration does not guess an alternative schema.
- Supported DDL is MySQL/MariaDB `RENAME COLUMN`; older engines without it fail
  rather than using a potentially lossy copy/drop fallback.
- SQL which cannot be unambiguously rewritten stops before DDL. Reused aliases
  across different scopes in an affected view require project-specific handling.
  Text literals and project output aliases are never globally replaced.
- Column-specific grants referring to old field names stop preflight. A DBA must
  back up these grants and arrange their explicit removal/remapping and restoration
  for the new names during maintenance. Table/view-wide privileges are retained;
  the migration never widens privileges to work around a failed column grant.
- Metadata inspection uses bounded pages. The supported affected-view graph is
  limited to 100 views, affected custom layouts to 100, native in-memory configured
  layout definitions to 1000, and columns to fewer than 4096 per table. Larger
  installations stop for a bounded project-specific migration.

## Historical versions

The migration first validates all selected object histories in pages of 100,
then changes only the four direct serialized property keys, including protected
or private property-name prefixes. Serialized lengths are updated. Values,
ordinary text, nested unrelated object properties, version IDs, notes, timestamps
and version counts are retained. It does not instantiate historical objects or
call their save methods. Native namespace conversion is a prerequisite, not a
global string replacement performed by this migration.

Malformed/custom serialization, duplicate mapped keys, missing payloads and
unexpected object binary data cause a stop. Orphan object versions cannot be
classified safely by the current objects table; their presence stops preflight
until their provenance and migration are resolved separately. Do not delete
history to bypass this check. Recycle-bin payloads are outside the selected
`versions` object histories and need separate native upgrade acceptance.

The adapter tests exercise native database and filesystem payload storage.
Custom/remote adapters require acceptance on the actual installation. Historical
read and restore must be tested on the copy with the new generated classes before
production acceptance; preserving serialized bytes alone does not establish every
shop-specific restore behavior.

## Partial execution and recovery

DDL and filesystem writes are not one transaction. `down()` deliberately throws
an irreversible-migration exception and does not promise to restore data.

Recovery artifacts are written under:

```text
var/migrations/adjustment-fields/<UTC timestamp>_<random>/manifest.json
var/migrations/adjustment-fields/<UTC timestamp>_<random>/history/<version id>.serialized
var/migrations/adjustment-fields/<UTC timestamp>_<random>/history/<version id>.json
```

These contain private shop data/configuration; keep directory permissions
restricted, retain them with the coordinated backup, and do not commit or expose
them. They are diagnostic recovery material, not a replacement for a full backup.

If execution fails, keep the shop and consumers stopped. Inspect the original
manifest, SQL failure, current tables/views and generated/definition files. Each
physical old-only column can be renamed on retry; already renamed columns remain
untouched. The planner accepts a partial rename across separate tables/fields,
but always rejects old+new in the same table. It does not infer how that partial
state was created: compare it with the original manifest before explicitly
rerunning. Do not retry after intervening application writes or unexplained changes.

An interrupted history pass is similarly repeatable: already mapped payloads
are no-ops, and originals for pending writes are backed up before mutation. Each
attempt gets a distinct recovery directory; retain the earliest original manifest
and all directories from later attempts. A failed DDL can leave the connection's
view creation charset/collation temporarily changed: close that failed console
process and retry from a fresh process after investigation.

If the original `Version20261030120000` was already marked executed before the
localized-view fix, normal `migrate` will not execute it again. On the restorable
copy first, use the explicit `doctrine:migrations:execute` dry-run/up commands
above to repair stale localized aliases with the corrected version. Do not delete
migration metadata or run a general installer. The already renamed table columns
and their amounts remain unchanged during this repair; retain the same backups
and maintenance precautions.

If recovery cannot be confidently completed, restore the coordinated pre-upgrade
database, version backends, definitions/generated classes and compatible code.
Reopen only after the copy/restore and application checks pass. Returning to old
code alone is not a rollback.

## Verification

The regression suite includes synthetic MariaDB tables, native version-storage
adapters, actual OpenDXP custom definitions and the real Doctrine executor. Run it
in the package's separate Docker project after the disposable OpenDXP/CoreShop
test installation described in the README:

```bash
docker compose -f bundles/InSquare/CoreShop/tests/docker-compose.yaml exec -T -e CORESHOP_ADJUSTMENT_EXECUTION_TESTS=1 php php vendor/bin/phpunit --bootstrap vendor/autoload.php --do-not-cache-result tests/Unit/Migration tests/Integration/Doctrine
```

The tests explicitly require `coreshop_migration_test` or `coreshop_test` and never
use the host application's database. Fresh-process checks are necessary for
generated PHP methods. Record the actual tested runtime and results separately
from real-shop acceptance; no universal compatibility or production validation
is implied.
