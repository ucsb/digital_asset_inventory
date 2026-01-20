# Archive Feature Toggle Specification

This document specifies the implementation of the Archive enable/disable configuration setting.

## Purpose

Allow site administrators to enable or disable Archive functionality, supporting a phased rollout where users start with inventory-only and enable Archive when ready.

## Configuration Setting

| Setting | Type | Default | Location |
|---------|------|---------|----------|
| `enable_archive` | boolean | `FALSE` | `digital_asset_inventory.settings` |

**Rationale for default FALSE:**
- Archive is an opt-in feature
- Users start with simpler inventory-only experience
- Archive can be enabled when compliance requirements apply

## Behavior When Disabled

### Routes (Access Denied)
All archive-related routes return 403 Forbidden:

| Route | Path |
|-------|------|
| `digital_asset_inventory.archive_asset` | `/admin/digital-asset-inventory/archive/{id}` |
| `digital_asset_inventory.execute_archive` | `/admin/digital-asset-inventory/archive/execute/{id}` |
| `digital_asset_inventory.cancel_archive` | `/admin/digital-asset-inventory/archive/cancel/{id}` |
| `digital_asset_inventory.archive_csv_export` | `/admin/digital-asset-inventory/archive/csv` |
| `digital_asset_inventory.add_manual_archive` | `/admin/digital-asset-inventory/archive/add` |
| `digital_asset_inventory.edit_manual_archive` | `/admin/digital-asset-inventory/archive/edit/{id}` |
| `digital_asset_inventory.unarchive` | `/admin/digital-asset-inventory/archive/unarchive/{id}` |
| `digital_asset_inventory.delete_archived_file` | `/admin/digital-asset-inventory/archive/delete-file/{id}` |
| `digital_asset_inventory.delete_manual_archive` | `/admin/digital-asset-inventory/archive/delete-manual/{id}` |
| `digital_asset_inventory.toggle_archive_visibility` | `/admin/digital-asset-inventory/archive/toggle-visibility/{id}` |
| `digital_asset_inventory.archive_detail` | `/archive-registry/{id}` |
| `view.digital_asset_archive.page_archive_management` | `/admin/digital-asset-inventory/archive` |
| `view.public_archive.page_1` | `/archive-registry` |

### Menu Links (Hidden)
The following menu links are not displayed:
- "Archive Management" (`digital_asset_inventory.archive_management`)
- "View Archive Registry" (`digital_asset_inventory.public_archive`)

### Inventory View (Hidden Elements)
- No "Queue for Archive" button in operations dropdown
- No archive status badges on documents/videos

### Data Preservation
- Existing `digital_asset_archive` records are NOT deleted
- Records remain in database, just inaccessible via UI
- Re-enabling Archive restores access to all previous records

## Implementation

### 1. Settings Form
**File:** `src/Form/SettingsForm.php`

Add fieldset with checkbox:
```php
$form['archive'] = [
  '#type' => 'fieldset',
  '#title' => $this->t('Archive Settings'),
];

$form['archive']['enable_archive'] = [
  '#type' => 'checkbox',
  '#title' => $this->t('Enable Archive functionality'),
  '#description' => $this->t('When enabled, documents can be archived for ADA Title II compliance. Disabling hides archive features but preserves existing records.'),
  '#default_value' => $config->get('enable_archive') ?? FALSE,
];
```

### 2. Helper Function
**File:** `digital_asset_inventory.module`

```php
/**
 * Check if Archive functionality is enabled.
 *
 * @return bool
 *   TRUE if archive is enabled, FALSE otherwise.
 */
function digital_asset_inventory_archive_enabled() {
  return (bool) \Drupal::config('digital_asset_inventory.settings')->get('enable_archive');
}
```

### 3. Route Access Check
**File:** `src/Access/ArchiveAccessCheck.php` (NEW)

```php
<?php

namespace Drupal\digital_asset_inventory\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks if Archive functionality is enabled.
 */
class ArchiveAccessCheck implements AccessInterface {

  /**
   * Checks access based on archive configuration.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    $enabled = \Drupal::config('digital_asset_inventory.settings')->get('enable_archive');
    return AccessResult::allowedIf((bool) $enabled)
      ->addCacheTags(['config:digital_asset_inventory.settings']);
  }

}
```

### 4. Service Registration
**File:** `digital_asset_inventory.services.yml`

```yaml
services:
  digital_asset_inventory.archive_access_check:
    class: Drupal\digital_asset_inventory\Access\ArchiveAccessCheck
    tags:
      - { name: access_check, applies_to: _archive_enabled }
```

### 5. Route Requirements
**File:** `digital_asset_inventory.routing.yml`

Add to all archive routes:
```yaml
requirements:
  _archive_enabled: 'TRUE'
  _permission: 'archive digital assets'
```

### 6. Menu Link Alteration
**File:** `digital_asset_inventory.module`

```php
/**
 * Implements hook_menu_links_discovered_alter().
 */
function digital_asset_inventory_menu_links_discovered_alter(&$links) {
  if (!digital_asset_inventory_archive_enabled()) {
    unset($links['digital_asset_inventory.archive_management']);
    unset($links['digital_asset_inventory.public_archive']);
  }
}
```

### 7. Conditional UI in Module File
**File:** `digital_asset_inventory.module`

Wrap archive-related UI logic:
```php
// In digital_asset_inventory_preprocess_views_view_field()
if (digital_asset_inventory_archive_enabled() && ($category === 'Documents' || $category === 'Videos')) {
  // Archive badge and button logic
}
```

### 8. Config Schema
**File:** `config/schema/digital_asset_inventory.schema.yml`

```yaml
digital_asset_inventory.settings:
  type: config_object
  label: 'Digital Asset Inventory settings'
  mapping:
    enable_archive:
      type: boolean
      label: 'Enable archive functionality'
```

### 9. Default Config
**File:** `config/install/digital_asset_inventory.settings.yml`

```yaml
enable_archive: false
```

## Caching Considerations

- Access results include cache tag `config:digital_asset_inventory.settings`
- Menu links require cache rebuild after toggle (`drush cr`)
- Inventory view respects config changes on next page load

## Verification Checklist

### When Disabled (enable_archive = FALSE)
- [ ] Settings page shows unchecked "Enable Archive functionality"
- [ ] Inventory view: No "Queue for Archive" button on documents
- [ ] Inventory view: No archive badges on documents
- [ ] Menu: No "Archive Management" link
- [ ] Menu: No "View Archive Registry" link
- [ ] URL `/admin/digital-asset-inventory/archive`: Access denied (403)
- [ ] URL `/archive-registry`: Access denied (403)
- [ ] Existing archive records preserved in database

### When Enabled (enable_archive = TRUE)
- [ ] Settings page shows checked "Enable Archive functionality"
- [ ] Inventory view: "Queue for Archive" button appears on documents
- [ ] Inventory view: Archive badges display for archived documents
- [ ] Menu: "Archive Management" link visible
- [ ] Menu: "View Archive Registry" link visible
- [ ] URL `/admin/digital-asset-inventory/archive`: Accessible
- [ ] URL `/archive-registry`: Accessible
- [ ] All previous archive records accessible

## Files Modified

| File | Change |
|------|--------|
| `src/Form/SettingsForm.php` | Add checkbox field |
| `digital_asset_inventory.module` | Add helper + wrap UI + menu hook |
| `src/Access/ArchiveAccessCheck.php` | NEW - Access checker class |
| `digital_asset_inventory.services.yml` | Register access checker |
| `digital_asset_inventory.routing.yml` | Add `_archive_enabled` to routes |
| `config/schema/digital_asset_inventory.schema.yml` | Add schema |
| `config/install/digital_asset_inventory.settings.yml` | Add default |
