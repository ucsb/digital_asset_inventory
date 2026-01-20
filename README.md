# Digital Asset Inventory Module

Site-wide digital asset scanning, usage tracking, exportable reports,
and ADA Title II–compliant archiving tools.

**Drupal Compatibility:** Drupal 10.x and 11.x

## Features

- **File Scanning**: Scans managed files, media entities, and orphan files
- **Usage Tracking**: Identifies which pages/content use each asset
- **Filtering**: Filter by file type, source type, and usage status
- **CSV Export**: Download inventory reports with formatted file sizes
- **Batch Processing**: Handles large sites with thousands of files
- **ADA Archive System**: Archive documents for ADA Title II compliance
- **Archive Feature Toggle**: Enable/disable archive functionality for phased rollout

## Requirements

This module will automatically install these dependencies:

- `views_data_export` - For CSV export functionality
- `csv_serialization` - For CSV format support
- `better_exposed_filters` - For enhanced filter UI
- `responsive_tables_filter` - For responsive table display

## Installation

Choose your preferred installation method, then [enable the module](#enable-the-module):

- [Composer (Recommended)](#composer-recommended) - Standard Composer workflow
- [Install to custom directory](#optional-install-to-webmodulescustom) - For institution-owned modules
- [Manual Installation](#alternative-manual-installation) - Download and place manually
- [Git Clone](#alternative-git-clone) - Clone repository directly
- [Enable the Module](#enable-the-module) - Final step for all methods
- [Configure Permissions](#configure-permissions) - Assign user access

### Composer (Recommended)

#### Step 1: Add the GitHub Repository

Add the GitHub repository to your project's `composer.json`:

```bash
composer config repositories.digital_asset_inventory vcs https://github.com/ucsb/digital_asset_inventory
```

#### Step 2: Require the Package

```bash
composer require ucsb/digital_asset_inventory
```

This downloads the module to `web/modules/contrib/digital_asset_inventory`
and automatically installs required dependencies:

- `drupal/views_data_export`
- `drupal/csv_serialization`
- `drupal/better_exposed_filters`
- `drupal/responsive_tables_filter`

### Optional: Install to `web/modules/custom`

If your project keeps site-specific or institution-owned modules in
`web/modules/custom` instead of `web/modules/contrib`, add an installer
path override in your root `composer.json`:

```json
{
  "extra": {
    "installer-paths": {
      "web/modules/custom/{$name}": [
        "ucsb/digital_asset_inventory"
      ]
    }
  }
}
```

After adding this, running `composer require ucsb/digital_asset_inventory`
will install the module to `web/modules/custom/digital_asset_inventory`.

### Alternative: Manual Installation

If you prefer not to use Composer:

1. Download or clone the repository to `web/modules/contrib/digital_asset_inventory`
   (or `web/modules/custom/digital_asset_inventory`)
2. Install the required dependencies via Composer or manually
3. Enable the module with Drush or the Extend page

### Alternative: Git Clone

```bash
cd web/modules/custom
git clone https://github.com/ucsb/digital_asset_inventory.git
```

Then install dependencies:

```bash
composer require drupal/views_data_export drupal/csv_serialization drupal/better_exposed_filters drupal/responsive_tables_filter
```

### Enable the Module

After installing via any method above, enable the module:

```bash
drush en digital_asset_inventory -y
drush cr
```

### Configure Permissions

Navigate to **People > Permissions** and assign permissions based on user roles:

| Permission | Description |
| ---------- | ----------- |
| View digital asset inventory | Browse the inventory page |
| Scan digital assets | Run the asset scanner |
| Delete digital assets | Delete assets from inventory |
| Archive digital assets | Manage archive records |
| Administer digital assets | Full access including settings |

**Recommended role assignments:**

| Role | Permissions |
| ---- | ----------- |
| Site Editor | View, Scan |
| Site Manager | View, Scan, Delete |
| Accessibility Staff | View, Scan, Archive |
| Site Administrator | All permissions |

## Uninstall

Delete entities in order: archives, usage, items.

```bash
drush entity:delete digital_asset_archive -y
drush entity:delete digital_asset_usage -y
drush entity:delete digital_asset_item -y
drush pm:uninstall digital_asset_inventory -y
drush cr
```

## Usage

For detailed documentation on scanning, filtering, archiving, and troubleshooting,
see the [Quick Reference Guide](docs/guidance/quick-reference-guide.md).

**Quick start:**

1. Navigate to `/admin/digital-asset-inventory`
2. Click "Scan Site for Digital Assets"
3. View results, filter, and export CSV reports

**Key routes:**

| Path | Purpose |
| ---- | ------- |
| `/admin/digital-asset-inventory` | Main inventory |
| `/admin/digital-asset-inventory/archive` | Archive management |
| `/admin/config/accessibility/digital-asset-inventory` | Module settings |
| `/archive-registry` | Public Archive Registry |

---

## Version History

| Version | Date | Changes |
| ------- | ---- | ------- |
| 1.0.0 | Dec 2025 | Initial release with full feature set |
| 1.0.1 | Dec 2025 | Added compressed file support (zip, tar, gz, 7z, rar) |
| 1.1.0 | Dec 2025 | Added ADA Title II archive system with public Archive Registry |
| 1.2.0 | Dec 2025 | Archive audit safeguards: immutable classification date, visibility toggle, file deletion with record preservation, CSV audit export |
| 1.3.0 | Jan 2026 | Private file support: detection of private files, File Storage/File Access filters, login prompts for anonymous users |
| 1.4.0 | Jan 2026 | Exemption void status: automatic detection when Legacy Archive content is modified after archiving |
| 1.5.0 | Jan 2026 | Archive feature toggle, Drupal 11 compatibility, manual archive entries for pages/URLs, public archive page compliance updates, admin menu icon |
| 1.6.0 | Jan 2026 | Source type label updates (Manual Upload replaces Orphaned File), usage tracking for external assets and manual uploads, category filter fixes |
| 1.7.0 | Jan 2026 | Archived content banner for manually archived pages, edit protection with acknowledgment checkbox, automatic exemption voiding when archived content is edited, file URL blocking in manual archive form |
| 1.8.0 | Jan 2026 | Dual-purpose archive: Legacy Archives (pre-deadline, ADA exempt) vs General Archives (post-deadline, no exemption). Archive Type filter/badges, conditional form messaging, Purpose filter, updated CSV export (Name, Archive Type, consolidated Original URL) |
