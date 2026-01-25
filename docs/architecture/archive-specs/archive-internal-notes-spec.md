# Archive Internal Notes System Specification

## Overview

Internal notes provide administrators with a way to record observations, follow-ups, and context about archived items. The system has two components:

1. **Initial Note** — Existing `internal_notes` field, set at archive creation, provides initial context
2. **Notes Log** — New append-only entity for ongoing administrative comments after archiving

Both are accessed via a dedicated admin page linked from the Operations column.

**Guiding principle:** Internal notes function like an append-only comment log for administrators and are intentionally lightweight, contextual, and non-disruptive.

---

## Policy Statement

> Internal notes are restricted administrative metadata used to document compliance decisions and operational context. They are not part of the archived material, are not publicly visible, and do not affect archive classification or exemption status. Notes are preserved as an append-only audit trail.

---

## Key Constraints

| Constraint | Rationale |
|------------|-----------|
| **Requires archive feature** | Notes functionality only available when archive feature is enabled; data preserved when disabled, access restored when re-enabled |
| **Not public** | Notes are admin-only; never shown on public pages |
| **Not in exports** | Excluded from audit CSV and public reports |
| **Append-only** | No editing or deletion; preserves decision history |
| **Separate from content** | Notes don't modify archive classification or exemption status |
| **Explicitly labeled** | UI clearly indicates "Internal / Admin-only" |
| **No rate limiting (v1)** | Note creation limited to authorized users only; no additional rate limiting required |

---

## Permissions

Dedicated permissions (do not reuse generic archive permissions):

| Permission | Description |
|------------|-------------|
| `view archive internal notes` | View notes on archive items |
| `add archive internal notes` | Add new notes to archive items |

**Access rules:**
- Anonymous users: No access
- Authenticated without permission: No access
- Users with `view archive internal notes`: Can see notes indicator, access notes page, read notes
- Users with `add archive internal notes`: Can add notes (requires view permission)

**Permission implication note:** Drupal permissions don't automatically imply each other. Roles must be granted both `view` and `add` permissions for users to add notes. A user with only `add` permission would see the link but get 403 on the notes page. The update hook grants both permissions together.

**Recommended role assignment:**
The `digital_asset_manager` role should be granted both permissions, reflecting its responsibility for archive operations. The update hook grants these permissions if the role exists; site admins can override role permissions as needed. Notes remain append-only and are not editable or deletable by any role.

---

## Requirements

### REQ-001: Append-Only Notes
**Type:** Ubiquitous
**Statement:** The system shall store Notes Log entries as immutable, timestamped entries that cannot be edited or deleted after creation.
**Rationale:** Preserves history and accountability for administrative annotations.
**Acceptance Criteria:**
- [ ] Each note includes timestamp, user ID, and note text
- [ ] Notes Log entries cannot be modified after creation
- [ ] Notes Log entries cannot be deleted
- [ ] Notes are displayed newest-first (sorted by `created` DESC, then `id` DESC)

**Note:** Append-only applies to Notes Log entries (`dai_archive_note`). The Initial Note (`internal_notes` field) is managed separately via the archive edit form.

### REQ-002: Dedicated Notes Page
**Type:** Event-driven
**Statement:** When a user activates the "Notes" link in the Operations column, the system shall navigate to a dedicated page displaying all notes for that archive item.
**Rationale:** Dedicated pages are simpler, more accessible, and work reliably on mobile devices.
**Acceptance Criteria:**
- [ ] Link navigates to `/admin/digital-asset-inventory/archive/{id}/notes`
- [ ] Page displays archive item context (name, type, status)
- [ ] Page displays all notes for the archive item
- [ ] "Add note" form shown only to users with `add archive internal notes` permission; view-only users see notes list only
- [ ] Page includes "Back to Archive Management" link

### REQ-003: Notes Indicator
**Type:** State-driven
**Statement:** The system shall display a "Notes" link in the Operations column based on user permissions and note count.
**Rationale:** Signals presence of notes without cluttering the table; allows adding first note.
**Acceptance Criteria:**
- [ ] Users with `add` permission: show "Notes" (no count) when count = 0, show "Notes (N)" when count > 0
- [ ] Users with `view` permission only: show "Notes (N)" when count > 0, hide when count = 0
- [ ] Count updates after adding a note
- [ ] The note count includes the initial archive note (if present) plus notes log entries

### REQ-004: No Table Column
**Type:** Ubiquitous
**Statement:** The system shall not display note content in table cells.
**Rationale:** Notes can be lengthy; embedding them in cells would harm table readability.
**Acceptance Criteria:**
- [ ] No "Internal Notes" column in the archive management view
- [ ] Note content only visible on the dedicated notes page

### REQ-005: Validation and Sanitization
**Type:** Ubiquitous
**Statement:** The system shall validate and sanitize note input before storage.
**Rationale:** Prevents blank notes, XSS, and ensures data consistency.
**Acceptance Criteria:**
- [ ] `note_text` is required; empty or whitespace-only submissions are rejected
- [ ] Input is trimmed before storage
- [ ] Maximum 500 characters enforced (form validation + DB constraint)
- [ ] Stored as plain text (no HTML allowed)
- [ ] Rendered with proper escaping

### REQ-006: Accessibility
**Type:** Ubiquitous
**Statement:** The system shall implement accessible page behavior per WCAG 2.1 AA.
**Rationale:** Module is part of ADA compliance suite; admin tools must also be accessible.
**Acceptance Criteria:**
- [ ] Page has proper heading hierarchy
- [ ] All interactive elements keyboard accessible
- [ ] Form inputs have visible labels
- [ ] Color contrast meets WCAG AAA (7:1)
- [ ] Note count included in link text for screen readers

---

## Data Model

### Two-Component Design

The notes system uses two data sources:

| Component | Storage | Purpose | Behavior |
|-----------|---------|---------|----------|
| **Initial Note** | Existing `internal_notes` field on `DigitalAssetArchive` | Context at archive creation | Read-only on notes page (editable only via archive edit form) |
| **Notes Log** | New `dai_archive_note` entity | Ongoing comments after archiving | Append-only, timestamped |

This design:
- Avoids data migration
- Preserves existing audit trail
- Provides clear mental model (creation context vs. ongoing notes)

### Initial Note (Existing Field)

The existing `internal_notes` field on `DigitalAssetArchive`:
- Type: `string_long`
- Captured at archive creation to record original context
- Displayed read-only on the notes page
- Editable only via the archive edit form, not modified through the notes system

This makes it clear that the notes system is not an edit surface for the initial note; any edit is deliberate and traceable through the archive edit form.

### Notes Log Entity (New)

New `dai_archive_note` entity (entity ID uses `dai_*` namespace, class follows `DigitalAsset*` convention):

```php
/**
 * Defines the Archive Note entity.
 *
 * @ContentEntityType(
 *   id = "dai_archive_note",
 *   label = @Translation("Archive Note"),
 *   base_table = "dai_archive_note",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "uid" = "author",
 *     "created" = "created",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\digital_asset_inventory\Access\ArchiveNoteAccessControlHandler",
 *   },
 * )
 */
class DigitalAssetArchiveNote extends ContentEntityBase {
  // ...
}
```

**No standalone UI routes:** The `dai_archive_note` entity has no canonical, add, edit, or delete routes. It is managed only through the notes page controller and form.

**All fields are base fields** (defined in `baseFieldDefinitions()`), not Field UI configurable fields. This keeps the entity simple and consistent with other module entities.

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Primary key |
| `uuid` | string | UUID |
| `archive_id` | entity_reference | Reference to `digital_asset_archive` (required) |
| `note_text` | string | The note content |
| `created` | timestamp | When the note was created |
| `author` | entity_reference | User who created the note |

**Field constraints:**
- `archive_id`: target_type = `digital_asset_archive`, required = TRUE
- `note_text`: `string` field with `max_length = 500` (validated and enforced at DB level)
- `author`: target_type = `user`, required = TRUE

**Indexes:**
- `archive_id` for efficient lookup

**Deletion behavior:**
- When a `digital_asset_archive` is deleted, all associated `dai_archive_note` entities are automatically deleted (cascade)
- On module uninstall: `dai_archive_note` entities must be deleted before `digital_asset_archive` entities (foreign key constraint)
- Uninstall cleanup runs regardless of archive feature flag (`enable_archive` setting)

### Entity Access Control

The `ArchiveNoteAccessControlHandler` enforces access at the entity level to prevent accidental exposure via Views, JSON:API, or generic entity endpoints:

| Operation | Access |
|-----------|--------|
| `view` | Allowed only if user has `view archive internal notes` permission |
| `create` | Allowed only if user has `add archive internal notes` permission |
| `update` | Always denied (append-only) |
| `delete` | Always denied (append-only) |

This ensures notes remain append-only even if someone creates a View or API endpoint that references the entity.

---

## UI Design

### Design Principles

The notes page must:
- Follow existing module design patterns (forms, pages, styling)
- Use CSS variables and components from `dai-base.css` and `dai-admin.css`
- Use surface containers, semantic badges, and button styles consistent with other admin pages
- Be compatible with both Drupal 10 and Drupal 11
- Meet WCAG 2.1 AA accessibility standards (WCAG AAA for color contrast)
- Work without JavaScript for core functionality
- Use Drupal Form API for the add note form (ensures CSRF protection, validation, accessibility)

### Operations Column

The "Notes" link appears in the Operations column of the **Archive Management** view (`/admin/digital-asset-inventory/archive`).

Link display follows REQ-003:
- Users with `add` permission: "Notes" (when count=0) or "Notes (N)" (when count>0)
- Users with `view` only: "Notes (N)" (when count>0), hidden when count=0

Examples for already-archived items:
```
[Unarchive] [Delete File] [Notes (2)]    ← user has view or add permission, 2 notes exist
[Unarchive] [Delete File] [Notes]        ← user has add permission, no notes yet
[Unarchive] [Delete File]                ← user has view-only permission, no notes
```

### Page Structure

```
┌─────────────────────────────────────────────────────────┐
│ Internal Notes (Admin Only)                             │
│ ← Back to Archive Management                            │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Archive Entry                                    │   │
│  │ Name: quarterly-report-2025.pdf                  │   │
│  │ Type: PDF                                        │   │
│  │ Status: Archived (Public)                        │   │
│  │ Archive Type: Legacy Archive                     │   │
│  │ Purpose: Reference                               │   │
│  │ Archived: 2025-12-15 by admin                    │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
│  Notes are for internal tracking and compliance         │
│  context. They are not part of the archived material    │
│  and are not publicly visible.                          │
│                                                         │
│  ─── Initial Note (Archive Creation) ───────────────── │
│                                                         │
│  Archived per department request. Original owner        │
│  confirmed this document is no longer in active use.    │
│                                                         │
│  ─── Notes Log ─────────────────────────────────────── │
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Add a note...                            [0/500] │   │
│  │                                                  │   │
│  └─────────────────────────────────────────────────┘   │
│  [Add Note]                                             │
│                                                         │
│  2026-01-24 14:30 — admin                              │
│  Verified with legal team that this qualifies for      │
│  legacy archive status.                                │
│                                                         │
│  2026-01-15 09:15 — jsmith                             │
│  Follow-up: file owner confirmed compliance approval.  │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

**Empty state handling:**
- If initial note is empty: show "(No initial note)" inside the Initial Note section
- If notes log is empty: show "(No notes yet)" inside the Notes Log section

**Timestamp display:**
- Display in site timezone
- Use `<time datetime="">` element for accessibility
- Format: human-readable (e.g., "2026-01-24 14:30")

**Required UI elements:**
- Page title: "Internal Notes (Admin Only)" - explicit labeling
- Back link to Archive Management
- **Archive entry metadata** (always present fields):
  - Name (file name or page title) — required
  - Type (PDF, Word, Web Page, etc.) — required
  - Status (Queued, Archived Public/Admin, etc.) — required
  - Archived/Queued date and user — required (use queued date if not yet archived)
- **Archive entry metadata** (show only if available):
  - Archive Type (Legacy Archive / General Archive) — only for archived items
  - Purpose (Reference, Research, Recordkeeping, Other) — may be empty
- Page should render even if optional fields are missing; don't block on empty values
- Disclaimer text explaining notes are internal-only
- **Initial Note section** (read-only, from existing `internal_notes` field)
  - Shows "(No initial note)" if empty
- **Notes Log section** (append-only)
  - Add note form with character counter (500 chars) - only if user has `add` permission
  - Timestamped entries, newest-first
  - Shows "(No notes yet)" if empty

---

## Implementation

### New Files

| File | Purpose |
|------|---------|
| `src/Entity/DigitalAssetArchiveNote.php` | Note entity definition (`dai_archive_note`) |
| `src/Access/ArchiveNoteAccessControlHandler.php` | Entity access control (view/create/deny update/delete) |
| `src/Form/AddArchiveNoteForm.php` | Form for adding notes (Drupal Form API) |
| `src/Controller/ArchiveNotesController.php` | Notes page controller |
| `src/Plugin/views/field/ArchiveNotesLink.php` | Views field for "Notes (N)" link |
| `templates/archive-notes-page.html.twig` | Notes page template (uses existing module CSS classes) |

**Note:** No new CSS file required. The notes page uses existing styles from `dai-admin.css` (surface containers, buttons, form elements).

### Routes

Single route for the notes page (no separate `/add` route needed):

```yaml
digital_asset_inventory.archive_notes:
  path: '/admin/digital-asset-inventory/archive/{digital_asset_archive}/notes'
  defaults:
    _controller: '\Drupal\digital_asset_inventory\Controller\ArchiveNotesController::page'
    _title_callback: '\Drupal\digital_asset_inventory\Controller\ArchiveNotesController::title'
  requirements:
    _permission: 'view archive internal notes'
    _archive_enabled: 'TRUE'
  options:
    _admin_route: TRUE
    parameters:
      digital_asset_archive:
        type: entity:digital_asset_archive
```

**Single-page form pattern:** The add-note form is embedded on the notes page and submits back to the same route (standard Drupal Form API pattern). The controller builds the page with the form; the form's `submitForm()` creates the note and redirects back to the same page. This avoids a separate route and follows Drupal conventions.

**Note:** Route uses the existing `_archive_enabled` custom access check (already implemented in the module) to ensure notes functionality is only available when the archive feature is enabled.

**Access behavior:**
- Invalid `{digital_asset_archive}` → 404
- User lacks permission → 403
- Archive feature disabled (`enable_archive` = false) → 403 (via `_archive_enabled`)
- Archive exists but user lacks entity access → 403

**Security:** Add-note form uses Drupal Form API (CSRF token built-in). The form only renders for users with `add archive internal notes` permission; view-only users see the notes list without the form.

### Controller Implementation Notes

**Defensive access check:** Even with route permissions, check entity access in controller:
```php
if (!$archive->access('view', $this->currentUser())) {
  throw new AccessDeniedHttpException();
}
```

**Build form via FormBuilder** (not inline):
```php
$form = $this->formBuilder()->getForm(AddArchiveNoteForm::class, $archive);
```

**Return structured render array** with `#cache` metadata, not template-only output.

### Form Implementation Notes

**Store archive in form state:**
```php
public function buildForm(array $form, FormStateInterface $form_state, $archive = NULL) {
  $form_state->set('archive', $archive);
  // ...
}
```

**Validation (trim + whitespace check):**
```php
public function validateForm(array &$form, FormStateInterface $form_state) {
  $text = trim($form_state->getValue('note_text'));
  if ($text === '') {
    $form_state->setErrorByName('note_text', $this->t('Note cannot be empty.'));
  }
  if (mb_strlen($text) > 500) {
    $form_state->setErrorByName('note_text', $this->t('Note cannot exceed 500 characters.'));
  }
  $form_state->setValue('note_text', $text); // Store trimmed value.
}
```

**Submit redirect:**
```php
public function submitForm(array &$form, FormStateInterface $form_state) {
  $archive = $form_state->get('archive');
  // Create note entity...
  Cache::invalidateTags($archive->getCacheTags());
  $this->messenger()->addStatus($this->t('Note added.'));
  $form_state->setRedirect('digital_asset_inventory.archive_notes', [
    'digital_asset_archive' => $archive->id(),
  ]);
}
```

**Form element character limit:**
```php
$form['note_text'] = [
  '#type' => 'textarea',
  '#title' => $this->t('Add a note'),
  '#maxlength' => 500,
  '#required' => TRUE,
];
```

**Append-only enforcement:** Form only creates new entities; never loads existing `dai_archive_note` for editing.

### Pagination Implementation Notes

If notes count > 50, use entity query with `range()` and pager manager:
```php
$query = $this->noteStorage->getQuery()
  ->condition('archive_id', $archive->id())
  ->sort('created', 'DESC')
  ->sort('id', 'DESC')
  ->accessCheck(TRUE)
  ->pager(50);
$ids = $query->execute();
```

Do NOT load all notes then slice in PHP.

### Caching

**Views field "Notes (N)" link:**
- Cache contexts: `user.permissions`
- Cache tags: parent archive entity tags (via `$archive->getCacheTags()`)

**Notes page:**
- Cache contexts: `user.permissions`
- Cache tags: parent archive entity tags
- Not cached publicly

**Cache invalidation:**
When a `dai_archive_note` is created, explicitly invalidate the parent archive's cache tags in the form submit handler:

```php
\Drupal\Core\Cache\Cache::invalidateTags($archive->getCacheTags());
```

This ensures the "Notes (N)" count in Views updates without manual cache clears.

**Notes page render array must include:**
```php
'#cache' => [
  'contexts' => ['user.permissions'],
  'tags' => $archive->getCacheTags(),
],
```

### Performance

**Note count query:**
- Use lightweight `COUNT(*)` query, not full entity load
- Query should be indexed on `archive_id`
- Expected response: <10ms for typical archives with <100 notes

**Views field count queries:**
- v1 uses a per-row count query (acceptable for typical page sizes of 25-50 rows)
- If performance becomes an issue, replace with a single aggregated query keyed by `archive_id` for the current result set

**Notes page pagination:**
- If notes count > 50, use Drupal's pager (required)
- Otherwise show all notes on one page

### Permissions File

Add to `digital_asset_inventory.permissions.yml`:

```yaml
view archive internal notes:
  title: 'View archive internal notes'
  description: 'View internal administrative notes on archived items.'
  restrict access: true

add archive internal notes:
  title: 'Add archive internal notes'
  description: 'Add internal administrative notes to archived items. Implies view permission.'
  restrict access: true
```

### Views Integration

The "Notes" link is added to the Archive Management view (`digital_asset_archive`) via a custom Views field plugin. This link appears in the Operations column alongside existing action links.

Add custom Views field plugin that:
1. Checks if archive feature is enabled
2. Checks user permissions (`view` or `add`)
3. Counts total notes (initial note + notes log entries)
4. Renders link based on permission and count (see REQ-003)

```php
/**
 * @ViewsField("dai_archive_notes_link")
 */
class ArchiveNotesLink extends FieldPluginBase {
  public function render(ResultRow $values) {
    // Only show notes link if archive feature is enabled.
    $config = \Drupal::config('digital_asset_inventory.settings');
    if (!$config->get('enable_archive')) {
      return [];
    }

    $can_add = $this->currentUser->hasPermission('add archive internal notes');
    $can_view = $this->currentUser->hasPermission('view archive internal notes');

    // Must have at least view permission.
    if (!$can_view && !$can_add) {
      return [];
    }

    $archive = $values->_entity;

    // Count includes: 1 if initial note exists + count of notes log entries.
    $has_initial_note = !empty($archive->getInternalNotes());
    $log_count = $this->noteStorage->countByArchive($archive->id());
    $total_count = ($has_initial_note ? 1 : 0) + $log_count;

    // View-only users: hide when count = 0.
    // Users with add permission: always show (to allow adding first note).
    if ($total_count === 0 && !$can_add) {
      return [];
    }

    // Build link title: "Notes" when count=0, "Notes (N)" when count>0.
    $title = $total_count > 0
      ? $this->t('Notes (@count)', ['@count' => $total_count])
      : $this->t('Notes');

    $aria_label = $total_count > 0
      ? $this->t('Notes, @count entries', ['@count' => $total_count])
      : $this->t('Notes, no entries yet');

    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute('digital_asset_inventory.archive_notes', [
        'digital_asset_archive' => $archive->id(),
      ]),
      '#attributes' => [
        'class' => ['dai-notes-link'],
        'aria-label' => $aria_label,
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $archive->getCacheTags(),
      ],
    ];
  }
}
```

**Count logic:**
- Initial note (existing field): counts as 1 if not empty
- Notes log entries: count from entity query
- Total = initial + log count

**Link visibility:**
- Archive feature disabled: hidden
- User with `add` permission: always shown (allows adding first note)
- User with `view` only + count = 0: hidden
- User with `view` only + count > 0: shown

### Views Field Implementation Notes

**Dependency injection:** Use `ContainerFactoryPluginInterface` to inject `$noteStorage` and `$currentUser`. Do not call `\Drupal::entityTypeManager()` per render.

**Safe access to initial note field:**
```php
$initial = trim((string) $archive->get('internal_notes')->value);
$has_initial = $initial !== '';
```
This avoids whitespace-only false positives and doesn't rely on a custom getter.

**Permission implication:** Drupal permissions don't automatically imply each other. The route requires `view archive internal notes`. If a user has `add` but not `view`, the link renders but the route returns 403. Roles should grant both permissions together (enforced in update hook).

**Aggregate query optimization (v2):** If per-row queries become a problem, collect archive IDs from `$this->view->result`, run a single `GROUP BY archive_id` query, then map counts back to each row.

### Update Hook

Update hook must install the new entity schema via the Entity Definition Update Manager and work correctly on existing installed sites.

```php
/**
 * Install dai_archive_note entity, add permissions to digital_asset_manager role.
 */
function digital_asset_inventory_update_10009() {
  // 1. Install new entity type schema.
  $entity_type_manager = \Drupal::entityTypeManager();
  $entity_definition_update_manager = \Drupal::entityDefinitionUpdateManager();

  // Get the entity type definition from the entity type manager.
  $entity_type = $entity_type_manager->getDefinition('dai_archive_note');
  $entity_definition_update_manager->installEntityType($entity_type);

  // 2. Grant permissions to digital_asset_manager role.
  $role = \Drupal\user\Entity\Role::load('digital_asset_manager');
  if ($role) {
    $role->grantPermission('view archive internal notes');
    $role->grantPermission('add archive internal notes');
    $role->save();
  }

  // 3. Add notes link field to archive view.
  // ... view config updates ...

  return t('Installed archive notes system.');
}
```

### Uninstall Hook Update

The existing `hook_uninstall()` must be updated to delete `dai_archive_note` entities before `digital_asset_archive` entities:

```php
// In digital_asset_inventory_uninstall():
// Order: notes → archive → usage → items

// 1. Delete notes first (references archives).
$note_storage = $entity_type_manager->getStorage('dai_archive_note');
$note_ids = $note_storage->getQuery()->accessCheck(FALSE)->execute();
if ($note_ids) {
  $notes = $note_storage->loadMultiple($note_ids);
  $note_storage->delete($notes);
}

// 2. Then archive records...
// (existing code)
```

### Logging (Optional)

For audit/ops visibility, log note creation at `notice` level:

```php
\Drupal::logger('digital_asset_inventory')->notice(
  'Note added to archive @archive_id by user @uid.',
  ['@archive_id' => $archive->id(), '@uid' => $user->id()]
);
```

---

## Accessibility Checklist

- [ ] Page has proper heading hierarchy (h1 for title, h2 for sections)
- [ ] Back link is clearly labeled and keyboard accessible
- [ ] Notes link in table includes count in accessible name: "Notes, 3 entries"
- [ ] Archive metadata presented in accessible format (definition list or table)
- [ ] Textarea has visible label and character counter announced (`aria-live="polite"`, debounced to avoid spam)
- [ ] Submit button has clear text ("Add Note")
- [ ] Timestamps use `<time>` element with `datetime` attribute
- [ ] Color contrast meets WCAG AAA (7:1)
- [ ] Page works without JavaScript
- [ ] Form validation errors announced to screen readers

---

## Audit Safeguards

Internal notes reduce audit risk when implemented correctly. This section documents the safeguards.

### What Makes Notes Audit-Safe

| Safeguard | Implementation |
|-----------|----------------|
| **Clear labeling** | Page title: "Internal Notes (Admin Only)" with explanatory text |
| **Separate from content** | Notes stored in separate entity, not on archive record |
| **Access-controlled** | Dedicated permissions, no public/anonymous access |
| **Append-only** | No edit or delete operations; preserves decision history |
| **Excluded from exports** | Not in audit CSV or public reports |
| **No content modification** | Notes don't change archive status, classification, or exemption |

### What Would Cause Audit Issues (Avoided)

- ❌ Notes visible on public archive pages
- ❌ Notes mixed into public CSV exports
- ❌ Notes editable without trace (silent overwrites)
- ❌ Notes changing archive classification or content state
- ❌ Notes used as substitute for required public disclaimers

### Why Auditors Accept Internal Notes

Auditors distinguish between:
- **Archived material** — public-facing, regulated
- **Administrative metadata** — internal, supporting compliance

Internal notes fall into the second category and are commonly used in:
- Records management systems
- Accessibility remediation logs
- Legal holds
- Case management systems

Append-only notes show decision evolution and preserve context, which auditors view favorably.

---

## Design Decisions

| Question | Decision | Rationale |
|----------|----------|-----------|
| **Note length** | 500 character limit | The 500-character limit is intentionally conservative to keep notes comment-like and prevent use as long-form documentation |
| **Note types** | None (v1) | Simple free-form comments; no categories needed initially |
| **Notifications** | None | Notes reviewed during normal admin workflows |
| **Search** | Not searchable | Notes only visible within context of the archived item |

The notes entity structure allows future enhancements (e.g., filtering or search) without data migration.

---

## Implementation Checklist

Quick reference for implementation verification.

### Controller/Form

- [ ] Embedded form via `FormBuilder` with `$archive` passed in
- [ ] Trim + whitespace-only validation in `validateForm()`
- [ ] Max length enforced in base field + form element `#maxlength`
- [ ] Notes list uses `created DESC, id DESC` sorting
- [ ] Pager for >50 notes (entity query with `->pager(50)`)
- [ ] Cache contexts/tags applied to page render array
- [ ] Invalidate `$archive->getCacheTags()` on form submit

### Views Plugin

- [ ] Use field API to check initial note: `trim((string) $archive->get('internal_notes')->value)`
- [ ] Both `view` and `add` permissions assigned together in update hook
- [ ] Inject `$noteStorage` via `ContainerFactoryPluginInterface`
- [ ] Index exists on `archive_id` column
- [ ] Per-row count query acceptable for v1; aggregate query available as v2 optimization

### Entity

- [ ] Base fields defined in `baseFieldDefinitions()` (not Field UI)
- [ ] Access handler denies `update` and `delete` operations
- [ ] No standalone UI routes (managed only via notes page)
