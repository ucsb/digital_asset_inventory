# Usage Detection Specification

## Overview

The Digital Asset Scanner uses multiple layered methods to detect where assets are used across the site. This ensures comprehensive coverage of different content structures and embedding methods.

## Usage Entity Schema

```sql
CREATE TABLE digital_asset_usage (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(128),
  asset_id INT UNSIGNED,      -- References digital_asset_item.id
  entity_type VARCHAR(32),    -- node, block_content, taxonomy_term, etc.
  entity_id INT UNSIGNED,     -- ID of the content entity
  field_name VARCHAR(255),    -- Field containing the reference
  count INT DEFAULT 1,        -- Number of times used in that field
  presentation_type VARCHAR(32),  -- AUDIO_HTML5, VIDEO_HTML5, etc.
  accessibility_signals LONGTEXT, -- JSON-encoded accessibility signals
  signals_evaluated TINYINT(1) DEFAULT 0,
  embed_method VARCHAR(32) DEFAULT 'field_reference' -- How asset is embedded
);
```

### Embed Method Values

The `embed_method` field tracks how an asset is embedded in content:

| Value | Label | Description |
|-------|-------|-------------|
| `field_reference` | Field Reference | File/image field on entity (direct upload) |
| `drupal_media` | Media Embed | CKEditor `<drupal-media>` embed |
| `html5_video` | HTML5 Video | Raw `<video>` tag in text content |
| `html5_audio` | HTML5 Audio | Raw `<audio>` tag in text content |
| `text_link` | Text Link | `<a href>` link to local file in text content |
| `inline_image` | Inline Image | `<img src>` tag in text content |
| `inline_object` | Object Embed | `<object data>` tag in text content |
| `inline_embed` | Embed Element | `<embed src>` tag in text content |
| `inline_iframe` | Iframe Embed | `<iframe src>` tag embedding external content (YouTube, Vimeo, etc.) |
| `text_url` | Text URL | External URL found in text field content (not in iframe/embed tags) |
| `link_field` | Link Field | URL from a Drupal Link field |
| `menu_link` | Menu Link | Menu link pointing to a file |
| `derived_thumbnail` | Thumbnail | Automatically created preview image for this file |

## Detection Methods

The scanner layers multiple detection methods to find all asset usage:

### Method 1: Entity Reference Fields

**Target**: Media entities referenced via `entity_reference` fields

**How It Works**:
1. Query all entity reference fields that target media entities
2. Use Entity Query API to find entities referencing specific media IDs
3. Handle multilingual sites where same file may have multiple media entities

```php
// Pseudocode
$query = $this->entityTypeManager->getStorage($entity_type)->getQuery();
$query->condition($field_name, $media_id);
$query->accessCheck(FALSE);
$results = $query->execute();
```

**Detects**:
- Media reference fields on nodes
- Media reference fields on paragraphs
- Media reference fields on blocks
- Media reference fields on taxonomy terms

### Method 2: CKEditor Media Embeds

**Target**: Media embedded in text fields via CKEditor's media button

**Pattern**: `<drupal-media data-entity-uuid="...">`

**How It Works**:
1. Search text fields (text_long, text_with_summary) for `drupal-media` tags
2. Extract media UUID from `data-entity-uuid` attribute
3. Match UUID to media entities to find file references

```php
// SQL LIKE search
$query = $database->select($table, 't')
  ->fields('t', ['entity_id'])
  ->condition($field_name . '_value', '%drupal-media%', 'LIKE')
  ->condition($field_name . '_value', '%' . $media_uuid . '%', 'LIKE');
```

**Detects**:
- Media embedded in body fields
- Media in paragraph text fields
- Media in custom text fields

### Method 3: Text Field File Links and Inline Elements

**Target**: Direct links, inline images, and legacy embeds in HTML content

**Tag/Attribute Patterns** (universal `sites/[^/]+/files` pattern via `FilePathResolver` trait):
- `<a href="/sites/*/files/...">` — Text links (`embed_method='text_link'`)
- `<img src="/sites/*/files/...">` — Inline images (`embed_method='inline_image'`)
- `<object data="/sites/*/files/...">` — Legacy object embeds (`embed_method='inline_object'`)
- `<embed src="/sites/*/files/...">` — Legacy embed elements (`embed_method='inline_embed'`)
- Private file equivalents using `/system/files/...`
- Universal `sites/[^/]+/files` regex matches all Drupal installations (default, multisite, Site Factory)

**How It Works**:
1. Scan text field tables for primary content entities: `node__`, `paragraph__`, `taxonomy_term__`, `block_content__`
2. Use SQL LIKE to find file path patterns
3. For each matching field value, extract URLs using `extractLocalFileUrls($text, $tag)`:
   - The method accepts a `$tag` parameter (`a`, `img`, `object`, `embed`) and selects the appropriate attribute (`href` for `<a>`, `data` for `<object>`, `src` for others)
   - Supports multi-line tags, absolute URLs, URL-encoded paths, and query string stripping
4. Create usage records via `processLocalFileLink()` with the appropriate `embed_method`

**Scope**: The scanner targets content entities where files may be presented to the public. System configuration, logs, and administrative metadata are intentionally excluded.

```php
// Extract URLs from different HTML tag types
$link_uris = $this->extractLocalFileUrls($field_value, 'a');       // <a href>
$image_uris = $this->extractLocalFileUrls($field_value, 'img');    // <img src>
$object_uris = $this->extractLocalFileUrls($field_value, 'object'); // <object data>
$embed_uris = $this->extractLocalFileUrls($field_value, 'embed');  // <embed src>
```

**Detects**:
- Links to PDF documents (`text_link`)
- Inline images not via media (`inline_image`)
- Legacy `<object>` embeds for documents (`inline_object`)
- Legacy `<embed>` elements (`inline_embed`)
- Direct file downloads
- Legacy content from pre-media library era

### Method 3b: External URLs in Text and Link Fields

**Target**: External URLs (Google Docs, YouTube, etc.) in text content and Link fields

**How It Works**:
1. Text fields are scanned for URLs matching configured external patterns
2. Link fields have their URL value checked directly
3. Embed method is set based on the source: `text_url` for text fields, `link_field` for Link fields

**Detects**:
- Google Workspace URLs in body text (`text_url`)
- External document service links in body text (`text_url`)
- URLs in Drupal Link fields (`link_field`)

### Method 4: Direct File/Image Fields

**Target**: Files in file/image fields (not media reference)

**How It Works**:
1. Query Drupal's `file_usage` table for non-media usage
2. Identify entities using the file
3. Determine field name by querying entity field definitions

```php
// Query file_usage for non-media entities
$usages = $database->select('file_usage', 'fu')
  ->fields('fu', ['type', 'id', 'module'])
  ->condition('fid', $file_id)
  ->condition('type', 'media', '<>')  // Exclude media (handled separately)
  ->execute();
```

**Detects**:
- Image fields (field_image)
- File fields (field_document)
- Custom file/image fields

### Method 5: Menu Links

**Target**: Files linked directly in navigation menus

**How It Works**:
1. Query all `menu_link_content` entities
2. Extract URI from link field
3. Normalize URI to file path (handle `internal:`, `base:`, and full URLs)
4. Match against known assets in inventory
5. Create usage record with menu context

```php
// Query menu links pointing to files
$query = $database->select('menu_link_content_data', 'm')
  ->fields('m', ['id', 'link__uri', 'menu_name']);

// Normalize URI patterns (construction uses dynamic path via FilePathResolver):
// - internal:/sites/{sitename}/files/...  (e.g., internal:/sites/default/files/...)
// - base:sites/{sitename}/files/...  (e.g., base:sites/default/files/...)
// - https://example.com/sites/{sitename}/files/...
// - internal:/system/files/... (private - universal path)
```

**Detects**:
- PDF documents in menus
- File downloads in navigation
- Policy/form links in menus
- Both public and private file paths

**Display Context**:
- Entity type: `menu_link_content`
- Field name: "Menu Link"
- Bundle: Menu name (e.g., "Main navigation")

## Paragraph Tracing

When assets are found in paragraph entities, the scanner traces through the paragraph hierarchy to find the root content entity.

### Why Paragraph Tracing?

Paragraphs are not standalone content - they're attached to parent entities (nodes, blocks). For meaningful usage tracking, we need to know the parent content, not just "paragraph #123".

### Tracing Algorithm

```text
FUNCTION getParentFromParagraph(paragraph_id):
  1. Load paragraph entity
  2. IF paragraph has parent_field_name:
     a. Get parent entity type and ID
     b. IF parent is another paragraph:
        - RECURSE: getParentFromParagraph(parent_id)
     c. ELSE (parent is node, block, etc.):
        - RETURN {type: parent_type, id: parent_id}
  3. IF no parent found:
     - Log as orphaned paragraph
     - INCREMENT orphan counter
     - RETURN NULL
```

### Orphaned Paragraphs

Paragraphs without a valid parent chain are "orphaned" (from deleted content, old revisions, etc.). These are:
- Skipped during usage tracking
- Counted for reporting (`getOrphanCount()`)
- Logged for administrator awareness

## Usage Record Structure

### Fields

| Field | Description |
| ----- | ----------- |
| asset_id | Reference to digital_asset_item |
| entity_type | Type of content (node, block_content, taxonomy_term) |
| entity_id | ID of the content entity |
| field_name | Name of the field containing the reference |
| count | Number of times asset appears in that field |

### Deduplication

Usage records are deduplicated by: `asset_id + entity_type + entity_id + field_name`

Same asset in same field of same entity = one record (count may be > 1)

## Detection by Asset Location

| Asset Location | Detection Methods Used |
| ------------ | ---------------------- |
| file_managed | All five methods |
| media_managed | Entity reference, CKEditor embeds, menu links |
| filesystem_only | Text field links, menu links |
| external | Text/link field scanning |

## CSV Export Fields

Usage information is pre-computed for CSV export:

### `used_in_csv` Field

Format: `"Page Title (URL); Another Page (URL); ..."`

**Building the Field**:
```php
// Query all usage records for asset
$usages = $usage_storage->loadByProperties(['asset_id' => $asset_id]);

$used_in_parts = [];
foreach ($usages as $usage) {
  // Load parent entity
  $entity = $storage->load($usage->entity_id);
  $title = $entity->label();
  $url = $entity->toUrl()->toString();
  $used_in_parts[$url] = "$title ($url)";  // Keyed by URL for dedup
}

return implode('; ', $used_in_parts) ?: 'No active use detected';
```

## Usage Count in Views

The inventory view displays usage count with a link to the usage detail page.

**Custom Views Field**: `UsedInField`

Displays:
- Count of unique content pages using the asset
- Link to `/admin/digital-asset-inventory/usage/{asset_id}`

## Usage Detail Page

**Route**: `/admin/digital-asset-inventory/usage/{digital_asset_item}`

**Header Information**:
- Asset name and thumbnail (if image)
- File type, size, source
- Media information (if media-backed)
- Alt text status (if image)

**Usage Table Columns**:
| Column | Description |
| ------ | ----------- |
| Used On | Link to content page |
| Item Type | Entity type (Node, Block, etc.) |
| Item Category | Content type (Article, Page, etc.) |
| Section | Field label where asset appears |
| Required Field | Whether field is required |
| Alt text | Alt text status (images only) |
| Media | View/Edit links (media-backed only) |

## Accuracy Considerations

### What IS Detected

- Media reference fields
- CKEditor media embeds
- Direct text/link field references
- File/image field usage
- Nested paragraph usage (traced to parent)

### What Is NOT Detected

- Theme template usage (hardcoded paths)
- Block plugin output (not in content fields)
- Custom code/module output
- Views-generated content (dynamic queries)
- JavaScript-loaded content

### False Positives

Rare but possible:
- URL in commented HTML (still matches pattern)
- URL in code examples (if stored in text field)
- Reverted drafts may show outdated usage

## Performance Optimizations

1. **Direct SQL queries**: Field table scans instead of entity loading
2. **Indexed conditions**: Leverage database indexes on entity_id, fid
3. **Batch processing**: Usage detection happens per-chunk during scan
4. **Deduplication**: Keyed arrays prevent duplicate records

## Related Files

- `src/Service/DigitalAssetScanner.php` - Detection methods
- `src/Entity/DigitalAssetUsage.php` - Usage entity definition
- `src/Plugin/views/field/UsedInField.php` - Usage count display
- `src/Plugin/views/field/UsageEntityInfoField.php` - Usage detail display
