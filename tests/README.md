# Digital Asset Inventory — Tests

This document explains how to run **unit** and **kernel** tests for the
Digital Asset Inventory module. All commands run from the **web root**
(`/path/to/drupal-site/web`) using `-c core/phpunit.xml.dist` to provide
Drupal's bootstrap and autoloader.

Kernel tests must run from `web/` because Drupal's test runner spawns a
child process that inherits the config path — relative paths like
`web/core/phpunit.xml.dist` break in the child. Unit tests also work from
`web/` for consistency.

---

## Running unit tests

```bash
cd /path/to/drupal-site/web

# All module unit tests:
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/digital_asset_inventory/tests/src/Unit

# Single test class:
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/digital_asset_inventory/tests/src/Unit/FilePathResolverTest.php

# By group (scoped to Unit to avoid triggering kernel tests):
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  --group digital_asset_inventory \
  modules/custom/digital_asset_inventory/tests/src/Unit
```

## Test structure

All test classes live in standard Drupal test namespaces under
`tests/src/`. The `tests/artifacts/` directory holds debug output
generated during kernel test runs (`.gitignore`d — never committed).

```text
tests/
  artifacts/                              (debug dumps, .gitignore'd, auto-created)
    .gitignore                            (keeps directory in git, ignores contents)
    dai-debug-dump.txt                    (table dumps from debug-enabled runs)
    dai-debug-dump.txt.run                (run marker for auto-clear detection)
  src/
    Unit/
      ArchiveServiceTest.php
      DigitalAssetScannerTest.php
      FilePathResolverTest.php
    Kernel/
      DigitalAssetKernelTestBase.php      (shared setUp, helpers, debug dumps)
      ArchiveIntegrityKernelTest.php      (checksums, auto-void, immutability)
      ArchiveWorkflowKernelTest.php       (state machine, usage policy)
      ConfigFlagsKernelTest.php           (config flag → service behavior)
      ScannerAtomicSwapKernelTest.php     (atomic swap, entity CRUD, gating)
```

- Unit tests extend `Drupal\Tests\UnitTestCase`
- Kernel tests extend `Drupal\KernelTests\KernelTestBase`

See:

- `docs/testing/unit-testing-spec.md`
- `docs/testing/kernel-testing-spec.md`

for the full testing specifications.

## Unit vs Kernel tests

|   | Unit | Kernel |
| --- | ------ | -------- |
| **Bootstrap** | Mocked services | Drupal kernel + SQLite |
| **Speed** | Fast (~ms per test) | Slower (~s per test) |
| **Scope** | Pure logic, mapping, parsing | Entity CRUD, queries, config |
| **Filesystem** | None | `public://` (test-scoped temp directory) |
| **Location** | `tests/src/Unit/` | `tests/src/Kernel/` |
| **Multisite-safe** | Yes | Yes (with `/tmp` SQLite) |

Most consumers only need to run unit tests.
Kernel tests are intended for module maintainers, CI pipelines, and
contributors modifying entity, scanner, or archive logic.

## Running kernel tests

Kernel tests require an SQLite database provided via the
`SIMPLETEST_DB` environment variable.

> **Rule:** The SQLite file must live outside the site directory
> (use `/tmp` or the system temp directory).

### Recommended (macOS, Linux, WSL, CI)

```bash
cd /path/to/drupal-site/web

SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/digital_asset_inventory/tests/src/Kernel
```

**Notes:**

- Run from the `web/` directory (not the site root)
- `-c core/phpunit.xml.dist` provides Drupal's bootstrap and autoloader
- The double slash after `localhost` is required (absolute path)
- `$$` creates a unique database file per run (safe for parallel tests)
- Kernel tests use `public://` for file operations (maps to a test-scoped temp directory; needed for `verifyIntegrity()` URL patterns)

### Single kernel test or class

```bash
cd /path/to/drupal-site/web

SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel.sqlite" \
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  --filter ArchiveWorkflowKernelTest \
  modules/custom/digital_asset_inventory/tests/src/Kernel
```

Using a fixed filename is helpful when inspecting the SQLite database
manually.

## Platform notes

### macOS / Linux

No special handling required. Use the commands above.

### Windows (WSL)

Running tests inside WSL is fully supported and recommended.

```bash
cd /path/to/drupal-site/web
export SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite"
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/custom/digital_asset_inventory/tests/src/Kernel
```

**Best practices:**

- Keep the project inside the Linux filesystem (for example `~/projects`)
- Avoid running from `/mnt/c/...` paths if you encounter performance or
  permission issues

### Lando

Run PHPUnit inside the container so PHP extensions match the site:

```bash
lando ssh -c '
  cd web
  export SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite"
  ../vendor/bin/phpunit -c core/phpunit.xml.dist \
    modules/custom/digital_asset_inventory/tests/src/Kernel
'
```

The container must have `pdo_sqlite` enabled (most Drupal recipes do).

### DDEV

Same approach as Lando:

```bash
ddev ssh -c '
  cd web
  export SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite"
  ../vendor/bin/phpunit -c core/phpunit.xml.dist \
    modules/custom/digital_asset_inventory/tests/src/Kernel
'
```

## Debugging kernel tests

### PHPUnit verbose flags

```bash
cd /path/to/drupal-site/web
export SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel.sqlite"

../vendor/bin/phpunit -c core/phpunit.xml.dist \
  --testdox \
  --stop-on-failure \
  modules/custom/digital_asset_inventory/tests/src/Kernel
```

- `--testdox` prints test names as they run
- `--stop-on-failure` stops immediately on first failure

### Debug dump helpers (opt-in)

The base test class provides table dump helpers that write to a file.
Drupal kernel tests run in a child process where STDOUT/STDERR are
captured, so dumps go to a file you can tail in a separate terminal.

Debug output is written to `tests/artifacts/dai-debug-dump.txt` inside
the module directory. This file is `.gitignore`d and never committed.
Because it lives in the project tree, you can find it easily:

```text
modules/custom/digital_asset_inventory/tests/artifacts/dai-debug-dump.txt
```

The dump file is **automatically cleared** at the start of each test
run — no manual cleanup needed between runs. (Detection uses the
PHPUnit parent process ID via `posix_getppid()`.)

**Environment variables:**

| Variable | Default | Purpose |
| --- | --- | --- |
| `DAI_TEST_DEBUG` | (off) | Enable dump helpers |
| `DAI_TEST_DUMP_DB` | (off) | Write the SQLite DB path to the dump file |
| `DAI_TEST_DUMP_FILE` | `tests/artifacts/dai-debug-dump.txt` | Override dump file location |

**Usage — run in two terminals:**

Terminal 1 (watch dumps — run from `web/`):

```bash
tail -f modules/custom/digital_asset_inventory/tests/artifacts/dai-debug-dump.txt
```

Terminal 2 (run tests — also from `web/`):

```bash
DAI_TEST_DEBUG=1 DAI_TEST_DUMP_DB=1 \
SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel.sqlite" \
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  --filter testQueueAndExecutePublic \
  modules/custom/digital_asset_inventory/tests/src/Kernel
```

**Reading results after a test run:**

```bash
cat modules/custom/digital_asset_inventory/tests/artifacts/dai-debug-dump.txt
```

**Available dump methods** (in test code):

```php
// Dump specific tables (gated by DAI_TEST_DEBUG=1):
$this->dumpArchivesTable('after execute');
$this->dumpItemsTable();
$this->dumpUsageTable();

// Generic table dump with custom columns:
$this->dumpTable('dai_archive_note', ['id', 'archive_id', 'note_text']);
```

**Example output:**

```text
DAI DEBUG: SIMPLETEST_DB=sqlite://localhost//tmp/dai-kernel.sqlite

DAI DEBUG: archives (digital_asset_archive) — after execute
  id | status          | file_name    | asset_type | archive_reason
  ---+-----------------+--------------+------------+---------------
  1  | archived_public | test-doc.pdf | pdf        | reference
```

Labels use fixed `DAI DEBUG:` prefixes so CI logs are grep-friendly:

```bash
grep "DAI DEBUG: archives" modules/custom/digital_asset_inventory/tests/artifacts/dai-debug-dump.txt
```

### Why the SQLite database is empty after a run

Drupal's `KernelTestBase::tearDown()` drops all tables after each test,
so the SQLite file is always empty once the run completes. You **cannot**
query it after the fact:

```bash
# This will always fail — tables are gone:
sqlite3 /tmp/dai-kernel.sqlite "SELECT * FROM digital_asset_archive;"
# Error: no such table: digital_asset_archive
```

The debug dump helpers (above) are the primary way to see database
state. They capture table data during the test and write it to the
artifacts file before tearDown erases everything.

If you need to run ad-hoc SQL queries against the live database, add a
temporary `sleep()` call in your test code to pause execution:

```php
$this->dumpArchivesTable('before sleep');
sleep(120);  // Remove after debugging!
```

Then in a separate terminal while the test is paused:

```bash
sqlite3 /tmp/dai-kernel.sqlite ".tables"
sqlite3 /tmp/dai-kernel.sqlite \
  "SELECT id, status, file_name FROM digital_asset_archive;"
```

Inside Lando/DDEV, run `sqlite3` inside the container (`lando ssh` or
`ddev ssh`).

### Cleanup

The dump file auto-clears on each new test run. To manually clean up
all artifacts and SQLite databases:

```bash
# From web/ directory:
rm -f modules/custom/digital_asset_inventory/tests/artifacts/dai-debug-dump.txt
rm -f modules/custom/digital_asset_inventory/tests/artifacts/dai-debug-dump.txt.run
rm -f /tmp/dai-kernel*.sqlite
```

The `tests/artifacts/` directory is `.gitignore`d so leftover files
are never committed accidentally.

## Gitignore note

PHPUnit generates a `.phpunit.result.cache` file when running tests. Since
this module is installed into consuming site projects, it does not ship its
own top-level `.gitignore`.

Add the following to the **site-level** `.gitignore`:

```gitignore
# Ignore PHPUnit cache
.phpunit.result.cache
```

## Summary

- Unit tests are fast and sufficient for most consumers
- Kernel tests validate real entity, database, and service behavior
- Tests are portable across macOS, Linux, WSL, Lando, and DDEV
- SQLite in `/tmp` and test-scoped `public://` ensures multisite safety
