# DigitalAssetScanner — Phase 3 Optimization Spec

**Status:** Ready for implementation
**Depends on:** [scan-bulk-reads-spec.md](scan-bulk-reads-spec.md) (deployed, baseline 15m 33s)
**File:** `src/Service/DigitalAssetScanner.php`

---

## Status

| Metric | Current (9f6019fc) | Target |
|---|---|---|
| Total scan time (7,486 assets) | 15 min 33s | ~5–6 min |
| Phase 3 (orphan processing) | ~12 min (77%) | ~1–2 min |
| Per-item write cost | ~0.45s total | ~0.02–0.05s amortized |
| Phase 3 throughput | ~22 items / 10s callback | ~100–200 items / 10s callback |

---

## Root Cause Analysis

### What the reverted commits proved

Three commits (49c7c4b3, 724c7d5a, 863b0c3d) targeted Phase 3 read-side
and serialization overhead. All three had **zero measured effect** — throughput
remained at 22 items per 10s callback. This established:

- Read-side optimizations (bulk paragraph resolution, raw SQL temp lookup)
  cannot move the needle because reads are <20% of per-item cost.
- Serialization optimizations (sandbox→instance properties, pre-captured
  filesize) cannot move the needle because sandbox overhead is negligible.
- Batch DELETEs cannot move the needle because the per-item DELETEs are
  mostly no-ops (0 rows affected for new items — near-instant).

### What actually costs 0.45s per item

Tracing through `processOrphanFile()`, the per-item SQL round-trips are:

| # | Operation | Method | RTT cost |
|---|---|---|---|
| 1 | INSERT or UPDATE asset item | `rawInsertAssetItem()` or `rawUpdateAssetItem()` | ~0.15s |
| 2 | DELETE old usage records | `rawDeleteUsageByAssetId()` | ~0.01s (no-op for new items) |
| 3 | DELETE old orphan refs | `rawDeleteOrphanRefsByAssetId()` | ~0.01s (no-op for new items) |
| 4 | Entity load for orphan ref bundle | `createOrphanReference()` → `->load()` | ~0.15s (when hit) |
| 5 | Entity API create+save orphan ref | `createOrphanReference()` → `->save()` | ~0.15s (when hit) |
| | **Total (no paragraph refs)** | | **~0.17s** |
| | **Total (with 1 paragraph orphan ref)** | | **~0.47s** |

Steps 4–5 fire only for orphan files that have text-link references from
paragraph entities with broken parent chains — i.e., orphan references from
deleted content. The variance between 0.17s and 0.47s per item explains
the averaged ~0.45s measurement.

### Drupal Insert builder reality

**Critical implementation detail:** Drupal's `Insert::execute()` in
`web/core/lib/Drupal/Core/Database/Query/Insert.php` does NOT produce
true multi-row `INSERT INTO ... VALUES (...), (...), (...)` SQL:

```php
$transaction = $this->connection->startTransaction();
foreach ($this->insertValues as $insert_values) {
    $stmt->execute($insert_values, $this->queryOptions);
}
// Transaction commits when $transaction goes out of scope.
```

It executes **N separate prepared-statement `execute()` calls within a single
transaction**. Each call is still a network round-trip to the DB server. However,
the shared transaction boundary + prepared statement reuse dramatically reduces
per-row cost:

| Approach | Per-row cost | 22 items | 100 items |
|---|---|---|---|
| Standalone INSERT (own transaction each) | ~0.15s | ~3.3s | ~15s |
| Drupal builder, multiple `->values()` (shared txn) | ~0.02s | ~0.44s | ~2.0s |
| Raw SQL multi-row INSERT string | ~0.5s total | ~0.5s | ~0.5s |

Evidence: the existing `flushUsageBuffer()` uses the Drupal builder approach
and achieves ~0.50s for 25 rows on managed hosting — confirmed in production.
The savings come from eliminating per-row BEGIN/COMMIT overhead and reusing
the prepared statement.

**The Drupal builder approach is sufficient.** True multi-row SQL would
require bypassing Drupal's abstraction layer and loses portability across
MySQL/PostgreSQL/SQLite. The builder approach delivers ~7× improvement per
row, which is enough to hit the target.

### Two hidden bottlenecks the original spec missed

#### 1. `createOrphanReference()` uses Entity API — `rawInsertOrphanReference()` is dead code

`createOrphanReference()` (line 7411) does two expensive operations per call:

```php
// Entity load just to get bundle name
$source_entity = $this->entityTypeManager
    ->getStorage($source_entity_type)->load($source_entity_id);

// Full Entity API create + save (hooks, validation, UUID, cache invalidation)
$this->entityTypeManager->getStorage('dai_orphan_reference')
    ->create([...])->save();
```

Meanwhile, `rawInsertOrphanReference()` at line 619 does the identical insert
via a single raw SQL query — **but is never called from any code path.** It was
added in commit 32bcc982 (bulk write optimization) but `createOrphanReference()`
was never updated to use it.

This affects all 13 call sites across the scanner, not just Phase 3.

**Impact:** For Phase 3 orphan files with paragraph-based text-link references
to deleted content, each orphan reference costs ~0.30s (entity load + Entity API
save) instead of ~0.15s (one raw SQL INSERT). If 20% of orphans per callback
hit this path with 1–2 orphan refs each, that consumes ~1.3–2.6s per callback.

#### 2. `existing_temp_map` loads full entity objects to extract IDs

In `scanOrphanFilesChunkNew()` (line 5893):

```php
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

Then in `processOrphanFile()`:
```php
$asset_id = (int) $existing_temp_map[$url_hash]->id();
```

Full entity objects are loaded, hydrated with field definitions, validated —
only to call `->id()`. A raw SQL query returning `url_hash => id` achieves
the same result without Entity API overhead.

---

## Implementation Plan

### Fix 0: Switch `createOrphanReference()` to raw SQL + buffer

**Priority: P0 — easiest win, biggest per-item savings for paragraph-heavy sites.**

This fix addresses the 13 call sites across the entire scanner, not just Phase 3.

#### 0a. Add orphan reference buffer property and methods

```php
/**
 * In-memory buffer of orphan reference records pending bulk INSERT.
 *
 * Flushed via flushOrphanRefBuffer() at the end of each batch callback.
 */
private array $orphanRefBuffer = [];
```

```php
/**
 * Adds an orphan reference record to the in-memory buffer.
 */
protected function bufferOrphanReference(
    int $asset_id,
    string $source_entity_type,
    int $source_entity_id,
    string $source_bundle,
    string $field_name,
    string $embed_method,
    string $reference_context,
): void {
    $this->orphanRefBuffer[] = [
        'asset_id' => $asset_id,
        'source_entity_type' => $source_entity_type,
        'source_entity_id' => $source_entity_id,
        'source_bundle' => $source_bundle,
        'field_name' => $field_name,
        'embed_method' => $embed_method,
        'reference_context' => $reference_context,
    ];
}
```

```php
/**
 * Flushes the orphan reference buffer to the database via bulk INSERT.
 *
 * Follows the same pattern as flushUsageBuffer(). Drupal's Insert builder
 * wraps multiple ->values() calls in a single transaction with a shared
 * prepared statement, reducing per-row cost from ~0.15s to ~0.02s.
 */
protected function flushOrphanRefBuffer(): void {
    if (empty($this->orphanRefBuffer)) {
        return;
    }

    $columns = [
        'uuid', 'asset_id', 'source_entity_type', 'source_entity_id',
        'source_bundle', 'field_name', 'embed_method', 'reference_context',
    ];

    $insert = $this->database->insert('dai_orphan_reference')->fields($columns);

    foreach ($this->orphanRefBuffer as $record) {
        $insert->values([
            'uuid' => \Drupal::service('uuid')->generate(),
            'asset_id' => $record['asset_id'],
            'source_entity_type' => $record['source_entity_type'],
            'source_entity_id' => $record['source_entity_id'],
            'source_bundle' => $record['source_bundle'],
            'field_name' => $record['field_name'],
            'embed_method' => $record['embed_method'],
            'reference_context' => $record['reference_context'],
        ]);
    }

    $insert->execute();
    $this->orphanRefBuffer = [];
}
```

#### 0b. Refactor `createOrphanReference()` to use buffer

Replace the current Entity API implementation:

```php
protected function createOrphanReference(
    int $asset_id,
    string $source_entity_type,
    int $source_entity_id,
    string $field_name = '',
    string $embed_method = 'field_reference',
    string $reference_context = 'detached_component'
): void {
    try {
        // Look up source entity bundle — lightweight raw SQL instead of
        // full entity load. The entity may not exist (missing_parent_entity
        // context), so bundle defaults to empty string.
        $source_bundle = '';
        try {
            $entity_type_def = $this->entityTypeManager->getDefinition($source_entity_type);
            $bundle_key = $entity_type_def->getKey('bundle');
            if ($bundle_key) {
                $data_table = $entity_type_def->getDataTable()
                    ?: $entity_type_def->getBaseTable();
                $id_key = $entity_type_def->getKey('id');
                if ($data_table && $id_key) {
                    $source_bundle = (string) $this->database
                        ->select($data_table, 'e')
                        ->fields('e', [$bundle_key])
                        ->condition($id_key, $source_entity_id)
                        ->range(0, 1)
                        ->execute()
                        ->fetchField();
                }
            }
            else {
                // Entity type has no bundle key (e.g., 'user') — bundle = entity type.
                $source_bundle = $source_entity_type;
            }
        }
        catch (\Exception $e) {
            // Entity type definition not available or table missing.
        }

        $this->bufferOrphanReference(
            $asset_id,
            $source_entity_type,
            $source_entity_id,
            $source_bundle,
            $field_name,
            $embed_method,
            $reference_context,
        );
    }
    catch (\Exception $e) {
        $this->logger->error('Failed to buffer orphan reference for asset @id: @error', [
            '@id' => $asset_id,
            '@error' => $e->getMessage(),
        ]);
    }
}
```

Key changes:
- Entity load `->load($source_entity_id)` replaced with raw SQL bundle lookup.
  The entity load was only used to call `->bundle()`. A single `SELECT type
  FROM paragraphs_item_field_data WHERE id = :id` is ~0.01s vs ~0.15s.
- Entity API `->create([...])->save()` replaced with `bufferOrphanReference()`.
  The buffer is flushed once per callback, not per item.

#### 0c. Add `flushOrphanRefBuffer()` calls to all batch callback methods

Add alongside existing `flushUsageBuffer()` calls in:

- `scanManagedFilesChunkNew()` (Phase 1)
- `scanOrphanFilesChunkNew()` (Phase 3)
- `scanContentChunkNew()` (Phase 4 — content scanning)
- `scanRemoteMediaChunkNew()` (Phase 5 — remote media)
- `scanMenuLinksChunkNew()` (Phase 6 — menu links)

Pattern:
```php
// Flush buffered records in bulk INSERTs.
$this->flushUsageBuffer();
$this->flushOrphanRefBuffer();
```

#### 0d. Expected impact

| Metric | Before | After |
|---|---|---|
| Per orphan-ref write cost | ~0.30s (entity load + save) | ~0.02s (buffered raw SQL) |
| Per callback with 5 orphan refs | ~1.5s on orphan refs | ~0.10s on orphan refs |
| Phase 3 throughput (paragraph-heavy) | ~22 items/10s | ~30–35 items/10s |

This fix alone won't hit the 100+ items/callback target because
`rawInsertAssetItem()` / `rawUpdateAssetItem()` still run per-item. But it
removes the highest per-item cost for the paragraph orphan-reference path
and benefits all 7 scan phases.

---

### Fix 1: Batch asset item INSERT via Drupal Insert builder

**Priority: P1 — highest-leverage write optimization for Phase 3.**

#### 1a. Two-pass architecture for `scanOrphanFilesChunkNew()`

The current architecture processes each item fully before moving to the next:

```
for each orphan:
    INSERT/UPDATE item  → need asset_id immediately
    DELETE old usage    → need asset_id
    DELETE old orphan refs → need asset_id
    buffer usage records   → need asset_id
    create orphan refs     → need asset_id
```

This forces per-item INSERT because `asset_id` (auto-increment) is needed
immediately for downstream writes. To batch inserts, restructure as two passes:

```
Pass 1 — Collect (CPU only, no DB writes):
    for each orphan:
        resolve metadata (mime, category, URL, etc.)
        resolve paragraph parents (uses paragraphParentCache)
        collect item fields keyed by url_hash
        collect usage records keyed by url_hash
        collect orphan ref records keyed by url_hash

Pass 2 — Flush (bulk DB writes):
    1. Bulk DELETE existing items:    DELETE FROM digital_asset_item
                                      WHERE url_hash IN (:hashes)
                                      AND source_type = 'filesystem_only'
                                      AND is_temp = 1
    2. Batch INSERT all items:        Drupal Insert builder with N ->values()
    3. Resolve IDs:                   SELECT id, url_hash FROM digital_asset_item
                                      WHERE url_hash IN (:hashes) AND is_temp = 1
    4. Remap usage + orphan refs:     Replace url_hash keys with resolved asset_ids
    5. Flush usage buffer:            flushUsageBuffer()  (existing method)
    6. Flush orphan ref buffer:       flushOrphanRefBuffer()  (from Fix 0)
```

**ID resolution strategy:** After batch INSERT, query back `url_hash → id`
mapping with one SELECT. This replaces the current `existing_temp_map` which
loads full entity objects via EntityQuery + loadMultiple.

#### 1b. New method: `processOrphanFileBatch()`

Replaces per-item `processOrphanFile()` calls in `scanOrphanFilesChunkNew()`.

```php
/**
 * Processes a batch of orphan files using two-pass bulk writes.
 *
 * Pass 1: Collects metadata, resolves paragraph parents, accumulates
 * item/usage/orphan-ref records in memory (keyed by url_hash).
 *
 * Pass 2: Bulk DELETE existing items, batch INSERT new items via Drupal
 * Insert builder (shared transaction), resolve auto-increment IDs,
 * remap and flush usage + orphan-ref buffers.
 *
 * @param array $orphan_batch
 *   Slice of orphan file info arrays from the orphan file list.
 * @param bool $is_temp
 *   Whether to create items as temporary.
 * @param array $orphan_usage_map
 *   Pre-built usage map from Phase 2 (url_hash => usage refs).
 */
protected function processOrphanFileBatch(array $orphan_batch, bool $is_temp, array $orphan_usage_map): void {
    if (empty($orphan_batch)) {
        return;
    }

    // ── Pass 1: Collect ──────────────────────────────────────────────

    $item_fields_by_hash = [];   // url_hash => [column => value]
    $usage_by_hash = [];         // url_hash => [[usage record], ...]
    $orphan_refs_by_hash = [];   // url_hash => [[orphan ref record], ...]
    $all_hashes = [];

    foreach ($orphan_batch as $file_info) {
        $file_path = $file_info['path'];
        $uri = $file_info['uri'];
        $filename = basename($file_path);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $url_hash = $file_info['url_hash'];
        $all_hashes[] = $url_hash;

        $filesize = file_exists($file_path) ? filesize($file_path) : 0;
        $mime = $this->extensionToMime($extension);
        $asset_type = $this->mapMimeToAssetType($mime);
        $category = $this->mapAssetTypeToCategory($asset_type);
        $sort_order = $this->getCategorySortOrder($category);

        try {
            $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
        }
        catch (\Exception $e) {
            $absolute_url = $uri;
        }

        $is_private = strpos($uri, 'private://') === 0;

        $item_fields_by_hash[$url_hash] = [
            'source_type' => 'filesystem_only',
            'url_hash' => $url_hash,
            'asset_type' => $asset_type,
            'category' => $category,
            'sort_order' => $sort_order,
            'file_path' => $absolute_url,
            'file_name' => $filename,
            'mime_type' => $mime,
            'filesize' => $filesize,
            'filesize_formatted' => $this->formatFileSize($filesize),
            'is_temp' => $is_temp ? 1 : 0,
            'is_private' => $is_private ? 1 : 0,
        ];

        // Resolve usage from pre-built index.
        $file_link_usage = $orphan_usage_map[$url_hash] ?? [];
        $seen_usage = [];

        foreach ($file_link_usage as $ref) {
            $parent_entity_type = $ref['entity_type'];
            $parent_entity_id = $ref['entity_id'];

            if ($parent_entity_type === 'paragraph') {
                if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
                    $this->paragraphParentCache[$parent_entity_id] =
                        $this->getParentFromParagraph($parent_entity_id);
                }
                $parent_info = $this->paragraphParentCache[$parent_entity_id];

                if ($parent_info && empty($parent_info['orphan'])) {
                    $parent_entity_type = $parent_info['type'];
                    $parent_entity_id = $parent_info['id'];
                }
                elseif ($parent_info && !empty($parent_info['orphan'])) {
                    // Collect orphan ref — asset_id resolved in Pass 2.
                    $orphan_refs_by_hash[$url_hash][] = [
                        'source_entity_type' => 'paragraph',
                        'source_entity_id' => $parent_entity_id,
                        'field_name' => $ref['field_name'],
                        'embed_method' => 'text_link',
                        'reference_context' => $parent_info['context'],
                    ];
                    continue;
                }
                else {
                    continue;
                }
            }

            $usage_key = $parent_entity_type . ':' . $parent_entity_id
                . ':' . $ref['field_name'];
            if (!isset($seen_usage[$usage_key])) {
                $seen_usage[$usage_key] = TRUE;
                $usage_by_hash[$url_hash][] = [
                    'entity_type' => $parent_entity_type,
                    'entity_id' => $parent_entity_id,
                    'field_name' => $ref['field_name'],
                    'embed_method' => 'text_link',
                ];
            }
        }
    }

    // ── Pass 2: Flush ────────────────────────────────────────────────

    // Step 1: Bulk DELETE existing items + their usage + orphan refs.
    // Uses url_hash (not asset_id) so we don't need to pre-query IDs.
    $existing_ids = $this->database->select('digital_asset_item', 'dai')
        ->fields('dai', ['id'])
        ->condition('url_hash', $all_hashes, 'IN')
        ->condition('source_type', 'filesystem_only')
        ->condition('is_temp', 1)
        ->execute()
        ->fetchCol();

    if (!empty($existing_ids)) {
        $this->database->delete('digital_asset_usage')
            ->condition('asset_id', $existing_ids, 'IN')
            ->execute();
        $this->database->delete('dai_orphan_reference')
            ->condition('asset_id', $existing_ids, 'IN')
            ->execute();
        $this->database->delete('digital_asset_item')
            ->condition('id', $existing_ids, 'IN')
            ->execute();
    }

    // Step 2: Batch INSERT all items via Drupal Insert builder.
    // The builder wraps N ->values() calls in a single transaction with a
    // shared prepared statement. Per-row cost: ~0.02s (vs ~0.15s standalone).
    $columns = array_merge(
        ['uuid', 'created', 'changed', 'active_use_csv', 'used_in_csv', 'location'],
        array_keys(reset($item_fields_by_hash)),
    );

    $now = \Drupal::time()->getRequestTime();
    $insert = $this->database->insert('digital_asset_item')->fields($columns);

    foreach ($item_fields_by_hash as $hash => $fields) {
        $insert->values(array_merge([
            'uuid' => \Drupal::service('uuid')->generate(),
            'created' => $now,
            'changed' => $now,
            'active_use_csv' => '',
            'used_in_csv' => '',
            'location' => '',
        ], $fields));
    }

    $insert->execute();

    // Step 3: Resolve auto-increment IDs via one SELECT.
    $id_map = $this->database->select('digital_asset_item', 'dai')
        ->fields('dai', ['url_hash', 'id'])
        ->condition('url_hash', $all_hashes, 'IN')
        ->condition('is_temp', 1)
        ->execute()
        ->fetchAllKeyed();  // url_hash => id

    // Step 4: Remap and buffer usage records with resolved asset_ids.
    foreach ($usage_by_hash as $hash => $records) {
        $asset_id = (int) ($id_map[$hash] ?? 0);
        if (!$asset_id) {
            continue;
        }
        foreach ($records as $record) {
            $this->bufferUsageRecord(array_merge(
                ['asset_id' => $asset_id],
                $record,
            ));
        }
    }

    // Step 5: Remap and buffer orphan ref records with resolved asset_ids.
    foreach ($orphan_refs_by_hash as $hash => $records) {
        $asset_id = (int) ($id_map[$hash] ?? 0);
        if (!$asset_id) {
            continue;
        }
        foreach ($records as $record) {
            // Bundle lookup for orphan ref — raw SQL, not entity load.
            $source_bundle = '';
            try {
                $entity_type_def = $this->entityTypeManager
                    ->getDefinition($record['source_entity_type']);
                $bundle_key = $entity_type_def->getKey('bundle');
                if ($bundle_key) {
                    $data_table = $entity_type_def->getDataTable()
                        ?: $entity_type_def->getBaseTable();
                    $id_key = $entity_type_def->getKey('id');
                    if ($data_table && $id_key) {
                        $source_bundle = (string) $this->database
                            ->select($data_table, 'e')
                            ->fields('e', [$bundle_key])
                            ->condition($id_key, $record['source_entity_id'])
                            ->range(0, 1)
                            ->execute()
                            ->fetchField();
                    }
                }
            }
            catch (\Exception $e) {
                // Entity type not available.
            }

            $this->bufferOrphanReference(
                $asset_id,
                $record['source_entity_type'],
                $record['source_entity_id'],
                $source_bundle,
                $record['field_name'],
                $record['embed_method'],
                $record['reference_context'],
            );
        }
    }

    // Step 6: Flush all buffered writes.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();
}
```

#### 1c. Refactored `scanOrphanFilesChunkNew()` using batch method

```php
public function scanOrphanFilesChunkNew(array &$context, bool $is_temp): void {
    $budget = $this->getBatchTimeBudget();
    $startTime = microtime(true);
    $itemsThisCallback = 0;

    // Read pre-built data from State API (built by Phase 2).
    if (!isset($context['sandbox']['orphan_files'])) {
        $context['sandbox']['orphan_files'] =
            $this->state->get('dai.scan.orphan_files', []);
        $context['sandbox']['orphan_usage_map'] =
            $this->state->get('dai.scan.orphan_usage_map', []);
        $context['sandbox']['orphan_index'] = 0;
        $context['sandbox']['orphan_total'] =
            count($context['sandbox']['orphan_files']);
    }

    $orphanFiles = $context['sandbox']['orphan_files'];
    $index = $context['sandbox']['orphan_index'];
    $total = $context['sandbox']['orphan_total'];
    $orphan_usage_map = $context['sandbox']['orphan_usage_map'];

    // Exhaustion guard.
    if ($index >= $total || empty($orphanFiles)) {
        $context['finished'] = 1;
        $context['results']['last_chunk_items'] = 0;
        return;
    }

    // Process in sub-batches sized for the time budget.
    // With bulk writes, each sub-batch of ~50-100 items takes ~2-3s.
    while ($index < $total) {
        if ((microtime(true) - $startTime) >= $budget) {
            break;
        }

        // Take a sub-batch from the current index.
        $remaining_budget = $budget - (microtime(true) - $startTime);
        // ~0.02s per item (Pass 1 metadata) + ~1s fixed flush cost.
        // Conservative: size sub-batch to leave 2s for flush.
        $sub_batch_size = min(
            (int) (($remaining_budget - 2.0) / 0.03),
            100,
            $total - $index,
        );
        if ($sub_batch_size < 1) {
            break;
        }

        $sub_batch = array_slice($orphanFiles, $index, $sub_batch_size);
        $this->processOrphanFileBatch($sub_batch, $is_temp, $orphan_usage_map);
        $this->maybeUpdateHeartbeat();

        $index += count($sub_batch);
        $itemsThisCallback += count($sub_batch);
    }

    $context['sandbox']['orphan_index'] = $index;

    // Progress.
    if ($total > 0) {
        $context['finished'] = $index / $total;
    }
    if ($index >= $total) {
        $context['finished'] = 1;
    }

    // Cache resets.
    $this->resetPhaseEntityCaches([
        'digital_asset_item', 'digital_asset_usage',
        'dai_orphan_reference', 'file',
    ]);
    if ($itemsThisCallback >= 50) {
        drupal_static_reset();
    }

    $context['results']['last_chunk_items'] = $itemsThisCallback;
}
```

#### 1d. Query count: before vs after

Per callback processing 100 items:

| Operation | Before (per-item) | After (batched) |
|---|---|---|
| Existing item check | 1 EntityQuery + 1 loadMultiple = 2 | 1 SELECT (IDs for DELETE) |
| DELETE old usage | 100 individual DELETEs | 1 bulk DELETE |
| DELETE old orphan refs | 100 individual DELETEs | 1 bulk DELETE |
| DELETE old items | 0 (update in-place) | 1 bulk DELETE |
| INSERT items | 100 individual INSERTs | 1 transaction (100 prepared stmt executions) |
| Resolve IDs | 0 (inline from insert) | 1 SELECT |
| Usage writes | 1 flushUsageBuffer | 1 flushUsageBuffer |
| Orphan ref writes | ~10 Entity API saves | 1 flushOrphanRefBuffer |
| **Total SQL round-trips** | **~310+** | **~7 + 100 (in-txn)** |
| **Estimated wall time** | **~10s (22 items)** | **~3s (100 items)** |

#### 1e. `existing_temp_map` eliminated

The current code loads full entity objects to build `existing_temp_map`:

```php
$existing_ids = $storage->getQuery()...->execute();
$existing_items = $storage->loadMultiple($existing_ids);
```

The two-pass approach replaces this entirely with the DELETE + re-INSERT
strategy. There is no need to check for existing items when the first step
deletes them. All items go through the INSERT path.

---

### Fix 2: Replace `existing_temp_map` EntityQuery with raw SQL (all other phases)

**Priority: P2 — cleanup for consistency across phases.**

Phase 3 no longer needs `existing_temp_map` after Fix 1. But the pattern
also exists in the legacy `scanOrphanFilesChunk()` method (line 5960+).

For the legacy method (kept for backward compatibility), replace:

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

With:

```php
$existing_temp_map = $this->database->select('digital_asset_item', 'dai')
    ->fields('dai', ['url_hash', 'id'])
    ->condition('url_hash', $orphan_hashes, 'IN')
    ->condition('source_type', 'filesystem_only')
    ->condition('is_temp', 1)
    ->execute()
    ->fetchAllKeyed();  // url_hash => id (int)
```

And in `processOrphanFile()`, update the existing-item branch:

```php
if (isset($existing_temp_map[$url_hash])) {
    $asset_id = (int) $existing_temp_map[$url_hash];  // was ->id()
```

---

### Fix 3: Delta scanning

**Priority: P3 — medium-term, implement after Fixes 0–2 are verified.**

#### Context: two types of orphans

The scanner detects two fundamentally different kinds of "orphan":

1. **Orphan files** (SFTP/manually uploaded): Files on the filesystem that
   are NOT in Drupal's `file_managed` table. Detected by `buildOrphanFileList()`
   comparing the directory listing against `file_managed` URIs. These files
   were never registered with Drupal — they exist because someone uploaded
   them via SFTP, rsync, or another non-Drupal mechanism.

2. **Orphan references** (from deleted content): References to assets that
   exist in text fields of paragraph entities whose parent chain is broken —
   the paragraph still exists but its parent node/block was deleted or the
   paragraph was removed from the parent's field. Detected by
   `getParentFromParagraph()` tracing the parent chain and finding
   `missing_parent_entity` or `detached_component` conditions.

These two types have different delta-scanning characteristics:

| Type | What changes | Delta-detectable? |
|---|---|---|
| Orphan files | New file appears on filesystem | ✅ Yes — file mtime or presence in directory listing |
| Orphan references | Content is deleted/revised | ⚠️ Partially — content deletion doesn't modify the paragraph or the file |

#### Delta scan design

```
┌──────────────────────────────────────────────────────────┐
│  Scan Mode Selection (Phase 0)                           │
│                                                          │
│  if state('dai.last_full_scan') is NULL                  │
│     OR state('dai.last_full_scan') > 7 days ago:         │
│    → Full scan (all phases, existing behavior)           │
│                                                          │
│  else:                                                   │
│    → Delta scan:                                         │
│      Phase 1: Only files WHERE changed > last_scan_time  │
│      Phase 2: Rebuild orphan usage index (always — 3s)   │
│      Phase 3: Only orphan files not already in inventory │
│      Phase 4+: Normal (already fast)                     │
│      Phase 7: CSV export for changed items only          │
│                                                          │
│  state('dai.last_full_scan') = now()                     │
└──────────────────────────────────────────────────────────┘
```

**Orphan reference gap:** A delta scan that skips already-inventoried orphan
files will NOT detect new orphan references caused by content deletion since
the last scan. Example: A node is deleted at time T. Its paragraph contained
a link to orphan file X which was already inventoried. File X now has an orphan
reference that should be recorded — but the file itself hasn't changed.

**Mitigation:** The weekly full scan catches this drift. For sites where
orphan reference accuracy is critical between full scans, Phase 3 can be
configured to always run in full mode while Phases 1/4–7 run in delta mode.
This is acceptable because after Fixes 0–1, Phase 3 processes 100+ items
per callback and completes in ~1–2 min even in full mode.

#### Decision

Implement after Fixes 0–2 are production-verified. Delta scanning is a
feature addition (new scan mode), not a performance fix for the existing
full-scan path. The combination of Fixes 0–1 should bring full-scan time
to ~5–6 min, which may make delta scanning a lower priority.

---

## Implementation Status

### Fix 0: `createOrphanReference()` → raw SQL + buffer

| Component | What changed |
|---|---|
| **New property** `$orphanRefBuffer` | In-memory buffer for orphan reference records pending bulk INSERT, analogous to existing `$usageBuffer` |
| **New method** `bufferOrphanReference()` | Collects orphan ref records in memory (7 fields: asset_id, source_entity_type, source_entity_id, source_bundle, field_name, embed_method, reference_context) |
| **New method** `flushOrphanRefBuffer()` | Bulk-inserts all buffered orphan refs via Drupal Insert builder (shared transaction + prepared statement). Clears buffer after flush. |
| **Refactored** `createOrphanReference()` | Entity API `->load()` for bundle lookup → raw SQL `SELECT type FROM data_table`. Entity API `->create()->save()` → `bufferOrphanReference()`. Affects all 13 call sites across 7 scan phases. |
| **Flush calls added (active pipeline)** | `scanManagedFilesChunk`, `scanContentChunkNew`, `scanOrphanFilesChunkNew` (via `processOrphanFileBatch`), `scanRemoteMediaChunkNew`, `scanMenuLinksChunkNew` |
| **Flush calls added (legacy)** | `scanManagedFilesChunkLegacy`, `scanContentChunk`, `scanOrphanFilesChunk`, `scanRemoteMediaChunk` |

### Fix 1: Two-pass batch architecture for Phase 3

| Component | What changed |
|---|---|
| **New method** `processOrphanFileBatch()` (~271 lines) | **Pass 1:** Collects metadata + resolves paragraph parents (CPU only, no DB writes). **Pass 2:** Transaction-wrapped bulk DELETE → batch INSERT → ID resolution → remap usage/orphan-ref buffers → flush. |
| **Refactored** `scanOrphanFilesChunkNew()` | Per-item loop + EntityQuery/loadMultiple `existing_temp_map` → sub-batch loop calling `processOrphanFileBatch()`. Adaptive sub-batch sizing based on remaining time budget. |
| **Transaction wrapping** | Pass 2 DELETE + INSERT + ID-resolve wrapped in `$this->database->startTransaction('orphan_batch')`. Drupal rolls back automatically on exception. |
| **Per-item fallback** | try/catch around Pass 2. On batch failure, falls back to per-item `rawInsertAssetItem()` with individual DELETE/INSERT per hash. Logs error + continues. |
| **Heartbeat** | `maybeUpdateHeartbeat()` called between Pass 1 and Pass 2 to prevent false stale-lock detection during long sub-batches. |
| **Eliminated** | EntityQuery + `loadMultiple()` for existing temp items (was loading full entity objects just to call `->id()`). Per-item `rawInsertAssetItem()` / `rawUpdateAssetItem()` / `rawDeleteUsageByAssetId()` / `rawDeleteOrphanRefsByAssetId()` in the Phase 3 hot path. |

### Concurrency & Interruption Safety Audit

| # | Scenario | Protection | Result |
|---|---|---|---|
| 1 | Concurrent user starts scan while Phase 3 running | `acquireScanLock()` → persistent DB lock (`lock.persistent`). Second user sees "scan already in progress" error. Form rebuilds with disabled buttons. | ✅ Blocked |
| 2 | User navigates away mid-scan (browser closes) | Batch API cannot detect browser close. Lock stays held. Heartbeat stops updating. After stale threshold (900s default), lock becomes stale and breakable via `buildForm` → `submitForm` flow. Temp items + checkpoint preserved. | ✅ Resume available |
| 3 | PHP timeout kills process mid-`processOrphanFileBatch` | Pass 2 wrapped in `startTransaction('orphan_batch')`. Drupal's `Transaction` destructor calls `rollBack()` if not committed. DELETEs + INSERTs roll back atomically. Items remain in pre-crash state. On resume, `scanOrphanFilesChunkNew` re-processes from last-saved `orphan_index` (cursor-based). `processOrphanFileBatch` does DELETE + re-INSERT (idempotent). | ✅ Transaction + cursor resume |
| 4 | DB connection drops during batch INSERT | try/catch around entire Pass 2. On exception, falls back to per-item `rawInsertAssetItem()` for each hash in `$item_fields_by_hash`. Each per-item insert has its own try/catch. Errors logged, scan continues. | ✅ Graceful degradation |
| 5 | User clicks Cancel during Batch API | `batchFinished($success=FALSE)` preserves checkpoint + temp items. Does NOT call `clearTemporaryItems()` or `clearCheckpoint()`. Releases lock. User sees "Scan interrupted. You can resume..." message. | ✅ Resume available |
| 6 | Fresh scan clears old temp data | `ACTION_FRESH` path calls `clearTemporaryItems()` (DELETE orphan refs → usage → items WHERE `is_temp=1`) then `clearCheckpoint()` (delete all State API keys). Clean slate for new scan. | ✅ Clean slate |
| 7 | Crash between transaction commit and usage/orphan-ref flush | Items exist in DB (transaction committed). Usage + orphan-ref buffers lost (PHP instance properties). On resume, sub-batch re-processed from last `orphan_index`. `processOrphanFileBatch` DELETEs the committed items and re-INSERTs them with fresh usage/orphan-refs. Same safety characteristics as existing `usageBuffer`. | ✅ Idempotent re-process |
| 8 | Heartbeat during long sub-batches | `maybeUpdateHeartbeat()` called between Pass 1 and Pass 2 in `processOrphanFileBatch()`, plus after each sub-batch in `scanOrphanFilesChunkNew()`. Sub-batch of 100 items ≈ 3s. Heartbeat interval: 2s. Stale threshold: 900s. No false stale detection possible. | ✅ No false stale |
| 9 | Resume re-processes items from crashed callback | `$context['sandbox']['orphan_index']` only persisted at callback end (line after while-loop). If callback crashes, Batch API doesn't save sandbox. On next callback, `orphan_index` is at value from previous *successful* callback. All sub-batches from crashed callback re-processed. DELETE + re-INSERT is idempotent — no duplicate items. | ✅ No duplicates |

### Expected Performance Impact

| Metric | Before (9f6019fc) | Expected after |
|---|---|---|
| Phase 3 SQL round-trips per callback | ~310+ (22 items × ~14 each) | ~7 fixed + 100 in-txn prepared stmt executions |
| Phase 3 items per 10s callback | ~22 | ~100–200 |
| Phase 3 duration (1,636 orphans) | ~12 min | ~1–2 min |
| Total scan time (7,486 assets) | 15 min 33s | ~5–6 min |
| Orphan ref write cost per item | ~0.30s (Entity API load + save) | ~0.02s (buffered raw SQL) |
| Asset item write cost per item | ~0.15s (standalone INSERT) | ~0.02s (shared transaction) |

---

## Deferred: Optimizations NOT Pursued

### Parallelize Phase 2 (Orphan Usage Index Build)

Phase 2 takes 3s. Not a bottleneck. Defer until site growth pushes above 30s.

### Increase Phase 3 chunk size (explicit tuning)

Not needed as a separate change. The two-pass architecture in Fix 1 naturally
processes larger batches — the sub-batch sizing loop adapts to the time budget.
With ~0.03s per item in Pass 1 and ~1s fixed flush cost, the loop automatically
sizes sub-batches at ~100 items.

### Sandbox→instance property migration for orphan data

Reverted commit 724c7d5a moved orphan file list and usage map from
`$context['sandbox']` to instance properties to avoid serialization overhead.
This had zero measured effect because the Batch API serialization cost is
negligible compared to DB write cost. Do not re-attempt.

### Pre-captured filesize in `buildOrphanFileList()`

Reverted commit 724c7d5a pre-captured filesize during Phase 2 to avoid
`file_exists()` + `filesize()` per orphan in Phase 3. This had zero measured
effect because filesystem I/O on managed hosting is fast (~1ms per call).
Do not re-attempt.

---

## Success Criteria

| Milestone | Metric | Pass Condition |
|---|---|---|
| Fix 0 complete | Orphan ref writes | All 13 `createOrphanReference()` sites use buffered raw SQL |
| Fix 0 complete | `rawInsertOrphanReference()` | Called via `flushOrphanRefBuffer()`, no longer dead code |
| Fix 1 complete | Phase 3 time | < 2 min on 1,636 orphans |
| Fix 1 complete | Phase 3 throughput | > 100 items / 10s callback |
| Fix 1 complete | Total scan time | < 6 min on 7,486 assets |
| Fixes 0–1 complete | Test suite | 299 tests pass, zero regressions |
| Fixes 0–1 complete | Data integrity | Scan output identical to baseline (item count, usage count, orphan ref count) |
| Fix 3 complete | Delta scan time | < 60s for < 100 changed files |
| Fix 3 complete | Full scan time | No regression from Fixes 0–1 |

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| `max_allowed_packet` exceeded on batch INSERT | Batch INSERT fails, items lost | Cap sub-batch at 100 items (~50KB). Default MySQL limit is 16MB. |
| Partial flush failure | Some items inserted, others lost | Wrap each flush in try/catch. On failure, fall back to per-item `rawInsertAssetItem()` for remaining items and log error. |
| ID resolution query returns incomplete results | Usage/orphan-ref records missing asset_id | Verify `count($id_map) === count($item_fields_by_hash)` after ID resolution. Log warning if mismatch. |
| `getParentFromParagraph()` entity loads in Pass 1 become new bottleneck | Per-item cost shifts from writes to reads | `paragraphParentCache` handles repeats within callback. If measurements show this dominates, implement bulk paragraph resolution (raw SQL as in reverted 49c7c4b3) as a follow-up — it was ineffective before only because writes dominated. |
| Drupal Insert builder changes behavior in future Drupal versions | Transaction wrapping or prepared statement reuse breaks | Builder API is stable since Drupal 8. If behavior changes, fall back to per-item `rawInsertAssetItem()`. |
| `createOrphanReference()` raw SQL bundle lookup fails for custom entity types | Empty `source_bundle` in orphan ref records | Acceptable — `source_bundle` is audit context, not functional. Empty string is a safe default. Current Entity API path also falls back to empty string when the entity doesn't exist. |

---

## Appendix A: Per-Item Cost Model (Updated)

Based on production measurements and Drupal core source analysis:

| Operation | Standalone cost | In-transaction cost | Notes |
|---|---|---|---|
| Single-row INSERT (own transaction) | ~0.15s | — | BEGIN + INSERT + COMMIT |
| Single-row INSERT (shared transaction) | — | ~0.02s | Prepared stmt reuse |
| Single-row UPDATE (own transaction) | ~0.15s | — | Same RTT as INSERT |
| Bulk DELETE ... IN (N items) | ~0.15s | — | 1 RTT regardless of N |
| Entity API `->load()` | ~0.15s | — | Full entity hydration |
| Entity API `->create()->save()` | ~0.15s | — | UUID + hooks + INSERT + cache |
| EntityQuery `->execute()` | ~0.01s | — | Simple SELECT |
| `file_exists()` on managed hosting | ~0.001s | — | Not a bottleneck |
| `fileUrlGenerator->generateAbsoluteString()` | ~0.001s | — | Not a bottleneck |
| Batch API sandbox serialization (300KB) | ~0.005s | — | Not a bottleneck |
| `flushUsageBuffer()` (25 rows) | ~0.50s | — | 25 in-txn executions |

**Key insight:** The ~0.15s per standalone SQL operation is dominated by
TCP round-trip time and per-transaction commit overhead on managed hosting.
Within a shared transaction, the commit overhead is paid once, reducing
per-row cost to ~0.02s. This is why the Drupal Insert builder with multiple
`->values()` calls is ~7× faster per row than individual inserts.

The three reverted commits targeted operations that cost ~0.001–0.005s per
item (filesystem I/O, serialization, URL generation). Even eliminating them
entirely would save <0.1s per callback — undetectable against the ~10s budget.

---

## Appendix B: Implementation Order

Fix 0 and Fix 1 should be deployed together in a single commit. Fix 0
alone would improve paragraph-heavy sites but not hit the throughput target.
Fix 1 alone would leave `createOrphanReference()` using Entity API in
the batch path. Together they address both write bottlenecks.

```
Fix 0: createOrphanReference() → raw SQL + buffer
  ↓
Fix 1: Two-pass batch architecture for Phase 3
  ↓
  Deploy + production verify (target: Phase 3 < 2 min)
  ↓
Fix 2: Cleanup legacy existing_temp_map (optional)
  ↓
Fix 3: Delta scanning (separate feature, after verification)
```

**Estimated effort:**
- Fix 0: 0.5 day (buffer + refactor createOrphanReference + add flush calls)
- Fix 1: 1 day (processOrphanFileBatch + refactored scanOrphanFilesChunkNew)
- Fix 2: 0.5 day (cleanup)
- Fix 3: 2–3 days (new feature with admin UI toggle)

---

## Appendix C: Verification Checklist

After implementation, verify:

1. **`createOrphanReference()` no longer calls `->load()` or `->save()`:**
   ```bash
   grep -A 30 'function createOrphanReference' src/Service/DigitalAssetScanner.php \
     | grep -c 'load\|->save()'
   # Expected: 0
   ```

2. **`rawInsertOrphanReference()` is no longer dead code:**
   ```bash
   grep -c 'rawInsertOrphanReference\|bufferOrphanReference\|flushOrphanRefBuffer' \
     src/Service/DigitalAssetScanner.php
   # Expected: 10+ (buffer calls from createOrphanReference + flush calls from callbacks)
   ```

3. **`flushOrphanRefBuffer()` called in all batch callback methods:**
   ```bash
   grep -B5 'flushOrphanRefBuffer' src/Service/DigitalAssetScanner.php \
     | grep 'function\|flushOrphanRef'
   # Expected: appears alongside flushUsageBuffer in 5+ methods
   ```

4. **`processOrphanFileBatch()` exists and is called:**
   ```bash
   grep -c 'processOrphanFileBatch' src/Service/DigitalAssetScanner.php
   # Expected: 2+ (definition + call from scanOrphanFilesChunkNew)
   ```

5. **No Entity API loads/saves in the Phase 3 hot path:**
   ```bash
   grep -A 200 'function processOrphanFileBatch' src/Service/DigitalAssetScanner.php \
     | grep -c 'loadMultiple\|->save()\|->create('
   # Expected: 0
   ```

6. **Data integrity after scan:**
   ```bash
   drush sqlq "SELECT COUNT(*) FROM digital_asset_item WHERE is_temp = 0"
   drush sqlq "SELECT COUNT(*) FROM digital_asset_usage"
   drush sqlq "SELECT COUNT(*) FROM dai_orphan_reference"
   # Compare against baseline scan output
   ```

7. **299 unit tests pass:**
   ```bash
   cd web && ../vendor/bin/phpunit -c core/phpunit.xml.dist \
     modules/custom/digital_asset_inventory/tests/src/Unit
   ```
