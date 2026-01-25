# Spec: Dual-Purpose Archive Feature

> **Status:** Implemented (Solution A: Automatic Date-Based Mode)
> **Created:** January 2026
> **Updated:** January 2026

---

## Problem Statement

The Digital Asset Inventory archive system is currently designed for ADA Title II compliance with a deadline of April 24, 2026. After this deadline, the archive can serve dual purposes:

1. **ADA Compliance Archive** - Pre-deadline content exempt from WCAG 2.1 AA requirements
2. **General Archive** - Post-deadline content archived for general reference/recordkeeping (not claiming ADA exemption)

---

## Requirements

| Requirement | Description |
|-------------|-------------|
| Dual-purpose support | Keep ADA compliance mode for pre-deadline, add general mode for post-deadline |
| Exemption void logic | Keep active - modified archives should still be flagged for audit |
| Archive reasons | Keep same options: Reference, Research, Recordkeeping, Other |
| Backward compatible | Existing ADA archives must remain valid |

---

## Existing Infrastructure

| Component | Description | Relevance |
|-----------|-------------|-----------|
| `flag_late_archive` field | Boolean, set TRUE when archiving after deadline | Already tracks post-deadline archives |
| `ada_compliance_deadline` config | Timestamp, default April 24, 2026 | Determines mode switch point |
| `archive_classification_date` | Immutable timestamp when archived | Audit trail for compliance |
| `exemption_void` status | Set when archived file is modified | Tracks compliance violations |

---

## Solution A: Automatic Date-Based Mode

### Overview

| Aspect | Details |
|--------|---------|
| **Trigger** | Current date vs `ada_compliance_deadline` |
| **User Choice** | None - automatic |
| **Database Changes** | None |
| **Migration** | None |
| **Complexity** | Low |

### How It Works

```
┌─────────────────────────────────────────────────────────────────┐
│                     ARCHIVE WORKFLOW                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Current Date < April 24, 2026                                  │
│  ┌─────────────────────────────────────────────────────┐       │
│  │  ADA COMPLIANCE MODE                                │       │
│  │  - Full ADA/WCAG language                           │       │
│  │  - References April 24, 2026 deadline               │       │
│  │  - "Exempt from accessibility requirements"         │       │
│  │  - flag_late_archive = FALSE                        │       │
│  └─────────────────────────────────────────────────────┘       │
│                                                                 │
│  Current Date >= April 24, 2026                                 │
│  ┌─────────────────────────────────────────────────────┐       │
│  │  GENERAL ARCHIVE MODE                               │       │
│  │  - No ADA/WCAG language                             │       │
│  │  - No deadline references                           │       │
│  │  - "Archived for reference purposes"                │       │
│  │  - flag_late_archive = TRUE                         │       │
│  └─────────────────────────────────────────────────────┘       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Files to Modify

| File | Change Type | Description |
|------|-------------|-------------|
| `src/Service/ArchiveService.php` | Add method | `isAdaComplianceMode(): bool` |
| `src/Form/SettingsForm.php` | Add display | Read-only "Current Mode" indicator |
| `src/Form/ArchiveAssetForm.php` | Conditional text | ADA requirements section |
| `src/Form/ExecuteArchiveForm.php` | Conditional text | Success/info messages |
| `src/Form/ManualArchiveForm.php` | Conditional text | Intro and visibility descriptions |
| `templates/archive-detail.html.twig` | Conditional blocks | Notice box content |
| `digital_asset_inventory.module` | Conditional text | Edit warning banner, help text |
| `config/install/views.view.public_archive.yml` | Conditional header | About This Archive text |

### Messaging Matrix

| Context | ADA Compliance Mode | General Archive Mode |
|---------|---------------------|---------------------|
| **Settings Page** | "Current Mode: ADA Compliance Mode (before April 24, 2026)" | "Current Mode: General Archive Mode (after April 24, 2026)" |
| **Archive Form Header** | "ADA Archive Requirements" | "Archive Requirements" |
| **Archive Form Intro** | "Under ADA Title II (updated April 2024), archived content is exempt from WCAG 2.1 AA requirements if ALL conditions are met..." | "Archived content is retained for reference, research, or recordkeeping purposes." |
| **Detail Page Notice Title** | "Archived Material Notice" | "Archive Notice" |
| **Detail Page Notice Body** | "This material is archived...created before April 24, 2026...not required to meet current accessibility standards." | "This material is archived and retained for reference purposes. It is no longer actively maintained." |
| **Edit Warning** | "This content is currently recorded as archived for ADA Title II purposes." | "This content is currently archived." |
| **Exemption Void Badge** | "Exemption Void" | "Modified" |
| **Exemption Void Tooltip** | "ADA exemption voided: file was modified after the compliance deadline" | "Content was modified after being archived" |
| **CSV Export: Late Archive** | "Late Archive (Yes/No)" | "Archived After Deadline (Yes/No)" |
| **Public Registry Header** | Full ADA compliance language | "This page lists archived materials retained for reference purposes..." |

### Pros & Cons

| Pros | Cons |
|------|------|
| Zero user friction | No flexibility - locked to date |
| Simplest implementation | Cannot archive as "general" before deadline |
| No migration required | Cannot archive as "ADA" after deadline |
| Leverages existing `flag_late_archive` | Testing requires date manipulation |
| Consistent behavior across users | Edge case at midnight on deadline |

---

## Solution B: Per-Archive Choice

### Overview

| Aspect | Details |
|--------|---------|
| **Trigger** | User selection when archiving |
| **User Choice** | Select "ADA Compliance" or "General" per item |
| **Database Changes** | New `archive_type` field |
| **Migration** | Required - set existing to `ada_compliance` |
| **Complexity** | Medium |

### How It Works

```
┌─────────────────────────────────────────────────────────────────┐
│                     ARCHIVE WORKFLOW                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Step 1: Queue for Archive                                      │
│  ┌─────────────────────────────────────────────────────┐       │
│  │  Archive Type Selection                             │       │
│  │                                                     │       │
│  │  ( ) ADA Compliance Archive                         │       │
│  │      For content requiring ADA Title II exemption   │       │
│  │                                                     │       │
│  │  ( ) General Archive                                │       │
│  │      For general reference/recordkeeping purposes   │       │
│  │                                                     │       │
│  │  [Default based on current date]                    │       │
│  └─────────────────────────────────────────────────────┘       │
│                                                                 │
│  Step 2: Execute Archive                                        │
│  ┌─────────────────────────────────────────────────────┐       │
│  │  Confirmation shows selected type                   │       │
│  │  Type is locked after execution                     │       │
│  └─────────────────────────────────────────────────────┘       │
│                                                                 │
│  Result: Each archive has explicit type stored                  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Database Schema Change

```sql
-- New column in digital_asset_archive table
ALTER TABLE digital_asset_archive
ADD COLUMN archive_type VARCHAR(32) DEFAULT 'ada_compliance';

-- Update existing records
UPDATE digital_asset_archive SET archive_type = 'ada_compliance';
```

### Entity Field Definition

```php
// In DigitalAssetArchive.php
$fields['archive_type'] = BaseFieldDefinition::create('list_string')
  ->setLabel(t('Archive Type'))
  ->setDescription(t('The type of archive: ADA compliance or general purpose.'))
  ->setSettings([
    'allowed_values' => [
      'ada_compliance' => 'ADA Compliance Archive',
      'general' => 'General Archive',
    ],
  ])
  ->setDefaultValue('ada_compliance')
  ->setRequired(TRUE);
```

### Files to Modify

| File | Change Type | Description |
|------|-------------|-------------|
| `src/Entity/DigitalAssetArchive.php` | Add field | `archive_type` field definition |
| `digital_asset_inventory.install` | Add update hook | Migration for existing records |
| `src/Service/ArchiveService.php` | Modify method | `markForArchive()` accepts type param |
| `src/Form/ArchiveAssetForm.php` | Add form element | Archive type radio selection |
| `src/Form/ExecuteArchiveForm.php` | Add display | Show selected type |
| `src/Form/ManualArchiveForm.php` | Add form element | Archive type selection |
| `src/Form/EditManualArchiveForm.php` | Add form element | Allow type change |
| `src/Controller/ArchiveDetailController.php` | Pass variable | `archive_type` to template |
| `templates/archive-detail.html.twig` | Conditional blocks | Based on `archive_type` |
| `config/install/views.view.digital_asset_archive.yml` | Add field/filter | Archive Type column |

### Messaging Matrix

| Context | archive_type = 'ada_compliance' | archive_type = 'general' |
|---------|--------------------------------|-------------------------|
| **Archive Form Label** | "ADA Compliance Archive" | "General Archive" |
| **Archive Form Description** | "For content requiring ADA Title II exemption" | "For general reference/recordkeeping purposes" |
| **Execute Confirmation** | "Archive Type: ADA Compliance" | "Archive Type: General" |
| **Detail Page Notice** | Full ADA notice with deadline | Simplified archive notice |
| **View Column** | "ADA Compliance" | "General" |
| **CSV Export** | "ADA Compliance" | "General" |

### Pros & Cons

| Pros | Cons |
|------|------|
| Maximum flexibility | Additional UX choice per archive |
| Clear audit trail per document | Migration required for existing data |
| Supports mixed use cases | More code to maintain |
| Future-proof (can add more types) | Potential user confusion |
| Explicit compliance decisions | Default selection may be wrong |

---

## Solution C: Global Admin Setting

### Overview

| Aspect | Details |
|--------|---------|
| **Trigger** | Admin setting toggle |
| **User Choice** | Site-wide setting (admin only) |
| **Database Changes** | New config setting |
| **Migration** | None |
| **Complexity** | Low-Medium |

### How It Works

```
┌─────────────────────────────────────────────────────────────────┐
│                     ARCHIVE WORKFLOW                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Admin Settings (/admin/config/accessibility/digital-asset-*)   │
│  ┌─────────────────────────────────────────────────────┐       │
│  │  Archive Mode                                       │       │
│  │                                                     │       │
│  │  ( ) ADA Compliance Mode                            │       │
│  │      Full accessibility compliance language         │       │
│  │                                                     │       │
│  │  ( ) General Archive Mode                           │       │
│  │      Standard archive terminology                   │       │
│  │                                                     │       │
│  └─────────────────────────────────────────────────────┘       │
│                                                                 │
│  Result: ALL archives display according to current setting      │
│          (existing and new)                                     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Config Schema

```yaml
# digital_asset_inventory.settings.yml
enable_archive: false
enable_manual_archive: false
ada_compliance_deadline: 1745452800
archive_mode: 'ada_compliance'  # NEW: 'ada_compliance' or 'general'
```

### Files to Modify

| File | Change Type | Description |
|------|-------------|-------------|
| `config/install/digital_asset_inventory.settings.yml` | Add setting | `archive_mode` default |
| `config/schema/digital_asset_inventory.schema.yml` | Add schema | `archive_mode` type |
| `src/Form/SettingsForm.php` | Add form element | Archive mode radio selection |
| `src/Service/ArchiveService.php` | Add method | `getArchiveMode(): string` |
| `templates/archive-detail.html.twig` | Read config | Mode from controller |
| All forms with ADA text | Read config | Conditional messaging |
| View headers | Dynamic text | Based on mode setting |

### Pros & Cons

| Pros | Cons |
|------|------|
| Simple admin control | All-or-nothing approach |
| Easy to test (toggle) | Changes existing ADA archive display |
| No data migration | Historical context lost when switching |
| Can switch back/forth | May confuse users if switched |
| Centralized control | Compliance risk if ADA archives shown as general |

---

## Comparison Matrix

| Criterion | A: Date-Based | B: Per-Archive | C: Global Setting |
|-----------|:-------------:|:--------------:|:-----------------:|
| **User Friction** | None | Medium | Low |
| **Flexibility** | Low | High | Medium |
| **Audit Trail** | Implicit (date) | Explicit (per record) | Implicit (setting) |
| **Database Migration** | None | Required | None |
| **Code Complexity** | Low | Medium | Low-Medium |
| **Testing Difficulty** | Medium (dates) | Low | Low |
| **Future Extensibility** | Low | High | Medium |
| **Risk of Misconfiguration** | None | Low | Medium |
| **Mixed Mode Support** | No | Yes | No |

---

## Recommendation

| Use Case | Recommended Solution |
|----------|---------------------|
| Simplest implementation, automatic behavior | **Solution A** |
| Maximum flexibility, per-document control | **Solution B** |
| Admin wants manual control over mode | **Solution C** |
| Strict audit requirements | **Solution B** |
| Minimal user training needed | **Solution A** |

---

## Test Scenarios

### Solution A: Date-Based (Implemented)

| Test | Before Deadline | After Deadline |
|------|-----------------|----------------|
| Archive new file | ADA mode, flag_late_archive=FALSE | General mode, flag_late_archive=TRUE |
| View existing archive | ADA notice | ADA notice (was archived pre-deadline) |
| Edit archived content | ADA warning | Simplified warning |
| Public registry | ADA header text | General header text |

### Voided Exemption Re-Archive Policy (Implemented)

| Test | Expected Result |
|------|-----------------|
| File with exemption_void, archive before deadline | Forced to General Archive (flag_late_archive=TRUE) |
| File with exemption_void, archive after deadline | General Archive (flag_late_archive=TRUE) |
| URL with exemption_void, manual entry before deadline | Forced to General Archive with warning message |
| URL with exemption_void, manual entry after deadline | General Archive (normal behavior) |
| Original exemption_void record | Preserved unchanged (immutable audit trail) |

### Solution B: Per-Archive (Not Implemented)

| Test | Expected Result |
|------|-----------------|
| Archive as ADA type | ADA messaging throughout |
| Archive as General type | General messaging throughout |
| Mix types in same view | Each shows its own type |
| Edit manual entry type | Type can be changed |
| Edit file-based type | Type is locked |

### Solution C: Global Setting (Not Implemented)

| Test | ADA Mode Setting | General Mode Setting |
|------|------------------|---------------------|
| All archives display | ADA messaging | General messaging |
| Toggle setting | Immediate change | Immediate change |
| Cache invalidation | Views refresh | Views refresh |

---

## UI Badge Design

### Archive Type Column

The public Archive Registry (`/archive-registry`) will display a single table with an "Archive Type" column containing styled badges.

| Archive Type | When Applied | Badge Style |
|--------------|--------------|-------------|
| **Legacy Archive** | `flag_late_archive = FALSE` (archived before deadline) | Background: `#E8F2FD` (light blue), Text: `#084C9E` (UCSB blue) |
| **General Archive** | `flag_late_archive = TRUE` (archived after deadline) | Background: `#F3F3F3` (neutral gray), Text: `#505050` (dark gray) |

### Badge CSS

```css
/* Archive Type Badges (dai- prefix for namespace collision avoidance) */
.dai-archive-type-badge {
  display: inline-block;
  padding: 0.25em 0.75em;
  border-radius: 3px;
  font-size: 0.85em;
  font-weight: 500;
  white-space: nowrap;
}

.dai-archive-type-badge--legacy {
  background-color: #E8F2FD;
  color: #084C9E;
}

.dai-archive-type-badge--general {
  background-color: var(--dai-surface-bg);
  color: var(--dai-text-muted);
}
```

> **Note:** All classes use `dai-` prefix to avoid collisions with Bootstrap or other frameworks.

### Deadline Display Rules

| Page | Show ADA Deadline? | Rationale |
|------|-------------------|-----------|
| Archive Registry (`/archive-registry`) | **No** | Landing page should not reference specific compliance dates |
| Legacy Archive Detail (`/archive-registry/{id}`) | **Yes** | Individual legacy items need deadline context for compliance |
| General Archive Detail (`/archive-registry/{id}`) | **No** | Post-deadline archives don't claim ADA exemption |

### Views Configuration

The `views.view.public_archive.yml` will need:

1. **New field**: Archive Type (computed from `flag_late_archive`)
2. **Twig template**: Custom field template for badge rendering
3. **No deadline in header**: Remove or conditionally hide deadline references

### Template Logic (archive-detail.html.twig)

```twig
{% if is_legacy_archive %}
  {# Show full ADA notice with deadline #}
  <p>This material was <strong>created before {{ compliance_deadline }}</strong>...</p>
{% else %}
  {# Show simplified notice without deadline #}
  <p>This material is archived and retained for reference purposes...</p>
{% endif %}
```

### Accessibility Considerations

- Badges use sufficient color contrast (WCAG AA compliant)
- Badge text is descriptive (not color-dependent)
- Screen readers can distinguish archive types by text alone

---

## Implementation Decision

**Solution A (Automatic Date-Based Mode)** was implemented because:

1. Zero user friction - automatic classification based on date
2. Simplest implementation with no migration required
3. Leverages existing `flag_late_archive` field
4. Consistent behavior across all users

**Additional policy implemented:**
- Files/URLs with `exemption_void` records permanently lose Legacy Archive eligibility
- Any new archive entry for such files/URLs is automatically classified as General Archive
- This ensures compliance violations are permanently documented and cannot be circumvented
