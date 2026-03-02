# Scan Performance Optimizations

## Context

During live Pantheon testing of the scan resilience changes (FR-1 through FR-8), the scan completed Phase 1 but timed out in Phase 2 on the test site (5,473 files). Debug logging revealed two systemic performance problems that affect multiple phases:

1. **Per-item DB queries in list-building:** `buildOrphanFileList()` runs one `SELECT COUNT(*)` per file on disk (~5,473 queries) to determine which files are orphans — even though only 33 are actually orphans. This runs every callback.
2. **Per-item entity loading:** `processWithTimeBudget()` loads entities one at a time via `->load($id)`. On Pantheon with Redis + remote DB, each load takes ~0.22 seconds. Phase 1 processed only 8-9 items per 2-second callback.

These same patterns likely exist in other phases and helper methods throughout the scanner.

**Test data from test site (5,473 managed files, Pantheon):**

| Phase | Budget | Items/callback | Per-item time | Problem |
|-------|--------|---------------|---------------|---------|
| 1 | 2s | 8-9 | ~0.22s | Individual entity loads |
| 2 | 10s | 1-2 | ~5-10s | 5,473 DB queries in buildOrphanFileList() per callback |

---

## Fix 1: Bulk Query in `buildOrphanFileList()` (Phase 2)

**File:** `src/Service/DigitalAssetScanner.php`

**Problem:** Lines ~4752-4756 run one DB query per file on disk:

```php
// CURRENT — runs for EVERY file found on disk (5,473 queries per callback)
$exists = $this->database->select('file_managed', 'f')
  ->condition('uri', $uri)
  ->countQuery()
  ->execute()
  ->fetchField();
```

On the test site, `scanDirectoryRecursive()` finds ~5,473 files. For each one, it queries `file_managed` to check if it's managed. That's ~5,473 DB round-trips just to identify 33 orphans. And this runs every single callback because the orphan list is rebuilt each time.

**Fix:** Load all managed URIs in one query, use in-memory lookup:

```php
protected function buildOrphanFileList(): array {
  $known_extensions = $this->getKnownExtensions();
  $orphan_files = [];
  $streams = ['public://', 'private://'];

  // Single query: load ALL managed file URIs into a hash set.
  // On test site: 1 query returning ~5,473 URIs vs 5,473 individual COUNT queries.
  $managed_uris = $this->database->select('file_managed', 'f')
    ->fields('f', ['uri'])
    ->execute()
    ->fetchCol();
  $managed_set = array_flip($managed_uris);  // array_flip for O(1) isset() lookup.

  foreach ($streams as $stream) {
    $base_path = $this->fileSystem->realpath($stream);
    if (!$base_path || !is_dir($base_path)) {
      continue;
    }

    $is_private_scan = ($stream === 'private://');
    $all_files = $this->scanDirectoryRecursive($base_path, $known_extensions, $is_private_scan);

    foreach ($all_files as $file_path) {
      $relative_path = str_replace($base_path, '', $file_path);
      $relative_path = ltrim($relative_path, '/');
      $uri = $stream . $relative_path;

      // In-memory check instead of DB query.
      if (!isset($managed_set[$uri])) {
        $orphan_files[] = [
          'path' => $file_path,
          'uri' => $uri,
          'relative' => $relative_path,
        ];
      }
    }
  }

  // Deterministic sort for index-based cursor consistency across callbacks.
  sort($orphan_files);
  return $orphan_files;
}
```

**Impact:** Reduces ~5,473 DB queries to 1. `buildOrphanFileList()` goes from ~30-60 seconds to <1 second.

**Also update the old method:** The original `scanOrphanFilesChunk()` (non-New version, line ~4975) has the same per-file query pattern. Apply the same bulk query fix there for consistency, even if only the new method is called during scans.

---

## Fix 2: Cache Orphan List in Sandbox (Phase 2)

**File:** `src/Service/DigitalAssetScanner.php`

**Problem:** Even after Fix 1, `buildOrphanFileList()` still does a filesystem scan + one DB query per callback. With Fix 1 the cost drops dramatically, but there's no reason to repeat it — the orphan list doesn't change during a scan.

**Fix:** Build once on first callback, store in sandbox:

```php
public function scanOrphanFilesChunkNew(array &$context, bool $is_temp): void {
  $budget = $this->getBatchTimeBudget();
  $startTime = microtime(true);
  $itemsThisCallback = 0;

  // Build orphan list ONCE on first callback, store in sandbox.
  // With Fix 1 (bulk query), the list-building is fast (<1s).
  // The orphan list is small (33 items on the test site) — negligible serialization.
  if (!isset($context['sandbox']['orphan_files'])) {
    $context['sandbox']['orphan_files'] = $this->buildOrphanFileList();
    $context['sandbox']['orphan_index'] = 0;
    $context['sandbox']['orphan_total'] = count($context['sandbox']['orphan_files']);
  }

  $orphanFiles = $context['sandbox']['orphan_files'];
  $index = $context['sandbox']['orphan_index'];
  $total = $context['sandbox']['orphan_total'];

  // Exhaustion guard.
  if ($index >= $total || empty($orphanFiles)) {
    $context['finished'] = 1;
    $context['results']['last_chunk_items'] = 0;
    return;
  }

  // Process items from current index until budget exhausted.
  while ($index < $total) {
    if ((microtime(true) - $startTime) >= $budget) {
      break;
    }

    $this->processOrphanFile($orphanFiles[$index], $is_temp);
    $this->maybeUpdateHeartbeat();

    $index++;
    $itemsThisCallback++;
  }

  $context['sandbox']['orphan_index'] = $index;

  // Progress.
  if ($total > 0) {
    $context['finished'] = $index / $total;
  }
  if ($index >= $total) {
    $context['finished'] = 1;
  }

  // FR-6: Cache resets.
  $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'file']);
  if ($itemsThisCallback >= 50) {
    drupal_static_reset();
  }

  $context['results']['last_chunk_items'] = $itemsThisCallback;
}
```

**Serialization note:** The original plan avoided caching in sandbox due to serialization concerns (5,000 paths = ~500KB-1MB). But the orphan list is typically tiny (33 files on the test site). Even on a worst-case site with 500 orphans, that's ~50KB — negligible. The alternative (5,473 DB queries per callback) is catastrophically worse.

**Impact:** Phase 2 builds the orphan list once instead of every callback. Combined with Fix 1, Phase 2 for 33 orphans completes in a single callback.

---

## Fix 3: Batch Entity Loading with `loadMultiple()` (Phases 1, 4, 5)

**File:** `src/Service/DigitalAssetScanner.php`

**Problem:** `processWithTimeBudget()` fetches a batch of IDs but the processing function loads entities one at a time:

```php
// CURRENT — each processFn call internally does ->load($id)
// That's one DB query + cache check + hook invocation per entity.
// On Pantheon: ~0.22 seconds per load.
foreach ($ids as $id) {
  ($processFn)($id);  // Internally: $this->entityTypeManager->getStorage('file')->load($id)
}
```

**Fix:** Pre-load entities in bulk before the processing loop. Update `processWithTimeBudget()`:

```php
/**
 * Processes entities using an ID-based cursor with time budget.
 *
 * @param callable $loadFn
 *   Function to bulk-load entities by IDs.
 *   Signature: function(array $ids): array
 *   Returns array of loaded entities keyed by ID.
 * @param callable $processFn
 *   Function to process a single loaded entity (not an ID).
 *   Signature: function(mixed $entity): void
 */
protected function processWithTimeBudget(
  array &$context,
  string $cursorKey,
  string $totalKey,
  callable $countFn,
  callable $queryFn,
  callable $loadFn,
  callable $processFn,
): int {
  $budget = $this->getBatchTimeBudget();
  $startTime = microtime(true);
  $itemsThisCallback = 0;

  // Initialize on first call.
  if (!isset($context['sandbox'][$cursorKey])) {
    $context['sandbox'][$cursorKey] = 0;
    $context['sandbox'][$totalKey] = ($countFn)();
    $context['sandbox']['processed'] = 0;
  }

  $lastId = $context['sandbox'][$cursorKey];

  // Fetch a batch of IDs.
  $ids = ($queryFn)($lastId, 100);

  // Exhaustion guard.
  if (empty($ids)) {
    $context['finished'] = 1;
    return $itemsThisCallback;
  }

  // BULK LOAD all entities in one query.
  // loadMultiple() uses SELECT ... WHERE id IN (...) — one query for all.
  // On Pantheon: ~0.5s for 100 entities vs ~22s for 100 individual loads.
  $entities = ($loadFn)($ids);

  foreach ($ids as $id) {
    if ((microtime(true) - $startTime) >= $budget) {
      break;
    }

    // Skip if entity failed to load (deleted between query and load).
    if (!isset($entities[$id])) {
      $context['sandbox'][$cursorKey] = $id;
      $context['sandbox']['processed']++;
      continue;
    }

    ($processFn)($entities[$id]);
    $this->maybeUpdateHeartbeat();

    $context['sandbox'][$cursorKey] = $id;
    $context['sandbox']['processed']++;
    $itemsThisCallback++;
  }

  // Progress calculation.
  $total = $context['sandbox'][$totalKey];
  if ($total > 0) {
    $context['finished'] = $context['sandbox']['processed'] / $total;
  }
  if ($context['finished'] >= 1) {
    $context['finished'] = 1;
  }

  return $itemsThisCallback;
}
```

**Update Phase 1 caller:**

```php
public function scanManagedFilesChunk(array &$context, bool $is_temp): void {
  if (!isset($context['sandbox']['orphan_paragraph_count'])) {
    $context['sandbox']['orphan_paragraph_count'] = 0;
  }

  $itemsThisCallback = $this->processWithTimeBudget(
    $context,
    'last_fid',
    'total_files',
    fn() => $this->countManagedFiles(),
    fn(int $lastFid, int $limit) => $this->getManagedFileIdsAfter($lastFid, $limit),
    // Bulk load function.
    fn(array $ids) => $this->entityTypeManager->getStorage('file')->loadMultiple($ids),
    // Process function now receives a loaded entity, not an ID.
    fn($file) => $this->processManagedFileEntity($file, $is_temp, $context),
  );

  // ... rest unchanged (orphan count persist, cache resets)
}
```

**Update Phase 4 caller:**

```php
public function scanRemoteMediaChunk(array &$context, bool $is_temp): void {
  $itemsThisCallback = $this->processWithTimeBudget(
    $context,
    'last_mid',
    'total_media',
    fn() => $this->countRemoteMedia(),
    fn(int $lastMid, int $limit) => $this->getRemoteMediaIdsAfter($lastMid, $limit),
    fn(array $ids) => $this->entityTypeManager->getStorage('media')->loadMultiple($ids),
    fn($media) => $this->processRemoteMediaEntity($media, $is_temp),
  );

  // ... cache resets
}
```

**Update Phase 5 caller:**

```php
public function scanMenuLinksChunk(array &$context, bool $is_temp): void {
  $itemsThisCallback = $this->processWithTimeBudget(
    $context,
    'last_id',
    'total_menu_links',
    fn() => $this->countMenuLinks(),
    fn(int $lastId, int $limit) => $this->getMenuLinkIdsAfter($lastId, $limit),
    fn(array $ids) => $this->entityTypeManager->getStorage('menu_link_content')->loadMultiple($ids),
    fn($link) => $this->processMenuLinkEntity($link, $is_temp),
  );

  // ... cache resets
}
```

**IMPORTANT — Process method refactor:** Each phase's process method needs a renamed/refactored version that accepts a loaded entity instead of an ID. For example, `processManagedFile(int $fid, ...)` becomes `processManagedFileEntity(FileInterface $file, ...)` that skips the `->load()` call at the top. The rest of the processing logic stays identical.

Pattern for each phase:

```php
// CURRENT (loads entity internally):
protected function processManagedFile(int $fid, bool $is_temp, array &$context): void {
  $file = $this->entityTypeManager->getStorage('file')->load($fid);
  if (!$file) return;
  // ... processing logic using $file ...
}

// NEW (receives pre-loaded entity):
protected function processManagedFileEntity(FileInterface $file, bool $is_temp, array &$context): void {
  // ... same processing logic using $file, no ->load() call ...
}
```

**Expected impact:**

| Metric | Before | After |
|--------|--------|-------|
| DB queries per 100 files | 100 individual SELECTs | 1 SELECT with IN clause |
| Time to load 100 files | ~22 seconds | ~0.5-1 second |
| Items per 10s callback | ~45 | ~200-500 |
| Phase 1 duration (5,473 files) | ~25-35 min | ~3-5 min |

---

## Fix 4: Bulk Lookups in `processOrphanFile()` (Phase 2)

**File:** `src/Service/DigitalAssetScanner.php`

**Problem:** `processOrphanFile()` at line ~4807 runs an entity query per orphan to check for existing temp items:

```php
// CURRENT — one entity query per orphan file
$existing_query = $storage->getQuery();
$existing_query->condition('url_hash', $uri_hash);
$existing_query->condition('source_type', 'filesystem_only');
$existing_query->condition('is_temp', TRUE);
$existing_query->accessCheck(FALSE);
$existing_ids = $existing_query->execute();
```

With 33 orphans this is minor. But the pattern should be fixed for consistency and for sites with hundreds of orphans.

**Fix:** Pre-load existing temp orphan items in bulk before the processing loop. Add to `scanOrphanFilesChunkNew()`:

```php
// After building/loading orphan list, before the processing loop:
// Bulk-fetch all existing temp orphan items to avoid per-item entity queries.
$orphan_hashes = array_map(fn($f) => md5($f['uri']), $orphanFiles);
if (!empty($orphan_hashes)) {
  $existing_temp_items = $storage->getQuery()
    ->condition('url_hash', $orphan_hashes, 'IN')
    ->condition('source_type', 'filesystem_only')
    ->condition('is_temp', TRUE)
    ->accessCheck(FALSE)
    ->execute();
  // Store in sandbox or pass to processOrphanFile.
  $context['sandbox']['existing_temp_ids'] = $existing_temp_items;
}
```

Then update `processOrphanFile()` to accept pre-queried results instead of querying per item.

**Impact:** Reduces per-orphan entity queries from N to 1 bulk query.

---

## Fix 5: Phase 3 Content Processing — Entity Load Hotspots (Confirmed Analysis)

**File:** `src/Service/DigitalAssetScanner.php`

**Confirmed architecture:** Phase 3 (`scanContentChunkNew`) does **not** use `processWithTimeBudget()`. It reads raw DB rows from field tables via `getFieldTableRows()` (fast — direct SQL), then processes each row in `processContentRow()`. The field table reads themselves are efficient. The bottlenecks are entity loads buried inside the per-row processing methods.

### Hotspot A: Paragraph Parent Tracing (`getParentFromParagraph()`, line ~4278)

Every row from a paragraph field table triggers `getParentFromParagraph()`, which:

1. Calls `->load($paragraph_id)` to load the paragraph entity
2. Calls `->getParentEntity()` which loads the parent entity
3. For nested paragraphs (3+ levels), chains multiple loads up the hierarchy
4. Calls `isParagraphInEntityField()` to verify attachment — another entity inspection

This is inherently sequential (each parent is discovered dynamically), so `loadMultiple()` doesn't directly apply. However, the same paragraph may be traced multiple times across different fields on the same entity.

**Fix:** Add a paragraph parent cache within the callback scope:

```php
// Cache paragraph parent lookups to avoid re-tracing the same paragraph.
// Key: paragraph_id, Value: parent info array or NULL.
// Cleared per callback (entity caches are reset anyway).
if (!isset($this->paragraphParentCache[$paragraph_id])) {
  $this->paragraphParentCache[$paragraph_id] = $this->getParentFromParagraph($paragraph_id);
}
$parent_info = $this->paragraphParentCache[$paragraph_id];
```

**Impact:** Eliminates duplicate paragraph traces within a callback. On sites with deeply nested paragraphs and multiple text fields per paragraph, this can reduce entity loads by 2-5x for paragraph rows.

### Hotspot B: Per-URL File Lookups (`processLocalFileLink()`, line ~2914)

Each local file URL found in text (`<a href>`, `<img src>`, `<object data>`, `<embed src>`) triggers:

```php
// CURRENT — one loadByProperties per URL found in text
$files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
```

A single text field with 5 file links = 5 individual `loadByProperties()` calls.

**Fix:** Collect all local file URIs from a batch of rows, then bulk-load:

```php
// Before processing a batch of rows, extract all local file URIs.
$all_uris = [];
foreach ($rows as $row) {
  $field_value = $row->{$table_info['column']};
  $all_uris = array_merge($all_uris, $this->extractLocalFileUrisFromText($field_value));
}

// Bulk query file_managed for all URIs at once.
if (!empty($all_uris)) {
  $file_query = $this->database->select('file_managed', 'f')
    ->fields('f', ['fid', 'uri'])
    ->condition('uri', array_unique($all_uris), 'IN')
    ->execute();
  $file_map = [];  // uri => fid
  foreach ($file_query as $record) {
    $file_map[$record->uri] = $record->fid;
  }
  // Pass $file_map to processLocalFileLink() to skip per-URI loadByProperties.
}
```

**Impact:** Reduces file lookups from N (one per URL in text) to 1 bulk query per batch of rows. Most impactful on content-heavy sites with many inline file links.

### Hotspot C: Per-URL Entity Queries for Dedup

Both `processExternalUrl()` and `processLocalFileLink()` run two entity queries per URL:

1. Check for existing temp item: `$asset_storage->getQuery()->condition('url_hash', $hash)->condition('is_temp', TRUE)`
2. Check for existing usage record: `$usage_storage->getQuery()->condition('asset_id', $id)->condition('entity_id', ...)`

**Fix:** Same pattern as Fix 4 — collect all URL hashes from a batch, bulk-query existing temp items and usage records before the processing loop. Pass the lookup maps to the processing methods.

**Impact:** Moderate. Each entity query is lightweight (index lookup), but on Pantheon with remote DB, even fast queries add ~10-20ms of network round-trip. With 50 rows per batch and 2-3 URLs per row, that's 200-300 entity queries reduced to 2-3 bulk queries.

### Phase 3 Summary

| Hotspot | Current | Fix | Effort |
| ------- | ------- | --- | ------ |
| A: Paragraph parent tracing | Individual `->load()` per paragraph, repeated | In-memory cache per callback | Low |
| B: File URI lookups | `loadByProperties()` per `<a>`, `<img>`, etc. | Bulk DB query per batch | Medium |
| C: Dedup entity queries | 2 entity queries per URL found | Bulk pre-query per batch | Medium |

**Note:** Unlike Phases 1/4/5 where `processWithTimeBudget()` can be upgraded with a `loadFn` parameter, Phase 3 requires targeted fixes in `processContentRow()` and its callees because the entity loads are scattered across multiple helper methods, not centralized in a single load-by-ID pattern.

---

## Fix 6: Audit All `->load()` Calls in Scanner

**File:** `src/Service/DigitalAssetScanner.php`

**Action:** Comprehensive audit of every individual entity load in the scanner.

```bash
# Find all individual load calls
grep -n '->load(' src/Service/DigitalAssetScanner.php
grep -n '->loadByProperties(' src/Service/DigitalAssetScanner.php
```

Every `->load($single_id)` inside a loop is a performance problem on Pantheon. Each one should be evaluated for conversion to `->loadMultiple()` with pre-fetching before the loop.

Common patterns to fix:

```php
// PATTERN 1: Load inside processing loop — convert to pre-load
// BAD:
foreach ($ids as $id) {
  $entity = $storage->load($id);
  // process...
}
// GOOD:
$entities = $storage->loadMultiple($ids);
foreach ($entities as $entity) {
  // process...
}

// PATTERN 2: Load related entity inside processing — batch where possible
// BAD:
function processItem($item) {
  $media = $this->entityTypeManager->getStorage('media')->load($item->media_id);
}
// GOOD: Pre-load all media IDs before the loop, pass loaded entities

// PATTERN 3: loadByProperties in loop — convert to entity query + loadMultiple
// BAD:
foreach ($items as $item) {
  $existing = $storage->loadByProperties(['url_hash' => $hash, 'is_temp' => TRUE]);
}
// GOOD:
$all_existing = $storage->getQuery()
  ->condition('url_hash', $all_hashes, 'IN')
  ->condition('is_temp', TRUE)
  ->execute();
$loaded = $storage->loadMultiple($all_existing);
```

---

## Fix 7: Default Time Budget Should Be 10, Not 4

**Files:**
- `config/install/digital_asset_inventory.settings.yml`
- `src/Service/DigitalAssetScanner.php` (constant)
- `digital_asset_inventory.install` (update hook)

**Problem:** The 4-second default was based on the assumption that per-item processing would be fast. In reality, on Pantheon with remote DB + Redis, even with `loadMultiple()`, each item has meaningful overhead. A 4-second budget produces too many batch round-trips, and each round-trip has ~2-3 seconds of Batch API overhead (serialize/deserialize context, HTTP redirect, AJAX callback).

From testing: at 2-second budget, the ratio was roughly 2s work + 3s overhead = 5s per round-trip = 40% efficiency. At 10s budget: 10s work + 3s overhead = 13s per round-trip = 77% efficiency.

**Fix:** Change default to 10 seconds.

```yaml
# config/install/digital_asset_inventory.settings.yml
scan_batch_time_budget_seconds: 10
```

```php
// DigitalAssetScanner.php
const BATCH_TIME_BUDGET_SECONDS = 10;
```

Update hook for existing sites:

```php
// In the existing update hook, change the default:
if ($config->get('scan_batch_time_budget_seconds') === NULL || $config->get('scan_batch_time_budget_seconds') == 4) {
  $config->set('scan_batch_time_budget_seconds', 10);
}
```

Update Settings form description:

```php
'#description' => $this->t('Maximum seconds of scan work per batch request. Lower values (4-8) are safer for hosting with strict timeouts. Higher values (10-20) reduce overhead and total scan time. Default: 10.'),
```

**Pantheon safety margin:** Pantheon timeout is ~59 seconds. At 10-second budget, the total batch request is ~13 seconds (10s work + 3s overhead). That's 22% of the timeout — plenty of headroom. Even with an occasional slow item pushing a callback to 15 seconds, the total stays well under 59.

---

## Fix 8: `breakStaleLock()` Bug — Direct Semaphore Table Query (Confirmed)

**File:** `src/Service/DigitalAssetScanner.php`

**Problem:** `breakStaleLock()` at line ~6988 directly queries the `semaphore` table instead of using the lock service API:

```php
// CURRENT — BUG: bypasses lock backend abstraction
$this->database->delete('semaphore')
  ->condition('name', self::SCAN_LOCK_NAME)
  ->execute();
```

**This is a confirmed bug.** On Pantheon and any site using Redis for persistent locks, the lock lives in Redis, not the `semaphore` table. This direct SQL delete is a no-op — the lock remains held in Redis, and `breakStaleLock()` silently fails to break the lock.

**Fix:** Use the lock service's `release()` method:

```php
// FIXED — works on any lock backend (MySQL semaphore, Redis, Memcache, etc.)
$this->persistentLock->release(self::SCAN_LOCK_NAME);
```

**Scope audit:** `grep -n 'semaphore' src/Service/DigitalAssetScanner.php` confirms this is the **only** direct semaphore table reference. All other lock methods (`acquireScanLock()`, `releaseScanLock()`, `isScanLocked()`, `isScanLockStale()`) correctly use the `$this->persistentLock` service API. Only `breakStaleLock()` bypasses it.

**Impact:** Without this fix, stale lock recovery does not work on Pantheon. A stuck scan requires manual Drush intervention to clear. With this fix, the "Resume Scan" and "Start Fresh Scan" buttons in the UI will correctly break stale locks on all hosting environments.

**Also add to recovery/troubleshooting documentation:**

```
## Clearing a Stuck Scan Lock

### Backend-agnostic (works on any lock backend — recommended)
drush ev "\Drupal::service('lock.persistent')->release('digital_asset_inventory_scan');"

### Verify lock is cleared
drush ev "echo \Drupal::service('lock.persistent')->lockMayBeAvailable('digital_asset_inventory_scan') ? 'FREE' : 'LOCKED';"
```

---

## Implementation Priority

| Priority | Fix | Impact | Effort |
| -------- | --- | ------ | ------ |
| **P0** | Fix 1: Bulk query in buildOrphanFileList | Phase 2 goes from timeout to <1s | Low |
| **P0** | Fix 3: loadMultiple in processWithTimeBudget | Phase 1 goes from ~30min to ~5min | Medium |
| **P0** | Fix 7: Default budget 10s | Immediate efficiency gain | Low |
| **P0** | Fix 8: breakStaleLock() bug (confirmed) | Stale lock recovery broken on Pantheon/Redis | Low |
| **P1** | Fix 2: Cache orphan list in sandbox | Avoids redundant filesystem scan | Low |
| **P1** | Fix 5A: Paragraph parent cache | Eliminates duplicate traces per callback | Low |
| **P1** | Fix 5B: Bulk file URI lookups | Reduces per-URL loadByProperties in Phase 3 | Medium |
| **P1** | Fix 5C: Bulk dedup queries | Reduces per-URL entity queries in Phase 3 | Medium |
| **P1** | Fix 6: Audit all ->load() calls | Catches remaining per-item loads | Medium |
| **P2** | Fix 4: Bulk lookups in processOrphanFile | Minor — only 33 orphans typically | Low |

**Deploy Fix 1 + Fix 7 + Fix 8 immediately** — Fix 1 and Fix 7 unblock the test site scan, Fix 8 is a one-line bug fix that enables stale lock recovery on Pantheon. Fix 3 is the biggest performance win but requires more refactoring.

---

## Estimated Completion Times After All Fixes (5,473 files, Pantheon)

| Phase | Before fixes | After Fix 1+7 | After all fixes |
|-------|-------------|---------------|-----------------|
| Phase 1 (Managed Files) | ~30+ min (timeout) | ~15-20 min | ~3-5 min |
| Phase 2 (Orphan Files) | Timeout | <1 min | <30 sec |
| Phase 3 (Content) | Unknown (untested) | ~10-15 min (est.) | ~3-5 min (est.) |
| Phase 4 (Remote Media) | Unknown | ~5-10 min (est.) | ~2-3 min (est.) |
| Phase 5 (Menu Links) | Unknown | ~2-5 min (est.) | ~1-2 min (est.) |
| **Total** | **Never completes** | **~35-50 min** | **~10-15 min** |

---

## Verification

After deploying fixes:

```bash
# Verify budget is 10
terminus drush site.dai -- cget digital_asset_inventory.settings scan_batch_time_budget_seconds

# Clear old scan state and start fresh
terminus drush site.dai -- ev "\Drupal::service('lock.persistent')->release('digital_asset_inventory_scan');"
terminus drush site.dai -- ev "\Drupal::service('digital_asset_inventory.scanner')->clearCheckpoint();"
terminus drush site.dai -- sqlq "DELETE FROM digital_asset_item WHERE is_temp = 1"
terminus drush site.dai -- cr

# Run scan, then check logs
terminus drush site.dai -- ws --count=20 --type=digital_asset_inventory

# Expected: Items per callback should be 50-200+ (vs 8-9 before)
# Expected: Phase 2 should complete in 1-2 callbacks (vs timeout before)
```
