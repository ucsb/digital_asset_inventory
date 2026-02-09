# Digital Asset Inventory Module

Site-wide digital asset discovery, usage tracking, exportable inventory reports,
and ADA Title II–aligned archival management.

**Drupal Compatibility:** Drupal 10.x and 11.x

## Features

### Digital Asset Inventory

- **Asset Scanning**
  Scans managed files, media entities, orphaned files, and external assets, including file references across site content, menus, and configuration

- **Reference Mapping**
  Tracks where each asset is used across the site, providing visibility into file dependencies

- **Targeted Filtering**
  Identify orphaned, missing, or unused assets to support focused cleanup efforts

- **CSV Export**
  Download inventory reports to support remediation planning and coordinated removal with asset owners

- **Granular Deletion**
  Remove unused items individually while preserving site integrity and avoiding accidental content loss

- **Batch Processing**
  Designed to handle large sites with thousands of files

### Archival Management System (ADA Title II Support)

- **Legacy Archives**
  Supports classification of pre-deadline content eligible for ADA Title II legacy archive considerations

- **General Archives**
  Retains archived content for reference without exemption claims and subject to institutional accessibility policy

- **SHA-256 Integrity Checks**
  Verifies archived files against stored checksums to detect post-archive modification

- **Edit-to-Void Protection**
  Automatically voids archive status if archived content is modified after the archival date

- **Audit-Ready Records**
  Maintains a complete audit trail of archival actions to support compliance reviews

- **Archive-in-Use Support**
  Optionally archive documents that are still referenced by active content

- **Archive Link Routing**
  Archived content routes to dedicated Archive Detail Pages

- **Visibility Controls**
  Public or admin-only archive disclosure

- **Manual Archive Entries**
  Archive web pages and external URLs

- **Configurable Link Labels**
  Customize or disable the "(Archived)" label on links

- **External URL Normalization**
  Archived external URLs resolve consistently to Archive Detail Pages

- **Feature Toggle**
  Enable or disable archive functionality for phased rollout

## Disclaimer

The Digital Asset Inventory module is a content governance and asset management tool and is not an accessibility remediation system. Use of this module does not make digital content accessible, does not remediate accessibility issues, and does not bring files, media, or web pages into compliance with WCAG 2.1 AA. The module supports accessibility compliance efforts by helping identify unused assets, manage content lifecycle decisions, and apply consistent archiving practices with appropriate disclosure and access pathways. Responsibility for accessibility testing, remediation, and compliance with applicable accessibility standards remains with content owners and site administrators.

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

## Testing

The module includes unit and kernel test suites. All commands below run from the `web/` directory. Adjust the module path to match your installation (e.g., `modules/contrib/` or `modules/custom/`).

### Unit tests

Pure-logic tests with mocked services. No database required.

```bash
cd /path/to/drupal-site/web
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/{custom,contrib}/digital_asset_inventory/tests/src/Unit
```

### Kernel tests

Integration tests using SQLite with full Drupal kernel bootstrap.

```bash
cd /path/to/drupal-site/web
SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  modules/{custom,contrib}/digital_asset_inventory/tests/src/Kernel
```

**Note:** The `browser_output` directory warning is safe to ignore — it applies to browser/functional tests, not unit or kernel tests.

See `tests/README.md` for platform-specific instructions (macOS, Linux, WSL, Lando, DDEV), debug dump helpers, and troubleshooting. See `docs/testing/` for full test specifications.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.
