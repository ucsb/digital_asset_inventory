# Spec: Theme-Agnostic Public UI

## Document Info

- **Version:** 1.4
- **Status:** Implemented
- **Date:** January 2026
- **Last Updated:** January 2026

---

## 1. Overview

### 1.1 Problem Statement

The Digital Asset Inventory module's public CSS (`dai-public.css`) had:
- Hardcoded colors that didn't integrate with the CSS variable system in `dai-base.css`
- Generic class names (e.g., `archive-notice`, `archive-type-badge`) that could collide with Bootstrap or other frameworks
- Inconsistent scoping patterns (some classes under `.dai--public`, others standalone)
- Duplicate `background`/`background-color` declarations

### 1.2 Objective

Make the module's public UI theme-agnostic while preserving:
- Information architecture and visual hierarchy
- Accessibility (WCAG AA compliance for public pages)
- Archive type distinction (Legacy vs General)
- Consistent user experience across different site themes

### 1.3 Scope

**In Scope:**

- `css/dai-public.css` - Primary refactoring target
- `css/dai-base.css` - CSS variable additions for public styles
- Template files using CSS classes
- Views configuration with CSS classes

**Out of Scope:**

- Dark mode support - Let site themes handle this
- Functional changes - UI behavior remains the same

---

## 2. Design Principles

| Principle | Description |
|-----------|-------------|
| **Variable-driven** | All colors use CSS variables for theme overridability |
| **Namespaced** | All classes prefixed with `dai-` to avoid collisions |
| **Scoped** | Use `.dai--public` wrapper or `dai-` prefixed component classes |
| **Theme inheritance** | Let themes control base colors; module provides structure |
| **Accessibility-first** | WCAG AA contrast ratios, visible focus states |

---

## 3. CSS Architecture

### 3.1 File Structure

```text
css/
├── dai-base.css    # Shared utilities + CSS variables
├── dai-admin.css   # Admin-only styles (scoped with .dai--admin)
└── dai-public.css  # Public-facing styles (scoped with .dai--public)
```

### 3.2 CSS Variables

**Location:** `dai-base.css`

Variables added for public styles:

```css
:root {
  /* Text colors - chosen for strong contrast */
  --dai-text-primary: #212529;
  --dai-text-muted: #545454;
  --dai-text-on-warning: #000;

  /* Link colors */
  --dai-link: #0056b3;
  --dai-link-hover: #003d82;

  /* Alert colors (login required, errors) */
  --dai-alert-danger-bg: #f8d7da;
  --dai-alert-danger-border: #f5c6cb;
  --dai-alert-danger-text: #842029;

  /* Confirmation/success text */
  --dai-text-success: #198754;
}
```

**Theme Override Example:**

```css
/* In theme's CSS */
:root {
  --dai-surface-bg: var(--theme-bg-color);
  --dai-link: var(--theme-link-color);
  --dai-text-primary: var(--theme-text-color);
}

/* Context-specific override */
.dai--public {
  --dai-surface-bg: #fefefe;
}
```

---

## 4. Scoping Strategy

### 4.1 Wrapper Classes

| Wrapper | Usage |
|---------|-------|
| `.dai--public` | Archive Registry view page |
| `.dai--archive-detail` | Archive detail page |

### 4.2 Component Classes

Standalone components that may appear in multiple contexts use `dai-` prefix without wrapper dependency:

- `.dai-archived-content-banner` - Banner on archived content pages
- `.dai-archive-type-badge` - Archive type badges (can appear in views or templates)

---

## 5. Component Specifications

### 5.1 Archive Registry Intro Box

**Purpose:** Explains the archive registry to visitors.

```css
.dai--public .dai-archive-registry-intro {
  margin-bottom: 2rem;
  padding: 1.5em;
  background-color: var(--dai-surface-bg);
  border: 1px solid var(--dai-surface-border);
  border-radius: var(--dai-surface-radius);
  color: var(--dai-text-primary);
}
```

---

### 5.2 Archive Type Badges

**Purpose:** Distinguish Legacy Archives from General Archives.

| Badge | When Applied | Visual |
|-------|--------------|--------|
| Legacy Archive | `flag_late_archive = FALSE` | Light blue background, blue text |
| General Archive | `flag_late_archive = TRUE` | Gray background, muted text |

```css
.dai-archive-type-badge {
  display: inline-block;
  padding: 0.25em 0.75em;
  border-radius: 3px;
  font-size: 0.85em;
  font-weight: 500;
  white-space: nowrap;
}

/* Legacy Archive - archived before ADA deadline */
.dai-archive-type-badge--legacy {
  background-color: #E8F2FD;
  color: #084C9E;
}

/* General Archive - archived after ADA deadline */
.dai-archive-type-badge--general {
  background-color: var(--dai-surface-bg);
  color: var(--dai-text-muted);
}
```

---

### 5.3 Archive Notice Box

**Purpose:** Displays compliance notice on archive detail pages.

```css
.dai-archive-notice {
  margin-top: 0;
  margin-bottom: 1.5em;
  padding: 1.5em;
  background-color: var(--dai-surface-bg);
  border: 1px solid var(--dai-surface-border);
  border-radius: var(--dai-surface-radius);
  color: var(--dai-text-primary);
}
```

---

### 5.4 Login Required Notice

**Purpose:** Notifies anonymous users that authentication is required for private files.

**Design rationale:** Authentication required is an expected access state, not an error. Uses notice styling (yellow/amber) with accent rail instead of danger/error styling.

```css
.dai-archive-login-required {
  margin-bottom: 1em;
  padding: 1em;
  background-color: var(--dai-notes-bg);
  border: 1px solid var(--dai-notes-border);
  border-left: 4px solid var(--dai-accent-info);
  border-radius: var(--dai-surface-radius);
  color: var(--dai-text-primary);
}
```

**Login link:** Styled as a prominent link (not a button) for better semantics.

```css
.dai-archive-login-link {
  font-weight: 600;
  color: var(--dai-link);
  text-decoration: underline;
}
```

---

### 5.5 Archived Content Banner

**Purpose:** Banner displayed on archived content pages (via hook_entity_view).

**Design rationale:** Left accent rail makes it recognizable as a system notice, consistent with other notice patterns.

**Width behavior:** The banner has no `max-width` constraint and inherits width from the theme's content container. This is intentional - the module doesn't know what width the theme uses for content areas. Themes can add width constraints as needed.

```css
.dai-archived-content-banner {
  background-color: var(--dai-surface-bg);
  border: 1px solid var(--dai-surface-border);
  border-left: 4px solid var(--dai-accent-info);
  border-radius: var(--dai-surface-radius);
  padding: 1rem;
  margin-bottom: 1.5rem;
  color: var(--dai-text-primary);
}
```

**Theme width override example:**

```css
/* Constrain banner width to match theme's content area */
.dai-archived-content-banner {
  max-width: 800px;  /* Adjust to match theme */
  margin-left: auto;
  margin-right: auto;
}
```

---

### 5.6 Copy Link Button

**Purpose:** Button to copy archive record URL.

```css
.dai-archive-copy-link__button {
  display: inline-block;
  padding: 0.625rem 1.25rem;
  background-color: var(--dai-accent-info);
  color: #fff;
  border: 2px solid var(--dai-accent-info);
  border-radius: var(--dai-surface-radius);
  font-weight: 600;
  cursor: pointer;
}

.dai-archive-copy-link__button:hover,
.dai-archive-copy-link__button:focus {
  background-color: var(--dai-accent-info-hover);
  border-color: var(--dai-accent-info-hover);
  outline: 2px solid var(--dai-accent-info-hover);
  outline-offset: 2px;
}

.dai-archive-copy-link__confirmation {
  margin-left: 0.5rem;
  color: var(--dai-text-success);
  font-weight: 600;
}
```

---

### 5.7 Archive Detail Table

**Purpose:** Displays archive metadata on detail page.

**Responsive behavior:** Table stacks into card layout at ≤1023px (mobile and 400% zoom). Uses `data-label` attributes on `<td>` elements to show column headers per cell. Supports WCAG 1.4.10 reflow.

```css
.dai-archive-detail-table {
  width: 100%;
  border-collapse: collapse;
  margin: 1rem 0;
}

.dai-archive-detail-table th,
.dai-archive-detail-table td {
  padding: 0.75rem;
  text-align: left;
  border: 1px solid var(--dai-surface-border);
}

.dai-archive-detail-table th {
  background-color: var(--dai-surface-bg);
  color: var(--dai-text-primary);
  font-weight: 700;
}

.dai-archive-detail-table td {
  background-color: var(--dai-surface-bg-alt);
  color: var(--dai-text-primary);
}

/* Responsive stacked mode (≤1023px) */
@media (max-width: 1023px) {
  .dai-archive-detail-table thead { display: none; }
  .dai-archive-detail-table tbody tr { display: block; }
  .dai-archive-detail-table td { display: block; }
  .dai-archive-detail-table td::before {
    content: attr(data-label);
    display: block;
    font-weight: 700;
  }
}
```

---

## 6. Link Styling

### 6.1 General Links

```css
.dai--public a:link,
.dai--public a:visited {
  color: var(--dai-link);
  text-decoration: underline;
  border-bottom: none;
  box-shadow: none;
}

.dai--public a:hover,
.dai--public a:focus {
  color: var(--dai-link-hover);
  text-decoration: underline;
  text-decoration-thickness: 2px;
}
```

### 6.2 Table Header Links

Sortable column headers use primary text color without underline:

```css
.dai--public th a:link,
.dai--public th a:visited {
  color: var(--dai-text-primary);
  text-decoration: none;
}

.dai--public th a:hover,
.dai--public th a:focus {
  text-decoration: underline;
}
```

---

## 7. Responsive Behavior

### 7.1 Tablesaw Stacked Mode (Mobile ≤640px)

```css
@media (max-width: 640px) {
  .dai--public .tablesaw-stack td,
  .dai--public .tablesaw-stack th {
    display: block;
    width: 100%;
  }

  .dai--public .tablesaw-stack tbody tr {
    display: block;
    border-bottom: 2px solid var(--dai-surface-border);
    margin-bottom: 1rem;
  }

  .dai--public .tablesaw-stack td .tablesaw-cell-label {
    font-weight: 700;
    display: block;
    margin-bottom: 0.25rem;
  }
}
```

### 7.2 Tablet Horizontal Scroll (≤768px)

```css
@media (max-width: 768px) {
  .dai--public .view-content {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
}
```

---

## 8. Class Name Reference

### 8.1 Archive Registry Page

| Component | CSS Class |
|-----------|-----------|
| Page wrapper | `.dai--public` |
| Intro box | `.dai-archive-registry-intro` |
| Empty state | `.dai-archive-empty` |
| Type badge (Legacy) | `.dai-archive-type-badge--legacy` |
| Type badge (General) | `.dai-archive-type-badge--general` |

### 8.2 Archive Detail Page

| Component | CSS Class |
|-----------|-----------|
| Page wrapper | `.dai--archive-detail` |
| Login required alert | `.dai-archive-login-required` |
| Login link | `.dai-archive-login-link` |
| Archive notice box | `.dai-archive-notice` |
| Copy link container | `.dai-archive-copy-link` |
| Copy link button | `.dai-archive-copy-link__button` |
| Copy confirmation | `.dai-archive-copy-link__confirmation` |
| Details table | `.dai-archive-detail-table` |
| Integrity note | `.dai-archive-integrity-note` |
| Back link | `.dai-archive-back-link` |

### 8.3 Standalone Components

| Component | CSS Class |
|-----------|-----------|
| Archived content banner | `.dai-archived-content-banner` |

---

## 9. Files Modified

| File | Changes |
|------|---------|
| `css/dai-base.css` | Added CSS variables for text, links, alerts, success |
| `css/dai-public.css` | Refactored to use variables, renamed all classes with `dai-` prefix |
| `src/Plugin/views/field/ArchiveTypeField.php` | Updated badge class names |
| `templates/archive-detail.html.twig` | Updated all class names |
| `config/install/views.view.public_archive.yml` | Updated intro box class |
| `digital_asset_inventory.install` | Added update hook 10008 |

---

## 10. Theme Override Guide

### 10.1 Override CSS Variables

```css
/* In theme's CSS file */
:root {
  --dai-surface-bg: var(--theme-bg-secondary);
  --dai-link: var(--theme-link-color);
  --dai-text-primary: var(--theme-text-color);
}
```

### 10.2 Context-Specific Overrides

```css
/* Override only in public context */
.dai--public {
  --dai-surface-bg: #fefefe;
}

/* Override only in archive detail context */
.dai--archive-detail {
  --dai-surface-bg: #f5f5f5;
}
```

### 10.3 Banner Width Override

The archived content banner has no `max-width` and inherits from the theme's content container. If the banner appears too wide, add a width constraint:

```css
/* Constrain banner width to match theme's content area */
.dai-archived-content-banner {
  max-width: 800px;  /* Adjust to match theme */
  margin-left: auto;
  margin-right: auto;
}
```

### 10.4 Page Title Alignment

The archive detail page title is rendered by Drupal's page template, outside the module's `.dai--archive-detail` container. This is standard Drupal behavior - the module does not control page title placement.

If the page title appears misaligned with the content below, adjust the theme's page title container:

```css
/* Match page title width to module's content container */
.page-title,
h1.page-title {
  max-width: 1200px;  /* Match .dai--archive-detail */
  margin-left: auto;
  margin-right: auto;
}
```

### 10.5 Use libraries-extend

```yaml
# In theme's *.info.yml
libraries-extend:
  digital_asset_inventory/public:
    - mytheme/dai_public_overrides
```

---

## 11. Acceptance Criteria

### 11.1 Visual

- [x] All colors use CSS variables
- [x] All classes namespaced with `dai-` prefix
- [x] Consistent scoping patterns applied
- [x] No duplicate background declarations

### 11.2 Accessibility

- [x] Link colors have sufficient contrast (WCAG AA)
- [x] Focus states visible on all interactive elements
- [x] Tables responsive on mobile devices
- [x] Touch targets meet 44px minimum (WCAG 2.5.5 AAA)
- [x] Detail table supports WCAG 1.4.10 reflow at 400% zoom

### 11.3 Maintainability

- [x] Themes can override variables
- [x] No collision risk with Bootstrap or other frameworks
- [x] Update hook for existing sites (10008)

---

## 12. Changelog

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | Jan 2026 | Initial spec - refactored dai-public.css with CSS variables and namespaced classes |
| 1.1 | Jan 2026 | Login required: changed from danger to notice styling; archived banner gets accent rail |
| 1.2 | Jan 2026 | Login action: changed from button to link for better semantics and accessibility |
| 1.3 | Jan 2026 | WCAG 2.5.5 AAA touch targets: copy button and back link now have 44px minimum height |
| 1.4 | Jan 2026 | Responsive detail table: stacks into card layout at ≤1023px for WCAG 1.4.10 reflow support |
