# Digital Asset Inventory - Quick Reference Guide

This guide covers scanning, filtering, archiving, and managing digital assets.

## Table of Contents

- [Running a Scan](#running-a-scan)
- [Filtering Options](#filtering-options)
- [CSV Export](#csv-export)
- [Viewing Asset Usage](#viewing-asset-usage)
- [Supported Asset Types](#supported-asset-types)
- [Archiving Documents](#archiving-documents-dual-purpose-archive-system)
- [Permissions](#permissions)
- [Key Routes](#key-routes)
- [Troubleshooting](#troubleshooting)

---

## Disclaimer

The Digital Asset Inventory module is a content governance and asset management tool and is not an accessibility remediation system. Use of this module does not make digital content accessible, does not remediate accessibility issues, and does not bring files, media, or web pages into compliance with WCAG 2.1 AA. The module supports accessibility compliance efforts by helping identify unused assets, manage content lifecycle decisions, and apply consistent archiving practices with appropriate disclosure and access pathways. Responsibility for accessibility testing, remediation, and compliance with applicable accessibility standards remains with content owners and site administrators.

---

## Running a Scan

1. Navigate to `/admin/digital-asset-inventory`
2. Click "Scan Site for Digital Assets"
3. Wait for the batch process to complete
4. View the inventory results

## Filtering Options

- **Category/Type**: Documents, Videos, Audio, Images, Google Workspace,
  Document Services, Forms & Surveys, Education Platforms, Embedded Media,
  Other, or specific asset types (PDF, Word, etc.)
- **Source Type**: Local Files, Media Files, Manual Uploads, External
- **File Storage**: Public Files Only, Private Files Only (files requiring authentication)
- **In Use**: Filter by whether assets are used on the site
- **Archive Status**: Not Archived, Queued, Archived (any/Public/Admin-Only) - only visible when archiving is enabled. Badges display for active statuses only (Queued, Archived Public, Archived Admin-Only). Terminal states (Exemption Void, Archived Deleted) show no badge since files can be re-archived.

## CSV Export

Export the full digital asset inventory:

1. Navigate to `/admin/digital-asset-inventory`
2. Click "Export Asset Inventory (CSV)" button

The export includes all scanned assets with columns:

- File Name, File URL, Asset Type, Category
- MIME Type, Source, File Size, Active Use Detected, Used In

## Viewing Asset Usage

To see where an asset is used across the site:

1. Navigate to `/admin/digital-asset-inventory`
2. Click on the number in the "Active Usage" column for any asset

The usage detail page shows:

### Header Info

Displays asset metadata in a bordered info box:

- **Asset name**: For media files, shows media title with filename in parentheses
  (e.g., "Annual Report (annual-report-2025.pdf)")
- **Type**: Asset type (PDF, Word, Excel, JPG, etc.)
- **Size**: File size in human-readable format (KB, MB)
- **Source**: How the file was added to Drupal
  - Local File: Uploaded through file fields
  - Media File: Uploaded through Media Library
  - Manual Upload: Added via FTP/SFTP outside Drupal
  - External: External URL (Google Docs, YouTube, etc.)
- **File access**:
  - Public (Accessible to anyone without logging in)
  - Private (Accessible only to logged-in or authorized users)
- **URL**: Clickable link to the file

### Usage Table

Shows where the asset is referenced:

| Column         | Description                                                  |
| -------------- | ------------------------------------------------------------ |
| Used On        | Links to the content where the asset is used                 |
| Item Type      | The entity type (Node, Media, Block, etc.)                   |
| Item Category  | The content type or bundle (Page, Article, etc.)             |
| Section        | The field label where the asset appears (e.g., "Hero Image") |
| Required Field | Whether the field is required on that content type           |

### Embed Type Column

The usage table shows an "Embed Type" column for all asset types, indicating how the asset is embedded in content:

| Embed Type      | Description                                           |
| --------------- | ----------------------------------------------------- |
| Media Embed     | Embedded via CKEditor media button (`<drupal-media>`) |
| Field Reference | Referenced via media or file field                    |
| HTML5 Video     | Raw `<video>` tag in content                          |
| HTML5 Audio     | Raw `<audio>` tag in content                          |
| Text Link       | Hyperlink (`<a href>`) to the file                    |
| Inline Image    | Inline `<img>` tag in text content                    |
| Object Embed    | Legacy `<object>` tag in text content                 |
| Embed Element   | Legacy `<embed>` tag in text content                  |
| Text URL        | External URL found in text content                    |
| Link Field      | URL from a Drupal Link field                          |
| Menu Link       | Link in a menu                                        |

### Video/Audio Accessibility Signal Columns

For video and audio assets, additional columns show accessibility signals:

| Column     | Values                  | Description                           |
| ---------- | ----------------------- | ------------------------------------- |
| Controls   | Yes / No / Unknown      | Whether playback controls are present |
| Captions   | Yes / No / Unknown      | Whether captions/subtitles are present|
| Transcript | Yes / No / Unknown      | Whether a transcript link is nearby   |

**Note:** Signal detection depends on embed type. HTML5 embeds can be analyzed directly; Media Library embeds may show "Unknown" for some signals.

## Supported Asset Types

The following asset types are recognized and categorized by the scanner.
Any file format not listed below is categorized as "Other".

### Local Files (by MIME type)

| Category | Asset Type | Label | Extensions |
| -------- | ---------- | ----- | ---------- |
| **Documents** | pdf | PDFs | .pdf |
| | word | Word Documents | .doc, .docx |
| | excel | Excel Spreadsheets | .xls, .xlsx |
| | powerpoint | PowerPoint Presentations | .ppt, .pptx |
| | text | Text Files | .txt |
| | csv | CSV Files | .csv |
| **Images** | jpg | JPEG Images | .jpg, .jpeg |
| | png | PNG Images | .png |
| | gif | GIF Images | .gif |
| | svg | SVG Images | .svg |
| | webp | WebP Images | .webp |
| **Videos** | mp4 | MP4 Videos | .mp4 |
| | webm | WebM Videos | .webm |
| | mov | QuickTime Videos | .mov |
| | avi | AVI Videos | .avi |
| **Audio** | mp3 | MP3 Audio | .mp3 |
| | wav | WAV Audio | .wav |
| | m4a | M4A Audio | .m4a |
| | ogg | OGG Audio | .ogg |
| **Other** | compressed | Compressed Files | .zip, .tar, .gz, .7z, .rar |
| | other | Other Files | (all other formats) |

### External URLs (by URL pattern)

| Category | Asset Type | Label | URL Patterns |
| -------- | ---------- | ----- | ------------ |
| **Google Workspace** | google_doc | Google Docs | docs.google.com/document/ |
| | google_sheet | Google Sheets | docs.google.com/spreadsheets/ |
| | google_slide | Google Slides | docs.google.com/presentation/ |
| | google_drive | Google Drive Files | drive.google.com/file/, drive.google.com/open |
| | google_form | Google Forms | docs.google.com/forms/ |
| | google_site | Google Sites | sites.google.com/ |
| **Document Services** | docusign | DocuSign Documents | docusign.com/, docusign.net/ |
| | box_link | Box Documents | app.box.com, box.com |
| | dropbox | Dropbox Files | dropbox.com/, dl.dropboxusercontent.com/ |
| | onedrive | OneDrive Files | onedrive.live.com/, 1drv.ms/ |
| | sharepoint | SharePoint Documents | sharepoint.com/, sharepoint.us/ |
| | adobe_acrobat | Adobe Acrobat Documents | acrobat.adobe.com/, documentcloud.adobe.com/ |
| **Forms & Surveys** | qualtrics | Qualtrics Forms | qualtrics.com/ |
| | microsoft_forms | Microsoft Forms | forms.office.com/, forms.microsoft.com/ |
| | surveymonkey | SurveyMonkey | surveymonkey.com/ |
| | typeform | Typeform | typeform.com/ |
| **Education Platforms** | canvas | Canvas LMS | instructure.com/ |
| | panopto | Panopto Videos | panopto.com/ |
| | kaltura | Kaltura/MediaSpace | kaltura.com/, mediaspace.* |
| **Embedded Media** | youtube | YouTube Videos | youtube.com/watch, youtu.be/, youtube.com/embed/ |
| | vimeo | Vimeo Videos | vimeo.com/, player.vimeo.com/ |
| | zoom_recording | Zoom Recordings | zoom.us/rec/, zoom.us/recording/ |
| | slideshare | SlideShare | slideshare.net/ |
| | prezi | Prezi Presentations | prezi.com/ |
| | issuu | Issuu Publications | issuu.com/ |
| | canva | Canva Designs | canva.com/ |

---

## Archiving Documents (Dual-Purpose Archive System)

> **Note:** Archive functionality must be enabled before use. Go to
> `/admin/config/accessibility/digital-asset-inventory` and enable
> "Archive Functionality" in the settings.

The archive system supports both **accessibility-related legacy archiving**
and **general archival preservation**:

### Archive Types

| Type | When Created | Purpose | ADA Exemption |
|------|--------------|---------|---------------|
| **Legacy Archive** | Before deadline | ADA Title II compliance | Yes (if unmodified) |
| **General Archive** | After deadline | Reference/recordkeeping | No |

- **Legacy Archives**: Created before the ADA compliance deadline (default: April 24, 2026).
  Under ADA Title II (April 2024), archived content is exempt from WCAG 2.1 AA
  requirements if kept for Reference, Research, or Recordkeeping purposes.
- **General Archives**: Created after the deadline. Retained for reference purposes
  without claiming ADA accessibility exemption. If modified, removed from public
  view and flagged for audit.

### Two-Step Archive Process

#### Step 1: Queue for Archive

1. Navigate to `/admin/digital-asset-inventory`
2. Click "Queue for Archive" next to a document
3. Select an archive reason and provide a public description
4. Click "Queue for Archive"

#### Step 2: Execute Archive

1. Navigate to `/admin/digital-asset-inventory/archive`
2. Click "Archive Asset" for a queued document
3. Choose visibility: Public or Admin-only
4. Click "Archive Now"

### Archiving Documents Still In Use

By default, documents must be removed from all content before archiving. However, if "Allow Archive In Use" is enabled in settings:

- Documents and videos can be archived while still referenced by content
- A confirmation checkbox is required acknowledging the document is in use
- Links throughout the site automatically route to the Archive Detail Page
- The archive record tracks that it was "archived while in use" for audit purposes

**Note:** This feature only applies to documents and videos, not images.

### Archive Link Routing

When a document or video is archived, links to that file are automatically updated throughout the site:

- Links point to the Archive Detail Page instead of the direct file URL
- Link text shows "(Archived)" label so visitors know before clicking
- This applies to links in content, menus, media embeds, and custom templates

**What gets routed:**

- Text links to documents and videos
- Menu links to files
- Media embeds in CKEditor content
- External URLs archived via manual archive entry (with normalized URL matching)

**What is NOT routed:**

- Images (would break page layouts)
- Audio files
- Compressed files (ZIP, etc.)
- Archive Registry pages (listing and detail pages)

When an archive is removed (status becomes Archived Deleted), links revert to direct file URLs.

### Configurable Archived Link Label

Administrators can customize or disable the "(Archived)" label that appears on links to archived content.

**Settings location:** `/admin/config/accessibility/digital-asset-inventory`

| Setting | Description |
|---------|-------------|
| Show archived label on links | Enable/disable the label on all archived links |
| Archived label text | Customize the label text (default: "Archived") |

**Note:** Parentheses are added automatically around the label text. Enter just the word (e.g., "Archived" not "(Archived)").

When the label is disabled, links still route to the Archive Detail Page but no visible indicator is shown. Image links still receive a `title` attribute for accessibility.

### External URL Matching

External URLs (archived via manual archive entry) use normalized URL comparison for matching:

- Scheme and host are lowercased (`HTTPS://Example.Com` → `https://example.com`)
- Default ports are removed (`:80` for http, `:443` for https)
- Trailing slashes are removed (`/page/` → `/page`)
- Query strings are preserved (different parameters = different resources)
- Fragment identifiers are ignored (client-side only)

This ensures that links match correctly even if the URL format varies slightly in content.

#### Understanding Archive Dates

The system tracks two important dates:

- **Archive Record Created Date** - When the archive record was created (Step 1: Queue). Represents the *intent* to archive.
- **Archive Classification Date** - When the archive was executed (Step 2: Execute). Represents the *formal compliance decision*. This date is immutable and determines Legacy vs General Archive.

Items can sit in "queued" status while waiting for content references to be removed. The Classification Date (not Created Date) determines archive type for compliance purposes.

### Archive Status Values

| Status              | Description                                        |
| ------------------- | -------------------------------------------------- |
| Queued              | Awaiting archive execution (file-based only)       |
| Archived (Public)   | Visible in public Archive Registry at `/archive-registry`   |
| Archived (Admin-Only)    | Archived but only visible to administrators        |
| Archived (Deleted)  | Terminal state: file deleted, entry removed, unarchived, or General Archive modified |
| Exemption Void      | Terminal state: Legacy Archive modified after archiving; ADA exemption voided |

**Note:** Both `Archived (Deleted)` and `Exemption Void` are terminal states with no available operations. Records are preserved for audit trail purposes.

### Archive Operations

| Action | Description | Available For |
|--------|-------------|---------------|
| Archive Asset | Execute archive with visibility choice | Queued |
| Remove from Queue | Cancel pending archive | Queued |
| Make Admin-only / Make Public | Toggle between Public and Admin-only | Archived (Public/Admin) |
| Edit Entry | Update title, description, notes | Archived (Public/Admin) - manual only |
| Unarchive | Remove from registry (sets status to Archived Deleted) | Archived (Public/Admin) |
| Delete File | Delete physical file, preserve archive record | Archived (Public/Admin) - file-based only |
| Remove Entry | Remove manual entry from registry | Archived (Public/Admin) - manual only |

**Terminal states (no operations):** Archived (Deleted), Exemption Void

### Warning Flags

Flags indicate problems but don't change status. "Yes = problem".

| Flag | Meaning | Resolution |
|------|---------|------------|
| Usage Detected | Content references document | Remove references, re-scan |
| File Deleted | File was intentionally deleted | No action (audit record) |
| Integrity Issue | Checksum mismatch (files) | Investigate modification |
| Modified | Content modified (manual entries) | Advisory for audit |
| Late Archive | Archived after ADA deadline | Advisory only, determines archive type |
| Prior Exemption Voided | Forced to General Archive due to prior voided exemption | Advisory only |

### Archive Filtering Options

- **Archive Type**: Legacy Archive (Pre-deadline), General Archive (Post-deadline)
- **Status**: Queued, Archived (Public), Archived (Admin-Only),
  Archived (Deleted), Exemption Void
- **Asset Type**: Documents, Videos, Web Pages, External Resources
- **Purpose**: Reference, Research, Recordkeeping, Other

### Exemption Void (Automatic)

When a **Legacy Archive** (pre-deadline) is modified after archiving,
the system automatically voids its accessibility exemption:

1. The integrity check detects the file's checksum has changed (file-based)
   or content was edited (manual entries)
2. Status changes from "Archived (Public/Admin)" to "Exemption Void"
3. The document is removed from the public Archive Registry
4. An "Exemption Voided" badge appears in Archive Management

**Important:** `Exemption Void` is a permanent terminal state with no available
operations. The record is preserved as compliance documentation that an ADA
exemption violation occurred.

**Re-archive policy:** Files/URLs with voided exemptions can be archived again,
but any new entry is automatically classified as **General Archive** regardless
of the current date. The voided record remains as the immutable audit trail.

### ADA Compliance Deadline (Configurable)

The ADA compliance deadline can be configured at:
`/admin/config/accessibility/digital-asset-inventory`

- Default: April 24, 2026
- Used to determine: Late Archive flag, Exemption Void status
- Documents archived before this date are exempt from WCAG 2.1 AA
- Documents modified after this date lose their exemption

### Archive Feature Toggle

Archive functionality can be enabled or disabled at:
`/admin/config/accessibility/digital-asset-inventory`

**When disabled:**

- Archive-related routes return 403 Forbidden
- "Archive Management" menu link is hidden
- "Queue for Archive" buttons don't appear in inventory
- Existing archive records are preserved (not deleted)

This supports phased rollout where users start with inventory-only and
enable Archive when ready for compliance requirements.

### Allow Archive In Use Setting

This setting controls whether documents can be archived while still referenced by content:
`/admin/config/accessibility/digital-asset-inventory`

**When enabled:**

- Documents and videos can be archived while still in use
- Links are automatically routed to the Archive Detail Page
- Archive records track "archived while in use" for audit

**When disabled (default):**

- Documents must have no active references before archiving
- Users must remove links, run a scan, then archive

### Manual Archive Entries

In addition to archiving files through the inventory, you can manually
add web pages and external resources to the Archive Registry.

1. Navigate to `/admin/digital-asset-inventory/archive`
2. Click "Add Manual Archive Entry"
3. Enter the title, URL, and archive reason
4. Choose visibility (Public or Admin-only)
5. Click "Add to Archive Registry"

**Supported content types:**

- **Web Page**: Internal pages on this website (nodes, taxonomy terms, or any page with a path alias)
- **External Resource**: Documents or pages hosted elsewhere

**Page URL autocomplete:** Start typing a page title, taxonomy term name, or path alias to search.

**URL validation:**

- Internal paths are resolved to absolute URLs
- File URLs (PDFs, documents, videos) are blocked - use the inventory instead
- Media entity paths are blocked - use the inventory instead
- Duplicate URLs are detected and prevented

**Removing manual entries:**

When a manual archive entry is removed from the Archive Registry, the record is
preserved with `archived_deleted` status for audit trail purposes. This matches
the behavior of file-based archives and ensures compliance audit trails remain
intact.

### Archived Content Banner

When an internal page (node, taxonomy term, etc.) is archived via manual archive entry, an
"Archived Material" banner automatically appears at the top of the page.

**Banner message:**

> **Archived Material**
> This material is retained for reference, research, or recordkeeping
> purposes. It is no longer updated and may not reflect current
> information, services, or policies.

The banner helps visitors understand that the content is preserved for
historical or compliance purposes and may not be current.

### Edit Protection for Archived Content

When editing content that has an active archive record, safeguards
prevent accidental loss of the archive exemption.

**Warning messages vary by archive type:**

| Archive Type | Warning |
|--------------|---------|
| Legacy Archive | "This content is currently recorded as archived for ADA Title II purposes. If you save changes, it will no longer qualify as archived/exempt." |
| General Archive | "This content is currently archived. If you save changes, it will be flagged as modified in the Archive Registry for audit tracking purposes." |

Users must check an acknowledgment box before saving. When saved:

- **Legacy Archives**: Status changes to "Exemption Void"
- **General Archives**: Status changes to "Archived (Deleted)" with Modified flag

### Private File Handling

Files stored in Drupal's private file system (`private://`) are automatically
detected during scanning. On the public Archive Registry detail pages:

- Anonymous users see a login prompt with a link to authenticate
- After logging in, users are redirected back to the archive detail page
- CAS (Central Authentication Service) login is used if available

### Archive Audit CSV Export

Export archive records for compliance audits:

1. Navigate to `/admin/digital-asset-inventory/archive`
2. Click "Export Archive Audit (CSV)" button

The export includes all archive records with audit-ready columns:

- Archive ID (UUID), Name, Asset Type, Archive Type
- Archive Classification Date, Current Status
- Archived By, Deleted Date, Deleted By
- Archive Reason, Public Description
- File Checksum (SHA-256)
- All warning flags with descriptive values
- Original URL, Archive Reference Path

All dates use ISO 8601 format (e.g., `2025-12-29T10:22:41-08:00`).

---

## Permissions

| Permission                     | Description                            |
| ------------------------------ | -------------------------------------- |
| `administer digital assets`    | Full access to settings and management |
| `view digital asset inventory` | View inventory page                    |
| `scan digital assets`          | Run the asset scanner                  |
| `delete digital assets`        | Delete individual assets               |
| `archive digital assets`       | Manage archived documents              |

### Digital Asset Manager Role (Optional)

The module provides an optional `digital_asset_manager` role in
`config/optional/`. This role has permissions for viewing, scanning,
deleting, and archiving digital assets without full admin access.

## Key Routes

| Path                                                  | Purpose                    |
| ----------------------------------------------------- | -------------------------- |
| `/admin/digital-asset-inventory`                      | Main inventory             |
| `/admin/digital-asset-inventory/scan`                 | Scan form                  |
| `/admin/digital-asset-inventory/csv`                  | Download inventory report  |
| `/admin/digital-asset-inventory/archive`              | Archive management         |
| `/admin/digital-asset-inventory/archive/csv`          | Archive audit CSV export   |
| `/admin/config/accessibility/digital-asset-inventory` | Module settings            |
| `/archive-registry`                                   | Public Archive Registry    |
| `/archive-registry/{id}`                              | Public archive detail page |

## Troubleshooting

### Archive blocked by usage

If archiving fails with "Usage Detected", the document is still referenced
in content. Remove all references, run a new scan, then try archiving again.

### Large file checksum pending

Files over 50MB have checksums calculated via cron to avoid timeouts.
Run `drush cron` or wait for the next cron run.

### Missing file after archive

If a file was deleted outside the module, the archive record shows the deletion
in the Archive Management page. Records are preserved for audit trail purposes
and cannot be removed.

### Exemption Void badge in inventory

If a document shows "Exemption Void" badge in the inventory, it means a
Legacy Archive (pre-deadline) was modified after being archived. The record
is preserved as compliance documentation that an ADA exemption violation occurred.

The file can be archived again, but any new entry will automatically be
classified as **General Archive** regardless of the current date. Files/URLs
with voided exemptions permanently lose Legacy Archive eligibility.
