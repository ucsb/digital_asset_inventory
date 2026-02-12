# Kernel Testing Specification

## Overview

This specification defines the PHPUnit **kernel testing** strategy for the Digital Asset Inventory module. Kernel tests complement the 299 existing unit tests by exercising real entity CRUD, database queries, status transitions, and service interactions through an actual Drupal kernel bootstrap with SQLite.

**Scope:** Entity lifecycle operations, archive state machine transitions, scanner atomic swap pattern, and service-level integration. Browser rendering, Views, batch forms, and response subscribers are out of scope (covered by functional/browser tests).

**Targets:** Five test classes covering 59 cases across `ArchiveService`, `DigitalAssetScanner`, configuration flags, orphan references, and the five custom entity types.

**Complements:** Unit tests (299 cases) cover pure-logic methods with mocked dependencies. Kernel tests validate that those methods work correctly with real entities and a real database.

### When to Run Kernel Tests

Kernel tests are intended for:
- Module maintainers
- CI pipelines validating releases
- Advanced contributors modifying archive logic, scanner behavior, or entities

Kernel tests are **not required** for:
- Site builders
- Content editors
- Teams consuming the module without modifying it

Unit tests provide sufficient safety for most consumers.

---

## 1. Infrastructure

### 1.1 Directory Structure

```text
digital_asset_inventory/
├── phpunit.xml.dist           <- module-scoped source coverage + kernel suite
├── tests/
│   ├── README.md                 <- testing guide (updated with kernel section)
│   ├── artifacts/
│   │   └── dai-debug-dump.txt    <- debug dump output (opt-in via DAI_TEST_DEBUG=1)
│   └── src/
│       ├── Unit/
│       │   ├── FilePathResolverTest.php
│       │   ├── DigitalAssetScannerTest.php
│       │   └── ArchiveServiceTest.php
│       └── Kernel/
│           ├── DigitalAssetKernelTestBase.php      <- shared base class + debug helpers
│           ├── ArchiveIntegrityKernelTest.php      <- integrity checks + auto-void + reconcile flags
│           ├── ArchiveWorkflowKernelTest.php       <- state machine + usage policy + flag persistence
│           ├── ConfigFlagsKernelTest.php            <- config flag → service behavior mapping
│           ├── ScannerAtomicSwapKernelTest.php     <- atomic swap + entity CRUD
│           └── OrphanReferenceKernelTest.php       <- orphan reference CRUD + atomic swap integration
└── src/
    ├── Entity/
    │   ├── DigitalAssetArchive.php
    │   ├── DigitalAssetItem.php
    │   ├── DigitalAssetOrphanReference.php
    │   ├── DigitalAssetUsage.php
    │   └── DigitalAssetArchiveNote.php
    └── Service/
        ├── ArchiveService.php
        └── DigitalAssetScanner.php
```

### 1.2 PHPUnit Configuration

The module's `phpunit.xml.dist` adds a `kernel` test suite alongside the existing `unit` suite:

```xml
<testsuites>
  <testsuite name="unit">
    <directory>tests/src/Unit</directory>
  </testsuite>
  <testsuite name="kernel">
    <directory>tests/src/Kernel</directory>
  </testsuite>
</testsuites>
```

### 1.3 Database Setup (SQLite)

Drupal's `KernelTestBase` requires the `SIMPLETEST_DB` environment variable. SQLite requires no external server.

**Important:** The SQLite DSN requires an **absolute path** with a double slash after `localhost`. Use `/tmp` (or the system temp directory) — never place the SQLite file inside the site directory (e.g., `sites/default/files/`), because multisite and Site Factory environments may have per-site `settings.php` that restricts that path.

```bash
# Recommended: /tmp with per-run unique name (safe for parallel CI):
SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
  ./vendor/bin/phpunit web/modules/custom/digital_asset_inventory/tests/src/Kernel

# Simple (single developer, no parallel runs):
SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel.sqlite" \
  ./vendor/bin/phpunit web/modules/custom/digital_asset_inventory/tests/src/Kernel

# Or set in phpunit.xml (use absolute path):
<env name="SIMPLETEST_DB" value="sqlite://localhost//tmp/dai-kernel.sqlite"/>
```

**Why `/tmp`?** The SQLite file is ephemeral (each test gets a fresh database). Placing it in `/tmp` avoids:
- Permission errors on multisite `sites/<name>/files/` directories
- Cluttering the site directory with test artifacts
- Path assumptions tied to a specific site configuration

**Common pitfall:** `sqlite://localhost/tmp/dai-kernel.sqlite` (single slash after localhost) is treated as a relative path and fails on many setups. Always use **double slash** + absolute path: `sqlite://localhost//tmp/...`.

**Per-run unique paths:** Use `$$` (shell PID) or `$RANDOM` in the filename to avoid collisions when multiple developers or CI jobs run tests in parallel on the same machine.

Each test gets a fresh database automatically.

### 1.4 Module Dependencies — Lean Strategy

The module declares dependencies on `drupal:media`, `drupal:views`, `better_exposed_filters`, `views_data_export`, and `csv_serialization`. However, **kernel tests should not load modules they don't need**. Loading Views + contrib increases boot time and introduces failure surface (missing config, schema, services, plugin discovery).

**Strategy: Start minimal, add only when a test truly needs it.**

The services under test (`ArchiveService`, `DigitalAssetScanner`) need entity storage, database, file system, config, and current user — none of which require Views or BEF.

```php
protected static $modules = [
  'system',
  'user',
  'file',
  'field',
  'text',
  'options',
  'filter',
  'serialization',
  'digital_asset_inventory',
];
```

**If module enable fails** because Drupal enforces declared dependencies:
1. Try adding `media` and `image` (core modules, moderate weight)
2. If contrib deps are still enforced, **don't enable the module** — instead manually install entity schemas and config, then instantiate services from the container
3. As a last resort, add the contrib modules (but expect slower tests and occasional CI flakiness)

**Fallback without module enable:**

```php
// Skip digital_asset_inventory in $modules, then manually:
$this->installEntitySchema('digital_asset_item');
$this->installEntitySchema('digital_asset_archive');
$this->installEntitySchema('dai_archive_note');
$this->installEntitySchema('digital_asset_usage');
$this->installConfig(['digital_asset_inventory']);
// Services come from the container if module code is autoloaded.
```

This fallback is appropriate when:
- Declared dependencies cannot be satisfied in the test environment
- The test targets service behavior, not hooks or event subscribers

It should **not** be used if testing module install behavior itself.

The implementation phase will iterate on this list — PHPUnit reports missing dependencies clearly.

### 1.5 Multisite Compatibility

Both unit and kernel tests are designed to work in multisite and Site Factory environments without modification.

**Unit tests** are fully multisite-safe by nature — they mock all Drupal services and never touch the filesystem, database, or site configuration. No `SIMPLETEST_DB` or `SIMPLETEST_BASE_URL` is needed.

**Kernel tests** are multisite-safe when two conditions are met:

1. **SQLite in `/tmp`**: The test database lives outside the site directory (Section 1.3). This avoids permission issues on `sites/<name>/files/` paths and works regardless of which site's `settings.php` is active.

2. **Test files use `public://`**: File creation helpers (Section 3.2.3) write to `public://` so that `generateAbsoluteString()` produces URLs with `/sites/.../files/` patterns that `urlPathToStreamUri()` can resolve back to stream URIs. This is required for `verifyIntegrity()`, which passes NULL for FID and relies on URL-to-stream conversion. In kernel tests, `public://` maps to a writable temp directory managed by the test framework.

**What about `SIMPLETEST_BASE_URL`?** Kernel tests do not render HTTP responses, so `SIMPLETEST_BASE_URL` is not required. If it's set in your environment (e.g., from running functional tests), it won't interfere.

**If you encounter permissions errors:**

```bash
# Check that /tmp is writable:
touch /tmp/dai-kernel-test && rm /tmp/dai-kernel-test

# If using a custom temp directory, ensure PHP can write to it:
php -r "echo sys_get_temp_dir();"
```

---

## 2. Conventions

### 2.1 Base Class

All kernel test classes extend the shared `DigitalAssetKernelTestBase`, which itself extends `Drupal\KernelTests\KernelTestBase`. The base class provides:

- Real Drupal kernel bootstrap (from `KernelTestBase`)
- SQLite database per test
- `$this->installEntitySchema()` for entity table creation
- `$this->installConfig()` for default configuration
- `$this->installSchema()` for non-entity tables
- `$this->container` for service access
- `$this->config()` for configuration manipulation
- Shared `setUp()` (entity schemas, config, current user, archive service)
- Fixture helpers (`createDocumentAsset()`, `createDocumentAssetWithFile()`, etc.)
- Configuration helpers (`setLegacyMode()`, `enableArchiveInUse()`, etc.)
- Debug dump helpers (`dumpArchivesTable()`, `dumpItemsTable()`, `dumpUsageTable()`)

`$strictConfigSchema = FALSE` is set because Views config in the module has incomplete schema definitions that are irrelevant to kernel test logic.

### 2.2 Class Annotations

```php
/**
 * Tests archive workflow state machine transitions with real entities.
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
class ArchiveWorkflowKernelTest extends DigitalAssetKernelTestBase {
```

- `@group digital_asset_inventory` — shared group with unit tests
- `@group digital_asset_inventory_kernel` — kernel-only filtering

### 2.3 Test Method Naming

Same convention as unit tests:

```
test<MethodOrWorkflow><Scenario>
```

Examples:

- `testQueueAndExecutePublic()`
- `testExecuteBlockedByUsage()`
- `testPromoteTemporaryItems()`

### 2.4 Assertions

Same conventions as unit tests:

- Prefer `assertSame()` over `assertEquals()` for type-strict comparisons
- Use `assertNull()` explicitly
- Use `assertCount()` for array length
- Use `assertTrue()` / `assertFalse()` for boolean checks
- Use `assertInstanceOf()` for entity type verification
- For exception tests: use `$this->expectException()` + `$this->expectExceptionMessageMatches()` (don't overfit message text)

---

## 3. Test Infrastructure

### 3.1 Common setUp() Pattern

All five kernel test classes extend `DigitalAssetKernelTestBase`, which provides a shared `setUp()`:

```php
// DigitalAssetKernelTestBase::setUp()
protected function setUp(): void {
  parent::setUp();

  // Auto-clear debug dump file at the start of each test run
  // (uses PPID marker to detect new runs — see Section 3.6).

  // Install entity schemas (user schema must come before creating users).
  $this->installEntitySchema('user');
  $this->installEntitySchema('file');
  $this->installEntitySchema('digital_asset_item');
  $this->installEntitySchema('digital_asset_archive');
  $this->installEntitySchema('dai_archive_note');
  $this->installEntitySchema('digital_asset_usage');
  $this->installConfig(['digital_asset_inventory']);
  $this->installSchema('file', ['file_usage']);

  // Set up current user (archive operations store archived_by as user ID).
  // Note: No permissions are granted — these tests exercise service-level
  // logic, not route access. If a test needs permissions, create roles here.
  $user = User::create([
    'name' => 'test_archiver',
    'mail' => 'test@example.com',
    'status' => 1,
  ]);
  $user->save();
  $this->container->get('current_user')->setAccount($user);

  $this->archiveService = $this->container->get('digital_asset_inventory.archive');
}
```

The `$modules` array includes all declared module dependencies (including `media`, `views`, and contrib modules). See `DigitalAssetKernelTestBase.php` for the full list.

### 3.1.1 tearDown() — Artifact Cleanup

Kernel tests create physical files in `public://` and an SQLite database in `/tmp`. Neither is automatically cleaned up by PHPUnit or Drupal's `KernelTestBase`. The base class tracks created file URIs and deletes them after each test:

```php
/** URIs of test files to clean up in tearDown(). */
protected array $testFileUris = [];

protected function tearDown(): void {
  // Clean up physical test files created during the test.
  if (!empty($this->testFileUris)) {
    $fs = $this->container->get('file_system');
    foreach ($this->testFileUris as $uri) {
      try {
        $fs->delete($uri);
      }
      catch (\Exception $e) {
        // File may already be deleted by the test (e.g., testReconcileFlagMissing).
      }
    }
  }
  parent::tearDown();
}
```

The `createTestFile()` helper (Section 3.2.3) registers each file URI in `$this->testFileUris` automatically.

**SQLite cleanup:** The SQLite file in `/tmp` is not deleted by the test framework. On most systems, `/tmp` is purged on reboot or by `systemd-tmpfiles` / `tmpreaper`. For CI environments, add a post-test cleanup step:

```bash
# Clean up stale SQLite files after test run:
rm -f /tmp/dai-kernel-*.sqlite
```

**Entity type ID reference:**
- `digital_asset_item` — `DigitalAssetItem.php`
- `digital_asset_archive` — `DigitalAssetArchive.php`
- `digital_asset_usage` — `DigitalAssetUsage.php`
- `dai_archive_note` — `DigitalAssetArchiveNote.php`

### 3.2 Helper Methods — Fixtures

#### 3.2.1 createDocumentAsset()

Creates a `DigitalAssetItem` fixture in the Documents category (archiveable).

```php
protected function createDocumentAsset(array $overrides = []): DigitalAssetItem {
  $values = [
    'file_name' => 'test-doc.pdf',
    'file_path' => '/sites/default/files/test-doc.pdf',
    'asset_type' => 'pdf',
    'category' => 'Documents',
    'mime_type' => 'application/pdf',
    'source_type' => 'file_managed',
    'is_temp' => FALSE,
  ] + $overrides;
  $asset = DigitalAssetItem::create($values);
  $asset->save();
  return $asset;
}
```

#### 3.2.2 createImageAsset()

Creates a `DigitalAssetItem` fixture in the Images category (not archiveable).

```php
protected function createImageAsset(): DigitalAssetItem {
  return $this->createDocumentAsset([
    'file_name' => 'photo.jpg',
    'file_path' => '/sites/default/files/photo.jpg',
    'asset_type' => 'jpg',
    'category' => 'Images',
    'mime_type' => 'image/jpeg',
  ]);
}
```

#### 3.2.3 createTestFile()

Creates a real physical file for checksum and integrity tests. Uses `public://` so that `generateAbsoluteString()` produces URLs with `/sites/.../files/` patterns that `urlPathToStreamUri()` can resolve back to stream URIs — this is critical for `verifyIntegrity()` which passes NULL for FID.

Uses Drupal's `FileSystem::saveData()` to avoid `realpath()` fallback issues.

```php
protected function createTestFile(
  string $filename = 'test-doc.pdf',
  string $content = 'test content'
): string {
  $fs = $this->container->get('file_system');
  $directory = 'public://';
  $fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
  $uri = 'public://' . $filename;
  $fs->saveData($content, $uri, FileExists::Replace);
  $this->testFileUris[] = $uri;
  return $uri;
}
```

**Why `public://`?** `verifyIntegrity()` needs URLs with `/sites/.../files/` patterns for `urlPathToStreamUri()` to convert back to stream URIs. The `temporary://` stream doesn't produce these patterns. In kernel tests, `public://` is managed by the test framework and is always writable.

#### 3.2.4 createFileEntity()

Creates a `file_managed` entity for a given URI. Required for checksum/integrity tests where `resolveSourceUri()` looks up the file by FID.

```php
protected function createFileEntity(
  string $uri,
  string $mime = 'application/pdf'
): \Drupal\file\FileInterface {
  $file = File::create([
    'uri' => $uri,
    'filename' => basename($uri),
    'filemime' => $mime,
    'status' => 1,
  ]);
  $file->save();
  return $file;
}
```

#### 3.2.5 createUsageRecord()

Creates a `DigitalAssetUsage` record referencing an asset.

```php
protected function createUsageRecord(
  DigitalAssetItem $asset,
  array $overrides = []
): DigitalAssetUsage {
  $values = [
    'asset_id' => $asset->id(),
    'entity_type' => 'node',
    'entity_id' => 1,
    'field_name' => 'body',
    'count' => 1,
    'embed_method' => 'text_link',
  ] + $overrides;
  $usage = DigitalAssetUsage::create($values);
  $usage->save();
  return $usage;
}
```

#### 3.2.6 createDocumentAssetWithFile()

Composite helper that creates a physical file, a `file_managed` entity, and a `DigitalAssetItem` with `fid` set — all wired together for deterministic path resolution.

```php
protected function createDocumentAssetWithFile(
  string $filename = 'test-doc.pdf',
  string $content = 'test content',
  array $overrides = []
): DigitalAssetItem {
  $uri = $this->createTestFile($filename, $content);
  $file = $this->createFileEntity($uri);

  // file_path is metadata only — resolution uses fid → file entity → URI.
  // The path value doesn't affect test behavior because resolveSourceUri()
  // prefers the FID path. We use a conventional path for readability.
  return $this->createDocumentAsset([
    'file_name' => $filename,
    'file_path' => '/sites/default/files/' . $filename,
    'fid' => $file->id(),
  ] + $overrides);
}
```

**Why this matters:** `ArchiveService::resolveSourceUri()` prefers `original_fid` → load file entity → get URI. Setting `fid` makes resolution deterministic and independent of base URL (eliminates "works locally, fails in CI" issues). The `file_path` value stored on the asset entity is metadata used for display and path-based fallback matching — it doesn't need to match the physical file location when `fid` is set.

**Why `public://` for the physical file?** See Section 1.5 and 3.2.3. The file entity's URI is `public://filename.pdf`, and `generateAbsoluteString()` produces URLs with `/sites/.../files/` patterns that `urlPathToStreamUri()` can resolve. This is critical for `verifyIntegrity()` which resolves the archive path without a FID.

### 3.3 Helper Methods — Configuration

#### 3.3.1 enableArchiveInUse()

```php
protected function enableArchiveInUse(): void {
  $this->config('digital_asset_inventory.settings')
    ->set('allow_archive_in_use', TRUE)
    ->save();
}
```

#### 3.3.2 disableArchiveInUse()

```php
protected function disableArchiveInUse(): void {
  $this->config('digital_asset_inventory.settings')
    ->set('allow_archive_in_use', FALSE)
    ->save();
}
```

#### 3.3.3 setLegacyMode()

Sets the ADA compliance deadline to far future so archives are classified as Legacy.

```php
protected function setLegacyMode(): void {
  $this->config('digital_asset_inventory.settings')
    ->set('ada_compliance_deadline', strtotime('3000-01-01'))
    ->save();
}
```

#### 3.3.4 setGeneralMode()

Sets the ADA compliance deadline to far past so archives are classified as General.

```php
protected function setGeneralMode(): void {
  $this->config('digital_asset_inventory.settings')
    ->set('ada_compliance_deadline', strtotime('2000-01-01'))
    ->save();
}
```

### 3.4 Helper Methods — Workflow Shortcuts

#### 3.4.1 queueAsset()

Queues a document asset for archive and returns the archive entity.

```php
protected function queueAsset(DigitalAssetItem $asset): DigitalAssetArchive {
  return $this->archiveService->markForArchive(
    $asset, 'reference', '', 'Test description for public registry.'
  );
}
```

#### 3.4.2 queueAndExecute()

Queues and executes an archive in one step. Returns the reloaded archive entity.

```php
protected function queueAndExecute(
  DigitalAssetItem $asset,
  string $visibility = 'public'
): DigitalAssetArchive {
  $archive = $this->queueAsset($asset);
  $this->archiveService->executeArchive($archive, $visibility);
  // Reload to get updated field values.
  return DigitalAssetArchive::load($archive->id());
}
```

### 3.5 Helper Methods — Counting

```php
/** Count all entities of a given type. */
protected function countEntities(string $entity_type_id): int {
  return (int) $this->container->get('entity_type.manager')
    ->getStorage($entity_type_id)
    ->getQuery()
    ->accessCheck(FALSE)
    ->count()
    ->execute();
}
```

### 3.6 Debug Dump Infrastructure

Kernel tests run in child processes where STDOUT and STDERR are captured by PHPUnit. To make entity state visible during development, the base class provides an opt-in debug dump system.

#### Activation

Set the `DAI_TEST_DEBUG=1` environment variable:

```bash
DAI_TEST_DEBUG=1 SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
  php vendor/bin/phpunit -c /abs/path/web/core/phpunit.xml.dist --group digital_asset_inventory_kernel
```

#### Output File

Dumps are written to `tests/artifacts/dai-debug-dump.txt` (relative to the module). Override with `DAI_TEST_DUMP_FILE` environment variable.

The file is automatically cleared at the start of each test run using a PPID marker file (`dai-debug-dump.txt.run`). Each kernel test runs in a separate child process, but all share the same parent PID within a single PHPUnit run. When a new PPID is detected, old dump data is cleared.

#### Dump Helpers

| Method | Dumps | Key Columns |
|--------|-------|-------------|
| `dumpArchivesTable($label)` | `digital_asset_archive` | id, status, file_name, flags, file_checksum |
| `dumpItemsTable($label)` | `digital_asset_item` | id, file_name, file_path, category, fid, is_temp |
| `dumpUsageTable($label)` | `digital_asset_usage` | id, asset_id, entity_type, field_name, embed_method |

All three delegate to `dumpTable()`, which produces column-aligned text tables with a `DAI DEBUG:` prefix:

```
DAI DEBUG: archives (digital_asset_archive) — after execute
  id | status          | file_name    | asset_type | flag_integrity | flag_usage | ...
  ---+-----------------+--------------+------------+----------------+------------+----
  1  | archived_public | test-doc.pdf | pdf        | 0              | 0          | ...
```

Empty tables display `(empty)`.

#### Usage in Tests

Every kernel test that creates entity state includes debug dump calls at the end (and sometimes at intermediate states). `ConfigFlagsKernelTest` is intentionally excluded — it tests config → service behavior mapping with no entity state to dump.

#### Live Monitoring

In a separate terminal:

```bash
tail -f web/modules/custom/digital_asset_inventory/tests/artifacts/dai-debug-dump.txt
```

---

## 4. Test Coverage Plan

### 4.1 ArchiveWorkflowKernelTest (16 cases)

**File:** `tests/src/Kernel/ArchiveWorkflowKernelTest.php`

Tests the archive state machine with real entities and the `ArchiveService`. Uses shared `setUp()` from `DigitalAssetKernelTestBase`.

#### Group A: File-Based State Machine (7 tests)

##### Test 1: `testQueueAndExecutePublic()` — F1 + F2

| Aspect | Detail |
|--------|--------|
| **Covers** | `markForArchive()` → `executeArchive('public')` |
| **Matrix refs** | F1 (queue), F2 (execute public) |
| **Test case refs** | TC-ARCH-001, TC-ARCH-002 |

**Setup:**
- Create document asset with file via `createDocumentAssetWithFile()`

**Steps:**
1. Call `markForArchive($asset, 'reference', '', 'Test description.')`
2. Assert: archive entity created, `getStatus()` === `'queued'`
3. Assert: `getArchivedBy()` returns current user ID
4. Assert: `getFileName()` === `'test-doc.pdf'`
5. Assert: `getArchiveReason()` === `'reference'`
6. Call `executeArchive($archive, 'public')`
7. Reload archive entity
8. Assert: `getStatus()` === `'archived_public'`
9. Assert: `get('archive_classification_date')->value` is not NULL (timestamp set)
10. Assert: `getFileChecksum()` is 64-character hexadecimal string (`/^[a-f0-9]{64}$/`)
11. Assert: `isArchivedPublic()` === TRUE
12. Assert: `isArchivedActive()` === TRUE

---

##### Test 2: `testQueueAndExecuteAdmin()` — F1 + F3

| Aspect | Detail |
|--------|--------|
| **Covers** | `markForArchive()` → `executeArchive('admin')` |
| **Matrix refs** | F1 (queue), F3 (execute admin) |
| **Test case refs** | TC-ARCH-003 |

**Setup:** Same as Test 1 — `createDocumentAssetWithFile()`.

**Steps:**
1. Queue asset
2. Call `executeArchive($archive, 'admin')`
3. Reload archive entity
4. Assert: `getStatus()` === `'archived_admin'`
5. Assert: `isArchivedAdmin()` === TRUE
6. Assert: `isArchivedActive()` === TRUE
7. Assert: `isArchivedPublic()` === FALSE

---

##### Test 3: `testExecuteBlockedByUsage()` — F2 blocked + AIU-3

| Aspect | Detail |
|--------|--------|
| **Covers** | `validateExecutionGates()` with active usage |
| **Matrix refs** | AIU-3 (execute blocked when in use + config disabled) |
| **Test case refs** | TC-ARCH-005, TC-AIU-003 |

**Setup:**
- Create document asset with file via `createDocumentAssetWithFile()`
- Queue the asset
- Create a `DigitalAssetUsage` record referencing the asset
- Ensure `allow_archive_in_use` is FALSE (default)

**Steps:**
1. Call `validateExecutionGates($archive)`
2. Assert: returns non-empty array
3. Assert: array has key `'usage_policy_blocked'`
4. Assert: `$gates['usage_policy_blocked']['usage_count']` > 0
5. Assert: `executeArchive()` throws `\Exception` (blocked by gates)

---

##### Test 4: `testToggleVisibility()` — F5 + F6

| Aspect | Detail |
|--------|--------|
| **Covers** | `toggleVisibility()` between public and admin |
| **Matrix refs** | F5 (public → admin), F6 (admin → public) |
| **Test case refs** | TC-ARCH-004 |

**Setup:**
- `createDocumentAssetWithFile()` → queue and execute as public

**Steps:**
1. Assert: `isArchivedPublic()` === TRUE
2. Call `toggleVisibility($archive)`
3. Reload archive
4. Assert: `isArchivedAdmin()` === TRUE
5. Call `toggleVisibility($archive)`
6. Reload archive
7. Assert: `isArchivedPublic()` === TRUE

---

##### Test 5: `testUnarchive()` — F7 + F8

| Aspect | Detail |
|--------|--------|
| **Covers** | `unarchive()` transitions to terminal status and clears flags |
| **Matrix refs** | F7 (unarchive public), F8 (unarchive admin) |
| **Test case refs** | TC-ARCH-007 |

**Setup:**
- `createDocumentAssetWithFile()` → queue and execute as public

**Steps:**
1. Call `unarchive($archive)`
2. Reload archive
3. Assert: `getStatus()` === `'archived_deleted'`
4. Assert: `isArchivedDeleted()` === TRUE
5. Assert: entity still exists (load by ID returns non-NULL)
6. Assert: `hasFlagUsage()` === FALSE (flags cleared)
7. Assert: `hasFlagMissing()` === FALSE (flags cleared)
8. Assert: `hasFlagIntegrity()` === FALSE (flags cleared)

---

##### Test 6: `testRemoveFromQueue()` — F4

| Aspect | Detail |
|--------|--------|
| **Covers** | `removeFromQueue()` deletes entity entirely |
| **Matrix refs** | F4 (remove from queue) |
| **Test case refs** | TC-ARCH-008 |

**Setup:**
- Create document asset, queue it

**Steps:**
1. Record archive ID
2. Call `removeFromQueue($archive)`
3. Assert: `DigitalAssetArchive::load($id)` returns NULL (entity deleted)

---

##### Test 7: `testTerminalStatesBlockOperations()` — F15 + F16

| Aspect | Detail |
|--------|--------|
| **Covers** | Terminal states prevent further operations |
| **Matrix refs** | F15 (exemption_void blocks), F16 (archived_deleted blocks) |
| **Test case refs** | TC-VOID-007 |

**Setup:**
- Create two document assets with files
- Queue and execute both as public
- Unarchive the first (→ `archived_deleted`)
- For the second, manually set status to `exemption_void` via direct entity save

**Steps — archived_deleted:**
1. Assert: `canExecuteArchive()` === FALSE
2. Assert: `canUnarchive()` === FALSE
3. Assert: `canToggleVisibility()` === FALSE
4. Assert: `canRemoveFromQueue()` === FALSE

**Steps — exemption_void:**
5. Assert: `canExecuteArchive()` === FALSE
6. Assert: `canUnarchive()` === TRUE (corrective action: transitions to `archived_deleted`)
7. Assert: `canToggleVisibility()` === FALSE
8. Assert: `canRemoveFromQueue()` === FALSE

**Note on exemption_void + unarchive:** The entity method `canUnarchive()` returns `$this->isArchivedActive() || $this->isExemptionVoid()`, explicitly allowing unarchive as a corrective action. This transitions the entity to `archived_deleted` and clears all flags.

---

#### Group B: Archive Type Rules (2 tests)

##### Test 8: `testLegacyArchiveBeforeDeadline()` — AT-1

| Aspect | Detail |
|--------|--------|
| **Covers** | Legacy Archive classification |
| **Matrix refs** | AT-1 (before deadline = Legacy) |
| **Test case refs** | TC-DUAL-001 |

**Setup:**
- Call `setLegacyMode()` (deadline far future)
- `createDocumentAssetWithFile()`

**Steps:**
1. Queue and execute as public
2. Reload archive
3. Assert: `hasFlagLateArchive()` === FALSE
4. Assert: `$this->archiveService->isLegacyArchive($archive)` === TRUE

---

##### Test 9: `testGeneralArchiveAfterDeadline()` — AT-2

| Aspect | Detail |
|--------|--------|
| **Covers** | General Archive classification |
| **Matrix refs** | AT-2 (after deadline = General) |
| **Test case refs** | TC-DUAL-002 |

**Setup:**
- Call `setGeneralMode()` (deadline far past)
- `createDocumentAssetWithFile()`

**Steps:**
1. Queue and execute as public
2. Reload archive
3. Assert: `hasFlagLateArchive()` === TRUE
4. Assert: `$this->archiveService->isLegacyArchive($archive)` === FALSE

---

#### Group C: Usage Policy Edge Cases (4 tests)

##### Test 10: `testArchiveInUseAllowed()` — AIU-20 + AIU-21

| Aspect | Detail |
|--------|--------|
| **Covers** | Archive succeeds when `allow_archive_in_use` is enabled |
| **Matrix refs** | AIU-20 (queue allowed), AIU-21 (execute allowed) |
| **Test case refs** | TC-AIU-004, TC-AIU-005 |

**Setup:**
- Call `enableArchiveInUse()`
- `createDocumentAssetWithFile()`
- Create usage record referencing the asset

**Steps:**
1. Queue asset — assert: succeeds (no exception)
2. Execute archive as public — assert: succeeds (no exception)
3. Reload archive
4. Assert: `getStatus()` === `'archived_public'`
5. Assert: `get('archived_while_in_use')->value` == TRUE
6. Assert: `get('usage_count_at_archive')->value` > 0

---

##### Test 11: `testToggleVisibilityBlockedWhenInUse()` — EC7

| Aspect | Detail |
|--------|--------|
| **Covers** | `isVisibilityToggleBlocked()` blocks admin → public when in use |
| **Matrix refs** | EC7 (Make Public blocked) |
| **Test case refs** | TC-AIU-006 |

**Setup:**
- Enable `allow_archive_in_use`
- `createDocumentAssetWithFile()` + usage record
- Queue and execute as admin (works because in-use is allowed)
- Call `disableArchiveInUse()`

**Steps:**
1. Call `isVisibilityToggleBlocked($archive)`
2. Assert: returns non-NULL array
3. Assert: array has `'usage_count'` key with value > 0
4. Assert: array has `'reason'` key (non-empty string)

---

##### Test 12: `testUnarchiveAlwaysAllowed()` — EC1 + AIU-5

| Aspect | Detail |
|--------|--------|
| **Covers** | Unarchive succeeds even when in-use + config disabled |
| **Matrix refs** | EC1 (unarchive after config disabled), AIU-5 (corrective action) |
| **Test case refs** | TC-AIU-008 |

**Setup:**
- Enable `allow_archive_in_use`
- `createDocumentAssetWithFile()` + usage record
- Queue and execute as public
- Call `disableArchiveInUse()`

**Steps:**
1. Call `unarchive($archive)` — assert: succeeds (no exception)
2. Reload archive
3. Assert: `getStatus()` === `'archived_deleted'`

---

##### Test 13: `testUsageMatchingByFidAndPath()` — Usage gate dual matching

| Aspect | Detail |
|--------|--------|
| **Covers** | `getUsageCountByArchive()` matches by FID or file_path |
| **Matrix refs** | -- |
| **Test case refs** | -- (high-ROI regression test for dual matching logic) |

This test validates that the usage gate works via both matching strategies: FID-based and path-based. Unit tests can't catch regressions here because they mock entity field access.

**Case 1 — Match by FID:**
1. Create file entity at `public://doc-a.pdf` (via `createTestFile()` + `createFileEntity()`)
2. Create asset with `fid` = file entity ID, `file_path` = `/sites/default/files/doc-a.pdf`
3. Create usage record for the asset
4. Queue asset → archive entity gets `original_fid` = file entity ID
5. Call `getUsageCountByArchive($archive)`
6. Assert: returns 1 (matched via FID → asset → usage)

**Case 2 — Match by path (no FID):**
7. Create asset with `fid` = NULL, `file_path` = `/sites/default/files/doc-b.pdf`
8. Create usage record for this asset
9. Queue asset → archive entity gets `original_fid` = NULL, `original_path` = `/sites/default/files/doc-b.pdf`
10. Call `getUsageCountByArchive($archive)`
11. Assert: returns 1 (matched via file_path → asset → usage)

**Why this matters:** If `getUsageCountByArchive()` only checks FID, external/filesystem assets (which have no FID) would silently pass the usage gate even when they have active references.

---

#### Group D: Flag Persistence and Operation Restrictions (3 tests)

##### Test 14: `testWarningFlagsSurviveVisibilityToggle()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Warning flags persist across `toggleVisibility()` |
| **Matrix refs** | -- |
| **Test case refs** | -- (regression test for flag preservation) |

**Setup:**
- `createDocumentAssetWithFile()` + usage record
- Enable `allow_archive_in_use`
- Queue and execute as public (flag_usage set during execute)

**Steps:**
1. Assert: `hasFlagUsage()` === TRUE
2. Toggle public → admin
3. Reload archive
4. Assert: `isArchivedAdmin()` === TRUE
5. Assert: `hasFlagUsage()` === TRUE (survives toggle)
6. Toggle admin → public
7. Reload archive
8. Assert: `isArchivedPublic()` === TRUE
9. Assert: `hasFlagUsage()` === TRUE (survives toggle)

---

##### Test 15: `testUnarchiveFromAdminVisibility()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Unarchive from `archived_admin` transitions to `archived_deleted` |
| **Matrix refs** | F8 (unarchive admin) |
| **Test case refs** | -- |

**Setup:**
- `createDocumentAssetWithFile()` → queue and execute as admin

**Steps:**
1. Assert: `isArchivedAdmin()` === TRUE
2. Call `unarchive($archive)`
3. Reload archive
4. Assert: `getStatus()` === `'archived_deleted'`
5. Assert: `isArchivedDeleted()` === TRUE
6. Assert: all flags cleared (`hasFlagUsage()`, `hasFlagMissing()`, `hasFlagIntegrity()` all FALSE)

---

##### Test 16: `testQueuedStatusOperationRestrictions()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Queued items allow execute/remove but block toggle/unarchive |
| **Matrix refs** | -- |
| **Test case refs** | -- (edge case validation) |

**Setup:**
- Create document asset (no file needed), queue it

**Steps:**
1. Assert: `getStatus()` === `'queued'`
2. Assert: `canExecuteArchive()` === TRUE
3. Assert: `canRemoveFromQueue()` === TRUE
4. Assert: `canToggleVisibility()` === FALSE
5. Assert: `canUnarchive()` === FALSE

---

### 4.2 ArchiveIntegrityKernelTest (8 cases)

**File:** `tests/src/Kernel/ArchiveIntegrityKernelTest.php`

Tests integrity verification, automatic status changes on file modification, immutability constraints, and reconcile flag behavior (flag_usage set/clear).

Uses shared `setUp()` from `DigitalAssetKernelTestBase`.

#### Group D: Integrity Enforcement (4 tests)

##### Test 17: `testLegacyIntegrityViolationVoidsExemption()` — F11 + MOD-1

| Aspect | Detail |
|--------|--------|
| **Covers** | Legacy Archive + checksum mismatch → `exemption_void` |
| **Matrix refs** | F11 (integrity violation, Legacy), MOD-1 |
| **Test case refs** | TC-VOID-001, TC-DUAL-008 |

**Setup:**
- Call `setLegacyMode()`
- `createDocumentAssetWithFile('integrity-test.pdf', 'original content')`
- Queue and execute as public

**Precondition assertions:**
1. Assert: `getFileChecksum()` matches `/^[a-f0-9]{64}$/`
2. Assert: `$this->archiveService->verifyIntegrity($archive)` === TRUE

**Steps:**
3. Overwrite the physical file content:
   ```php
   $fs = $this->container->get('file_system');
   $fs->saveData('modified content', 'public://integrity-test.pdf',
     FileExists::Replace);
   ```
4. Call `$this->archiveService->reconcileStatus($archive)`
5. Reload archive
6. Assert: `getStatus()` === `'exemption_void'`
7. Assert: `isExemptionVoid()` === TRUE
8. Assert: `hasFlagIntegrity()` === TRUE

---

##### Test 18: `testGeneralIntegrityViolationDeletes()` — F13 + MOD-3

| Aspect | Detail |
|--------|--------|
| **Covers** | General Archive + checksum mismatch → `archived_deleted` |
| **Matrix refs** | F13 (integrity violation, General), MOD-3 |
| **Test case refs** | TC-DUAL-017 |

**Setup:**
- Call `setGeneralMode()`
- `createDocumentAssetWithFile('general-test.pdf', 'original content')`
- Queue and execute as public

**Steps:**
1. Overwrite the physical file content
2. Call `reconcileStatus($archive)`
3. Reload archive
4. Assert: `getStatus()` === `'archived_deleted'`
5. Assert: `isArchivedDeleted()` === TRUE
6. Assert: `hasFlagIntegrity()` === TRUE

---

##### Test 19: `testReconcileFlagMissing()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `reconcileStatus()` detects missing file |
| **Matrix refs** | -- |
| **Test case refs** | TC-ARCH-006 |

**Setup:**
- `createDocumentAssetWithFile('missing-test.pdf')`
- Queue and execute as public

**Steps:**
1. Delete the physical file:
   ```php
   $this->container->get('file_system')->delete('public://missing-test.pdf');
   ```
2. Call `reconcileStatus($archive)`
3. Reload archive
4. Assert: `hasFlagMissing()` === TRUE

---

##### Test 20: `testReconcileNoChangeLeavesStateUnchanged()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `reconcileStatus()` with no modifications preserves state |
| **Matrix refs** | -- |
| **Test case refs** | -- |

**Setup:**
- `createDocumentAssetWithFile()`
- Queue and execute as public

**Steps:**
1. Call `reconcileStatus($archive)`
2. Reload archive
3. Assert: `getStatus()` === `'archived_public'` (unchanged)
4. Assert: `hasFlagMissing()` === FALSE
5. Assert: `hasFlagIntegrity()` === FALSE
6. Assert: `hasFlagUsage()` === FALSE
7. Assert: no new `dai_archive_note` entities created beyond the initial archive notes

**Note:** We do **not** assert on the entity's `changed` timestamp — that is brittle since `reconcileStatus()` may or may not call `$archive->save()` even when nothing changed. The meaningful assertion is that status and flags are unchanged.

---

#### Group D-Bonus: Advanced Integrity (2 tests)

##### Test 21: `testPriorVoidForcesGeneralArchive()` — AT-3 + RA-5

| Aspect | Detail |
|--------|--------|
| **Covers** | Prior voided exemption forces General Archive |
| **Matrix refs** | AT-3 (before deadline + prior void = General), RA-5 |
| **Test case refs** | TC-VOID-006 |

**Setup:**
- Call `setLegacyMode()`
- `createDocumentAssetWithFile('void-test.pdf', 'original')` → queue and execute
- Manually set status to `exemption_void` (simulates integrity violation)

**Steps:**
1. Create a second `DigitalAssetItem` pointing to the **same FID** as the first (the prior void lookup uses `original_fid`)
2. Queue the second asset
3. Execute the new archive
4. Reload new archive
5. Assert: `hasFlagPriorVoid()` === TRUE
6. Assert: `hasFlagLateArchive()` === TRUE (forced to General despite pre-deadline)

**Note:** This test depends on `executeArchive()` detecting prior `exemption_void` records by matching `original_fid`. If the lookup logic differs, the fixture setup will need adjustment during implementation.

---

##### Test 22: `testImmutabilityConstraints()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `preSave()` blocks changes to immutable fields |
| **Matrix refs** | -- |
| **Test case refs** | TC-ARCH-014, TC-DUAL-018, TC-DUAL-021 |

**Setup:**
- `createDocumentAssetWithFile()` → queue and execute as public

**Steps — archive_classification_date:**
1. Load archive entity
2. Modify `archive_classification_date` to a different timestamp
3. Assert: `$archive->save()` throws `\LogicException`
4. Assert: exception message matches `/immutable/i`

**Steps — file_checksum:**
5. Load archive entity fresh (new load to avoid stale state from caught exception)
6. Modify `file_checksum` to a different value
7. Assert: `$archive->save()` throws `\LogicException`
8. Assert: exception message matches `/immutable/i`

**Implementation pattern:**
```php
$this->expectException(\LogicException::class);
$this->expectExceptionMessageMatches('/immutable/i');
$archive->set('archive_classification_date', time() + 1000);
$archive->save();
```

**Note:** Since PHPUnit stops at the first `expectException`, test `archive_classification_date` and `file_checksum` in separate test methods if both need coverage. Or use a `try/catch` pattern for the first and `expectException` for the second.

---

#### Group D-Extra: Reconcile Flag Behavior (2 tests)

##### Test 23: `testReconcileSetsUsageFlag()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `reconcileStatus()` sets `flag_usage` when active references exist |
| **Matrix refs** | -- |
| **Test case refs** | -- (validates reconcile detects usage) |

**Setup:**
- `createDocumentAssetWithFile()` → queue and execute as public

**Steps:**
1. Assert: `hasFlagUsage()` === FALSE (no usage yet)
2. Create usage record for the asset
3. Call `reconcileStatus($archive)`
4. Reload archive
5. Assert: `hasFlagUsage()` === TRUE
6. Assert: return value contains `'flag_usage'`
7. Assert: `getStatus()` === `'archived_public'` (unchanged — usage flag is advisory)

---

##### Test 24: `testReconcileClearsUsageFlag()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `reconcileStatus()` clears `flag_usage` after references are removed |
| **Matrix refs** | -- |
| **Test case refs** | -- (validates reconcile clears usage flag) |

**Setup:**
- `createDocumentAssetWithFile()` + usage record
- Enable `allow_archive_in_use`
- Queue and execute as public (flag_usage set during execute)

**Steps:**
1. Assert: `hasFlagUsage()` === TRUE
2. Delete usage record
3. Reset entity static cache: `$storage->resetCache()`
4. Call `reconcileStatus($archive)`
5. Reload archive
6. Assert: `hasFlagUsage()` === FALSE
7. Assert: return value does NOT contain `'flag_usage'`
8. Assert: `getStatus()` === `'archived_public'` (unchanged)

**Note:** Entity static cache must be reset after cross-service deletes so `getUsageCountByArchive()` sees fresh data.

---

### 4.3 ScannerAtomicSwapKernelTest (11 cases)

**File:** `tests/src/Kernel/ScannerAtomicSwapKernelTest.php`

Tests the scanner's atomic swap pattern, entity CRUD, and entity-level queries that require a real database.

Uses shared `setUp()` from `DigitalAssetKernelTestBase`, plus adds scanner service:

```php
protected DigitalAssetScanner $scanner;

protected function setUp(): void {
  parent::setUp();
  $this->scanner = $this->container->get('digital_asset_inventory.scanner');
}
```

#### Group E: Atomic Swap Pattern (3 tests)

##### Test 25: `testPromoteTemporaryItems()` — TC-SCAN-004 success path

| Aspect | Detail |
|--------|--------|
| **Covers** | `promoteTemporaryItems()` atomic swap |
| **Matrix refs** | -- |
| **Test case refs** | TC-SCAN-004 (success path) |

**Setup:**
- Create 2 permanent `DigitalAssetItem` entities (`is_temp` = FALSE), record their IDs
- Create 1 usage record for each permanent item, record usage IDs
- Create 3 temporary `DigitalAssetItem` entities (`is_temp` = TRUE), record their IDs
- Create 1 usage record for each temporary item, record usage IDs

**Steps:**
1. Call `$this->scanner->promoteTemporaryItems()`
2. Assert: old permanent item IDs all load as NULL (deleted)
3. Assert: old usage record IDs all load as NULL (deleted)
4. Assert: temp item IDs all load as non-NULL (still exist)
5. Assert: all 3 items now have `get('is_temp')->value` == FALSE (promoted)
6. Assert: temp usage record IDs all load as non-NULL (preserved)
7. Assert: `$this->countEntities('digital_asset_item')` === 3
8. Assert: `$this->countEntities('digital_asset_usage')` === 3

**Validates:**
- Old usage records are deleted (no orphaned rows with old asset IDs)
- New usage records survive promotion
- Deletion order correct (usage before items)

---

##### Test 26: `testClearTemporaryItems()` — TC-SCAN-004 failure path

| Aspect | Detail |
|--------|--------|
| **Covers** | `clearTemporaryItems()` cleanup on failure |
| **Matrix refs** | -- |
| **Test case refs** | TC-SCAN-004 (failure path) |

**Setup:**
- Create 2 permanent items (`is_temp` = FALSE) with usage records, record all IDs
- Create 3 temporary items (`is_temp` = TRUE) with usage records, record all IDs

**Steps:**
1. Call `$this->scanner->clearTemporaryItems()`
2. Assert: temp item IDs all load as NULL (deleted)
3. Assert: temp usage IDs all load as NULL (deleted — no orphaned usage rows)
4. Assert: permanent item IDs all load as non-NULL (untouched)
5. Assert: permanent usage IDs all load as non-NULL (untouched)
6. Assert: permanent items still have `get('is_temp')->value` == FALSE
7. Assert: `$this->countEntities('digital_asset_item')` === 2

**Validates:** Scan failure preserves previous inventory data intact.

---

##### Test 27: `testClearUsageRecords()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `clearUsageRecords()` deletes all usage |
| **Matrix refs** | -- |
| **Test case refs** | -- |

**Setup:**
- Create 3 `DigitalAssetItem` entities
- Create 5 `DigitalAssetUsage` records across the 3 assets

**Steps:**
1. Assert: `$this->countEntities('digital_asset_usage')` === 5 (precondition)
2. Call `$this->scanner->clearUsageRecords()`
3. Assert: `$this->countEntities('digital_asset_usage')` === 0
4. Assert: `$this->countEntities('digital_asset_item')` === 3 (items untouched)

---

#### Group F: Entity CRUD Operations (3 tests)

##### Test 28: `testDigitalAssetItemCrud()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Full CRUD lifecycle for `DigitalAssetItem` |
| **Matrix refs** | -- |
| **Test case refs** | -- |

**Steps — Create:**
1. Create entity with all fields populated:
   - `file_name`, `file_path`, `asset_type`, `category`, `mime_type`, `source_type`, `is_private`, `is_temp`, `fid`, `filesize`
2. Save entity
3. Assert: `id()` is non-NULL (auto-increment)

**Steps — Read:**
4. Load entity by ID
5. Assert all field values match: `getFilename()`, `getFilepath()`, `getAssetType()`, `getCategory()`, `getMimeType()`, `isPrivate()`, `get('is_temp')->value`, `get('fid')->value`, `getFilesize()`

**Steps — Update:**
6. Call `setFilename('updated.pdf')`
7. Call `setCategory('Videos')`
8. Save entity
9. Reload entity
10. Assert: `getFilename()` === `'updated.pdf'`
11. Assert: `getCategory()` === `'Videos'`

**Steps — Delete:**
12. Delete entity
13. Assert: `DigitalAssetItem::load($id)` returns NULL

---

##### Test 29: `testDigitalAssetUsageDeletionOrder()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Usage → Item relationship and critical deletion order |
| **Matrix refs** | -- |
| **Test case refs** | Entity deletion order constraint |

**Steps:**
1. Create `DigitalAssetItem`, record ID
2. Create `DigitalAssetUsage` with `asset_id` = item ID
3. Assert: usage `get('asset_id')->target_id` === item ID
4. Delete usage first — assert: succeeds (no exception)
5. Delete item — assert: succeeds (no exception)
6. Assert: both entity IDs load as NULL

**Note on FK enforcement:** SQLite (used in kernel tests) does not enforce foreign key constraints by default. This test validates that the code's deletion order is correct, but won't catch violations via DB error. The promoteTemporaryItems/clearTemporaryItems tests provide stronger validation by asserting no orphaned usage rows exist after operations.

---

##### Test 30: `testDigitalAssetArchiveEntityFields()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `DigitalAssetArchive` field getters, flags, and type checks |
| **Matrix refs** | -- |
| **Test case refs** | -- |

**Steps — Create and verify getters:**
1. Create `DigitalAssetArchive` with all fields populated (status = `'queued'`, asset_type = `'pdf'`, etc.)
2. Save entity
3. Assert getters: `getStatus()`, `getFileName()`, `get('archive_path')->value`, `getFileChecksum()`, `getArchiveReason()`, `getAssetType()`, `getMimeType()`

**Steps — Verify boolean flags:**
4. Set `flag_usage` = TRUE, `flag_missing` = TRUE, `flag_integrity` = TRUE, `flag_modified` = TRUE
5. Save and reload
6. Assert: `hasFlagUsage()` === TRUE
7. Assert: `hasFlagMissing()` === TRUE
8. Assert: `hasFlagIntegrity()` === TRUE
9. Assert: `hasFlagModified()` === TRUE
10. Assert: `hasWarningFlags()` === TRUE

**Steps — Verify clearFlags():**
11. Call `clearFlags()`
12. Save and reload
13. Assert: `hasFlagUsage()` === FALSE
14. Assert: `hasFlagMissing()` === FALSE
15. Assert: `hasFlagIntegrity()` === FALSE
16. Assert: `hasFlagModified()` === FALSE

**Steps — Verify type checks:**
17. Assert: `isManualEntry()` === FALSE (asset_type = 'pdf')
18. Assert: `isFileArchive()` === TRUE
19. Create another archive with `asset_type` = `'page'`
20. Assert: `isManualEntry()` === TRUE
21. Assert: `isFileArchive()` === FALSE
22. Create another archive with `asset_type` = `'external'`
23. Assert: `isManualEntry()` === TRUE

---

#### Group G: Scanner Entity Queries (3 tests)

##### Test 31: `testGetManagedFilesCount()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `getManagedFilesCount()` with real file_managed table |
| **Matrix refs** | -- |
| **Test case refs** | TC-SCAN-001 (partial) |

**Setup:**
- `file` entity schema already installed in setUp
- Create 3 file entities with non-excluded URIs: `public://doc1.pdf`, `public://doc2.pdf`, `public://doc3.pdf`
- Create 1 file entity with excluded URI: `public://styles/thumbnail/doc.jpg`

**Steps:**
1. Call `$this->scanner->getManagedFilesCount()`
2. Assert: count === 3 (excluded file not counted)

**Note:** The exact exclusion patterns are in `excludeSystemGeneratedFiles()`. This test validates that the exclusion query works with real DB records. The `public://` URIs here are metadata stored in `file_managed` rows — no physical files are created, so multisite writability is not a concern.

---

##### Test 32: `testCanArchiveGating()` — TC-ARCH-001 precondition

| Aspect | Detail |
|--------|--------|
| **Covers** | `ArchiveService::canArchive()` with real entity objects |
| **Matrix refs** | -- |
| **Test case refs** | TC-ARCH-001 (precondition) |

**Steps:**
1. Create asset with `category` = `'Documents'` → assert: `canArchive()` === TRUE
2. Create asset with `category` = `'Videos'` → assert: `canArchive()` === TRUE
3. Create asset with `category` = `'Images'` → assert: `canArchive()` === FALSE
4. Create asset with `category` = `'Audio'` → assert: `canArchive()` === FALSE
5. Create asset with `category` = `'Other'` → assert: `canArchive()` === FALSE

**Note:** This overlaps with unit test coverage but validates real entity field access (not mocks).

---

##### Test 33: `testEntityCountHelpers()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Sanity check that entity storage CRUD works end-to-end |
| **Matrix refs** | -- |
| **Test case refs** | -- |

**Steps:**
1. Assert: `$this->countEntities('digital_asset_item')` === 0 (empty)
2. Create 3 items
3. Assert: count === 3
4. Delete 1 item
5. Assert: count === 2

This is a lightweight sanity check that the entity type definitions, schemas, and storage are wired up correctly — catches module enable / entity registration issues early.

---

### 4.4 ConfigFlagsKernelTest (10 cases)

**File:** `tests/src/Kernel/ConfigFlagsKernelTest.php`

Tests that configuration flags correctly control service behavior. These tests manipulate config values via `$this->config()` and assert that service methods reflect the changes. No entity state is created or dumped — these are pure config → service behavior tests.

Uses shared `setUp()` from `DigitalAssetKernelTestBase`.

#### Group H: Link Routing Gates (3 tests)

##### Test 34: `testLinkRoutingDisabledByDefault()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Default config: both `enable_archive` and `allow_archive_in_use` are FALSE |

**Steps:**
1. Assert: `isLinkRoutingEnabled()` === FALSE (no config changes, both flags default to FALSE)

---

##### Test 35: `testLinkRoutingEnabledWithArchiveEnabled()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `enable_archive` = TRUE enables link routing |

**Steps:**
1. Set `enable_archive` = TRUE
2. Assert: `isLinkRoutingEnabled()` === TRUE

---

##### Test 36: `testLinkRoutingFallbackToArchiveInUse()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Backwards compatibility: routing activates if only `allow_archive_in_use` is enabled |

**Steps:**
1. Set `enable_archive` = FALSE, `allow_archive_in_use` = TRUE
2. Assert: `isLinkRoutingEnabled()` === TRUE

---

#### Group I: Archive-in-Use Policy (1 test)

##### Test 37: `testArchiveInUseAllowedToggle()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `isArchiveInUseAllowed()` reflects config toggle |

**Steps:**
1. Assert: `isArchiveInUseAllowed()` === FALSE (default)
2. Call `enableArchiveInUse()`
3. Assert: `isArchiveInUseAllowed()` === TRUE
4. Call `disableArchiveInUse()`
5. Assert: `isArchiveInUseAllowed()` === FALSE

---

#### Group J: Archived Label Display (5 tests)

##### Test 38: `testShowArchivedLabelEnabledByDefault()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `shouldShowArchivedLabel()` defaults to TRUE |

**Steps:**
1. Assert: `shouldShowArchivedLabel()` === TRUE

---

##### Test 39: `testShowArchivedLabelDisabled()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `shouldShowArchivedLabel()` returns FALSE when disabled |

**Steps:**
1. Set `show_archived_label` = FALSE
2. Assert: `shouldShowArchivedLabel()` === FALSE

---

##### Test 40: `testArchivedLabelDefaultText()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `getArchivedLabel()` returns 'Archived' by default |

**Steps:**
1. Assert: `getArchivedLabel()` === `'Archived'`

---

##### Test 41: `testArchivedLabelCustomText()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `getArchivedLabel()` returns custom text when configured |

**Steps:**
1. Set `archived_label_text` = `'Legacy Document'`
2. Assert: `getArchivedLabel()` === `'Legacy Document'`

---

##### Test 42: `testArchivedLabelEmptyFallback()`

| Aspect | Detail |
|--------|--------|
| **Covers** | `getArchivedLabel()` falls back to 'Archived' when set to empty string |

**Steps:**
1. Set `archived_label_text` = `''`
2. Assert: `getArchivedLabel()` === `'Archived'`

---

#### Group K: Default Deadline Classification (1 test)

##### Test 43: `testDefaultDeadlineClassification()`

| Aspect | Detail |
|--------|--------|
| **Covers** | Default `ada_compliance_deadline` behavior and mode overrides |

**Steps:**
1. Assert: `isAdaComplianceMode()` === TRUE (default deadline is April 24, 2026 — current date is before)
2. Call `setGeneralMode()` (deadline in the past)
3. Assert: `isAdaComplianceMode()` === FALSE
4. Call `setLegacyMode()` (deadline in the future)
5. Assert: `isAdaComplianceMode()` === TRUE

**Note:** The default `ada_compliance_deadline` is not set in `config/install/digital_asset_inventory.settings.yml` — the service falls back to `strtotime('2026-04-24 00:00:00 UTC')`.

---

## 5. Test Case Cross-Reference

### 5.1 Kernel Tests → Source Documents

Maps kernel tests to `docs/testing/test-cases.md` and `docs/testing/status-transition-matrix.md`:

| # | Class | Test | test-cases.md | matrix.md |
|---|-------|------|---------------|-----------|
| 1 | Integrity | testLegacyIntegrityViolationVoidsExemption | TC-VOID-001, TC-DUAL-008 | F11, MOD-1 |
| 2 | Integrity | testGeneralIntegrityViolationDeletes | TC-DUAL-017 | F13, MOD-3 |
| 3 | Integrity | testReconcileFlagMissing | TC-ARCH-006 | -- |
| 4 | Integrity | testReconcileNoChangeLeavesStateUnchanged | -- | -- |
| 5 | Integrity | testPriorVoidForcesGeneralArchive | TC-VOID-006 | AT-3, RA-5 |
| 6 | Integrity | testImmutabilityConstraints | TC-ARCH-014, TC-DUAL-018, TC-DUAL-021 | -- |
| 7 | Integrity | testReconcileSetsUsageFlag | -- | -- |
| 8 | Integrity | testReconcileClearsUsageFlag | -- | -- |
| 9 | Workflow | testQueueAndExecutePublic | TC-ARCH-001, TC-ARCH-002 | F1, F2 |
| 10 | Workflow | testQueueAndExecuteAdmin | TC-ARCH-003 | F1, F3 |
| 11 | Workflow | testExecuteBlockedByUsage | TC-ARCH-005, TC-AIU-003 | AIU-3 |
| 12 | Workflow | testToggleVisibility | TC-ARCH-004 | F5, F6 |
| 13 | Workflow | testUnarchive | TC-ARCH-007 | F7, F8 |
| 14 | Workflow | testRemoveFromQueue | TC-ARCH-008 | F4 |
| 15 | Workflow | testTerminalStatesBlockOperations | TC-VOID-007 | F15, F16 |
| 16 | Workflow | testLegacyArchiveBeforeDeadline | TC-DUAL-001 | AT-1 |
| 17 | Workflow | testGeneralArchiveAfterDeadline | TC-DUAL-002 | AT-2 |
| 18 | Workflow | testArchiveInUseAllowed | TC-AIU-004, TC-AIU-005 | AIU-20, AIU-21 |
| 19 | Workflow | testToggleVisibilityBlockedWhenInUse | TC-AIU-006 | EC7 |
| 20 | Workflow | testUnarchiveAlwaysAllowed | TC-AIU-008 | EC1 |
| 21 | Workflow | testUsageMatchingByFidAndPath | -- | -- |
| 22 | Workflow | testWarningFlagsSurviveVisibilityToggle | -- | -- |
| 23 | Workflow | testUnarchiveFromAdminVisibility | -- | F8 |
| 24 | Workflow | testQueuedStatusOperationRestrictions | -- | -- |
| 25 | ConfigFlags | testLinkRoutingDisabledByDefault | -- | -- |
| 26 | ConfigFlags | testLinkRoutingEnabledWithArchiveEnabled | -- | -- |
| 27 | ConfigFlags | testLinkRoutingFallbackToArchiveInUse | -- | -- |
| 28 | ConfigFlags | testArchiveInUseAllowedToggle | -- | -- |
| 29 | ConfigFlags | testShowArchivedLabelEnabledByDefault | -- | -- |
| 30 | ConfigFlags | testShowArchivedLabelDisabled | -- | -- |
| 31 | ConfigFlags | testArchivedLabelDefaultText | -- | -- |
| 32 | ConfigFlags | testArchivedLabelCustomText | -- | -- |
| 33 | ConfigFlags | testArchivedLabelEmptyFallback | -- | -- |
| 34 | ConfigFlags | testDefaultDeadlineClassification | -- | -- |
| 35 | Scanner | testPromoteTemporaryItems | TC-SCAN-004 (success) | -- |
| 36 | Scanner | testClearTemporaryItems | TC-SCAN-004 (failure) | -- |
| 37 | Scanner | testClearUsageRecords | -- | -- |
| 38 | Scanner | testDigitalAssetItemCrud | -- | -- |
| 39 | Scanner | testDigitalAssetUsageDeletionOrder | -- (entity deletion order) | -- |
| 40 | Scanner | testDigitalAssetArchiveEntityFields | -- | -- |
| 41 | Scanner | testGetManagedFilesCount | TC-SCAN-001 (partial) | -- |
| 42 | Scanner | testCanArchiveGating | TC-ARCH-001 (precondition) | -- |
| 43 | Scanner | testEntityCountHelpers | -- | -- |

### 5.2 Test Cases Deferred to Functional Tests

These test cases require browser interaction, Views rendering, or full Drupal bootstrap:

| TC ID Range | Reason |
|-------------|--------|
| TC-SCAN-001 to TC-SCAN-011 | Full scan workflow with batch processing |
| TC-ALR-* (link routing) | Response subscriber + rendered HTML |
| TC-MANUAL-* (manual entries) | Form submission + validation |
| TC-PUBLIC-* (public registry) | Views rendering |
| TC-PERM-* (permissions) | Route access checks |
| TC-VIEW-* (views/filters) | Views rendering |
| TC-DPS-* (page document status) | `hook_entity_view` + rendered pages |
| TC-LABEL-* (link labels) | Response subscriber |
| TC-EMBED-* (embed method) | Content entities with fields |
| TC-USAGE-* (usage page) | Views + rendered output |

### 5.3 Scanner Methods NOT Suitable for Kernel Tests

| Method | Reason | Better Approach |
|--------|--------|-----------------|
| `scanManagedFilesChunk()` | Needs real file_managed + media + file_usage + content entities with fields | Functional test |
| `scanOrphanFilesChunk()` | Needs real filesystem with physical files across directories | Functional test |
| `scanContentChunk()` | Needs real nodes with text/link fields | Functional test |
| `scanRemoteMediaChunk()` | Needs real media entities with oEmbed sources | Functional test |
| `scanMenuLinksChunk()` | Needs real `menu_link_content` entities + file_managed records | Functional test |

These are better suited for BrowserTestBase where the full site is available. The unit tests already cover the pure-logic extraction methods these scan chunks delegate to.

---

## 6. Key Implementation Notes

### 6.1 ArchiveService Dependencies

The `ArchiveService` constructor needs 8 services. In kernel tests with the module enabled, all come from the container automatically:

| Service | Purpose in Tests |
|---------|------------------|
| `entity_type.manager` | CRUD operations on all 4 entity types |
| `database` | Direct queries (usage counts, file_managed) |
| `file_system` | `saveData()` for test files, `realpath()` for checksum, `delete()` for cleanup |
| `file_url_generator` | `generateString('public://')` for FilePathResolver trait |
| `current_user` | `archived_by` field on archive entities |
| `logger.factory` | Logging (no assertions needed, just ensure no errors) |
| `config.factory` | `ada_compliance_deadline`, `allow_archive_in_use` |
| `queue` | Batch checksum queue (not exercised in kernel tests) |

### 6.2 Deterministic File Path Resolution

Tests that exercise `executeArchive()`, `verifyIntegrity()`, or `reconcileStatus()` need a real physical file **and** a deterministic resolution path.

`ArchiveService::resolveSourceUri()` resolves in this order:
1. `original_fid` → load file entity → get URI (preferred)
2. Fallback: derive stream URI from URL/path

**Always use `createDocumentAssetWithFile()`** for checksum/integrity tests. This creates:
- A physical file at `public://filename.pdf`
- A `file_managed` entity pointing to that URI
- A `DigitalAssetItem` with `fid` set to the file entity's ID

This makes resolution deterministic via the FID path and eliminates "works locally, fails in CI because base URL differs" issues. Using `public://` ensures that `generateAbsoluteString()` produces URLs with `/sites/.../files/` patterns that `urlPathToStreamUri()` can resolve (see Section 1.5).

**Anti-pattern (fragile):**
```php
// DON'T: fid is NULL, relies on path-based resolution which
// depends on the site's public files path and base URL.
$asset = $this->createDocumentAsset([
  'file_path' => '/sites/default/files/test-doc.pdf',
]);
```

**Correct pattern:**
```php
// DO: fid is set, resolution is deterministic via file entity URI.
// Works regardless of site config, base URL, or stream wrapper.
$asset = $this->createDocumentAssetWithFile('test-doc.pdf', 'content');
```

**Integrity note:** `verifyIntegrity()` calls `resolveSourceUri($archive_path, NULL)` — path-only resolution with no FID. This falls back to `urlPathToStreamUri()`, which uses the universal `sites/[^/]+/files` pattern and the dynamic base path from `FilePathResolver`. Since `createDocumentAssetWithFile()` uses `public://`, `generateAbsoluteString()` produces URLs with `/sites/.../files/` that `urlPathToStreamUri()` can resolve back to stream URIs.

### 6.3 Usage Record Matching in getUsageCountByArchive()

`getUsageCountByArchive()` finds the matching `digital_asset_item` by `original_fid` or `original_path`, then counts `digital_asset_usage` records where `asset_id` matches:

1. If `original_fid` is set: query `digital_asset_item` where `fid` = `original_fid`
2. Else if `original_path` is set: query `digital_asset_item` where `file_path` = `original_path`
3. If no matching item found: return 0
4. Count `digital_asset_usage` where `asset_id` = matched item's ID

For tests that check usage blocking:
- The `DigitalAssetItem.fid` must match the `DigitalAssetArchive.original_fid`
- OR the `DigitalAssetItem.file_path` must match the `DigitalAssetArchive.original_path`
- The `DigitalAssetUsage.asset_id` must reference the `DigitalAssetItem.id`

Test 13 (`testUsageMatchingByFidAndPath`) explicitly validates both paths.

### 6.4 Entity Creation for Archive Tests

`markForArchive()` copies fields from the `DigitalAssetItem` to the new `DigitalAssetArchive`:

- `original_fid` ← `$asset->get('fid')->value`
- `original_path` ← `$asset->getFilepath()`
- `file_name` ← `$asset->getFilename()`
- `asset_type` ← `$asset->getAssetType()`
- `mime_type` ← `$asset->getMimeType()`
- `filesize` ← `$asset->getFilesize()`
- `is_private` ← `$asset->isPrivate()`

The item must have `category` set to `'Documents'` or `'Videos'` (enforced by `canArchive()`).

### 6.5 Config Manipulation

Kernel tests use `$this->config()` to manipulate settings:

```php
$this->config('digital_asset_inventory.settings')
  ->set('key', 'value')
  ->save();
```

This writes to the test database's config table. Each test starts with the defaults from `config/install/digital_asset_inventory.settings.yml`.

If additional modules are enabled (e.g., `media`, `field`), their config may also need installing:

```php
$this->installConfig(['system', 'user', 'file', 'digital_asset_inventory']);
```

Only install configs you actually need — unnecessary config installs can trigger missing dependency errors.

### 6.6 SQLite FK Constraints

SQLite does not enforce foreign key constraints by default. This means:
- Deleting a `digital_asset_item` while `digital_asset_usage` rows reference it will **not** fail with a DB error
- The atomic swap tests (Tests 20-21) validate correct deletion order by asserting **no orphaned usage rows** exist after operations, which is a stronger check than relying on DB-level FK enforcement
- Test 24 validates the code's deletion order but acknowledges the DB won't catch violations

### 6.7 SQLite Boolean Condition Quirk

SQLite PDO treats PHP boolean `FALSE` differently from integer `0` in entity query conditions. A query like `$query->condition('is_temp', FALSE)` will silently return **no results** even when rows exist with `is_temp=0`.

**Always use integer values for boolean fields:**

```php
// ✅ CORRECT — matches is_temp=0 in SQLite
$query->condition('is_temp', 0);

// ❌ WRONG — returns no results in SQLite
$query->condition('is_temp', FALSE);
```

This applies to all boolean/tinyint fields queried via entity queries in kernel tests. MySQL/MariaDB handle both forms, so this bug only surfaces in SQLite-backed kernel tests.

### 6.8 Permissions in Kernel Tests

Kernel tests don't involve route access, so permissions are generally not needed. The `current_user` is set for `archived_by` tracking only. If a future test needs permission checks, create roles via:

```php
$role = Role::create(['id' => 'archiver', 'label' => 'Archiver']);
$role->grantPermission('archive digital assets');
$role->save();
$user->addRole('archiver');
$user->save();
```

---

## 7. Verification

### 7.1 Run Kernel Tests Only

**Important:** Use the module's `phpunit.xml.dist` for test suite definition, but the core bootstrap for Drupal's kernel test infrastructure. All paths must be **absolute** (child processes run from different working directories).

```bash
cd /path/to/drupal-site

# Recommended: module config + core bootstrap (absolute paths required):
SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
  php vendor/bin/phpunit \
  -c /abs/path/web/modules/custom/digital_asset_inventory/phpunit.xml.dist \
  --bootstrap /abs/path/web/core/tests/bootstrap.php \
  --testsuite kernel

# Single file (using core bootstrap directly):
SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
  php vendor/bin/phpunit \
  -c /abs/path/web/modules/custom/digital_asset_inventory/phpunit.xml.dist \
  --bootstrap /abs/path/web/core/tests/bootstrap.php \
  /abs/path/web/modules/custom/digital_asset_inventory/tests/src/Kernel/ArchiveWorkflowKernelTest.php

# With debug dumps enabled:
DAI_TEST_DEBUG=1 SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
  php vendor/bin/phpunit \
  -c /abs/path/web/modules/custom/digital_asset_inventory/phpunit.xml.dist \
  --bootstrap /abs/path/web/core/tests/bootstrap.php \
  --testsuite kernel

# Human-readable output (shows test names):
# Add --testdox flag to any of the above commands
```

**Common pitfall:** Using relative paths like `-c web/core/phpunit.xml.dist` causes "Could not read" errors because child processes don't share the parent's working directory. Always use absolute paths.

### 7.2 Run All Module Tests

```bash
# Unit tests (no DB needed — fully multisite-safe):
./vendor/bin/phpunit web/modules/custom/digital_asset_inventory/tests/src/Unit

# Kernel tests (DB needed — see Section 7.1 for full command):
SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
  php vendor/bin/phpunit \
  -c /abs/path/web/modules/custom/digital_asset_inventory/phpunit.xml.dist \
  --bootstrap /abs/path/web/core/tests/bootstrap.php \
  --testsuite kernel

# Both unit + kernel suites:
SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
  php vendor/bin/phpunit \
  -c /abs/path/web/modules/custom/digital_asset_inventory/phpunit.xml.dist \
  --bootstrap /abs/path/web/core/tests/bootstrap.php
```

### 7.3 Multisite Environments

Both unit and kernel tests work in multisite and Site Factory environments without modification:

- **Unit tests** mock all Drupal services and never touch the filesystem, database, or site configuration.
- **Kernel tests** use `/tmp` for SQLite (no site directory dependency) and `public://` for test files (required for `verifyIntegrity()` URL resolution; in kernel tests, `public://` is managed by the test framework).

If the environment already has `SIMPLETEST_DB` configured, the inline env var is not needed. For multisite setups, ensure the configured path points to `/tmp` (not `sites/<site>/files/`).

**Troubleshooting:**

| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| `SQLSTATE: unable to open database file` | SQLite path is relative or dir doesn't exist | Use absolute path with double slash: `sqlite://localhost//tmp/...` |
| Permission denied on `public://` | Multisite `sites/<name>/files/` not writable in runtime context | In kernel tests, `public://` is managed by the test framework — this error should not occur |
| `Class not found` for module entities | Module dependencies not met | See Section 1.4 for fallback strategies |

### 7.4 Expected Output

```
PHPUnit 9.6.x

OK (59 tests, 1182 assertions)
```

---

## 8. Summary

| Test Class | Groups | Tests | Focus |
|------------|--------|-------|-------|
| `ArchiveIntegrityKernelTest` | D (integrity), D-Bonus (prior void, immutability), D-Extra (reconcile flags) | 8 | Checksum verification, auto-void, reconcile flags |
| `ArchiveWorkflowKernelTest` | A (state machine), B (archive type), C (usage policy), D (flag persistence) | 16 | Archive lifecycle with real entities |
| `ConfigFlagsKernelTest` | H (link routing), I (archive-in-use), J (archived label), K (deadline) | 10 | Config flag → service behavior mapping |
| `ScannerAtomicSwapKernelTest` | E (atomic swap), F (entity CRUD), G (queries) | 11 | Scanner entity operations, active use CSV |
| **Kernel Total** | | **45** | |

Combined with unit tests: **299 unit + 45 kernel = 344 total**.

### 8.1 Test Execution Order

PHPUnit runs tests **alphabetically by class name**, then by **method declaration order** within each class. This produces a natural reading progression in both test output and debug dumps:

1. **ArchiveIntegrityKernelTest** (8 tests) — Integrity verification, auto-void, immutability, reconcile flags
2. **ArchiveWorkflowKernelTest** (16 tests) — Full archive lifecycle: queue → execute → toggle → unarchive → terminal states → usage policy → flag persistence
3. **ConfigFlagsKernelTest** (10 tests) — Config flags controlling service behavior (no entity state/dumps)
4. **ScannerAtomicSwapKernelTest** (9 tests) — Entity CRUD, atomic swap, scanner helpers

The debug dump file (`tests/artifacts/dai-debug-dump.txt`) follows this same order. Reading top-to-bottom:

- **Integrity scenarios first** — exemption void, General delete, missing, no-change, prior void, immutability, usage flag set/clear
- **Workflow lifecycle next** — execute → admin → blocked → toggle → unarchive → remove → terminal → Legacy/General → in-use → usage matching → flag persistence → queued restrictions
- **Scanner entity operations last** — promote, clearTemp, clearUsage, CRUD, deletion order, entity fields, canArchive, counts

This mirrors a natural reading progression: "What can go wrong with integrity?" → "How does the normal workflow proceed?" → "How do config flags behave?" → "How do the low-level entities work?" The within-class method order is designed to tell a story — simple cases before edge cases.

No explicit ordering configuration is needed. If you ever want to change execution order, PHPUnit supports `--order-by=defects` (failed tests first) and `@depends` annotations, but neither is needed here.
