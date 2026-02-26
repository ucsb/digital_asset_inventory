# Revision-Aware Delete Guard for Required Field References

## Audience

Module developers implementing or maintaining the asset deletion workflow.

## Instruction Level

**STRICT** — follow exactly when implementing deletion guards for paragraph references.

## Objective

Align the required-field deletion guard with the scanner's definition of "in use"
(reachable from the default revision of current content), so that paragraph
revision ghosts do not permanently block cleanup of genuinely unused assets.

## Non-Goals

- Changing the scanner's usage detection logic
- Adding an admin settings toggle (may be added in a future release)
- Handling non-paragraph revision ghost references (content entities like
  nodes already work correctly because `getQuery()` without `allRevisions()`
  only returns default revisions)

---

## Definitions

| Term | Meaning |
|------|---------|
| **Default revision** | The revision Drupal treats as canonical (usually the published version). Entity queries without `allRevisions()` return only default revisions. |
| **Revision-only reference** | A file reference that exists only in a previous paragraph revision, not reachable from any default revision of a host entity. Also called a "revision ghost." |
| **Current content** | Content reachable through the default revision chain: host entity (default revision) → paragraph (referenced by `target_revision_id` in that revision) → file. |
| **Orphan paragraph** | A paragraph whose `getParentEntity()` returns NULL — the parent entity no longer exists or the paragraph is completely detached. |
| **Detached paragraph** | A paragraph whose parent exists but the parent's default revision no longer references this paragraph (or this revision of the paragraph). |

---

## Policy

> **DAI treats the default revision as "current content" for delete
> eligibility.** A file referenced only by non-default paragraph revisions
> is eligible for deletion with a warning.

This aligns with the scanner, which tracks usage only in current content.

**Note:** Draft-only paragraph references (content moderation workflows) are
treated as revision-only. This is consistent with the scanner's behavior,
which inspects only default revisions.

---

## Current Behavior (Problem)

`DeleteAssetForm::checkRequiredFieldUsage()` discovers required-field
references across **all entity types**, including paragraphs. When a paragraph
entity (even from a previous revision) references a file in a required image
field, deletion is blocked:

```text
This file cannot be deleted because it is used in required fields on the
following content:
- paragraph "Info Page with Map > Blades (previous revision)" (field: Image)
Please remove or replace the reference before deleting.
```

The paragraph may be a revision ghost — not attached to any current content —
but the guard treats it identically to a live reference. This permanently
prevents cleanup of unused assets on sites with paragraph content and revision
history.

---

## Requirements

### REQ-1: Revision-Aware Classification

**Type:** Event-driven
**Statement:** When `checkRequiredFieldUsage()` finds a paragraph entity
referencing the file in a required field, the system shall classify the
reference as either "blocking" (reachable from current content) or
"revision-only" (not reachable from current content).

**Acceptance Criteria:**

- [ ] Paragraph references classified using `isParagraphRevisionInCurrentContent()`
- [ ] Non-paragraph entity references always classified as "blocking" (unchanged)
- [ ] Classification result includes `entity_type`, `entity_id`, `label`,
      `field_name`
- [ ] Each classified reference is placed into either the `blocking`
      or `revision_only` bucket (the bucket itself determines classification)

### REQ-2: Reachability Check via Paragraph APIs

**Type:** Ubiquitous
**Statement:** The system shall determine paragraph reachability using the
paragraph module's `getParentEntity()` and `getParentFieldName()` APIs,
verifying both `target_id` and `target_revision_id` on the host entity's
default revision.

**Rationale:** Checking only `target_id` can misclassify revision ghosts as
current content when the paragraph entity ID is the same but the revision
differs.

**Acceptance Criteria:**

- [ ] Helper loads the paragraph entity by ID (default revision)
- [ ] Uses `getParentEntity()` to trace parent chain
- [ ] Uses `getParentFieldName()` to inspect only the relevant field (not all fields)
- [ ] For `entity_reference_revisions` fields, compares both `target_id` and
      `target_revision_id` against the paragraph's revision
- [ ] Recurses through nested paragraph chains with depth guard (max 25)
- [ ] Returns FALSE (not in current content) when:
  - Paragraph cannot be loaded
  - `getParentEntity()` returns NULL (orphan)
  - `getParentFieldName()` returns empty
  - Parent's default revision field does not contain matching `target_id` +
    `target_revision_id`
  - Recursion depth exceeded (warning log includes paragraph ID and
    indicates recursion depth exceeded)

### REQ-3: Structured Return from Required Field Check

**Type:** Ubiquitous
**Statement:** `checkRequiredFieldUsage()` shall return a structured result
with two categories: `blocking` (current content) and `revision_only`
(revision ghosts).

**Acceptance Criteria:**

- [ ] Return format:
      ```php
      [
        'blocking' => [...],
        'revision_only' => [...],
      ]
      ```
- [ ] Each entry contains: `entity_type`, `entity_id`, `label`, `field_name`
- [ ] Both `checkRequiredMediaReferenceFields()` and `checkRequiredFileFields()`
      apply paragraph classification

### REQ-4: Block for Current Content, Warn for Revision-Only

**Type:** Event-driven
**Statement:** When blocking references exist, the system shall block deletion
with an error message and redirect (existing behavior). When only
revision-only references exist, the system shall show a warning message on
the delete confirmation form but allow the user to proceed.

**Warning message:**

> This file is referenced by previous content revisions in required fields.
> It is not used in current content. Deleting it may cause missing images
> if content is reverted to an older revision.
>
> [list of revision-only references]

**Acceptance Criteria:**

- [ ] Blocking references → error message + redirect to inventory page (unchanged behavior)
- [ ] Revision-only references → warning message on form, deletion allowed
- [ ] Warning lists affected content with entity type, label, and field name
- [ ] Warning uses `messages--warning` style (not error)

### REQ-5: Audit Logging

**Type:** Event-driven
**Statement:** When deletion proceeds despite revision-only references, the
system shall log a notice with the user, asset, and reference details.

**Log format:**

```text
User @user deleted asset @filename (@aid) despite revision-only required
field references: @refs
```

**Structured reference format:** `paragraph#123 (field_image), paragraph#456 (field_hero)`

**Acceptance Criteria:**

- [ ] Logged via `\Drupal::logger('digital_asset_inventory')->notice()`
- [ ] Includes: user name/UID, filename, asset ID, structured list of
      paragraph entity IDs and field names (parseable format)

---

## Implementation Notes

### Helper Method Signature

```php
protected function isParagraphRevisionInCurrentContent(
  int $paragraph_id,
  int $max_depth = 25
): bool
```

### Reachability Algorithm

```text
1. Load paragraph entity by ID (default revision)
   // Note: We intentionally load only the default revision.
   // DAI defines "current content" as the default revision chain.
   // Draft-only references are treated as revision-only.
2. If NULL → return FALSE
3. Get parent: $paragraph->getParentEntity()
4. If parent is NULL → return FALSE (orphan)
5. If parent is paragraph:
   a. Decrement depth; if 0 → log warning + return FALSE
   b. Recurse with parent paragraph ID
6. If parent is content entity:
   a. Get field name: $paragraph->getParentFieldName()
   b. If empty → return FALSE
   c. Load parent's field items for that field
   d. For each item:
      - If field type is entity_reference_revisions:
        Check item->target_id == paragraph ID
        AND item->target_revision_id == paragraph revision ID
      - If field type is entity_reference:
        Check item->target_id == paragraph ID
   e. If match found → return TRUE
   f. No match → return FALSE (detached from current revision)
```

### Edge Cases

| Case | Behavior |
|------|----------|
| Parent entity deleted | `getParentEntity()` returns NULL → revision-only |
| Parent field removed from bundle | `getParentFieldName()` returns empty → revision-only |
| Nested paragraphs (3+ levels) | Recursion traces to root; depth guard prevents infinite loops |
| Content moderation (draft vs published) | Uses default revision consistently (matches scanner behavior) |
| Paragraph shared across revisions | Same paragraph ID but different revision IDs; `target_revision_id` comparison distinguishes |
| Non-paragraph required field reference | Always blocking (unchanged behavior) |

### Files Modified

| File | Changes |
|------|---------|
| `src/Form/DeleteAssetForm.php` | Add `isParagraphRevisionInCurrentContent()`, modify `checkRequiredFieldUsage()` return structure, update `buildForm()` UI, add audit logging in `submitForm()` |

### No New Dependencies

Uses existing `$this->entityTypeManager` — no constructor or `create()` changes.

---

## Verification

### Automated

- Unit tests: 299 tests pass (no changes to unit-tested code)
- Kernel tests: 94 tests pass (no changes to kernel-tested code)

### Manual

| Scenario | Expected |
|----------|----------|
| Delete asset referenced only by previous paragraph revision | Warning shown, deletion allowed |
| Delete asset referenced by current paragraph in required field | Blocked with error (unchanged) |
| Delete asset with no paragraph references | No warning, deletion allowed (unchanged) |
| Delete asset with both current and revision-only references | Blocked (current reference takes precedence) |
| Revert content to old revision after deletion | Broken image (acceptable risk, user was warned) |
| Check watchdog log after deletion with warning | Notice logged with user, file, and reference details |

