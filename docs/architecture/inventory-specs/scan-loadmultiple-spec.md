# loadMultiple Performance Fix

## Context

The Digital Asset Inventory scanner works on Pantheon after the resilience changes and initial fixes (Fix 1, 7, 8). However, full scans are slow due to per-item entity loading. On a test site with ~5,500 managed files, a full scan takes 54 minutes. Phase 1 processes ~43 items per 10-second callback because each `->load($id)` call takes ~0.22 seconds on Pantheon's remote DB + Redis stack. Phase 2 processes ~10 orphans per callback due to individual entity queries per orphan file.

`loadMultiple()` loads 100 entities in one SQL query (~0.5s) vs 100 individual loads (~22s). This is the single biggest performance improvement available.

**Already deployed (not repeated here):**
- Fix 1: Bulk query in `buildOrphanFileList()`
- Fix 7: Default time budget 10 seconds
- Fix 8: `breakStaleLock()` uses lock service API

**Test site reference data (~5,500 files):**

| Entity | Count | Scan Phase | Current Duration | After All Fixes |
|--------|-------|------------|------------------|--------------------|
| Managed files | 5,527 | Phase 1 | ~28 min | ~5-8 min |
| Orphan files | 1,636 | Phase 2 | ~27 min | ~2-3 min |
| Content (field rows) | 38,315 | Phase 3 | ~30 sec | ~30 sec (already fast) |
| Remote media | 17 | Phase 4 | ~14 sec | ~14 sec |
| Menu links | 196 | Phase 5 | ~1 sec | ~1 sec |
| **Total** | **7,485 assets** | | **54 min** | **~10-15 min** |

---

## Files to Modify

| File | Changes |
|------|---------|
| `src/Service/DigitalAssetScanner.php` | Update `processWithTimeBudget()`, refactor process methods to accept entities, cache orphan list, bulk orphan lookups, add paragraph parent cache, cron suspension |
| `src/Form/ScanAssetsForm.php` | Add `suspendCron()` / `restoreCron()` calls in submit and batch finished handlers |
| `digital_asset_inventory.services.yml` | Add `@module_handler` argument to scanner service |

---

## Fix 1: Update `processWithTimeBudget()` to Accept a Load Function

**File:** `src/Service/DigitalAssetScanner.php`

### 1a. Update Method Signature

Add `callable $loadFn` between `$queryFn` and `$processFn`. The existing three callers (Phases 1, 4, 5) must all be updated in the same commit — the signature change breaks them otherwise.

**Current signature:**

```php
protected function processWithTimeBudget(
  array &$context,
  string $cursorKey,
  string $totalKey,
  callable $countFn,
  callable $queryFn,
  callable $processFn,
): int {
```

**New signature:**

```php
protected function processWithTimeBudget(
  array &$context,
  string $cursorKey,
  string $totalKey,
  callable $countFn,
  callable $queryFn,
  callable $loadFn,
  callable $processFn,
): int {
```

### 1b. Update Method Body

Replace the section from ID fetching through the processing loop. The initialization block and progress calculation at the end stay the same.

Find this section:

```php
  $lastId = $context['sandbox'][$cursorKey];

  // Fetch a batch of IDs (fetch more than we'll likely process in one budget window).
  $ids = ($queryFn)($lastId, 100);

  // Exhaustion guard: no more items means phase is done.
  if (empty($ids)) {
    $context['finished'] = 1;
    return $itemsThisCallback;
  }

  foreach ($ids as $id) {
    // Time check BEFORE processing — never start an item we can't finish within budget.
    if ((microtime(true) - $startTime) >= $budget) {
      break;
    }

    ($processFn)($id);
    $this->maybeUpdateHeartbeat();

    $context['sandbox'][$cursorKey] = $id;
    $context['sandbox']['processed']++;
    $itemsThisCallback++;
  }
```

Replace with:

```php
  $lastId = $context['sandbox'][$cursorKey];

  // Fetch a batch of IDs.
  $ids = ($queryFn)($lastId, 100);

  // Exhaustion guard: no more items means phase is done.
  if (empty($ids)) {
    $context['finished'] = 1;
    return $itemsThisCallback;
  }

  // BULK LOAD all entities in one query.
  // loadMultiple() uses SELECT ... WHERE id IN (...) — one DB round-trip.
  // On Pantheon: ~0.5s for 100 entities vs ~22s for 100 individual loads.
  $entities = ($loadFn)($ids);

  foreach ($ids as $id) {
    // Time check BEFORE processing.
    if ((microtime(true) - $startTime) >= $budget) {
      break;
    }

    // Skip if entity failed to load (deleted between ID query and load).
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
```

### 1c. Update the PHPDoc

Update the docblock to document the new parameter:

```php
/**
 * Processes entities using an ID-based cursor with time budget.
 *
 * Uses bulk entity loading via loadMultiple() to minimize DB round-trips.
 * On Pantheon with remote DB, this reduces 100 individual loads (~22s)
 * to 1 bulk load (~0.5s).
 *
 * @param array &$context
 *   Batch API context array.
 * @param string $cursorKey
 *   Sandbox key for the cursor (e.g., 'last_fid', 'last_mid', 'last_id').
 * @param string $totalKey
 *   Sandbox key for the total count (e.g., 'total_files', 'total_media').
 * @param callable $countFn
 *   Function returning total item count. Called once on first invocation.
 *   Signature: function(): int
 * @param callable $queryFn
 *   Function returning array of IDs to process.
 *   Signature: function(int $lastId, int $limit): array
 * @param callable $loadFn
 *   Function to bulk-load entities by IDs.
 *   Signature: function(array $ids): array
 *   Returns array of loaded entities keyed by ID.
 * @param callable $processFn
 *   Function to process a single loaded entity (not an ID).
 *   Signature: function(mixed $entity): void
 *
 * @return int
 *   Number of items processed in this callback.
 */
```

---

## Fix 2: Refactor Process Methods to Accept Entities

**File:** `src/Service/DigitalAssetScanner.php`

Each phase's existing process method loads an entity by ID internally. Refactor the original method to accept a loaded entity, and create a thin backward-compat wrapper for the old ID-based signature. This avoids duplicate logic — a bug fix in the main method applies everywhere.

### 2a. Phase 1 — `processManagedFile()`

Find the existing method. It will start with something like:

```php
protected function processManagedFile(int $fid, bool $is_temp, array &$context): void {
  $file = $this->entityTypeManager->getStorage('file')->load($fid);
  if (!$file) {
    return;
  }
  // ... rest of processing logic using $file ...
}
```

**Refactor:** Change the original method to accept a `FileInterface` entity. Move the load+null-check into a thin wrapper:

```php
/**
 * Processes a managed file entity.
 *
 * @param \Drupal\file\FileInterface $file
 *   The file entity to process.
 * @param bool $is_temp
 *   Whether to create temp items.
 * @param array &$context
 *   Batch context for sandbox access.
 */
protected function processManagedFile(FileInterface $file, bool $is_temp, array &$context): void {
  // All existing processing logic — unchanged. Just remove the
  // ->load() call and null check that were at the top.
}

/**
 * Backward-compat wrapper: loads a file by ID and processes it.
 *
 * Only needed if the old scan*Chunk() (non-New) methods still call
 * by ID. If those methods are dead code, remove this wrapper entirely
 * (see Fix 2d below).
 */
protected function processManagedFileById(int $fid, bool $is_temp, array &$context): void {
  $file = $this->entityTypeManager->getStorage('file')->load($fid);
  if (!$file) {
    return;
  }
  $this->processManagedFile($file, $is_temp, $context);
}
```

### 2b. Phase 4 — `processRemoteMedia()`

Same pattern:

```php
// Refactored — accepts entity directly:
protected function processRemoteMedia(MediaInterface $media, bool $is_temp): void {
  // All existing processing logic, no ->load() call.
}

// Backward-compat wrapper (if needed):
protected function processRemoteMediaById(int $mid, bool $is_temp): void {
  $media = $this->entityTypeManager->getStorage('media')->load($mid);
  if (!$media) return;
  $this->processRemoteMedia($media, $is_temp);
}
```

### 2c. Phase 5 — `processMenuLink()`

Same pattern:

```php
// Refactored — accepts entity directly:
protected function processMenuLink(MenuLinkContentInterface $link, bool $is_temp): void {
  // All existing processing logic, no ->load() call.
}

// Backward-compat wrapper (if needed):
protected function processMenuLinkById(int $id, bool $is_temp): void {
  $link = $this->entityTypeManager->getStorage('menu_link_content')->load($id);
  if (!$link) return;
  $this->processMenuLink($link, $is_temp);
}
```

### 2d. Remove Dead Code

The old non-New chunk methods (`scanManagedFilesChunk()`, `scanOrphanFilesChunk()`, `scanContentChunk()`, etc. — the versions without "New" suffix) may be dead code if only the `*ChunkNew()` versions are called by the checkpoint flow.

**Check:** Search for calls to the old chunk method names in `ScanAssetsForm.php` and anywhere batch operations are registered. If no code references them:
- Remove the old chunk methods entirely
- Remove the `*ById()` wrappers (no callers remain)
- This eliminates dead code instead of deprecating it

If any code still references the old methods, keep the `*ById()` wrappers and add `@deprecated` with a removal target.

**NOTE:** The exact method names, parameter lists, and type hints may differ from what's shown here. Inspect the actual code to determine:
- The exact current method names (might be `scanManagedFile`, `processFile`, etc.)
- What parameters they accept beyond the entity ID
- What the `$is_temp` parameter is called (might be `$is_temporary`)
- Whether `$context` is passed by reference

The pattern is the same regardless: refactor the original to accept an entity, create a thin `*ById()` wrapper for backward compat if needed.

### 2e. Known Remaining Hotspot: Secondary Entity Loads Inside Processing

Phase 1's `processManagedFile()` also loads media entities internally — for each file that has a media association, it calls `$this->entityTypeManager->getStorage('media')->load($media_id)`. The `loadMultiple()` at the caller level only bulk-loads file entities, not the secondary media lookups inside the processing loop.

This is a known remaining hotspot. On a site with 5,500 files where most are media-associated, this adds ~0.1-0.2s per item from the secondary load. Fixing it requires collecting media IDs from the batch of files, bulk-loading media, and passing a lookup map into the process method — a deeper refactor for a follow-up.

**For now:** The primary `loadMultiple()` fix delivers the biggest improvement (5-7x). The secondary media loads reduce the theoretical maximum but still result in a major net improvement.

---

## Fix 3: Update Phase Callers to Use loadMultiple

**File:** `src/Service/DigitalAssetScanner.php`

Each phase's scan*Chunk method must be updated to pass the new `$loadFn` argument.

### 3a. Phase 1 — `scanManagedFilesChunk()`

Find the existing `processWithTimeBudget()` call in `scanManagedFilesChunk()` (or `scanManagedFilesChunkNew()`). It currently looks like:

```php
$itemsThisCallback = $this->processWithTimeBudget(
  $context,
  'last_fid',
  'total_files',
  fn() => $this->countManagedFiles(),
  fn(int $lastFid, int $limit) => $this->getManagedFileIdsAfter($lastFid, $limit),
  fn(int $fid) => $this->processManagedFile($fid, $is_temp, $context),
);
```

Replace with:

```php
$itemsThisCallback = $this->processWithTimeBudget(
  $context,
  'last_fid',
  'total_files',
  fn() => $this->countManagedFiles(),
  fn(int $lastFid, int $limit) => $this->getManagedFileIdsAfter($lastFid, $limit),
  fn(array $ids) => $this->entityTypeManager->getStorage('file')->loadMultiple($ids),
  fn($file) => $this->processManagedFile($file, $is_temp, $context),
);
```

### 3b. Phase 4 — `scanRemoteMediaChunk()`

Find the existing `processWithTimeBudget()` call. Replace with:

```php
$itemsThisCallback = $this->processWithTimeBudget(
  $context,
  'last_mid',
  'total_media',
  fn() => $this->countRemoteMedia(),
  fn(int $lastMid, int $limit) => $this->getRemoteMediaIdsAfter($lastMid, $limit),
  fn(array $ids) => $this->entityTypeManager->getStorage('media')->loadMultiple($ids),
  fn($media) => $this->processRemoteMedia($media, $is_temp),
);
```

### 3c. Phase 5 — `scanMenuLinksChunk()`

Find the existing `processWithTimeBudget()` call. Replace with:

```php
$itemsThisCallback = $this->processWithTimeBudget(
  $context,
  'last_id',
  'total_menu_links',
  fn() => $this->countMenuLinks(),
  fn(int $lastId, int $limit) => $this->getMenuLinkIdsAfter($lastId, $limit),
  fn(array $ids) => $this->entityTypeManager->getStorage('menu_link_content')->loadMultiple($ids),
  fn($link) => $this->processMenuLink($link, $is_temp),
);
```

---

## Fix 4: Cache Orphan List in Sandbox (Phase 2)

**File:** `src/Service/DigitalAssetScanner.php`

Find `scanOrphanFilesChunkNew()`. It currently calls `buildOrphanFileList()` at the start of every callback. Change it to build once and store in sandbox.

Find this block:

```php
  // Rebuild orphan file list each callback (filesystem read is fast;
  // avoids serializing thousands of paths through the batch table).
  $orphanFiles = $this->buildOrphanFileList();

  // Initialize on first call.
  if (!isset($context['sandbox']['orphan_index'])) {
    $context['sandbox']['orphan_index'] = 0;
    $context['sandbox']['orphan_total'] = count($orphanFiles);
  }
```

Replace with:

```php
  // Build orphan list ONCE on first callback, store in sandbox.
  // With bulk query (Fix 1), list-building is fast (<1s).
  // Orphan count varies widely by site: tens on well-maintained sites,
  // 1,000+ on migration or legacy sites (~200 bytes/entry, so 1,636
  // orphans = ~320KB serialized — well within batch table limits).
  if (!isset($context['sandbox']['orphan_files'])) {
    $context['sandbox']['orphan_files'] = $this->buildOrphanFileList();
    $context['sandbox']['orphan_index'] = 0;
    $context['sandbox']['orphan_total'] = count($context['sandbox']['orphan_files']);
  }

  $orphanFiles = $context['sandbox']['orphan_files'];
```

Then update the remaining code in the method to read from `$orphanFiles` (local variable) instead of calling `buildOrphanFileList()` again. The `$index`, `$total`, processing loop, progress calculation, and cache resets stay the same.

---

## Fix 5: Paragraph Parent Cache (Phase 3)

**File:** `src/Service/DigitalAssetScanner.php`

### 5a. Add Cache Property

Add to the class properties:

```php
/**
 * Per-callback cache of paragraph parent lookups.
 *
 * Key: paragraph entity ID.
 * Value: Parent info array from getParentFromParagraph(), or NULL if no parent found.
 * Cleared per callback when entity caches are reset.
 */
private array $paragraphParentCache = [];
```

### 5b. Add Cache Lookup

Find every call site where `getParentFromParagraph($paragraph_id)` is called. Wrap each one with a cache check:

```php
// BEFORE:
$parent_info = $this->getParentFromParagraph($paragraph_id);

// AFTER:
if (!array_key_exists($paragraph_id, $this->paragraphParentCache)) {
  $this->paragraphParentCache[$paragraph_id] = $this->getParentFromParagraph($paragraph_id);
}
$parent_info = $this->paragraphParentCache[$paragraph_id];
```

**NOTE:** Use `array_key_exists()` not `isset()` because the cached value can be `NULL` (paragraph with no parent found). `isset()` would re-query every time for NULL results.

To find the call sites:

```bash
grep -n 'getParentFromParagraph' src/Service/DigitalAssetScanner.php
```

### 5c. Clear Cache Per Callback

The paragraph parent cache is a class property that persists within a single PHP request. In Batch API, each callback is a separate HTTP request so the property resets naturally. However, for explicitness and safety, clear it at the end of every chunk method that could trigger paragraph lookups.

Add to `resetPhaseEntityCaches()` (the shared cache-clearing method called at the end of every chunk):

```php
// In resetPhaseEntityCaches(), add:
$this->paragraphParentCache = [];
```

This ensures the cache is cleared regardless of which phase is running. Phase 1 also calls `getParentFromParagraph()` when tracing media usage through paragraphs, not just Phase 3.

---

## Fix 6: Bulk Lookups in Phase 2 Orphan Processing

**File:** `src/Service/DigitalAssetScanner.php`

**Problem:** Pantheon testing revealed 1,636 orphan files (not 33 as initially expected). Phase 2 processed only 10 orphans per 10-second callback (~1s per orphan), taking 27 minutes total — tied with Phase 1 as the longest phase.

`processOrphanFile()` runs individual entity queries per orphan:

```php
// CURRENT — one entity query per orphan file to check for existing temp items
$existing_query = $storage->getQuery();
$existing_query->condition('url_hash', $uri_hash);
$existing_query->condition('source_type', 'filesystem_only');
$existing_query->condition('is_temp', TRUE);
$existing_query->accessCheck(FALSE);
$existing_ids = $existing_query->execute();
```

With 1,636 orphans, that's 1,636+ individual entity queries across callbacks.

Additionally, `processOrphanFile()` likely calls other methods that do per-item DB lookups (creating the inventory item, checking usage records, etc.). Each adds ~10-20ms of network round-trip on Pantheon.

### 6a. Pre-Query Existing Temp Items in Bulk

**First:** Update the already-deployed `buildOrphanFileList()` to include a pre-computed `url_hash` in each orphan entry. This avoids recomputing `md5($uri)` in both the bulk pre-query and `processOrphanFile()`. This is a small addition to the method deployed with Fix 1:

```php
// In buildOrphanFileList(), when building the orphan array:
$orphan_files[] = [
  'path' => $file_path,
  'uri' => $uri,
  'relative' => $relative_path,
  'url_hash' => md5($uri),  // Pre-compute once.
];
```

**Then:** In `scanOrphanFilesChunkNew()`, after loading the orphan list from sandbox and the existing time budget setup (`$budget = $this->getBatchTimeBudget()`, `$startTime = microtime(true)`), add the bulk pre-query before the processing loop:

```php
  $orphanFiles = $context['sandbox']['orphan_files'];
  $index = $context['sandbox']['orphan_index'];
  $total = $context['sandbox']['orphan_total'];

  if ($index >= $total || empty($orphanFiles)) {
    $context['finished'] = 1;
    $context['results']['last_chunk_items'] = 0;
    return;
  }

  // Pre-query existing temp items for the upcoming batch.
  // Over-fetch 200 hashes — cheap bulk query, covers more than one callback
  // will process. Next callback re-slices from updated $index and re-queries,
  // which is fine since the bulk query is fast (<50ms).
  $upcoming = array_slice($orphanFiles, $index, 200);
  $orphan_hashes = array_column($upcoming, 'url_hash');

  // ONE bulk query instead of per-orphan queries.
  $existing_temp_map = [];
  if (!empty($orphan_hashes)) {
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $existing_ids = $storage->getQuery()
      ->condition('url_hash', $orphan_hashes, 'IN')
      ->condition('source_type', 'filesystem_only')
      ->condition('is_temp', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($existing_ids)) {
      $existing_items = $storage->loadMultiple($existing_ids);
      foreach ($existing_items as $item) {
        $existing_temp_map[$item->get('url_hash')->value] = $item;
      }
    }
  }

  // Process items from current index until budget exhausted.
  while ($index < $total) {
    if ((microtime(true) - $startTime) >= $budget) {
      break;
    }

    $this->processOrphanFile($orphanFiles[$index], $is_temp, $existing_temp_map);
    $this->maybeUpdateHeartbeat();

    $index++;
    $itemsThisCallback++;
  }
```

### 6b. Update `processOrphanFile()` to Accept Pre-Queried Map

Update the method signature to accept an optional pre-queried map of existing temp items:

```php
/**
 * Processes a single orphan file.
 *
 * @param array $orphan
 *   Orphan file info with 'path', 'uri', 'relative' keys.
 * @param bool $is_temp
 *   Whether to create temp items.
 * @param array $existing_temp_map
 *   Pre-queried map of url_hash => loaded entity for existing temp items.
 *   If the hash exists in this map, skip the per-item entity query.
 */
protected function processOrphanFile(array $orphan, bool $is_temp, array $existing_temp_map = []): void {
  $url_hash = $orphan['url_hash'];  // Pre-computed in buildOrphanFileList().

  // Check pre-queried map. The map covers all upcoming orphans for this callback,
  // so absence means the item genuinely doesn't exist — no fallback query needed.
  if (isset($existing_temp_map[$url_hash])) {
    $existing_item = $existing_temp_map[$url_hash];
    // Update existing item if needed...
  }
  else {
    // No existing temp item — create a new one.
    // ... existing creation logic ...
  }

  // ... rest of processing logic unchanged ...
}
```

**IMPORTANT:** Inspect the actual `processOrphanFile()` code to identify ALL individual DB queries inside it, not just the existing-item check. Common patterns that need bulk-ifying:

1. **Existing temp item check** (shown above) — bulk query by `url_hash`
2. **Usage record check/creation** — if the method queries `digital_asset_usage` per orphan
3. **File stat operations** — `filesize()`, `filemtime()` are filesystem calls, not DB — these are fine

The `existing_temp_map` pattern handles #1. If #2 is also per-item, apply the same pattern: collect all relevant keys, bulk-query usage records before the loop, pass the map.

### 6c. Adjust Sandbox Cache for Large Orphan Lists

With 1,636 orphans on the test site, the sandbox serialization is ~320KB (200 bytes/entry × 1,636). This is well within MySQL/MariaDB limits for the Batch API `batch` table. Add a guard for extremely large lists:

```php
  if (!isset($context['sandbox']['orphan_files'])) {
    $orphan_list = $this->buildOrphanFileList();

    // For very large orphan lists (>5000), log a warning.
    // Consider whether the site needs cleanup rather than scanning all orphans.
    if (count($orphan_list) > 5000) {
      $this->logger->warning('Large orphan file count: @count. Consider cleaning up unused files to improve scan performance.', [
        '@count' => count($orphan_list),
      ]);
    }

    $context['sandbox']['orphan_files'] = $orphan_list;
    $context['sandbox']['orphan_index'] = 0;
    $context['sandbox']['orphan_total'] = count($orphan_list);
  }
```

### 6d. Expected Impact

| Metric | Before Fix 6 | After Fix 6 |
|--------|-------------|-------------|
| Per-orphan time | ~1s (individual entity queries) | ~0.05-0.1s (in-memory map lookup) |
| Orphans per 10s callback | ~10 | ~100-200 |
| Phase 2 (1,636 orphans) | ~27 min | ~2-3 min |

---

## Implementation Order

All six fixes must be deployed together as a single commit. The signature change to `processWithTimeBudget()` (Fix 1) breaks the callers (Fix 3) if deployed separately.

```
Fix 1: Update processWithTimeBudget() signature + body
  ↓
Fix 2: Refactor process methods to accept entities + remove dead code
  ↓
Fix 3: Update phase callers to pass loadFn + use refactored methods
  ↓
Fix 4: Cache orphan list in sandbox (independent, same commit)
  ↓
Fix 5: Paragraph parent cache (independent, same commit)
  ↓
Fix 6: Bulk lookups in orphan processing (independent, same commit)
```

---

## Verification

### Code Verification

Before deploying, check:

1. **`processWithTimeBudget()` has 7 callable parameters:** `$context`, `$cursorKey`, `$totalKey`, `$countFn`, `$queryFn`, `$loadFn`, `$processFn`. Search for `processWithTimeBudget(` — should appear 4 times (1 definition + 3 callers).

2. **All callers pass `$loadFn`:** Each of the 3 callers must have a `fn(array $ids) => ...->loadMultiple($ids)` argument between the query function and the process function.

3. **Process methods accept entities, not IDs:** `processManagedFile()`, `processRemoteMedia()`, `processMenuLink()` should have entity type hints as their first parameter (`FileInterface`, `MediaInterface`, `MenuLinkContentInterface`), NOT `int`. They should NOT contain `->load(` calls.

4. **Dead code removed or wrapped:** If old non-New chunk methods are dead code, they should be removed entirely. If they're still called, thin `*ById()` wrappers should exist that call through to the refactored methods. Search for the old method signatures — no duplicate processing logic should exist.

5. **Secondary media loads documented:** `processManagedFile()` still loads media entities internally per file. This is a known remaining hotspot, not a bug. Verify it hasn't been accidentally removed during refactoring.

6. **Orphan list cached:** In `scanOrphanFilesChunkNew()`, verify `$context['sandbox']['orphan_files']` is set on first call. `buildOrphanFileList()` should be called exactly once, inside the `if (!isset(...))` block.

7. **Paragraph cache uses `array_key_exists()`:** Not `isset()`. The cached value can be NULL.

8. **Paragraph cache is cleared:** Search for `paragraphParentCache = []` — should appear inside `resetPhaseEntityCaches()`, which is called at the end of every chunk method.

9. **Orphan bulk lookup:** In `scanOrphanFilesChunkNew()`, verify a bulk entity query with `->condition('url_hash', $hashes, 'IN')` runs before the processing loop. `processOrphanFile()` should accept a pre-queried map parameter.

10. **No per-orphan entity queries in loop:** Inside `processOrphanFile()`, verify there are no `->getQuery()->condition('url_hash', $single_hash)` calls. The existing-item check should use the passed-in map.

11. **Orphan entries include pre-computed hash:** In `buildOrphanFileList()`, each entry in the returned array should have a `url_hash` key (`md5($uri)`). `processOrphanFile()` should read `$orphan['url_hash']`, not recompute `md5($orphan['uri'])`.

12. **Cron suspension uses DI:** `suspendCron()` and `restoreCron()` should use `$this->moduleHandler->moduleExists()`, NOT `\Drupal::moduleHandler()`. Verify `ModuleHandlerInterface` is in the constructor and `@module_handler` is in `services.yml`.

13. **Cron restore on all exit paths:** Search for `restoreCron()` — should appear in `batchFinished()` success path, `batchFinished()` error path, and `breakStaleLock()`.

### Automated Tests

```bash
cd web && SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  --group digital_asset_inventory \
  modules/custom/digital_asset_inventory/tests/src
```

### Pantheon Testing

After deploying to a Pantheon multidev:

```bash
# Clear old scan state
terminus drush site.env -- ev "\Drupal::service('lock.persistent')->release('digital_asset_inventory_scan');"
terminus drush site.env -- ev "\Drupal::service('digital_asset_inventory.scanner')->clearCheckpoint();"
terminus drush site.env -- sqlq "DELETE FROM digital_asset_item WHERE is_temp = 1"
terminus drush site.env -- cr
```

Run the scan, then check logs:

```bash
terminus drush site.env -- ws --count=20 --type=digital_asset_inventory
```

**Expected results:**

| Metric | Before | After |
|--------|--------|-------|
| Phase 1 items per callback | ~43 | ~150-300 |
| Phase 1 per-item time | ~0.22s | ~0.03-0.07s |
| Phase 1 total callbacks | ~130 | ~20-40 |
| Phase 1 total time | ~25 min | ~5-8 min |
| Phase 2 orphans per callback | ~10 | ~100-200 |
| Phase 2 total time (1,636 orphans) | ~27 min | ~2-3 min |
| Full scan total | ~54 min | ~10-15 min |

If Phase 1 items per callback is still ~43 after deployment, the process methods may still contain internal `->load()` calls from the old signature. Check that `processManagedFile()` now accepts `FileInterface` as the first parameter, not `int`.

If Phase 2 orphans per callback is still ~10, check that `processOrphanFile()` receives the `$existing_temp_map` parameter and uses it instead of per-item entity queries.

**Note:** Even with loadMultiple, Phase 1 won't reach the theoretical maximum of 500+ items/callback because `processManagedFile()` still loads media entities internally per file (see Fix 2e). Expect ~150-300 items/callback — still a major improvement over ~43.

### UCTech Regression Check

Also test on a small site to verify no regression:

```bash
terminus drush site.env -- ws --count=20 --type=digital_asset_inventory
```

Small sites should complete in fewer callbacks than before (loadMultiple processes more items per callback, so fewer callbacks needed). Expected: 4-5 callbacks total instead of 7.

---

## Appendix: Recovery & Troubleshooting Documentation

**Add to module README or admin documentation.**

### Clearing a Stuck Scan Lock

The lock backend varies by hosting environment. Always use the lock service API, not direct SQL:

```bash
# Release the lock (works on all backends — MySQL, Redis, Memcache)
drush ev "\Drupal::service('lock.persistent')->release('digital_asset_inventory_scan');"

# Verify lock is cleared
drush ev "echo \Drupal::service('lock.persistent')->lockMayBeAvailable('digital_asset_inventory_scan') ? 'FREE' : 'LOCKED';"

# Clear checkpoint (if starting completely fresh)
drush ev "\Drupal::service('digital_asset_inventory.scanner')->clearCheckpoint();"

# Clear temp items from failed scan
drush sqlq "DELETE FROM digital_asset_item WHERE is_temp = 1"

# Clear cache
drush cr
```

**For Pantheon via Terminus:**

```bash
terminus drush site.env -- ev "\Drupal::service('lock.persistent')->release('digital_asset_inventory_scan');"
terminus drush site.env -- ev "echo \Drupal::service('lock.persistent')->lockMayBeAvailable('digital_asset_inventory_scan') ? 'FREE' : 'LOCKED';"
```

**IMPORTANT:** Do NOT use `drush sqlq "DELETE FROM semaphore WHERE name = '...'"` — this only works on sites using MySQL for locks. Sites using Redis (Pantheon, many Acquia environments) store locks in Redis, not the semaphore table. The `lock.persistent` service abstracts the backend correctly.

### Automatic Cron Suspension During Scans

Cron jobs compete for DB/memory resources during scans. The scanner automatically disables automated cron at scan start and re-enables it when the scan completes (or fails).

**Implementation** (add to `src/Service/DigitalAssetScanner.php`):

**Constructor dependency:** Add `ModuleHandlerInterface $moduleHandler` to the constructor and `@module_handler` to `services.yml`.

```php
use Drupal\Core\Extension\ModuleHandlerInterface;

// In constructor:
protected ModuleHandlerInterface $moduleHandler,
```

```php
/**
 * Suspends automated cron for the duration of the scan.
 *
 * Saves the current cron interval to State so it can be restored
 * after scan completion, even if the scan is interrupted and resumed.
 */
public function suspendCron(): void {
  // Guard: not all sites use automated_cron. Some use system cron (crontab)
  // or external cron services. Skip if the module isn't installed.
  if (!$this->moduleHandler->moduleExists('automated_cron')) {
    return;
  }

  $config = $this->configFactory->getEditable('automated_cron.settings');
  $currentInterval = $config->get('interval');

  // Don't suspend if already suspended (interval = 0) or if we already saved.
  if ($currentInterval > 0) {
    $this->state->set('dai.scan.cron_interval_backup', $currentInterval);
    $config->set('interval', 0)->save();
    $this->logger->notice('Automated cron suspended during scan (was @interval seconds).', [
      '@interval' => $currentInterval,
    ]);
  }
}

/**
 * Restores automated cron after scan completion.
 */
public function restoreCron(): void {
  if (!$this->moduleHandler->moduleExists('automated_cron')) {
    $this->state->delete('dai.scan.cron_interval_backup');
    return;
  }

  $savedInterval = $this->state->get('dai.scan.cron_interval_backup');
  if ($savedInterval) {
    $config = $this->configFactory->getEditable('automated_cron.settings');
    $config->set('interval', $savedInterval)->save();
    $this->state->delete('dai.scan.cron_interval_backup');
    $this->logger->notice('Automated cron restored (@interval seconds).', [
      '@interval' => $savedInterval,
    ]);
  }
}
```

**Call sites** (in `src/Form/ScanAssetsForm.php`):

```php
// In submitForm(), after acquiring lock and before batch_set():
$scanner->suspendCron();

// In batchFinished(), in BOTH success and error paths:
$scanner->restoreCron();
```

**Edge case — interrupted scan:** If the scan is interrupted (browser closed, timeout), cron stays disabled. When the user returns and clicks Resume or Start Fresh, `restoreCron()` is called in `batchFinished()` of the new scan. As a safety net, `breakStaleLock()` should also call `restoreCron()`:

```php
public function breakStaleLock(): void {
  // ... existing lock break logic ...
  $this->restoreCron();
}
```

**State key:** `dai.scan.cron_interval_backup` — stores the original interval. Deleted after restore. If this key exists and no scan is running, cron was left disabled by an interrupted scan — `restoreCron()` handles it.

### Tuning Guide

| Environment | Time Budget | Stale Threshold | Rationale |
|-------------|-------------|-----------------|-----------|
| Pantheon | 10 (default) | 900 (default) | ~59s timeout, 10s + 3s overhead = safe |
| Acquia | 10-15 | 900 | Similar timeout profile |
| Platform.sh | 10-15 | 900 | 60s default timeout |
| VPS/AWS | 15-20 | 900 | Higher timeouts, fewer round-trips |
| Local/DDEV | 20-25 | 900 | No timeout, minimize round-trips |
| Aggressive proxy (30s) | 4-8 | 600 | Short proxy timeout, need headroom |
