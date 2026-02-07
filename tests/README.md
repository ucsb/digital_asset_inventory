# Digital Asset Inventory — Tests

## Running tests

Run PHPUnit from the **Drupal site root** so Drupal's autoloader and test
utilities are available:

```bash
cd /path/to/drupal-site

# All module unit tests:
./vendor/bin/phpunit web/modules/custom/digital_asset_inventory/tests/src/Unit

# Single test class:
./vendor/bin/phpunit web/modules/custom/digital_asset_inventory/tests/src/Unit/FilePathResolverTest.php

# By group:
./vendor/bin/phpunit --group digital_asset_inventory
```

If the site has a `phpunit.xml` or `phpunit.xml.dist` at the root (or in
`core/`), PHPUnit picks it up automatically. Otherwise, point to Drupal
core's bootstrap (rarely needed for standard Drupal site setups):

```bash
./vendor/bin/phpunit --bootstrap web/core/tests/bootstrap.php \
  web/modules/custom/digital_asset_inventory/tests/src/Unit
```

## Test structure

All test classes extend `Drupal\Tests\UnitTestCase` and live in the
standard Drupal test namespace:

```
tests/src/Unit/
  FilePathResolverTest.php    (47 cases)
  DigitalAssetScannerTest.php (162 cases)
  ArchiveServiceTest.php      (71 cases)
```

See `docs/testing/unit-testing-spec.md` for the full testing specification.

## Unit vs Kernel tests

| | Unit | Kernel |
|---|------|--------|
| **Bootstrap** | Mocked services | Drupal kernel + database |
| **Speed** | Fast (~ms per test) | Slow (~s per test) |
| **Scope** | Pure logic, mapping, parsing | Entity CRUD, queries, config |
| **Location** | `tests/src/Unit/` | `tests/src/Kernel/` (future) |

This module currently has unit tests only. Kernel tests may be added
later for entity and database-dependent logic.

## Gitignore note

PHPUnit generates a `.phpunit.result.cache` file when running tests. Since
this module is installed into consuming site projects, it does not ship its
own `.gitignore`. Add the following to the **site-level** `.gitignore`:

```gitignore
# Ignore PHPUnit cache
.phpunit.result.cache
```
