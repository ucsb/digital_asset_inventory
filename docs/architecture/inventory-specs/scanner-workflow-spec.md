# Scanner Workflow Specification

## Overview

The Digital Asset Scanner (`DigitalAssetScanner` service) discovers and catalogs all digital assets across a Drupal site. The scanning process runs in four sequential phases, each targeting a different asset source. The scanner uses Drupal's Batch API for processing large datasets without timeouts.

## Scan Initiation

**Route**: `/admin/digital-asset-inventory/scan`
**Form**: `ScanAssetsForm`
**Permission**: `scan digital assets`

### Pre-Scan Steps

1. Reset scan statistics (`resetScanStats()`)
2. Store scan start timestamp in Drupal State (used to calculate scan duration at completion)
3. Initialize batch operations for all four phases

## Scan Phases

### Phase 1: Managed Files

**Method**: `scanManagedFilesChunk($offset, $limit, $is_temp)`
**Batch Size**: 50 items per chunk
**Source**: `file_managed` database table

#### Process

```
FOR EACH file in file_managed (offset, limit):
  1. Skip system-generated files (see Exclusions below)
  2. Determine asset_type from MIME type
  3. Determine category from asset_type
  4. Check for Media entity association (file_usage table)
  5. Convert URI to absolute URL
  6. Detect private file system (private://)
  7. Create/update digital_asset_item (is_temp=TRUE)
  8. Detect and record usage:
     - Entity reference fields targeting media
     - CKEditor media embeds (<drupal-media> tags)
     - Text field file links (href/src to /sites/default/files/)
     - Direct file/image field usage
  9. Update CSV export fields (filesize_formatted, used_in_csv)
```

#### File Exclusions

System-generated files are excluded from scanning:

| Pattern | Description |
| ------- | ----------- |
| `styles/` | Image style derivatives |
| `thumbnails/` | Various thumbnail directories |
| `media-icons/` | Media type icons |
| `oembed_thumbnails/` | oEmbed preview images |
| `video_thumbnails/` | Video preview images |
| `css/` | Aggregated CSS |
| `js/` | Aggregated JavaScript |
| `php/` | PHP temporary files |
| `ctools/` | CTools generated files |
| `xmlsitemap/` | Sitemap files |
| `config_*` | Configuration exports |
| `wordmark/` | Site logos |
| `archive/` | ADA-archived documents |

### Phase 2: Orphan Files

**Method**: `scanOrphanFilesChunk($offset, $limit, $is_temp)`
**Batch Size**: 50 items per chunk
**Source**: Filesystem (`public://`, `private://`)

#### Purpose

Discovers files uploaded outside Drupal (FTP, SFTP, direct upload) that are not tracked in `file_managed`.

#### Process

```
FOR EACH file on filesystem (recursive scan):
  1. Skip excluded directories (same as Phase 1)
  2. Match file extension against known types
  3. Check if file exists in file_managed
  4. IF NOT in file_managed:
     a. Determine MIME type from extension
     b. Determine asset_type and category
     c. Create digital_asset_item with source_type='filesystem_only'
     d. Detect usage in text fields
     e. Update CSV export fields
```

#### Supported Extensions

Documents: pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv
Images: jpg, jpeg, png, gif, svg, webp
Videos: mp4, webm, mov, avi
Audio: mp3, wav, m4a, ogg
Compressed: zip, tar, gz, 7z, rar

### Phase 3: External URLs

**Method**: `scanContentChunk($offset, $limit, $is_temp)`
**Batch Size**: 25 items per chunk (text processing is heavier)
**Source**: Text and link field tables

#### Purpose

Discovers external resources (Google Docs, YouTube, etc.) referenced in content.

#### Scanned Entity Types

The Digital Asset Inventory scans all primary content entities where files, media, or links may be presented to the public. System configuration, logs, and administrative metadata are intentionally excluded to ensure accurate, actionable compliance reporting.

| Prefix | Entity Type | Examples |
| ------ | ----------- | -------- |
| `node__` | Nodes | Articles, Pages, Events |
| `paragraph__` | Paragraphs | Text blocks, accordions, carousels |
| `taxonomy_term__` | Taxonomy terms | Categories, tags |
| `block_content__` | Custom blocks | Sidebar content, footer blocks |

**Not scanned**: User profiles, comments, system configuration entities, or custom entity types not listed above.

#### Future Enhancement: Menu Link Content

Menu links (`menu_link_content`) can contain direct links to files and documents that are accessible to users without going through node content. This is common for "Forms", "Policies", and "Reports" menu sections.

| Status | Entity Type | Rationale |
| ------ | ----------- | --------- |
| Not yet scanned | `menu_link_content` | Links to PDFs/documents in navigation menus |

**Implementation notes**:

- Only scan link fields (`link`, `uri`)
- Scan stored field values, not rendered output
- Would detect direct file links in menu items

#### Process

```
FOR EACH of the four entity types above:
  1. Find all field tables with that prefix (e.g., node__field_body)
  2. FOR text fields (_value columns):
     - Extract URLs using regex pattern matching
  3. FOR link fields (_uri columns):
     - Read URL directly from field value
  4. FOR EACH extracted URL:
     a. Match URL against configured patterns
     b. IF matches known service (not 'other'):
        - Create/update digital_asset_item with source_type='external'
        - Record usage on parent entity
```

#### URL Pattern Matching

URLs are matched against service-specific patterns:

| Service | Pattern Examples |
| ------- | ---------------- |
| Google Docs | docs.google.com/document |
| Google Sheets | docs.google.com/spreadsheets |
| Google Drive | drive.google.com |
| YouTube | youtube.com, youtu.be |
| Vimeo | vimeo.com |
| Box | box.com |
| Dropbox | dropbox.com |
| Qualtrics | qualtrics.com |
| Canvas | instructure.com |

**Note**: URLs that don't match any known pattern are skipped (not inventoried as 'other').

### Phase 4: Remote Media

**Method**: `scanRemoteMediaChunk($offset, $limit, $is_temp)`
**Batch Size**: 25 items per chunk
**Source**: Media entities with oEmbed source

#### Purpose

Discovers remote video media (YouTube, Vimeo) added via Media Library's "Remote Video" media type.

#### Why Separate Phase?

Remote video media entities:
- Don't have entries in `file_managed` (no physical file)
- Store video URL in source field (not file reference)
- Need dedicated detection logic

#### Process

```
1. Identify media types using oEmbed source plugin (oembed:video, video_embed_field)
2. FOR EACH remote media entity:
   a. Extract video URL from source field
   b. Detect asset type from URL (youtube, vimeo)
   c. Create digital_asset_item:
      - source_type = 'media_managed'
      - filesize = NULL (no local file)
      - file_name = media title
   d. Find usage via:
      - Entity reference fields
      - CKEditor media embeds
   e. Update CSV export fields
```

## Batch Processing Flow

```
┌─────────────────┐
│   User clicks   │
│   "Scan Now"    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Reset stats    │
│  Scan start     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐     ┌─────────────────┐
│ Phase 1:        │     │ Progress:       │
│ Managed Files   │────▶│ "Processing     │
│ (chunks of 50)  │     │  managed files" │
└────────┬────────┘     └─────────────────┘
         │
         ▼
┌─────────────────┐     ┌─────────────────┐
│ Phase 2:        │     │ Progress:       │
│ Orphan Files    │────▶│ "Processing     │
│ (chunks of 50)  │     │  orphan files"  │
└────────┬────────┘     └─────────────────┘
         │
         ▼
┌─────────────────┐     ┌─────────────────┐
│ Phase 3:        │     │ Progress:       │
│ External URLs   │────▶│ "Scanning for   │
│ (chunks of 25)  │     │  external URLs" │
└────────┬────────┘     └─────────────────┘
         │
         ▼
┌─────────────────┐     ┌─────────────────┐
│ Phase 4:        │     │ Progress:       │
│ Remote Media    │────▶│ "Processing     │
│ (chunks of 25)  │     │  remote media"  │
└────────┬────────┘     └─────────────────┘
         │
         ▼
┌─────────────────┐
│   SUCCESS?      │
└────────┬────────┘
         │
    ┌────┴────┐
    ▼         ▼
┌───────┐  ┌───────┐
│  YES  │  │  NO   │
└───┬───┘  └───┬───┘
    │          │
    ▼          ▼
┌─────────┐  ┌─────────┐
│ Promote │  │  Clear  │
│  temp   │  │  temp   │
│  items  │  │  items  │
└────┬────┘  └────┬────┘
     │            │
     ▼            ▼
┌─────────┐  ┌─────────┐
│ Display │  │ Display │
│ success │  │  error  │
│ message │  │ message │
└─────────┘  └─────────┘
```

## Post-Scan Processing

### On Success (`batchFinished` with $success=TRUE)

1. **Promote temporary items** (`promoteTemporaryItems()`)
   - Delete old permanent items and their usage records
   - Mark new temp items as permanent
2. **Store completion timestamp**
3. **Calculate scan duration**
4. **Query actual counts** by source type
5. **Display summary message**:
   - Total assets found
   - Breakdown by source type
   - Usage record count
   - Orphaned paragraph count (if any)

### On Failure/Cancel (`batchFinished` with $success=FALSE)

1. **Clear temporary items** (`clearTemporaryItems()`)
   - Delete all temp items and their usage records
   - Preserve previous inventory intact
2. **Log warning**
3. **Display error message**

## Scan Statistics

After successful scan, the following metrics are available:

| Metric | Source |
| ------ | ------ |
| Total assets | Count of digital_asset_item |
| Local files | source_type IN (file_managed, media_managed) |
| Orphan files | source_type = filesystem_only |
| External URLs | source_type = external |
| Usage records | Count of digital_asset_usage |
| Orphaned paragraphs | Drupal State (orphan_count) |
| Scan duration | Calculated from start/end timestamps |

## Performance Considerations

1. **Chunked processing**: Prevents PHP timeout on large sites
2. **Direct SQL queries**: Faster than entity loading where possible
3. **Cached orphan file list**: Built once, processed in chunks
4. **Skip known patterns**: External URLs matching 'other' are not stored
5. **Deduplication**: URL hash prevents duplicate external asset records

## Related Files

- `src/Service/DigitalAssetScanner.php` - Scanner service implementation
- `src/Form/ScanAssetsForm.php` - Batch form handler
- `config/install/digital_asset_inventory.settings.yml` - Asset type configuration
