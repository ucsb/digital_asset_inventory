# HTML5 Video/Audio Tag Scanning Specification

## Overview

This specification defines how the Digital Asset Inventory module will scan for and track videos and audio files embedded via raw HTML5 `<video>` and `<audio>` tags in content, bypassing Drupal's Media system.

## Problem Statement

Currently, the scanner only detects media embedded via:
1. **Entity reference fields** - Media entities referenced in fields
2. **CKEditor Media embeds** - `<drupal-media data-entity-uuid="...">` tags
3. **File fields** - Direct file attachments

Videos and audio embedded via raw HTML5 tags are **not detected**, even though:
- The underlying files may exist in Drupal's file system
- The HTML contains valuable accessibility signals (`controls`, `<track>` elements)
- Content editors may paste HTML from external sources or use Full HTML format

### Example Not Currently Detected

```html
<video controls height="360" poster="thumb.jpg" width="640">
  <source src="/sites/default/files/2026-02/video.mp4" type="video/mp4" />
  <track default kind="subtitles" label="English" src="/sites/default/files/2026-02/captions.srt" srclang="en" />
  Your browser does not support the video tag.
</video>
```

This contains:
- Video file URL
- Caption/subtitle file URL
- `controls` attribute
- `poster` image URL

None of this is currently tracked or analyzed.

---

## Requirements

### REQ-001: Detect HTML5 Video Tags
**Type:** Event-driven
**Statement:** When scanning content fields, the system shall detect `<video>` tags and extract source URLs.
**Rationale:** Videos embedded via raw HTML bypass Media system but still need inventory tracking.
**Acceptance Criteria:**
- [ ] Detects `<video src="...">` direct source attribute
- [ ] Detects `<video><source src="..."></video>` nested source elements
- [ ] Handles multiple `<source>` elements (different formats)
- [ ] Extracts `poster` attribute URL if present

### REQ-002: Detect HTML5 Audio Tags
**Type:** Event-driven
**Statement:** When scanning content fields, the system shall detect `<audio>` tags and extract source URLs.
**Rationale:** Audio files embedded via raw HTML need the same tracking as video.
**Acceptance Criteria:**
- [ ] Detects `<audio src="...">` direct source attribute
- [ ] Detects `<audio><source src="..."></audio>` nested source elements
- [ ] Handles multiple `<source>` elements

### REQ-003: Detect Caption/Subtitle Tracks
**Type:** Event-driven
**Statement:** When scanning video tags, the system shall detect `<track>` elements and extract caption file URLs.
**Rationale:** Caption files are accessibility-critical assets that should be inventoried.
**Acceptance Criteria:**
- [ ] Detects `<track src="...">` elements
- [ ] Extracts `kind` attribute (subtitles, captions, descriptions, chapters, metadata)
- [ ] Extracts `srclang` attribute for language identification
- [ ] Extracts `label` attribute for display name

### REQ-004: Link to Existing Inventory Items
**Type:** State-driven
**Statement:** While processing HTML5 embeds, the system shall link detected URLs to existing inventory items when the file already exists in the inventory.
**Rationale:** Avoid duplicate inventory entries; track usage of already-known files.
**Acceptance Criteria:**
- [ ] Matches URL to existing `digital_asset_item` by `file_path`
- [ ] Creates usage record linking embed location to existing asset
- [ ] Handles URL variations (with/without domain, encoded characters)

### REQ-005: Create New Items for Unknown Files
**Type:** Event-driven
**Statement:** When an HTML5 embed references a file not in the inventory, the system shall create a new inventory item.
**Rationale:** Files uploaded via FTP or referenced externally need tracking.
**Acceptance Criteria:**
- [ ] Creates `digital_asset_item` for unknown local files
- [ ] Creates `digital_asset_item` for external video URLs
- [ ] Sets appropriate `source_type` based on file location

### REQ-006: Extract Accessibility Signals
**Type:** Event-driven
**Statement:** When processing HTML5 embeds, the system shall extract accessibility signals and store them with the usage record.
**Rationale:** Raw HTML contains accessibility data that Media entities lack.
**Acceptance Criteria:**
- [ ] Detects `controls` attribute presence
- [ ] Detects `<track kind="captions|subtitles">` presence
- [ ] Stores signals in usage record for per-context evaluation

---

## Design Decisions

### DD-001: Asset Categorization

**Question:** Should raw HTML5 video/audio embeds be categorized as "Embedded Media" or keep their native category (Videos/Audio)?

**Decision:** **Keep native category (Videos/Audio)** based on file type, not embed method.

**Rationale:**
- "Embedded Media" category is for **external platforms** (YouTube, Vimeo, Slideshare) where we can't inspect the actual file
- Local video files (matching universal `sites/[^/]+/files` path pattern) are the same whether embedded via Media or raw HTML
- The embed method is tracked via `source_type` and usage context, not category
- Keeps reporting consistent: all MP4 files appear under "Videos" regardless of how they're embedded

**Category mapping:**
| Source | Category | Asset Type |
|--------|----------|------------|
| Local video file via `<video>` | Videos | mp4, webm, mov, etc. |
| Local audio file via `<audio>` | Audio | mp3, wav, ogg, etc. |
| External video URL via `<video>` | Embedded Media | external_video |
| Caption/subtitle file via `<track>` | Documents | srt, vtt |

### DD-002: Source Type for HTML5 Embeds

**Question:** What `source_type` should be used for files discovered via HTML5 tags?

**Decision:** Use existing source types based on file origin, add embed context to usage record.

| File Location | source_type | Notes |
|---------------|-------------|-------|
| `/sites/[^/]+/files/...` (public, e.g., `/sites/default/files/...`) | `file_managed` or `filesystem_only` | Check if file exists in `file_managed` table |
| `/system/files/...` (private) | `file_managed` or `filesystem_only` | Check if file exists in `file_managed` table |
| External URL (e.g., `https://cdn.example.com/video.mp4`) | `external` | External video hosting |

### DD-003: Usage Record Enhancement

**Decision:** Add new field `embed_method` to `digital_asset_usage` to distinguish how media is embedded.

**Values:**
- `field_reference` - Entity reference field (existing)
- `drupal_media` - CKEditor `<drupal-media>` embed (existing)
- `html5_video` - Raw `<video>` tag (new)
- `html5_audio` - Raw `<audio>` tag (new)
- `text_link` - Hyperlink in text content (existing)
- `menu_link` - Menu link (existing)

This allows filtering inventory by embed method and understanding adoption of Media system vs raw HTML.

### DD-004: Caption File Handling

**Question:** How should caption/subtitle files (`<track>` elements) be handled?

**Decision:** Track caption files as separate inventory items in "Documents" category.

**Rationale:**
- Caption files (SRT, VTT) are distinct assets that need accessibility review
- They may be reused across multiple videos
- Tracking them separately allows reporting on caption coverage

**Implementation:**
- Create `digital_asset_item` for each unique caption file URL
- Asset type: `srt` or `vtt` based on extension
- Category: Documents (they are text documents)
- Create usage record linking caption to parent video's usage context
- Add `caption_for` relationship field to link caption to video asset

### DD-005: Signal Storage Location

**Question:** Where should accessibility signals extracted from HTML5 tags be stored?

**Decision:** Store in `digital_asset_usage` entity (existing pattern), not on the asset itself.

**Rationale:**
- Signals are **context-dependent** - the same video file could be embedded with `controls` in one place and without in another
- Follows existing pattern used by `MediaAccessibilitySignalDetector`
- Allows per-usage signal evaluation

**Signals to extract from HTML5 video:**
| Signal | Source | Storage |
|--------|--------|---------|
| `controls` | `<video controls>` attribute | `usage.signals.controls` |
| `captions` | `<track kind="captions\|subtitles">` presence | `usage.signals.captions` |
| `autoplay` | `<video autoplay>` attribute | `usage.signals.autoplay` |
| `muted` | `<video muted>` attribute | `usage.signals.muted` |
| `loop` | `<video loop>` attribute | `usage.signals.loop` |

**Signals to extract from HTML5 audio:**
| Signal | Source | Storage |
|--------|--------|---------|
| `controls` | `<audio controls>` attribute | `usage.signals.controls` |
| `autoplay` | `<audio autoplay>` attribute | `usage.signals.autoplay` |
| `muted` | `<audio muted>` attribute | `usage.signals.muted` |
| `loop` | `<audio loop>` attribute | `usage.signals.loop` |

---

## Technical Design

### Scan Phase Integration

Add HTML5 media scanning to existing `scanContentChunk()` method in `DigitalAssetScanner`.

```text
Existing scan flow:
1. batchProcessManagedFiles - file_managed table
2. batchProcessOrphanFiles - filesystem scan
3. batchProcessContent - external URLs ← ADD HTML5 SCANNING HERE
4. batchProcessMediaEntities - remote media (YouTube, Vimeo via Media)
5. batchProcessMenuLinks - menu links

Updated flow for step 3:
3a. Scan for external URLs (existing)
3b. Scan for <video> tags (new)
3c. Scan for <audio> tags (new)
3d. Scan for <track> elements within video/audio (new)
```

### Regex Patterns

```php
// Video tag with optional nested sources
$video_pattern = '/<video[^>]*>.*?<\/video>/is';

// Audio tag with optional nested sources
$audio_pattern = '/<audio[^>]*>.*?<\/audio>/is';

// Source element (within video/audio)
$source_pattern = '/<source[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

// Track element (within video)
$track_pattern = '/<track[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

// Direct src attribute on video/audio
$direct_src_pattern = '/<(?:video|audio)[^>]+src=["\']([^"\']+)["\'][^>]*>/i';

// Controls attribute detection
$controls_pattern = '/<(?:video|audio)[^>]*\bcontrols\b/i';

// Poster attribute (video only)
$poster_pattern = '/<video[^>]+poster=["\']([^"\']+)["\'][^>]*>/i';
```

### URL Resolution

URLs in HTML5 tags may be:
- Absolute: `https://example.com/video.mp4`
- Protocol-relative: `//example.com/video.mp4`
- Root-relative: `/sites/default/files/video.mp4` (matched via universal `sites/[^/]+/files` pattern)
- Relative: `../files/video.mp4`

Resolution logic:
```php
function resolveMediaUrl($url, $base_url) {
  if (parse_url($url, PHP_URL_SCHEME)) {
    // Absolute URL
    return $url;
  }
  if (strpos($url, '//') === 0) {
    // Protocol-relative
    return 'https:' . $url;
  }
  if (strpos($url, '/') === 0) {
    // Root-relative
    return $base_url . $url;
  }
  // Relative - resolve against content URL
  return $base_url . '/' . $url;
}
```

### Data Flow

```text
┌─────────────────────────────────────────────────────────────────┐
│ Text Field Content                                               │
│ <video controls>                                                 │
│   <source src="/files/video.mp4" type="video/mp4">              │
│   <track src="/files/captions.srt" kind="subtitles">            │
│ </video>                                                         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ HTML5 Media Parser                                               │
│ 1. Extract <video> tag                                           │
│ 2. Parse src, controls, poster attributes                        │
│ 3. Parse nested <source> elements                                │
│ 4. Parse nested <track> elements                                 │
└─────────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│ Video File      │ │ Caption File    │ │ Poster Image    │
│ /files/video.mp4│ │ /files/caps.srt │ │ /files/thumb.jpg│
└────────┬────────┘ └────────┬────────┘ └────────┬────────┘
         │                   │                   │
         ▼                   ▼                   ▼
┌─────────────────────────────────────────────────────────────────┐
│ Inventory Lookup                                                 │
│ - Check if URL matches existing digital_asset_item.file_path    │
│ - If found: create usage record                                  │
│ - If not found: create new asset item + usage record            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ Usage Record                                                     │
│ - asset_id: [matched or new asset]                              │
│ - entity_type: node                                              │
│ - entity_id: 123                                                 │
│ - field_name: body                                               │
│ - embed_method: html5_video                                      │
│ - signals: {controls: detected, captions: detected}             │
└─────────────────────────────────────────────────────────────────┘
```

---

## Database Changes

### digital_asset_usage Table

Add new column:

```sql
ALTER TABLE digital_asset_usage
ADD COLUMN embed_method VARCHAR(32) DEFAULT 'field_reference';
```

Values:
- `field_reference` - Entity reference field
- `drupal_media` - CKEditor media embed
- `html5_video` - Raw `<video>` tag
- `html5_audio` - Raw `<audio>` tag
- `text_link` - Hyperlink in text
- `menu_link` - Menu link

### digital_asset_item Table

Add new column for caption relationship (optional):

```sql
ALTER TABLE digital_asset_item
ADD COLUMN caption_for_asset_id INT UNSIGNED DEFAULT NULL;
```

This links a caption file (SRT/VTT) to its parent video asset.

---

## UI Changes

### Inventory View

The main inventory view tracks assets. The "Used In" column shows usage count with link to the usage detail view.

### Usage Detail View

The usage detail view (`/admin/digital-asset-inventory/usage/{asset_id}`) shows all locations where an asset is used. For video and audio assets, additional columns are displayed:

**Embed Type Column:**
Shows how the asset is embedded using the `MediaSignalsField` Views plugin:

| `embed_method` Value | Display Label |
|---------------------|---------------|
| `drupal_media` | Media Embed |
| `field_reference` | Field Reference |
| `html5_video` | HTML5 Video |
| `html5_audio` | HTML5 Audio |
| `text_link` | Text Link |
| `menu_link` | Menu Link |

**Important:** The Embed Type column prioritizes `embed_method` over `presentation_type` for consistent labeling. This ensures "Media Embed" displays for `<drupal-media>` embeds instead of "Hosted Video" (which was the `presentation_type`).

**Example usage table:**
| Used On | Embed Type | Controls | Captions |
|---------|------------|----------|----------|
| Homepage | Media Embed | Yes | Unknown |
| About Us | HTML5 Video | Yes | Yes |
| Contact | Text Link | — | — |

### Signals Display

For video and audio assets, accessibility signals columns are shown:
- **Controls:** Yes / No / Unknown
- **Captions:** Yes / No / Unknown
- **Transcript:** Yes / No / Unknown

Signal columns only appear when viewing video or audio assets. The `MediaSignalsField` plugin checks if the asset category is "Videos" or "Audio" before rendering.

---

## Migration/Update Hook

```php
/**
 * Add embed_method column to digital_asset_usage table.
 */
function digital_asset_inventory_update_10036() {
  $schema = \Drupal::database()->schema();

  if (!$schema->fieldExists('digital_asset_usage', 'embed_method')) {
    $schema->addField('digital_asset_usage', 'embed_method', [
      'type' => 'varchar',
      'length' => 32,
      'not null' => TRUE,
      'default' => 'field_reference',
      'description' => 'How the asset is embedded (field_reference, drupal_media, html5_video, html5_audio, text_link, menu_link)',
    ]);
  }

  return t('Added embed_method column to digital_asset_usage table.');
}
```

---

## Test Cases

### TC-001: Basic Video Tag Detection
**Given:** Content with `<video src="/files/video.mp4" controls></video>`
**When:** Scanner runs
**Then:** Video file is added to inventory with usage record, `embed_method=html5_video`, `signals.controls=detected`

### TC-002: Video with Nested Sources
**Given:** Content with `<video><source src="/files/video.mp4"><source src="/files/video.webm"></video>`
**When:** Scanner runs
**Then:** Both video files are added to inventory with usage records

### TC-003: Video with Caption Track
**Given:** Content with `<video><source src="/files/video.mp4"><track src="/files/captions.srt" kind="subtitles"></video>`
**When:** Scanner runs
**Then:** Video and caption files are added, `signals.captions=detected`, caption linked to video

### TC-004: Audio Tag Detection
**Given:** Content with `<audio src="/files/podcast.mp3" controls></audio>`
**When:** Scanner runs
**Then:** Audio file is added to inventory with usage record, `embed_method=html5_audio`

### TC-005: External Video URL
**Given:** Content with `<video src="https://cdn.example.com/video.mp4"></video>`
**When:** Scanner runs
**Then:** External video is added with `source_type=external`, category=Embedded Media

### TC-006: Existing File Linkage
**Given:** Video file already exists in inventory (uploaded via Media)
**And:** Same file is also embedded via raw HTML in another page
**When:** Scanner runs
**Then:** Single inventory item with two usage records (one `drupal_media`, one `html5_video`)

### TC-007: No Controls Attribute
**Given:** Content with `<video src="/files/video.mp4"></video>` (no controls)
**When:** Scanner runs
**Then:** Usage record has `signals.controls=not_detected`

### TC-008: Autoplay Detection (Accessibility Concern)
**Given:** Content with `<video src="/files/video.mp4" autoplay></video>`
**When:** Scanner runs
**Then:** Usage record has `signals.autoplay=detected` (flagged for review)

---

## Implementation Tasks

- [x] Task 1: Add `embed_method` field to `DigitalAssetUsage` entity ✓
  - Updated entity definition in `DigitalAssetUsage.php`
  - Created update hook for existing installations

- [x] Task 2: Create HTML5 media parser service ✓
  - `extractHtml5MediaEmbeds()` parses `<video>` and `<audio>` tags
  - `parseHtml5MediaTag()` extracts attributes from tags
  - Parses nested `<source>` and `<track>` elements
  - `resolveRelativeUrl()` handles URL resolution

- [x] Task 3: Integrate parser into `scanContentChunk()` ✓
  - Called after existing external URL scan
  - `processHtml5MediaEmbed()` matches URLs to existing inventory items
  - Creates new items for unknown files via `findOrCreateExternalVideoAsset()`
  - Creates usage records with `embed_method` set to `html5_video` or `html5_audio`

- [x] Task 4: Extract accessibility signals ✓
  - Detects `controls`, `autoplay`, `muted`, `loop` attributes
  - Detects `<track>` presence and extracts kind, srclang, label
  - Stores signals in `accessibility_signals` JSON field on usage record

- [x] Task 5: Handle caption files ✓
  - `findOrCreateCaptionAsset()` creates inventory items for SRT/VTT files
  - VTT (`text/vtt`) and SRT (`application/x-subrip`) MIME types added
  - Category: Documents, asset_type: vtt/srt
  - Usage records created linking caption to parent video's context

- [x] Task 6: Update `MediaAccessibilitySignalDetector` ✓
  - Supports `html5_video` and `html5_audio` embed methods
  - `evaluateUsageSignals()` uses pre-extracted signals from usage record
  - `detectPresentationType()` returns `VIDEO_HTML5` or `AUDIO_HTML5` for raw tags

- [x] Task 7: Update Views and UI ✓
  - `MediaSignalsField` plugin displays Embed Type column in usage view
  - Shows embed method labels: "Media Embed", "Text Link", "HTML5 Video", "HTML5 Audio", "Menu Link", "Field Reference"
  - **Prioritizes `embed_method` over `presentation_type`** for consistent labeling
  - Displays accessibility signals (Controls, Captions, Transcript) for video/audio assets

- [x] Task 8: Update CLAUDE.md ✓
  - Documented new `embed_method` field values
  - Documented HTML5 scanning capability
  - Added test cases and changelog entries

---

## Open Questions

1. **Poster images:** Should `poster` attribute images be tracked as separate inventory items?
   - Pro: Complete picture of video-related assets
   - Con: May already be tracked as regular images

2. **Fallback content:** Should we parse the fallback text inside `<video>` tags?
   - Example: `<video>Your browser does not support video.</video>`
   - Could indicate accessibility awareness (or lack thereof)

3. **Iframe embeds:** Should we also scan for `<iframe>` tags embedding video (non-oEmbed)?
   - Example: `<iframe src="https://player.vimeo.com/video/123"></iframe>`
   - Currently only oEmbed via Media is detected
