# Archived Link Label Configuration & External URL Routing Specification

## Overview

This specification documents two related features:

1. **Configurable Archived Link Label** - Administrators can enable/disable and customize the text label (e.g., "(Archived)") that appears on links to archived content.

2. **External URL Routing** - Archived external URLs (manual archive entries) in content are routed to the Archive Detail Page, similar to how archived files are handled.

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Trailing slashes | Normalize (remove) | `example.com/page/` → `example.com/page` for consistent matching |
| Label scope | Global only | Single setting applies to all surfaces (menus, breadcrumbs, content) |
| Query strings | Include in match | Different query strings = different resources |
| URL storage | Original URL preserved | Archive records store original URL for display; normalization at comparison time only |

---

## Feature 1: Configurable Archived Link Label

### Configuration Settings

Two new settings in `digital_asset_inventory.settings`:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `show_archived_label` | boolean | `true` | Whether to display the archived label on links |
| `archived_label_text` | string | `Archived` | The text to display (parentheses added automatically) |

### Settings Form

Located in the Archive Settings section of `/admin/config/accessibility/digital-asset-inventory`:

- **Show archived label on links** (checkbox)
  - Only visible when `enable_archive` is checked
  - Controls whether any label appears on archived links

- **Archived label text** (textfield)
  - Only visible when both `enable_archive` and `show_archived_label` are checked
  - Validates that text is non-empty when labeling is enabled
  - Default: "(Archived)"

### Affected Surfaces

When enabled, the archived label appears on:

| Surface | Implementation | Example Output |
|---------|----------------|----------------|
| CKEditor links | `ArchiveFileLinkFilter` | `Document Name (Archived)` |
| CKEditor media embeds | `ArchiveFileLinkFilter` | `Video Name (Archived)` |
| File field links | `hook_preprocess_file_link` | `report.pdf (Archived)` |
| Menu links | `menu_archive_links.js` | `Downloads (Archived)` |
| Breadcrumbs | `hook_system_breadcrumb_alter` | `Reports (Archived)` |
| Link fields | `hook_link_alter` | `External Resource (Archived)` |
| Media displays | `hook_preprocess_media` | `Presentation (Archived)` |

### Behavior When Disabled

When `show_archived_label` is `false`:
- Links still route to Archive Detail Page
- No visible label is appended to link text
- Image links still get `title` attribute for accessibility

### Pages Excluded from Link Rewriting

The following pages are excluded from automatic link rewriting to prevent recursive or inappropriate rewriting:

| Page | Path | Reason |
|------|------|--------|
| Archive Registry (listing) | `/archive-registry` | Archive list page - links are to detail pages, not original URLs |
| Archive Detail Page | `/archive-registry/{id}` | The "Visit Link" should point to the original URL without modification |
| Admin pages | `/admin/*` | Editing contexts should show original URLs |

### ArchiveService Helper Methods

```php
/**
 * Check if archived label should be shown on links.
 */
public function shouldShowArchivedLabel(): bool;

/**
 * Get the configured archived label text.
 */
public function getArchivedLabel(): string;
```

---

## Feature 2: External URL Routing

### Problem Statement

Previously, only file-based archives and internal page archives were routed to the Archive Detail Page. External URLs (e.g., Google Docs, external PDFs) added via manual archive were not routed.

### Solution

External URLs in content are now matched and routed to their Archive Detail Page when:
1. The archive feature is enabled (`enable_archive = true`)
2. The external URL has an active archive record (`archived_public` or `archived_admin`)

### URL Normalization

External URLs are normalized **at comparison time** (not during storage) to ensure consistent matching while preserving the original URL for display:

```php
/**
 * Normalize a URL for consistent matching.
 *
 * Rules:
 * - Lowercase scheme and host
 * - Remove default ports (:80 for http, :443 for https)
 * - Remove trailing slash from path (except root "/")
 * - Preserve query strings (they're significant)
 * - Remove fragment identifiers (client-side only)
 */
public function normalizeUrl(string $url): string;
```

### Examples

| Input URL | Normalized URL |
|-----------|----------------|
| `HTTPS://Docs.Google.Com/document/d/abc` | `https://docs.google.com/document/d/abc` |
| `https://example.com/page/` | `https://example.com/page` |
| `http://example.com:80/path` | `http://example.com/path` |
| `https://example.com/doc?id=123` | `https://example.com/doc?id=123` |
| `https://example.com/page#section` | `https://example.com/page` |

### Implementation Points

1. **ManualArchiveForm** - Stores original external URLs as-is (preserves URL for display on Archive Detail Page)
2. **ArchiveService::getArchiveRecordForBadge()** - Normalizes inventory URLs at comparison time to find matching archive record
3. **ArchiveLinkResponseSubscriber** - Stores both original and normalized URL variants for matching; routes external URLs to Archive Detail Page
4. **Update hook 10035** - Adds default values for label configuration settings

### Archive Badge Display

External assets in the inventory will display an "Archived" badge when:
1. The external URL has been added to the Archive Registry via manual archive
2. The URLs match after normalization during comparison

**Design Decision:** Both the inventory and archive records store **original URLs** as-is (for display purposes). Normalization happens **at comparison time only** in `getArchiveRecordForBadge()` - both the inventory URL and the archive record's `original_path` are normalized before comparison.

The `getArchiveRecordForBadge()` method matches by:
- `fid` (file ID) for file-based assets
- `original_path` for external assets (normalizes both sides before comparison)

---

## Schema Definition

```yaml
digital_asset_inventory.settings:
  type: config_object
  label: 'Digital Asset Inventory settings'
  mapping:
    # ... existing settings ...
    show_archived_label:
      type: boolean
      label: 'Show archived label on links'
    archived_label_text:
      type: string
      label: 'Archived label text'
```

---

## Update Hooks

### update_10035: Add Label Config Defaults

Adds default values for new label configuration:

```php
function digital_asset_inventory_update_10035() {
  $config->set('show_archived_label', TRUE);
  $config->set('archived_label_text', 'Archived');
}
```

**Note:** URLs are NOT normalized during storage. Both inventory and archive records retain original URLs for display purposes. Normalization happens at comparison time only in `getArchiveRecordForBadge()` and `ArchiveLinkResponseSubscriber`.

---

## JavaScript Integration

The `menu_archive_links.js` file receives label configuration via drupalSettings:

```javascript
var showLabel = settings.digitalAssetInventory?.showArchivedLabel !== false;
var labelText = settings.digitalAssetInventory?.archivedLabelText || 'Archived';
```

Settings are passed in `hook_page_attachments`:

```php
$attachments['#attached']['drupalSettings']['digitalAssetInventory']['showArchivedLabel'] = $archive_service->shouldShowArchivedLabel();
$attachments['#attached']['drupalSettings']['digitalAssetInventory']['archivedLabelText'] = $archive_service->getArchivedLabel();
```

---

## Cache Invalidation

When label settings change:
- `rendered` cache tag is invalidated
- All pages with archived links will re-render with new label

Cache tags used:
- `digital_asset_archive_list` - When archives change
- `config:digital_asset_inventory.settings` - When settings change

---

## Files Modified

| File | Changes |
|------|---------|
| `config/install/digital_asset_inventory.settings.yml` | Added `show_archived_label`, `archived_label_text` |
| `config/schema/digital_asset_inventory.schema.yml` | Added schema for new settings |
| `src/Form/SettingsForm.php` | Added label config form fields with #states visibility |
| `src/Service/ArchiveService.php` | Added `shouldShowArchivedLabel()`, `getArchivedLabel()`, `normalizeUrl()`; updated `getArchiveRecordForBadge()` to normalize URLs at comparison time |
| `src/Form/ManualArchiveForm.php` | Stores original external URLs (no normalization); matching uses normalized comparison |
| `src/EventSubscriber/ArchiveLinkResponseSubscriber.php` | Use label helper, store multiple URL variants (original + normalized) for matching |
| `src/Plugin/Filter/ArchiveFileLinkFilter.php` | Use label helper methods |
| `digital_asset_inventory.module` | Updated hooks to use label helper, pass settings to JS |
| `digital_asset_inventory.install` | Added update hook 10035 for label config defaults |
| `js/menu_archive_links.js` | Use configurable label from drupalSettings |

---

## Test Cases

### Label Configuration Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| Default behavior | Enable archive, archive a document | Label shows "(Archived)" on all surfaces |
| Disable label | Uncheck "Show archived label" | Label disappears, links still route to Archive Detail |
| Custom text | Set label to "Legacy" | "Legacy" appears instead of "Archived" |
| Empty text validation | Clear label text, save | Validation error shown |

### External URL Routing Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| Create external archive | Add manual entry for `https://docs.google.com/document/d/abc123` | Entry created with original URL (displayed as-is on Archive Detail Page) |
| Link routing | Add link to that URL in content | Link routes to Archive Detail Page |
| Label on external | View page with external archived link | "(Archived)" label appears |
| URL variations | Link with trailing slash | Matches via normalized comparison (original URL preserved in archive record) |

### URL Normalization Tests

| Input | Expected Normalized Output |
|-------|---------------------------|
| `HTTPS://Example.Com/Path/` | `https://example.com/path` |
| `http://example.com:80/doc` | `http://example.com/doc` |
| `https://example.com:443/doc` | `https://example.com/doc` |
| `https://example.com/doc?a=1&b=2` | `https://example.com/doc?a=1&b=2` |
| `https://example.com/doc#section` | `https://example.com/doc` |

### Regression Tests

| Test | Expected Result |
|------|-----------------|
| Internal file routing | Still works |
| Internal page routing | Still works |
| Images NOT routed | Would break rendering |
| Unarchived links | Remain unchanged |
| Admin-only archives | Still route correctly |

---

## Accessibility Considerations

- Label text is part of the link, readable by screen readers
- Image links get `title` attribute with archived indicator
- No reliance on color alone to indicate archived status
- Label styling uses semantic markup (`<span class="dai-archived-label">`)

---

## Related Documentation

- [Archive In-Use Spec](archive-in-use-spec.md) - Link routing architecture
- [Theme-Agnostic Public UI Spec](../ui-specs/theme-agnostic-public-ui-spec.md) - Link styling
- [Archive UX Spec Index](archive-ux-spec-index.md) - Overview of archive UX features
