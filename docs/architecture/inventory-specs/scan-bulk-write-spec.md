# Bulk Write Performance Fix

## Context

The `loadMultiple` performance fix (see `scan-loadmultiple-spec.md`) had **zero measurable effect** on scan throughput. Phase 1 still processes ~43 items per 10-second callback — identical to before.

**Root cause:** The read was never the bottleneck. The **writes** are.

Every item processed in `processManagedFile()` performs:

| Operation | Calls per file | Time on managed hosting |
|-----------|---------------|-----------------|
| `$item->save()` (Entity API INSERT) | 1–3 | ~0.1s each |
| `$usage_storage->create([...])->save()` | 1–5 | ~0.1s each |
| Entity queries for dedup/existence checks | 3–8 | ~0.03s each |
| `updateCsvExportFields()` — loads asset, queries usage, loads parent entities, generates URLs, saves | 1 | ~0.3–0.5s |
| **Total per file (typical)** | | **~0.8–1.2s** |

For 5,527 managed files at ~1s each → ~92 min of pure write time, spread across ~130 batch callbacks at ~43 items per 10s window.

The `updateCsvExportFields()` call alone accounts for ~40% of per-item time. It is called **18 times** across the codebase — once per item in every phase that creates or touches assets.

### Why Entity API saves are slow for temp items

Each `$storage->create([...])->save()` triggers:

1. UUID generation
2. Entity validation (`$entity->validate()`)
3. `hook_entity_presave()` / `hook_entity_insert()` invocations
4. Individual `INSERT INTO` SQL statement
5. Entity cache invalidation
6. Key-value store updates

For **temporary scan items** (`is_temp = TRUE`) that get atomically swapped at the end, none of this overhead is needed. No module listens for `digital_asset_item` or `digital_asset_usage` hooks. No cache consumers read temp items. Validation is unnecessary because the scanner controls all field values.

### Already deployed (not repeated here)

- `loadMultiple` batch loading (scan-loadmultiple-spec.md) — reduced read time to near-zero
- Resilience fixes (scan-resilience-spec.md) — heartbeat, checkpoint, stale lock detection
- Orphan list caching, paragraph parent cache, bulk orphan pre-query

### Test site reference data (~5,500 files)

| Phase | Items | Current duration | Items/callback | Bottleneck |
|-------|-------|-----------------|----------------|------------|
| Phase 1 – Managed files | 5,527 | ~28 min | ~43 | Entity saves + updateCsvExportFields |
| Phase 2 – Orphan files | 1,636 | ~27 min | ~10 | Entity saves + updateCsvExportFields |
| Phase 3 – Content (external URLs) | 38,315 rows | ~30 sec | ~400+ | Already fast (most rows have no URLs) |
| Phase 4 – Remote media | 17 | ~14 sec | all | Already fast |
| Phase 5 – Menu links | 196 | ~1 sec | all | Already fast |
| Promotion | 7,485 items | ~5 min | N/A | Entity load + save per item |
| **Total** | | **~62 min** | | |

---

## Strategy: Raw SQL for Temp Items + Deferred CSV Fields

Three changes deliver the performance gain:

1. **Replace Entity API saves with raw SQL INSERT/UPDATE** for temp `digital_asset_item` and `digital_asset_usage` records
2. **Buffer usage records in memory**, flush as one bulk INSERT at callback end
3. **Defer `updateCsvExportFields()` to a new Phase 6**, executed once after all scanning phases complete

These changes are **safe** because:

- Temp items have no hook subscribers — no module listens for `digital_asset_item` entity hooks
- Temp items are invisible to the UI — they're filtered out by `is_temp = 1`
- The atomic swap (`promoteTemporaryItems()`) only runs after all phases complete
- Entity caches don't cache temp items — they're never loaded by the render pipeline
- The scanner controls all field values — validation is unnecessary

### Expected impact (original estimates vs measured)

| Phase | Before | Estimated | Measured (Site 1) | Notes |
|-------|--------|-----------|-----------------|-------|
| Phase 1 (5,527 files) | ~28 min (~43/cb) | ~5 min (~200+/cb) | **~20 min (~48-65/cb)** | Reads dominate; see Appendix C.1 |
| Phase 2 (1,636 orphans) | ~27 min (~10/cb) | ~2 min (~100+/cb) | **~27 min (~4-12/cb)** | LIKE queries dominate; see Appendix C.2 |
| Phase 6 (CSV fields) | N/A (was inline) | ~3 min (new phase) | TBD | |
| Promotion | ~5 min (entity saves) | ~5 sec (raw SQL) | **~2s** | ✅ 150× faster |
| **Total** | **~62 min** | **~12 min** | **~50 min (est)** | Reads are the real bottleneck |

**Key finding:** Entity API write overhead on managed hosting is much lower than originally measured (~0.03s/save, not ~0.1s). The per-item cost is dominated by **read queries** — `file_usage` lookups, media entity queries, text-field LIKE searches — not writes. The bulk write changes deliver their full value on the local dev environment (19s → 3s) but have minimal impact on managed hosting where reads account for ~80% of per-item time. See Appendix C for the next optimization targets.

---

## Files to Modify

| File | Changes |
|------|---------|
| `src/Service/DigitalAssetScanner.php` | Add raw SQL write methods, usage buffer, refactor Phase 1/2 process methods, add Phase 6, refactor promotion |
| `src/Form/ScanAssetsForm.php` | Add Phase 6 batch operation, update PHASE_MAP |

---

## Fix 1: Add Raw SQL Write Methods

**File:** `src/Service/DigitalAssetScanner.php`

### 1a. Add Usage Buffer Property

Add to the class properties, near the existing `$paragraphParentCache` property:

```php
/**
 * In-memory buffer of usage records pending bulk INSERT.
 *
 * Each entry is an associative array of column => value.
 * Flushed to the database at the end of each batch callback
 * via flushUsageBuffer().
 */
private array $usageBuffer = [];
```

### 1b. Add `rawInsertAssetItem()` Method

This replaces `$storage->create([...])->save()` for temp items. Uses Drupal's database abstraction layer (not raw SQL strings) for DB portability, but bypasses the Entity API entirely.

```php
/**
 * Inserts a digital_asset_item row via raw SQL, bypassing Entity API.
 *
 * Safe for temp items only — no hooks, validation, or cache invalidation.
 * Generates a UUID automatically. Returns the auto-increment ID.
 *
 * @param array $fields
 *   Associative array of column => value. Must NOT include 'id' or 'uuid'.
 *   Required keys: source_type, file_name, file_path, is_temp.
 *
 * @return int
 *   The auto-generated entity ID.
 */
protected function rawInsertAssetItem(array $fields): int {
  $fields['uuid'] = \Drupal::service('uuid')->generate();
  $now = \Drupal::time()->getRequestTime();
  $fields += [
    'created' => $now,
    'changed' => $now,
    'filesize_formatted' => $this->formatFileSize($fields['filesize'] ?? 0),
    'active_use_csv' => '',
    'used_in_csv' => '',
    'location' => '',
  ];
  return (int) $this->database->insert('digital_asset_item')
    ->fields($fields)
    ->execute();
}
```

**Why Drupal's `$this->database->insert()` and not a raw query string:**

- Drupal's `Insert` query builder generates a proper parameterized query
- Single-row `->execute()` returns the auto-increment ID via `lastInsertId()`
- Works across MySQL, MariaDB, PostgreSQL, SQLite
- Still bypasses Entity API — the overhead we're eliminating is entity hooks/validation/cache, not the query builder

### 1c. Add `rawUpdateAssetItem()` Method

For conditional updates (e.g., reverse thumbnail detection changes `source_type` after initial insert):

```php
/**
 * Updates specific columns on a digital_asset_item row via raw SQL.
 *
 * @param int $id
 *   The entity ID.
 * @param array $fields
 *   Associative array of column => value to update.
 */
protected function rawUpdateAssetItem(int $id, array $fields): void {
  $fields['changed'] = \Drupal::time()->getRequestTime();
  $this->database->update('digital_asset_item')
    ->fields($fields)
    ->condition('id', $id)
    ->execute();
}
```

### 1d. Add `bufferUsageRecord()` Method

Collects usage records in memory. The buffer is flushed at the end of each batch callback.

```php
/**
 * Adds a usage record to the in-memory buffer for bulk INSERT.
 *
 * @param array $fields
 *   Associative array with keys: asset_id, entity_type, entity_id,
 *   field_name, count, embed_method. Optional: presentation_type,
 *   accessibility_signals, signals_evaluated.
 */
protected function bufferUsageRecord(array $fields): void {
  $fields += [
    'count' => 1,
    'presentation_type' => '',
    'accessibility_signals' => '',
    'signals_evaluated' => 0,
    'embed_method' => 'field_reference',
  ];
  $this->usageBuffer[] = $fields;
}
```

### 1e. Add `flushUsageBuffer()` Method

Bulk INSERTs all buffered usage records in one database operation.

```php
/**
 * Flushes the usage record buffer to the database via bulk INSERT.
 *
 * Inserts all buffered records in a single multi-row INSERT statement.
 * On managed hosting, 100 individual entity saves (~20s) become 1 bulk INSERT (~0.1s).
 *
 * Clears the buffer after successful insertion.
 */
protected function flushUsageBuffer(): void {
  if (empty($this->usageBuffer)) {
    return;
  }

  $columns = [
    'uuid', 'asset_id', 'entity_type', 'entity_id', 'field_name',
    'count', 'embed_method', 'presentation_type',
    'accessibility_signals', 'signals_evaluated',
  ];

  $insert = $this->database->insert('digital_asset_usage')->fields($columns);

  foreach ($this->usageBuffer as $record) {
    $insert->values([
      'uuid' => \Drupal::service('uuid')->generate(),
      'asset_id' => $record['asset_id'],
      'entity_type' => $record['entity_type'],
      'entity_id' => $record['entity_id'],
      'field_name' => $record['field_name'] ?? '',
      'count' => $record['count'] ?? 1,
      'embed_method' => $record['embed_method'] ?? 'field_reference',
      'presentation_type' => $record['presentation_type'] ?? '',
      'accessibility_signals' => $record['accessibility_signals'] ?? '',
      'signals_evaluated' => $record['signals_evaluated'] ?? 0,
    ]);
  }

  $insert->execute();
  $this->usageBuffer = [];
}
```

**NOTE:** Drupal's MySQL `Insert` driver batches multiple `->values()` calls into a single `INSERT INTO ... VALUES (...), (...), (...)` statement. This is the bulk INSERT behavior we need.

### 1f. Add `rawDeleteUsageByAssetId()` Method

Replaces the entity query + loadMultiple + delete pattern:

```php
/**
 * Deletes all usage records for a given asset ID via raw SQL.
 *
 * Replaces: $usage_storage->getQuery()->condition('asset_id', $id)...
 *           $usage_storage->delete($usage_storage->loadMultiple($ids))
 *
 * @param int $asset_id
 *   The digital_asset_item entity ID.
 */
protected function rawDeleteUsageByAssetId(int $asset_id): void {
  $this->database->delete('digital_asset_usage')
    ->condition('asset_id', $asset_id)
    ->execute();
}
```

### 1g. Add `rawDeleteOrphanRefsByAssetId()` Method

Same pattern for orphan references:

```php
/**
 * Deletes all orphan reference records for a given asset ID via raw SQL.
 *
 * @param int $asset_id
 *   The digital_asset_item entity ID.
 */
protected function rawDeleteOrphanRefsByAssetId(int $asset_id): void {
  $this->database->delete('dai_orphan_reference')
    ->condition('asset_id', $asset_id)
    ->execute();
}
```

### 1h. Add `rawInsertOrphanReference()` Method

Replaces `createOrphanReference()` entity saves for the most common case:

```php
/**
 * Inserts an orphan reference record via raw SQL.
 *
 * @param int $asset_id
 *   The digital_asset_item entity ID.
 * @param string $source_entity_type
 *   The orphan source entity type (e.g., 'paragraph').
 * @param int $source_entity_id
 *   The orphan source entity ID.
 * @param string $source_bundle
 *   The bundle of the source entity.
 * @param string $field_name
 *   The field containing the reference.
 * @param string $embed_method
 *   How the asset is referenced.
 * @param string $reference_context
 *   Why this reference is orphaned.
 */
protected function rawInsertOrphanReference(
  int $asset_id,
  string $source_entity_type,
  int $source_entity_id,
  string $source_bundle,
  string $field_name,
  string $embed_method,
  string $reference_context,
): void {
  $this->database->insert('dai_orphan_reference')
    ->fields([
      'uuid' => \Drupal::service('uuid')->generate(),
      'asset_id' => $asset_id,
      'source_entity_type' => $source_entity_type,
      'source_entity_id' => $source_entity_id,
      'source_bundle' => $source_bundle,
      'field_name' => $field_name,
      'embed_method' => $embed_method,
      'reference_context' => $reference_context,
    ])
    ->execute();
}
```

---

## Fix 2: Refactor `processManagedFile()` to Use Raw SQL Writes

**File:** `src/Service/DigitalAssetScanner.php`

### 2a. Overview of Changes

The current `processManagedFile()` (lines 512–868) does ~15 DB write operations per file via the Entity API. The refactored version keeps all **read logic** (determining source_type, finding usage, resolving paragraphs) identical but replaces every **write** with the raw SQL methods from Fix 1.

Changes within the method:

| Current code | Replacement |
|-------------|-------------|
| `$storage->getQuery()->condition('fid', ...)->condition('is_temp', TRUE)...` then `$storage->load()` then `$item->set(...)` then `$item->save()` | `$this->database->select('digital_asset_item')` to check existence, then `rawInsertAssetItem()` or `rawUpdateAssetItem()` |
| `$usage_storage->getQuery()...` + `$usage_storage->loadMultiple()` + `$usage_storage->delete()` | `$this->rawDeleteUsageByAssetId($asset_id)` |
| `$usage_storage->create([...])->save()` (each usage record) | `$this->bufferUsageRecord([...])` |
| `$this->updateCsvExportFields($asset_id, ...)` | **Remove entirely** — deferred to Phase 6 |
| `$this->createOrphanReference(...)` | `$this->rawInsertOrphanReference(...)` where the source entity bundle lookup is done from data already in memory, or via a lightweight raw query. For the less-common cases where the source entity must be loaded to get the bundle, keep the existing method. |
| `$this->registerDerivedFileUsage(...)` | Keep Entity API — called infrequently (~100–500 times per scan vs 5,500 for main files). The read complexity inside this method (loading file entities, checking system icons) makes raw SQL refactoring fragile for marginal gain. |

### 2b. Replace Existing Item Check + Insert/Update

Find the block in `processManagedFile()` that checks for existing temp items and creates/updates:

```php
    // Find existing TEMP entity by fid field.
    $existing_query = $storage->getQuery()
      ->condition('fid', $file->fid)
      ->condition('is_temp', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    if ($existing_ids = $existing_query) {
      $existing = $storage->load(reset($existing_ids));
      $item = $existing;
      $item->set('source_type', $source_type);
      // ... more ->set() calls ...
    }
    else {
      $item = $storage->create([
        'fid' => $file->fid,
        // ... field values ...
      ]);
    }

    $item->save();
    $asset_id = $item->id();
```

Replace with:

```php
    // Check for existing TEMP item by fid — lightweight raw query.
    $existing_id = $this->database->select('digital_asset_item', 'dai')
      ->fields('dai', ['id'])
      ->condition('fid', $file->fid)
      ->condition('is_temp', 1)
      ->execute()
      ->fetchField();

    $item_fields = [
      'fid' => $file->fid,
      'source_type' => $source_type,
      'media_id' => $media_id,
      'asset_type' => $asset_type,
      'category' => $category,
      'sort_order' => $sort_order,
      'file_path' => $absolute_url,
      'file_name' => $file->filename,
      'mime_type' => $file->filemime,
      'filesize' => $file->filesize,
      'is_temp' => $is_temp ? 1 : 0,
      'is_private' => $is_private ? 1 : 0,
    ];

    if ($existing_id) {
      $this->rawUpdateAssetItem((int) $existing_id, $item_fields);
      $asset_id = (int) $existing_id;
    }
    else {
      $asset_id = $this->rawInsertAssetItem($item_fields);
    }
```

### 2c. Replace Usage Record Deletion

Find:

```php
    // Clear existing usage records for this asset before re-scanning.
    $old_usage_query = $usage_storage->getQuery();
    $old_usage_query->condition('asset_id', $asset_id);
    $old_usage_query->accessCheck(FALSE);
    $old_usage_ids = $old_usage_query->execute();

    if ($old_usage_ids) {
      $old_usages = $usage_storage->loadMultiple($old_usage_ids);
      $usage_storage->delete($old_usages);
    }
```

Replace with:

```php
    // Clear existing usage records — one raw SQL DELETE.
    $this->rawDeleteUsageByAssetId($asset_id);
```

### 2d. Replace Usage Record Creation

Every `$usage_storage->create([...])->save()` call becomes `$this->bufferUsageRecord([...])`.

**Example — direct file usage:**

Find:

```php
      if (!$existing_usage_ids) {
        $usage_storage->create([
          'asset_id' => $asset_id,
          'entity_type' => $parent_entity_type,
          'entity_id' => $parent_entity_id,
          'field_name' => $ref['field_name'],
          'count' => 1,
          'embed_method' => 'field_reference',
        ])->save();
      }
```

Replace with:

```php
      $this->bufferUsageRecord([
        'asset_id' => $asset_id,
        'entity_type' => $parent_entity_type,
        'entity_id' => $parent_entity_id,
        'field_name' => $ref['field_name'],
        'embed_method' => 'field_reference',
      ]);
```

**NOTE:** The dedup check (`if (!$existing_usage_ids)`) can be removed because we already called `rawDeleteUsageByAssetId($asset_id)` above — there are no existing records to conflict with. However, within the same item's processing, duplicate usage entries can occur from multiple code paths (direct file usage + media usage for the same entity). To handle this, collect usage keys in a local `$seen_usage` set:

```php
    // Track unique usage keys within this item to avoid duplicates.
    $seen_usage = [];

    // ... in each usage creation block:
    $usage_key = $parent_entity_type . ':' . $parent_entity_id . ':' . $ref['field_name'];
    if (!isset($seen_usage[$usage_key])) {
      $seen_usage[$usage_key] = TRUE;
      $this->bufferUsageRecord([...]);
    }
```

### 2e. Remove Second Usage Deletion Block

The current code has a **second** usage deletion block specifically for media files that deletes non-direct-field usages before re-creating them. With `rawDeleteUsageByAssetId()` already clearing ALL usage records for this asset at the top (2c), this second deletion block is unnecessary.

Find (inside the `if (!empty($all_media_ids))` block):

```php
      $old_usage_query = $usage_storage->getQuery();
      $old_usage_query->condition('asset_id', $asset_id);
      $old_usage_query->accessCheck(FALSE);
      $old_usage_ids = $old_usage_query->execute();

      if ($old_usage_ids) {
        $old_usages = $usage_storage->loadMultiple($old_usage_ids);
        $direct_field_keys = [];
        foreach ($direct_file_usage as $ref) {
          $direct_field_keys[] = ...;
        }
        foreach ($old_usages as $old_usage) {
          $key = ...;
          if (!in_array($key, $direct_field_keys)) {
            $old_usage->delete();
          }
        }
      }
```

**Remove entirely.** The top-level `rawDeleteUsageByAssetId($asset_id)` already cleared all usage for this asset. The re-creation loop handles dedup via `$seen_usage`.

### 2f. Replace `updateCsvExportFields()` Call

Find at the end of `processManagedFile()`:

```php
    // Update CSV export fields.
    $this->updateCsvExportFields($asset_id, $file->filesize);
```

**Remove entirely.** The `filesize_formatted` field is now populated at insert time (in `rawInsertAssetItem()`). The `active_use_csv` and `used_in_csv` fields are populated by Phase 6 after all scanning phases complete.

### 2g. Replace Conditional Secondary Saves

The "reverse thumbnail check" section conditionally updates the item after the initial save:

```php
      if (!empty($thumbnail_media_ids)) {
        $first_mid = reset($thumbnail_media_ids);
        $item->set('source_type', 'media_managed');
        $item->set('media_id', $first_mid);
        $item->save();
```

Replace the `$item->set(...)` + `$item->save()` calls with:

```php
      if (!empty($thumbnail_media_ids)) {
        $first_mid = reset($thumbnail_media_ids);
        $this->rawUpdateAssetItem($asset_id, [
          'source_type' => 'media_managed',
          'media_id' => $first_mid,
        ]);
```

Apply the same pattern to the pdf_image_entity reverse detection section.

### 2h. Replace Thumbnail Usage Entity Saves

In the reverse thumbnail section, replace:

```php
          if (!$existing_usage_query->execute()) {
            $usage_storage->create([
              'asset_id' => $asset_id,
              'entity_type' => 'media',
              'entity_id' => $thumb_media->id(),
              'field_name' => 'thumbnail',
              'count' => 1,
              'embed_method' => 'derived_thumbnail',
            ])->save();
          }
```

With:

```php
          $this->bufferUsageRecord([
            'asset_id' => $asset_id,
            'entity_type' => 'media',
            'entity_id' => $thumb_media->id(),
            'field_name' => 'thumbnail',
            'embed_method' => 'derived_thumbnail',
          ]);
```

Remove the `$existing_usage_query` since we already deleted all usage for this asset.

### 2i. Remove `$storage` and `$usage_storage` Variables

After the refactoring, `processManagedFile()` no longer uses `$storage` or `$usage_storage` for item/usage writes (only `registerDerivedFileUsage()` still receives them as parameters).

Find at the top of `processManagedFile()`:

```php
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');
```

These are still needed because `registerDerivedFileUsage()` takes them as parameters. Do NOT remove yet. When `registerDerivedFileUsage()` is refactored in a future pass, they can be removed.

---

## Fix 3: Refactor `processOrphanFile()` to Use Raw SQL Writes

**File:** `src/Service/DigitalAssetScanner.php`

The same pattern as Fix 2, applied to `processOrphanFile()` (lines 4898–5006).

### 3a. Replace Item Insert/Update

Find:

```php
    if (isset($existing_temp_map[$url_hash])) {
      $asset = $existing_temp_map[$url_hash];
      $asset_id = $asset->id();
      $asset->set('filesize', $filesize);
      $asset->set('file_path', $absolute_url);
      $asset->set('is_private', $is_private);
      $asset->save();
    }
    else {
      $asset = $storage->create([
        'source_type' => 'filesystem_only',
        // ... fields ...
      ]);
      $asset->save();
      $asset_id = $asset->id();
    }
```

Replace with:

```php
    if (isset($existing_temp_map[$url_hash])) {
      $asset_id = (int) $existing_temp_map[$url_hash]->id();
      $this->rawUpdateAssetItem($asset_id, [
        'filesize' => $filesize,
        'filesize_formatted' => $this->formatFileSize($filesize),
        'file_path' => $absolute_url,
        'is_private' => $is_private ? 1 : 0,
      ]);
    }
    else {
      $asset_id = $this->rawInsertAssetItem([
        'source_type' => 'filesystem_only',
        'url_hash' => $url_hash,
        'asset_type' => $asset_type,
        'category' => $category,
        'sort_order' => $sort_order,
        'file_path' => $absolute_url,
        'file_name' => $filename,
        'mime_type' => $mime,
        'filesize' => $filesize,
        'is_temp' => $is_temp ? 1 : 0,
        'is_private' => $is_private ? 1 : 0,
      ]);
    }
```

### 3b. Replace Usage Record Deletion and Creation

Same pattern as Fix 2c/2d — replace entity query + delete with `rawDeleteUsageByAssetId()`, replace entity create + save with `bufferUsageRecord()`.

### 3c. Remove `updateCsvExportFields()` Call

Find at the end of `processOrphanFile()`:

```php
    $this->updateCsvExportFields($asset_id, $filesize);
```

**Remove entirely.** Handled by Phase 6.

### 3d. Update `scanOrphanFilesChunkNew()` to Flush Buffer

In `scanOrphanFilesChunkNew()`, add the buffer flush after the processing loop, before cache resets:

```php
    // ... end of while ($index < $total) loop ...

    $context['sandbox']['orphan_index'] = $index;

    // Flush buffered usage records in one bulk INSERT.
    $this->flushUsageBuffer();

    // Progress.
    // ... existing progress calculation ...
```

---

## Fix 4: Flush Usage Buffer in Phase 1

**File:** `src/Service/DigitalAssetScanner.php`

In `scanManagedFilesChunk()`, add the buffer flush after the `processWithTimeBudget()` call and before cache resets:

```php
    $itemsThisCallback = $this->processWithTimeBudget(
      $context,
      'last_fid',
      'total_files',
      fn() => $this->getManagedFilesCount(),
      fn(int $lastFid, int $limit) => $this->getManagedFileIdsAfter($lastFid, $limit),
      fn(array $ids) => $this->loadManagedFileRows($ids),
      fn(object $file) => $this->processManagedFile($file, $is_temp, $context),
    );

    // Flush buffered usage records in one bulk INSERT.
    $this->flushUsageBuffer();

    // FR-4: Accumulate orphan count...
```

**NOTE:** The buffer is flushed at the END of each callback, not after each item. This means all usage records for the ~43–200+ items processed in this callback are inserted in a single bulk query. On managed hosting, this turns 100–500 individual entity saves (~20–100s) into 1 bulk INSERT (~0.1–0.3s).

---

## Fix 5: Add Phase 6 — Bulk CSV Export Field Update

**File:** `src/Service/DigitalAssetScanner.php`

### 5a. Add `updateCsvExportFieldsBulk()` Method

This replaces the per-item `updateCsvExportFields()` calls removed in Fixes 2f and 3c. It processes ALL temp items in batches, computing the CSV export fields (`active_use_csv`, `used_in_csv`) using bulk queries.

```php
/**
 * Bulk-updates CSV export fields for all temp asset items.
 *
 * Processes items in cursor-based batches within the time budget.
 * Called as Phase 6 after all scanning phases complete.
 *
 * For each batch of items:
 * 1. Bulk-loads usage records for the batch
 * 2. Determines active_use_csv (Yes/No) from usage existence
 * 3. Loads parent entities to build used_in_csv ("Title (URL); ...")
 * 4. Bulk-UPDATEs the items via raw SQL
 *
 * @param array &$context
 *   Batch API context array.
 */
public function updateCsvExportFieldsBulk(array &$context): void {
  $budget = $this->getBatchTimeBudget();
  $startTime = microtime(true);
  $itemsThisCallback = 0;

  // Initialize on first call.
  if (!isset($context['sandbox']['csv_last_id'])) {
    $context['sandbox']['csv_last_id'] = 0;
    $context['sandbox']['csv_total'] = (int) $this->database
      ->select('digital_asset_item', 'dai')
      ->condition('is_temp', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
    $context['sandbox']['csv_processed'] = 0;
  }

  $lastId = $context['sandbox']['csv_last_id'];
  $total = $context['sandbox']['csv_total'];

  while ((microtime(true) - $startTime) < $budget) {
    // Fetch a batch of item IDs + filesize.
    $items = $this->database->select('digital_asset_item', 'dai')
      ->fields('dai', ['id', 'filesize'])
      ->condition('id', $lastId, '>')
      ->condition('is_temp', 1)
      ->orderBy('id', 'ASC')
      ->range(0, 50)
      ->execute()
      ->fetchAllAssoc('id');

    if (empty($items)) {
      $context['finished'] = 1;
      break;
    }

    $item_ids = array_keys($items);

    // Bulk-load ALL usage records for this batch of items.
    $usage_rows = $this->database->select('digital_asset_usage', 'dau')
      ->fields('dau', ['asset_id', 'entity_type', 'entity_id'])
      ->condition('asset_id', $item_ids, 'IN')
      ->execute()
      ->fetchAll();

    // Group usage by asset_id.
    $usage_by_asset = [];
    foreach ($usage_rows as $row) {
      $usage_by_asset[$row->asset_id][] = $row;
    }

    // Collect unique entity references for bulk loading.
    $entity_refs = [];
    foreach ($usage_by_asset as $usages) {
      foreach ($usages as $usage) {
        $key = $usage->entity_type . ':' . $usage->entity_id;
        if (!isset($entity_refs[$key])) {
          $entity_refs[$key] = [
            'type' => $usage->entity_type,
            'id' => $usage->entity_id,
          ];
        }
      }
    }

    // Bulk-load parent entities grouped by type.
    $entity_labels = [];
    $by_type = [];
    foreach ($entity_refs as $key => $ref) {
      $by_type[$ref['type']][] = $ref['id'];
    }
    foreach ($by_type as $entity_type => $ids) {
      try {
        if (!$this->entityTypeManager->hasDefinition($entity_type)) {
          continue;
        }
        $entities = $this->entityTypeManager->getStorage($entity_type)
          ->loadMultiple($ids);
        foreach ($entities as $entity) {
          $label = $entity->label();
          $url = '';
          try {
            if ($entity->hasLinkTemplate('canonical')) {
              $url = $entity->toUrl('canonical', ['absolute' => TRUE])
                ->toString();
            }
          }
          catch (\Exception $e) {
            // No canonical URL.
          }
          $entity_labels[$entity_type . ':' . $entity->id()] = [
            'label' => $label,
            'url' => $url,
          ];
        }
      }
      catch (\Exception $e) {
        // Skip entity types that fail to load.
      }
    }

    // Build CSV fields and update each item.
    foreach ($items as $item_id => $item) {
      $usages = $usage_by_asset[$item_id] ?? [];
      $active_use = !empty($usages) ? 'Yes' : 'No';

      $used_in_parts = [];
      foreach ($usages as $usage) {
        $key = $usage->entity_type . ':' . $usage->entity_id;
        if (isset($entity_labels[$key])) {
          $info = $entity_labels[$key];
          $used_in_parts[] = $info['url']
            ? $info['label'] . ' (' . $info['url'] . ')'
            : $info['label'];
        }
      }
      $used_in_parts = array_unique($used_in_parts);
      $used_in_csv = !empty($used_in_parts)
        ? implode('; ', $used_in_parts)
        : 'No active use detected';

      $this->database->update('digital_asset_item')
        ->fields([
          'active_use_csv' => $active_use,
          'used_in_csv' => $used_in_csv,
        ])
        ->condition('id', $item_id)
        ->execute();

      $lastId = $item_id;
      $context['sandbox']['csv_last_id'] = $lastId;
      $context['sandbox']['csv_processed']++;
      $itemsThisCallback++;
    }

    $this->maybeUpdateHeartbeat();
  }

  // Progress calculation.
  if ($total > 0) {
    $context['finished'] = $context['sandbox']['csv_processed'] / $total;
  }
  if ($context['finished'] >= 1) {
    $context['finished'] = 1;
  }

  // Cache resets.
  $this->resetPhaseEntityCaches(['node', 'paragraph', 'block_content',
    'taxonomy_term', 'media', 'menu_link_content']);

  $context['results']['last_chunk_items'] = $itemsThisCallback;
}
```

**Why this is faster than per-item `updateCsvExportFields()`:**

| Operation | Per-item (current) | Bulk Phase 6 |
|-----------|-------------------|--------------|
| Load asset entity | 1 entity load per item | Not needed (raw query) |
| Query usage records | 1 entity query per item | 1 bulk query per batch of 50 |
| Load parent entities | 1 entity load per usage record | Bulk loadMultiple grouped by type |
| Save asset entity | 1 entity save per item | 1 raw SQL UPDATE per item |
| **Total for 50 items** | **~15–25s** | **~0.5–1s** |

### 5b. Update `ScanAssetsForm.php` — Add Phase 6

**File:** `src/Form/ScanAssetsForm.php`

Update the `PHASE_MAP` constant:

```php
  const PHASE_MAP = [
    1 => ['method' => 'batchProcessManagedFiles', 'label' => 'Managed Files'],
    2 => ['method' => 'batchProcessOrphanFiles', 'label' => 'Orphan Files'],
    3 => ['method' => 'batchProcessContent', 'label' => 'Content (External URLs)'],
    4 => ['method' => 'batchProcessMediaEntities', 'label' => 'Remote Media'],
    5 => ['method' => 'batchProcessMenuLinks', 'label' => 'Menu Links'],
    6 => ['method' => 'batchProcessCsvFields', 'label' => 'CSV Export Fields'],
  ];
```

Update `buildBatch()` to include Phase 6:

```php
  protected function buildBatch(int $start_phase): array {
    $operations = [];
    for ($phase = $start_phase; $phase <= 6; $phase++) {
      $method = self::PHASE_MAP[$phase]['method'];
      $operations[] = [
        [static::class, $method],
        [$phase],
      ];
    }
    // ...
  }
```

Add the batch callback method:

```php
  /**
   * Batch operation: Update CSV export fields for all temp items.
   *
   * @param int $phase_number
   *   The phase number for checkpoint saving.
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessCsvFields(int $phase_number, array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $scanner->resetHeartbeatWriteCount();
    $callbackStartTime = microtime(true);
    $scanner->updateScanHeartbeat();

    $scanner->updateCsvExportFieldsBulk($context);

    if ($context['finished'] >= 1) {
      $scanner->saveCheckpoint($phase_number, TRUE);
    }

    $scanner->updateScanHeartbeat();

    $cursor = $context['sandbox']['csv_last_id'] ?? 'n/a';
    $items = $context['results']['last_chunk_items'] ?? 0;
    $scanner->logBatchTiming($phase_number, $items, $callbackStartTime, $cursor);
  }
```

### 5c. Update Checkpoint Logic for Phase 6

The existing checkpoint logic checks for `phase5_complete` as the final phase. Update to reference Phase 6 as the final phase.

In `ScanAssetsForm.php`, the `submitForm()` resume logic:

```php
    elseif ($action === self::ACTION_RESUME && $checkpoint) {
      $resume_from = $checkpoint['phase'];
      if ($resume_from === 6 && !$checkpoint['phase6_complete']) {
        $batch = $this->buildBatch(6);
      }
      else {
        $batch = $this->buildBatch($resume_from + 1);
      }
    }
```

In `DigitalAssetScanner::saveCheckpoint()`, verify it stores `phase6_complete` when Phase 6 completes.

### 5d. Remove `updateCsvExportFields()` Calls from ALL Scan Methods

Remove every `$this->updateCsvExportFields(...)` call from the scanning phases. All 18 call sites listed below must be removed:

| Line | Method | Context |
|------|--------|---------|
| 867 | `processManagedFile()` | End of Phase 1 processing |
| 1343 | `scanManagedFilesChunkLegacy()` | Legacy method (deprecated) |
| 2345 | `processContentRow()` | Phase 3 — external URLs |
| 2713 | `scanContentChunk()` | Legacy method (deprecated) |
| 3084 | `processLocalFileLink()` | Phase 3 — local file links |
| 3255 | `processExternalUrl()` | Phase 3 — external URLs |
| 3418 | `findOrCreateLocalAssetForHtml5()` | Phase 3 — HTML5 embeds |
| 3482 | `findOrCreateExternalAssetForHtml5()` | Phase 3 — HTML5 embeds |
| 3595 | `findOrCreateCaptionAsset()` | Phase 3 — caption files |
| 3656 | `findOrCreateExternalCaptionAsset()` | Phase 3 — caption files |
| 5005 | `processOrphanFile()` | Phase 2 — orphan files |
| 5309 | `scanOrphanFilesChunk()` | Legacy method (deprecated) |
| 5634 | `scanRemoteMediaChunk()` | Legacy method (deprecated) |
| 5854 | `processRemoteMedia()` | Phase 4 — remote media |
| 6419 | `registerDerivedFileUsage()` | Phase 1 — derived thumbnails |
| 6672 | `scanMenuLinksChunk()` | Legacy method (deprecated) |
| 6778 | `processMenuLink()` | Phase 5 — menu links |

**For the deprecated legacy methods** (lines 1343, 2713, 5309, 5634, 6672): Leave the calls in place. These methods are not used by the current batch pipeline. They only exist for backward compatibility and will be removed entirely in a future cleanup.

**For all active methods** (the remaining 12 call sites): Remove the call.

**Do NOT delete the `updateCsvExportFields()` method itself.** It is still needed by:
- Legacy deprecated methods (until removed)
- Potentially by non-scan code paths (archive operations, etc.)

Mark it as `@internal` with a note:

```php
/**
 * Updates CSV export fields for a single asset.
 *
 * @internal Only used by legacy deprecated scan methods and non-scan
 *   code paths. The primary scan pipeline uses updateCsvExportFieldsBulk()
 *   (Phase 6) instead.
 */
```

---

## Fix 6: Refactor `promoteTemporaryItems()` to Use Raw SQL

**File:** `src/Service/DigitalAssetScanner.php`

The current `promoteTemporaryItems()` loads every temp item entity and saves it individually to flip `is_temp = FALSE`. For 7,000 items, this takes ~5 minutes.

### 6a. Replace Entity-Based Promotion with Raw SQL

Find the block that loads and saves each temp item:

```php
    // Mark all temporary items as permanent.
    $query = $storage->getQuery();
    $query->condition('is_temp', TRUE);
    $query->accessCheck(FALSE);
    $ids = $query->execute();

    if ($ids) {
      $entities = $storage->loadMultiple($ids);
      foreach ($entities as $entity) {
        $entity->set('is_temp', FALSE);
        $entity->save();
      }
    }
```

Replace with:

```php
    // Mark all temporary items as permanent — single raw SQL UPDATE.
    $this->database->update('digital_asset_item')
      ->fields(['is_temp' => 0, 'changed' => \Drupal::time()->getRequestTime()])
      ->condition('is_temp', 1)
      ->execute();
```

### 6b. Replace Entity-Based Deletion of Old Items with Raw SQL

Find the blocks that delete old (non-temp) items, their usage records, and orphan references:

```php
    // Get IDs of old non-temporary items (to be deleted).
    $query = $storage->getQuery();
    $query->condition('is_temp', 0);
    $query->accessCheck(FALSE);
    $old_item_ids = $query->execute();

    if (!empty($old_item_ids)) {
      // Delete orphan references for old items first.
      // ... entity query + loadMultiple + delete ...

      // Delete usage records.
      // ... entity query + loadMultiple + delete ...

      // Delete the old items.
      $entities = $storage->loadMultiple($old_item_ids);
      $storage->delete($entities);
    }
```

Replace with:

```php
    // Delete old items and their dependent records — raw SQL in FK-safe order.
    // Step 1: Delete orphan references for old items.
    $this->database->query(
      'DELETE oref FROM {dai_orphan_reference} oref
       INNER JOIN {digital_asset_item} dai ON oref.asset_id = dai.id
       WHERE dai.is_temp = 0'
    );

    // Step 2: Delete usage records for old items.
    $this->database->query(
      'DELETE dau FROM {digital_asset_usage} dau
       INNER JOIN {digital_asset_item} dai ON dau.asset_id = dai.id
       WHERE dai.is_temp = 0'
    );

    // Step 3: Delete old items.
    $this->database->delete('digital_asset_item')
      ->condition('is_temp', 0)
      ->execute();
```

**NOTE:** The joined DELETE syntax (`DELETE t1 FROM t1 INNER JOIN t2...`) is MySQL/MariaDB-specific. If PostgreSQL support is needed, use subquery form:

```sql
DELETE FROM {dai_orphan_reference} WHERE asset_id IN (
  SELECT id FROM {digital_asset_item} WHERE is_temp = 0
)
```

Since Drupal sites on most managed hosting (Pantheon, Acquia, Platform.sh, etc.) use MySQL/MariaDB, the joined form is correct. For maximum portability, use subqueries via Drupal's query builder:

```php
    // Portable version using subquery:
    $old_ids_subquery = $this->database->select('digital_asset_item', 'dai')
      ->fields('dai', ['id'])
      ->condition('is_temp', 0);

    $this->database->delete('dai_orphan_reference')
      ->condition('asset_id', $old_ids_subquery, 'IN')
      ->execute();

    $this->database->delete('digital_asset_usage')
      ->condition('asset_id', $old_ids_subquery, 'IN')
      ->execute();

    $this->database->delete('digital_asset_item')
      ->condition('is_temp', 0)
      ->execute();
```

### 6c. Expected Impact

| Operation | Before | After |
|-----------|--------|-------|
| Delete ~7,000 old items + usage + orphan refs | ~3 min (entity load + delete) | ~1s (3 raw SQL DELETEs) |
| Promote ~7,000 temp items | ~5 min (entity load + save) | ~0.1s (1 raw SQL UPDATE) |
| **Total promotion** | **~8 min** | **~2s** |

---

## Fix 7: Remove `updateCsvExportFields()` from Content Phase Methods

**File:** `src/Service/DigitalAssetScanner.php`

Phase 3 (`scanContentChunkNew()`) already processes ~400+ rows per callback and completes in ~30 seconds. However, each call to `processContentRow()`, `processLocalFileLink()`, `processExternalUrl()`, and the HTML5 embed methods calls `updateCsvExportFields()`, which adds unnecessary overhead.

Removing these calls (as listed in Fix 5d) reduces Phase 3 time by ~50% and eliminates the possibility of `updateCsvExportFields()` producing stale data (it's called before all phases finish, so the usage records are incomplete at that point).

The same applies to Phases 4 and 5. Remove `updateCsvExportFields()` from `processRemoteMedia()` and `processMenuLink()`.

**No other changes to Phase 3–5 are needed.** They're already fast enough. The bulk write optimization is specifically for Phases 1, 2, and promotion — the bottlenecks.

---

## Implementation Order

All fixes must be deployed together as a single commit. Removing `updateCsvExportFields()` calls without adding Phase 6 would leave CSV fields empty. Adding Phase 6 without updating `ScanAssetsForm.php` would skip it.

```
Fix 1: Add raw SQL write methods + usage buffer
  ↓
Fix 2: Refactor processManagedFile() to use raw writes
  ↓
Fix 3: Refactor processOrphanFile() to use raw writes
  ↓
Fix 4: Flush usage buffer in scanManagedFilesChunk()
  ↓
Fix 5: Add Phase 6 (CSV fields) + update ScanAssetsForm
  ↓
Fix 6: Refactor promoteTemporaryItems() to use raw SQL
  ↓
Fix 7: Remove updateCsvExportFields() from Phase 3–5 methods
```

---

## Risk Assessment

### What raw SQL bypasses (and why it's safe here)

| Entity API feature | Impact of skipping | Mitigation |
|---|---|---|
| `hook_entity_presave` / `hook_entity_insert` | No modules implement hooks for `digital_asset_item` or `digital_asset_usage`. Verified by `grep -r 'hook_digital_asset' .` | None needed |
| Entity validation | Scanner controls all field values — no user input | Field values are computed from trusted sources (file_managed, file_usage) |
| UUID generation | Entities need UUIDs for Drupal's entity system | Generated explicitly via `\Drupal::service('uuid')->generate()` |
| Cache invalidation | Entity caches are invalidated on save | Temp items are never loaded by the render pipeline. Phase entity caches are reset per callback via `resetPhaseEntityCaches()` |
| `changed` timestamp | Tracks last modification time | Set explicitly in `rawInsertAssetItem()` and `rawUpdateAssetItem()` |
| Key-value store | Drupal stores entity info in key-value | Not used by these entity types (no bundles, no field storage config) |

### Rollback plan

If raw SQL writes cause data integrity issues (e.g., missing UUIDs, incorrect field values):

1. Delete all temp items: `drush sqlq "DELETE FROM digital_asset_item WHERE is_temp = 1"`
2. Delete orphaned usage/orphan refs: `drush sqlq "DELETE FROM digital_asset_usage WHERE asset_id NOT IN (SELECT id FROM digital_asset_item)"`
3. Clear checkpoint: `drush ev "\Drupal::service('digital_asset_inventory.scanner')->clearCheckpoint();"`
4. Revert to Entity API saves and re-scan

### Edge cases

1. **Resumed scans**: The raw SQL methods handle both INSERT (new items) and UPDATE (existing temp items from a previous callback). The existence check uses a lightweight `SELECT id` query instead of a full entity load.

2. **Concurrent requests**: The scan lock prevents concurrent scans. Within a single callback, the usage buffer is private to the PHP process — no concurrency issues.

3. **Large batches**: The `flushUsageBuffer()` method inserts all buffered records in one query. For a callback processing 200 files with 3 usage records each, that's 600 VALUES in one INSERT — well within MySQL's `max_allowed_packet` (default 64MB, each record is ~200 bytes = ~120KB total).

4. **DB transaction safety**: Drupal's Batch API does NOT wrap callbacks in transactions. This is the same as the current Entity API behavior — a crash mid-callback leaves partial data. The checkpoint/cursor system handles this by re-processing from the last cursor on resume.

5. **`registerDerivedFileUsage()` still uses Entity API**: This method is called infrequently (~100–500 times per scan for thumbnail/PDF image detection) and has complex read logic (loading file entities, checking system icons). Refactoring it to raw SQL is low-priority. It calls `updateCsvExportFields()` — remove that call (Fix 5d) but keep the entity saves for the derived items themselves.

---

## Verification

### Code Verification

Before deploying, check:

1. **No `$storage->create(` in `processManagedFile()`**: Search the method body for `$storage->create(` — should not appear. Item creation uses `rawInsertAssetItem()`.

2. **No `$usage_storage->create(` in `processManagedFile()`**: Search for `$usage_storage->create(` — should not appear. Usage creation uses `bufferUsageRecord()`.

3. **No `->save()` in `processManagedFile()` for items**: The only `->save()` calls should be inside `registerDerivedFileUsage()` (kept as-is). No `$item->save()` calls.

4. **`flushUsageBuffer()` called in both Phase 1 and Phase 2**: In `scanManagedFilesChunk()` and `scanOrphanFilesChunkNew()`, verify `$this->flushUsageBuffer()` appears after the processing loop.

5. **`updateCsvExportFields()` removed from 12 active call sites**: Search for `updateCsvExportFields(` — should appear only in the method definition, the deprecated legacy methods, and `registerDerivedFileUsage()` (if not yet removed there).

6. **Phase 6 in PHASE_MAP**: `ScanAssetsForm::PHASE_MAP` should have 6 entries. `buildBatch()` should loop to `<= 6`.

7. **Phase 6 batch callback exists**: `batchProcessCsvFields()` static method in `ScanAssetsForm`.

8. **`promoteTemporaryItems()` uses raw SQL**: No `$entity->save()` loops. Three DELETE queries + one UPDATE query.

9. **UUID generation in all raw INSERT methods**: Every `rawInsertAssetItem()`, `flushUsageBuffer()`, and `rawInsertOrphanReference()` call must include `'uuid' => \Drupal::service('uuid')->generate()`.

10. **`filesize_formatted` populated at insert time**: In `rawInsertAssetItem()`, verify `$fields['filesize_formatted']` is set via `$this->formatFileSize()`.

11. **`processOrphanFile()` uses raw writes**: Same checks as #1–3 but for `processOrphanFile()`.

12. **Checkpoint phase 6 handling**: `saveCheckpoint()` must handle phase 6. Resume logic in `submitForm()` must check for phase 6 completion.

### Automated Tests

```bash
cd web && SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  --group digital_asset_inventory \
  modules/custom/digital_asset_inventory/tests/src
```

The kernel tests (`ScannerAtomicSwapKernelTest`, etc.) exercise the scan-then-promote flow. After this change, they validate that:

- Items are created correctly via raw SQL
- Usage records are created correctly via bulk INSERT
- Promotion correctly flips `is_temp` via raw SQL
- CSV export fields are populated after Phase 6

**NOTE:** Some kernel tests may call `processManagedFile()` directly and then check entity properties via Entity API. These tests still work because the raw SQL writes hit the same database tables — Entity API reads (`$storage->load()`) will see the data written by raw SQL. The entity static cache may be stale; add `$storage->resetCache()` before entity loads in tests if needed.

### Production Testing

After deploying to a staging/multidev environment:

```bash
# Clear old scan state (adjust drush alias for your hosting)
drush ev "\Drupal::service('lock.persistent')->release('digital_asset_inventory_scan');"
drush ev "\Drupal::service('digital_asset_inventory.scanner')->clearCheckpoint();"
drush sqlq "DELETE FROM digital_asset_item WHERE is_temp = 1"
drush cr
```

Run the scan, then check logs:

```bash
drush ws --count=30 --type=digital_asset_inventory
```

**Expected results (updated with production measurements):**

| Metric | Before | Expected | Measured (Site 1) |
|--------|--------|----------|-----------------|
| Phase 1 items per callback | ~43 | ~200–400 | ~48-65 (reads dominate) |
| Phase 1 per-item time | ~0.23s | ~0.03–0.05s | ~0.15-0.2s (read-bound) |
| Phase 1 total time | ~28 min | ~5 min | ~20 min |
| Phase 2 orphans per callback | ~10 | ~100–200 | ~4-12 (LIKE-bound) |
| Phase 2 total time | ~27 min | ~2 min | ~27 min (est) |
| Phase 6 items per callback | N/A | ~200–500 | TBD |
| Phase 6 total time | N/A | ~3 min | TBD |
| Promotion time | ~5 min | ~5 sec | ~2s ✅ |
| **Full scan total** | **~62 min** | **~12 min** | **~50 min (est)** |

**Why the estimates were wrong:** Entity API write overhead on managed hosting is ~0.03s/save (not ~0.1s as originally measured), because Drupal's entity caching layer absorbs most of the cost. The per-item bottleneck is **read queries** — `file_usage` lookups (~0.02s), `findMediaUsageViaEntityQuery()` (~0.08-0.12s), paragraph parent resolution (~0.03s), and LIKE text searches (~2.5s/orphan). See Appendix C for the next optimization plan targeting reads.

**Diagnostic: If Phase 1 is still slow (~43-65 items/callback):**

This is expected with the current read-bound architecture. The write optimizations ARE working (confirmed by zero `->save()` calls in `processManagedFile()`), but the reads consume ~80% of per-item time. The next fix is bulk reads (Appendix C.1): pre-query `file_usage`, media references, and paragraph parents for 100-item batches.

```bash
# Confirm entity saves are eliminated in processManagedFile
grep -A 500 'function processManagedFile' src/Service/DigitalAssetScanner.php | grep -c '->save()'
# Expected: 0 (in the main method body) — registerDerivedFileUsage saves are in a separate method
```

**Diagnostic: If Phase 2 is slow (~4 items/callback):**

Check that `usage_batch_end` and `orphan_usage_map` are persisted in `$context['sandbox']` across callbacks. Without persistence, the batch LIKE query refills on every callback (costing ~2.5s/callback for only 4 items). With persistence, it refills every 50 items.

```bash
# Check batch LIKE refill pattern in logs — look for callbacks with 10+ items (good) vs 4 items (bad)
drush ws --count=20 --type=digital_asset_inventory | grep 'Phase: 2'
```

**Diagnostic: If CSV fields are empty after scan:**

Verify Phase 6 ran. Check logs for `Phase: 6` entries. If missing, verify `PHASE_MAP` has 6 entries and `buildBatch()` loops to `<= 6`.

```bash
drush sqlq "SELECT active_use_csv, COUNT(*) as cnt FROM digital_asset_item WHERE is_temp = 0 GROUP BY active_use_csv"
# Expected: rows for 'Yes' and 'No' (not empty string)
```

---

## Appendix A: Per-Item Cost Breakdown (Before vs After)

### Phase 1: `processManagedFile()` — typical file with 1 media association, 2 usage records

**Before (Entity API):**

| Step | Operation | DB ops | Time |
|------|-----------|--------|------|
| 1 | file_usage query for media | 1 SELECT | 0.03s |
| 2 | Entity query for existing temp item | 1 SELECT (via entity query) | 0.05s |
| 3 | `$item->save()` | 1 INSERT + hooks + cache | 0.10s |
| 4 | Entity query for old usage records | 1 SELECT | 0.05s |
| 5 | Load + delete old usage entities | 2 SELECT + 2 DELETE | 0.10s |
| 6 | `findDirectFileUsage()` | 1 SELECT | 0.03s |
| 7 | `findFileFieldName()` — loads entity | 1 SELECT + field iteration | 0.05s |
| 8 | Entity query for existing usage (dedup) | 1 SELECT | 0.03s |
| 9 | `$usage->save()` per record × 2 | 2 INSERT + hooks + cache | 0.20s |
| 10 | `findMediaUsageViaEntityQuery()` | 2–4 SELECT | 0.10s |
| 11 | `updateCsvExportFields()` | 3 SELECT + 1 UPDATE + hooks | 0.35s |
| | **Total** | **~18 DB ops** | **~1.09s** |

**After (Raw SQL + buffered writes):**

| Step | Operation | DB ops | Time |
|------|-----------|--------|------|
| 1 | file_usage query for media | 1 SELECT | 0.03s |
| 2 | Raw SELECT for existing temp item | 1 SELECT | 0.02s |
| 3 | `rawInsertAssetItem()` | 1 INSERT | 0.01s |
| 4 | `rawDeleteUsageByAssetId()` | 1 DELETE | 0.01s |
| 5 | `findDirectFileUsage()` | 1 SELECT | 0.03s |
| 6 | `findFileFieldName()` — loads entity | 1 SELECT + field iteration | 0.05s |
| 7 | `bufferUsageRecord()` × 2 | 0 (in-memory) | 0.00s |
| 8 | `findMediaUsageViaEntityQuery()` | 2–4 SELECT | 0.10s |
| 9 | (no updateCsvExportFields) | 0 | 0.00s |
| | **Total** | **~8 DB ops** | **~0.25s** |

**Plus amortized bulk flush at callback end:**

- 200 items × ~3 usage records = ~600 records
- 1 bulk INSERT: ~0.1s
- Per-item amortized: ~0.0005s

**Estimated net per-item time: ~0.25s → ~4× faster than before.**

**Measured on managed hosting (Site 1, 7,485 assets):** ~0.15-0.2s/item → ~1.3-1.5× faster. The Entity API write overhead was only ~0.03-0.05s/save (not ~0.10s as originally profiled), so eliminating writes saved less than expected. The read queries dominate at ~0.15s/item regardless of write method:

| Read query | Measured time | Notes |
|------------|--------------|-------|
| `findMediaUsageViaEntityQuery()` | ~0.08-0.12s | Entity reference field queries per media ID |
| `findDirectFileUsage()` + `file_usage` | ~0.03s | Already fast individually |
| `getParentFromParagraph()` | ~0.03s | Cached within callback |
| Thumbnail/PDF checks | ~0.05s | Conditional |
| **Total reads** | **~0.15-0.20s** | **Dominates per-item cost** |

These reads are the **next** optimization target — see Appendix C.1 for the bulk reads plan.

### Phase 2: `processOrphanFile()` — typical orphan with 1 text link usage

**Before:** ~1.0s/item → ~10 items/callback
**Estimated:** ~0.15s/item → ~65 items/callback
**Measured on managed hosting (Site 1, 1,636 orphans):** ~2.5s/item → ~4 items/callback (without sandbox persistence fix), ~0.8-1.0s/item → ~10-12 items/callback (with fix). The batch LIKE query (`findLocalFileLinkUsageBatch(50)`) costs ~2.5s per refill and must be persisted in sandbox to amortize across callbacks. See Appendix C.2 for the single-pass broad LIKE optimization that would eliminate this cost.

---

## Appendix B: Why Not Bulk INSERT for Items Too?

The item INSERT could theoretically be batched like usage records (collect in buffer, flush at callback end). However, this creates a dependency problem:

1. `processManagedFile()` creates an item → needs `$asset_id` immediately
2. Uses `$asset_id` to create usage records
3. Uses `$asset_id` for orphan references
4. Uses `$asset_id` in `registerDerivedFileUsage()`

Without the auto-generated ID, we'd need either:
- **Placeholder IDs** mapped after flush (complex, error-prone)
- **Pre-generated IDs** from a sequence (not standard in MySQL auto-increment)
- **Two-pass processing** (collect all data, flush items, map IDs, flush usage)

The single raw SQL INSERT per item (~0.01s) vs Entity API save (~0.10s) already delivers a 10× speedup. Batching items would save an additional ~0.005s/item — not worth the architectural complexity.

The **usage buffer** is where batching delivers the biggest win, because usage records don't have downstream dependencies within the same callback.

---

## Appendix C: Future Optimization Targets

After deploying this fix, the remaining bottlenecks in order of impact:

### C.1 Phase 1 — Bulk Reads (highest impact, ~20 min → ~5-8 min)

Phase 1 processes ~48-65 items per 10s callback on managed hosting. Removing `updateCsvExportFields()` had **zero measurable impact** — the reads dominate at ~0.15-0.2s per item. The per-item read queries:

| Query | Time | Calls/item |
|-------|------|------------|
| `file_usage` lookup | ~0.02s | 1 |
| `findDirectFileUsage()` | ~0.02s | 1 |
| `findMediaUsageViaEntityQuery()` | ~0.08-0.12s | 1 per media ID |
| `getParentFromParagraph()` | ~0.03s | 1 per paragraph ref |
| Thumbnail/PDF entity queries | ~0.05s | conditional |

**Recommendation:** Pre-query `file_usage`, media references, and paragraph parents in bulk for the entire 100-item batch loaded by `processWithTimeBudget()`, then pass pre-fetched data to `processManagedFile()`. This eliminates ~300-500 individual queries per callback.

### C.2 Phase 2 — Single-Pass Broad LIKE (high impact, ~27 min → ~5 min)

Phase 2 orphan files require `findLocalFileLinkUsage()` to detect hardcoded `<a href>` references in CKEditor text fields. These are files uploaded via FTP/SFTP (not through Drupal UI), so they have no `file_managed` entry — text body LIKE search is the **only** way to detect usage.

**Current approach:** `findLocalFileLinkUsageBatch(50)` queries 113 text-field tables with ~100 OR LIKE conditions per table, refilling every 50 orphans. On a site with 1,636 orphans this requires ~33 refills × 113 queries = ~3,700 table scans total.

**Recommended approach — single broad pass:** Query each text-field table **once** with `WHERE value LIKE '%/files/%'`, fetching `entity_id` + `value` for all rows that reference any file. Cache the results, then match against all 1,636 orphan paths in PHP:

```php
// Run ONCE at start of Phase 2 (or first callback):
$file_references = []; // table → [entity_id → text_value]
foreach ($this->getTextFieldTables() as $t) {
  $rows = $this->database->select($t['table'], 'tbl')
    ->fields('tbl', ['entity_id', $t['value_column']])
    ->condition($t['value_column'], '%/files/%', 'LIKE')
    ->execute()->fetchAll();
  foreach ($rows as $row) {
    $file_references[$t['table']][$row->entity_id] = $row->{$t['value_column']};
  }
}
// Then for each orphan, match needles against cached text values in PHP.
```

**Cost:** 113 queries (fixed, regardless of orphan count) + PHP `stripos()` matching. The cached text values may be 1-10MB depending on content volume, but well within PHP memory limits.

**Impact:** Reduces Phase 2 LIKE query cost from O(tables × orphan_batches) to O(tables). For Site 1: 3,700 table scans → 113 table scans.

### C.3 Phase 1 — Read Query Details

1. **`findMediaUsageViaEntityQuery()` — ~0.1s per file with media**: Queries entity reference fields individually per media ID. Could be bulk-queried for a batch of media IDs using raw SQL against entity reference field tables.

2. **`findFileFieldName()` — ~0.05s per file with file_usage records**: Loads the parent entity and iterates fields to find which field references the file. Could use raw DB queries against field data tables.

3. **`findDirectFileUsage()` — 1 query per file**: Already fast individually, but could be bulk-queried for a batch of fids using `WHERE fid IN (...)`.

4. **Paragraph parent lookups — already cached**: The `$paragraphParentCache` handles repeat lookups within a callback. Cross-callback caching could help but is complex (stale data risk).

### C.4 Phase 6 Entity Loading

The bulk `loadMultiple()` per entity type in `updateCsvExportFieldsBulk()` is already efficient. Further optimization (raw DB queries for titles/URLs) would bypass entity access checks and URL generation.

### C.5 Production Timing Baseline (Site 1, Feb 27 2026)

Measured with bulk write + Phase 6 + batch LIKE fixes deployed:

| Phase | Items | Duration | Items/callback | Notes |
|-------|-------|----------|----------------|-------|
| Phase 1 – Managed files | 5,527 | ~20 min | ~48-65 | Read-bound (0.15-0.2s/item) |
| Phase 2 – Orphan files | 1,636 | ~27 min (est) | ~4-12 | LIKE-query-bound |
| Phase 3 – Content | 38,315 rows | ~30 sec | ~400+ | Already fast |
| Phase 4 – Remote media | 17 | ~14 sec | all | Already fast |
| Phase 5 – Menu links | 196 | ~1 sec | all | Already fast |
| Phase 6 – CSV fields | ~7,485 | TBD | TBD | New phase |
| **Total** | | **~53 min** | | vs 53 min pre-optimization |

**Key insight:** Write optimizations had minimal impact on managed hosting because Drupal's Entity API caching makes writes faster than expected (~0.03s/save vs ~0.1s originally measured). The bottleneck is definitively **reads** — per-item entity queries and LIKE text searches. The next optimization round should target C.1 and C.2 above.
