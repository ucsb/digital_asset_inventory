# Orphan Reference Detection — Phase 2 Specification (Outline)

> **Status**: Outline — not implementation-ready. Requires validation of Phase 1 detection on multiple sites before proceeding. See [Phase 1 spec](orphan-reference-detection-phase1-spec.md) for the current implementation specification.

## Objective

Expand orphan detection beyond paragraph entities to prevent false "In Use" classifications caused by unreachable `block_content` and `media` entities. Introduce safe, permission-gated cleanup for paragraph orphans only.

Phase 2 maintains the same tri-state model established in Phase 1:

| Classification | Condition | Meaning |
| --- | --- | --- |
| **In Use** | `digital_asset_usage` rows exist | Asset is referenced by reachable content |
| **Orphan References Only** | No usage rows, but `dai_orphan_reference` rows exist | Asset is only referenced by unreachable entities |
| **Not In Use** | No usage rows and no orphan reference rows | Asset has no references anywhere |

## Phase Boundary Summary

| Phase | Capability |
| --- | --- |
| Phase 1 | Paragraph orphan detection + tri-state visibility (read-only) |
| Phase 2 | Block & media orphan detection + safe paragraph cleanup |
| Phase 3 | Taxonomy term, Layout Builder, uniqueness, bulk cleanup |

---

## 1. Scope

### Included in Phase 2

**A. Additional Orphan Source Entity Types:**

- `block_content` — custom blocks not placed or referenced anywhere
- `media` — media entities not referenced by any reachable content

**B. Cleanup Actions (Paragraph Only):**

- Delete orphan paragraph entities from the Orphan References tab
- Permission-gated via new `delete orphan paragraphs` permission
- Single-entity deletion only (no bulk delete initially)

### Explicitly Out of Scope

- Taxonomy term orphan detection
- Revision-based soft orphans (`unreachable_revision` context)
- Layout Builder deep graph traversal
- Cross-site orphan detection
- Uniqueness enforcement for orphan references (still optional)
- Bulk deletion UI

---

## 2. Detection Model Expansion

### 2.1 Generalized Reachability Rule

**Conservative bias**: An entity is considered reachable unless proven unreachable. When a resolver cannot determine reachability (e.g., entity not found, ambiguous state), default to reachable to prevent false orphan classifications.

An entity is considered **reachable** if:

- It is referenced by active content (via entity reference field, media embed, etc.)
- It is placed in an active, enabled block placement or layout section
- It is referenced by another reachable entity (transitive reachability)

An entity is considered **orphan** if:

- It exists in storage
- It is not reachable from any active content graph

**Reachability cascade**: When a host entity is orphan, all entities reachable only through that host are also orphan. For example, media referenced only by unreachable paragraphs is itself unreachable — the orphan classification propagates through the reference chain.

### 2.2 Reachability Decision Order

When resolving reachability for any entity type:

1. If entity not found → return `NULL` (no orphan ref to create)
2. If entity found and reachable → return reachable result
3. If entity found and not reachable → return unreachable result

The scanner must never assume reachability if a resolver returns `NULL`. A `NULL` return means the source entity does not exist — there is no entity to track as an orphan reference.

### 2.3 Core Invariant (Unchanged from Phase 1)

Orphan references never create `digital_asset_usage` rows. Only reachable references produce usage records.

---

## 3. Entity-Specific Reachability Rules

### 3.1 Block Content Orphans

**Reachable if** (any of):

- Placed via block placement config (block layout system) AND placement is enabled (status = TRUE, region is not empty)
- Referenced by Layout Builder section storage in active content
- Referenced via entity reference field in active content

**Orphan if**:

- Exists in storage but not placed or referenced anywhere
- Placed in config but disabled (status = FALSE) or has no region assignment

**Scanner behavior**: When an asset reference originates from `block_content`:

- If block is reachable → create `digital_asset_usage`
- If block is not reachable → create `dai_orphan_reference`

**`reference_context` values**:

- `unreachable_block` — block exists but not placed or referenced
- `missing_parent_entity` — block entity deleted but references remain

**Risk level**: Low — block placement is deterministic and queryable.

### 3.2 Media Orphans

**Reachable if** (any of):

- Referenced by any reachable content entity (via media reference field)
- Embedded via CKEditor `<drupal-media>` in reachable content
- Referenced in a reachable paragraph or block

**Orphan if**:

- Media entity exists but not referenced by any reachable content
- Media referenced only by unreachable entities (e.g., orphan paragraphs, unreachable blocks) — the orphan classification cascades through the reference chain

**Scanner behavior**: When an asset reference originates from `media`:

- If media is reachable → create `digital_asset_usage`
- If media is not reachable → create `dai_orphan_reference`

**`reference_context` values**:

- `unreachable_media` — media exists but not referenced by reachable content
- `missing_parent_entity` — media entity deleted but file remains

**Risk level**: Low — media reachability can be anchored to "has at least one reachable host reference" using existing usage data.

---

## 4. Scanner Architecture Update

### 4.1 Reachability Resolver Pattern

Create small internal resolvers, one per entity type:

```php
resolveParagraphReachability(int $entity_id): array  // Phase 1 (exists as getParentFromParagraph)
resolveBlockReachability(int $entity_id): array       // Phase 2
resolveMediaReachability(int $entity_id): array       // Phase 2
```

Each returns a standardized result:

```php
// Reachable:
['reachable' => TRUE, 'type' => string, 'id' => int]

// Orphan:
['reachable' => FALSE, 'context' => string, 'entity_id' => int]

// Not found:
NULL
```

**Resolver design constraints**:

- Resolvers should be as pure as practical — accept preloaded data when available to avoid repeated queries
- All lookups must use indexed fields (entity reference tables, block config keys, section storage)
- No `entity_load_multiple()` loops over all blocks or media — use targeted queries

**Scanner rules** (unchanged from Phase 1):

- Never create `digital_asset_usage` rows if `reachable === FALSE`
- Always create `dai_orphan_reference` if `reachable === FALSE` and `$asset_id` is known

### 4.2 File Changes

**`src/Service/DigitalAssetScanner.php`**:

- Refactor `getParentFromParagraph()` to delegate to `resolveParagraphReachability()` (backwards-compatible wrapper)
- Add `resolveBlockReachability()` — checks block placement config, Layout Builder references, and entity reference fields
- Add `resolveMediaReachability()` — checks media reference fields and CKEditor embeds across reachable content
- Update non-paragraph scan paths: wherever a usage row is created from `block_content` or `media`, add reachability check before creating usage vs orphan reference

### 4.3 Relationship to Phase 1 Call Sites

Phase 1 wires orphan detection at 8 paragraph-specific call sites. Phase 2 adds reachability checks at the points where `block_content` and `media` entities are encountered as asset sources — these are different code paths from the paragraph sites.

---

## 5. Cleanup Actions (Paragraph Only)

### 5.1 New Permission

```yaml
delete orphan paragraphs:
  title: 'Delete Orphan Paragraphs'
  description: 'Delete orphan paragraph entities from the digital asset inventory'
```

Separate from:

- `administer digital assets`
- `view digital asset orphan references`

### 5.2 Orphan References Tab Enhancement

Add action column to the Orphan References view:

| Source Type | ID | Context | Detected | Action |
| --- | --- | --- | --- | --- |
| paragraph | 1234 | detached_component | 2026-02-10 | Delete Paragraph |

Action column visible only when:

- `source_entity_type = 'paragraph'`
- User has `delete orphan paragraphs` permission

### 5.3 Delete Safety Rules

Deletion allowed only if:

- Paragraph is still unreachable at time of action (re-validate before delete)
- Paragraph has no reachable parent
- Paragraph is not referenced by another reachable paragraph

**Re-validation is mandatory**: A scan may have run since the orphan reference was recorded. The paragraph may have been re-attached to content. Always re-validate reachability immediately before deletion.

**Revision caveat**: Phase 2 cleanup operates on the default (current) revision only. The scanner does not check whether the paragraph is referenced in non-default revisions. If revision-based reachability is added in Phase 3, the cleanup logic must be updated accordingly. Document this limitation in the cleanup confirmation UI.

If reachable at delete time → block deletion with error message.

### 5.4 Context Column

The `reference_context` column becomes visible in Phase 2 (hidden in Phase 1):

| Context Value | Display Label |
| --- | --- |
| `missing_parent_entity` | Missing Parent |
| `detached_component` | Detached |
| `unreachable_block` | Unreachable Block |
| `unreachable_media` | Unreachable Media |

---

## 6. UI Adjustments

- No new filter states (tri-state filter unchanged)
- Orphan References tab gains Context column and Action column
- Context column shows human-readable labels (not raw values)
- Action column only appears for users with `delete orphan paragraphs` permission
- No changes to inventory "Active Usage" column or batch prefetch logic

---

## 7. Atomic Swap Behavior

No changes required. `dai_orphan_reference` continues to be:

- Deleted before usage records
- Deleted before asset items
- Guarded with `!empty()` checks on item IDs

---

## 8. Entity Schema Changes

### 8.1 `dai_orphan_reference` — No Schema Changes Required

The Phase 1 entity already supports:

- `source_entity_type` — accepts `'paragraph'`, `'block_content'`, `'media'`, etc.
- `reference_context` — accepts new values (`'unreachable_block'`, `'unreachable_media'`)

### 8.2 Optional Addition

`source_bundle` (string, 128) — stores the entity bundle for better triage/reporting (e.g., `'accordion'` for a paragraph type, `'basic'` for a block type). Not required for Phase 2 correctness but aids diagnostics. Evaluate after Phase 1 field experience.

---

## 9. Performance Requirements

- No N+1 queries introduced by new reachability checks
- Reachability checks must use indexed queries
- No full graph traversal per asset — use direct field/placement lookups
- Acceptable tradeoff: reachability re-validation during deletion may perform an extra query (single entity, not batch)
- No measurable scan regression (>5% runtime increase)

---

## 10. Files Summary (Estimated)

| File | Action | Purpose |
| ------ | -------- | --------- |
| `src/Service/DigitalAssetScanner.php` | MODIFY | Add `resolveBlockReachability()`, `resolveMediaReachability()`, wire at new call sites |
| `src/Form/OrphanCleanupForm.php` (or controller) | CREATE | Paragraph deletion confirmation + re-validation |
| `digital_asset_inventory.routing.yml` | MODIFY | Add cleanup route(s) |
| `digital_asset_inventory.permissions.yml` | MODIFY | Add `delete orphan paragraphs` permission |
| `config/install/views.view.dai_orphan_references.yml` | MODIFY | Add Context column, Action column |

---

## 11. Acceptance Criteria

### Detection

- Block-origin asset references from unreachable blocks never create `digital_asset_usage` rows
- Media-origin asset references from unreachable media never create `digital_asset_usage` rows
- Orphan references correctly recorded with appropriate `reference_context`
- No new `digital_asset_usage` rows with `entity_type='paragraph'` (Phase 1 regression guard maintained)

### Cleanup

- Orphan paragraph entities can be deleted from the Orphan References tab
- Deletion re-validates reachability immediately before executing
- Reachable paragraphs cannot be deleted (blocked with error)
- No FK violations occur during or after deletion

### UX

- Orphan References tab shows Context column with human-readable labels
- Delete action only appears for paragraph orphans (not block/media)
- Tri-state classification remains correct after cleanup
- Both tabs remain always present (no conditional display)

### Performance

- No measurable scan regression (>5% runtime increase)
- No N+1 queries in inventory display or orphan references view

---

## 11b. Recommended Implementation Split

Split Phase 2 into two implementation PRs to isolate detection from deletion:

**Phase 2A — Detection Only:**

- Add `resolveBlockReachability()` and `resolveMediaReachability()`
- Wire reachability checks at block/media scan paths
- Context column visible in Orphan References view
- No cleanup UI

**Phase 2B — Paragraph Cleanup:**

- `delete orphan paragraphs` permission
- Cleanup form with re-validation
- Action column in Orphan References view

Rationale: Detection errors are easier to debug without delete actions in the same diff. Phase 2A can be validated independently before cleanup is enabled.

---

## 11c. Kernel Test Cases

### Block Reachability Tests

- Block placed in active region → reachable
- Block placed but disabled (status = FALSE) → unreachable
- Block placed but region empty → unreachable
- Block referenced by Layout Builder section → reachable
- Block referenced by entity reference field → reachable
- Block exists but not placed or referenced → orphan

### Media Reachability Tests

- Media referenced by published node via media reference field → reachable
- Media embedded via `<drupal-media>` in reachable content → reachable
- Media referenced only by orphan paragraph → orphan (cascade)
- Media referenced only by unreachable block → orphan (cascade)
- Media entity exists but not referenced anywhere → orphan

### Cleanup Tests

- Orphan paragraph with `delete orphan paragraphs` permission → delete action visible
- Orphan paragraph without permission → delete action hidden
- Paragraph re-attached to content after scan → deletion blocked with error
- Deletion does not cause FK violations
- Orphan reference record removed after paragraph deletion

---

## 12. Risks & Safeguards

| Risk | Mitigation |
| --- | --- |
| False orphan classification | Default to reachable unless unreachable is proven; conservative checks |
| Deleting valid content | Re-validate reachability immediately before delete; block if reachable |
| Layout Builder edge cases | Excluded from Phase 2 deep detection; blocks placed via LB are checked via section storage |
| Duplicate orphan rows | Accepted until Phase 3 uniqueness enforcement |
| Media reachability complexity | Anchor to existing usage data — media is orphan only if zero reachable hosts reference it |
| Revision-referenced paragraphs | Cleanup operates on current revision only; document limitation in UI; defer revision checks to Phase 3 |

---

## 13. Expansion Risk Heatmap

Safest entity types to expand to, ordered by risk:

| Risk Level | Entity Type | Rationale |
| --- | --- | --- |
| **Low** | `paragraph` | Phase 1 — explicit parent field membership |
| **Low** | `block_content` | Usage via block placement config / layout sections; deterministic and queryable |
| **Low** | `media` | Referenced through fields; reachability anchored to "has at least one reachable host reference" |
| **Medium** | `menu_link_content` | Links can exist unused or disabled; "reachable" means "present in active menu + not disabled" |
| **Medium** | `taxonomy_term` | Referenced everywhere; "reachability" definition expensive without good indexing |
| **High** | Layout Builder components | Reachability lives in serialized section storage; edge cases with overrides, defaults, revisions |
| **High** | Config entities | Assets referenced in config are "system usage," not content reachability; may need separate classification |

**Recommended expansion order**: Paragraph → Block Content → Media → Menu Link Content → Taxonomy Term → Layout Builder → Config/other

---

## 14. Phase 3 Candidates (Future)

- Taxonomy term orphan detection
- Layout Builder unreachable component detection
- Revision-based soft orphan detection (`unreachable_revision` context)
- Uniqueness enforcement constraint on `dai_orphan_reference`
- Bulk delete with preview mode
- Scan run UUID tracking
- `source_bundle` field for diagnostic reporting

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
