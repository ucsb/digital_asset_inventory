# Field-Type Driven Scanning Specification

## Overview

This specification describes an enhancement to the Digital Asset Inventory scanner that dynamically discovers entity types based on their field storage types rather than relying on hardcoded entity type prefixes.

**Current approach:** The scanner uses hardcoded prefixes (`node__`, `paragraph__`, `taxonomy_term__`, `block_content__`) to determine which entity types to scan.

**Proposed approach:** Discover entity types dynamically by examining which content entity types have fields capable of containing or referencing digital assets.

### Benefits

| Benefit | Description |
|---------|-------------|
| **Drupal 11+ safe** | No hardcoded entity assumptions that may change between versions |
| **Custom module support** | Automatic discovery of custom entity types with relevant fields |
| **Gradual rollout** | Hybrid mode enables safe transition from fixed to dynamic discovery |
| **Admin control** | Denylist + allowlist + bundle controls provide flexibility |
| **Performance** | Cached discovery results avoid repeated field introspection |

### Scope & Limitations

Field-type discovery can automatically include **fieldable content entities** that store asset references in Field API fields.

| Entity Category | Field-Type Discovery | Notes |
|-----------------|---------------------|-------|
| Content entities with fields | Automatic | Nodes, paragraphs, taxonomy terms, custom content entities |
| Config entities | Not supported | Most config entities don't use Field API |
| Entities without Field API | Not supported | Requires custom scan source |

**Entities requiring custom scan sources:**
- Configuration entities (views, image styles, etc.)
- Entities that store data in custom tables without Field API
- External data sources (third-party APIs, legacy databases)

To scan non-fieldable entities, implement a custom scan source plugin (see Future Considerations).

---

## Discovery Modes

The scanner supports three discovery modes, controlled via configuration:

| Mode | Behavior | Use Case |
|------|----------|----------|
| `fixed` | Current hardcoded list only | Production stability, backward compatibility |
| `field_type` | Discovered + allowlist override | Full dynamic discovery |
| `hybrid` | Fixed list + discovered list | Safe rollout, testing new entity types |

### Mode Descriptions

**Fixed Mode (`fixed`)**
- Uses the current hardcoded entity type prefixes
- No dynamic discovery
- Recommended for sites that don't need custom entity support

**Field-Type Mode (`field_type`)**
- Discovers entity types dynamically based on field storage types
- Applies denylist to exclude system/internal entity types
- Applies allowlist to force-include specific entity types
- Full replacement for hardcoded list

**Hybrid Mode (`hybrid`)**
- Combines the hardcoded list with dynamically discovered types
- Useful for gradual rollout and testing
- Allows administrators to verify discovered types before switching fully

---

## Configuration Schema

```yaml
scan:
  # Discovery mode: 'fixed' | 'field_type' | 'hybrid'
  # This is the single source of truth for discovery behavior.
  # - fixed: no field-type discovery
  # - hybrid: field-type discovery ON (adds to fixed list)
  # - field_type: field-type discovery ON (replaces fixed list)
  discovery_mode: 'fixed'

  # Field types that indicate an entity may contain digital assets
  field_type_allowlist:
    - text_long
    - text_with_summary
    - link
    - file
    - entity_reference

  # For entity_reference fields, which target types indicate digital assets
  entity_reference_targets:
    media: true
    file: false

  # Denylist: Entity types to always exclude from scanning
  excluded_entity_types:
    - user
    - comment
    - shortcut
    - path_alias
    - search_api_task
    - search_api_index
    - redirect
    - webform_submission
    - queue_item

  # Allowlist override: Force include these entity types regardless of field analysis
  additional_entity_types:
    - webform
    - menu_link_content

  # Bundle-level controls (per entity type)
  bundle_overrides:
    node:
      include_bundles: []       # Empty = scan all bundles
      exclude_bundles: []
    paragraph:
      include_bundles: []
      exclude_bundles: ['admin_notes']  # Example: exclude admin-only bundles
    media:
      include_bundles: []
      exclude_bundles: ['remote_video']  # Example: exclude if handled separately
```

### Configuration Notes

- `discovery_mode` is the **single source of truth** for discovery behavior
  - `fixed` = no field-type discovery (default, backward compatible)
  - `hybrid` = field-type discovery ON, merged with fixed list
  - `field_type` = field-type discovery ON, replaces fixed list
- `field_type_allowlist` defines which field storage types indicate asset-capable content
- `entity_reference_targets` controls whether entity reference fields targeting specific types are considered
- `excluded_entity_types` takes precedence over discovery (denylist always wins)
- `additional_entity_types` forces inclusion even if not discovered (allowlist override)
- `bundle_overrides` provides per-entity-type bundle controls

**Note on `image` field type:** The `image` field type is not included in the default allowlist because it is a specialized `file` field type. Sites using file-based image fields (rather than media entities) may add `image` to `field_type_allowlist` if needed.

---

## Implementation

### Step 1: Discover Eligible Entity Types

Use Drupal's Entity Type Manager to find all content entity types:

```php
/**
 * Discovers content entity types eligible for scanning.
 *
 * @return array
 *   Array of entity type IDs that are content entities.
 */
protected function discoverContentEntityTypes(): array {
  $entity_types = [];
  $definitions = $this->entityTypeManager->getDefinitions();

  foreach ($definitions as $entity_type_id => $definition) {
    // Only process content entities (not config entities)
    if (!$definition instanceof ContentEntityTypeInterface) {
      continue;
    }

    // Skip config entities explicitly
    if ($definition instanceof ConfigEntityTypeInterface) {
      continue;
    }

    // Apply denylist
    if (in_array($entity_type_id, $this->getExcludedEntityTypes())) {
      continue;
    }

    $entity_types[] = $entity_type_id;
  }

  // Apply allowlist override (force include)
  foreach ($this->getAdditionalEntityTypes() as $entity_type_id) {
    if (!in_array($entity_type_id, $entity_types)) {
      $entity_types[] = $entity_type_id;
    }
  }

  return $entity_types;
}
```

### Step 2: Determine Scan-Worthy Entity Types

For each eligible entity type, check if it has fields that could contain digital assets:

```php
/**
 * Determines if an entity type supports asset scanning based on its fields.
 *
 * @param string $entity_type_id
 *   The entity type ID to check.
 *
 * @return bool
 *   TRUE if the entity type has asset-capable fields.
 */
protected function entityTypeSupportsAssetScanning(string $entity_type_id): bool {
  $field_type_allowlist = $this->getFieldTypeAllowlist();
  $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);

  foreach (array_keys($bundles) as $bundle) {
    // Check bundle-level overrides.
    if ($this->isBundleExcluded($entity_type_id, $bundle)) {
      continue;
    }

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

    foreach ($field_definitions as $field_name => $field_definition) {
      $field_type = $field_definition->getType();

      // Check if field type is in allowlist.
      if (in_array($field_type, $field_type_allowlist)) {
        // Special handling for entity_reference fields.
        if ($field_type === 'entity_reference') {
          $target_type = $field_definition->getSetting('target_type');
          if ($this->isEntityReferenceTargetEnabled($target_type)) {
            return TRUE;
          }
        }
        else {
          return TRUE;
        }
      }
    }
  }

  return FALSE;
}
```

### Step 3: Bundle-Level Controls

Optional fine-grained control at the bundle level:

```php
/**
 * Checks if a specific bundle should be excluded from scanning.
 *
 * @param string $entity_type_id
 *   The entity type ID.
 * @param string $bundle
 *   The bundle name.
 *
 * @return bool
 *   TRUE if the bundle should be excluded.
 */
protected function isBundleExcluded(string $entity_type_id, string $bundle): bool {
  $overrides = $this->config->get('scan.bundle_overrides') ?? [];

  // Check if this entity type has bundle overrides.
  if (!isset($overrides[$entity_type_id])) {
    return FALSE;
  }

  $entity_overrides = $overrides[$entity_type_id];

  // If include_bundles is specified and non-empty, only those bundles are scanned.
  if (!empty($entity_overrides['include_bundles'])) {
    return !in_array($bundle, $entity_overrides['include_bundles']);
  }

  // Check exclude_bundles.
  if (!empty($entity_overrides['exclude_bundles'])) {
    return in_array($bundle, $entity_overrides['exclude_bundles']);
  }

  return FALSE;
}
```

### Step 4: Cache Discovery Results

Cache the discovered entity types to avoid repeated introspection:

```php
/**
 * Gets the list of entity types to scan, with caching.
 *
 * @return array
 *   Array of entity type IDs to scan.
 */
public function getEntityTypesToScan(): array {
  $discovery_mode = $this->config->get('scan.discovery_mode') ?? 'fixed';

  // Fixed mode: return hardcoded list.
  if ($discovery_mode === 'fixed') {
    return $this->getFixedEntityTypes();
  }

  // Build cache ID with config hash to handle setting changes.
  $scan_config = $this->config->get('scan') ?? [];
  $config_hash = hash('sha256', serialize($scan_config));
  $cid = 'digital_asset_inventory:discovered_entity_types:' . $config_hash;

  // Check cache.
  $cache = $this->cache->get($cid);
  if ($cache) {
    return $cache->data;
  }

  // Discover entity types.
  $discovered = [];
  foreach ($this->discoverContentEntityTypes() as $entity_type_id) {
    if ($this->entityTypeSupportsAssetScanning($entity_type_id)) {
      $discovered[] = $entity_type_id;
    }
  }

  // Hybrid mode: merge with fixed list.
  if ($discovery_mode === 'hybrid') {
    $discovered = array_unique(array_merge($this->getFixedEntityTypes(), $discovered));
  }

  // Cache with appropriate tags.
  $this->cache->set($cid, $discovered, Cache::PERMANENT, [
    'config:digital_asset_inventory.settings',
    'entity_field_info',
  ]);

  return $discovered;
}
```

**Cache Strategy:**

- Cache ID includes a hash of scan configuration to handle setting changes
- Cache tag `config:digital_asset_inventory.settings` invalidates when module settings change
- Cache tag `entity_field_info` invalidates when field definitions change (new fields added, etc.)

---

## Denylist Defaults

The following entity types are excluded by default:

| Entity Type | Reason |
|-------------|--------|
| `user` | Privacy - contains personal data, opt-in only |
| `comment` | User-generated content, typically not part of site inventory |
| `path_alias` | System/routing entity, no content |
| `redirect` | System/routing entity, no content |
| `webform_submission` | Privacy - contains form submissions |
| `search_api_task` | Search API internal entity |
| `search_api_index` | Search API internal entity |
| `queue_item` | System queue entity |
| `shortcut` | Admin UI shortcuts |
| `content_moderation_state` | Workflow state tracking |
| `workspace` | Workspaces module internal |

**Note:** These defaults can be overridden via configuration. Administrators can remove items from the denylist or add items to the allowlist as needed.

---

## Usage Attribution Strategy

When scanning paragraph entities or other embedded entity types, usage needs to be attributed to the parent (host) entity to avoid confusion in reports.

### Parent Chain Context

For assets found in paragraphs:
1. Scan the paragraph entity for assets
2. Trace the parent chain to find the root entity (node, block_content)
3. Record usage against the host entity, not the paragraph

### Usage Record Structure

```
digital_asset_usage
├── asset_id (the digital asset)
├── entity_type (host entity type, e.g., 'node')
├── entity_id (host entity ID)
├── field_name (paragraph field → actual field path)
└── count
```

### "Used On / Section / Required Field" Preservation

The current scanner already handles paragraph tracing. Field-type discovery affects **which entity types are scanned**, not how asset usage is attributed or recorded.

---

## Rollout Plan

### Phase 1: Ship Safely (Fixed Mode Default)

- `discovery_mode: 'fixed'` is the default
- Field-type discovery code and configuration may ship, but is disabled by default
- No behavioral change for existing installations

### Phase 2: Add Guardrails Before Enabling Discovery

Add these safety controls to the Settings UI before exposing discovery as "on":

**Preview Discovery (non-destructive)**
- Button: **Preview Discovery**
- Shows what would be included without changing scan behavior
- Safe to run at any time

**Restore to Fixed Mode (safe reset)**
- Button: **Restore to Fixed Mode (Safe Reset)**
- Immediately returns scanning behavior to the known-safe default
- One-click recovery option

**Save settings snapshot before applying changes (recommended)**
- Checkbox (default checked): **Save a restore point before applying changes**
- Stores a timestamped snapshot of current DAI scan settings
- Provide a simple "Restore" dropdown if multiple snapshots exist
- Result: sites without Drush/config exports still have an easy rollback path

### Phase 3: Enable Discovery in Test Mode First (Optional)

Add a session-scoped option for safer experimentation:

- Toggle: **Enable discovery (test mode)**
- Applies discovery settings temporarily for:
  - Preview
  - A test scan
- Requires explicit **Confirm & Save** to persist changes
- If test mode is not implemented, snapshots + restore provide similar safety

### Phase 4: Preview Report (Expanded)

When discovery is enabled (test mode or saved), the preview report should show:

**For each entity type:**

| Column | Description |
|--------|-------------|
| Entity Type | Machine name and label |
| Current Status | Scanned now / Not scanned |
| Would Be Included | Yes / No |
| Why | Field types detected (e.g., `link`, `entity_reference → media`) |
| Estimated Count | Entity count (and optionally bundle counts) |
| Notes/Warnings | e.g., "large entity volume", "admin-only content", "privacy-sensitive" |

**Summary row (prominent):**
- Total entity types newly included
- Estimated additional entities
- Any "high-risk" types flagged

### Phase 5: Gradual Migration (Recommended Path)

The default migration path strongly encourages a staged rollout:

1. **Start in Hybrid mode**
   - `discovery_mode: 'hybrid'` (fixed + discovered)
   - Easiest to validate because you keep known coverage and only add new sources

2. **Review Preview Discovery**
   - Identify unexpected entity types
   - Adjust configuration:
     - **Denylist**: Exclude noise/system/admin entities
     - **Allowlist**: Force-include special entity types
     - **Bundle overrides**: Limit scope for heavy types

3. **Run a scan and validate**
   - Report clarity (no confusing "paragraph-only" rows)
   - Performance/runtime acceptable
   - Usage attribution is correct

4. **Switch to Field-type mode**
   - `discovery_mode: 'field_type'`
   - Once you're confident discovery output is correct and stable

---

## Migration Path

### Step-by-Step: Fixed → Hybrid → Field-type

**Step 1: Create a restore point**
- Use the built-in **Save settings snapshot** option (recommended)
- Or record settings manually via config export

**Step 2: Preview discovery**
- Click **Preview Discovery**
- Review the "newly included" list and warnings
- No changes applied yet

**Step 3: Enable discovery**
- Prefer **Test mode** if available (recommended)
- Otherwise enable discovery normally (but with snapshot enabled)
- Select **Hybrid mode** first
- Adds discovered entity types while keeping fixed coverage

**Step 4: Constrain scope**
- Add exclusions for unexpected entity types
- Add bundle overrides for heavy entities
- Add allowlist entries for special cases you want included

**Step 5: Run scan and validate**
- Confirm:
  - New coverage is helpful
  - Reports remain understandable
  - Runtime is acceptable

**Step 6: Switch to Field-type mode**
- When discovery results are stable and expected
- `discovery_mode: 'field_type'`

### Rollback Options (No Drush Required)

**Option A: Safe Reset (fastest)**
1. Click **Restore to Fixed Mode (Safe Reset)**
2. Clear caches if available
3. Re-run scan

**Option B: Restore from Snapshot (best)**
1. Select a saved snapshot (restore point)
2. Click **Restore**
3. Clear caches if available
4. Re-run scan

**Option C: Manual Revert (last resort)**
1. Set `discovery_mode: 'fixed'`
2. Disable field-type discovery
3. Remove denylist/allowlist overrides if needed
4. Re-run scan

### Data Migration

**No data migration is required.**

Discovery settings only affect what is scanned on future runs. Existing inventory and archive records remain unchanged.

---

## Future Considerations

### Performance Optimization

For very large sites, discovery and scanning can be optimized further:

**Lazy discovery (on-demand)**
- Defer entity-type discovery until a scan is initiated
- Cache the discovered results (with `entity_field_info` + module/config cache tags)
- Avoid repeated full discovery during routine page loads or settings edits

**Preview guidance for administrators**
- Include estimated entity counts in the Preview Discovery report
- Highlight "high-volume" entity types so administrators can:
  - Exclude them via denylist
  - Restrict to specific bundles
  - Roll out gradually via Hybrid mode

**Optional scan scope limiting**
- Allow discovery to be limited to:
  - "Public-facing" entity types only
  - An explicit allowlist of content entity types
- Useful for environments where scanning everything is unnecessary or too expensive

### Custom Field Type Support

Allow contributed modules to register their field types as scan-worthy:

```php
/**
 * Implements hook_digital_asset_inventory_field_types_alter().
 */
function mymodule_digital_asset_inventory_field_types_alter(array &$field_types) {
  $field_types[] = 'my_custom_media_field';
}
```

### Entity Type Discovery Hooks

Allow modules to influence discovery:

```php
/**
 * Implements hook_digital_asset_inventory_entity_types_alter().
 */
function mymodule_digital_asset_inventory_entity_types_alter(array &$entity_types) {
  // Force include a custom entity type.
  $entity_types[] = 'my_custom_content';

  // Remove an entity type from scanning.
  $key = array_search('unwanted_entity', $entity_types);
  if ($key !== FALSE) {
    unset($entity_types[$key]);
  }
}
```

### Scan Source Abstraction

Field-type discovery is the **default scan source** for content entities. Additional scan sources (e.g., config entities, custom tables, external systems) may be implemented via pluggable scan source handlers.

This architecture:

- Explains why non-fieldable entities are excluded from field-type discovery
- Creates a clean extension path for custom modules
- Prevents pressure to overload the discovery logic with special cases

