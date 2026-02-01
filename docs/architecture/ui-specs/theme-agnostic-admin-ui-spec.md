# Spec: Theme-Agnostic Admin UI

## Document Info

- **Version:** 1.3
- **Status:** Implemented
- **Date:** January 2026
- **Last Updated:** January 2026

---

## 1. Overview

### 1.1 Problem Statement

The Digital Asset Inventory module's admin CSS contained hardcoded colors, heavy borders, and full-row warning backgrounds that clashed with different Drupal admin themes (Seven, Claro, Gin). The UI looked "extra boxy" in modern themes and fought with theme-provided table striping.

### 1.2 Objective

Make the module's admin UI theme-agnostic while preserving:

- Information architecture and groupings
- Accessibility (WCAG AAA compliance)
- Badge meanings and visual hierarchy

### 1.3 Scope

**In Scope:**

- `css/dai-admin.css` - Primary refactoring target
- `css/dai-base.css` - CSS variable definitions
- Responsive table layout for mobile and 400% zoom (WCAG 1.4.10)

**Out of Scope:**

- Dark mode support - Let admin themes handle this
- Functional changes - UI behavior remains the same

> **Note:** `css/dai-public.css` has been refactored to use CSS variables and namespaced classes (v1.3).

---

## 2. Design Principles

| Principle              | Description                                                  |
| ---------------------- | ------------------------------------------------------------ |
| **Surface-first**      | Use background + spacing instead of heavy borders            |
| **Theme inheritance**  | Let themes control base colors; module provides structure    |
| **Minimal overrides**  | Don't re-theme tables, headers, or links unless necessary    |
| **Badge-driven status**| Status badges communicate all states; no row indicators needed |
| **Accessibility-first**| Text/badges explain meaning; WCAG AAA compliance             |

---

## 3. CSS Architecture

### 3.1 File Structure

```text
css/
├── dai-base.css    # Shared utilities + CSS variables
├── dai-admin.css   # Admin-only styles (scoped)
└── dai-public.css  # Public-facing styles (unchanged)
```

### 3.2 CSS Variables

**Location:** `dai-base.css`

```css
:root {
  /* Surface colors (neutral, theme-safe) */
  --dai-surface-bg: #f8f9fa;
  --dai-surface-bg-alt: #fff;
  --dai-surface-border: rgba(0, 0, 0, 0.1);
  --dai-surface-radius: 4px;

  /* Container accent */
  --dai-accent-info: #005587;

  /* Text colors - WCAG AAA compliant (7:1 contrast on white) */
  --dai-text-muted: #545454;

  /* Badge semantic colors (5 max) - WCAG AAA compliant with badge text */
  --dai-badge-neutral-bg: #545454;  /* 7.5:1 with white text */
  --dai-badge-info-bg: #005587;     /* 7.1:1 with white text */
  --dai-badge-success-bg: #0d5534;  /* 7.1:1 with white text */
  --dai-badge-warning-bg: #ffc107;  /* Uses dark text */
  --dai-badge-danger-bg: #a71d1d;   /* 7.5:1 with white text */
}
```

**Theme Override Example:**

```css
/* In theme's CSS */
:root {
  --dai-surface-bg: var(--gin-bg-secondary);
  --dai-accent-info: var(--gin-color-primary);
}
```

---

## 4. Component Specifications

### 4.1 Surface Cards (Containers)

**Pattern:** Background + padding + left accent. No full borders.

**Components Using This Pattern:**

- `.digital-asset-scan-info`
- `.asset-info-header__wrapper`
- `.archive-option`
- `.manual-archive-intro-box`
- `.form-item-archive-acknowledgment` (warning variant)

**CSS:**

```css
.surface-card {
  background-color: var(--dai-surface-bg);
  padding: 1rem 1.25rem;
  border-radius: var(--dai-surface-radius);
  border-left: 3px solid var(--dai-accent-info);
}

.surface-card--warning {
  border-left: 3px solid var(--dai-badge-warning-bg);
}
```

**Before/After:**

| Property   | Before                       | After                     |
| ---------- | ---------------------------- | ------------------------- |
| Border     | 2px solid #003660 (all sides)| 3px left accent only      |
| Background | #f8f9fa (hardcoded)          | var(--dai-surface-bg)     |
| Nesting    | Often double-wrapped         | Single container          |

---

### 4.2 Table Styling

**Policy:** Let theme handle base styling. Module provides only structural overrides.

**Removed:**

- Row background overrides (theme handles striping)
- Header color overrides (theme handles header styling)
- Row indicator borders (badges communicate status)

**Kept (structural only):**

- Column width CSS for grouped tables (ensures uniform layout across categories)
- `table-layout: fixed` for consistent column alignment (desktop only)
- Responsive stacking for mobile and 400% zoom
- Text overflow and wrapping rules

---

### 4.3 Row Indicators

**Decision:** Row indicators have been removed. Status badges provide sufficient visual indication of critical issues without adding redundant visual noise.

**Rationale:**

- Status badges (red "Missing", "Integrity", "Usage", "Void") clearly communicate critical issues
- Left border indicators were redundant with badges
- Removing them simplifies the UI and reduces theme conflicts

---

### 4.4 Badge System

**Policy:** 5 semantic colors maximum.

#### 4.4.1 Color Mapping

| Badge Type                           | Semantic | Variable                |
| ------------------------------------ | -------- | ----------------------- |
| file_managed                         | Info     | `--dai-badge-info-bg`   |
| media_managed                        | Info     | `--dai-badge-info-bg`   |
| filesystem_only                      | Danger   | `--dai-badge-danger-bg` |
| external                             | Success  | `--dai-badge-success-bg`|
| queued                               | Warning  | `--dai-badge-warning-bg`|
| archived_public                      | Success  | `--dai-badge-success-bg`|
| archived_admin                       | Success  | `--dai-badge-success-bg`|
| archived_deleted                     | Neutral  | `--dai-badge-neutral-bg`|
| exemption_void                       | Danger   | `--dai-badge-danger-bg` |
| warning (missing/integrity/usage/void)| Danger   | `--dai-badge-danger-bg` |
| late_archive                         | Warning  | `--dai-badge-warning-bg`|
| modified                             | Warning  | `--dai-badge-warning-bg`|
| prior_void                           | Neutral  | `--dai-badge-neutral-bg`|

#### 4.4.2 CSS

```css
/* Info badges (blue) - managed files */
.dai-badge--file_managed,
.dai-badge--media_managed {
  background-color: var(--dai-badge-info-bg);
  color: #fff;
}

/* Success badges (green) - external resources */
.dai-badge--external {
  background-color: var(--dai-badge-success-bg);
  color: #fff;
}

/* Danger badges (red) - manual uploads requiring attention */
.dai-badge--filesystem_only {
  background-color: var(--dai-badge-danger-bg);
  color: #fff;
}

/* Warning badges (yellow) */
.dai-warning-badge--late,
.dai-warning-badge--modified {
  background-color: var(--dai-badge-warning-bg);
  color: #000;
}

/* Danger warning badges (red) */
.dai-warning-badge--usage,
.dai-warning-badge--missing,
.dai-warning-badge--integrity,
.dai-warning-badge--void {
  background-color: var(--dai-badge-danger-bg);
  color: #fff;
}
```

> **Note:** All badge classes are prefixed with `dai-` to avoid namespace collisions with other modules or themes (e.g., Bootstrap).

---

### 4.5 Admin Wrapper Class

**Requirement:** All admin views have `.dai--admin` wrapper.

**Purpose:** Scope admin styles to prevent unintended theme conflicts.

**Implementation:** Added via `hook_views_pre_render()`:

```php
$view->element['#attributes']['class'][] = 'dai';
$view->element['#attributes']['class'][] = 'dai--admin';
```

**Views with wrapper:**

- `digital_assets` (Inventory)
- `digital_asset_archive` (Archive Management)
- `digital_asset_usage` (Usage Details)

---

### 4.6 Responsive Table Layout (WCAG 1.4.10 Reflow)

**Requirement:** Tables must reflow to single column at 400% zoom without horizontal scrolling.

#### 4.6.1 Breakpoint Strategy

| Breakpoint       | Layout          | Purpose                                    |
| ---------------- | --------------- | ------------------------------------------ |
| ≤640px           | Tablesaw stack  | Mobile devices (Tablesaw JS handles)       |
| 641px - 1023px   | CSS stack       | Large screens at 400% zoom                 |
| ≥1024px          | Fixed columns   | Desktop layout                             |

#### 4.6.2 Mobile/Tablet Stacked Layout

- Table headers hidden; labels shown per cell via `::before` pseudo-elements
- Each row becomes a card with border separator
- Each cell displays as block with label above content
- File names wrap with `word-break: break-word`

#### 4.6.3 Operation Links on Mobile

On mobile/tablet viewports, dropbutton operation links are converted to simple stacked links:

- All dropbutton wrapper styling removed (`all: unset`)
- Dropdown toggle hidden
- Each action displayed as underlined link
- Link color: `var(--dai-accent-info)` (#005587)
- Hover: Darker color (#003d5c) with thicker underline
- Focus: Visible outline for keyboard navigation
- Touch target: 44px minimum height (WCAG AAA)

```css
/* Mobile operation links */
.dai--admin .tablesaw-stack .dropbutton li a {
  display: inline-block !important;
  min-height: 44px !important;
  line-height: 44px !important;
  text-decoration: underline !important;
  color: var(--dai-accent-info) !important;
}

.dai--admin .tablesaw-stack .dropbutton li a:hover {
  color: #003d5c !important;
  text-decoration-thickness: 2px !important;
}

.dai--admin .tablesaw-stack .dropbutton li a:focus {
  outline: 2px solid var(--dai-accent-info) !important;
  outline-offset: 2px !important;
}
```

---

## 5. Files Modified

| File                              | Changes                                                                            |
| --------------------------------- | ---------------------------------------------------------------------------------- |
| `css/dai-base.css`                | CSS variable definitions (removed row indicator variable)                          |
| `css/dai-admin.css`               | Refactored containers, responsive stacking, operation links, removed row indicators |
| `digital_asset_inventory.module`  | Added `.dai--admin` wrapper to admin views                                         |
| Module documentation              | Updated CSS scoping documentation                                                  |

---

## 6. Acceptance Criteria

### 6.1 Visual

- [x] Containers use surface-first pattern (no full borders)
- [x] Tables inherit theme styling (no hardcoded colors)
- [x] Status badges communicate all states (no row indicators)
- [x] Badges use 5 semantic colors maximum
- [x] Column alignment maintained for grouped inventory tables

### 6.2 Accessibility

- [x] WCAG AAA contrast ratios maintained for badges (7:1 minimum)
- [x] Text/badges explain meaning (not color-dependent)
- [x] Focus states visible for all interactive elements
- [x] Touch targets meet 44px minimum (WCAG 2.5.5 AAA)
- [x] Content reflows at 400% zoom without horizontal scrolling (WCAG 1.4.10)

### 6.3 Maintainability

- [x] All colors use CSS variables
- [x] Themes can override variables
- [x] Admin styles scoped under `.dai--admin`
- [x] Responsive tables work in both Claro and Seven themes

---

## 7. Theme Override Guide

### 7.1 Override CSS Variables

```css
/* In theme's CSS file */
:root {
  --dai-surface-bg: var(--gin-bg-secondary);
  --dai-surface-border: var(--gin-border-color);
  --dai-accent-info: var(--gin-color-primary);
  --dai-badge-info-bg: var(--gin-color-primary);
}
```

### 7.2 Use libraries-extend

```yaml
# In theme's *.info.yml
libraries-extend:
  digital_asset_inventory/admin:
    - mytheme/dai_admin_overrides
```

### 7.3 Target with Wrapper Class

```css
/* Higher specificity overrides */
.dai--admin .digital-asset-scan-info {
  background-color: var(--claro-bg-secondary);
  border-left-color: var(--claro-color-primary);
}
```

---

## 8. Browser Compatibility

| Feature              | Support                                      |
| -------------------- | -------------------------------------------- |
| CSS Variables        | All modern browsers                          |
| `all: unset`         | All modern browsers                          |
| `::before` content   | All modern browsers                          |

---

## 9. Button Styling

### 9.1 Button Types

Three semantic button types for different action contexts:

| Type | Purpose | Example Actions |
|------|---------|-----------------|
| Secondary | Quiet/cancel actions | Cancel, Back, Close |
| Intentional | Serious but non-destructive | Archive, Execute, Confirm |
| Danger | Destructive actions | Delete, Remove, Unarchive |

### 9.2 CSS Variables

```css
:root {
  /* Secondary button - quiet/cancel actions */
  --dai-btn-secondary-bg: transparent;
  --dai-btn-secondary-border: #787878;
  --dai-btn-secondary-text: #333;
  --dai-btn-secondary-hover-bg: #f5f5f5;
  --dai-btn-secondary-hover-border: #555;

  /* Intentional button - serious but non-destructive actions */
  --dai-btn-intentional-bg: #5c5c5c;
  --dai-btn-intentional-hover-bg: #454545;
  --dai-btn-intentional-active-bg: #333;

  /* Danger button - destructive actions */
  --dai-btn-danger-bg: var(--dai-badge-danger-bg);
  --dai-btn-danger-hover-bg: #8a1717;
  --dai-btn-danger-active-bg: #6d1212;
}
```

### 9.3 Button Classes

```css
.dai-btn--secondary {
  background-color: var(--dai-btn-secondary-bg);
  border: 1px solid var(--dai-btn-secondary-border);
  color: var(--dai-btn-secondary-text);
}

.dai-btn--intentional {
  background-color: var(--dai-btn-intentional-bg);
  color: #fff;
}

.dai-btn--danger {
  background-color: var(--dai-btn-danger-bg);
  color: #fff;
}
```

---

## 10. Testing Guidelines

### 10.1 Theme Compatibility Testing

Test admin UI in these themes:

| Theme | Version | Priority |
|-------|---------|----------|
| Claro | Drupal 10/11 default | High |
| Gin | Popular contrib | Medium |
| Seven | Legacy (Drupal 9) | Low |

### 10.2 Test Checklist

- [ ] Surface cards display with left accent (no full borders)
- [ ] Tables use theme's row striping
- [ ] Badges are readable (contrast check)
- [ ] Buttons have visible focus states
- [ ] Dropbuttons work on desktop
- [ ] Operation links work on mobile (stacked layout)
- [ ] Tables reflow at 400% zoom without horizontal scroll
- [ ] Touch targets are 44px minimum on mobile

### 10.3 Accessibility Testing

- [ ] Run axe DevTools on inventory page
- [ ] Run axe DevTools on archive management page
- [ ] Verify keyboard navigation through all interactive elements
- [ ] Test with screen reader (NVDA or VoiceOver)
- [ ] Check color contrast with browser dev tools

---

## 11. Implementation Status

All specifications have been implemented:

- [x] CSS variables for all colors in `dai-base.css`
- [x] Surface-first container patterns
- [x] Badge system with 5 semantic colors
- [x] Button styling (secondary, intentional, danger)
- [x] `.dai--admin` wrapper class on all admin views
- [x] Responsive table layout for mobile and 400% zoom
- [x] Operation links converted to stacked links on mobile
- [x] Theme override guide with examples

---

## 12. Non-Goals

- **Dark mode support** - Let admin themes handle this
- **Functional changes** - UI behavior unchanged
- **New features** - This is a styling refactor only

---

## 13. Changelog

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | Jan 2026 | Initial spec |
| 1.1 | Jan 2026 | Added button styling, responsive tables |
| 1.2 | Jan 2026 | Removed row indicators, badge-driven status only |
| 1.3 | Jan 2026 | Namespaced all CSS classes with `dai-` prefix; dai-public.css refactored |
