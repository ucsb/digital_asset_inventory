# Usage Page UI Improvements (Media-Aware)

## Overview

This specification defines enhancements to the Digital Asset Inventory → Usage page to make it Media-aware, improve image accessibility visibility, and provide clear Media management actions, while maintaining full compatibility with non-media and non-image assets.

**Goal**: Surface actionable signals, not declare accessibility compliance.

---

## Goals

- Surface Media context (View/Edit) when assets are Media-backed
- Display image thumbnails at the asset level
- Show alt text status per usage, not per file
- Provide a lightweight alt text summary for triage
- Avoid UI clutter or regressions for non-media assets
- Use compliance-safe language throughout

## Non-Goals

- No automated remediation
- No WCAG pass/fail claims
- No editing alt text inside DAI
- No thumbnails in table rows (header only)
- No changes to existing behavior for non-image, non-media assets

---

## Current State

- **Header**: `AssetInfoHeader.php` shows filename, type, size, access, file URL
- **Table columns**:
  - Used On
  - Item Type
  - Item Category
  - Section
  - Required Field
  - Times Used
- **View**: `views.view.digital_asset_usage.yml`
- **Rendering**: `UsageEntityInfoField.php` handles usage context detection

---

## Scope

| Asset Type | Header Enhancements | Alt Text Column |
|------------|---------------------|-----------------|
| Image (Media) | Yes (thumbnail, Media ID, alt status, View/Edit Media) | Yes |
| Image (File) | Yes (thumbnail) | Yes (inline `<img>` only) |
| Video / Doc (Media) | Yes (Media ID, View/Edit Media) | No |
| Non-media / External | No changes | No |

**Note:** View/Edit Media actions are available in the header only. A separate Media column was removed in v1.20.1 to reduce redundancy.

---

## Architecture Summary

- **Asset-level context** → handled in `AssetInfoHeader`
- **Usage-level context** → handled via Views field plugins
- **Alt text evaluation logic** → centralized in a service
- **Column visibility** → determined once per page (asset-scoped)

---

## Media-Aware Asset Header

### New Header Layout (Media-backed images)

```text
[Thumbnail 64–96px]  Title (filename)
                     JPG | 691.17 KB | Media File | Media ID: 2 | Public
                     Media alt text: detected
                     Alt text summary: 3 with alt · 1 not detected · 1 decorative
                     View Media | Edit Media
                     Direct file URL: https://...
```

### Header Components

**Thumbnail**:
- Render for image MIME types (64–96px)
- Use Drupal image style (configurable)
- SVG → icon placeholder only
- Positioned left of header content

**Metadata line**:
- Format: `Type | Size | Source | Media ID: {mid} | Access`
- Media ID: inline with metadata, muted, monospace font
- Pipe-separated values

**Alt text status**:
- Label: "Media alt text:" (muted color)
- Value: "detected" or "not detected" (normal color)
- Reflects Media image field only

**Alt text summary** (images only):
- Inline format: "Alt text summary: N with alt · N not detected · N decorative"
- Only shown when usages exist
- See Alt Text Summary Strip section for details

**Media actions**:
- Format: `View Media | Edit Media`
- Pipe separator between actions
- View always shown
- Edit permission-aware (hidden if no edit access)

**File URL**:
- Label: "Direct file URL:"
- Full absolute URL displayed

### CSS Classes

```css
.asset-info-header__media-id           /* Media ID in metadata (monospace) */
.asset-info-header__alt-label          /* "Media alt text:" label */
.asset-info-header__alt-value          /* Status value */
.asset-info-header__alt-value--detected    /* Detected state */
.asset-info-header__alt-value--missing     /* Not detected state */
.asset-info-header__media-actions      /* Actions container */
.asset-info-header__action-divider     /* Pipe separator */
.asset-info-header__file-url-label     /* "Direct file URL:" label */
```

---

## Alt Text Column (Usage-Level)

### Key Drupal Behavior

> **In Drupal, Media image alt text is shared across all Media references, but images embedded directly in content can define alt text per usage.**

This distinction is critical:

| Usage Method | Alt Text Source | Per-Page Variation |
|--------------|-----------------|-------------------|
| Media reference field | `media.field_media_image.alt` | No (shared everywhere) |
| Content override | Reference field with custom `alt` | Yes (per content item) |
| Inline `<img>` in text field | HTML `alt` attribute | Yes (page-specific) |
| Template-rendered | Template logic | Varies |

The **header** shows Media-level status ("The Media entity has alt text").
The **table** shows per-usage truth ("What alt text is actually used here?").

### Column Label

`Alt text`

### Status Values

- `detected` — alt text found
- `not_detected` — no alt text found
- `decorative` — empty alt attribute (alt="")
- `not_evaluated` — cannot determine (template-controlled or unknown)

### Source Constants

The `AltTextEvaluator` service defines these source constants:

| Constant | Value | Description |
|----------|-------|-------------|
| `SOURCE_CONTENT_OVERRIDE` | `content_override` | Alt text defined on content item, overrides Media default |
| `SOURCE_INLINE_IMAGE` | `inline_image` | Alt text from inline `<img>` in text field |
| `SOURCE_MEDIA_FIELD` | `media_field` | Alt text from Media entity, shared across usages |
| `SOURCE_TEMPLATE` | `template` | Alt text generated by site templates |

---

### Display Cases

The Alt Text column renders differently based on status and source:

#### Case 1: Detected — Content Override

Alt text was defined on this specific content item, overriding the Media default.

**Display:**
```text
"A large coral tree in full bloom"
(content override)
```

**Styling:**
- Value: normal text color
- Source label: muted, smaller font (0.85em)

**Tooltip on source:** "Alt text defined on this content item and overrides the Media default."

---

#### Case 2: Detected — Inline Image

Alt text extracted from an `<img>` element in a text field.

**Display:**
```text
"Photo of campus quad"
(inline image)
```

**Styling:**
- Value: normal text color
- Source label: muted, smaller font

**Tooltip on source:** "Alt text provided directly in this content field."

---

#### Case 3: Detected — From Media

Alt text comes from the Media entity (shared across all usages).

**Display:**
```text
"Sunset over Storke Tower"
(from media)
```

**Styling:**
- Value: normal text color
- Source label: muted, smaller font

**Tooltip on source:** "Alt text from the Media entity, shared across all usages."

---

#### Case 4: Decorative Image

Image marked as decorative (empty alt attribute: `alt=""`).

**Display:**
```text
Decorative image
```

**Styling:**
- Text: muted color, italic
- No source label shown

**Tooltip:** "Image is marked as decorative (empty alt attribute)."

---

#### Case 5: Template-Controlled

Alt text is generated by site templates and cannot be reliably evaluated.

**Display:**
```text
Managed by template
```

**Styling:**
- Text: muted color, italic
- No source label shown

**Tooltip:** "Alt text is generated by site templates and cannot be evaluated here."

---

#### Case 6: Not Evaluated

Alt text status could not be determined.

**Display:**
```text
Not evaluated
```

**Styling:**
- Text: muted color (not italic)
- No source label shown

**Tooltip:** "Alt text could not be reliably detected for this usage."

---

#### Case 7: Not Detected

No alt text was found for this image usage.

**Display:**
```text
Not detected
```

**Styling:**
- Text: muted color (not italic)
- No source label shown

**Tooltip:** "No alt text was found for this image usage."

---

### UI Principles

1. **Value first, source second** — The actual alt text (or status) is the primary signal
2. **No icons, no badges, no color-coding** — Neutral presentation, not pass/fail
3. **Decorative and template = italic** — Distinct from detected/not detected
4. **Source labels are parenthetical** — Secondary information, not the focus
5. **Tooltips provide context** — Explain what each source means

### Truncation

- Alt text truncated to 80–120 characters
- Truncated text shows full text in tooltip on hover
- Ellipsis indicates truncation

### CSS Classes

```css
.alt-cell                 /* Container */
.alt-cell__value          /* Alt text value */
.alt-cell__source         /* Source label */
.alt-cell__decorative     /* Decorative image state */
.alt-cell__template       /* Template-controlled state */
.alt-cell__unknown        /* Not evaluated state */
.alt-cell__missing        /* Not detected state */
```

### Notes

- Alt text is evaluated per usage, not per file
- No assumptions or inheritance
- Decorative images are informational, not errors
- Fail-safe: If source cannot be confidently determined, return `not_evaluated`

---

## Alt Text Summary Strip

### Location

Inline with asset header metadata, below the alt text status line.

### Display Format (images only)

Single sentence with middle dot separators:

```text
Alt text summary: 3 with alt · 1 not detected · 1 decorative
```

### Full Header Layout Example

```text
[Thumbnail]  coral tree.jpg
             JPG | 691.17 KB | Media File | Media ID: 2 | Public
             Media alt text: detected
             Alt text summary: 3 with alt · 1 not detected · 1 decorative
             View Media | Edit Media
             Direct file URL: https://...
```

### Summary Components

| Component | Display | When Shown |
|-----------|---------|------------|
| With alt | "N with alt" | When detected count > 0 |
| Not detected | "N not detected" | When not_detected count > 0 |
| Not evaluated | "N not evaluated" | When not_evaluated count > 0 |
| Decorative | "N decorative" | When decorative count > 0 |

### Behavior

- Render only if:
  - Asset is image MIME type
  - At least one usage exists
- Inline format (not a separate panel)
- Informational only
- No scoring, percentages, or pass/fail language
- Components separated by middle dot (·) with `aria-hidden="true"`

### CSS Classes

```css
.asset-info-header__alt-summary        /* Container */
.asset-info-header__alt-summary-label  /* "Alt text summary:" label */
.asset-info-header__alt-summary-sep    /* Middle dot separator */
```

---

## Column Visibility (Page-Level)

### Rationale

The Usage page is scoped to one asset, so applicability is known upfront.

### Preferred Behavior

In `hook_views_pre_render()`:
- If asset is not image → remove Alt text column

### Fallback

If column removal is not feasible:
- Render "—" (em dash) in cells
- Never show "N/A"

---

## Compliance-Safe Language

### Use

- "Alt text detected"
- "Alt text not detected"
- "Managed by template"
- "Not evaluated"

### Avoid

- "Accessible"
- "Compliant"
- "WCAG pass/fail"

### Footer Disclaimer

> Alt text is evaluated per usage location. Results indicate whether alt text was detected for this specific use and do not represent a comprehensive accessibility evaluation.

---

## Caching & Performance

- Media resolution: `media:{mid}`, `file:{fid}`
- Alt parsing: cache per entity revision where possible
- Thumbnails: Drupal image styles (cached)
- Skip thumbnails for files > 15MB

---

## Files Affected

### New Files

- `src/Plugin/views/field/UsageAltTextField.php`
- `src/Service/AltTextEvaluator.php`

### Modified Files

- `src/Plugin/views/area/AssetInfoHeader.php` - Thumbnail, Media ID, Alt status, actions, summary strip
- `config/install/views.view.digital_asset_usage.yml` - New columns
- `css/dai-admin.css` - New styles for header layout, summary strip
- `digital_asset_inventory.module` - Page-level column hiding
- `digital_asset_inventory.services.yml` - Alt text evaluator service
- `digital_asset_inventory.install` - Update hook for view config

---

## Design Decisions

1. **Thumbnails**: Header only (cleaner table layout)
2. **Media Actions**: Header only (View/Edit Media links in asset info header)
3. **Alt Summary Strip**: Included (triage tool)
4. **No redundancy**: Media actions removed from table (v1.20.1) since header already provides them

---

## Future Signals (Out of Scope)

- Video captions
- Transcript availability
- Decorative usage counts
- Navigation usage detection
- ARIA attribute analysis
