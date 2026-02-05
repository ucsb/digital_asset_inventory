# Archived Page Document Status Specification

## Overview

When a page is archived via manual archive entry, the archived content banner displays contextual notes about the status of documents and external resources linked from that page. This helps visitors understand whether referenced materials are also archived or remain active.

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

### REQ-002: Document and External Resource Status Detection

**Type:** Event-driven
**Statement:** When an archived page is displayed, the system shall scan the page content for document, video, and external resource references and determine their archive status.

**Document Types Detected:**
- PDF, Word, Excel, PowerPoint
- Text files (txt, csv, rtf)
- OpenDocument formats (odt, ods, odp)
- Video files (mp4, webm, mov, avi)

**External Resource Types Detected:**
- Google Workspace (Docs, Sheets, Slides, Drive, Forms, Sites)
- Document services (Box, Dropbox, OneDrive, SharePoint, DocuSign, Adobe Acrobat)
- Forms & Surveys (Qualtrics, Microsoft Forms, SurveyMonkey, Typeform)
- Education platforms (Canvas, Panopto, Kaltura)
- Embedded media (YouTube, Vimeo, Zoom recordings, SlideShare, Prezi, Issuu, Canva)

**Scan Locations:**
- File fields
- Media reference fields (document/video media)
- Link fields (both files and external URLs)
- Text fields (href/src attributes)
- Drupal media embeds (`<drupal-media>` tags)
- Archive registry links (`/archive-registry/{id}`)
- Paragraph entities (recursive scan)

### REQ-003: Active Materials Note

**Type:** State-driven
**Statement:** When an archived page contains only active (non-archived) materials, the system shall append the "Related Active Materials" note as a paragraph within the banner.

**Conditions:**
- Page is archived (manual archive entry)
- One or more materials are linked (documents or external resources)
- ALL linked materials are active (not archived)

**Note Content:**
```
Related Active Materials: Some materials referenced on this archived
page remain active and may continue to be updated.
```

### REQ-004: Archived Materials Note

**Type:** State-driven
**Statement:** When an archived page contains only archived materials, the system shall append the "Archived Supporting Materials" note as a paragraph within the banner.

**Conditions:**
- Page is archived (manual archive entry)
- One or more materials are linked (documents or external resources)
- ALL linked materials are archived

**Note Content:**
```
Archived Supporting Materials: All supporting materials linked from
this page are archived and provided for reference purposes only.
```

### REQ-005: Mixed Status Note

**Type:** State-driven
**Statement:** When an archived page contains both active and archived materials, the system shall append the "Mixed Content Status" note as a paragraph within the banner.

**Conditions:**
- Page is archived (manual archive entry)
- Two or more materials are linked (documents or external resources)
- SOME materials are archived AND SOME are active

**Note Content:**
```
Mixed Content Status: This archived page references both archived and
active materials. Archived materials are retained for reference
purposes. Active materials may continue to be updated.
```

## Decision Matrix

The system tracks both documents and external resources, each with their own archived/active counts. The combined totals determine which note is displayed.

| Docs Archived | Docs Active | Ext Archived | Ext Active | Total Archived | Total Active | Note Displayed |
|---------------|-------------|--------------|------------|----------------|--------------|----------------|
| 0 | 0 | 0 | 0 | 0 | 0 | None (baseline only) |
| 0 | 3 | 0 | 0 | 0 | 3 | Related Active Materials |
| 0 | 0 | 0 | 2 | 0 | 2 | Related Active Materials |
| 0 | 2 | 0 | 1 | 0 | 3 | Related Active Materials |
| 3 | 0 | 0 | 0 | 3 | 0 | Archived Supporting Materials |
| 0 | 0 | 2 | 0 | 2 | 0 | Archived Supporting Materials |
| 2 | 0 | 1 | 0 | 3 | 0 | Archived Supporting Materials |
| 2 | 1 | 0 | 0 | 2 | 1 | Mixed Content Status |
| 0 | 0 | 1 | 1 | 1 | 1 | Mixed Content Status |
| 1 | 1 | 1 | 1 | 2 | 2 | Mixed Content Status |
| 2 | 0 | 0 | 1 | 2 | 1 | Mixed Content Status |
| 0 | 2 | 1 | 0 | 1 | 2 | Mixed Content Status |

## Implementation

### Helper Functions

```php
_digital_asset_inventory_get_entity_document_status($entity)
```
Scans entity for document and external resource references. Returns:
- `docs`: Array with 'total', 'archived', 'active' counts for documents
- `external`: Array with 'total', 'archived', 'active' counts for external resources
- `cache_tags`: Array of cache tags for invalidation

```php
_digital_asset_inventory_extract_document_info($entity, $depth = 0)
```
Extracts resource info from file fields, media references, link fields, text fields, and paragraph entities. Returns arrays with:
- `fid` and `uri` for documents
- `external_url` for external resources
- `archive_id` for direct archive registry links

Recursively scans paragraph fields up to 5 levels deep.

```php
_digital_asset_inventory_build_document_status_note(array $docs, array $external)
```
Builds the appropriate contextual note HTML (plain `<p>` tag) using 3 generic messages:
1. **All Active**: "Related Active Materials: Some materials referenced..."
2. **All Archived**: "Archived Supporting Materials: All supporting materials..."
3. **Mixed**: "Mixed Content Status: This archived page references both..."

The function combines document and external resource counts to determine which message to display.

```php
_digital_asset_inventory_get_external_url_patterns()
```
Gets the configured external URL patterns from settings. Used to identify external resources.

```php
_digital_asset_inventory_is_external_resource_url($url, $patterns)
```
Checks if a URL matches any configured external resource patterns.

```php
_digital_asset_inventory_get_archive_for_external_url($url, $archive_service, $archive_storage)
```
Gets an archive record for an external URL using normalized URL comparison.

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

### TC-DPS-001: No Resources

1. Archive a page with no document or external resource links
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

**Expected:** Baseline banner with "Archived Supporting Materials: All documents..." paragraph

### TC-DPS-004: Mixed Documents

1. Create page with 3 PDF links
2. Archive 1 PDF, leave 2 active
3. Archive the page via manual entry
4. View the archived page

**Expected:** Baseline banner with "Mixed Content Status:" paragraph (documents version)

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

### TC-DPS-008: Non-Archived External Resources (Ignored)

1. Create page with 2 Google Docs links (not archived in our system)
2. Archive the page via manual entry
3. View the archived page

**Expected:** No contextual note for external resources (they're ignored if not archived). Only baseline banner displayed.

### TC-DPS-009: All Archived External Resources

1. Create page with 2 Google Docs links
2. Archive both external URLs via manual archive (asset_type='external')
3. Archive the page via manual entry
4. View the archived page

**Expected:** Baseline banner with "Archived Supporting Materials: All supporting materials linked from this page are external resources..." paragraph

### TC-DPS-010: Mixed Types - All Archived

1. Create page with 2 PDF links and 2 Google Docs links
2. Archive both PDFs via inventory
3. Archive both external URLs via manual archive
4. Archive the page via manual entry
5. View the archived page

**Expected:** Baseline banner with "Archived Supporting Materials: Supporting materials linked from this page may include archived site documents and external resources..." paragraph

### TC-DPS-011: Mixed Types - Docs Mixed + External Archived

1. Create page with 3 PDF links and 2 Google Docs links
2. Archive 1 PDF via inventory (leave 2 active)
3. Archive both Google Docs links via manual archive
4. Archive the page via manual entry
5. View the archived page

**Expected:** Baseline banner with "Mixed Content Status: ...including documents and external resources..." paragraph

### TC-DPS-012: External URL Normalization

1. Create manual archive for `https://docs.google.com/document/d/abc123/`
2. Create page with link to same URL without trailing slash: `https://docs.google.com/document/d/abc123`
3. Archive the page via manual entry
4. View the archived page

**Expected:** URL is matched via normalization, shows as archived in contextual note
