# Bulk Reads Performance Fix — Implementation Plan

**Status:** Fix 1–3 deployed, Fix 4–5 planned
**Depends on:** [scan-bulk-write-spec.md](scan-bulk-write-spec.md) (deployed)
**File:** `src/Service/DigitalAssetScanner.php`

## Context

After three rounds of optimization (loadMultiple, raw SQL writes, deferred CSV),
Phase 1 throughput on a production site (7,486 assets, managed hosting with ~59s request timeout) was stuck at **~48–65 items per 10s callback**
(~0.15–0.21s/item). The bottleneck was definitively **per-item read queries**.

Entity API writes on managed hosting cost ~0.03s/save — far less than originally profiled.
The bulk write changes saved ~0.02s/item but per-item reads consumed ~0.15–0.20s
unchanged. Phase 2's LIKE queries were O(orphans × tables) — 3,700+ table scans
for 1,636 orphans even with the current batch-50 approach.

### Evidence timeline

| Optimization | Phase 1 items/cb | Phase 2 (orphan) items/cb | Total scan time |
|---|---|---|---|
| Baseline (no optimizations) | ~43 | ~10 | ~54 min |
| loadMultiple (bulk entity reads) | ~43 | ~10 | ~54 min |
| + Raw SQL writes + deferred CSV | ~48 | ~4 (regression¹) | ~50 min (est) |
| + Orphan sandbox persistence fix | ~48–65 | ~10–12 | ~45 min (est) |
| **+ Fix 1–3 (bulk reads)** | **100** | **~23** | **15m 33s** |

¹ Sandbox persistence bug — `usage_batch_end` reset every callback. Fixed.

### Production results (Fix 1–3 deployed)

Site 1: 7,486 assets (5,505 local, 1,636 orphans, 345 external), 30,834 usage records.

| Phase | What | Duration | Items/cb | Per-item |
|---|---|---|---|---|
| 1 – Managed Files | 5,527 files | **~2 min** | 100 | 0.02s |
| 2 – Orphan Usage Index | 107 tables | **3s** | 107 | — |
| **3 – Orphan Files** | **1,636 orphans** | **~12 min** | **23** | **0.43s** |
| 4 – Content | 38,315 rows | ~24s | 12,700 | — |
| 5 – Remote Media | 16 | ~14s | 8 | — |
| 6 – Menu Links | 196 | <1s | 98 | — |
| 7 – CSV Export Fields | 7,486 | ~26s | 2,500 | — |
| **Total** | | **15m 33s** | | |

**Phase 3 (orphan files) is now 77% of total scan time.** The LIKE queries
are gone (moved to Phase 2 index build), but per-orphan Entity API calls
remain: EntityQuery + `loadMultiple()` for existing temp items, and
`getParentFromParagraph()` entity loads for paragraph parent resolution.

### Per-item read breakdown in `processManagedFile()` (lines 714–1012)

| # | Read operation | Method / line | Queries | Time | Batchable? |
|---|---|---|---|---|---|
| 1 | `file_usage` — find media using this file | inline ~730 | 1 SELECT | ~0.03s | **Yes** |
| 2 | Existing temp item check by fid | inline ~773 | 1 SELECT | ~0.02s | **Yes** |
| 3 | `findDirectFileUsage()` — non-media file_usage | line 4456 | 1 SELECT | ~0.02s | **Yes** (same table as #1) |
| 4 | `findFileFieldName()` — entity load + field scan | line 4514, called from #3 | 1 entity load | ~0.05s | **Yes** |
| 5 | `findMediaUsageViaEntityQuery()` — entity ref fields | line 3927, Part 1 | 2–8 EntityQueries | ~0.05s | **Yes** |
| 6 | `findMediaUsageViaEntityQuery()` — CKEditor `<drupal-media>` LIKE | line 3927, Part 2 | 5–15 LIKE queries + entity loads | ~0.03s | **Yes** |
| 7 | Media entity load (get UUID for #6) | line 3936 | 1 `load()` | ~0.01s | **Yes** |
| 8 | Paragraph parent lookup | `getParentFromParagraph()` line 4627 | 1 entity load | ~0.03s | Cached² |

² `$paragraphParentCache` handles repeats within a callback. Resets between callbacks.

**Total per item: ~0.15–0.21s, ~8–20 queries.** With 100 items per batch loaded
by `processWithTimeBudget()`, that's 800–2,000 individual queries per callback.

---

## Fix 1: Bulk Pre-Query All Read Data for Phase 1

### Strategy

Replace per-item queries with per-batch bulk queries. The key constraint is that
`scanManagedFilesChunk()` delegates to `processWithTimeBudget()` (line 347),
which internally fetches IDs, calls `loadFn`, and iterates items. Pre-queries
must happen **inside** `processWithTimeBudget()` between the `loadFn` call and
the per-item loop, via a new optional `preloadFn` callback.

### 1a. Add `preloadFn` parameter to `processWithTimeBudget()`

The method currently accepts `countFn`, `queryFn`, `loadFn`, `processFn`.
Add an optional `preloadFn` that receives the loaded entities and returns
pre-queried data passed to each `processFn` call.

**Current signature** (line 347):

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
    ?callable $preloadFn = NULL,
): int {
```

**Insert after the `$entities = ($loadFn)($ids);` call (line ~389):**

```php
    // Run batch pre-queries if provided.
    // $preloaded is passed as second argument to processFn.
    $preloaded = $preloadFn ? ($preloadFn)($entities) : NULL;
```

**Update the `processFn` call (line ~400):**

```php
      ($processFn)($entities[$id], $preloaded);
```

This is backward-compatible: existing callers pass a `processFn` that ignores
the second argument (PHP allows extra arguments to closures).

### 1b. Add `preloadManagedFileBatch()` method

This method runs all bulk pre-queries for a batch of file rows. Called once
per callback (~100 items), replaces ~800–2,000 individual queries with ~15–25
bulk queries.

```php
/**
 * Pre-queries read data for a batch of managed files.
 *
 * Called once per callback by processWithTimeBudget() via preloadFn.
 * Returns a keyed array of lookup maps that processManagedFile() uses
 * instead of per-item queries.
 *
 * @param array $file_rows
 *   Keyed by fid => stdClass file row from loadManagedFileRows().
 *
 * @return array
 *   Associative array with keys:
 *   - 'file_usage': fid => [file_usage rows]
 *   - 'existing_temp': fid => digital_asset_item.id (or missing)
 *   - 'media_entity_refs': media_id => [['entity_type', 'entity_id', 'field_name', 'bundle']]
 *   - 'media_embed_refs': media_uuid => [['entity_type', 'entity_id', 'field_name']]
 *   - 'file_field_refs': fid => [['entity_type', 'entity_id', 'field_name', 'bundle']]
 *   - 'media_uuids': media_id => uuid string
 */
protected function preloadManagedFileBatch(array $file_rows): array {
  $fids = array_keys($file_rows);
  if (empty($fids)) {
    return [];
  }

  $result = [
    'file_usage' => $this->bulkQueryFileUsage($fids),
    'existing_temp' => $this->bulkQueryExistingTempItems($fids),
    'file_field_refs' => [],
    'media_entity_refs' => [],
    'media_embed_refs' => [],
    'media_uuids' => [],
  ];

  // Extract media IDs from file_usage (type = 'media').
  $all_media_ids = [];
  foreach ($result['file_usage'] as $fid => $usages) {
    foreach ($usages as $row) {
      if ($row->type === 'media') {
        $all_media_ids[] = (int) $row->id;
      }
    }
  }
  $all_media_ids = array_unique($all_media_ids);

  // Bulk pre-query media entity references and CKEditor embeds.
  if (!empty($all_media_ids)) {
    $result['media_entity_refs'] = $this->bulkQueryMediaEntityRefs($all_media_ids);
    $result['media_uuids'] = $this->bulkQueryMediaUuids($all_media_ids);
    $result['media_embed_refs'] = $this->bulkQueryMediaEmbedRefs($result['media_uuids']);
  }

  // Bulk pre-query file/image field references (for findFileFieldName replacement).
  $result['file_field_refs'] = $this->bulkQueryFileFieldRefs($fids);

  return $result;
}
```

### 1c. `bulkQueryFileUsage()` — replaces per-item `file_usage` queries

Currently `processManagedFile()` queries `file_usage` at line ~730 (media check)
and `findDirectFileUsage()` at line 4462 (non-media check). Both hit the same
table with different `type` filters. One bulk query covers both.

```php
/**
 * Bulk-queries file_usage for a batch of fids.
 *
 * Returns ALL usage rows (media, file, node, etc.) — callers filter in PHP.
 * Replaces: per-item SELECT on file_usage in processManagedFile() (line ~730)
 *           and findDirectFileUsage() (line 4462).
 *
 * @param array $fids
 *   Array of file IDs.
 *
 * @return array
 *   Keyed by fid => array of stdClass rows (id, type, module, count).
 */
protected function bulkQueryFileUsage(array $fids): array {
  $map = [];
  if (empty($fids)) {
    return $map;
  }

  $rows = $this->database->select('file_usage', 'fu')
    ->fields('fu', ['fid', 'id', 'type', 'module', 'count'])
    ->condition('fid', $fids, 'IN')
    ->execute()
    ->fetchAll();

  foreach ($rows as $row) {
    $map[$row->fid][] = $row;
  }

  return $map;
}
```

**Cost:** 1 query for 100 fids (~0.03s) replaces 200 individual queries (~6s).

### 1d. `bulkQueryExistingTempItems()` — replaces per-item temp check

Currently `processManagedFile()` queries `digital_asset_item` at line ~773.

```php
/**
 * Bulk-queries existing temp items by fid.
 *
 * Replaces: per-item SELECT on digital_asset_item in processManagedFile() (line ~773).
 *
 * @param array $fids
 *   Array of file IDs.
 *
 * @return array
 *   Keyed by fid => digital_asset_item.id.
 */
protected function bulkQueryExistingTempItems(array $fids): array {
  if (empty($fids)) {
    return [];
  }

  return $this->database->select('digital_asset_item', 'dai')
    ->fields('dai', ['fid', 'id'])
    ->condition('fid', $fids, 'IN')
    ->condition('is_temp', 1)
    ->execute()
    ->fetchAllKeyed();  // fid => id
}
```

**Cost:** 1 query (~0.02s) replaces 100 queries (~2s).

### 1e. `bulkQueryFileFieldRefs()` — replaces `findFileFieldName()` entity loads

`findDirectFileUsage()` (line 4456) queries `file_usage` to find which entities
reference a file, then calls `findFileFieldName()` (line 4514) which **loads the
parent entity and iterates field definitions** to find the exact field name.

This entity load is the expensive part (~0.05s each). Replace with direct queries
on field data tables — file/image fields store `target_id` (the fid) in
`{entity_type}__{field_name}` tables.

```php
/**
 * Cached list of file/image field data tables.
 *
 * Built once per scan by getFileFieldTables().
 *
 * @var array[]|null
 */
private ?array $fileFieldTableCache = NULL;

/**
 * Returns all file/image field data tables on the site.
 *
 * Cached for the scan duration. Uses Schema::findTables() + schema check
 * (same pattern as getTextFieldTables()). Cross-database compatible (D10/D11).
 *
 * @return array[]
 *   Array of ['table', 'column', 'entity_type', 'field_name'].
 */
protected function getFileFieldTables(): array {
  if ($this->fileFieldTableCache !== NULL) {
    return $this->fileFieldTableCache;
  }

  $this->fileFieldTableCache = [];
  $db_schema = $this->database->schema();

  $prefixes = [
    'node__' => 'node',
    'taxonomy_term__' => 'taxonomy_term',
    'block_content__' => 'block_content',
  ];
  if ($this->moduleHandler->moduleExists('paragraphs')) {
    $prefixes['paragraph__'] = 'paragraph';
  }

  foreach ($prefixes as $prefix => $entity_type) {
    // Cross-database compatible (D10/D11).
    $tables = $db_schema->findTables($prefix . '%');

    foreach ($tables as $table) {
      $field_name = substr($table, strlen($prefix));
      // File/image fields use {field_name}_target_id column.
      $target_col = $field_name . '_target_id';

      if ($db_schema->fieldExists($table, $target_col)) {
        // Distinguish file/image fields from entity_reference fields:
        // file/image fields also have {field_name}_display or {field_name}_alt.
        $is_file_field = $db_schema->fieldExists($table, $field_name . '_display')
          || $db_schema->fieldExists($table, $field_name . '_alt');

        if ($is_file_field) {
          $this->fileFieldTableCache[] = [
            'table' => $table,
            'column' => $target_col,
            'entity_type' => $entity_type,
            'field_name' => $field_name,
          ];
        }
      }
    }
  }

  return $this->fileFieldTableCache;
}

/**
 * Bulk-queries file/image field tables for a batch of fids.
 *
 * For each file/image field table, runs one SELECT ... WHERE target_id IN (...).
 * Returns which entity+field references each fid. This replaces the per-item
 * entity load in findFileFieldName() (line 4514).
 *
 * IMPORTANT: This also serves as a current-revision filter. The file_usage
 * table may contain stale entries from old revisions. If a fid appears in
 * file_usage but NOT in any field data table, the usage is stale and must
 * be skipped. The field data tables ({entity_type}__{field_name}) only
 * contain the current/default revision data.
 *
 * @param array $fids
 *   Array of file IDs.
 *
 * @return array
 *   Keyed by fid => [['entity_type', 'entity_id', 'field_name', 'bundle']].
 *   A fid missing from the map means no current-revision field references it.
 */
protected function bulkQueryFileFieldRefs(array $fids): array {
  $map = [];
  if (empty($fids)) {
    return $map;
  }

  foreach ($this->getFileFieldTables() as $field_info) {
    try {
      $rows = $this->database->select($field_info['table'], 'f')
        ->fields('f', ['entity_id', $field_info['column'], 'bundle'])
        ->condition($field_info['column'], $fids, 'IN')
        ->execute()
        ->fetchAll();

      foreach ($rows as $row) {
        $fid = $row->{$field_info['column']};
        $map[$fid][] = [
          'entity_type' => $field_info['entity_type'],
          'entity_id' => (int) $row->entity_id,
          'field_name' => $field_info['field_name'],
          'bundle' => $row->bundle,
        ];
      }
    }
    catch (\Exception $e) {
      continue;
    }
  }

  return $map;
}
```

**Cost:** ~10–20 queries for all file/image field tables (~0.3s total) replaces
~100 entity loads (~5s).

**Behavioral note:** `findFileFieldName()` returns `'direct_file'` when the fid
is not found in the entity's current fields, and `findDirectFileUsage()` skips
that record (line 4483: `if ($field_name === 'direct_file') { continue; }`).
The bulk approach replicates this: if a fid from `file_usage` has no entry in
`$file_field_refs[$fid]`, the usage came from a stale revision and is skipped.

### 1f. `bulkQueryMediaEntityRefs()` — replaces entity reference queries in `findMediaUsageViaEntityQuery()` Part 1

`findMediaUsageViaEntityQuery()` (line 3927) does TWO expensive things per media ID:

**Part 1 (entity reference fields, lines 3940–3992):**
For each entity type, it loads `field_storage_config` to find media-targeting
entity_reference fields, then runs an EntityQuery per field per media ID.
On a typical site with ~15 media reference fields across node/paragraph/block,
this is 15 EntityQueries per media ID × multiple media IDs per callback.

```php
/**
 * Cached list of entity reference field tables targeting media.
 *
 * Built once per scan by getMediaReferenceFieldTables().
 *
 * @var array[]|null
 */
private ?array $mediaRefFieldTableCache = NULL;

/**
 * Returns all entity reference field tables that target media entities.
 *
 * Uses Schema::findTables() + schema check (D10/D11 compatible). Determines media targeting by checking
 * if the field_storage_config for the field has target_type = 'media'.
 *
 * @return array[]
 *   Array of ['table', 'column', 'entity_type', 'field_name'].
 */
protected function getMediaReferenceFieldTables(): array {
  if ($this->mediaRefFieldTableCache !== NULL) {
    return $this->mediaRefFieldTableCache;
  }

  $this->mediaRefFieldTableCache = [];
  $db_schema = $this->database->schema();

  $prefixes = [
    'node__' => 'node',
    'taxonomy_term__' => 'taxonomy_term',
    'block_content__' => 'block_content',
  ];
  if ($this->moduleHandler->moduleExists('paragraphs')) {
    $prefixes['paragraph__'] = 'paragraph';
  }

  // Load all entity_reference field storage configs that target media.
  // This is a single entity query — cached for the scan.
  $all_field_storages = $this->entityTypeManager
    ->getStorage('field_storage_config')
    ->loadMultiple();

  $media_field_names = [];
  foreach ($all_field_storages as $field_storage) {
    if ($field_storage->getType() === 'entity_reference'
        && $field_storage->getSetting('target_type') === 'media') {
      // Key format: {entity_type}.{field_name}
      $media_field_names[$field_storage->getTargetEntityTypeId()][] = $field_storage->getName();
    }
  }

  foreach ($prefixes as $prefix => $entity_type) {
    $field_names = $media_field_names[$entity_type] ?? [];
    foreach ($field_names as $field_name) {
      $table = $entity_type . '__' . $field_name;
      $column = $field_name . '_target_id';

      if ($db_schema->tableExists($table) && $db_schema->fieldExists($table, $column)) {
        $this->mediaRefFieldTableCache[] = [
          'table' => $table,
          'column' => $column,
          'entity_type' => $entity_type,
          'field_name' => $field_name,
        ];
      }
    }
  }

  return $this->mediaRefFieldTableCache;
}

/**
 * Bulk-queries entity reference fields for a batch of media IDs.
 *
 * Replaces Part 1 of findMediaUsageViaEntityQuery() (entity reference fields,
 * lines 3940–3992). For each media-targeting field table, runs one
 * SELECT ... WHERE target_id IN (...).
 *
 * @param array $media_ids
 *   Array of media entity IDs.
 *
 * @return array
 *   Keyed by media_id => [['entity_type', 'entity_id', 'field_name', 'bundle']].
 */
protected function bulkQueryMediaEntityRefs(array $media_ids): array {
  $map = [];
  if (empty($media_ids)) {
    return $map;
  }

  foreach ($this->getMediaReferenceFieldTables() as $field_info) {
    try {
      $rows = $this->database->select($field_info['table'], 'f')
        ->fields('f', ['entity_id', $field_info['column'], 'bundle'])
        ->condition($field_info['column'], $media_ids, 'IN')
        ->execute()
        ->fetchAll();

      foreach ($rows as $row) {
        $mid = (int) $row->{$field_info['column']};
        $map[$mid][] = [
          'entity_type' => $field_info['entity_type'],
          'entity_id' => (int) $row->entity_id,
          'field_name' => $field_info['field_name'],
          'bundle' => $row->bundle,
          'method' => 'entity_reference',
        ];
      }
    }
    catch (\Exception $e) {
      continue;
    }
  }

  return $map;
}
```

**Cost:** ~10–20 queries (~0.3s) replaces ~150–300 EntityQueries (~5s).

### 1g. `bulkQueryMediaUuids()` + `bulkQueryMediaEmbedRefs()` — replaces CKEditor `<drupal-media>` LIKE in `findMediaUsageViaEntityQuery()` Part 2

**Part 2 (CKEditor embeds, lines 4007–4063):**
For each media ID, the method loads the media entity to get its UUID, then LIKE-
searches all text fields for `<drupal-media data-entity-uuid="{uuid}">`. It also
loads matching entities to verify the UUID is present in the current field value.

This is the most complex part. The bulk approach:
1. Bulk-load media UUIDs for the batch
2. Query each text-field table once for ANY of the UUIDs
3. Match results to specific UUIDs in PHP

```php
/**
 * Bulk-queries media entity UUIDs for a batch of media IDs.
 *
 * @param array $media_ids
 *   Array of media entity IDs.
 *
 * @return array
 *   Keyed by media_id => uuid string.
 */
protected function bulkQueryMediaUuids(array $media_ids): array {
  if (empty($media_ids)) {
    return [];
  }

  return $this->database->select('media', 'm')
    ->fields('m', ['mid', 'uuid'])
    ->condition('mid', $media_ids, 'IN')
    ->execute()
    ->fetchAllKeyed();  // mid => uuid
}

/**
 * Bulk-queries text fields for CKEditor <drupal-media> embeds.
 *
 * Replaces Part 2 of findMediaUsageViaEntityQuery() (CKEditor embeds,
 * lines 4007–4063). For each text-field table, runs one SELECT with a
 * broad LIKE '%data-entity-uuid%' then matches specific UUIDs in PHP.
 *
 * Uses getTextFieldTables() (already cached, line 4235).
 *
 * @param array $media_uuids
 *   Keyed by media_id => uuid string.
 *
 * @return array
 *   Keyed by media_uuid => [['entity_type', 'entity_id', 'field_name']].
 */
protected function bulkQueryMediaEmbedRefs(array $media_uuids): array {
  $map = [];
  if (empty($media_uuids)) {
    return $map;
  }

  $uuid_to_mid = array_flip($media_uuids);  // uuid => mid
  $tables = $this->getTextFieldTables();

  foreach ($tables as $t) {
    try {
      // Broad LIKE: find ANY row with a drupal-media embed.
      // This is cheaper than N individual UUID LIKEs.
      $rows = $this->database->select($t['table'], 'tbl')
        ->fields('tbl', ['entity_id', $t['value_column']])
        ->condition($t['value_column'], '%data-entity-uuid%', 'LIKE')
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      continue;
    }

    if (empty($rows)) {
      continue;
    }

    // Match specific UUIDs in PHP (fast string search).
    $value_col = $t['value_column'];
    foreach ($rows as $row) {
      $text = $row->$value_col;
      foreach ($media_uuids as $mid => $uuid) {
        if (strpos($text, $uuid) !== FALSE) {
          $map[$uuid][] = [
            'entity_type' => $t['entity_type'],
            'entity_id' => (int) $row->entity_id,
            'field_name' => $t['field_name'],
            'method' => 'media_embed',
          ];
        }
      }
    }
  }

  return $map;
}
```

**Cost:** 113 text-field table queries once per callback (~1–2s) replaces
per-media-ID × per-text-field LIKE queries (~3s per item with embeds).

**Tradeoff:** The broad `LIKE '%data-entity-uuid%'` query hits every text-field
table once per callback, even if the batch has zero media embeds. On a typical site this
is ~113 queries. However, these are the same tables already queried by Phase 2
orphan LIKE searches, so MySQL's buffer pool will have them cached. If profiling
shows this is too expensive for media-light batches, add a guard:

```php
// Skip CKEditor embed search if no media IDs in this batch.
if (empty($all_media_ids)) {
  $result['media_embed_refs'] = [];
}
```

This guard is already present in `preloadManagedFileBatch()` — the embed query
only runs when `$all_media_ids` is non-empty.

### 1h. Update `processManagedFile()` to use pre-loaded data

**New signature:**

```php
protected function processManagedFile(
    object $file,
    bool $is_temp,
    array &$context,
    ?array $preloaded = NULL,
): void {
```

**Replace inline `file_usage` media query (line ~730):**

```php
// CURRENT:
$media_usages = $this->database->select('file_usage', 'fu')
  ->fields('fu', ['id'])
  ->condition('fid', $file->fid)
  ->condition('type', 'media')
  ->execute()
  ->fetchCol();

// NEW:
if ($preloaded) {
  $media_usages = [];
  foreach (($preloaded['file_usage'][$file->fid] ?? []) as $row) {
    if ($row->type === 'media') {
      $media_usages[] = $row->id;
    }
  }
}
else {
  // Fallback for direct calls (tests, legacy).
  $media_usages = $this->database->select('file_usage', 'fu')
    ->fields('fu', ['id'])
    ->condition('fid', $file->fid)
    ->condition('type', 'media')
    ->execute()
    ->fetchCol();
}
```

**Replace existing temp item check (line ~773):**

```php
// CURRENT:
$existing_id = $this->database->select('digital_asset_item', 'dai')
  ->fields('dai', ['id'])
  ->condition('fid', $file->fid)
  ->condition('is_temp', 1)
  ->execute()
  ->fetchField();

// NEW:
$existing_id = $preloaded
  ? ($preloaded['existing_temp'][$file->fid] ?? NULL)
  : $this->database->select('digital_asset_item', 'dai')
      ->fields('dai', ['id'])
      ->condition('fid', $file->fid)
      ->condition('is_temp', 1)
      ->execute()
      ->fetchField();
```

**Replace `findDirectFileUsage()` call (line ~793):**

`findDirectFileUsage()` queries `file_usage` for non-media types, then calls
`findFileFieldName()` to get the field name (which loads the parent entity).
With pre-loaded data, replace both with map lookups:

```php
// CURRENT:
$direct_file_usage = $this->findDirectFileUsage($file->fid);

// NEW:
if ($preloaded) {
  $direct_file_usage = [];
  // Get non-media file_usage entries.
  foreach (($preloaded['file_usage'][$file->fid] ?? []) as $row) {
    if ($row->type === 'media') {
      continue;
    }
    if (!in_array($row->type, ['node', 'paragraph', 'taxonomy_term', 'block_content'])) {
      continue;
    }

    // Find field name from pre-queried file field refs.
    // If fid is not in any current-revision field table, this is a stale
    // file_usage entry from an old revision — skip it (same logic as
    // findFileFieldName() returning 'direct_file').
    $field_name = 'direct_file';
    foreach (($preloaded['file_field_refs'][$file->fid] ?? []) as $ref) {
      if ($ref['entity_type'] === $row->type && $ref['entity_id'] === (int) $row->id) {
        $field_name = $ref['field_name'];
        break;
      }
    }

    // Skip stale revision references (same as line 4483).
    if ($field_name === 'direct_file') {
      continue;
    }

    $direct_file_usage[] = [
      'entity_type' => $row->type,
      'entity_id' => (int) $row->id,
      'field_name' => $field_name,
      'method' => 'file_usage',
    ];
  }
}
else {
  $direct_file_usage = $this->findDirectFileUsage($file->fid);
}
```

**Replace `findMediaUsageViaEntityQuery()` calls (line ~839):**

```php
// CURRENT:
foreach ($all_media_ids as $mid) {
  $refs = $this->findMediaUsageViaEntityQuery($mid);
  $media_references = array_merge($media_references, $refs);
}

// NEW:
if ($preloaded) {
  foreach ($all_media_ids as $mid) {
    // Part 1: entity reference fields.
    foreach (($preloaded['media_entity_refs'][$mid] ?? []) as $ref) {
      $media_references[] = $ref;
    }
    // Part 2: CKEditor <drupal-media> embeds.
    $uuid = $preloaded['media_uuids'][$mid] ?? NULL;
    if ($uuid) {
      foreach (($preloaded['media_embed_refs'][$uuid] ?? []) as $ref) {
        $media_references[] = $ref;
      }
    }
  }
}
else {
  foreach ($all_media_ids as $mid) {
    $refs = $this->findMediaUsageViaEntityQuery($mid);
    $media_references = array_merge($media_references, $refs);
  }
}
```

### 1i. Update `scanManagedFilesChunk()` caller

```php
// CURRENT (line 1022):
$itemsThisCallback = $this->processWithTimeBudget(
  $context,
  'last_fid',
  'total_files',
  fn() => $this->getManagedFilesCount(),
  fn(int $lastFid, int $limit) => $this->getManagedFileIdsAfter($lastFid, $limit),
  fn(array $ids) => $this->loadManagedFileRows($ids),
  fn(object $file) => $this->processManagedFile($file, $is_temp, $context),
);

// NEW:
$itemsThisCallback = $this->processWithTimeBudget(
  $context,
  'last_fid',
  'total_files',
  fn() => $this->getManagedFilesCount(),
  fn(int $lastFid, int $limit) => $this->getManagedFileIdsAfter($lastFid, $limit),
  fn(array $ids) => $this->loadManagedFileRows($ids),
  fn(object $file, ?array $preloaded) => $this->processManagedFile($file, $is_temp, $context, $preloaded),
  fn(array $entities) => $this->preloadManagedFileBatch($entities),
);
```

### 1j. Expected impact

| Metric | Before (per callback, 48–65 items) | After (per callback) |
|---|---|---|
| `file_usage` queries | 100–200 (~3–6s) | 1 bulk (~0.03s) |
| Existing temp check | 100 (~2s) | 1 bulk (~0.02s) |
| `findFileFieldName()` entity loads | ~50 (~2.5s) | ~15 field table queries (~0.3s) |
| `findMediaUsageViaEntityQuery()` | ~150–300 EntityQueries + LIKEs (~5–8s) | ~25 bulk queries (~0.8–1.5s) |
| Media entity loads (UUID) | ~50 `load()` calls (~0.5s) | 1 bulk SELECT on `media` (~0.02s) |
| **Total read time** | **~10–16s (exceeds budget)** | **~1.5–2.5s** |
| **Items per 10s callback** | **~48–65** | **~150–250** |

Phase 1 estimate: 5,527 files ÷ 200 items/cb ≈ 28 callbacks × 12s ≈ **~6 min**
(down from ~20 min).

**Conservative estimate:** The media embed LIKE queries across 113 tables add
~1–2s per callback. If many batches have media, effective throughput may be
~120–180 items/cb, giving ~8 min total. Still a 2.5× improvement.

---

## Fix 2: Single-Pass Broad LIKE for Phase 2 Orphans

### Strategy

Replace the current O(tables × orphan_batches) LIKE search with O(tables).
Query each text-field table ONCE for any row containing `/files/`, then match
all 1,636 orphan paths in PHP.

The Batch API already solves the timeout problem by splitting work into
callbacks. Rather than manually reimplementing cursor-based table scanning
inside `scanOrphanFilesChunkNew()`, use the Batch API properly: make the
broad LIKE scan its own **batch phase** that naturally handles timeouts via
callbacks, then let the orphan processing phase consume the pre-built index.

### Current architecture (to be replaced)

`scanOrphanFilesChunkNew()` (line 5259) uses `findLocalFileLinkUsageBatch()`
(line 4317) to batch LIKE queries for 50 orphans at a time. The batch state
(`usage_batch_end`, `orphan_usage_map`) persists in `$context['sandbox']`.

Example site with 1,636 orphans: 1,636 ÷ 50 = 33 refills × 113 tables = **3,729 table scans**.
Each refill costs ~2.5s on managed hosting. With ~10–12 orphans processed per 10s
callback, Phase 2 takes ~27 min.

### 2a. Split Phase 2 into two batch operations

Instead of embedding the LIKE scan inside orphan processing, use two
Batch API operations:

- **Phase 2a — Orphan LIKE Index:** Scans all text-field tables for `/files/`
  references. Builds a usage map and stores it via State API. Each callback
  processes tables within the time budget — the Batch API handles the rest.
- **Phase 2b — Orphan Processing:** Reads the pre-built usage map from State
  API. Processes orphan files with instant hash lookups instead of LIKE queries.

This is how Batch API is designed to work: each operation focuses on one task,
the framework manages timeouts and callbacks automatically.

**Update `PHASE_MAP` in `ScanAssetsForm.php`:**

```php
const PHASE_MAP = [
  1 => ['method' => 'batchProcessManagedFiles', 'label' => 'Managed Files'],
  2 => ['method' => 'batchBuildOrphanUsageIndex', 'label' => 'Orphan Usage Index'],
  3 => ['method' => 'batchProcessOrphanFiles', 'label' => 'Orphan Files'],
  4 => ['method' => 'batchProcessContent', 'label' => 'Content (External URLs)'],
  5 => ['method' => 'batchProcessMediaEntities', 'label' => 'Remote Media'],
  6 => ['method' => 'batchProcessMenuLinks', 'label' => 'Menu Links'],
  7 => ['method' => 'batchProcessCsvFields', 'label' => 'CSV Export Fields'],
];
```

**Why State API instead of `$context['results']`:** Batch API's `$context['results']`
persists across all operations but gets serialized on every callback of every
subsequent phase. The usage map (~48–300KB) would add overhead to Phases 3–7
(now Phases 4–7 with the new numbering).
State API stores it once, reads it once, and cleans up at scan end.

### 2b. Phase 2a — `buildOrphanUsageIndex()` scanner method

A standard Batch API callback method. Each callback scans text-field tables
within the time budget. The Batch API calls it repeatedly until `$context['finished'] = 1`.

```php
/**
 * Builds the orphan file usage index by scanning text-field tables.
 *
 * Batch API operation (Phase 2a). Each callback scans a batch of
 * text-field tables for rows containing '/files/', matches results
 * against known orphan paths, and accumulates a usage map.
 *
 * The completed map is stored via State API for Phase 2b (orphan processing).
 * This replaces findLocalFileLinkUsageBatch() which ran O(tables × batches)
 * queries; this runs O(tables) — one LIKE per table, regardless of orphan count.
 *
 * @param array &$context
 *   Batch API context array.
 */
public function buildOrphanUsageIndex(array &$context): void {
  $budget = $this->getBatchTimeBudget();
  $startTime = microtime(true);

  // Initialize on first callback.
  if (!isset($context['sandbox']['table_index'])) {
    // Build orphan file list and needle map.
    $orphan_files = $this->buildOrphanFileList();
    $needle_to_hashes = [];
    foreach ($orphan_files as $file) {
      $needles = $this->buildFileSearchNeedles($file['uri']);
      foreach ($needles as $needle) {
        $needle_to_hashes[$needle][] = $file['url_hash'];
      }
    }

    $context['sandbox']['table_index'] = 0;
    $context['sandbox']['needle_to_hashes'] = $needle_to_hashes;
    $context['sandbox']['usage_map'] = [];

    // Also store orphan file list in State for Phase 2b.
    $this->state->set('dai.scan.orphan_files', $orphan_files);
  }

  $tables = $this->getTextFieldTables();
  $total_tables = count($tables);
  $table_index = $context['sandbox']['table_index'];
  $needle_to_hashes = $context['sandbox']['needle_to_hashes'];
  $all_needles = array_keys($needle_to_hashes);
  $tablesThisCallback = 0;

  // Scan tables within time budget — Batch API handles the rest.
  while ($table_index < $total_tables && (microtime(true) - $startTime) < $budget) {
    $t = $tables[$table_index];
    try {
      $rows = $this->database->select($t['table'], 'tbl')
        ->fields('tbl', ['entity_id', $t['value_column']])
        ->condition($t['value_column'], '%/files/%', 'LIKE')
        ->execute()
        ->fetchAll();

      if (!empty($rows)) {
        $value_col = $t['value_column'];
        foreach ($rows as $row) {
          $text = $row->$value_col;
          foreach ($all_needles as $needle) {
            if (stripos($text, $needle) !== FALSE) {
              foreach ($needle_to_hashes[$needle] as $hash) {
                $key = $t['entity_type'] . ':' . $row->entity_id . ':' . $t['field_name'];
                $context['sandbox']['usage_map'][$hash][$key] = [
                  'entity_type' => $t['entity_type'],
                  'entity_id' => (int) $row->entity_id,
                  'field_name' => $t['field_name'],
                  'method' => 'file_link',
                ];
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // Skip failed tables — log and continue.
      $this->logger->warning('Failed to scan table @table for orphan usage: @error', [
        '@table' => $t['table'],
        '@error' => $e->getMessage(),
      ]);
    }

    $table_index++;
    $tablesThisCallback++;
    $this->maybeUpdateHeartbeat();
  }

  $context['sandbox']['table_index'] = $table_index;

  // Progress: fraction of tables scanned.
  $context['finished'] = $total_tables > 0 ? $table_index / $total_tables : 1;

  if ($context['finished'] >= 1) {
    // Flatten dedup keys to simple arrays, store in State for Phase 2b.
    $usage_map = $context['sandbox']['usage_map'];
    foreach ($usage_map as $hash => &$refs) {
      $refs = array_values($refs);
    }
    unset($refs);

    $this->state->set('dai.scan.orphan_usage_map', $usage_map);
    $context['finished'] = 1;
  }

  $context['results']['last_chunk_items'] = $tablesThisCallback;
}
```

### 2c. Phase 2b — Update `scanOrphanFilesChunkNew()` to consume pre-built index

The orphan processing phase reads the pre-built usage map from State API
instead of running LIKE queries. Each orphan gets an instant hash lookup.

```php
// In scanOrphanFilesChunkNew(), replace orphan list build + LIKE refill logic:

// CURRENT — build orphan list in sandbox + refill LIKE batches:
if (!isset($context['sandbox']['orphan_files'])) {
  $orphan_list = $this->buildOrphanFileList();
  $context['sandbox']['orphan_files'] = $orphan_list;
  // ... usage_batch_end, orphan_usage_map ...
}
// ... refill logic with findLocalFileLinkUsageBatch() ...

// NEW — read pre-built data from State API:
if (!isset($context['sandbox']['orphan_files'])) {
  // Phase 2a already built these and stored in State.
  $context['sandbox']['orphan_files'] = $this->state->get('dai.scan.orphan_files', []);
  $context['sandbox']['orphan_usage_map'] = $this->state->get('dai.scan.orphan_usage_map', []);
  $context['sandbox']['orphan_index'] = 0;
  $context['sandbox']['orphan_total'] = count($context['sandbox']['orphan_files']);
}

// In the processing loop — instant hash lookup, no LIKE queries:
$hash = $orphanFiles[$index]['url_hash'];
$usage = $context['sandbox']['orphan_usage_map'][$hash] ?? [];
$this->processOrphanFile($orphanFiles[$index], $is_temp, $existing_temp_map, $usage);
```

Remove the `usage_batch_end` sandbox key and `findLocalFileLinkUsageBatch()`
refill logic entirely. The processing loop becomes pure writes — no LIKE
queries, no refill pauses.

### 2d. Add batch callback in `ScanAssetsForm.php`

```php
/**
 * Batch operation: Build orphan file usage index.
 *
 * @param int $phase_number
 *   The phase number for checkpoint saving.
 * @param array $context
 *   Batch context array.
 */
public static function batchBuildOrphanUsageIndex(int $phase_number, array &$context) {
  $scanner = \Drupal::service('digital_asset_inventory.scanner');
  $scanner->resetHeartbeatWriteCount();
  $callbackStartTime = microtime(true);
  $scanner->updateScanHeartbeat();

  $scanner->buildOrphanUsageIndex($context);

  if ($context['finished'] >= 1) {
    $scanner->saveCheckpoint($phase_number, TRUE);
  }

  $scanner->updateScanHeartbeat();

  $tables = $context['results']['last_chunk_items'] ?? 0;
  $scanner->logBatchTiming($phase_number, $tables, $callbackStartTime, 'tables');
}
```

### 2e. Clean up State data at scan end

In `batchFinished()` or `promoteTemporaryItems()`, clean up the State entries:

```php
$this->state->delete('dai.scan.orphan_files');
$this->state->delete('dai.scan.orphan_usage_map');
```

### 2f. State API size considerations

The State API stores data in the `key_value` table (serialized). Size limits:

- `key_value.value` column is `LONGBLOB` — supports up to 4GB
- Typical orphan usage map: ~48KB (200 orphans × 2 refs × 120 bytes)
- Worst case: ~300KB (500 orphans × 5 refs × 120 bytes)
- Orphan file list: ~320KB (1,636 orphans × ~200 bytes/entry)

Both are well within limits. For sites with 5,000+ orphans (unusual), add a
size guard:

```php
if (count($orphan_files) > 5000) {
  $this->logger->warning('Large orphan file count: @count. Consider cleaning up unused files.', [
    '@count' => count($orphan_files),
  ]);
}
```

### 2g. Expected impact

| Metric | Before | After |
|---|---|---|
| LIKE queries per scan | 113 × 33 batches = 3,729 | **113** (fixed) |
| Phase 2a (index build) | N/A | ~1–3 callbacks (~10–30s) |
| Phase 2b orphan processing | ~0.8–1.0s/orphan (LIKE-bound) | ~0.02–0.05s/orphan (writes only) |
| Orphans per callback (Phase 2b) | ~10–12 | ~200+ |
| **Phase 2 total** | **~27 min** | **~2–4 min** |

### 2h. Why this is better than manual sub-stepping

The previous spec version embedded table-cursor logic inside `scanOrphanFilesChunkNew()`,
manually tracking `broad_like_table_index` and `broad_like_complete` in sandbox.
This reimplemented what Batch API already does:

| Concern | Manual approach | Batch API approach |
|---|---|---|
| Timeout safety | Manual time check + early return | Batch API calls back automatically |
| Progress tracking | Manual `$context['finished'] = 0.01` hack | Natural `table_index / total_tables` |
| Checkpoint/resume | Must handle two sub-states in one phase | Each phase has its own checkpoint |
| Code clarity | Mixed LIKE scanning + orphan processing in one method | One method per concern |
| Logging | Ambiguous "Phase 2" with 0 items | Clear "Phase 2: Orphan Usage Index" vs "Phase 3: Orphan Files" |

---

## Fix 3: Reset `$fileFieldTableCache` and `$mediaRefFieldTableCache`

The new cache properties must be reset at scan start and between phases to
avoid stale data (same pattern as `$textFieldTableCache`).

### 3a. Add properties (near line 181)

```php
/**
 * Cached list of file/image field data tables.
 * Built once per PHP process by getFileFieldTables().
 */
private ?array $fileFieldTableCache = NULL;

/**
 * Cached list of entity reference field tables targeting media.
 * Built once per PHP process by getMediaReferenceFieldTables().
 */
private ?array $mediaRefFieldTableCache = NULL;
```

### 3b. Reset in `resetScanState()` (or equivalent)

If there's a method that resets scan state between phases or at scan start,
add:

```php
$this->fileFieldTableCache = NULL;
$this->mediaRefFieldTableCache = NULL;
```

These caches are safe to persist across callbacks within a phase (field config
doesn't change mid-scan), but should be reset between scans.

---

## Implementation Order

```
Fix 2: Single-pass broad LIKE via Batch API   — DEPLOYED ✅
  Result: Phase 2 orphan index built in 3s (was ~27 min of LIKE queries)

Fix 1: Bulk reads for Phase 1                  — DEPLOYED ✅
  Result: Phase 1 at 100 items/cb, 0.02s/item (was 48–65 items, 0.15–0.21s)

Fix 3: Cache resets                            — DEPLOYED ✅
  Result: fileFieldTableCache + mediaRefFieldTableCache reset between scans

Fix 4: Raw SQL existing-temp lookup (Phase 3)  — PLANNED
  Impact: ~1–2 min saved (eliminates EntityQuery + loadMultiple per callback)
  Complexity: Low (replaces 6 lines of Entity API with 1 raw SQL query)
  Risk: Low (same pattern as bulkQueryExistingTempItems in Fix 1)

Fix 5: Bulk paragraph parent resolution        — PLANNED (BIGGEST REMAINING WIN)
  Impact: ~8–10 min saved (eliminates per-paragraph entity loads)
  Complexity: Moderate (raw SQL on paragraphs_item_field_data + attachment
              verification via entity_reference_revisions field tables)
  Risk: Medium (must correctly detect orphan paragraphs)
```

---

## Fix 4: Raw SQL Existing-Temp Lookup for Phase 3

### Problem

`scanOrphanFilesChunkNew()` (line 5891) pre-queries existing temp items
for the upcoming batch of 200 orphans using Entity API:

```php
$storage = $this->entityTypeManager->getStorage('digital_asset_item');
$existing_ids = $storage->getQuery()
  ->condition('url_hash', $orphan_hashes, 'IN')
  ->condition('source_type', 'filesystem_only')
  ->condition('is_temp', TRUE)
  ->accessCheck(FALSE)
  ->execute();
$existing_items = $storage->loadMultiple($existing_ids);
foreach ($existing_items as $item) {
  $existing_temp_map[$item->get('url_hash')->value] = $item;
}
```

This runs EntityQuery (SQL + overhead) then `loadMultiple()` to load full
entity objects — but `processOrphanFile()` only uses `->id()` from them
(line 5677: `$asset_id = (int) $existing_temp_map[$url_hash]->id();`).

### Solution

Replace with raw SQL returning `url_hash => id` map. Same pattern as
`bulkQueryExistingTempItems()` (Fix 1) but keyed by `url_hash` instead
of `fid`:

```php
$existing_temp_map = [];
if (!empty($orphan_hashes)) {
  $rows = $this->database->select('digital_asset_item', 'dai')
    ->fields('dai', ['url_hash', 'id'])
    ->condition('url_hash', $orphan_hashes, 'IN')
    ->condition('source_type', 'filesystem_only')
    ->condition('is_temp', 1)
    ->execute()
    ->fetchAllKeyed();  // url_hash => id
  $existing_temp_map = $rows;
}
```

Update `processOrphanFile()` to accept `int` instead of entity object:

```php
// CURRENT (line 5676):
if (isset($existing_temp_map[$url_hash])) {
  $asset_id = (int) $existing_temp_map[$url_hash]->id();
  $this->rawUpdateAssetItem($asset_id, [...]);
}

// NEW:
if (isset($existing_temp_map[$url_hash])) {
  $asset_id = (int) $existing_temp_map[$url_hash];
  $this->rawUpdateAssetItem($asset_id, [...]);
}
```

### Expected impact

1 raw SQL query (~0.02s) replaces EntityQuery + loadMultiple (~0.2–0.5s).
Saves ~0.3s per callback × ~70 callbacks = **~20s total**. Small but free.

---

## Fix 5: Bulk Paragraph Parent Resolution for Phase 3

### Problem

`processOrphanFile()` calls `getParentFromParagraph()` for every usage
record that references a `paragraph` entity. This method:

1. Loads the paragraph entity: `$this->entityTypeManager->getStorage('paragraph')->load($paragraph_id)` (~0.03s)
2. Calls `$paragraph->getParentEntity()` which loads the parent entity (~0.03s)
3. For nested paragraphs, recurses up the chain (each level = another entity load)
4. Calls `isParagraphInEntityField()` which loads the parent entity's fields and iterates all `entity_reference_revisions` fields to verify attachment (~0.05s)

With `$paragraphParentCache`, repeat paragraph IDs within a callback are
cached. But the cache resets every callback (line 459, via `resetPhaseEntityCaches`
at line 5943). With ~23 orphans per callback, each with ~2–5 usage refs,
and many refs pointing to paragraphs, this is **20–50 entity loads per callback**.

Production evidence: 23 items per 10s callback = 0.43s/item. The raw SQL
writes take ~0.01s/item. The usage map lookup is instant (hash table). The
remaining ~0.42s/item is almost entirely paragraph parent resolution.

### Strategy

Replace entity loads with raw SQL on `paragraphs_item_field_data` table.
The paragraph entity stores `parent_type`, `parent_id`, `parent_field_name`
columns — exactly the data `getParentFromParagraph()` extracts via entity load.

Two-step approach:
1. **Bulk query parent chain data** from `paragraphs_item_field_data`
2. **Bulk verify attachment** via `entity_reference_revisions` field tables

### 5a. `bulkResolveParagraphParents()` — per-callback pre-query

Called once at the start of each Phase 3 callback. Collects all paragraph
entity IDs from the upcoming batch's usage refs, then resolves parents in bulk.

```php
/**
 * Bulk-resolves paragraph parent chains via raw SQL.
 *
 * For a set of paragraph IDs, queries paragraphs_item_field_data to get
 * parent_type/parent_id, traces chains to root, then verifies attachment
 * via entity_reference_revisions field tables.
 *
 * @param array $paragraph_ids
 *   Array of paragraph entity IDs.
 *
 * @return array
 *   Keyed by paragraph_id => result array:
 *   - ['type' => 'node', 'id' => 123] for attached paragraphs
 *   - ['orphan' => TRUE, 'context' => '...'] for orphaned paragraphs
 *   - NULL if paragraph doesn't exist
 */
protected function bulkResolveParagraphParents(array $paragraph_ids): array {
  if (empty($paragraph_ids) || !$this->moduleHandler->moduleExists('paragraphs')) {
    return [];
  }

  $paragraph_ids = array_unique(array_map('intval', $paragraph_ids));

  // Step 1: Bulk-query parent chain data from paragraphs_item_field_data.
  // May need multiple rounds for nested paragraphs.
  $all_needed = $paragraph_ids;
  $parent_data = [];  // paragraph_id => {parent_type, parent_id, parent_field_name}
  $max_depth = 10;    // Safety limit for deeply nested paragraphs.

  for ($depth = 0; $depth < $max_depth && !empty($all_needed); $depth++) {
    $rows = $this->database->select('paragraphs_item_field_data', 'p')
      ->fields('p', ['id', 'parent_type', 'parent_id', 'parent_field_name'])
      ->condition('id', $all_needed, 'IN')
      ->execute()
      ->fetchAllAssoc('id');

    $next_needed = [];
    foreach ($all_needed as $pid) {
      if (!isset($rows[$pid])) {
        // Paragraph doesn't exist — stale reference.
        $parent_data[$pid] = NULL;
        continue;
      }
      $row = $rows[$pid];
      $parent_data[$pid] = $row;

      // If parent is another paragraph, we need its data too.
      if ($row->parent_type === 'paragraph' && !isset($parent_data[$row->parent_id])) {
        $next_needed[] = (int) $row->parent_id;
      }
    }
    $all_needed = array_unique($next_needed);
  }

  // Step 2: Trace each original paragraph to its root parent.
  $results = [];
  foreach ($paragraph_ids as $pid) {
    if (!isset($parent_data[$pid]) || $parent_data[$pid] === NULL) {
      $results[$pid] = NULL;
      continue;
    }

    // Trace chain to root.
    $chain = [$pid];
    $current_id = $pid;
    $root_parent_type = NULL;
    $root_parent_id = NULL;
    $found_root = FALSE;

    for ($i = 0; $i < $max_depth; $i++) {
      $data = $parent_data[$current_id] ?? NULL;
      if ($data === NULL) {
        // Broken chain — orphan.
        break;
      }
      if ($data->parent_type !== 'paragraph') {
        // Found non-paragraph root parent.
        $root_parent_type = $data->parent_type;
        $root_parent_id = (int) $data->parent_id;
        $found_root = TRUE;
        break;
      }
      // Parent is another paragraph — continue tracing.
      $current_id = (int) $data->parent_id;
      $chain[] = $current_id;
    }

    if (!$found_root) {
      $this->currentOrphanCount++;
      $results[$pid] = ['orphan' => TRUE, 'context' => 'missing_parent_entity', 'paragraph_id' => $pid];
      continue;
    }

    // Step 3: Verify attachment via entity_reference_revisions field tables.
    // The root paragraph (last in chain) must be in the root parent's
    // paragraph field. Each nested paragraph must be in its parent paragraph's field.
    $root_paragraph_id = end($chain);
    if (!$this->isParagraphAttachedRawSql($root_paragraph_id, $root_parent_type, $root_parent_id)) {
      $this->currentOrphanCount++;
      $results[$pid] = ['orphan' => TRUE, 'context' => 'detached_component', 'paragraph_id' => $pid];
      continue;
    }

    // For nested chains, verify each level.
    $is_attached = TRUE;
    for ($i = 0; $i < count($chain) - 1; $i++) {
      $child_id = $chain[$i];
      $parent_id = $chain[$i + 1];
      if (!$this->isParagraphAttachedRawSql($child_id, 'paragraph', $parent_id)) {
        $this->currentOrphanCount++;
        $results[$pid] = ['orphan' => TRUE, 'context' => 'detached_component', 'paragraph_id' => $pid];
        $is_attached = FALSE;
        break;
      }
    }

    if ($is_attached) {
      $results[$pid] = [
        'type' => $root_parent_type,
        'id' => $root_parent_id,
      ];
    }
  }

  return $results;
}
```

### 5b. `isParagraphAttachedRawSql()` — verify attachment without entity load

Replaces `isParagraphInEntityField()` which loads the parent entity and
iterates all fields. Instead, directly queries `entity_reference_revisions`
field tables (same pattern as `getFileFieldTables()`).

```php
/**
 * Cached list of entity_reference_revisions field tables.
 * Built once per scan by getParagraphRefFieldTables().
 *
 * @var array[]|null
 */
private ?array $paragraphRefFieldTableCache = NULL;

/**
 * Returns all entity_reference_revisions field tables targeting paragraphs.
 *
 * @return array[]
 *   Array of ['table', 'column', 'entity_type'].
 */
protected function getParagraphRefFieldTables(): array {
  if ($this->paragraphRefFieldTableCache !== NULL) {
    return $this->paragraphRefFieldTableCache;
  }

  $this->paragraphRefFieldTableCache = [];
  if (!$this->moduleHandler->moduleExists('paragraphs')) {
    return $this->paragraphRefFieldTableCache;
  }

  $db_schema = $this->database->schema();

  // entity_reference_revisions fields targeting paragraphs.
  // Load field_storage_config to find them.
  $all_field_storages = $this->entityTypeManager
    ->getStorage('field_storage_config')
    ->loadMultiple();

  $paragraph_field_names = [];
  foreach ($all_field_storages as $field_storage) {
    if ($field_storage->getType() === 'entity_reference_revisions'
        && $field_storage->getSetting('target_type') === 'paragraph') {
      $entity_type = $field_storage->getTargetEntityTypeId();
      $paragraph_field_names[$entity_type][] = $field_storage->getName();
    }
  }

  $prefixes = [
    'node' => 'node',
    'taxonomy_term' => 'taxonomy_term',
    'block_content' => 'block_content',
    'paragraph' => 'paragraph',
  ];

  foreach ($prefixes as $entity_type => $entity_type_id) {
    $field_names = $paragraph_field_names[$entity_type] ?? [];
    foreach ($field_names as $field_name) {
      $table = $entity_type . '__' . $field_name;
      $column = $field_name . '_target_id';

      if ($db_schema->tableExists($table) && $db_schema->fieldExists($table, $column)) {
        $this->paragraphRefFieldTableCache[] = [
          'table' => $table,
          'column' => $column,
          'entity_type' => $entity_type,
        ];
      }
    }
  }

  return $this->paragraphRefFieldTableCache;
}

/**
 * Checks if a paragraph is attached to a parent entity via raw SQL.
 *
 * Replaces isParagraphInEntityField() which loads the parent entity and
 * iterates field definitions. Instead queries entity_reference_revisions
 * field tables directly.
 *
 * @param int $paragraph_id
 *   The paragraph ID to look for.
 * @param string $parent_entity_type
 *   The parent entity type (e.g., 'node', 'paragraph').
 * @param int $parent_entity_id
 *   The parent entity ID.
 *
 * @return bool
 *   TRUE if the paragraph is found in the parent's fields.
 */
protected function isParagraphAttachedRawSql(int $paragraph_id, string $parent_entity_type, int $parent_entity_id): bool {
  foreach ($this->getParagraphRefFieldTables() as $field_info) {
    if ($field_info['entity_type'] !== $parent_entity_type) {
      continue;
    }
    try {
      $found = $this->database->select($field_info['table'], 'f')
        ->fields('f', ['entity_id'])
        ->condition($field_info['column'], $paragraph_id)
        ->condition('entity_id', $parent_entity_id)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($found !== FALSE) {
        return TRUE;
      }
    }
    catch (\Exception $e) {
      continue;
    }
  }
  return FALSE;
}
```

### 5c. Wire into `scanOrphanFilesChunkNew()`

After the existing-temp pre-query, collect all paragraph IDs from the
upcoming batch's usage refs and resolve them in bulk. Populate
`$this->paragraphParentCache` so `processOrphanFile()` uses cached
results instead of per-item entity loads.

```php
// After existing_temp_map pre-query, before processing loop:

// Collect paragraph IDs from upcoming usage refs.
$paragraph_ids = [];
foreach ($upcoming as $orphan) {
  $hash = $orphan['url_hash'];
  $usage = $orphan_usage_map[$hash] ?? [];
  foreach ($usage as $ref) {
    if ($ref['entity_type'] === 'paragraph') {
      $paragraph_ids[] = (int) $ref['entity_id'];
    }
  }
}

// Bulk resolve and warm the cache.
if (!empty($paragraph_ids)) {
  $resolved = $this->bulkResolveParagraphParents($paragraph_ids);
  foreach ($resolved as $pid => $result) {
    $this->paragraphParentCache[$pid] = $result;
  }
}
```

No changes needed in `processOrphanFile()` — it already checks
`$this->paragraphParentCache` before calling `getParentFromParagraph()`:

```php
if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
  $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
}
```

Since `bulkResolveParagraphParents()` pre-populates the cache,
`getParentFromParagraph()` is never called for pre-resolved IDs.

### 5d. Per-item read breakdown in Phase 3 (current)

| # | Read operation | Method | Queries/item | Time/item | Fix |
|---|---|---|---|---|---|
| 1 | Existing temp check (EntityQuery + loadMultiple) | `scanOrphanFilesChunkNew()` line 5897 | ~2 (per callback) | ~0.01s amortized | Fix 4 |
| 2 | Paragraph entity load | `getParentFromParagraph()` line 5125 | 1 entity load | ~0.03s | **Fix 5** |
| 3 | Parent entity load | `getParentEntity()` (Paragraphs module) | 1 entity load | ~0.03s | **Fix 5** |
| 4 | Nested paragraph chain (if 2+ levels) | recursive `getParentFromParagraph()` | 1 per level | ~0.03s/level | **Fix 5** |
| 5 | Attachment verification | `isParagraphInEntityField()` line 5232 | 1 entity load + field iteration | ~0.05s | **Fix 5** |
| 6 | Raw SQL writes | `rawInsertAssetItem()` / `rawUpdateAssetItem()` | 1–3 queries | ~0.01s | — |
| 7 | Usage buffer | `bufferUsageRecord()` | 0 (in-memory) | ~0s | — |
| **Total** | | | **~5–8 queries** | **~0.15–0.20s** | |

Items #2–5 sum to ~0.10–0.15s per paragraph reference. With ~23 items per
callback and ~2–5 paragraph refs per item, that's ~50–100 entity loads
consuming ~5–8s of the 10s budget.

### 5e. Expected impact

| Metric | Before (Fix 4+5) | After |
|---|---|---|
| Existing temp pre-query | EntityQuery + loadMultiple (~0.3s/cb) | 1 raw SQL (~0.02s/cb) |
| Paragraph parent resolution | ~50 entity loads (~5–8s/cb) | ~2–5 raw SQL queries (~0.1–0.3s/cb) |
| **Items per 10s callback** | **~23** | **~100–150** |
| **Phase 3 total (1,636 orphans)** | **~12 min** | **~2–3 min** |
| **Total scan time** | **15m 33s** | **~5–7 min** |

---

## Risk Assessment

### What could go wrong

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `getFileFieldTables()` misses a field type | Low | Missed usage records | Validate against `findFileFieldName()` output on test site |
| `getMediaReferenceFieldTables()` misses a field | Low | Missed media refs | Compare output against `getFieldMapByFieldType('entity_reference')` |
| CKEditor embed LIKE on 113 tables too slow | Medium | First callback slow | Batch API handles via multiple callbacks |
| State API data too large (orphan map) | Low | Slow state reads | `key_value.value` is LONGBLOB; size guard + warning log |
| File/image field identification heuristic wrong | Low | Wrong field tables included/excluded | Validate: `_display` column = file field, `_alt` column = image field |
| `preloadFn` changes `processWithTimeBudget()` contract | Low | Other phases affected | New param is optional + nullable; existing callers unaffected |
| Stale `file_usage` entries not filtered | Medium | False usage records | `file_field_refs` map acts as current-revision filter (section 1e) |
| `getParagraphRefFieldTables()` misses a field | Low | Orphan paragraphs not detected | Validate: compare orphan count before/after |
| `bulkResolveParagraphParents()` chain logic wrong | Medium | Wrong parent attribution | Compare usage records before/after on test site |
| `isParagraphAttachedRawSql()` false negative | Low | Paragraph falsely marked orphan | Integration test: run both old and new, diff results |

### Rollback plan

Each fix can be rolled back independently:
- **Fix 1:** Remove `preloadFn` parameter, revert `processManagedFile()` to use
  inline queries (the `else` fallback branches are the rollback path)
- **Fix 2:** Remove Phase 2a batch operation, restore `findLocalFileLinkUsageBatch()`
  refill pattern in `scanOrphanFilesChunkNew()`, revert PHASE_MAP to 6 phases,
  clean up State keys
- **Fix 3:** Remove properties (no behavioral impact)
- **Fix 4:** Replace raw SQL with original EntityQuery + loadMultiple (6 lines)
- **Fix 5:** Remove `bulkResolveParagraphParents()` pre-warm; `processOrphanFile()`
  falls back to per-item `getParentFromParagraph()` automatically via cache miss

---

## Verification

### Fix 1 Verification — ✅ PASSED

1. **Data parity test on local dev:** ✅
   110 assets, 142 usage records — all categories match before/after.

2. **No per-item `file_usage` queries in `processManagedFile()`:** ✅
   When `$preloaded` is set, no `$this->database->select('file_usage'` calls.

3. **No `findMediaUsageViaEntityQuery()` calls when `$preloaded` is set:** ✅

4. **No entity loads in `findFileFieldName()` path when `$preloaded` is set:** ✅

5. **`processWithTimeBudget()` backward compatible:** ✅
   Phase 3–7 callers don't pass `preloadFn` — existing behavior unchanged.

6. **Unit tests pass:** ✅ All 299 tests.

7. **Production verified:** ✅ Site 1 — 100 items/cb (was 48–65), Phase 1 ~2 min.

### Fix 2 Verification — ✅ PASSED

1. **Orphan usage parity test:** ✅
   30,834 usage records match before/after on local dev.

2. **Phase 2 completes and stores State data:** ✅
   107 tables scanned in 1 callback (2.86s).

3. **Phase 3 reads State data, not LIKE queries:** ✅

4. **State cleanup after scan:** ✅
   `dai.scan.orphan_files` and `dai.scan.orphan_usage_map` deleted in
   `promoteTemporaryItems()` and `clearCheckpoint()`.

5. **PHASE_MAP has 7 entries:** ✅

6. **Production verified:** ✅ Site 1 — 15m 33s total (was 53 min).

### Fix 4+5 Verification (planned)

1. **Data parity test on local dev:**
   Run scan BEFORE changes, record all `digital_asset_item` + `digital_asset_usage`
   + `dai_orphan_reference` rows. Apply changes, re-scan. Diff — must match
   exactly (except `id`/`uuid`).

2. **Orphan paragraph count must match:**
   Compare "orphaned paragraphs skipped" count before/after.
   Current baseline: 129 orphaned paragraphs on Site 1.

3. **`bulkResolveParagraphParents()` matches `getParentFromParagraph()`:**
   On local dev, run both paths and compare results for all paragraph IDs.

4. **`isParagraphAttachedRawSql()` matches `isParagraphInEntityField()`:**
   Test with known attached and detached paragraphs.

5. **`paragraphRefFieldTableCache` populated correctly:**
   Cross-check against `field_storage_config` entities of type
   `entity_reference_revisions` targeting `paragraph`.

6. **Unit tests pass:** All 299 existing tests.

### Production Testing

```bash
# Clear state (adjust drush alias for your hosting)
drush ev "\Drupal::service('lock.persistent')->release('digital_asset_inventory_scan');"
drush ev "\Drupal::service('digital_asset_inventory.scanner')->clearCheckpoint();"
drush sqlq "DELETE FROM digital_asset_item WHERE is_temp = 1"
drush cr

# After scan, check logs
drush ws --count=100 --type=digital_asset_inventory

# Expected after Fix 4+5:
# Phase 1: 100 items per callback (~2 min)
# Phase 2 (index build): 107 tables in 1 callback (~3s)
# Phase 3 (orphan files): 100+ orphans per callback (~2-3 min, was 12 min)
# Total scan: ~5-7 min (was 15m 33s)
```

---

## Appendix A: Method Inventory

### New methods (Fix 1) — DEPLOYED ✅

| Method | Replaces | Cache property |
|---|---|---|
| `preloadManagedFileBatch()` | Orchestrates all pre-queries | — |
| `bulkQueryFileUsage()` | Per-item `file_usage` SELECT × 2 | — |
| `bulkQueryExistingTempItems()` | Per-item `digital_asset_item` SELECT | — |
| `getFileFieldTables()` | Per-item entity load in `findFileFieldName()` | `$fileFieldTableCache` |
| `bulkQueryFileFieldRefs()` | `findFileFieldName()` entity loads | — |
| `getMediaReferenceFieldTables()` | Per-item `field_storage_config` loads in `findMediaUsageViaEntityQuery()` | `$mediaRefFieldTableCache` |
| `bulkQueryMediaEntityRefs()` | Per-media EntityQueries in `findMediaUsageViaEntityQuery()` Part 1 | — |
| `bulkQueryMediaUuids()` | Per-media `$media->uuid()` calls | — |
| `bulkQueryMediaEmbedRefs()` | Per-media LIKE queries in `findMediaUsageViaEntityQuery()` Part 2 | — |

### New methods (Fix 2) — DEPLOYED ✅

| Method | Location | Replaces |
|---|---|---|
| `buildOrphanUsageIndex()` | Scanner | Phase 2 batch method — scans tables, builds usage map |
| `batchBuildOrphanUsageIndex()` | ScanAssetsForm | Phase 2 batch callback |

### New methods (Fix 4+5) — PLANNED

| Method | Location | Replaces |
|---|---|---|
| `bulkResolveParagraphParents()` | Scanner | Per-item `getParentFromParagraph()` entity loads |
| `getParagraphRefFieldTables()` | Scanner | Per-item `isParagraphInEntityField()` entity loads |
| `isParagraphAttachedRawSql()` | Scanner | `isParagraphInEntityField()` entity field iteration |

### Modified methods

| Method | Change | Status |
|---|---|---|
| `processWithTimeBudget()` | Add optional `?callable $preloadFn` parameter | ✅ |
| `processManagedFile()` | Add optional `?array $preloaded` parameter | ✅ |
| `scanManagedFilesChunk()` | Pass `preloadFn` closure | ✅ |
| `scanOrphanFilesChunkNew()` | Read usage map from State API; raw SQL temp lookup (Fix 4); pre-warm paragraph cache (Fix 5) | Partial (Fix 4+5 planned) |
| `ScanAssetsForm::PHASE_MAP` | 6 → 7 phases | ✅ |
| `ScanAssetsForm::buildBatch()` | Loop to `<= 7` | ✅ |

### New properties

| Property | Type | Cache lifetime | Status |
|---|---|---|---|
| `$fileFieldTableCache` | `?array` | Per PHP process (reset between scans) | ✅ |
| `$mediaRefFieldTableCache` | `?array` | Per PHP process (reset between scans) | ✅ |
| `$paragraphRefFieldTableCache` | `?array` | Per PHP process (reset between scans) | Planned |

### Unchanged methods (kept for backward compatibility / fallback)

| Method | Used by |
|---|---|
| `findDirectFileUsage()` | `processManagedFile()` fallback when `$preloaded` is NULL |
| `findFileFieldName()` | `findDirectFileUsage()` (fallback path only) |
| `findMediaUsageViaEntityQuery()` | `processManagedFile()` fallback; also used by Phase 5 `processRemoteMedia()` |
| `findLocalFileLinkUsage()` | `processOrphanFile()` fallback when `$pre_fetched_usage` is empty |
| `findLocalFileLinkUsageBatch()` | Not called from active pipeline after Fix 2; kept for potential direct use |
| `getParentFromParagraph()` | `processOrphanFile()` fallback via cache miss (after Fix 5, rarely called) |
| `isParagraphInEntityField()` | `getParentFromParagraph()` fallback path |
