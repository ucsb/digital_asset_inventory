# Installation Guide

Complete installation instructions for the Digital Asset Inventory module,
including GitHub authentication, CI/CD setup, platform-hosted builds,
custom directory installation, and Git-based workflows.

For a quick overview, see the [README](../../README.md#installation).

---

## Table of Contents

- [Composer (Recommended)](#composer-recommended)
  - [GitHub Authentication](#prerequisites-github-authentication)
  - [Add the Repository](#step-1-add-the-github-repository)
  - [Require the Package](#step-2-require-the-package)
  - [Pin to a Specific Version Without API Discovery](#pin-to-a-specific-version-without-api-discovery)
- [Install to `web/modules/custom`](#install-to-webmodulescustom)
- [Git Clone](#git-clone)
  - [Initial Setup](#initial-setup)
  - [Updating](#updating)
  - [Pinning to a Release](#pinning-to-a-release)
  - [Git Tracking](#git-tracking)
- [Enable the Module](#enable-the-module)
- [Configure Permissions](#configure-permissions)
- [Updating the Module](#updating-the-module)

---

## Composer (Recommended)

### Prerequisites: GitHub Authentication

Composer uses the GitHub API to resolve package metadata from `vcs` repositories.
Unauthenticated requests are limited to 60 per hour, which can cause `composer require`
and `composer update` to fail with 403 errors — especially in CI/CD pipelines or
on teams where multiple developers share an IP.

Configure a GitHub personal access token to raise the limit to 5,000 requests per hour:

#### For Local Development

```bash
composer config --global github-oauth.github.com ghp_YOUR_TOKEN_HERE
```

Generate a token at [github.com/settings/tokens](https://github.com/settings/tokens).
A classic token with `repo` scope (or a fine-grained token with read-only Contents
access to the repository) is sufficient.

#### For CI/CD Pipelines

Set the `COMPOSER_AUTH` environment variable in your pipeline configuration:

```bash
export COMPOSER_AUTH='{"github-oauth":{"github.com":"ghp_YOUR_TOKEN_HERE"}}'
```

Or commit an `auth.json` file (excluded from version control) to the project root:

```json
{
  "github-oauth": {
    "github.com": "ghp_YOUR_TOKEN_HERE"
  }
}
```

#### For Platform-Hosted Builds (Pantheon, Acquia, Platform.sh, etc.)

If your site uses a custom upstream with a platform-managed build pipeline,
Composer runs during the platform's build step — not on your local machine.
The token must be configured in the platform's environment, not in your
global Composer config. For example:

- **Pantheon:** `terminus secret:set <site> composer-auth '{"github-oauth":{"github.com":"ghp_YOUR_TOKEN_HERE"}}' --scope=ic`
- **Acquia:** Add `COMPOSER_AUTH` as an environment variable in Cloud IDE or pipeline settings
- **Platform.sh:** `platform variable:create --name env:COMPOSER_AUTH --value '{"github-oauth":{"github.com":"ghp_YOUR_TOKEN_HERE"}}'`

Refer to your platform's documentation for the exact steps. Do not commit
`auth.json` to an upstream repository — downstream sites inherit the file,
which would expose the token to every fork.

> **Troubleshooting:** If you encounter `Could not fetch` or `403` errors during
> `composer require`, verify your token is valid and has not expired.
> Run `composer config --global --list | grep github` to confirm the token is set.

### Step 1: Add the GitHub Repository

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

### Step 2: Require the Package

```bash
composer require ucsb/digital_asset_inventory
```

This downloads the module to `web/modules/contrib/digital_asset_inventory`
and automatically installs required dependencies:

- `drupal/views_data_export`
- `drupal/csv_serialization`
- `drupal/better_exposed_filters`

### Pin to a Specific Version Without API Discovery

If rate limits remain an issue (for example, in restricted CI environments without
token access), you can replace the `vcs` repository with a `package` repository
that points directly to a tagged release. This bypasses GitHub API discovery entirely:

```json
{
  "repositories": {
    "digital_asset_inventory": {
      "type": "package",
      "package": {
        "name": "ucsb/digital_asset_inventory",
        "version": "1.25.0",
        "type": "drupal-module",
        "source": {
          "url": "https://github.com/ucsb/digital_asset_inventory.git",
          "type": "git",
          "reference": "v1.25.0"
        }
      }
    }
  }
}
```

Then require the package as usual:

```bash
composer require ucsb/digital_asset_inventory:1.25.0
```

> **Note:** With this approach, Composer will not discover new versions automatically.
> To upgrade, update the `version` and `reference` values in `composer.json` and
> run `composer update ucsb/digital_asset_inventory`.

---

## Install to `web/modules/custom`

If your project keeps site-specific or institution-owned modules in
`web/modules/custom` instead of `web/modules/contrib`:

**Step 1:** Add the repository (same as above — see [Step 1](#step-1-add-the-github-repository))

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

---

## Git Clone

For teams that manage modules outside of Composer's dependency tree, you can
clone the repository directly. All commands assume you start from the Drupal
project root (the directory containing `web/`, `vendor/`, and `composer.json`).

### Initial Setup

```bash
# 1. Create the target directory if it doesn't exist
mkdir -p web/modules/custom

# 2. Clone the module
cd web/modules/custom
git clone https://github.com/ucsb/digital_asset_inventory.git

# 3. Return to the project root and install dependencies
cd /path/to/drupal-project
composer require drupal/views_data_export drupal/csv_serialization drupal/better_exposed_filters

# 4. Enable the module
drush en digital_asset_inventory -y
drush cr
```

### Updating

Pull the latest changes and run database updates:

```bash
cd web/modules/custom/digital_asset_inventory
git pull
drush updb -y
drush cr
```

### Pinning to a Release

To pin to a specific tagged release instead of tracking the latest:

```bash
cd web/modules/custom/digital_asset_inventory
git fetch --tags
git checkout v1.25.0
drush updb -y
drush cr
```

> **Tip:** Checking out a tag puts the repository in "detached HEAD" state.
> This is expected and not an error — it means you are on an exact release
> rather than tracking a branch. Run `git checkout main` to return to the
> latest development branch.

### Git Tracking

If your project's `.gitignore` excludes `web/modules/custom/*/`, the cloned
module won't be tracked by your project repository. This is the intended
behavior — the module has its own Git history and is updated independently.

If your project **does** track `web/modules/custom/`, add an exclusion to
avoid committing the module's full Git history into your project:

```gitignore
web/modules/custom/digital_asset_inventory/
```

---

## Enable the Module

After installing via any method above, enable the module:

```bash
drush en digital_asset_inventory -y
drush cr
```

---

## Configure Permissions

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

---

## Updating the Module

**Composer installations:**

```bash
composer update ucsb/digital_asset_inventory
drush updb -y
drush cr
```

**Git Clone installations:**

```bash
cd web/modules/custom/digital_asset_inventory
git pull
drush updb -y
drush cr
```

Re-scan if the changelog mentions scanner improvements.
