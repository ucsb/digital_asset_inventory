# Audio & Video Accessibility Signals Specification

## Overview

This specification defines how the Digital Asset Inventory module detects and displays accessibility signals for audio and video assets. Signals are evaluated **per-usage** (how the media is embedded in each content location), not per-asset, mirroring the existing alt-text pattern used for images.

**Key Principle**: Signals describe what accessibility features are **detectable** in the embedding context. They use compliance-safe language ("detected", "not detected", "unknown") rather than pass/fail terminology. Unknown is a valid state, not a failure.

---

## Goals

- Surface accessibility signals for audio and video assets per usage context
- Display presentation type (how media is embedded)
- Detect controls, captions, and transcript availability
- Use compliance-safe language throughout
- Maintain fail-safe behavior (unknown != failure)

## Non-Goals

- No automated remediation
- No WCAG pass/fail claims
- No editing accessibility features inside DAI
- No external platform API integration (e.g., checking YouTube caption status)
- No changes to existing behavior for image, document, or external assets

---

## Requirements

### REQ-001: Per-Usage Signal Detection

**Type:** Event-driven
**Statement:** When displaying usage details for an audio or video asset, the system shall evaluate accessibility signals for each usage context independently.

**Rationale:** The same video file may be embedded with captions in one location and without in another. Per-usage detection ensures accurate compliance reporting.

### REQ-002: Presentation Type Classification

**Type:** State-driven
**Statement:** The system shall classify each audio/video usage by its presentation type to determine which signals are applicable.

**Presentation Types:**

| Type | Description | Applicable Signals |
|------|-------------|-------------------|
| `AUDIO_HTML5` | HTML5 `<audio>` element | controls, transcript_link |
| `VIDEO_HTML5` | HTML5 `<video>` element (local file) | controls, captions, transcript_link |
| `VIDEO_IFRAME_REMOTE` | Remote video via iframe (YouTube, Vimeo) | captions*, transcript_link |
| `VIDEO_EMBED_HOSTED` | Hosted video via Media Library | controls, captions, transcript_link |

*Remote iframe captions are platform-dependent and may only be detected as "unknown"

### REQ-003: Signal Detection

**Type:** Event-driven
**Statement:** The system shall detect the following accessibility signals based on presentation type.

**Signals:**

| Signal | Description | Detection Method |
|--------|-------------|------------------|
| `controls` | Player controls are present | Check for `controls` attribute on `<audio>`/`<video>` |
| `captions` | Captions/subtitles available | Check for `<track kind="captions">` or `<track kind="subtitles">` |
| `transcript_link` | Link to transcript nearby | Heuristic: scan surrounding content for transcript links |

**Signal Values:**

| Value | Meaning |
|-------|---------|
| `detected` | Signal is present in the embedding context |
| `not_detected` | Signal is not present (checked and absent) |
| `unknown` | Cannot determine (e.g., iframe content, external platform) |
| `not_applicable` | Signal doesn't apply to this presentation type |

### REQ-004: Usage Page Display

**Type:** State-driven
**Statement:** The Usage Page shall display accessibility signal information in the per-usage table via signal columns showing status for each usage row.

**Note:** Header summary was considered but removed because signals are highly context-dependent per-usage, and aggregate counts are less actionable than reviewing individual usages. The signals indicate what accessibility features are **detectable**, not whether the content is fully accessible.

### REQ-005: Fail-Safe Design

**Type:** Ubiquitous
**Statement:** The system shall treat detection failures gracefully. If signal detection fails for any reason, the signal value shall be "unknown" rather than causing an error.

---

## Data Model

### Entity Changes: DigitalAssetUsage

Add three new fields to the `digital_asset_usage` entity:

| Field | Type | Description |
|-------|------|-------------|
| `presentation_type` | VARCHAR(32) | How the media is embedded (AUDIO_HTML5, VIDEO_HTML5, etc.) |
| `accessibility_signals` | LONGTEXT (JSON) | Signal values: `{"controls":"detected","captions":"not_detected",...}` |
| `signals_evaluated` | TINYINT(1) | Whether signals have been evaluated for this usage |

**JSON Structure for `accessibility_signals`:**

```json
{
  "controls": "detected",
  "captions": "not_detected",
  "transcript_link": "unknown"
}
```

---

## Service Design

### MediaAccessibilitySignalDetector Service

New service mirroring the `AltTextEvaluator` pattern:

**Service ID:** `digital_asset_inventory.media_signal_detector`

**Key Methods:**

| Method | Purpose |
|--------|---------|
| `evaluateUsageSignals()` | Evaluate all accessibility signals for a usage context |
| `determinePresentationType()` | Classify how media is embedded |
| `detectControlsSignal()` | Check for player controls |
| `detectCaptionsSignal()` | Check for captions/subtitles |
| `detectTranscriptLinkSignal()` | Scan for nearby transcript links |
| `getAssetSignalSummary()` | Aggregate signals across all usages for an asset |

**Constants:**

```php
const AUDIO_HTML5 = 'AUDIO_HTML5';
const VIDEO_HTML5 = 'VIDEO_HTML5';
const VIDEO_IFRAME_REMOTE = 'VIDEO_IFRAME_REMOTE';
const VIDEO_EMBED_HOSTED = 'VIDEO_EMBED_HOSTED';

const SIGNAL_DETECTED = 'detected';
const SIGNAL_NOT_DETECTED = 'not_detected';
const SIGNAL_UNKNOWN = 'unknown';
const SIGNAL_NOT_APPLICABLE = 'not_applicable';
```

---

## Presentation Type Detection

### Detection Logic

```
IF asset.category == "Audio":
  IF field uses HTML5 audio formatter:
    RETURN "AUDIO_HTML5"
  ELSE:
    RETURN "AUDIO_HTML5" (default for audio)

ELSE IF asset.category == "Videos":
  IF asset.source_type == "external":
    RETURN "VIDEO_IFRAME_REMOTE"
  ELSE IF field uses Media Library with video formatter:
    IF media uses oEmbed source (YouTube, Vimeo):
      RETURN "VIDEO_IFRAME_REMOTE"
    ELSE:
      RETURN "VIDEO_EMBED_HOSTED"
  ELSE IF field uses HTML5 video formatter:
    RETURN "VIDEO_HTML5"
  ELSE:
    RETURN "VIDEO_HTML5" (default for local video)
```

---

## Signal Detection Details

### Controls Detection

**HTML5 Audio/Video:**
- Check for `controls` attribute in rendered markup
- Regex: `/<(audio|video)[^>]*\bcontrols\b/i`

**Iframe/Remote:**
- Return `unknown` (cannot inspect iframe content)

### Captions Detection

**HTML5 Video:**
- Check for `<track>` elements with `kind="captions"` or `kind="subtitles"`
- Regex: `/<track[^>]*kind=["\']?(captions|subtitles)/i`

**Media Library Hosted Video:**
- Check Media entity for caption track field
- Check associated VTT/SRT files

**Iframe/Remote (YouTube, Vimeo):**
- Return `unknown` (platform manages captions externally)

**Audio:**
- Return `not_applicable` (captions are for video)

### Transcript Link Detection

**Heuristic Approach:**
1. Scan parent entity's text fields for links containing "transcript" keywords
2. Check for sibling fields with "transcript" in field name
3. Check for adjacent paragraph entities with transcript content

**Keywords to match:**
- transcript
- transcription
- text version
- full text
- read along

---

## Usage Page Display

### Header Summary Section

~~Originally planned but removed.~~ Header summary was considered but removed because:
- Signals are highly context-dependent per-usage
- Aggregate counts are less actionable than reviewing individual usages
- The signals indicate what accessibility features are **detectable**, not whether the content is fully accessible

### Per-Usage Table Columns

Add new columns to usage table (after existing columns):

| Column | Header | Values |
|--------|--------|--------|
| Embed Type | "Embed Type" | Media Embed, Field Reference, HTML5 Video, Text Link, etc. |
| Controls | "Controls" | Icon + text (Yes, No, Unknown, —) |
| Captions | "Captions" | Icon + text (Yes, No, Unknown, —) |
| Transcript | "Transcript" | Icon + text (Yes, No, Unknown, —) |

**Embed Type Column Prioritization:**

The Embed Type column shows the `embed_method` (how the asset is embedded) rather than `presentation_type` (what type of media presentation it is). This provides consistent labeling:

| `embed_method` Value | Display Label |
|---------------------|---------------|
| `drupal_media` | Media Embed |
| `field_reference` | Field Reference |
| `html5_video` | HTML5 Video |
| `html5_audio` | HTML5 Audio |
| `text_link` | Text Link |
| `menu_link` | Menu Link |

If `embed_method` is not set, the column falls back to displaying `presentation_type` labels (HTML5 Video, Remote Iframe, Hosted Video).

**Column Visibility:**
- Only show for assets in Video or Audio categories
- Use CSS page-level column hiding (same pattern as alt-text for images)

**Display Values:**

| Signal Value | Display | Tooltip |
|--------------|---------|---------|
| `detected` | "Yes" | "Signal detected in this usage context" |
| `not_detected` | "No" | "Signal not found in this usage context" |
| `unknown` | "Unknown" | "Cannot determine (external platform or iframe)" |
| `not_applicable` | "—" | "Signal does not apply to this media type" |

---

## Views Integration

### New Views Field Plugin

Create `MediaSignalsField` plugin (mirrors `UsageAltTextField` pattern):

**File:** `src/Plugin/views/field/MediaSignalsField.php`

**Features:**
- Renders signal values with icons
- Handles presentation type display
- Supports column configuration

### Header Area Plugin

~~Originally planned to modify `AssetInfoHeader` for signal summary, but removed.~~ No header modifications needed - signals are displayed only in the per-usage table columns.

---

## Evaluation Timing

### When Signals Are Evaluated

**Option A: On-Demand (Recommended)**
- Evaluate signals when Usage Page is viewed
- Cache results in usage entity fields
- Re-evaluate on cache miss or explicit refresh

**Rationale:** Content may change; on-demand ensures freshness while caching provides performance.

### Evaluation Flow

```
1. User views Usage Page for audio/video asset
2. System loads all usage records for asset
3. For each usage where signals_evaluated = FALSE:
   a. Load parent entity
   b. Determine presentation type
   c. Detect each applicable signal
   d. Store results in usage entity
   e. Set signals_evaluated = TRUE
4. Display cached results
```

### Cache Invalidation

- Set `signals_evaluated = FALSE` when:
  - Parent entity is updated
  - Media entity is updated
  - Usage is re-scanned

---

## Schema Migration

### Update Hook

```php
/**
 * Add accessibility signal fields to digital_asset_usage entity.
 */
function digital_asset_inventory_update_10040() {
  // Add presentation_type field
  // Add accessibility_signals field (JSON)
  // Add signals_evaluated flag
}
```

---

## CSS Styling

### Signal Display Classes

```css
.signal-cell                    /* Container */
.signal-cell__value             /* Value display */
.signal-cell__value--detected   /* Green/positive */
.signal-cell__value--not-detected /* Muted/neutral */
.signal-cell__value--unknown    /* Orange/uncertain */
.signal-cell__value--na         /* Gray/muted */
```

### Summary Strip Classes

```css
.asset-info-header__signals-summary      /* Container */
.asset-info-header__signals-summary-label /* Label */
.asset-info-header__signals-summary-item  /* Each signal line */
```

---

## Test Cases

### TC-AVS-001: HTML5 Video with Captions

1. Upload video file with `<track kind="captions">` in formatter
2. Add video to page via Media reference field
3. Run scanner, view Usage Page

**Expected:**
- Presentation Type: "HTML5 Video"
- Controls: "Yes" (if `controls` attribute present)
- Captions: "Yes"
- Transcript: "No" (unless transcript link nearby)

### TC-AVS-002: YouTube Video (Remote Iframe)

1. Add YouTube video via Media Library (oEmbed)
2. Embed in page content
3. View Usage Page

**Expected:**
- Presentation Type: "Remote Iframe"
- Controls: "Unknown"
- Captions: "Unknown"
- Transcript: "No" or "Yes" (based on nearby content)

### TC-AVS-003: Audio with Transcript

1. Upload audio file
2. Add to page with adjacent text field containing "Download transcript" link
3. View Usage Page

**Expected:**
- Presentation Type: "HTML5 Audio"
- Controls: "Yes" or "No" (based on formatter)
- Captions: "—" (not applicable)
- Transcript: "Yes"

### TC-AVS-004: Multiple Usages, Mixed Signals

1. Add same video to 3 different pages
2. Page A: with captions track
3. Page B: without captions
4. Page C: external embed
5. View Usage Page

**Expected Header:**
- "Captions: 1 of 3 usages detected, 1 unknown"

### TC-AVS-005: Signal Evaluation Caching

1. View Usage Page (signals evaluated)
2. View again (should use cached values)
3. Edit parent entity
4. View Usage Page (should re-evaluate)

**Expected:** `signals_evaluated` flag controls caching behavior

### TC-AVS-006: Non-Video Asset

1. View Usage Page for PDF document
2. Check for signal columns

**Expected:** Signal columns hidden (not applicable to documents)

---

## Files to Create/Modify

| File | Action | Description |
|------|--------|-------------|
| `src/Entity/DigitalAssetUsage.php` | Modify | Add 3 new fields with getters/setters |
| `src/Service/MediaAccessibilitySignalDetector.php` | Create | New service for signal detection |
| `digital_asset_inventory.services.yml` | Modify | Register new service |
| `src/Plugin/views/field/MediaSignalsField.php` | Create | Views field plugin for signals |
| `digital_asset_inventory.module` | Modify | Register Views field, add column visibility logic |
| `config/install/views.view.digital_asset_usage.yml` | Modify | Add signal columns |
| `digital_asset_inventory.install` | Modify | Add update hooks 10037, 10038 |
| `css/dai-admin.css` | Modify | Add signal cell styling |

---

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Per-usage vs per-asset | Per-usage | Same file may have different signals in different contexts |
| Storage format | JSON field | Flexible for future signal additions |
| Evaluation timing | On-demand with caching | Balances freshness and performance |
| Unknown as valid | Yes | Cannot inspect external platforms; unknown != failure |
| Language | "Detected/Not detected" | Compliance-safe; avoids pass/fail implications |
| Scope | Video and Audio only | Images use alt-text; documents use different patterns |
| Header summary | Removed | Signals are context-dependent per-usage; aggregate counts less useful than individual review; signals indicate detectability, not accessibility |

---

## Future Considerations

1. **Audio description signal**: For videos with described audio track
2. **Autoplay detection**: Flag videos that autoplay (accessibility concern)
3. **Sign language signal**: For videos with sign language interpretation
4. **Bulk evaluation**: Re-evaluate all usages via admin action
5. **CSV export**: Include signals in inventory CSV export

---

## Related Documentation

- [Usage Detection Spec](../inventory-specs/usage-detection-spec.md) - How usage is tracked
- [Usage Page Media-Aware Spec](usage-page-media-aware-spec.md) - Alt-text pattern for images
- [Asset Types & Categories](../inventory-specs/asset-types-categories-spec.md) - Video/Audio category definitions

---

End of Specification
