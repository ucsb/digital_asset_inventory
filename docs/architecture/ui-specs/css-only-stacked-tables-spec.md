# CSS-Only Stacked Tables Specification

## Overview

Responsive table stacking without JavaScript dependencies. This implementation replaces the Tablesaw/responsive_tables_filter dependency with a pure CSS solution using `data-label` attributes, while maintaining compatibility with sites that have `responsive_tables_filter` enabled globally.

## Architecture

### Template Pattern

Per-view Twig templates inject `data-label` attributes on table cells. These templates are located in `templates/views/`:

- `views-view-table--digital-assets.html.twig`
- `views-view-table--digital-asset-archive.html.twig`
- `views-view-table--digital-asset-usage.html.twig`
- `views-view-table--public-archive.html.twig`

**Key template logic:**

```twig
{# Build header labels lookup for data-label attributes #}
{% set header_labels = {} %}
{% if header %}
  {% for key, column in header %}
    {% set label_text = column.content|render|striptags|trim %}
    {% set header_labels = header_labels|merge({ (key): label_text }) %}
  {% endfor %}
{% endif %}

{# Apply data-label to each cell #}
{% set label = header_labels[key]|default('') %}
<td{{ column.attributes.addClass(column_classes).setAttribute('data-label', label) }}>
```

### Template Registration

Templates in the `templates/views/` subdirectory are registered via `hook_theme_registry_alter()`:

```php
function digital_asset_inventory_theme_registry_alter(&$theme_registry) {
  $module_path = \Drupal::service('extension.list.module')->getPath('digital_asset_inventory');

  $views_templates = [
    'views_view_table__digital_assets' => 'views-view-table--digital-assets',
    'views_view_table__digital_asset_archive' => 'views-view-table--digital-asset-archive',
    'views_view_table__digital_asset_usage' => 'views-view-table--digital-asset-usage',
    'views_view_table__public_archive' => 'views-view-table--public-archive',
  ];

  foreach ($views_templates as $hook => $template) {
    if (isset($theme_registry['views_view_table'])) {
      $theme_registry[$hook] = $theme_registry['views_view_table'];
      $theme_registry[$hook]['template'] = $template;
      $theme_registry[$hook]['path'] = $module_path . '/templates/views';
    }
  }
}
```

### CSS Pattern

CSS uses `::before` pseudo-element with `content: attr(data-label)` to display labels on mobile/tablet breakpoints.

**Target class:** `.dai-stack-table`

**Breakpoints:**

- Mobile: `max-width: 640px`
- Tablet/Zoom: `641px - 1023px`
- Desktop: `1024px+`

**Core CSS pattern:**

```css
@media (max-width: 1023px) {
  /* Hide table header */
  table.dai-stack-table thead {
    display: none;
  }

  /* Stack rows as cards */
  table.dai-stack-table tbody tr {
    display: block;
    border: 1px solid var(--dai-surface-border);
    border-radius: var(--dai-surface-radius);
    margin-bottom: 1rem;
  }

  /* Stack cells */
  table.dai-stack-table td {
    display: block;
    width: 100%;
    padding: 0.75rem;
    border-bottom: 1px solid var(--dai-surface-border);
  }

  /* Show labels from data-label attribute */
  table.dai-stack-table td::before {
    content: attr(data-label);
    display: block;
    font-weight: 700;
    margin-bottom: 0.25rem;
    color: var(--dai-text-primary);
  }

  /* Hide empty labels */
  table.dai-stack-table td[data-label=""]::before {
    display: none;
  }
}
```

### PHP Hooks

**Class addition** via `hook_preprocess_views_view_table()`:

```php
function digital_asset_inventory_preprocess_views_view_table(&$variables) {
  $view = $variables['view'];
  $responsive_views = ['digital_assets', 'digital_asset_archive', 'digital_asset_usage', 'public_archive'];

  if (in_array($view->id(), $responsive_views)) {
    $variables['attributes']['class'][] = 'dai-stack-table';
  }
}
```

## Tablesaw Coexistence

Sites may have `responsive_tables_filter` (Tablesaw) enabled globally for other views. The CSS includes fallback rules to prevent duplicate labels and ensure proper layout when both systems are active.

### How It Works

1. **Detection**: CSS uses `:has(.tablesaw-cell-label)` to detect if Tablesaw has wrapped cell content
2. **Label hiding**: When Tablesaw is present, the `::before` pseudo-element labels are hidden
3. **Fallback styling**: Tablesaw's `.tablesaw-cell-label` and `.tablesaw-cell-content` elements are styled for proper stacking

### Coexistence CSS Pattern

```css
/* Hide our ::before labels if Tablesaw is present */
table.dai-stack-table td:has(.tablesaw-cell-label)::before {
  display: none !important;
}

/* Style Tablesaw elements when present */
table.dai-stack-table .tablesaw-cell-label,
table.dai-stack-table .tablesaw-cell-content {
  float: none !important;
  width: 100% !important;
  display: block !important;
  position: static !important;
}

table.dai-stack-table .tablesaw-cell-label {
  font-weight: 700;
  margin-bottom: 0.25rem;
}
```

### Scenarios

| Scenario | Label Source | Result |
|----------|--------------|--------|
| Module only (no Tablesaw) | `data-label` + `::before` | CSS-only labels |
| Tablesaw enabled globally | `.tablesaw-cell-label` spans | Tablesaw labels, no duplicates |
| Mixed (some views with Tablesaw) | Varies per view | Each view uses appropriate method |

## Views Affected

| View ID | Admin Path | Description |
|---------|------------|-------------|
| `digital_assets` | `/admin/digital-asset-inventory` | Main inventory |
| `digital_asset_archive` | `/admin/digital-asset-inventory/archive` | Archive management |
| `digital_asset_usage` | `/admin/digital-asset-inventory/usage/{id}` | Usage details |
| `public_archive` | `/archive-registry` | Public Archive Registry |

## Dependency Status

- `responsive_tables_filter` is **NO LONGER required** by this module
- Existing installs retain the module if still installed (no auto-uninstall by Drupal)
- New installs work without it
- Sites can keep `responsive_tables_filter` for other views without conflict
- Sites can manually uninstall `responsive_tables_filter` if no other modules depend on it

## Accessibility

- Labels remain visible for screen readers via `data-label` attribute
- WCAG 1.4.10 reflow compliance at 400% zoom (1023px breakpoint)
- Keyboard accessibility maintained
- Proper heading and semantic structure

## Files Modified

### New Files

- `templates/views/views-view-table--digital-assets.html.twig`
- `templates/views/views-view-table--digital-asset-archive.html.twig`
- `templates/views/views-view-table--digital-asset-usage.html.twig`
- `templates/views/views-view-table--public-archive.html.twig`
- `docs/architecture/ui-specs/css-only-stacked-tables-spec.md`

### Modified Files

- `css/dai-admin.css` - CSS-only stacking with Tablesaw coexistence
- `css/dai-public.css` - CSS-only stacking with Tablesaw coexistence
- `digital_asset_inventory.module` - Added `hook_theme_registry_alter()`, updated preprocess hook
- `digital_asset_inventory.info.yml` - Removed responsive_tables_filter dependency
- `digital_asset_inventory.libraries.yml` - Removed tablesaw-filter dependency
- `composer.json` - Removed drupal/responsive_tables_filter requirement
- `README.md` - Updated dependencies documentation

## Testing Checklist

### Mobile (max-width: 640px)

- [ ] Headers are hidden
- [ ] Each cell shows its label via `::before`
- [ ] Operations display as stacked links
- [ ] Cards have proper borders and spacing

### Tablet/Zoom (641-1023px)

- [ ] Card layout displays correctly
- [ ] Labels show via data-label attribute
- [ ] WCAG 1.4.10 reflow at 400% zoom

### Desktop (1024px+)

- [ ] Normal table layout
- [ ] Headers visible
- [ ] Sorting works

### Each View

- [ ] `/admin/digital-asset-inventory` - Inventory stacks correctly
- [ ] `/admin/digital-asset-inventory/archive` - Archive management stacks
- [ ] `/admin/digital-asset-inventory/usage/1` - Usage view stacks
- [ ] `/archive-registry` - Public archive stacks

### Without responsive_tables_filter

- [ ] Module works without responsive_tables_filter installed
- [ ] Labels display via `::before` pseudo-element
- [ ] No JavaScript errors in console

### With responsive_tables_filter (Coexistence)

- [ ] Enable responsive_tables_filter module
- [ ] Configure to attach to all views
- [ ] Verify no duplicate labels appear
- [ ] Verify Tablesaw labels display correctly
- [ ] Verify proper cell stacking

### Cache

- [ ] Clear cache after deployment (`drush cr`)
- [ ] Verify templates are picked up (check for `data-label` attributes in HTML)

## Rollback Plan

If issues arise, revert by:

1. Remove `hook_theme_registry_alter()` from `.module`
2. Restore `responsive_tables_filter:responsive_tables_filter` dependency in `.info.yml`
3. Restore `responsive_tables_filter/tablesaw-filter` library in `.libraries.yml`
4. Restore Tablesaw library attachments in `hook_views_pre_render()`
5. Restore Tablesaw data attributes in `hook_preprocess_views_view_table()`
6. Remove the custom templates from `templates/views/`
7. Restore Tablesaw CSS selectors in `dai-admin.css` and `dai-public.css`
