# Adjustment migration verification — 2026-10-30

Implemented version: `CoreShop\Bundle\CoreBundle\Migrations\Version20261030120000`.
Changes remain uncommitted in the working tree. No production migration, commit
or push was performed. The pre-existing README change was preserved.

## Environment and results

Separate Docker Compose project: `tests/docker-compose.yaml`, with dedicated
`coreshop_test` and `coreshop_migration_test` databases and an installed disposable
OpenDXP/CoreShop application in its own volume. The host application database and
its workers were not used by the tests.

Runtime: PHP **8.3.36**, OpenDXP **1.5.2**, MariaDB **10.11**. Dependencies were
resolved in the disposable application; no host dependency update was performed.

| Check | Result |
| --- | --- |
| Full package `tests/Unit` + `tests/Integration/Doctrine`, executor tests explicitly enabled | PASS: 77 tests, 1092 assertions |
| Additional detailed definition checks in the isolated child process | PASS: 39 checks |
| PHP syntax of all new PHP files | PASS |
| ECS on all new PHP files | PASS |
| Symfony `lint:container` | PASS |
| Actual console `doctrine:migrations:execute` target with `--dry-run` on fresh schema | PASS, 0 SQL queries |
| Focused PHPStan level 5 on the new migration and its migration services | PASS, no errors |
| Independent source review | No unresolved BLOCKING/HIGH findings |

The package-wide PHPStan configuration did not begin analysis in the standalone
layout because existing exclusion paths refer to missing directories. The
focused check used a temporary level-5 configuration covering the new source;
it does not establish a clean package-wide PHPStan run. Its findings were an
incomplete array-shape annotation and a redundant null check; after correction,
the schema suite was rerun: **8 tests, 82 assertions — PASS**, followed by ECS
for the two touched files — PASS.

## Covered behavior

- Six physical storage/query tables for configured custom class names/IDs:
  NULL, zero, negative and large integer amounts, independent query/store values,
  custom columns, indexes and column properties retained.
- Complete conflict preflight, new-only no-op, partial-DDL retry, stale view
  repair, indirect and joined views, original aliases/literals and security
  context, creation charset/collation including a non-ASCII `café` regression.
- Column-specific legacy grants cause a stop before mutation; the isolated
  fixture creates and removes its own grant-test account.
- Actual customized OpenDXP definitions and custom layout: fields, panels,
  inheritance, variants, interfaces, parent class, extra DB column and custom
  view retained without invoking full schema synchronization.
- Actual Doctrine executor and registered console listener: dry-run does not
  change schema/data/files/history; failed SQL prevents definition publication;
  rerun completes; a missing execution context fails closed; `down()` refuses
  an automatic rollback.
- Fresh-process reads/writes through all four generated accessor pairs for
  order, order item and invoice. Separate fixture object saves verify persistence.
- Native historical generated-object version payloads map to new fields and
  load through native version APIs. Native DB/filesystem adapter tests process
  more than one page; original payload backups and version metadata are retained.
  Malformed/conflicting payloads, orphan versions and unexpected binary data
  stop safely.
- Gross/net and converted correction totals with positive, negative and neutral
  adjustments; repeated recalculation retains totals without double counting.
  Those calculations occur only in acceptance fixtures, never in the migration.
- No object add/update events during the migration execution. The implementation
  does not call order saves, mail/payment/stock processors or domain calculators;
  the disposable environment uses a null mail transport and no live integration
  credentials.

## Remaining acceptance

### Localized-view regression follow-up

The initial verification missed native localized output aliases. A focused
regression suite now first reproduces all three failures against the original
implementation: old localized aliases retained, already-migrated schema not
repaired, and `verify()` accepting the wrong native contract. With the correction,
three tests / 399 assertions pass for three custom class IDs and two languages,
covering all four fields, values and column properties, fallback expressions,
SELECT/filter/order queries, dependent project views and preserved project aliases,
idempotence and repair of the original migration's partial contract.

An additional subprocess fixture uses the installed OpenDXP native localized-view
generator and actual locale-aware Listing API with all configured test languages
and all four new fields. It passes without using a CoreShop installer or writing
to the host application database. These remain disposable test fixtures, not a
production or actual-shop upgrade.

After this follow-up, the complete `tests/Unit` and `tests/Integration/Doctrine`
suite with executor tests enabled passed: **81 tests, 1493 assertions**.
PHP syntax, ECS, Symfony container lint and focused PHPStan level 5 passed;
independent source review found no unresolved actionable findings.

This evidence combines synthetic legacy schemas and a fresh OpenDXP installation
with custom definitions deliberately changed to the old field contract. It is
**not an upgrade of a copy of Nanili or another real shop**, and not a complete
Pimcore application upgrade. Production behavior, all supported OpenDXP/MySQL
versions, custom remote version adapters and every merchant-specific version
restore hook have not been tested.

Before production acceptance, rehearse the documented backup/restore and full
upgrade sequence on a restorable copy of the actual installation, including
historical version restoration, grant handling, custom layouts/views, deployed
process restart and the shop's normal correction calculations. Follow
[Adjustment-Fields-Upgrade.md](Adjustment-Fields-Upgrade.md).
