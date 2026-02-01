# Digital Asset Inventory Module

Site-wide digital asset scanning, usage tracking, exportable reports,
and ADA Title II–compliant archiving tools.

**Drupal Compatibility:** Drupal 10.x and 11.x

## Features

- **File Scanning**: Scans managed files, media entities, remote videos (YouTube/Vimeo), and orphan files
- **Usage Tracking**: Identifies which pages/content use each asset, including menu links
- **Filtering**: Filter by file type, source type, usage status, and more
- **CSV Export**: Download inventory reports with formatted file sizes
- **Batch Processing**: Handles large sites with thousands of files
- **ADA Archive System**: Archive documents for ADA Title II compliance
  - **Dual-Purpose Archives**: Legacy Archives (pre-deadline, ADA exempt) vs General Archives (post-deadline)
  - **Archive-in-Use Support**: Optionally archive documents while still referenced in content
  - **Link Routing**: Automatic redirection to Archive Detail Pages for archived content
  - **Admin-Only Visibility**: Control public vs admin-only disclosure of archived content
  - **Manual Entries**: Archive web pages and external URLs
- **Archive Feature Toggle**: Enable/disable archive functionality for phased rollout

## Requirements

This module will automatically install these dependencies:

- `views_data_export` - For CSV export functionality
- `csv_serialization` - For CSV format support
- `better_exposed_filters` - For enhanced filter UI

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

Add the GitHub repository to your project's `composer.json`.

**Option A: Using Composer command**

```bash
composer config repositories.digital_asset_inventory vcs https://github.com/ucsb/digital_asset_inventory
```

**Option B: Manual edit**

Add to the `repositories` section of your project's root `composer.json`:

```json
{
  "repositories": {
    "digital_asset_inventory": {
      "type": "vcs",
      "url": "https://github.com/ucsb/digital_asset_inventory"
    }
  }
}
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

### Optional: Install to `web/modules/custom`

If your project keeps site-specific or institution-owned modules in
`web/modules/custom` instead of `web/modules/contrib`:

**Step 1:** Add the repository (same as above - see [Step 1](#step-1-add-the-github-repository))

**Step 2:** Add an installer path override in your project's root `composer.json`.
The package-specific entry must appear **before** the generic `type:drupal-module`
entry to take precedence:

```json
{
  "repositories": {
    "digital_asset_inventory": {
      "type": "vcs",
      "url": "https://github.com/ucsb/digital_asset_inventory"
    }
  },
  "extra": {
    "installer-paths": {
      "web/modules/custom/{$name}": ["ucsb/digital_asset_inventory", "type:drupal-custom-module"],
      "web/modules/contrib/{$name}": ["type:drupal-module"]
    }
  }
}
```

**Step 3:** Require the package

```bash
composer require ucsb/digital_asset_inventory
```

If the module is already installed to `contrib`, remove and reinstall:

```bash
composer remove ucsb/digital_asset_inventory
composer require ucsb/digital_asset_inventory
```

The module will now install to `web/modules/custom/digital_asset_inventory`.

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
composer require drupal/views_data_export drupal/csv_serialization drupal/better_exposed_filters
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
| Archive digital assets | Manage archive records and add internal notes |
| View digital asset archives | View archive management and notes (read-only, for auditors) |
| Administer digital assets | Full access including settings |

**Recommended role assignments:**

| Role | Permissions |
| ---- | ----------- |
| Site Editor | View, Scan |
| Site Manager | View, Scan, Delete |
| Accessibility Staff | View, Scan, Archive |
| Internal Auditor | View Digital Asset Archives (read-only) |
| Site Administrator | All permissions |

## Uninstall

Delete entities in order: notes, archives, usage, items.

```bash
drush entity:delete dai_archive_note -y
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
| `/admin/digital-asset-inventory/archive/{id}/notes` | Internal notes for archive |
| `/admin/config/accessibility/digital-asset-inventory` | Module settings |
| `/archive-registry` | Public Archive Registry |

---

## Changelog

| Version | Date | Changes |
| ------- | ---- | ------- |
| 1.0.0 | Dec 2025 | Initial release with full feature set |
| 1.0.1 | Dec 2025 | Added compressed file support (zip, tar, gz, 7z, rar) |
| 1.1.0 | Dec 2025 | Added ADA Title II archive system with public Archive Registry |
| 1.2.0 | Dec 2025 | Archive audit safeguards: immutable classification date, visibility toggle, file deletion with record preservation, CSV audit export |
| 1.3.0 | Jan 2026 | Private file support: detection of private files, File Storage/File Access filters, login prompts for anonymous users |
| 1.4.0 | Jan 2026 | Exemption void status: automatic detection when Legacy Archive content is modified after archiving |
| 1.5.0 | Jan 2026 | Archive feature toggle, Drupal 11 compatibility, manual archive entries for pages/URLs, admin menu icon |
| 1.6.0 | Jan 2026 | Source type label updates, usage tracking for external assets and manual uploads, category filter fixes |
| 1.7.0 | Jan 2026 | Archived content banner, edit protection with acknowledgment checkbox, automatic exemption voiding |
| 1.8.0 | Jan 2026 | Dual-purpose archive: Legacy Archives (pre-deadline, ADA exempt) vs General Archives (post-deadline) |
| 1.9.0 | Jan 2026 | Simplified archive lifecycle: removed requeue functionality, unarchiving sets `archived_deleted` status |
| 1.10.0 | Jan 2026 | WCAG accessibility improvements, visibility defaults to Public |
| 1.11.0 | Jan 2026 | Theme-agnostic admin UI with CSS variables for theming |
| 1.12.0 | Jan 2026 | Internal notes system: append-only notes log, dedicated notes page, `archived_by` records executor |
| 1.13.0 | Jan 2026 | Taxonomy term archiving, page URL autocomplete for manual archive form |
| 1.14.0 | Jan 2026 | Permission simplification: `view digital asset archives` for read-only auditor access |
| 1.15.0 | Jan 2026 | Usage page Media-aware enhancements: thumbnail, alt text status, Media actions |
| 1.16.0 | Jan 2026 | Remote video media scanning (YouTube, Vimeo via Media Library) |
| 1.17.0 | Jan 2026 | Archive-in-use support: archive documents/videos while still referenced in content |
| 1.18.0 | Jan 2026 | Menu link file scanning: detect file references in menu links |
| 1.19.0 | Jan 2026 | Archive link routing: automatic redirection to Archive Detail Pages |
| 1.20.0 | Jan 2026 | Admin-only visibility controls disclosure, conditional display for anonymous users |
