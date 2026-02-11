# Orphan Reference Detection Specification

## Overview

The Digital Asset Scanner traces paragraph entities through their parent chain and validates structural attachment at each hop. When a paragraph (or any paragraph-to-paragraph chain) is not currently attached within the entity graph being scanned, it is an **orphan**. Orphan paragraphs that reference digital assets can inflate "in use" counts and block editors from deleting files that have no reachable references.

**Definition of "reachable"**: A paragraph is reachable when it is currently present in its parent entity's paragraph field at every step in the chain, and usage is attributed to the root non-paragraph parent returned by `getParentFromParagraph()` (validated via `isParagraphInEntityField()` checks at each hop). Reachability is structural attachment, not visibility.

**Unpublished content policy**: Unpublished entities are still considered **reachable**. The scanner uses `accessCheck(FALSE)` and does not filter by publication status. An unpublished entity with an attached paragraph produces a `digital_asset_usage` row, not an orphan reference.

This specification introduces **reachability-based usage classification**: assets are classified as In Use, Orphan References Only, or Not In Use based on whether references come from reachable content or orphan entities.

**Key invariant**: Orphan references never create `digital_asset_usage` rows and therefore never classify an asset as "In Use."

## Problem Statement

DAI currently reports some files as "in use" because they are referenced by orphan paragraphs — paragraph entities not reachable from any active host content.

**Root cause**: The scanner's `getParentFromParagraph()` method (`DigitalAssetScanner.php`) only verifies paragraph attachment for `node` parents via `isParagraphInEntityField()`. Paragraphs parented by `block_content`, `taxonomy_term`, or other entity types skip verification, causing orphan paragraphs from those parents to be counted as valid usage.

**Additional bugs**: Two scanning paths (`scanContentChunk` inline external URLs and `processExternalUrl`) have missing `else { continue/return }` branches when `getParentFromParagraph()` returns NULL, allowing orphan paragraph references to create usage records with `entity_type='paragraph'`.

## Scope

### Phase 1 (This Spec): Detection & Visibility

Phase 1 detects orphan references originating from **paragraph entities only**. The data model is intentionally generic (`source_entity_type` field) to support additional source entity types (e.g., orphan `block_content`, `media`, layout builder components) in Phase 2+.

- Fix orphan detection gaps in the scanner's paragraph tracing
- Introduce `dai_orphan_reference` entity to track orphan references separately
- Replace binary usage filter (In Use / Not In Use) with tri-state (In Use / Orphan References Only / Not In Use)
- Display orphan status in inventory UI and CSV export
- Read-only orphan reference detail view (tabbed)
- Non-goal: Publication/visibility filtering (reachability is structural attachment, not public visibility)

### Phase 2 (Deferred): Cleanup & Expansion

- Delete orphan paragraph entities
- Bulk cleanup actions
- Uniqueness enforcement for orphan references
- Detection of orphan references from non-paragraph entity types (block_content, media, layout builder components)

## Tri-State Usage Classification

| Classification | Condition | Meaning |
| --- | --- | --- |
| **In Use** | `digital_asset_usage` rows exist | Asset is referenced by reachable content |
| **Orphan References Only** | No `digital_asset_usage` rows, but `dai_orphan_reference` rows exist | Asset is only referenced by unreachable orphan entities |
| **Not In Use** | No `digital_asset_usage` rows and no `dai_orphan_reference` rows | Asset has no references anywhere |

## Data Model

### `dai_orphan_reference` Entity

Content entity tracking orphan references to digital assets. Follows the `DigitalAssetArchiveNote` / `DigitalAssetUsage` pattern.

```php
@ContentEntityType(
  id = "dai_orphan_reference",
  label = "Digital Asset Orphan Reference",
  base_table = "dai_orphan_reference",
  admin_permission = "administer digital assets",
  handlers = {
    "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
    "views_data" = "Drupal\views\EntityViewsData"
  },
  entity_keys = { "id" = "id", "uuid" = "uuid" }
)
```

No revision support. Do not define `links` in the annotation and do not register a route provider — there must be no canonical/add/edit/delete routes. Views pages are the only UI. The `admin_permission` locks down any accidental entity access beyond Views pages.

### Entity Fields

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer (unsigned, auto) | yes | Primary key |
| `uuid` | uuid | yes | Inherited from parent |
| `asset_id` | entity_reference → `digital_asset_item` | yes | Logical FK — reference used for atomic swap cleanup |
| `source_entity_type` | string (128) | yes | e.g., `paragraph` |
| `source_entity_id` | integer (unsigned) | yes | Orphan entity ID |
| `source_revision_id` | integer (unsigned) | no | For future soft/hard distinction |
| `field_name` | string (128) | no | Field containing the reference |
| `embed_method` | string (32) | no | Mirrors usage embed_method |
| `reference_context` | string (32) | yes | Enum: see below |
| `detected_on` | created (timestamp) | yes | When detected — auto-populated by Drupal's `created` base field |

### `reference_context` Values

Stored for audit/debug purposes; the Phase 1 Orphan References view does not display this column (may be added in a future phase):

| Value | Meaning |
| --- | --- |
| `missing_parent_entity` | Parent entity does not exist |
| `detached_component` | Paragraph exists, parent exists, but paragraph not in parent's current fields |
| `unreachable_revision` | For future use (soft orphan) |
| `unreachable_layout` | For future use (Layout Builder) |

### Indexing

`asset_id` must be indexed on `dai_orphan_reference`. Implement the index using the same `BaseFieldDefinition` settings pattern already used on `digital_asset_usage.asset_id` (do not invent a new schema style). This is critical for:

- The `EXISTS` subquery in the tri-state filter
- The batch prefetch in `UsedInField`

Confirm `digital_asset_usage.asset_id` is also indexed (existing entity — verify).

### No Stored Orphan Count on `DigitalAssetItem`

Orphan counts are computed dynamically, not stored as a base field:

- **CSV export**: Views aggregate relationship (LEFT JOIN + COUNT), CSV display only
- **Views UI**: Batch prefetch in `UsedInField::preRender()`
- **`dai_orphan_reference`** is the single source of truth

Rationale: Storing a derived count creates drift risk (counts change without item save), increases write load during scan, and adds schema complexity.

### No `is_temp` Field

Orphan refs are cleaned up via `asset_id` logical FK during atomic swap — when old items are deleted, their orphan refs are deleted first.

## Scanner Changes

### Detection Gap Fix

**File**: `src/Service/DigitalAssetScanner.php`

Remove the node-only guard in `getParentFromParagraph()` so `isParagraphInEntityField()` runs for ALL parent entity types (node, block_content, taxonomy_term, etc.):

```php
// BEFORE:
if ($root_parent->getEntityTypeId() === 'node') {
    // ... verify attachment via isParagraphInEntityField()
}

// AFTER:
// No entity-type guard: always verify attachment via isParagraphInEntityField().
// The isParagraphInEntityField() method works for any entity type — it was just
// never called for non-node parents due to this guard.
```

### Silent Orphan Bug Fixes

Two scanning paths have missing `else` branches when `getParentFromParagraph()` returns NULL:

1. **`scanContentChunk` inline external URLs** (approx. line 1508): Missing `else { continue; }` allows orphan paragraph usage records
2. **`processExternalUrl`** (approx. line 1928): Missing `else { return 0; }` allows orphan paragraph usage records

Both must add orphan-aware branching:

```php
if ($parent_entity_type === 'paragraph') {
    $parent_info = $this->getParentFromParagraph($entity_id);
    if ($parent_info && empty($parent_info['orphan'])) {
        // Valid parent found.
        $parent_entity_type = $parent_info['type'];
        $parent_entity_id = $parent_info['id'];
    }
    elseif ($parent_info && !empty($parent_info['orphan'])) {
        // Orphan detected — create orphan ref, passing context from structured result.
        // Only call when $asset_id is known at this site.
        $this->createOrphanReference($asset_id, 'paragraph', $entity_id, '', 'field_reference', $parent_info['context']);
        continue;
    }
    else {
        // Paragraph not found (NULL) — skip.
        continue;
    }
}
```

### Structured Orphan Return from `getParentFromParagraph()`

Instead of using a mutable `$lastOrphanReason` property (hidden state, leaks across calls), `getParentFromParagraph()` returns structured results:

| Outcome | Return Value |
| --- | --- |
| Valid parent | `['type' => string, 'id' => int]` (unchanged) |
| Orphan detected | `['orphan' => TRUE, 'context' => string, 'paragraph_id' => int]` |
| Not found | `NULL` (paragraph doesn't exist — no orphan ref to create because the entity does not exist to identify) |

**Context values set at each detection point:**

| Detection Point | Context |
| --- | --- |
| Paragraph doesn't load | `NULL` (no entity to reference) |
| Parent is NULL | `'missing_parent_entity'` |
| Root paragraph not in entity field | `'detached_component'` |
| Nested paragraph not in parent | `'detached_component'` |
| Legacy fallback, not attached | `'detached_component'` |

`incrementOrphanCount()` is still called in all orphan cases (backwards-compatible scan summary).

### Caller Guard Invariant

**Invariant**: Orphan arrays are truthy; callers MUST treat `empty($parent_info['orphan'])` as the only valid-parent condition. Use `empty($parent_info['orphan'])` for the valid-parent check and `!empty($parent_info['orphan'])` for the orphan check at ALL 8 call sites. Do not mix with `isset()` — `empty()` is more defensive.

A bare `if ($parent_info)` would treat orphan results as valid parents — this is the primary correctness trap.

Standard caller pattern:

```php
$parent_info = $this->getParentFromParagraph($entity_id);
if ($parent_info && empty($parent_info['orphan'])) {
    // Valid parent — create usage record.
    $parent_entity_type = $parent_info['type'];
    $parent_entity_id = $parent_info['id'];
}
else {
    // Orphan or not found — skip usage, optionally create orphan ref.
    // Only call createOrphanReference() at call sites where $asset_id is resolved (see Call Site Map).
    if ($parent_info && !empty($parent_info['orphan'])) {
        $this->createOrphanReference($asset_id, 'paragraph', $entity_id, '', 'field_reference', $parent_info['context']);
    }
    continue;
}
```

### `createOrphanReference()` Helper

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
        $this->entityTypeManager->getStorage('dai_orphan_reference')->create([
            'asset_id' => $asset_id,
            'source_entity_type' => $source_entity_type,
            'source_entity_id' => $source_entity_id,
            'field_name' => $field_name,
            'embed_method' => $embed_method,
            'reference_context' => $reference_context,
        ])->save();
    }
    catch (\Exception $e) {
        $this->logger->error('Failed to create orphan reference for asset @id: @error', [
            '@id' => $asset_id,
            '@error' => $e->getMessage(),
        ]);
    }
}
```

**Critical**: Every orphan branch must pass `$parent_info['context']` as the `reference_context` argument — do NOT rely on the default `'detached_component'`. The structured return distinguishes `'missing_parent_entity'` from `'detached_component'`, and relying on the default would silently erase that distinction.

### Call Site Map

8 locations where `getParentFromParagraph()` is called:

| # | Location | `$asset_id` available? | Approach |
| --- | ---------- | ---------------------- | ---------- |
| 1 | Phase 1 direct file usage | Yes | Add orphan ref in `else` branch |
| 2 | Phase 1 media references | Yes | Add orphan ref in `else` branch |
| 3 | Phase 3 inline external URLs | Yes | Add orphan ref in new `else` branch |
| 4 | Phase 3 HTML5 embeds | No (returns before asset) | **Skip in Phase 1** — no `dai_orphan_reference` rows created; orphan count still incremented, usage correctly excluded |
| 5 | Phase 3 local file links | No (resolved later) | **Restructure**: move paragraph check after asset resolution |
| 6 | Phase 3 external URLs | Yes | Add orphan ref in new `else` branch |
| 7 | Phase 2 orphan file links | Yes | Add orphan ref in `else` branch |
| 8 | Phase 4 remote media | Yes | Add orphan ref in `else` branch |

**Site #5 restructure**: Move paragraph check after asset resolution so `$asset_id` is available. After restructure, site #5 MUST create orphan refs (it is no longer a "skip" site). Asset creation does not depend on parent info; a URL/file validation guard prevents creating assets for invalid URLs.

### Atomic Swap Extension

**Deletion order**: orphan references → usage records → asset items.

`promoteTemporaryItems()` and `clearTemporaryItems()` both add orphan ref deletion before usage deletion:

```php
// Deletion order safety: delete orphan refs before usage before items.
if (!empty($old_item_ids)) {
    $orphan_ref_storage = $this->entityTypeManager->getStorage('dai_orphan_reference');
    $orphan_query = $orphan_ref_storage->getQuery()
        ->condition('asset_id', $old_item_ids, 'IN')
        ->accessCheck(FALSE);
    $old_orphan_ids = $orphan_query->execute();
    if ($old_orphan_ids) {
        $orphan_ref_storage->delete($orphan_ref_storage->loadMultiple($old_orphan_ids));
    }
}
```

Both methods must guard with `!empty()` check on item IDs.

## UI Changes

### Inventory Filter: Tri-State

**File**: `src/Plugin/views/filter/DigitalAssetIsUsedFilter.php`

```php
'#title' => $this->t('Usage Status'),
'#options' => [
    'All' => $this->t('- Any -'),
    '1' => $this->t('In Use'),
    'orphan_only' => $this->t('Orphan References Only'),
    '0' => $this->t('Not In Use'),
],
```

The `'orphan_only'` query:

```php
"NOT EXISTS (SELECT 1 FROM {digital_asset_usage} dau WHERE dau.asset_id = " . $base_table . ".id) " .
"AND EXISTS (SELECT 1 FROM {dai_orphan_reference} dor WHERE dor.asset_id = " . $base_table . ".id)"
```

Uses `$this->ensureMyTable()` for safe table alias. The existing `'All'` key convention is preserved — matches existing `acceptExposedInput()` and `defineOptions()` logic.

### Inventory "Used In" Column

**File**: `src/Plugin/views/field/UsedInField.php`

When `usage_count === 0` and `orphan_count > 0`, display "Orphan references only (N)" linked to the orphan references tab.

**Batch prefetch** avoids N+1 queries:

```php
private static array $orphanCounts = [];

public function preRender(&$values) {
    $asset_ids = [];
    foreach ($values as $row) {
        if ($row->_entity) {
            $asset_ids[] = $row->_entity->id();
        }
    }
    if (!empty($asset_ids)) {
        $database = \Drupal::database();
        $results = $database->select('dai_orphan_reference', 'dor')
            ->fields('dor', ['asset_id'])
            ->condition('asset_id', $asset_ids, 'IN')
            ->groupBy('asset_id')
            ->addExpression('COUNT(*)', 'orphan_count')
            ->execute()
            ->fetchAllKeyed();
        self::$orphanCounts = $results;
    }
    else {
        self::$orphanCounts = [];
    }
}

// In render(), when $usage_count === 0:
self::$orphanCounts ??= [];
$orphan_count = (int) (self::$orphanCounts[$asset_id] ?? 0);
```

Link uses route-based generation:

```php
// Prefer the contextual filter's configured argument identifier (if set) over Views' default 'arg_0'.
// Confirm the parameter key in the generated route definition before shipping.
$url = Url::fromRoute('view.dai_orphan_references.page_1', ['arg_0' => $asset_id]);
```

### Asset Detail Page: Tabbed Interface

The existing usage detail page at `/admin/digital-asset-inventory/usage/{id}` becomes tabbed:

| Tab | Route | Content |
| --- | --- | --- |
| **Active Usage** (default) | `view.digital_asset_usage.page_1` (existing) | `digital_asset_usage` records |
| **Orphan References** | `view.dai_orphan_references.page_1` (new) | `dai_orphan_reference` records |

**Orphan References View** (`config/install/views.view.dai_orphan_references.yml`):

- Path: `/admin/digital-asset-inventory/usage/%/orphans`
- Base table: `dai_orphan_reference`
- Contextual filter: `asset_id` (from URL argument)
- Area plugin: Reuse `AssetInfoHeader` for consistent header
- Columns: Source Entity Type, Entity ID, Field Name, Detected Date
- Access: `view digital asset orphan references` permission
- Empty text: "No orphan references detected for this asset." (configured within the View's "No results behavior")

**Local tasks** (`digital_asset_inventory.links.task.yml`):

```yaml
digital_asset_inventory.usage_active:
  title: 'Active Usage'
  route_name: view.digital_asset_usage.page_1
  base_route: view.digital_asset_usage.page_1

digital_asset_inventory.usage_orphans:
  title: 'Orphan References'
  route_name: view.dai_orphan_references.page_1
  base_route: view.digital_asset_usage.page_1
```

**Both tabs always present** — no conditional display in Phase 1. Read-only in Phase 1. Phase 2 will add a "Delete orphan entity" action column.

### CSS

```css
.dai-usage-orphan-only {
  color: var(--dai-badge-warning-text, #6a4e00);
  font-style: italic;
}
```

### Scan Summary

**File**: `src/Form/ScanAssetsForm.php`

After scan completes, query `dai_orphan_reference` grouped by `source_entity_type`:

```text
orphan_reference_total: N
orphan_reference_by_type: { paragraph: X }
affected_asset_count: Z
```

Phase 1 only produces `paragraph` entries. The `by_type` breakdown is structured for future expansion when additional source entity types are added in Phase 2+.

### CSV Export

Add orphan reference count via Views aggregate relationship (LEFT JOIN + COUNT) in the CSV export display only. Enable Views aggregation only on the CSV display. Ensure only the orphan count is aggregated and the base row remains grouped by `digital_asset_item.id`; do not aggregate or group by additional fields that would multiply rows. CSV column header: `orphan_reference_count`.

## Permissions

**New permission** in `digital_asset_inventory.permissions.yml`:

```yaml
view digital asset orphan references:
  title: 'View Digital Asset Orphan References'
  description: 'View orphan paragraph reference details in the digital asset inventory'
```

**Views access**: The orphan references View must use `view digital asset orphan references` permission (not `administer digital assets`). The entity's `admin_permission` is a safety net, not the primary access gate.

## Update Hooks

| Hook | Purpose |
| --- | --- |
| `update_10047()` | Install `dai_orphan_reference` entity schema |
| `update_10048()` | Sync Views config for new filter options and CSV column |

## Uninstall

Updated deletion order in `hook_uninstall()`:

1. `dai_archive_note`
2. `dai_orphan_reference` ← NEW
3. `digital_asset_usage`
4. `digital_asset_item`
5. `digital_asset_archive`

## Files Summary

| File | Action | Purpose |
| ------ | -------- | --------- |
| `src/Entity/DigitalAssetOrphanReference.php` | CREATE | New entity |
| `config/install/views.view.dai_orphan_references.yml` | CREATE | Orphan references detail view |
| `digital_asset_inventory.links.task.yml` | CREATE | Local tasks for Active Usage / Orphan References tabs |
| `src/Service/DigitalAssetScanner.php` | MODIFY | Fix detection gap, fix 2 bugs, structured orphan context, orphan ref creation, extend atomic swap |
| `src/Plugin/views/filter/DigitalAssetIsUsedFilter.php` | MODIFY | Tri-state filter |
| `src/Plugin/views/field/UsedInField.php` | MODIFY | Batch prefetch orphan counts, show orphan status with link |
| `src/Form/ScanAssetsForm.php` | MODIFY | Enhanced scan summary with orphan breakdown |
| `config/install/views.view.digital_assets.yml` | MODIFY | Updated filter, CSV column |
| `digital_asset_inventory.permissions.yml` | MODIFY | New permission |
| `digital_asset_inventory.install` | MODIFY | Update hooks 10047-10048, uninstall order |
| `css/dai-admin.css` | MODIFY | Orphan status styling |

## Known Phase 1 Limitations

1. **HTML5 media orphan refs**: `processHtml5MediaEmbed` returns before asset creation — orphan refs cannot be created at this site. Orphan count is still incremented, and usage is correctly excluded. Will be addressed in Phase 2.
2. **No cleanup actions**: Editors see orphan references but cannot delete them. Deferred to Phase 2.
3. **Possible duplicate orphan rows**: Phase 1 accepts possible duplicate orphan rows if the scanner encounters the same orphan reference from multiple scan paths; counts may be inflated until Phase 2 uniqueness is added. Phase 1 correctness is defined as: no reachable usage is created from orphan sources, and at least one orphan ref is recorded when detection occurs (where `$asset_id` is available).

## Nested Paragraph Validation Matrix

These cases confirm `getParentFromParagraph()` handles paragraph-to-paragraph chains correctly. Assumptions: recursive walk to root, node-only guard removed, all callers use `empty($parent_info['orphan'])`.

### Case 1 — Fully Reachable Chain (Happy Path)

```text
Node (published or unpublished)
  └─ Paragraph A
       └─ Paragraph B
            └─ File reference
```

- B is attached to A, A is attached to Node, Node is reachable
- Scanner creates `digital_asset_usage`
- No orphan reference created
- Asset classified: **In Use**

Confirms: recursion works correctly.

### Case 2 — Detached Nested Paragraph (Soft Orphan)

```text
Node
  └─ Paragraph A

Paragraph B (exists in DB but no longer attached to A)
  └─ File reference
```

- `getParentFromParagraph(B)` finds parent A
- `isParagraphInEntityField(A, B)` returns FALSE
- Returns `['orphan' => TRUE, 'context' => 'detached_component']`
- Scanner does NOT create `digital_asset_usage`, creates `dai_orphan_reference`
- Asset classified: **Orphan References Only**

Confirms: nested attachment verification works.

### Case 3 — Missing Parent in Chain (Hard Orphan)

```text
Paragraph B
  └─ File reference

Parent Paragraph A was deleted.
```

- Parent load fails (NULL)
- Returns `['orphan' => TRUE, 'context' => 'missing_parent_entity']`
- Scanner does NOT create `digital_asset_usage`, creates `dai_orphan_reference`
- Asset classified: **Orphan References Only**

Confirms: hard orphan detection works.

### Case 4 — Broken Chain Higher Up

```text
Node
  └─ Paragraph A (exists but removed from node field)
       └─ Paragraph B
            └─ File reference
```

- B attached to A: TRUE
- A attached to Node: FALSE
- `getParentFromParagraph()` bubbles up failure
- Returns orphan result for B
- Scanner does NOT create `digital_asset_usage`, creates `dai_orphan_reference`
- Asset classified: **Orphan References Only**

Confirms: full chain validation works, not just immediate parent.

### Validation Summary

If all 4 cases behave as expected:

- Nested paragraph recursion works
- Soft and hard orphans are detected
- No false usage rows created
- Tri-state classification is accurate

## Acceptance Criteria

### Correctness

- Orphan paragraph references never create `digital_asset_usage` rows
- Orphan references are written to `dai_orphan_reference` with the correct `asset_id` and `reference_context`
- Non-node parents (block_content, taxonomy_term, etc.) go through attachment verification

### UX

- Inventory shows "Orphan references only (N)" when `usage_count=0` and `orphan_count>0`
- Views filter supports: Any / In Use / Orphan References Only / Not In Use
- Both tabs always present on usage detail page

### Performance

- No per-row orphan count queries in Views render (batch prefetch, no N+1)
- Scan runtime stays within existing envelope

## Verification

### Automated Tests

**Kernel tests** (extend `ScannerAtomicSwapKernelTest` or new `OrphanReferenceKernelTest`):

- Entity CRUD: create/load/delete `dai_orphan_reference`
- Atomic swap: `promoteTemporaryItems()` deletes orphan refs for old items, preserves new
- Atomic swap: `clearTemporaryItems()` deletes orphan refs for temp items
- Deletion order: orphan refs → usage → items (no FK violations)

**Unit tests**:

- `getParentFromParagraph()` returns structured orphan result with correct context
- `DigitalAssetIsUsedFilter` tri-state option validation

### Manual Testing

1. Run scan on site with paragraph content → verify orphan refs created
2. Check "Usage Status" filter has 4 options: Any, In Use, Orphan References Only, Not In Use
3. Verify "Orphan References Only" filter shows correct assets
4. Verify "Used In" column shows "Orphan references only (N)" linked to orphan tab
5. Verify CSV export includes orphan reference count
6. Verify scan summary includes orphan reference count by entity type
7. Run scan twice → verify old orphan refs are replaced (atomic swap)
8. Cancel scan mid-way → verify temp orphan refs are cleaned up
9. Click "Orphan references only (N)" link → navigates to Orphan References tab
10. Verify Orphan References tab shows: source entity type, entity ID, field name, detected date
11. Verify Active Usage tab still works as before
12. Verify both tabs show the same `AssetInfoHeader` at the top
13. Verify the Orphan References tab always appears and shows the empty-state message when no records exist

### Regression Testing

- No new `digital_asset_usage` rows may be created with `entity_type='paragraph'` (orphan paragraphs must never produce usage records)
- Existing "In Use" filter still works correctly
- Assets with real usage still show correct counts
- Archive workflow not affected (see Archive Impact below)
- Deletion safety unchanged (only "Not In Use" is safely deletable)

### Archive Impact

Phase 1 does not modify any archive code (`ArchiveService`, archive forms, archive entities, archive link routing). The archive system is fully isolated from orphan detection.

**Expected behavioral side effect**: After a Phase 1 rescan, assets that were previously "In Use" *solely because of orphan paragraph references* will reclassify to "Orphan References Only" or "Not In Use". This means:

- `ArchiveService::getUsageCount()` returns a lower count (orphan refs no longer inflate `digital_asset_usage`)
- `flag_usage` may flip from TRUE to FALSE on reconciliation
- Assets previously blocked from archiving (due to inflated orphan usage) become archivable
- `used_in_csv` will have fewer entries

This is a data-correctness fix: orphan-derived usage rows were never valid reachable usage. The "Orphan References Only" label in the inventory explains where the references went.

## PR Reviewer Checklist

Use these 10 checks to verify the implementation matches the spec.

- [ ] **1. Entity schema**: `dai_orphan_reference` entity exists with `admin_permission = "administer digital assets"`, no revision support, no entity links, and fields: `asset_id` (entity_reference, indexed), `source_entity_type`, `source_entity_id`, `source_revision_id`, `field_name`, `embed_method`, `reference_context`, `detected_on`. Confirm `asset_id` is indexed using the same pattern as `digital_asset_usage.asset_id`.

- [ ] **2. Detection gap closed**: `getParentFromParagraph()` calls `isParagraphInEntityField()` for ALL parent entity types, not just `node`. Verify the `if ($root_parent->getEntityTypeId() === 'node')` guard has been removed or broadened.

- [ ] **3. Structured return type**: `getParentFromParagraph()` returns `['type' => ..., 'id' => ...]` for valid parents, `['orphan' => TRUE, 'context' => ..., 'paragraph_id' => ...]` for orphans, or `NULL` for missing paragraphs. No mutable `$lastOrphanReason` property exists.

- [ ] **4. Caller guard consistency**: Grep for all `getParentFromParagraph()` call sites. Every caller uses `empty($parent_info['orphan'])` (not `!isset` or bare `if ($parent_info)`). No call site treats an orphan array as a valid parent.

- [ ] **5. Silent bugs fixed**: In `scanContentChunk` and `processExternalUrl`, verify the orphan/NULL branches `continue`/`return` instead of falling through to create usage records. No `digital_asset_usage` rows should have `entity_type='paragraph'` as a result of orphan paragraphs.

- [ ] **6. Context passthrough**: Every `createOrphanReference()` call passes `$parent_info['context']` as the `reference_context` argument. No call relies on the default `'detached_component'`. Calls only happen where `$asset_id` is available (see Call Site Map — site #4 skips creation).

- [ ] **7. Atomic swap integrity**: `promoteTemporaryItems()` and `clearTemporaryItems()` delete orphan refs BEFORE usage records BEFORE items. Both have `!empty($item_ids)` guards. Deletion order: `dai_orphan_reference` → `digital_asset_usage` → `digital_asset_item`.

- [ ] **8. Tri-state filter**: `DigitalAssetIsUsedFilter` has 4 options: Any (`'All'`), In Use (`'1'`), Orphan References Only (`'orphan_only'`), Not In Use (`'0'`). The `'orphan_only'` query uses `NOT EXISTS (usage) AND EXISTS (orphan_ref)`. `acceptExposedInput()` does not reject `'orphan_only'` as empty.

- [ ] **9. No N+1 in Views**: `UsedInField` uses `preRender()` to batch-fetch orphan counts in a single grouped query. `render()` reads from `self::$orphanCounts` (cast to `int`). No per-row DB queries for orphan counts.

- [ ] **10. Tabs always present**: Both "Active Usage" and "Orphan References" tabs appear on the usage detail page for ALL assets (not conditionally). Orphan References tab shows "No orphan references detected for this asset." via View empty text when no records exist. Links from inventory use `Url::fromRoute()`, not hardcoded paths.

## Phase 2 Readiness Trigger

Proceed to cleanup only after:

- Orphan detection validated on multiple Drupal 10/11 sites
- No unexpected reachability edge cases found
- Stakeholders confirm clarity of tri-state model
