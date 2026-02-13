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

#### B. Media Orphan Detection

- Detect unreachable `media` entities
- Cascade orphan classification when media is referenced only by unreachable hosts

### Explicitly Out of Scope

- Manual orphan deletion or paragraph cleanup actions
- Bulk cleanup UI
- Taxonomy term orphan detection
- Layout Builder deep graph traversal
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

---

## 6. Entity-Specific Rules

### 6.1 Block Content Reachability

**Reachable if** (any of):

- Placed via block placement config with `status = TRUE` and region assigned
- Referenced via Layout Builder section storage in active content
- Referenced via entity reference field in reachable content
- Referenced by another reachable entity

**Orphan if**:

- Exists but not placed anywhere
- Placement disabled (`status = FALSE`)
- Region empty
- Referenced only by unreachable entities

**Scanner behavior**: When asset reference originates from `block_content`:

- If block reachable → create `digital_asset_usage`
- If block unreachable → create `dai_orphan_reference`

**`reference_context` values**:

- `unreachable_block` — block exists but not placed or referenced
- `missing_parent_entity` — block entity deleted but references remain

### 6.2 Media Reachability

**Reachable if** (any of):

- Referenced by reachable content via media field
- Embedded via `<drupal-media>` in reachable content
- Referenced by reachable paragraph or block

**Orphan if**:

- Media entity exists but has no reachable hosts
- Referenced only by unreachable paragraphs or blocks

**Cascade rule**: If media is referenced only by orphan entities, media is also orphan. Media reachability must be evaluated after host entity reachability is resolved to prevent premature orphan classification.

**Scanner behavior**: When asset reference originates from `media`:

- If media reachable → create `digital_asset_usage`
- If media unreachable → create `dai_orphan_reference`

**`reference_context` values**:

- `unreachable_media` — media exists but not referenced by reachable content
- `missing_parent_entity` — media entity deleted but file remains

---

## 7. Scanner Architecture

### 7.1 Resolver Pattern

Introduce resolvers per entity type:

```php
resolveParagraphReachability(int $id): ?array  // Phase 1 (exists as getParentFromParagraph)
resolveBlockReachability(int $id): ?array       // Phase 2
resolveMediaReachability(int $id): ?array       // Phase 2
```

Return format:

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

**Scanner rules**:

- Never create `digital_asset_usage` rows if `reachable === FALSE`
- Only create `dai_orphan_reference` rows when entity exists and is unreachable
- If resolver returns `NULL`, do not create orphan rows — a `NULL` return indicates the source entity no longer exists and should not produce orphan records

### 7.2 Relationship to Phase 1

Phase 1 wires orphan detection at 8 paragraph-specific call sites using `getParentFromParagraph()`. Phase 2 adds reachability checks at the points where `block_content` and `media` entities are encountered as asset sources — these are different code paths from the paragraph sites.

The existing `getParentFromParagraph()` uses a different return format (`['orphan' => TRUE, 'context' => ...]` for orphans). Phase 2 resolvers use the standardized `['reachable' => FALSE, ...]` format. A backwards-compatible wrapper can bridge `getParentFromParagraph()` to the resolver pattern, or the Phase 1 format can be retained for paragraph call sites. Either approach is acceptable as long as the scanner rules above are followed.

### 7.3 File Changes

| File | Action | Purpose |
| --- | --- | --- |
| `src/Service/DigitalAssetScanner.php` | MODIFY | Add `resolveBlockReachability()`, `resolveMediaReachability()`, wire at new call sites |
| `config/install/views.view.dai_orphan_references.yml` | MODIFY | Add `reference_context` display labels for new values |
| `digital_asset_inventory.module` | MODIFY | Add human-readable labels for `unreachable_block`, `unreachable_media` in preprocess hook |

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

---

## 12. Acceptance Criteria

### Detection

- Unreachable block-origin references never create `digital_asset_usage` rows
- Unreachable media-origin references never create `digital_asset_usage` rows
- Cascade behavior correct (media orphan when all hosts are orphan; media reachable when at least one host is reachable)
- No regression in paragraph detection
- Tri-state classification correct

### UX

- New context labels visible in Orphan References tab
- No delete action available
- Active Usage logic unchanged
- Orphan references remain informational

### Performance

- Scan runtime regression <5%
- No additional N+1 queries
- Inventory view performance unaffected

---

## 13. Kernel Test Coverage

### Block Reachability Tests

- Block placed in active region → reachable
- Block placed but disabled (`status = FALSE`) → orphan
- Block placed but region empty → orphan
- Block referenced by Layout Builder section → reachable
- Block referenced by entity reference field → reachable
- Block exists but not placed or referenced → orphan
- Block referenced only by orphan paragraph → orphan (cascade)

### Media Reachability Tests

- Media referenced by reachable node via media reference field → reachable
- Media embedded via `<drupal-media>` in reachable content → reachable
- Media referenced only by orphan paragraph → orphan (cascade)
- Media referenced only by unreachable block → orphan (cascade)
- Media entity exists but not referenced anywhere → orphan

### Regression Tests

- Paragraph detection unchanged
- No usage rows created for orphan entities
- Orphan references correctly categorized with `reference_context`

---

## 14. Risk Mitigation

| Risk | Mitigation |
| --- | --- |
| False orphan classification | Conservative reachability bias — default to reachable |
| Circular detection logic | Reachability anchored to host graph, not usage rows |
| Layout Builder edge cases | Limited to section storage lookups; deep traversal deferred |
| Duplicate orphan rows | Accepted (uniqueness deferred to Phase 3) |
| Media reachability complexity | Anchor reachability to the host entity graph (nodes, blocks, paragraphs), not to usage rows |

---

## 15. Expansion Risk Heatmap

Safest entity types to expand to, ordered by risk:

| Risk Level | Entity Type | Rationale |
| --- | --- | --- |
| **Low** | `paragraph` | Phase 1 — explicit parent field membership |
| **Low** | `block_content` | Usage via block placement config / layout sections; deterministic and queryable |
| **Low** | `media` | Referenced through fields; reachability anchored to host entity graph evaluation |
| **Medium** | `menu_link_content` | Links can exist unused or disabled; "reachable" means "present in active menu + not disabled" |
| **Medium** | `taxonomy_term` | Referenced everywhere; "reachability" definition expensive without good indexing |
| **High** | Layout Builder components | Reachability lives in serialized section storage; edge cases with overrides, defaults, revisions |
| **High** | Config entities | Assets referenced in config are "system usage," not content reachability; may need separate classification |

---

## 16. Phase 3 Candidates

- Taxonomy term orphan detection
- Layout Builder deep graph traversal
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
