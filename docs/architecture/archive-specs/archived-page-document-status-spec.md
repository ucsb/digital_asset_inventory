# Archived Page Document Status Specification

## Overview

When a page is archived via manual archive entry, the archived content banner displays contextual notes about the status of documents linked from that page. This helps visitors understand whether referenced materials are also archived or remain active.

## Requirements

### REQ-001: Baseline Banner (Always Shown)

**Type:** Ubiquitous
**Statement:** The system shall display the standard "Archived Material" banner on all archived pages.

**Banner Content:**
```
Archived Material

This material is retained for reference, research, or recordkeeping
purposes. It is no longer updated and may not reflect current
information, services, or policies.

For an accessible or alternative format, please contact the
accessibility resources on this website. Requests will be fulfilled
within a reasonable timeframe in accordance with applicable
accessibility standards.
```

### REQ-002: Document Status Detection

**Type:** Event-driven
**Statement:** When an archived page is displayed, the system shall scan the page content for document and video references and determine their archive status.

**Document Types Detected:**
- PDF, Word, Excel, PowerPoint
- Text files (txt, csv, rtf)
- OpenDocument formats (odt, ods, odp)
- Video files (mp4, webm, mov, avi)

**Scan Locations:**
- File fields
- Media reference fields (document/video media)
- Link fields
- Text fields (href/src attributes)
- Drupal media embeds (`<drupal-media>` tags)
- Archive registry links (`/archive-registry/{id}`)
- Paragraph entities (recursive scan)

### REQ-003: Active Documents Note

**Type:** State-driven
**Statement:** When an archived page contains only active (non-archived) documents, the system shall append the "Related Active Documents" note as a paragraph within the banner.

**Conditions:**
- Page is archived (manual archive entry)
- One or more documents are linked
- ALL linked documents are active (not archived)

**Note Content:**
```
Related Active Documents: Some documents referenced on this archived
page remain active and are not archived. These materials may continue
to be updated and maintained separately from this page.
```

### REQ-004: Archived Documents Note

**Type:** State-driven
**Statement:** When an archived page contains only archived documents, the system shall append the "Archived Supporting Materials" note as a paragraph within the banner.

**Conditions:**
- Page is archived (manual archive entry)
- One or more documents are linked
- ALL linked documents are archived

**Note Content:**
```
Archived Supporting Materials: All documents associated with this page
are also archived and are provided for reference or recordkeeping
purposes only.
```

### REQ-005: Mixed Documents Note

**Type:** State-driven
**Statement:** When an archived page contains both active and archived documents, the system shall append the "Mixed Content Status" note as a paragraph within the banner.

**Conditions:**
- Page is archived (manual archive entry)
- Two or more documents are linked
- SOME documents are archived AND SOME are active

**Note Content:**
```
Mixed Content Status: This archived page references a combination of
archived and active documents. Archived documents are retained for
reference or recordkeeping purposes. Active documents remain publicly
available and may continue to be updated independently of this page.
```

## Decision Matrix

| Scenario | Documents Found | Archived | Active | Note Displayed |
|----------|-----------------|----------|--------|----------------|
| No documents | 0 | 0 | 0 | None (baseline only) |
| All active | 3 | 0 | 3 | Related Active Documents |
| All archived | 3 | 3 | 0 | Archived Supporting Materials |
| Mixed | 5 | 2 | 3 | Mixed Content Status |

## Implementation

### Helper Functions

```php
_digital_asset_inventory_get_entity_document_status($entity)
```
Scans entity for document references and returns count of archived vs active, plus cache tags for linked documents.

```php
_digital_asset_inventory_extract_document_info($entity, $depth = 0)
```
Extracts document info (fid, uri, archive_id) from file fields, media references, link fields, text fields, and paragraph entities. Recursively scans paragraph fields up to 5 levels deep.

```php
_digital_asset_inventory_build_document_status_note($status)
```
Builds the appropriate contextual note HTML (plain `<p>` tag) based on document status.

### Integration Point

The logic is integrated into `hook_entity_view()` and executes only for:
- Manual archive entries (`$archive->isManualEntry()`)
- Full view modes (not teasers, search results, etc.)

### Cache Considerations

The banner includes cache tags for:
- `digital_asset_archive_list` - Invalidated when any archive changes
- `digital_asset_archive:{id}` - Invalidated when specific archive changes (page and linked documents)
- `file:{fid}` - Invalidated when active files are archived

When document archive status changes, the page cache is automatically invalidated via these tags.

## Styling

The contextual note displays as a plain paragraph within the banner container, using the same styling as other banner content.

CSS class:
- `.dai-archived-content-banner` - Main banner container

## Accessibility

- Banner uses `role="alert"` and `aria-live="polite"`
- Semantic heading: `<h2>` for main banner title
- Contextual notes use plain paragraph text (no separate heading)
- Sufficient color contrast for all text
- No information conveyed by color alone

## Test Cases

### TC-DPS-001: No Documents

1. Archive a page with no document links
2. View the archived page

**Expected:** Only baseline banner displayed, no contextual note

### TC-DPS-002: All Active Documents

1. Create page with 2 PDF links (not archived)
2. Archive the page via manual entry
3. View the archived page

**Expected:** Baseline banner with "Related Active Documents:" paragraph

### TC-DPS-003: All Archived Documents

1. Create page with 2 PDF links
2. Archive both PDFs via inventory
3. Archive the page via manual entry
4. View the archived page

**Expected:** Baseline banner with "Archived Supporting Materials:" paragraph

### TC-DPS-004: Mixed Documents

1. Create page with 3 PDF links
2. Archive 1 PDF, leave 2 active
3. Archive the page via manual entry
4. View the archived page

**Expected:** Baseline banner with "Mixed Content Status:" paragraph

### TC-DPS-005: Cache Invalidation

1. Archive a page with 1 active PDF
2. Note displays "Related Active Documents"
3. Archive the PDF via inventory
4. Refresh the archived page

**Expected:** Note changes to "Archived Supporting Materials"

### TC-DPS-006: Paragraph Content Detection

1. Create page with paragraph fields containing document links
2. Archive the documents via inventory
3. Archive the page via manual entry
4. View the archived page

**Expected:** Documents in paragraphs are detected, "Archived Supporting Materials:" displays

### TC-DPS-007: Archive Registry Link Detection

1. Create page with links to `/archive-registry/{id}` (already-archived documents)
2. Archive the page via manual entry
3. View the archived page

**Expected:** Archive registry links are detected as archived documents
