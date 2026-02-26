# Digital Asset Inventory Dashboard UI

**Route:** `/admin/digital-asset-inventory/dashboard`
**Permission:** `view digital asset inventory` (existing)
**Version:** 1.33.0

---

## Overview

A visual dashboard providing at-a-glance inventory health, usage breakdown, and archive status. Uses Chart.js charts with progressive-enhancement table fallbacks and chart/table toggle buttons.

**Key constraints:**

- No external build dependencies (no npm/CDN/Composer for Chart.js — vendored UMD build at `js/vendor/chart.min.js`)
- Database-level aggregation only — entity objects are never loaded or instantiated for dashboard metrics
- Refined Gray-Blue Sequential color palette (colorblind-safe) with 3:1+ non-text contrast; tables provide accessible alternatives; charts do not rely on color alone
- Chart/table toggle button for each chart section (keyboard accessible, ARIA `aria-pressed` state)
- Responsive tables with CSS-only stacked layout on mobile using `data-label` attributes
- Multisite safe
- Uses existing CSS variables from `dai-base.css` (provided via `digital_asset_inventory/admin` library dependency)

---

## Schema Assumptions

All queries target the module's custom entity tables. Key fields referenced:

| Table | Fields Used | Notes |
| ----- | ----------- | ----- |
| `digital_asset_item` | `id`, `is_temp`, `category`, `sort_order`, `filesize`, `source_type`, `file_name`, `file_path` | `category` is a string (Documents, Videos, etc.); `sort_order` is an int column set by the scanner per `DigitalAssetScanner::getCategorySortOrder()` — stored on each row at scan time, not config-driven |
| `digital_asset_usage` | `id`, `asset_id`, `signals_evaluated` | `signals_evaluated` is `TINYINT(1)` — 1 = evaluated, 0 = not evaluated |
| `dai_orphan_reference` | `id`, `asset_id` | Separate table (not a flag on usage); introduced in v1.30.0 |
| `digital_asset_archive` | `id`, `status`, `flag_late_archive`, `archive_reason` | `status` enum: queued, archived_public, archived_admin, archived_deleted, exemption_void |

---

## Fallback Strategy: Progressive Enhancement

Tables are **always rendered** in the HTML (not inside `<noscript>`). Chart.js canvases are hidden by default.

**Per-chart toggling:** Each chart section wrapper has a data attribute (e.g., `data-dai-chart="category"`). When a chart initializes successfully, JS adds a `.dai-chart-active` class to **that section's wrapper only**. CSS rules:

- `.dai-chart-active .dai-chart-canvas` — shown
- `.dai-chart-active .dai-chart-fallback` — hidden
- Without `.dai-chart-active` — canvas hidden, table visible (default)

**Wrapper selector:** Classes are applied to individual `.dai-chart-section` elements inside the `.dai--dashboard` container. No global toggle — each chart stands alone.

If a specific chart fails to init (caught by per-chart try/catch):

- That section's table remains visible (`.dai-chart-active` never added)
- An inline "Chart unavailable" message replaces the canvas area in that section, styled with `.messages--warning` for visual consistency with Drupal admin messaging
- The `<canvas>` stays hidden
- Other successfully initialized charts are unaffected

This ensures:

- **JS disabled**: all tables visible, all canvases hidden
- **JS enabled, all charts OK**: all canvases visible, all tables hidden
- **JS enabled, one chart fails**: failed chart shows table + warning; other charts show canvases normally
- **Screen readers**: always have table data regardless of JS state

---

## UI Layout

### Summary Bar (always visible)

A row of stat cards at the top showing key numbers:

| Stat | Source | Conditional |
| ---- | ------ | ----------- |
| Total Assets | `getTotalAssetCount()` | Always |
| In Use | `getUsageBreakdown()['in_use']` | Always |
| Unused | `getUsageBreakdown()['unused']` | Always |
| Archived | `getTotalArchivedCount()` | Only when `enable_archive = TRUE` |

Each card shows a large number with a label underneath. Numbers use `aria-label` for screen reader context (e.g., "142 total assets"). The grid uses `auto-fit` so it adapts to 3 or 4 cards depending on whether archiving is enabled.

### Section 1: Inventory Overview (always visible)

**Two charts side-by-side (2-column grid):**

| Chart | Type | Data |
| ----- | ---- | ---- |
| Assets by Category | Horizontal Bar | `getCategoryBreakdown()` — Documents, Videos, Images, etc. |
| Usage Status | Doughnut | `getUsageBreakdown()` — In Use / Unused |

### Section 2: Location & Top Assets (always visible)

**Two panels side-by-side (2-column grid):**

| Panel | Type | Data |
| ----- | ---- | ---- |
| Assets by Location | Pie | `getLocationBreakdown()` — Upload / Media / Server / External |
| Top Assets by Usage | HTML table | `getTopAssetsByUsage(10)` — top 10 assets by usage count |

**Top Assets by Usage table columns:**

| Column | Description |
| ------ | ----------- |
| # | Rank |
| File Name | Truncated to 40 chars, linked via inventory usage detail view |
| Category | Documents, Videos, etc. |
| Uses | Number of content references |

File Name links use the inventory's usage detail view route (not raw `file_path`) to handle public, private, external, and filesystem-only paths safely.

Displays top 10 assets by usage count. Shows "No usage data available" if empty.

### Section 3: Archive Status (conditional: `enable_archive = TRUE`)

**Three charts in grid:**

| Chart | Type | Data |
| ----- | ---- | ---- |
| Archive Status | Pie | `getArchiveStatusBreakdown()` — Queued, Archived (Public), etc. |
| Archive Type | Horizontal Bar | `getArchiveTypeBreakdown()` — Legacy vs General |
| Archive Purpose | Doughnut | `getArchiveReasonBreakdown()` — Reference, Research, Recordkeeping, Other |

---

## Files to Create

### 1. `js/vendor/chart.min.js`

Vendored Chart.js v4.4.x UMD production build (~200KB approx; varies by minified build version). No npm, no CDN, no Composer. File location matches `digital_asset_inventory.libraries.yml` reference.

### 2. `src/Service/DashboardDataService.php`

**Injected:** `@database`, `@config.factory`

| Method | Returns | Notes |
| ------ | ------- | ----- |
| `getTotalAssetCount()` | `int` | `COUNT(*) FROM {digital_asset_item} WHERE is_temp = 0` |
| `getTotalArchivedCount()` | `int` | `COUNT(*) FROM {digital_asset_archive} WHERE status IN ('archived_public', 'archived_admin')` |
| `getCategoryBreakdown()` | `array` | `GROUP BY category ORDER BY MIN(sort_order)` |
| `getUsageBreakdown()` | `array` | LEFT JOINs to `{digital_asset_usage}` + `{dai_orphan_reference}` subqueries (raw SQL) |
| `getTopAssetsByUsage(int $limit = 10)` | `array` | INNER JOIN `{digital_asset_usage}`, GROUP BY, ORDER BY count DESC |
| `getLocationBreakdown()` | `array` | `GROUP BY source_type` with label mapping (Upload/Media/Server/External) |
| `getArchiveStatusBreakdown()` | `array` | `GROUP BY status` from `{digital_asset_archive}` with label mapping |
| `getArchiveTypeBreakdown()` | `array` | `SUM(CASE flag_late_archive)` on active archives (status IN archived_public, archived_admin) |
| `getArchiveReasonBreakdown()` | `array` | `GROUP BY archive_reason` on active archives with label mapping |

**Usage Breakdown SQL** (raw SQL used intentionally — rewriting via Drupal's query builder degrades readability and performance for subquery JOINs; do not refactor to query builder):

```sql
SELECT
  COALESCE(SUM(CASE WHEN u.usage_count > 0 THEN 1 ELSE 0 END), 0) AS in_use,
  COALESCE(SUM(CASE WHEN COALESCE(u.usage_count, 0) = 0
    AND COALESCE(o.orphan_count, 0) > 0 THEN 1 ELSE 0 END), 0) AS orphan_only,
  COALESCE(SUM(CASE WHEN COALESCE(u.usage_count, 0) = 0
    AND COALESCE(o.orphan_count, 0) = 0 THEN 1 ELSE 0 END), 0) AS unused
FROM {digital_asset_item} dai
LEFT JOIN (
  SELECT asset_id, COUNT(*) AS usage_count
  FROM {digital_asset_usage} GROUP BY asset_id
) u ON dai.id = u.asset_id
LEFT JOIN (
  SELECT asset_id, COUNT(*) AS orphan_count
  FROM {dai_orphan_reference} GROUP BY asset_id
) o ON dai.id = o.asset_id
WHERE dai.is_temp = 0
```

**Performance:** Queries rely on columns commonly used for grouping and filtering (`asset_id`, `category`, `status`, `source_type`, `is_temp`). Ensure appropriate indexes exist in the entity schema if performance degrades at scale. Should remain fast at 500+ assets with standard Drupal entity table indexing.

### 3. `src/Controller/DashboardController.php`

- Extends `ControllerBase`, DI via `create()` + `ContainerInterface`
- Injects: `DashboardDataService`, `ConfigFactoryInterface`
- Single `page()` method returning `#theme => 'dai_dashboard'` render array
- **No duplicate computation:** `DashboardDataService` is the single source of truth for all dashboard metrics. The controller computes all metrics once via the service; Twig receives precomputed values for tables/stat cards; the same dataset is passed to `drupalSettings` for Chart.js — no redundant aggregation
- Conditional: archive data gathered and passed only when `enable_archive = TRUE`
- Attaches `digital_asset_inventory/dashboard_charts` library
- Cache tags and max-age (see Cache Strategy section)

### 4. `templates/dai-dashboard.html.twig`

- Wrapped in `.dai--dashboard` container
- Each chart section is a `.dai-chart-section` with `data-dai-chart="{name}"` attribute, containing:
  - `<canvas class="dai-chart-canvas">` (hidden by default via CSS) with `role="img"`, `aria-label`, and `aria-describedby` pointing to a visually-hidden summary
  - Fallback `<table class="dai-chart-fallback">` (visible by default) — hidden when parent has `.dai-chart-active`
  - Visually-hidden `<span>` summary for `aria-describedby` (e.g., "Documents: 150 assets. Videos: 42 assets.")
- Section headings (`<h3>`) with `id` attributes for ARIA references
- Sections 1-3 always rendered; Section 4 conditional on `archive_enabled`
- Chart containers have consistent `min-height: 300px` to prevent layout jumps

### 5. `js/dashboard.js`

- `Drupal.behaviors.daiDashboard` with `once()` guard
- Reads chart data from `drupalSettings.digitalAssetInventory.dashboard`
- Per-chart init wrapped in try/catch — on success, adds `.dai-chart-active` to that section's `.dai-chart-section` wrapper (hides that chart's fallback table only); on failure, shows `.messages--warning` "Chart unavailable" inline in that section
- Init functions: `initPieChart()` (pie/doughnut), `initHorizontalBarChart()` (horizontal bar)
- `activateSection()` adds `.dai-chart-active` class and injects a toggle button (Show data table / Show chart) with `aria-pressed` state
- Empty data guard: shows "No data available" text instead of rendering empty charts
- `prefers-reduced-motion` respect: disables Chart.js animations when user prefers reduced motion
- Charts are responsive (`responsive: true`); pie/doughnut use `maintainAspectRatio: true` (circular), bar charts use `false`
- Canvas elements have `max-height: 300px` to prevent oversized charts

### 6. `css/dai-dashboard.css`

- Scoped under `.dai--dashboard`
- Uses existing CSS variables (`--dai-surface-bg`, `--dai-accent-info`, `--dai-surface-border`, etc.) from `dai-base.css`
- Library dependency chain: `dashboard_charts` → `digital_asset_inventory/admin` → `digital_asset_inventory/base` (which provides `dai-base.css` variables)
- Grid layout: 2-column chart rows; 3-column for archive section; responsive breakpoints at 1024px (tablet), 768px (mobile), 480px (phone)
- Summary bar: `auto-fit` grid adapts to 3 cards (archive disabled) or 4 cards (archive enabled); 2-column at 768px, 1-column at 480px
- Chart containers: consistent `min-height: 300px` (250px on tablet); canvas `max-height: 300px`
- Summary stat cards with large numbers and subtle `--dai-surface-bg` background
- Per-chart toggling: `.dai-chart-canvas` hidden by default; `.dai-chart-active .dai-chart-canvas` shown; `.dai-chart-active .dai-chart-fallback` hidden
- Toggle button (`.dai-chart-toggle`): hidden by default, shown when `.dai-chart-active`; toggles `.dai-chart-table-view` class to swap canvas/table visibility
- No global toggle — each `.dai-chart-section` toggles independently
- Fallback tables styled with existing admin table patterns
- Responsive stacked tables at 768px: thead hidden, cells use `display: flex` with `data-label` attributes via `::before` pseudo-elements
- Top assets table uses same stacked pattern on mobile with word-break for file names

---

## Files to Modify

### 7. `digital_asset_inventory.services.yml`

```yaml
  digital_asset_inventory.dashboard_data:
    class: Drupal\digital_asset_inventory\Service\DashboardDataService
    arguments: ['@database', '@config.factory']
```

### 8. `digital_asset_inventory.routing.yml`

```yaml
digital_asset_inventory.dashboard:
  path: '/admin/digital-asset-inventory/dashboard'
  defaults:
    _controller: '\Drupal\digital_asset_inventory\Controller\DashboardController::page'
    _title: 'Digital Asset Dashboard'
  requirements:
    _permission: 'view digital asset inventory'
  options:
    _admin_route: TRUE
```

### 9. `digital_asset_inventory.links.task.yml`

Add local task tabs on the inventory page (`/admin/digital-asset-inventory`) to switch between views:

```yaml
digital_asset_inventory.inventory_tab:
  title: 'Digital Asset Inventory'
  route_name: view.digital_assets.page_inventory
  base_route: view.digital_assets.page_inventory
  weight: 0

digital_asset_inventory.dashboard_tab:
  title: 'Digital Asset Dashboard'
  route_name: digital_asset_inventory.dashboard
  base_route: view.digital_assets.page_inventory
  weight: 1

digital_asset_inventory.archive_management_tab:
  title: 'Archive Management'
  route_name: view.digital_asset_archive.page_archive_management
  base_route: view.digital_assets.page_inventory
  weight: 2
```

All tabs use `view.digital_assets.page_inventory` as the `base_route` so they appear together on all three pages. Tab titles match the page titles. The Archive Management tab is conditionally hidden via `hook_menu_local_tasks_alter()` when `enable_archive` is `FALSE`.

### 11. `digital_asset_inventory.libraries.yml`

```yaml
dashboard_charts:
  js:
    js/vendor/chart.min.js: { minified: true, preprocess: false }
    js/dashboard.js: {}
  css:
    theme:
      css/dai-dashboard.css: {}
  dependencies:
    - digital_asset_inventory/admin
    - core/drupal
    - core/once
    - core/drupalSettings
```

### 12. `digital_asset_inventory.module`

Add `dai_dashboard` to `hook_theme()`:

```php
'dai_dashboard' => [
  'variables' => [
    'total_assets' => 0,
    'total_archived' => 0,
    'category_breakdown' => [],
    'usage_breakdown' => [],
    'top_assets' => [],
    'location_breakdown' => [],
    'archive_enabled' => FALSE,
    'archive_status' => [],
    'archive_type' => [],
    'archive_reason' => [],
  ],
  'template' => 'dai-dashboard',
],
```

### 13. `config/install/views.view.digital_assets.yml`

Inventory page header retains action buttons only (navigation handled by tabs):

```html
<a href="/admin/digital-asset-inventory/scan" class="button button--primary" role="button">Scan Site for Digital Assets</a>
<a href="/admin/digital-asset-inventory/csv" class="button" role="button">Export Asset Inventory (CSV)</a>
```

Navigation buttons (Archive Management, View Archive Registry, View Dashboard) removed — replaced by local task tabs.

### 14. `digital_asset_inventory.links.menu.yml`

Add "Digital Asset Dashboard" as the second menu item (weight: 1) under the main inventory parent:

```yaml
digital_asset_inventory.dashboard:
  title: 'Digital Asset Dashboard'
  parent: digital_asset_inventory.view
  description: 'Visual overview of inventory health, usage, and archive status'
  route_name: digital_asset_inventory.dashboard
  weight: 1
```

**Full menu order under Digital Asset Inventory:**

| Weight | Title |
| ------ | ----- |
| 0 | View Digital Asset Inventory |
| 1 | Digital Asset Dashboard |
| 2 | Scan Site for Digital Assets |
| 3 | Archive Management |
| 4 | View Archive Registry |

### 15. `digital_asset_inventory.install`

**Update hook `10064`** — Dashboard, tabs, and page header restructuring. Syncs both views configs.

**Update hook `10065`** — Accessibility and heading hierarchy improvements. Syncs both views configs.

**Update hook `10066`** — Syncs usage, orphan references, and public archive view configurations (views not covered by hooks 10064/10065).

All hooks overwrite the view configs to match the module's canonical definition. Site-local customizations will be replaced. This is the established pattern — Views are treated as module-owned config.

**Rule:** Any change to files in `config/install/` must have a corresponding update hook. See CLAUDE.md "Configuration Management" section.

---

## `drupalSettings` Contract

The controller passes chart data to JS via `drupalSettings.digitalAssetInventory.dashboard`:

```javascript
drupalSettings.digitalAssetInventory.dashboard = {
  category: {
    labels: ['Documents', 'Videos', 'Images', ...],
    values: [150, 42, 310, ...]
  },
  usage: {
    labels: ['In Use', 'Unused'],
    values: [280, 207]
  },
  location: {
    labels: ['Upload', 'Media', 'Server', 'External'],
    values: [200, 150, 30, 122]
  },
  // Only present when enable_archive = TRUE:
  archiveStatus: {
    labels: ['Queued', 'Archived (Public)', ...],
    values: [5, 42, 3, 8, 2]
  },
  archiveType: {
    labels: ['Legacy', 'General'],
    values: [35, 10]
  },
  archiveReason: {
    labels: ['Reference', 'Research', 'Recordkeeping', 'Other'],
    values: [25, 10, 8, 2]
  }
};
```

JS checks for each key's existence before initializing the corresponding chart.

---

## Chart Color Palette

**Refined Gray-Blue Sequential Palette (Colorblind-Safe)**

A monochromatic sequential palette using blue-gray tones that is inherently safe for all forms of color vision deficiency. Targets 3:1+ non-text contrast against white (`#FFFFFF`) surface. Charts include data labels and table alternatives — color is not the sole information channel.

| Index | Color | Hex | Hover Hex |
| ----- | ----- | --- | --------- |
| 0 | Very dark slate blue | `#1E3A5F` | `#162D4A` |
| 1 | Dark muted blue | `#34527D` | `#284367` |
| 2 | Mid gray-blue | `#4C739B` | `#3E6085` |
| 3 | Soft grayish blue | `#6C8EB6` | `#587A9E` |
| 4 | Light blue-gray | `#8FA8CB` | `#7892B4` |
| 5 | Very light gray-blue | `#C3D4E3` | `#A6BFCF` |

**Stroke color:** `#1E3A5F` (shared chart text and border color)

---

## Cache Strategy

```php
'#cache' => [
  'tags' => [
    'digital_asset_item_list',
    'digital_asset_archive_list',
    'config:digital_asset_inventory.settings',
  ],
  'max-age' => 3600,
],
```

These are the canonical list tags invalidated by the module:

- `digital_asset_item_list` — invalidated by `promoteTemporaryItems()` after scan completion
- `digital_asset_archive_list` — invalidated by `ArchiveService` on any archive status change
- `config:digital_asset_inventory.settings` — invalidated by Drupal's config system when settings are saved

---

## Empty States

| Section | Empty Condition | Display |
| ------- | --------------- | ------- |
| Summary Bar | `total_assets = 0` | All cards show "0" |
| Category Chart | No categories | "No asset data available. Run a scan to populate the dashboard." |
| Usage Chart | All zeros | "No usage data available." |
| Top Assets | Empty array | "No usage data available." |
| Location Chart | No location data | "No location data available." |
| Archive charts | No archive data | "No archive records found." |

---

## Accessibility

- **Progressive enhancement**: fallback tables always rendered in DOM; available to screen readers regardless of JS state
- All `<canvas>` elements have `role="img"`, descriptive `aria-label`, and `aria-describedby` pointing to a visually-hidden summary element (e.g., "Documents: 150 assets. Videos: 42 assets. Images: 310 assets.") for quick screen reader comprehension — the full fallback table provides complete detail
- **Chart/table toggle**: Each chart section has a toggle button ("Show data table" / "Show chart") with `aria-pressed` state, inserted after the heading when chart initializes successfully
- Section headings (`<h3>`) with `id` attributes for ARIA region references
- Colorblind-safe gray-blue sequential palette (3:1+ non-text contrast); charts include data labels — color is never the sole indicator
- `prefers-reduced-motion: reduce` disables Chart.js animations
- Keyboard focus indicators on interactive elements (toggle button has visible focus outline using `--dai-accent-info` color)
- Stat card numbers use `aria-label` for screen reader context (e.g., "142 total assets")
- If Chart.js fails, tables remain visible and an inline "Chart unavailable" message appears
- **Responsive tables**: At 768px and below, all tables switch to CSS-only stacked layout using `data-label` attributes on `<td>` elements and `::before` pseudo-elements for row labels

---

## Archive Management Page Structure

The archive management page (`/admin/digital-asset-inventory/archive`) has the following visual hierarchy, built from static config and dynamic `hook_views_pre_render` injections:

### Heading Hierarchy

| Level | Element | Source |
| ----- | ------- | ------ |
| H2 | Archiving Policy | Static config (`dai-archive-policy` wrapper) |
| H2 | Archived Assets | Dynamic (injected above filters when records exist) |
| H3 | Archive Documents and Videos | Static config (workflow card) |
| H3 | Archive Web Pages and External Resources | Dynamic (when `enable_manual_archive` enabled) |

### Page Layout (top to bottom)

1. **Archiving Policy** (H2) — Policy text in `.dai-archive-policy` wrapper
2. **Action buttons** — Export Archive Audit (CSV) + View Public Archive Registry, secondary styling, `role="button"`, flex-wrap for mobile
3. **Section divider** — `1px solid` border-top via `.archive-options` or `.view-header > .archive-option`
4. **Workflow cards** — Side-by-side grid (`.archive-options`): Documents/Videos card + Manual Entry card (conditional)
5. **Archived Assets** (H2) — Section heading with `2rem` top margin
6. **Exposed filters** — Archive type, status, asset type, purpose
7. **Table** — Archive records with operations

### Dynamic Content (hook_views_pre_render)

| Content | Condition | Injection Method |
| ------- | --------- | ---------------- |
| Export + Registry buttons | Always | `str_replace` after policy `</div>` |
| Manual entry card | `enable_manual_archive = TRUE` | `preg_replace` wrapping `<section>` |
| "Archived Assets" heading | `$total_archive_count > 0` | Appended to header content |
| Export button hidden | `$total_archive_count = 0` | `preg_replace` removal |

### Breadcrumbs

Page names are appended to breadcrumbs on all module pages via `hook_system_breadcrumb_alter()`:

| Route | Breadcrumb |
| ----- | ---------- |
| `/admin/digital-asset-inventory` | Home › Administration › **Digital Asset Inventory** |
| `/admin/digital-asset-inventory/dashboard` | Home › Administration › Digital Asset Inventory › **Digital Asset Dashboard** |
| `/admin/digital-asset-inventory/archive` | Home › Administration › Digital Asset Inventory › **Archive Management** |
| `/admin/digital-asset-inventory/scan` | Home › Administration › Digital Asset Inventory › **Scan Site for Digital Assets** |
| `/admin/digital-asset-inventory/usage/{id}` | Home › Administration › Digital Asset Inventory › **{file_name}** › Active Usage |

### Accessibility

- All `<a class="button">` links have `role="button"`
- `<section>` elements have `aria-labelledby` pointing to their heading `id`
- "Archived Assets" heading hidden when no records exist (no orphan heading)
- Buttons use `display: flex; flex-wrap: wrap; gap: 0.5rem` for mobile spacing

---

## Verification Checklist

1. **Charts render**: Visit `/admin/digital-asset-inventory/dashboard` — all always-on sections visible with charts
2. **Progressive fallback (JS disabled)**: Disable JS — verify tables are visible, canvases are hidden
3. **Progressive fallback (Chart.js fails)**: Block `chart.min.js` — verify tables remain visible, "Chart unavailable" shown
4. **Conditional sections**: Toggle `enable_archive` off — Archive Status section hidden
5. **Cache invalidation**: Run scan — dashboard updates; archive asset — Archive Status section updates
6. **Empty state**: Fresh install with no scan — shows empty state messages
7. **Permissions**: User with `view digital asset inventory` can access; anonymous cannot
8. **Accessibility**: Keyboard navigation, screen reader announces chart descriptions via tables and aria-labels; all button links have `role="button"`; heading hierarchy H2 → H3
9. **Chart/table toggle**: Each chart section has toggle button; clicking switches between chart and table; `aria-pressed` updates correctly
10. **Responsive (tablet 1024px)**: Archive 3-column grid collapses to 2 columns; chart min-height reduces to 250px
11. **Responsive (mobile 768px)**: All grids collapse to single column; summary bar becomes 2-column; tables switch to stacked layout with data-label headers
12. **Responsive (phone 480px)**: Summary cards become 1-column
13. **Stacked tables**: At 768px, thead hidden; each cell shows label via `::before` with `data-label` attribute; top assets table word-breaks file names
14. **Performance**: Test with 500+ assets — SQL queries return quickly (no entity objects are loaded or instantiated for dashboard metrics)
15. **Archive Purpose**: Archive Status section shows Reference/Research/Recordkeeping/Other breakdown
16. **Archived stat card**: Shows active archive count when archiving enabled; hidden when disabled
17. **Menu link**: "Digital Asset Dashboard" appears as second item (after View Digital Asset Inventory) under admin menu
18. **Tabs**: Three tabs on inventory page: Digital Asset Inventory, Digital Asset Dashboard, Archive Management; Archive Management tab hidden when archive disabled
19. **Archive page structure**: Policy → Buttons → Cards → "Archived Assets" heading → Filters → Table
20. **Archive page empty**: No "Archived Assets" heading, no filters, no export button when zero records
21. **Breadcrumbs**: Page name appended on all tabbed pages (Inventory, Dashboard, Archive Management, Scan)
22. **drupalSettings contract**: Verify JS receives expected data shape per contract section (no `storage` key)
23. **Color palette**: Charts use Refined Gray-Blue Sequential palette (#1E3A5F through #C3D4E3); colorblind-safe
24. **Chart types**: Assets by Category is horizontal bar; Usage Status is doughnut; Location is pie; Archive Status is pie; Archive Type is horizontal bar; Archive Purpose is doughnut
