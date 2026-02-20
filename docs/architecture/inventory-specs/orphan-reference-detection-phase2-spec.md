# Orphan Reference Detection — Phase 2 Specification

## Detection Expansion (No Manual Cleanup)

> **Status**: Implementation-ready (after Phase 1 validation)
> **Scope**: Detection only — no orphan deletion UI, no manual cleanup tools.
> See [Phase 1 spec](orphan-reference-detection-phase1-spec.md) for the current implementation.

## 1. Objective

Expand orphan detection beyond paragraph entities to prevent false "In Use" classifications caused by unreachable:

- `block_content` — custom blocks not placed or referenced anywhere
- `media` — media entities not referenced by any reachable content

Phase 2 maintains the tri-state model introduced in Phase 1 and does not introduce any manual cleanup actions.

Orphan references remain:

- Informational
- Non-blocking (do not prevent deletion)
- Automatically cleaned via scan refresh
- Not user-managed

## 2. Tri-State Model (Unchanged)

| Classification | Condition | Meaning |
| --- | --- | --- |
| **In Use** | `digital_asset_usage` rows exist | Referenced by reachable content |
| **Orphan References Only** | No usage rows, but `dai_orphan_reference` rows exist | Referenced only by unreachable entities |
| **Not In Use** | No usage rows and no orphan reference rows | No references anywhere |

**Core invariant (unchanged)**: Orphan references never create `digital_asset_usage` rows. Only reachable references produce usage records.

**Mixed-host invariant**: An asset can have both usage rows (from reachable hosts) and orphan reference rows (from unreachable hosts). The tri-state classification is determined by usage rows: if any exist, the asset is "In Use." Orphan references are informational alongside active usage, matching the existing "9 uses + 3 orphan references" UI pattern from Phase 1.

## 3. Phase Boundary Summary

| Phase | Capability |
| --- | --- |
| Phase 1 | Paragraph orphan detection + tri-state visibility (read-only) |
| Phase 2 | Block & media orphan detection (detection only, no cleanup) |
| Phase 3 | Taxonomy term, Layout Builder, uniqueness, optional admin tools |

## 4. Scope

### Included in Phase 2

#### A. Block Content Orphan Detection

- Detect unreachable `block_content` entities
- Classify asset references originating from unreachable blocks as orphan references
- Gate all three block_content discovery pipelines consistently

#### B. Media Orphan Detection

- Detect unreachable `media` entities
- Cascade orphan classification when media is referenced only by unreachable hosts
- Buffered decision pattern: collect all hosts, evaluate reachability, then write usage OR orphan refs

#### C. Paragraph→Block Cascade

- Extend existing paragraph orphan detection: when `getParentFromParagraph()` returns `block_content` as parent, gate with block reachability check
- Affects all 8 existing paragraph orphan call sites via new `resolveParentReachability()` helper

### Explicitly Out of Scope

- Manual orphan deletion or paragraph cleanup actions
- Bulk cleanup UI
- Taxonomy term orphan detection
- Layout Builder deep graph traversal (section storage deserialization)
- Revision-based orphan detection
- Uniqueness enforcement
- Cross-site detection

---

## 5. Reachability Model

### 5.1 General Reachability Rule

An entity is **reachable** if:

- Referenced by reachable content (regardless of publication status)
- Placed in an enabled block placement (structural placement, not visibility rules)
- Referenced by another reachable entity (transitive)

An entity is **orphan** if:

- Exists in storage
- Not reachable from any active content graph

### 5.2 Conservative Bias

Default to reachable unless proven unreachable. If reachability cannot be determined, do not create orphan reference rows.

### 5.3 Cascade Graph

Phase 2 introduces a reachability cascade where entity types depend on each other:

```
paragraph → parent entity → block_content? → placement/reference check
                         → node/taxonomy_term → always reachable (Phase 3 may revisit taxonomy_term)

media → host entities → node/taxonomy_term → always reachable
                     → paragraph → parent trace (Phase 1)
                     → block_content → placement/reference check (Phase 2)
```

**Evaluation order matters**: Block reachability must be resolved before media reachability, because media hosts may include block_content entities. This is a **scan-phase invariant** — any future refactoring (e.g., queue-based parallel scanning) must preserve this ordering or the cascade produces incorrect results.

---

## 6. Entity-Specific Rules

### 6.1 Block Content Reachability

**Reachable if** (any of):

- Placed via block placement config with `status = TRUE` and region assigned
- Referenced via entity reference field in reachable content (see §6.1.1 for query logic)
- Is a Layout Builder inline block (`reusable = FALSE`) — assumed reachable per conservative bias
- Referenced by another reachable entity

**Orphan if**:

- Exists but not placed anywhere
- Placement disabled (`status = FALSE`)
- Region empty
- Referenced only by unreachable entities

#### 6.1.1 Entity Reference Reachability Query

When checking whether a block is referenced by reachable content:

1. Query all field tables from `blockReferenceFields` cache (§7.3B) for `target_id = block_id`
2. For each referencing entity, check reachability:
   - `node` or `taxonomy_term` → immediately reachable (short-circuit: block is reachable)
   - `paragraph` → `getParentFromParagraph()` + `resolveParentReachability()` (full chain)
   - `block_content` → **not followed** (block→block references are theoretically possible but would require cycle detection; not followed per pipeline-completeness simplicity — if the referencing block is itself placed, its assets will be caught via its own pipeline; this is not a conservative-bias decision but a pragmatic one to avoid recursion)
3. If any referencing entity is reachable → block is reachable
4. If no referencing entity is reachable → block is unreachable

**No cycle guard needed**: Block→block references are not recursively followed. The resolver checks one level of entity references only.

**Layout Builder conservative bias**: Inline blocks created by Layout Builder (`reusable = FALSE`) are assumed reachable without section storage lookup. Deep Layout Builder traversal (deserializing section storage to verify placement) is deferred to Phase 3. This avoids false orphan positives for inline blocks while keeping scan performance acceptable.

**Scanner behavior**: When asset reference originates from `block_content`:

- If block reachable → create `digital_asset_usage`
- If block unreachable → create `dai_orphan_reference`

**`reference_context` values**:

- `unreachable_block` — block exists but not placed or referenced by reachable content
- `missing_parent_entity` — block entity deleted but references remain (reused from Phase 1)

### 6.2 Media Reachability

**Reachable if** (any of):

- Referenced by reachable content via media field
- Embedded via `<drupal-media>` in reachable content
- Referenced by reachable paragraph or block

**Orphan if**:

- Media entity exists but has no reachable hosts
- Referenced only by unreachable paragraphs or blocks

**Cascade rule**: If media is referenced only by orphan entities, media is also orphan. Media reachability must be evaluated after host entity reachability is resolved to prevent premature orphan classification.

**Mixed-host rule**: If a media entity is referenced by both reachable and unreachable hosts:

- Media is **reachable** (any reachable host short-circuits)
- Usage rows created for reachable hosts only
- Orphan reference rows created for unreachable hosts
- This is consistent with the existing "usage + orphans" display model

**Scanner behavior**: When asset reference originates from `media`:

- Collect all host references first (buffered decision)
- Evaluate reachability per host
- If any host reachable → media reachable → write usage rows for reachable hosts, orphan refs for unreachable hosts
- If no host reachable → media orphan → write orphan references only

**`reference_context` values**:

- `unreachable_media` — media exists but not referenced by reachable content
- `missing_parent_entity` — media entity deleted but file remains (reused from Phase 1)

---

## 7. Scanner Architecture

### 7.1 Resolver Pattern

New resolvers for Phase 2:

```php
resolveBlockReachability(int $id): ?array       // Phase 2
evaluateMediaHosts(int $asset_id, array $media_references): array  // Phase 2
resolveParentReachability(array $parent): array  // Phase 2 bridge
```

Return formats:

```php
// resolveBlockReachability():
// Reachable:
['reachable' => TRUE, 'type' => 'block_content', 'id' => int]
// Orphan:
['reachable' => FALSE, 'context' => string, 'entity_id' => int]
// Not found:
NULL

// evaluateMediaHosts() — see §7.5 for full specification

// resolveParentReachability():
// Reachable:
['reachable' => TRUE]
// Orphan:
['reachable' => FALSE, 'context' => string]
```

**Resolver design constraints**:

- Resolvers should be as pure as practical — accept preloaded data when available to avoid repeated queries
- All lookups must use indexed fields (entity reference tables, block config keys, section storage)
- No `entity_load_multiple()` loops over all blocks or media — use targeted queries

**Scanner rules**:

- Never create `digital_asset_usage` rows if `reachable === FALSE`
- Only create `dai_orphan_reference` rows when entity exists and is unreachable
- If resolver returns `NULL`, do not create orphan rows — a `NULL` return indicates the source entity no longer exists and should not produce orphan records

### 7.2 Backward Compatibility with Phase 1

**Do not modify `getParentFromParagraph()`.** The existing method returns the Phase 1 format (`['type' => ..., 'id' => ...]` for reachable parents, `['orphan' => TRUE, 'context' => ...]` for orphan paragraphs). This format is used at all 8 paragraph call sites and changing it would require a wide refactor.

Instead, add a **second-pass helper** `resolveParentReachability($parent)`:

```php
/**
 * Checks whether a resolved parent entity is itself reachable.
 *
 * Called after getParentFromParagraph() returns a non-orphan parent.
 * Applies Phase 2 reachability gates for block_content parents.
 *
 * @param array $parent
 *   Parent info from getParentFromParagraph(): ['type' => string, 'id' => int].
 *
 * @return array
 *   ['reachable' => TRUE] for reachable parents,
 *   ['reachable' => FALSE, 'context' => string] for unreachable parents.
 */
resolveParentReachability(array $parent): array
```

**Logic**:

- If `$parent['type'] === 'block_content'` → delegate to `resolveBlockReachability($parent['id'])`
- If `$parent['type']` is `node`, `taxonomy_term`, or any other type → return `['reachable' => TRUE]`
- If `resolveBlockReachability()` returns `NULL` (entity gone) → return `['reachable' => FALSE, 'context' => 'missing_parent_entity']`

This lets all 8 existing paragraph call sites add block reachability gating with minimal code change:

```php
// Existing Phase 1 pattern:
$parent_info = $this->getParentFromParagraph($entity_id);
if (!empty($parent_info['orphan'])) {
  $this->createOrphanReference(...);
  continue;
}

// Phase 2 addition (insert after existing orphan check):
$parent_reach = $this->resolveParentReachability($parent_info);
if ($parent_reach['reachable'] === FALSE) {
  $this->createOrphanReference(
    $asset_id, 'paragraph', $entity_id, $field_name,
    $embed_method, $parent_reach['context']
  );
  continue;
}
```

### 7.3 Scan-Level Caches

Two caches built once at scan start and held in scanner service state for the scan run:

#### A. Block Placement Map

```php
/** @var array<string, bool> UUID → is_placed */
protected array $blockPlacementMap;

/** @var array<int, string> block_content ID → UUID */
protected array $blockIdToUuidMap;
```

Built via `\Drupal::configFactory()->listAll('block.block.')` — iterating all block placement config entities:

- Load each config, extract plugin ID (format: `block_content:{uuid}`)
- Check `status === TRUE` and `region` is non-empty
- Store UUID → TRUE for placed blocks

The `blockIdToUuidMap` is built alongside the placement map by querying all `block_content` entities for their `id` → `uuid` mapping in a single query at scan start:

```php
// Single query: SELECT id, uuid FROM block_content
$this->blockIdToUuidMap = $this->database->select('block_content', 'bc')
  ->fields('bc', ['id', 'uuid'])
  ->execute()
  ->fetchAllKeyed();
```

**Why UUID**: Block placement config references block_content by UUID, not numeric ID. The `blockIdToUuidMap` avoids per-block entity loads in the resolver — the resolver looks up the UUID from the map, then checks the placement map. If a block ID is not in `blockIdToUuidMap`, the block entity no longer exists (resolver returns `NULL`).

#### B. Block Reference Field Map

```php
/** @var array<string, array> field_table_name → ['entity_type' => string, 'field_name' => string] */
protected array $blockReferenceFields;
```

Built by discovering entity reference field storage configs that target `block_content`:

- Query `field.storage.*` configs where `settings.target_type === 'block_content'`
- For each, derive the field data table name (e.g., `node__field_block_ref`) and store with entity type and field name
- Used by `resolveBlockReachability()` to find entities referencing a specific block (§6.1.1)

**Why at scan start**: Avoids repeated field config discovery per block. The set of fields targeting block_content is fixed for the scan duration.

### 7.4 Block Content Discovery Pipelines

Block content creates usage through three distinct pipelines. All three must be gated consistently:

#### Pipeline 1: `findDirectFileUsage()` (file_usage table) in `scanManagedFilesChunk()`

When `findDirectFileUsage()` returns a result with `entity_type = 'block_content'`:

The current call site (in the `foreach ($direct_file_usage as $ref)` loop in `scanManagedFilesChunk()`) only has a `paragraph` branch before falling through to usage creation. Phase 2 adds a `block_content` branch:

```php
foreach ($direct_file_usage as $ref) {
  $parent_entity_type = $ref['entity_type'];
  $parent_entity_id = $ref['entity_id'];

  // Phase 2: Gate block_content references.
  if ($parent_entity_type === 'block_content') {
    $block_reach = $this->resolveBlockReachability($parent_entity_id);
    if ($block_reach === NULL) {
      // Block entity gone, stale file_usage entry — skip.
      continue;
    }
    if ($block_reach['reachable'] === FALSE) {
      $this->createOrphanReference($asset_id, 'block_content', $parent_entity_id, $ref['field_name'], 'field_reference', $block_reach['context']);
      continue;
    }
    // Block is reachable — fall through to usage creation.
  }

  if ($parent_entity_type === 'paragraph') {
    // ... existing Phase 1 orphan check ...
    // ... Phase 2 addition: resolveParentReachability() after orphan check ...
  }

  // ... existing usage creation ...
}
```

**Implementation note**: Gating happens at the call site, not inside `findDirectFileUsage()`. The function's return contract is unchanged.

#### Pipeline 2: Content chunk field table scan (`block_content__*` tables) in `scanContentChunk()`

When a hit is found in a `block_content__*` field table during `scanContentChunk()`:

- Extract `entity_id` from the row
- Call `resolveBlockReachability($entity_id)`
- If reachable → create `digital_asset_usage` (existing behavior)
- If unreachable → create `dai_orphan_reference`

**Naming note**: "Content chunk" refers to scanner Phase 3 (`scanContentChunk()`), not orphan detection Phase 3.

#### Pipeline 3: Paragraph parent resolution

When `getParentFromParagraph()` returns `['type' => 'block_content', 'id' => X]`:

- Call `resolveParentReachability($parent_info)` (delegates to `resolveBlockReachability()`)
- If reachable → create `digital_asset_usage` (existing behavior)
- If unreachable → create `dai_orphan_reference` with `source_entity_type = 'paragraph'` (the orphan source is the paragraph, the unreachable parent is the block)

### 7.5 Media Reachability Evaluation

#### Buffered Decision Pattern

Media reachability cannot be determined incrementally. The scanner must:

1. **Collect** all host references for a media entity
2. **Evaluate** reachability per host:
   - `node` / `taxonomy_term` → reachable (short-circuit: media is reachable)
   - `paragraph` → `getParentFromParagraph()` + `resolveParentReachability()`
   - `block_content` → `resolveBlockReachability()`
3. **Decide** based on aggregation:
   - If any host reachable → media reachable
   - If no hosts reachable → media orphan
4. **Write** usage rows for reachable hosts, orphan references for unreachable hosts

This replaces the current pattern where usage rows are written immediately as hosts are discovered.

#### `evaluateMediaHosts()` Method

The buffered logic is encapsulated in a single method called from both managed and remote media paths:

```php
/**
 * Evaluates media host reachability and writes usage/orphan records.
 *
 * Implements the buffered decision pattern: collects all hosts, evaluates
 * reachability per host, then writes usage rows for reachable hosts and
 * orphan reference rows for unreachable hosts.
 *
 * @param int $asset_id
 *   The digital asset item ID.
 * @param array $media_references
 *   Array of host references, each with keys: entity_type, entity_id,
 *   field_name, method. Already deduplicated by caller.
 * @param \Drupal\Core\Entity\EntityStorageInterface $usage_storage
 *   The digital_asset_usage entity storage.
 *
 * @return void
 */
protected function evaluateMediaHosts(int $asset_id, array $media_references, $usage_storage): void
```

**Internal logic**:

```php
$reachable_hosts = [];
$unreachable_hosts = [];

foreach ($media_references as $ref) {
  $host_type = $ref['entity_type'];
  $host_id = $ref['entity_id'];

  if ($host_type === 'node' || $host_type === 'taxonomy_term') {
    // Immediately reachable — short-circuit.
    $reachable_hosts[] = $ref;
    continue;
  }

  if ($host_type === 'paragraph') {
    $parent_info = $this->getParentFromParagraph($host_id);
    if ($parent_info === NULL) {
      // Paragraph entity gone — skip entirely per scanner rules (§7.1):
      // NULL means entity no longer exists, do not create orphan rows.
      continue;
    }
    if ($parent_info && empty($parent_info['orphan'])) {
      $parent_reach = $this->resolveParentReachability($parent_info);
      if ($parent_reach['reachable']) {
        $reachable_hosts[] = $ref;
        continue;
      }
    }
    // Orphan paragraph or unreachable parent — classify as unreachable host.
    $unreachable_hosts[] = $ref;
    continue;
  }

  if ($host_type === 'block_content') {
    $block_reach = $this->resolveBlockReachability($host_id);
    if ($block_reach && $block_reach['reachable']) {
      $reachable_hosts[] = $ref;
    } else {
      $unreachable_hosts[] = $ref;
    }
    continue;
  }

  // Unknown host type — conservative bias: treat as reachable.
  $reachable_hosts[] = $ref;
}

// Write usage rows for reachable hosts.
foreach ($reachable_hosts as $ref) {
  // ... create digital_asset_usage (same dedup check as existing code) ...
}

// Write orphan refs for unreachable hosts.
foreach ($unreachable_hosts as $ref) {
  $ref_embed = (isset($ref['method']) && $ref['method'] === 'media_embed') ? 'drupal_media' : 'field_reference';
  $this->createOrphanReference($asset_id, $ref['entity_type'], $ref['entity_id'],
    $ref['field_name'] ?? 'media', $ref_embed, 'unreachable_media');
}
```

**Return value**: `evaluateMediaHosts()` is side-effecting (writes usage/orphan rows directly). No return value needed — callers don't need a summary.

#### Integration with `scanManagedFilesChunk()` (Managed Files Path)

The managed files path (line ~360) currently uses `findMediaUsageViaEntityQuery()` to find media hosts, deduplicates via `$unique_refs`, then writes usage per-host in a `foreach` loop.

**Before** (current pattern):

```php
$media_references = [];
foreach ($all_media_ids as $mid) {
  $refs = $this->findMediaUsageViaEntityQuery($mid);
  $media_references = array_merge($media_references, $refs);
}
// Deduplicate...
$unique_refs = [...];
$media_references = array_values($unique_refs);

foreach ($media_references as $ref) {
  // Paragraph orphan check inline...
  // Write usage immediately per host...
}
```

**After** (Phase 2 buffered pattern):

```php
$media_references = [];
foreach ($all_media_ids as $mid) {
  $refs = $this->findMediaUsageViaEntityQuery($mid);
  $media_references = array_merge($media_references, $refs);
}
// Existing deduplication preserved.
$unique_refs = [...];
$media_references = array_values($unique_refs);

// Replace per-host foreach with buffered evaluation.
$this->evaluateMediaHosts($asset_id, $media_references, $usage_storage);
```

The existing deduplication logic (`$unique_refs`) stays — it runs before `evaluateMediaHosts()`. The existing `old_usage_query` that clears usage records at the start of the media block also stays — it runs before the buffered evaluation.

#### Integration with `scanRemoteMediaChunk()` (Remote Media Path)

The remote media path (line ~4070) currently uses `findMediaUsageViaEntityQuery()` + `scanTextFieldsForMediaEmbed()`, merges and deduplicates, then writes usage per-host.

**After** (Phase 2):

```php
// Existing: merge findMediaUsageViaEntityQuery() + scanTextFieldsForMediaEmbed()
// Existing: deduplicate...
$media_references = [...];

// Replace per-host foreach with buffered evaluation.
$this->evaluateMediaHosts($asset_id, $media_references, $usage_storage);
```

Both paths use `findMediaUsageViaEntityQuery()` as the primary host discovery method. `findMediaReferencesDirectly()` is a separate method used internally; the buffered pattern applies at the call site level regardless of which discovery method is used.

### 7.6 Updated Call Site Map

8 locations where `getParentFromParagraph()` is called (excluding the definition at line 3177, the recursive self-call at line 3249, and the non-usage call at line 3401 in `isParagraphInCurrentRevision()`):

| # | Line | Method | Phase 2 Change |
| --- | --- | --- | --- |
| 1 | 302 | `scanManagedFilesChunk()` — direct file usage | Add `resolveParentReachability()` after orphan check |
| 2 | 381 | `scanManagedFilesChunk()` — media references | Replaced by `evaluateMediaHosts()` (buffered) |
| 3 | 1614 | `scanContentChunk()` — inline external URLs | Add `resolveParentReachability()` after orphan check |
| 4 | 1750 | `processHtml5MediaEmbed()` | Add `resolveParentReachability()` after orphan check |
| 5 | 1909 | `processLocalFileLink()` | Add `resolveParentReachability()` after orphan check |
| 6 | 2022 | `processExternalUrl()` | Add `resolveParentReachability()` after orphan check |
| 7 | 3782 | `scanOrphanFilesChunk()` — orphan file links | Add `resolveParentReachability()` after orphan check |
| 8 | 4091 | `scanRemoteMediaChunk()` — remote media refs | Replaced by `evaluateMediaHosts()` (buffered) |

**Sites #2 and #8**: These are inside the media host iteration loops that are replaced by `evaluateMediaHosts()`. The paragraph reachability check moves inside `evaluateMediaHosts()`, which handles both paragraph tracing and block reachability in one place.

**Sites #1, #3–#7**: Each adds a `resolveParentReachability()` call after the existing Phase 1 orphan check (the standard Phase 2 bridge pattern from §7.2).

### 7.7 File Changes

| File | Action | Purpose |
| --- | --- | --- |
| `src/Service/DigitalAssetScanner.php` | MODIFY | Add `resolveBlockReachability()`, `evaluateMediaHosts()`, `resolveParentReachability()`, scan-level caches (`buildBlockPlacementMap()`, `buildBlockReferenceFieldMap()`), block_content gate in Pipeline 1, wire `resolveParentReachability()` at 6 call sites, replace media host loops at 2 call sites with `evaluateMediaHosts()` |
| `config/install/views.view.dai_orphan_references.yml` | MODIFY | Add `reference_context` display labels for new values |
| `digital_asset_inventory.module` | MODIFY | Add human-readable labels for `unreachable_block`, `unreachable_media` in preprocess hook |
| `src/Form/ScanAssetsForm.php` | MODIFY | Scan summary reports block/media orphan types in `orphan_reference_by_type` breakdown |
| `digital_asset_inventory.install` | MODIFY | Update hook(s) to re-import `views.view.dai_orphan_references` config with new context labels (next available hook after `update_10060`) |

### 7.8 Implementation Order

1. **Block placement cache** — `buildBlockPlacementMap()` + `blockIdToUuidMap` called at scan start
2. **Block reference field cache** — `buildBlockReferenceFieldMap()` called at scan start
3. **`resolveBlockReachability()`** — placement map + entity reference check (§6.1.1) + Layout Builder `reusable` guard
4. **Gate Pipeline 1 (block_content branch)** — add `block_content` branch in `findDirectFileUsage()` call site in `scanManagedFilesChunk()`
5. **Gate Pipeline 2** — `block_content__*` field table hits in `scanContentChunk()`
6. **`resolveParentReachability()`** — bridge helper for paragraph call sites
7. **Gate Pipeline 3 + Pipeline 1 paragraph branch** — call sites #1, #3–#7 (add `resolveParentReachability()` after existing orphan check). Note: Pipeline 1 (call site #1) is touched twice — step 4 adds the `block_content` branch, step 7 adds `resolveParentReachability()` to its existing `paragraph` branch
8. **`evaluateMediaHosts()`** — buffered host aggregation with per-host reachability
9. **Wire media in `scanManagedFilesChunk()`** — replace per-host foreach with `evaluateMediaHosts()` (call site #2)
10. **Wire media in `scanRemoteMediaChunk()`** — replace per-host foreach with `evaluateMediaHosts()` (call site #8)
11. **Context labels** — `unreachable_block`, `unreachable_media` in module preprocess hook
12. **Scan summary** — update `ScanAssetsForm.php` to report block/media orphan types
13. **Kernel tests** — per section 13

---

## 8. UI Adjustments

### Orphan References Tab

The tab already displays (from Phase 1):

- Item Type (`source_entity_type` with human-readable labels)
- Item Category (`source_bundle`)
- Entity ID
- Field Name
- Reference Context (with human-readable labels)

Phase 2 adds new `reference_context` display labels:

| Context Value | Display Label |
| --- | --- |
| `missing_parent_entity` | Parent entity deleted |
| `detached_component` | Detached from parent |
| `unreachable_block` | Unreachable block (new) |
| `unreachable_media` | Unreachable media (new) |

No action column. No cleanup controls. Tri-state filter unchanged.

---

## 9. Deletion Policy (Unchanged)

- Assets with active usage → cannot be deleted
- Assets with orphan references only → can be deleted
- Orphan references do not block deletion
- Orphan reference rows are refreshed during subsequent scans

---

## 10. Schema

No schema changes required. The existing `dai_orphan_reference` entity supports:

- `source_entity_type` — accepts `'paragraph'`, `'block_content'`, `'media'`, etc.
- `source_bundle` — already exists (added in Phase 1, update_10051), stores entity bundle for triage/reporting
- `reference_context` — accepts new values (`'unreachable_block'`, `'unreachable_media'`)

---

## 11. Performance Requirements

- No N+1 queries
- Indexed lookups only
- No full entity loads per asset
- No measurable scan regression (>5%)
- Resolver logic must be query-based, not entity-load loops
- Resolver logic must avoid scanning all blocks or all media entities per scan run — use targeted queries per entity, not full-table sweeps
- Block placement map and reference field map built once per scan (not per entity)

---

## 12. Acceptance Criteria

### Detection

- Unreachable block-origin references never create `digital_asset_usage` rows
- Unreachable media-origin references never create `digital_asset_usage` rows
- Cascade behavior correct (media orphan when all hosts are orphan; media reachable when at least one host is reachable)
- Paragraph→block_content cascade correct (paragraph in unreachable block → orphan reference)
- Layout Builder inline blocks (`reusable = FALSE`) treated as reachable (conservative bias)
- No regression in paragraph detection
- Tri-state classification correct
- Mixed-host media: usage rows for reachable hosts, orphan refs for unreachable hosts

### UX

- New context labels visible in Orphan References tab
- No delete action available
- Active Usage logic unchanged
- Orphan references remain informational

### Performance

- Scan runtime regression <5%
- No additional N+1 queries
- Block placement map built once per scan
- Block reference field map built once per scan
- Inventory view performance unaffected

---

## 13. Kernel Test Coverage

### Block Reachability Tests

- Block placed in active region → reachable
- Block placed but disabled (`status = FALSE`) → orphan
- Block placed but region empty → orphan
- Block referenced by entity reference field from reachable node → reachable
- Block exists but not placed or referenced → orphan
- Block referenced only by orphan paragraph (via parent field) → orphan (cascade)
- Block referenced only by orphan paragraph (via entity_reference field) → orphan (cascade through entity reference)
- Block entity deleted but stale `file_usage` entry remains → `resolveBlockReachability()` returns NULL → no orphan row, no usage row (skip silently)
- Layout Builder inline block (`reusable = FALSE`) → reachable (conservative bias)

### Media Reachability Tests

- Media referenced by reachable node via media reference field → reachable
- Media embedded via `<drupal-media>` in reachable content → reachable
- Media referenced only by orphan paragraph → orphan (cascade)
- Media referenced only by unreachable block → orphan (cascade)
- Media entity exists but not referenced anywhere → orphan
- Media referenced by both reachable node and unreachable block → reachable (mixed hosts, orphan refs for unreachable)

### Paragraph→Block Cascade Tests

- Paragraph parent is placed block_content → reachable (usage created)
- Paragraph parent is unplaced block_content → orphan reference created
- Paragraph parent is node → reachable (unchanged from Phase 1)
- Paragraph parent is taxonomy_term → reachable (unchanged from Phase 1)

### Buffered Decision Pattern Tests

- `evaluateMediaHosts()` with mixed reachable/unreachable hosts → usage rows for reachable, orphan refs for unreachable
- `evaluateMediaHosts()` with all unreachable hosts → orphan refs only, no usage rows
- `evaluateMediaHosts()` with empty references → no rows written
- `evaluateMediaHosts()` with unknown host type → treated as reachable (conservative bias)

### Regression Tests

- Paragraph detection unchanged (all 8 call sites still work correctly)
- No usage rows created for orphan entities
- Orphan references correctly categorized with `reference_context`
- `getParentFromParagraph()` return format unchanged
- Existing deduplication logic preserved in media paths

---

## 14. Risk Mitigation

| Risk | Mitigation |
| --- | --- |
| False orphan classification | Conservative reachability bias — default to reachable |
| Layout Builder inline blocks | `reusable = FALSE` → assume reachable; deep traversal deferred to Phase 3 |
| Circular detection logic | Block→block entity references not recursively followed (§6.1.1); reachability anchored to host graph, not usage rows |
| Media evaluation ordering | Block reachability resolved before media reachability (cascade order); documented as scan-phase invariant |
| `findDirectFileUsage()` return contract change | Gate at call site, not inside function; preserve backward compatibility |
| `getParentFromParagraph()` refactor risk | Do not modify; add `resolveParentReachability()` as second-pass helper |
| Duplicate orphan rows | Accepted (uniqueness deferred to Phase 3); Phase 2 increases duplicate surface area due to multiple pipelines — monitor counts in testing but do not block on this |
| Media reachability complexity | Encapsulated in `evaluateMediaHosts()`; anchor reachability to the host entity graph (nodes, blocks, paragraphs), not to usage rows |
| Block placement map stale during scan | Acceptable — map reflects state at scan start; config changes during scan are rare. If scanner is parallelized in future, cache must be shared or rebuilt per worker |
| Entity reference field discovery overhead | Built once at scan start; fixed for scan duration |
| `findMediaUsageViaEntityQuery()` vs `findMediaReferencesDirectly()` confusion | Both methods find media hosts; the buffered pattern applies at the call site level via `evaluateMediaHosts()` regardless of which discovery method the caller uses |

---

## 15. Expansion Risk Heatmap

Safest entity types to expand to, ordered by risk:

| Risk Level | Entity Type | Rationale |
| --- | --- | --- |
| **Low** | `paragraph` | Phase 1 — explicit parent field membership |
| **Low** | `block_content` | Usage via block placement config / layout sections; deterministic and queryable |
| **Low** | `media` | Referenced through fields; reachability anchored to host entity graph evaluation |
| **Medium** | `menu_link_content` | Links can exist unused or disabled; "reachable" means "present in active menu + not disabled" |
| **Medium** | `taxonomy_term` | Referenced everywhere; "reachability" definition expensive without good indexing. Currently treated as always reachable (§5.3); Phase 3 may revisit |
| **High** | Layout Builder components | Reachability lives in serialized section storage; edge cases with overrides, defaults, revisions |
| **High** | Config entities | Assets referenced in config are "system usage," not content reachability; may need separate classification |

---

## 16. Phase 3 Candidates

- Taxonomy term orphan detection
- Layout Builder deep graph traversal (section storage deserialization for inline block verification)
- Revision-based orphan detection (`unreachable_revision` context)
- Uniqueness enforcement for orphan references
- Optional admin maintenance tools
- Scan run UUID tracking

---

## Prerequisites

Phase 2 should not begin until:

- Phase 1 orphan detection validated on multiple Drupal 10/11 sites, including:
  - Large content site (500+ nodes)
  - Layout Builder site
  - Media-heavy site
  - Paragraph-heavy site (3+ nesting levels)
  - Multisite environment
- No unexpected reachability edge cases found in paragraph detection
- Stakeholders confirm clarity of tri-state model
- Phase 1 PR reviewer checklist items all verified
