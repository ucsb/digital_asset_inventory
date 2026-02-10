# Digital Asset Inventory - Test Cases

## Setup

```bash
drush en digital_asset_inventory -y
drush cr
```

Required permissions:

- `administer digital assets`
- `scan digital assets`
- `delete digital assets`
- `archive digital assets`

**Note on file paths:** Test cases use `/sites/default/files/` as the example public files path (the Drupal default). The module uses the `FilePathResolver` trait with the principle: *discover and parse using universal path anchors (`sites/[^/]+/files`, `/system/files/`); generate using site-aware services (`getPublicFilesBasePath()`)*. This supports multisite (`sites/{sitename}/files/`) and Site Factory environments. For multisite testing, see TC-SCAN-MULTI below.

---

## Asset Scanning

### TC-SCAN-001: Basic Scan

1. Navigate to `/admin/digital-asset-inventory`
2. Click "Scan Site for Digital Assets"
3. Click "Scan Now"

**Expected**: Assets discovered and displayed in inventory table.

### TC-SCAN-002: Source Types

Upload files via different methods and verify source type detection:

| Method                  | Expected Source Type |
| ----------------------- | -------------------- |
| File field on node      | Local File           |
| Media Library           | Media File           |
| FTP/SFTP upload         | Manual Upload        |
| External URL in content | External             |

### TC-SCAN-003: External URLs

Add these URLs to content body, scan, and verify detection:

- `https://docs.google.com/document/d/xxx` → google_doc
- `https://www.youtube.com/watch?v=xxx` → youtube
- `https://drive.google.com/file/d/xxx` → google_drive

### TC-SCAN-005: Remote Video Media (Media Library)

1. Add a YouTube video via Media Library (Remote Video media type)
2. Embed the remote video in a content node
3. Run a scan

**Expected**:
- Remote video appears in inventory
- Source Type: "Media File"
- Asset Type: "youtube" or "vimeo" (detected from URL)
- Category: "Embedded Media"
- File Size: "-" (dash, not "0 bytes")
- Media name shown as file name

### TC-SCAN-006: Remote Video Usage Tracking

1. Add a YouTube video via Media Library
2. Use the same video in multiple content nodes
3. Run a scan

**Expected**:
- Single asset entry in inventory
- Usage count reflects all content nodes where video is used
- Click "Used In" shows all usage locations

### TC-SCAN-004: Scan Failure Preserves Data

1. Run a successful scan and verify assets are displayed with usage counts
2. Note the current asset count and usage records
3. Temporarily add code to force scan failure (throw exception after processing 1 item)
4. Run scan again - it should fail with error
5. Navigate to inventory

**Expected**: Previous inventory data is preserved (same assets and usage counts as step 2). Scan failure should not wipe out existing data.

### TC-SCAN-007: Asset Category Mapping

Verify these external URLs are categorized correctly:

| URL Pattern | Expected Category |
| ----------- | ----------------- |
| `qualtrics.com/` | Forms & Surveys |
| `forms.office.com/` | Forms & Surveys |
| `surveymonkey.com/` | Forms & Surveys |
| `typeform.com/` | Forms & Surveys |
| `youtube.com/` | Embedded Media |
| `vimeo.com/` | Embedded Media |
| `canva.com/` | Embedded Media |
| `slideshare.net/` | Embedded Media |
| `prezi.com/` | Embedded Media |
| `box.com/` | Document Services |
| `dropbox.com/` | Document Services |

### TC-SCAN-008: File Size Display

1. Run a scan with various asset types
2. View inventory

**Expected**:
- Local files show formatted size (e.g., "2.5 MB", "156 KB")
- External URLs show "-" (dash)
- Remote video media (YouTube, Vimeo via Media Library) show "-" (dash)

### TC-SCAN-009: Menu Link File References

1. Create a menu link pointing to a file (internal URI format: `internal:/sites/default/files/doc.pdf`)
2. Run a scan

**Expected**:
- File appears in inventory with usage count > 0
- Click "Used In" shows menu link as usage source
- Menu name appears in the Item Category column
- Field name shows "Menu Link"

### TC-SCAN-010: Menu Link Usage - Full URL Format

1. Create a menu link with full URL pointing to local file (e.g., `https://example.com/sites/default/files/doc.pdf`)
2. Run a scan

**Expected**:
- File usage is detected
- Menu link appears in usage details

### TC-SCAN-011: Menu Link to Private File

1. Create a menu link pointing to a private file (`internal:/system/files/private/doc.pdf`)
2. Run a scan

**Expected**:
- File usage is detected
- Private file correctly identified

### TC-SCAN-MULTI: Multisite File Path Resolution

**Design principle:** Discover and parse using universal path anchors; generate using site-aware services. See [File Path Resolution Spec](../architecture/inventory-specs/file-path-resolution-spec.md) for full details.

**Setup:**
1. Set `$settings['file_public_path'] = 'sites/testsite/files';` in `settings.php`
2. Copy files: `cp -r web/sites/default/files/* web/sites/testsite/files/`
3. Clear caches: `drush cr`
4. Run a full scan

**Important:** Both the setting AND the physical files must be at the new path. The orphan scanner walks the physical directory that `public://` resolves to. If files are not copied, orphan file counts will be lower. After testing, revert the setting, run `drush cr`, and re-scan to restore default URLs.

#### TC-SCAN-MULTI-01: Discovery (Universal Patterns)

1. Create a node with body containing `<a href="/sites/testsite/files/doc.pdf">Link</a>`
2. Run scan

**Expected:**
- `extractLocalFileUrls()` finds the URL via `sites/[^/]+/files` pattern
- File appears in inventory with correct stream URI (`public://doc.pdf`)
- `file_path` URL reflects the current `file_public_path` setting (e.g., `https://site.com/sites/testsite/files/doc.pdf`)

#### TC-SCAN-MULTI-02: Backward Compatibility

1. With `file_public_path = sites/testsite/files`, create content referencing `/sites/default/files/old-doc.pdf`
2. Ensure that file exists at `sites/testsite/files/old-doc.pdf` (same relative path)
3. Run scan

**Expected:**
- URL `/sites/default/files/old-doc.pdf` is discovered via universal `sites/[^/]+/files` pattern
- Converts to `public://old-doc.pdf` (canonical stream URI)
- Stored `file_path` uses the current site's path (`/sites/testsite/files/old-doc.pdf`), not the path from the HTML

#### TC-SCAN-MULTI-03: Conversion Order (5-Step)

Test that `urlPathToStreamUri()` resolves paths in the correct priority:

| Input Path | Expected URI | Step |
|------------|--------------|------|
| `/sites/testsite/files/private/doc.pdf` | `private://doc.pdf` | 1 (Legacy private) |
| `/sites/testsite/files/doc.pdf` | `public://doc.pdf` | 2 (Universal public) |
| `/files/private/doc.pdf` (if base is `/files`) | `private://doc.pdf` | 3 (Dynamic private) |
| `/files/doc.pdf` (if base is `/files`) | `public://doc.pdf` | 4 (Dynamic public) |
| `/system/files/doc.pdf` | `private://doc.pdf` | 5 (Universal private) |

#### TC-SCAN-MULTI-04: Construction (Site-Aware URLs)

1. With `file_public_path = sites/testsite/files`, run scan
2. Check inventory `file_path` values

**Expected:**
- Managed files show `https://site.com/sites/testsite/files/...` URLs
- Orphan files show `https://site.com/sites/testsite/files/...` URLs
- Archive link routing rewrites URLs using `/sites/testsite/files/` path
- `findLocalFileLinkUsage()` DB search includes `/sites/testsite/files/` needle

#### TC-SCAN-MULTI-05: Private Files (Universal)

1. Upload a file to `private://doc.pdf`
2. Create content with `<a href="/system/files/doc.pdf">Link</a>`
3. Run scan

**Expected:**
- `/system/files/doc.pdf` converts to `private://doc.pdf` (universal, path-independent)
- File appears with `is_private = TRUE`
- File deletion resolves private path correctly

#### TC-SCAN-MULTI-06: Usage Page Thumbnails

1. With `file_public_path = sites/testsite/files`, ensure a Media image exists
2. Navigate to the usage page for that image

**Expected:**
- Thumbnail renders correctly using the current site's file path
- `AssetInfoHeader::convertPathToUri()` delegates to `urlPathToStreamUri()` for multisite-safe conversion

#### TC-SCAN-MULTI-07: Path Switch Behavior

1. Run scan with default path → note asset count (e.g., 95)
2. Change `file_public_path`, copy files to new path, `drush cr`
3. Re-scan → verify same asset count
4. Revert `file_public_path`, `drush cr`
5. Re-scan

**Expected:**
- Asset count matches across all scans (when files are properly copied)
- URLs update to reflect the current configuration after each scan
- No stale URLs from previous configurations remain after re-scan

#### TC-SCAN-MULTI-08: Grep Audit

Run: `grep -rn "sites/default/files" src/ digital_asset_inventory.module --include="*.php" --include="*.module"`

**Expected:** Only comments and docblock examples. No functional code contains hardcoded `/sites/default/files/`.

---

## Archive Workflow

### TC-ARCH-001: Queue for Archive

1. Find unused document in inventory
2. Click "Queue for Archive"
3. Select reason, enter description
4. Submit

**Expected**: Document appears in Archive Management with Status: "Queued"

### TC-ARCH-002: Execute Archive - Public

1. Find queued document in `/admin/digital-asset-inventory/archive`
2. Click "Archive Asset"
3. Select "Public" visibility
4. Click "Archive Now"

**Expected**: Status changes to "Archived (Public)", appears at `/archive-registry`

### TC-ARCH-003: Execute Archive - Admin-only

1. Queue a document
2. Execute with "Admin-only" visibility

**Expected**: Status: "Archived (Admin-Only)", NOT visible at `/archive-registry`

### TC-ARCH-004: Toggle Visibility

1. Find archived document (Public or Admin)
2. Click "Make Admin-only" or "Make Public"

**Expected**: Status toggles between "Archived (Public)" and "Archived (Admin-Only)"

### TC-ARCH-005: Execute Blocked by Usage Detected After Queuing

1. Ensure `allow_archive_in_use` is disabled in settings
2. Queue a document (when it has no usage)
3. Add the document to content (creates usage)
4. Run scan to detect the new usage
5. Try to execute archive

**Expected**: "Usage Detected" warning, execute blocked, "Blocked" badge displayed

**Note**: For queuing blocked by usage, see TC-AIU-001. For executing when in-use is allowed, see TC-AIU-005.

### TC-ARCH-006: File Deleted (Status Change)

1. Archive a document
2. Manually delete physical file from filesystem
3. Navigate to Archive Management

**Expected**: "File Missing" warning badge displayed

### TC-ARCH-007: Unarchive

1. Find archived document
2. Click "Unarchive"

**Expected**: Status: "Archived (Deleted)", removed from `/archive-registry`, record preserved for audit trail

### TC-ARCH-008: Remove from Queue

1. Find queued document
2. Click "Remove from Queue"

**Expected**: Archive record deleted

### TC-ARCH-010: Integrity Verification

1. Archive a document
2. Modify physical file content
3. Navigate to Archive Management

**Expected**: "Integrity Issue" warning badge displayed

### TC-ARCH-011: Delete Archived File (Preserve Record)

1. Archive a document (Public or Admin)
2. Navigate to Archive Management
3. Delete the archived file using module UI (not filesystem)

**Expected**: Status changes to "Archived (Deleted)", record preserved with
deleted_date and deleted_by fields populated

### TC-ARCH-012: Late Archive Flag

1. Configure ADA compliance deadline in settings (set to past date for testing)
2. Archive a new document after the deadline

**Expected**: "Late Archive" warning badge displayed on Archive Management page

### TC-ARCH-013: Large File Checksum Queue

1. Upload a file larger than 50MB
2. Queue and execute archive

**Expected**: Checksum shows as pending, calculated via cron

### TC-ARCH-014: Immutable Classification Date

1. Archive a document (sets archive_classification_date)
2. Attempt to modify the date via database or code

**Expected**: Date cannot be changed (enforced in entity preSave)

### TC-ARCH-015: Re-archive After Unarchive

1. Archive a document
2. Unarchive it (status becomes "Archived (Deleted)")
3. Queue the same file for archive again from inventory

**Expected**: New archive record created (new UUID), original record preserved with "Archived (Deleted)" status for audit trail

---

## Archive In-Use Configuration

These tests cover the `allow_archive_in_use` configuration setting behavior.

### TC-AIU-001: Queue Blocked When In Use (Config Disabled)

1. Ensure `allow_archive_in_use` is disabled in settings
2. Find a document with active content references (usage > 0)
3. Navigate to inventory

**Expected**:
- "Queue for Archive" button is NOT visible for the in-use document
- Navigating directly to `/admin/digital-asset-inventory/archive/{id}` redirects to inventory with error message

### TC-AIU-002: Queue Allowed When Not In Use (Config Disabled)

1. Ensure `allow_archive_in_use` is disabled in settings
2. Find a document with NO content references (usage = 0)
3. Click "Queue for Archive"

**Expected**: Queue form displays normally, document can be queued

### TC-AIU-003: Execute Blocked After Usage Detected (Config Disabled)

1. Ensure `allow_archive_in_use` is disabled in settings
2. Queue a document that has no usage
3. Add the document to content (creates usage)
4. Run scan to detect usage
5. Navigate to Archive Management

**Expected**:
- "Blocked" badge appears next to the queued item
- Execute Archive action is blocked with error message

### TC-AIU-004: Queue Allowed When In Use (Config Enabled)

1. Enable `allow_archive_in_use` in settings
2. Find a document with active content references (usage > 0)
3. Click "Queue for Archive"

**Expected**:
- Queue form displays with warning about in-use status
- Confirmation checkbox required before proceeding
- Document can be queued successfully

### TC-AIU-005: Execute Allowed When In Use (Config Enabled)

1. Enable `allow_archive_in_use` in settings
2. Queue a document that is in use
3. Navigate to Execute Archive form

**Expected**:
- Execute form displays with warning about in-use status
- Confirmation checkbox required before proceeding
- Archive executes successfully
- `archived_while_in_use` field set to TRUE
- `usage_count_at_archive` records the count

### TC-AIU-006: Visibility Toggle Blocked (Admin→Public, In Use, Config Disabled)

1. Enable `allow_archive_in_use`, archive a document while in use
2. Disable `allow_archive_in_use` in settings
3. Set archive visibility to Admin-only
4. Try to toggle visibility to Public

**Expected**: "Make Public" action is blocked with error message

### TC-AIU-007: Visibility Toggle Allowed (Public→Admin, In Use, Config Disabled)

1. Enable `allow_archive_in_use`, archive a document (Public) while in use
2. Disable `allow_archive_in_use` in settings
3. Try to toggle visibility to Admin-only

**Expected**: Visibility toggle to Admin-only succeeds (corrective action always allowed)

### TC-AIU-008: Unarchive Allowed When Blocked (Config Disabled)

1. Enable `allow_archive_in_use`, archive a document while in use
2. Disable `allow_archive_in_use` in settings
3. Try to unarchive the document

**Expected**:
- Unarchive succeeds (corrective action always allowed)
- Warning displayed that re-archiving will be blocked

### TC-AIU-009: Manual Entries Bypass Usage Gate

1. Ensure `allow_archive_in_use` is disabled in settings
2. Add a manual archive entry for an internal page
3. Edit the page to add content

**Expected**: Manual entry creation succeeds regardless of config setting (no usage gate for manual entries)

### TC-AIU-010: Re-Enable Config Unblocks Actions

1. Disable `allow_archive_in_use`, verify actions are blocked for in-use items
2. Re-enable `allow_archive_in_use` in settings
3. Attempt previously blocked actions

**Expected**: All archive actions now allowed for in-use items

---

## Archive Link Routing

These tests verify that links to archived files are automatically routed to the Archive Detail Page.

### TC-ALR-001: CKEditor File Link Routing

1. Create a content node with a CKEditor link to a PDF document
2. Archive the PDF document
3. View the content node as anonymous user

**Expected**:
- Link text shows "Original Link Text (Archived)"
- Link href points to `/archive-registry/{id}` (Archive Detail Page)
- Click opens the Archive Detail Page, not the direct file

### TC-ALR-002: CKEditor Media Embed Routing

1. Create a content node with a drupal-media embed (document or video)
2. Archive the media file
3. View the content node as anonymous user

**Expected**:
- Displays inline "Media Name (Archived)" link
- Link points to Archive Detail Page
- No placeholder box on public pages (simplified display)

### TC-ALR-003: File Field Link Routing

1. Create a content node with a file field containing a PDF
2. Archive the PDF document
3. View the content node

**Expected**:
- File link shows "filename.pdf (Archived)"
- Link points to Archive Detail Page

### TC-ALR-004: Menu Link Routing

1. Create a menu link pointing to a PDF document
2. Archive the PDF document
3. View a page with that menu displayed

**Expected**:
- Menu item shows "Menu Title (Archived)"
- Link points to Archive Detail Page

### TC-ALR-005: Breadcrumb Link Routing

1. Set up a breadcrumb that includes a link to a file
2. Archive the file
3. View a page showing that breadcrumb

**Expected**:
- Breadcrumb shows "Text (Archived)"
- Link points to Archive Detail Page

### TC-ALR-006: Media Library Placeholder (Admin)

1. Archive a document that is used in Media Library
2. Browse Media Library in admin (as editor)
3. View the archived media in `media_library` or `thumbnail` view mode

**Expected**:
- Full placeholder displays with icon and message
- Message includes archive date: "This document is for reference only and was archived on [Date]."

### TC-ALR-007: Link Routing Persists When Policy Gate Disabled

1. Enable `allow_archive_in_use`, archive a document while in use
2. Disable `allow_archive_in_use` in settings
3. View content with link to archived document

**Expected**:
- Links STILL route to Archive Detail Page (routing is independent of policy gate)
- Link text still shows "(Archived)" label

### TC-ALR-008: Link Routing Reverts on Unarchive

1. Archive a document that is in use
2. View content - verify links route to Archive Detail Page
3. Unarchive the document
4. View content again

**Expected**:
- Links now point directly to the file (no routing)
- "(Archived)" label no longer appears

### TC-ALR-009: Admin-Only Archive Link Routing

1. Archive a document with Admin-only visibility
2. Create content with link to that file
3. View content as anonymous user

**Expected**:
- Link routes to Archive Detail Page
- Anonymous user sees limited metadata (no download link)
- "This item is not available in the public Archive Registry" message shown

### TC-ALR-010: Images Are NOT Routed

1. Create content with an image displayed on the page
2. Archive the image file
3. View the content

**Expected**:
- Image still displays normally (no routing)
- No "(Archived)" label on images
- Images are excluded from link routing (would break rendering)

### TC-ALR-011: Manual Entry (Page) Link Routing

1. Create a manual archive entry for an internal page (e.g., `/about-us`)
2. Create a menu link to `/about-us`
3. View a page with that menu

**Expected**:
- Menu link routes to Archive Detail Page for the manual entry
- "(Archived)" appended to link text

### TC-ALR-012: Response Subscriber Handles Dynamic Content

1. Create a View that outputs file links dynamically
2. Archive one of the files that appears in the View
3. View the page with the View

**Expected**:
- Links to archived files are rewritten by Response Subscriber
- "(Archived)" label appears on archived file links
- Non-archived files unchanged

### TC-ALR-013: URL-Encoded Paths

1. Upload a file with spaces in name: "my document.pdf"
2. Create content linking to the file
3. Archive the file
4. View the content

**Expected**:
- Link routing works correctly despite URL encoding (%20 for space)
- File correctly matched to archive record

### TC-ALR-014: Twig Extension - archive_aware_url Filter

1. In a custom template, use `{{ file_url|archive_aware_url }}`
2. Archive the file
3. View page using that template

**Expected**:
- URL returns Archive Detail Page path when file is archived
- Returns original URL when file is not archived

### TC-ALR-015: Twig Extension - is_archived Function

1. In a custom template, use `{% if is_archived(file_url) %}`
2. Archive the file
3. View page using that template

**Expected**:
- Function returns TRUE for archived files
- Returns FALSE for non-archived files

---

## Exemption Void

### TC-VOID-001: Automatic Exemption Void After Deadline

1. Set ADA compliance deadline to a past date (e.g., yesterday)
2. Archive a document
3. Modify the physical file content
4. Navigate to Archive Management (triggers reconciliation)

**Expected**: Status changes to "Exemption Void", "Exemption Voided" badge displayed

### TC-VOID-002: No Void Before Deadline

1. Set ADA compliance deadline to a future date
2. Archive a document
3. Modify the physical file content
4. Navigate to Archive Management

**Expected**: "Integrity Issue" badge displayed, but status remains "Archived (Public/Admin)"

### TC-VOID-003: Void Removes from Public Registry

1. Archive a document (Public)
2. Trigger exemption void (modify file after deadline)
3. Navigate to `/archive-registry`

**Expected**: Document no longer appears in public Archive Registry

### TC-VOID-004: Void Badge in Inventory

1. Archive a document
2. Trigger exemption void
3. Navigate to `/admin/digital-asset-inventory`

**Expected**: "Exemption Void" badge (dark red) appears next to document name

### TC-VOID-005: Queue Hidden for Voided Document

1. Archive a document
2. Trigger exemption void
3. Navigate to inventory, find the document

**Expected**: "Queue for Archive" button NOT visible (document has active archive record)

### TC-VOID-006: Re-archive Voided Document

1. Find a voided document in Archive Management (note: terminal state, no operations available)
2. Navigate to inventory, find the same file
3. Queue for archive and execute

**Expected**: New archive record created with fresh checksum, **forced to General Archive** regardless of current date (voided exemption rule), original voided record preserved as immutable audit trail

### TC-VOID-007: Voided Document - No Operations Available

1. Find a voided document in Archive Management

**Expected**: No action buttons available (Exemption Void is a terminal state)

### TC-VOID-009: Void CSV Export Column

1. Archive a document
2. Trigger exemption void
3. Export Archive Audit CSV

**Expected**: "Exemption Voided" column shows "Yes (ADA exemption voided: file was modified after the compliance deadline)"

---

## ADA Compliance Deadline Settings

### TC-DEADLINE-001: Configure Deadline

1. Navigate to `/admin/config/accessibility/digital-asset-inventory`
2. Change ADA Compliance Deadline
3. Save configuration

**Expected**: New deadline saved, displayed correctly on form

### TC-DEADLINE-002: Deadline Display in Forms

1. Configure deadline to "January 15, 2027"
2. Navigate to `/admin/digital-asset-inventory/archive/{id}` (queue form)

**Expected**: ADA Archive Requirements section shows "January 15, 2027"

### TC-DEADLINE-003: Deadline Display on Detail Page

1. Configure deadline to "January 15, 2027"
2. Archive a document
3. View archive detail page at `/archive-registry/{id}`

**Expected**: Note text shows "created before January 15, 2027"

### TC-DEADLINE-004: Deadline Display in Public Registry

1. Configure deadline to "January 15, 2027"
2. Navigate to `/archive-registry`

**Expected**: Header text shows "created before January 15, 2027"

### TC-DEADLINE-005: Deadline Persistence (No Date Shift)

1. Configure deadline to "April 24, 2026"
2. Save configuration
3. Reload settings page
4. Save again (without changes)

**Expected**: Date remains "April 24, 2026" (no timezone shift)

---

## Manual Archive Entries

### TC-MANUAL-001: Add Internal Web Page Entry

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Enter title: "Test Archived Page"
3. Enter URL: `node/123` (existing node)
4. Select Content Type: "Web Page"
5. Select Archive Reason: "Reference"
6. Enter public description (20+ characters)
7. Select visibility: "Public"
8. Submit

**Expected**:
- Manual entry created with asset_type="page"
- Appears in Archive Management with status "Archived (Public)"
- Internal path resolved to absolute URL
- Archived content banner appears on the page

### TC-MANUAL-002: Add External Resource Entry

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Enter title: "External Policy Document"
3. Enter URL: `https://example.com/policy`
4. Select Content Type: "External Resource"
5. Select Archive Reason: "Recordkeeping"
6. Enter public description
7. Select visibility: "Admin-only"
8. Submit

**Expected**:
- Manual entry created with asset_type="external"
- Appears in Archive Management with status "Archived (Admin-Only)"
- Does NOT appear in public registry

### TC-MANUAL-003: Edit Manual Entry

1. Find manual archive entry in Archive Management
2. Click "Edit" button
3. Modify title and public description
4. Save

**Expected**: Changes saved, updated values displayed

### TC-MANUAL-004: Manual Entry Public Visibility

1. Create manual entry with "Public" visibility
2. Navigate to `/archive-registry`

**Expected**: Manual entry appears in "Archived Content" section of public registry

### TC-MANUAL-005: Block File URLs in Manual Entry

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Enter URL: `https://example.com/document.pdf`
3. Submit

**Expected**: Validation error - "File URLs and folder paths cannot be archived using this form"

### TC-MANUAL-006: Block Media Entity Paths

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Enter URL: `media/123`
3. Submit

**Expected**: Validation error - "Media entities cannot be archived using this form"

### TC-MANUAL-007: Block File Storage Paths

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Enter URL: `/sites/default/files/documents`
3. Submit

**Expected**: Validation error - "File URLs and folder paths cannot be archived using this form"

### TC-MANUAL-008: Duplicate URL Detection

1. Create manual entry for `node/123`
2. Try to create another entry for `node/123`

**Expected**: Validation error - "This URL is already in the Archive Registry"

### TC-MANUAL-009: Remove Manual Entry (Preserves Record)

1. Find manual archive entry
2. Click "Remove from Registry"
3. Confirm removal

**Expected**:
- Status changes to "Archived (Deleted)"
- Record preserved in Archive Management for audit trail
- Entry removed from public registry

### TC-MANUAL-010: Content Type Mismatch Validation

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Enter URL: `https://external-site.com/page`
3. Select Content Type: "Web Page" (incorrect - should be External)
4. Submit

**Expected**: Validation error - "External URLs must use the External Resource content type"

### TC-MANUAL-011: Archive Taxonomy Term via Path Alias

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Enter title: "Archived Event Category"
3. Enter URL: `/events/taco-tuesdays` (path alias for taxonomy term)
4. Select Content Type: "Web Page"
5. Select Archive Reason: "Reference"
6. Enter public description
7. Submit

**Expected**:
- Manual entry created successfully
- Path alias resolved to absolute URL
- Archived content banner appears on taxonomy term page
- Edit protection warning appears when editing the taxonomy term

### TC-MANUAL-012: Archive Taxonomy Term via System Path

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Enter URL: `taxonomy/term/5`
3. Select Content Type: "Web Page"
4. Complete other required fields
5. Submit

**Expected**:
- Manual entry created successfully
- System path resolved to absolute URL
- Banner and edit protection work correctly

### TC-MANUAL-013: Page URL Autocomplete - Node Title Search

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Select Content Type: "Web Page"
3. In Page URL field, type part of a node title (e.g., "About")

**Expected**:
- Autocomplete dropdown shows matching nodes
- Results display as "Title (/path/alias)"
- Selecting a result populates the field with the path

### TC-MANUAL-014: Page URL Autocomplete - Taxonomy Term Search

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Select Content Type: "Web Page"
3. In Page URL field, type part of a taxonomy term name

**Expected**:
- Autocomplete dropdown shows matching taxonomy terms
- Results display as "Term Name (/path/alias)"
- Selecting a result populates the field with the path

### TC-MANUAL-015: Page URL Autocomplete - Path Alias Search

1. Navigate to `/admin/digital-asset-inventory/archive/add`
2. Select Content Type: "Web Page"
3. In Page URL field, type part of a path alias (e.g., "events/taco")

**Expected**:
- Autocomplete dropdown shows paths matching the alias
- Results include the page title if available
- Selecting a result populates the field with the alias

### TC-MANUAL-016: Multilingual Autocomplete - Default Language

1. On multilingual site, navigate to `/admin/digital-asset-inventory/archive/add`
2. Select Content Type: "Web Page"
3. Type a node title in default language

**Expected**: Autocomplete shows matching results without language suffix

### TC-MANUAL-017: Multilingual Autocomplete - Translated Content

1. On multilingual site with translated nodes
2. Type a translated node title (e.g., Spanish translation)

**Expected**:
- Autocomplete shows result with language suffix (e.g., "Page Title [es]")
- Selecting it resolves to correct translated URL

### TC-MANUAL-018: Multilingual Autocomplete - Non-multilingual Site

1. On site without multilingual enabled
2. Use page URL autocomplete

**Expected**: Works normally without errors, no language suffixes shown

---

## Warning Flags

### TC-FLAG-001: Usage Detected

- **Trigger**: Document referenced in content
- **Display**: Orange "Usage Detected" badge in Warnings column

### TC-FLAG-002: File Missing

- **Trigger**: `flag_missing=TRUE` OR `status=archived_deleted`
- **Display**: Red "File Missing" badge in Warnings column

### TC-FLAG-003: Integrity Issue

- **Trigger**: File checksum doesn't match stored value
- **Display**: Red "Integrity Issue" badge in Warnings column

### TC-FLAG-004: Late Archive

- **Trigger**: Archive classification date is after ADA compliance deadline
- **Display**: Yellow "Late Archive" badge in Warnings column
- **Note**: Advisory only, determines archive type (Legacy vs General)

### TC-FLAG-005: Exemption Voided

- **Trigger**: `status=exemption_void` (Legacy Archive modified after deadline)
- **Display**: Dark red "Exemption Voided" badge in Warnings column
- **Note**: Status change, not just a flag - document removed from public registry

### TC-FLAG-006: Modified

- **Trigger**: `flag_modified=TRUE` (General Archive manual entry modified)
- **Display**: Orange "Modified" badge in Warnings column
- **Note**: Only for manual entries (pages/URLs); file-based archives use Integrity Issue

### TC-FLAG-007: Prior Exemption Voided

- **Trigger**: `flag_prior_void=TRUE` (file/URL has a previous exemption_void record)
- **Display**: Gray "Prior Void" badge in Warnings column
- **Note**: Advisory only - indicates new archive was forced to General Archive due to prior voided exemption

---

## CSV Exports

### TC-CSV-001: Inventory Export

1. Navigate to `/admin/digital-asset-inventory`
2. Click "Export Asset Inventory (CSV)"

**Expected**: CSV with columns: File Name, File URL, Asset Type,
Category, MIME Type, Source, File Size, Active Use Detected, Used In

### TC-CSV-002: Archive Audit Export

1. Navigate to `/admin/digital-asset-inventory/archive`
2. Click "Export Archive Audit (CSV)"

**Expected**: CSV with audit columns, dates in ISO 8601 format
(e.g., `2025-12-29T10:22:41-08:00`)

### TC-CSV-003: Archive Audit Flag Values

Verify flag columns show descriptive values:

- `Yes (File checksum does not match...)` / `No (File checksum matches...)`
- `Yes (Active content references...)` / `No (No active content...)`
- `Yes (Underlying file no longer exists...)` / `No (File exists...)`
- `Private (Login required)` / `Public` for File Access
- `Yes (Archived with active content references)` / `No` for Archived While In Use

### TC-CSV-004: Archive Audit In-Use Fields

1. Enable `allow_archive_in_use` in settings
2. Archive a document that is in use (has active content references)
3. Export Archive Audit CSV

**Expected**:
- "Archived While In Use" column shows "Yes (Archived with active content references)"
- "Usage Count at Archive" column shows the number of references at time of archive (e.g., "3")

### TC-CSV-005: Archive Audit Private File

1. Archive a file stored in private file system (`private://`)
2. Export Archive Audit CSV

**Expected**:
- "File Access" column shows "Private (Login required)"

---

## Public Archive Registry

### TC-PUBLIC-001: Registry Page

1. Navigate to `/archive-registry` (anonymous)

**Expected**: Lists only "Archived (Public)" items, not Admin-only or Queued

### TC-PUBLIC-002: Detail Page

1. Click document name in registry

**Expected**: Detail page at `/archive-registry/{id}` with file info and
accessibility contact

### TC-PUBLIC-003: Missing File Hidden

1. Archive a document (Public)
2. Delete the physical file
3. Navigate to `/archive-registry`

**Expected**: Document no longer appears in public registry

---

## Delete Assets

### TC-DEL-001: Delete Manual Upload File

1. Find manual upload file (Source: "Manual Upload")
2. Click "Delete"

**Expected**: Physical file removed, asset removed from inventory

### TC-DEL-002: Delete Managed File

1. Find unused managed file
2. Click "Delete"

**Expected**: File entity and physical file removed

### TC-DEL-003: Delete Media File

1. Find unused media file
2. Click "Delete"

**Expected**: Media entity, file entity, and physical file removed

### TC-DEL-004: Delete Hidden for Used Assets

**Expected**: No "Delete" button for assets with usage > 0

---

## Private Files

### TC-PRIV-001: Private File Detection

1. Upload a file to the private file system (`private://`)
2. Run a scan

**Expected**: File appears with `is_private=TRUE` in database

### TC-PRIV-002: File Storage Filter (Inventory)

1. Navigate to `/admin/digital-asset-inventory`
2. Use "File Storage" filter to select "Private Files Only"

**Expected**: Only files from `private://` stream displayed

### TC-PRIV-003: File Access Filter (Archive)

1. Navigate to `/admin/digital-asset-inventory/archive`
2. Use "File Access" filter to select "Private Files (Login Required)"

**Expected**: Only archived private files displayed

### TC-PRIV-004: Archive Detail - Anonymous Login Prompt

1. Archive a private file (Public visibility)
2. Log out
3. Navigate to `/archive-registry/{id}` for the private file

**Expected**: Login prompt displayed with "Authentication Required" message
and link to log in

### TC-PRIV-005: Archive Detail - Authenticated Access

1. Archive a private file (Public visibility)
2. Log in as authenticated user
3. Navigate to `/archive-registry/{id}` for the private file

**Expected**: Note about private files shown, download link functional

### TC-PRIV-006: Archive Detail - CAS Login Redirect

1. Archive a private file (Public visibility)
2. Log out
3. Navigate to `/archive-registry/{id}` as anonymous user
4. Click login link

**Expected**: Redirected to CAS login (if enabled) with destination back
to archive detail page

### TC-PRIV-007: Private Flag Preserved on Archive

1. Queue a private file for archive
2. Execute archive

**Expected**: `is_private` flag preserved in archive record

---

## Views and Filters

### TC-VIEW-001: Inventory Filters

Test each filter: Category, Asset Type, Source Type, File Storage, In Use, Archive Status

**Archive Status filter** (only visible when archiving is enabled):
- Not Archived - assets without any active archive record
- Queued - assets queued for archive
- Archived (any) - assets with public or admin-only archive
- Archived (Public) - assets archived with public visibility
- Archived (Admin-Only) - assets archived with admin-only visibility

**Badge display** (inventory file name column):
- Only active statuses show badges: Queued, Archived (Public), Archived (Admin-Only)
- Terminal states (exemption_void, archived_deleted) show NO badge - files can be re-archived
- Filter matches what badges display (filter options = badge statuses)

**Filter matching logic**:
- Managed files: matched by fid (file ID)
- External/filesystem assets (NULL fid): matched by file_path

### TC-VIEW-002: Archive Filters

Filter by Archive Type: Legacy Archive, General Archive

Filter by Status: Queued, Archived (Public), Archived (Admin-Only),
Archived (Deleted), Exemption Void

Filter by Asset Type: Documents, Videos, Web Pages, External Resources

Filter by Purpose: Reference, Research, Recordkeeping, Other

### TC-VIEW-003: Empty State

- No scan yet: Filters hidden, "Last Scan: Never" shown
- Filters return nothing: Warning message displayed

### TC-VIEW-004: Responsive Tables (CSS-only)

1. View each admin view at mobile viewport (≤640px):
   - `/admin/digital-asset-inventory`
   - `/admin/digital-asset-inventory/archive`
   - `/admin/digital-asset-inventory/usage/{id}`
   - `/archive-registry`

**Expected**:
- Tables stack vertically with CSS-only solution (no JavaScript)
- Headers hidden, each cell shows label via `data-label` attribute
- Cards have proper borders and spacing
- Operations display as text links (not dropbuttons)

2. View at tablet viewport (641-1023px)

**Expected**: Card layout with labels visible

3. View at desktop viewport (≥1024px)

**Expected**: Normal table layout with headers visible

### TC-VIEW-004a: Usage Detail Page

1. Navigate to `/admin/digital-asset-inventory`
2. Click on the "Used In" count for any asset with usage > 0

**Expected**:
- Asset info header displays at top with bordered box containing:
  - Asset name (media title + filename in parentheses for media files)
  - File type, size, source, and file access separated by `|` dividers
  - File access shows "Public (Accessible to anyone without logging in)" or "Private (Accessible only to logged-in or authorized users)"
  - For media images: Media ID line, Media alt text status, View/Edit Media links
  - For images: Thumbnail displayed (64-96px)
  - Alt text summary strip (images only): inline format with counts
  - Clickable file URL
- Usage table shows columns: Used On, Item Type, Item Category, Section, Required Field
- For images: Alt text column visible
- Used On links to the content page (opens in same window)
- Section shows actual field label (e.g., "Hero Image", not "media")
- Required Field shows "Yes" or "No" based on field configuration
- "Return to Inventory" button appears at bottom

### TC-VIEW-005: Archive Badge Display

1. Queue a document for archive
2. View main inventory

**Expected**: "Queued" badge appears next to document name

### TC-VIEW-006: Archive Badge After Archive

1. Archive a document (Public)
2. View main inventory

**Expected**: "Archived" badge appears next to document name

### TC-VIEW-007: Exemption Void Badge in Inventory

1. Archive a document
2. Trigger exemption void (modify file after deadline)
3. View main inventory

**Expected**: "Exemption Void" badge (dark red) appears next to document name,
"Queue for Archive" button NOT visible

---

## Permissions

### TC-PERM-001: View Without Scan Permission

1. Log in as user with only `view digital asset inventory`
2. Navigate to `/admin/digital-asset-inventory`

**Expected**: Can view inventory, "Scan" button not visible

### TC-PERM-002: Scan Without Delete Permission

1. Log in as user with `scan digital assets` but not `delete digital assets`
2. Run a scan
3. View inventory

**Expected**: Scan works, "Delete" buttons not visible

### TC-PERM-003: Archive Permission Required

1. Log in as user without `archive digital assets` or `view digital asset archives`
2. Navigate to `/admin/digital-asset-inventory/archive`

**Expected**: Access denied (403)

### TC-PERM-004: Admin Permission

1. Log in as user with `administer digital assets`
2. Navigate to `/admin/config/accessibility/digital-asset-inventory`

**Expected**: Settings page accessible

### TC-PERM-005: View Digital Asset Archives (Read-only)

1. Create user with only `view digital asset archives` permission
2. Navigate to `/admin/digital-asset-inventory/archive`

**Expected**:
- Can view archive management page
- Cannot see Archive/Unarchive/Delete operation buttons
- Cannot see "Add Manual Archive Entry" button

3. Click "Notes" link for any archive record

**Expected**:
- Can access notes page
- Can view Initial Note and Archive Review Log
- Add Note form is NOT visible

### TC-PERM-006: Archive Digital Assets (Full Access)

1. Create user with `archive digital assets` permission
2. Navigate to `/admin/digital-asset-inventory/archive`

**Expected**:
- Full access to archive management
- Can see all operation buttons
- Can see "Add Manual Archive Entry" button

3. Click "Notes" link for any archive record

**Expected**:
- Can access notes page
- Add Note form IS visible and functional

---

## Internal Notes

### TC-NOTES-001: View Notes Page

1. Log in as user with `archive digital assets`
2. Navigate to Archive Management
3. Click "Notes" link for an archived item

**Expected**:
- Notes page displays with archive info (collapsed details)
- Initial Note section shows note from archive creation
- Archive Review Log section shows additional notes

### TC-NOTES-002: Add Note

1. On notes page, enter text in Add Note form (max 500 chars)
2. Click "Add Note"

**Expected**:
- Note appears in Archive Review Log
- Shows author name and timestamp
- Form clears for next entry

### TC-NOTES-003: Notes Are Append-Only

1. View existing notes on notes page

**Expected**: No edit or delete buttons on any note

### TC-NOTES-004: Notes Link Shows Count

1. View Archive Management page

**Expected**:
- "Notes" shows when no additional notes exist
- "Notes (3)" shows count when notes exist

### TC-NOTES-005: Notes Pagination

1. Add more than 25 notes to an archive record
2. View notes page

**Expected**: Pagination appears, 25 notes per page

### TC-NOTES-006: Read-only User Cannot Add Notes

1. Log in as user with only `view digital asset archives`
2. Navigate to notes page for any archive

**Expected**:
- Can view all notes
- Add Note form is not displayed

---

## Archived Page Document and External Resource Status Notes

### TC-DPS-001: No Resources on Page

1. Create a page with text content but no document or external resource links
2. Archive the page via manual archive entry
3. View the archived page

**Expected**: Only baseline "Archived Material" banner displayed, no contextual note

### TC-DPS-002: All Active Documents

1. Create page with 2 PDF links (not archived)
2. Archive the page via manual entry
3. View the archived page

**Expected**: Baseline banner + "Related Active Documents" note

### TC-DPS-003: All Archived Documents

1. Create page with 2 PDF links
2. Archive both PDFs via inventory workflow
3. Archive the page via manual entry
4. View the archived page

**Expected**: Baseline banner + "Archived Supporting Materials: All documents..." note

### TC-DPS-004: Mixed Documents

1. Create page with 3 PDF links
2. Archive 1 PDF via inventory, leave 2 active
3. Archive the page via manual entry
4. View the archived page

**Expected**: Baseline banner + "Mixed Content Status" note (documents version)

### TC-DPS-005: Admin Note Visibility

1. Archive a page with document links
2. View as user WITH `archive digital assets` permission
3. Log out and view as anonymous user

**Expected**:
- Admin sees "Administrative Note" at bottom
- Anonymous user does not see "Administrative Note"

### TC-DPS-006: Document Status Cache Invalidation

1. Archive a page with 1 active PDF link
2. Verify banner shows "Related Active Documents"
3. Archive the PDF via inventory workflow
4. Clear cache and refresh the archived page

**Expected**: Note changes to "Archived Supporting Materials"

### TC-DPS-007: Non-Archived External Resources (Ignored)

1. Create page with 2 Google Docs links (not archived in our system)
2. Archive the page via manual entry
3. View the archived page

**Expected**: No contextual note for external resources (they're ignored if not archived). Only document status notes appear if documents are present.

### TC-DPS-008: All Archived External Resources

1. Create page with 2 Google Docs links
2. Archive both external URLs via manual archive (asset_type='external')
3. Archive the page via manual entry
4. View the archived page

**Expected**: Baseline banner + "Archived Supporting Materials: All supporting materials linked from this page are external resources..." note

### TC-DPS-009: Mixed Types - All Archived

1. Create page with 2 PDF links and 2 Google Docs links
2. Archive both PDFs via inventory
3. Archive both external URLs via manual archive
4. Archive the page via manual entry
5. View the archived page

**Expected**: Baseline banner + "Archived Supporting Materials: Supporting materials linked from this page may include archived site documents and external resources..." note

### TC-DPS-010: Mixed Types - Docs Mixed + External Archived

1. Create page with 3 PDF links and 2 Google Docs links
2. Archive 1 PDF via inventory (leave 2 active)
3. Archive both Google Docs links via manual archive
4. Archive the page via manual entry
5. View the archived page

**Expected**: Baseline banner + "Mixed Content Status: This archived page references a combination of archived and active materials, including documents and external resources..." note

### TC-DPS-011: External URL Normalization Matching

1. Create manual archive for `https://docs.google.com/document/d/abc123/` (with trailing slash)
2. Create page with link to same URL without trailing slash: `https://docs.google.com/document/d/abc123`
3. Archive the page via manual entry
4. View the archived page

**Expected**: URL is matched via normalization, external resource shows as archived in contextual note

---

## Cache Invalidation

### TC-CACHE-001: Archive Status Change

1. Archive a document
2. Note the views display
3. Toggle visibility

**Expected**: Views update immediately to reflect new status

### TC-CACHE-002: File Deletion Cache

1. Archive a document
2. Delete the archived file via module UI
3. View Archive Management

**Expected**: Status shows "Archived (Deleted)" without page refresh

---

## Edge Cases

### TC-EDGE-001: Special Characters in Filename

1. Upload file with special characters: `test file (2).pdf`
2. Scan and view in inventory

**Expected**: File displays correctly with proper URL encoding

### TC-EDGE-002: Very Long Filename

1. Upload file with 200+ character name
2. Scan and view in inventory

**Expected**: Name truncated appropriately in display

### TC-EDGE-003: Unicode Filename

1. Upload file with unicode: `文档.pdf`
2. Scan and view in inventory

**Expected**: File displays correctly

### TC-EDGE-004: Concurrent Archive Attempts

1. Open archive form for same document in two browser tabs
2. Submit in both tabs

**Expected**: Second submission shows "already has active archive record"

### TC-EDGE-005: Archive During Active Scan

1. Start a scan
2. Attempt to archive a document mid-scan

**Expected**: Archive completes or shows appropriate message

---

## Error Handling

### TC-ERR-001: Archive Missing File

1. Queue a document for archive
2. Delete the physical file
3. Try to execute archive

**Expected**: Error message "Source file does not exist", flag_missing set

### TC-ERR-002: Archive With Active Usage

1. Queue a document for archive
2. Add the document to a node
3. Run scan
4. Try to execute archive

**Expected**: Error message about active usage, flag_usage set

### TC-ERR-003: Default Visibility Applied

1. Navigate to Execute Archive form
2. Do not change visibility selection
3. Click "Archive Asset"

**Expected**: Archive created with `status=archived_public` (default visibility)

---

## Dual-Purpose Archive

### TC-DUAL-001: Legacy Archive Classification (Before Deadline)

1. Set ADA compliance deadline to a future date (e.g., 2027-01-01)
2. Archive a document
3. Navigate to Archive Management

**Expected**:
- Archive is created with `flag_late_archive=FALSE`
- "Archive Type" column shows "Legacy Archive" with blue badge
- Document appears in public registry with full ADA notice

### TC-DUAL-002: General Archive Classification (After Deadline)

1. Set ADA compliance deadline to a past date (e.g., 2024-01-01)
2. Archive a document
3. Navigate to Archive Management

**Expected**:
- Archive is created with `flag_late_archive=TRUE`
- "Archive Type" column shows "General Archive" with gray badge
- Document appears in public registry with simplified notice (no deadline reference)

### TC-DUAL-003: Archive Type Filter

1. Create archives before and after the deadline (Legacy and General)
2. Navigate to Archive Management
3. Use "Archive Type" filter

**Expected**:
- "Legacy Archive" shows only archives with `flag_late_archive=FALSE`
- "General Archive" shows only archives with `flag_late_archive=TRUE`

### TC-DUAL-004: Form Messaging - Legacy Archive

1. Set deadline to future date
2. Navigate to Queue for Archive form

**Expected**:
- Archive Type notice shows: "Legacy Archive (archived before [deadline])" with status (green) styling
- Archive Requirements section explains both Legacy and General archives

### TC-DUAL-005: Form Messaging - General Archive

1. Set deadline to past date
2. Navigate to Queue for Archive form

**Expected**:
- Archive Type notice shows: "General Archive (archived after [deadline])" with warning (yellow) styling
- Archive Requirements section explains both Legacy and General archives

### TC-DUAL-006: Edit Warning - Legacy Archive

1. Create a Legacy archive for an internal page (manual entry)
2. Navigate to edit the page

**Expected**:
- Warning title: "This content is currently recorded as archived for ADA Title II purposes."
- Warning body mentions "will no longer qualify as archived/exempt"
- Checkbox text mentions "archived/exempt status"

### TC-DUAL-007: Edit Warning - General Archive

1. Create a General archive for an internal page (manual entry)
2. Navigate to edit the page

**Expected**:
- Warning title: "This content is currently archived."
- Warning body mentions "flagged as modified in the Archive Registry"
- Checkbox text mentions "modified in the archive record"

### TC-DUAL-008: Legacy Archive Edit - Exemption Void

1. Create Legacy archive for internal page
2. Edit and save the page (with acknowledgment)
3. Navigate to Archive Management

**Expected**:
- Status changes to "Exemption Void"
- "Exemption Voided" badge displayed in Warnings column
- Document removed from public registry

### TC-DUAL-009: General Archive Edit - Archived Deleted with Modified Flag

1. Create General archive for internal page
2. Edit and save the page (with acknowledgment)
3. Navigate to Archive Management

**Expected**:
- Status changes to "Archived (Deleted)"
- `flag_modified=TRUE`
- "Modified" badge displayed in Warnings column
- Document removed from public registry

### TC-DUAL-010: Archive Type in CSV Export

1. Create both Legacy and General archives
2. Export Archive Audit CSV

**Expected**:
- "Archive Type" column shows "Legacy Archive" or "General Archive"

### TC-DUAL-011: Archive Type Badge on Public Registry

1. Create a Legacy archive (Public)
2. Create a General archive (Public)
3. Navigate to `/archive-registry`

**Expected**:
- Legacy archive shows "Legacy Archive" badge (blue)
- General archive shows "General Archive" badge (gray)

### TC-DUAL-012: Archive Type Detail Page - Legacy

1. Create a Legacy archive (Public)
2. Navigate to `/archive-registry/{id}`

**Expected**:
- Shows "Legacy Archive" badge
- Notice includes ADA exemption language and deadline reference
- "Archived Material Notice" title

### TC-DUAL-013: Archive Type Detail Page - General

1. Create a General archive (Public)
2. Navigate to `/archive-registry/{id}`

**Expected**:
- Shows "General Archive" badge
- Notice does NOT include ADA exemption language
- "Archive Notice" title (simplified)

### TC-DUAL-014: Purpose Filter

1. Create archives with different purposes (Reference, Research, Recordkeeping, Other)
2. Navigate to Archive Management
3. Use "Purpose" filter

**Expected**: Filter correctly filters by archive_reason field

### TC-DUAL-015: Asset Type Filter (Grouped)

1. Create archives for PDF, Word, Excel (Documents)
2. Create archives for MP4, WebM (Videos)
3. Create manual entries for pages and external URLs
4. Navigate to Archive Management
5. Use "Asset Type" filter

**Expected**:
- "Documents" shows PDF, Word, Excel archives
- "Videos" shows MP4, WebM archives
- "Web Pages" shows manual page entries
- "External Resources" shows manual external entries

### TC-DUAL-016: Modified Flag for Manual Entries Only

1. Create a General archive for an internal page (manual entry)
2. Edit and save the page

**Expected**:
- `flag_modified=TRUE` set
- "Modified" badge appears in Warnings column

### TC-DUAL-017: Integrity Issue Flag for File-Based Archives

1. Create a General archive for a file (not manual entry)
2. Modify the physical file content
3. Navigate to Archive Management (triggers reconciliation)

**Expected**:
- `flag_integrity=TRUE` set
- Status changes to "Archived (Deleted)"
- "Integrity Issue" badge appears (NOT "Modified" - Modified is for manual entries only)

### TC-DUAL-018: Classification Date Immutability

1. Archive a document
2. Try to modify `archive_classification_date` via database

**Expected**: Date remains unchanged (enforced in entity preSave)

### TC-DUAL-019: Deadline Change Doesn't Affect Existing Archives

1. Create Legacy archive (deadline in future)
2. Change deadline to past date
3. Navigate to Archive Management

**Expected**: Existing archive still shows "Legacy Archive" (classification is immutable)

### TC-DUAL-020: Manual Archive Form - Archive Type Notice

1. Set deadline to future date
2. Navigate to Add Manual Archive Entry form

**Expected**: Shows "Legacy Archive" notice with ADA exemption eligibility message

### TC-DUAL-021: File Checksum Immutability

1. Archive a document (file-based, not manual entry)
2. Verify checksum was calculated and stored
3. Try to modify `file_checksum` via database or code

**Expected**: Checksum remains unchanged (enforced in entity preSave). LogicException thrown if modification attempted after archive execution.

---

## Usage Page (Media-Aware)

### TC-USAGE-001: Media-Backed Image Header

1. Upload an image via Media Library
2. Use the image in a content node
3. Run a scan
4. Click on the "Used In" count for the image asset

**Expected**:
- Thumbnail displayed in header (64-96px)
- Media title shown with filename in parentheses
- Media ID displayed
- "Media alt text: detected" or "Media alt text: not detected" status line
- "View Media" and "Edit Media" action links

### TC-USAGE-002: File-Managed Image Header

1. Upload an image directly via file field (not Media)
2. Use the image in a content node
3. Run a scan
4. Click on the "Used In" count for the image asset

**Expected**:
- Thumbnail displayed in header
- No Media ID line
- No Media alt text status line
- No View/Edit Media links

### TC-USAGE-003: Non-Image Asset Header

1. Upload a PDF document
2. Use the document in content
3. Run a scan
4. Click on the "Used In" count for the PDF asset

**Expected**:
- No thumbnail displayed
- No alt text column visible in table

### TC-USAGE-004: Alt Text Column - Media Reference (Shared Alt)

1. Upload an image via Media Library with alt text "Mountain sunset"
2. Add the media to a content node via media entity reference field
3. Run a scan
4. Navigate to usage page for the image

**Expected**:
- Alt text column visible
- Shows "Mountain sunset" (truncated if > 120 chars)
- Source label shows "(from media)" indicating alt text is shared from Media entity

### TC-USAGE-005: Alt Text Column - Inline Image with Alt

1. Create content with CKEditor
2. Insert inline `<img src="..." alt="Beach view">` in body field
3. Run a scan
4. Navigate to usage page for the image

**Expected**:
- Alt text column shows "Beach view"
- Source label shows "(inline image)" indicating alt from `<img>` tag

### TC-USAGE-006: Alt Text Column - Missing Alt

1. Create content with inline `<img>` tag without alt attribute
2. Run a scan
3. Navigate to usage page for the image

**Expected**:
- Alt text column shows "Not detected" with muted styling
- Tooltip explains "No alt text was found for this image usage."

### TC-USAGE-007: Alt Text Column - Decorative Image

1. Create content with `<img alt="">` (empty alt)
2. Run a scan
3. Navigate to usage page for the image

**Expected**:
- Alt text column shows "Decorative image" in muted/italic style
- Tooltip explains "Image is marked as decorative (empty alt attribute)."

### TC-USAGE-008: Alt Text Column - Template Controlled

1. Use an image in a paragraph field that renders via template
2. Run a scan
3. Navigate to usage page for the image

**Expected**:
- Alt text column shows "Managed by template" or "Not evaluated"

### TC-USAGE-008a: Alt Text Column - Content Override (CKEditor Embed)

1. Upload an image via Media Library with alt text "Original alt text"
2. In a content node, embed the same media via CKEditor using the media icon
3. In the CKEditor embed dialog, change the alt text to "Custom override text"
4. Run a scan
5. Navigate to usage page for the image

**Expected**:
- Alt text column shows "Custom override text"
- Source label shows "(content override)" indicating alt was customized from Media default

### TC-USAGE-008b: Same Media, Different Sources

1. Upload an image via Media Library with alt text "Shared media alt"
2. Create a content node with:
   - A media reference field containing the image (use "Shared media alt")
   - A text field with the same image embedded via CKEditor, with alt override "CKEditor custom alt"
3. Run a scan
4. Navigate to usage page for the image

**Expected**:
- Two usage rows displayed for the same node
- Media field usage shows "Shared media alt" with source "(from media)"
- CKEditor embed usage shows "CKEditor custom alt" with source "(content override)"

### TC-USAGE-008c: Media in Paragraph Field

1. Create a paragraph type with a media reference field
2. Add the paragraph to a content node
3. Upload/select an image via Media Library in the paragraph's media field
4. Run a scan
5. Navigate to usage page for the image

**Expected**:
- Usage is tracked on the parent node (not the paragraph entity)
- Alt text is correctly detected from the paragraph's media field
- Source label shows "(from media)" if using Media's alt text

### TC-USAGE-008d: Linked Image (Not Displayed)

1. Create content with a link to an image file: `<a href="/sites/default/files/image.jpg">Download Image</a>`
2. Run a scan
3. Navigate to usage page for the linked image

**Expected**:
- Alt text column shows "–" (em dash)
- Tooltip explains "This file is linked, not displayed as an image. Alt text does not apply."

### TC-USAGE-008e: Image Field (Non-Media) Alt Text

1. Create a content type with an Image field (not Media reference)
2. Upload an image directly with alt text "Direct upload alt"
3. Run a scan
4. Navigate to usage page for the image

**Expected**:
- Alt text column shows "Direct upload alt"
- Source label shows "(inline image)" indicating direct image field upload

### TC-USAGE-009: Alt Text Summary Strip

1. Use a media image in 5 different content nodes
2. Ensure 3 have alt text, 1 is missing alt, 1 is decorative
3. Navigate to usage page for the image

**Expected**:
- Summary strip shows below header, inline with middle dots (•):
  - "5 image usages • 3 with alt text • 1 missing alt • 1 decorative"
- Only non-zero counts are displayed (e.g., if 0 decorative, that item is omitted)

### TC-USAGE-010: Alt Text Summary Strip - Not Shown for Non-Images

1. Navigate to usage page for a PDF document

**Expected**: No alt text summary strip displayed

### TC-USAGE-011: Media Actions in Header - View Link

1. Navigate to usage page for a media-backed image
2. Click "View Media" in the asset info header

**Expected**: Opens the Media canonical view page

### TC-USAGE-012: Media Actions in Header - Edit Link Permission

1. Log in as user WITHOUT media edit permission
2. Navigate to usage page for a media-backed image

**Expected**: Only "View Media" link shown in header, no "Edit Media" link

3. Log in as user WITH media edit permission

**Expected**: Both "View Media" and "Edit Media" links shown in header

### TC-USAGE-013: Column Hiding - Non-Image Asset

1. Navigate to usage page for a PDF document

**Expected**:
- "Alt text" column is NOT visible

### TC-USAGE-014: Column Hiding - Non-Media Image

1. Navigate to usage page for a file-managed image (not media)

**Expected**:
- "Alt text" column IS visible
- No "View Media" / "Edit Media" links in header (not media-backed)

### TC-USAGE-015: Column Hiding - Media-Backed Image

1. Navigate to usage page for a media-backed image

**Expected**:
- "Alt text" column IS visible
- "View Media" / "Edit Media" links visible in header

### TC-USAGE-016: SVG Thumbnail Placeholder

1. Upload an SVG image via Media Library
2. Use in content and run scan
3. Navigate to usage page

**Expected**: SVG placeholder icon shown instead of rendered thumbnail

### TC-USAGE-017: Large File Thumbnail Placeholder

1. Upload image > 15MB
2. Use in content and run scan
3. Navigate to usage page

**Expected**: "Large file" placeholder shown instead of thumbnail

### TC-USAGE-018: Responsive Layout

1. Navigate to usage page for a media-backed image
2. Resize browser to mobile viewport (≤640px)

**Expected**:
- Thumbnail stacks above details (not side-by-side)
- Alt text summary strip list items stack vertically

---

## Configurable Archived Link Label

### TC-LABEL-001: Default Label Behavior

1. Enable archive feature
2. Archive a document
3. Add link to that document in content
4. View the page

**Expected**: Link shows "(Archived)" label after link text

### TC-LABEL-002: Disable Label

1. Go to `/admin/config/accessibility/digital-asset-inventory`
2. Uncheck "Show archived label on links"
3. Save settings
4. View page with archived link

**Expected**: Link routes to Archive Detail Page but no "(Archived)" label appears

### TC-LABEL-003: Custom Label Text

1. Go to settings, enable show archived label
2. Change label text to "Legacy"
3. Save settings
4. View page with archived link

**Expected**: Link shows "(Legacy)" instead of "(Archived)"

### TC-LABEL-004: Empty Label Validation

1. Go to settings, enable show archived label
2. Clear the label text field
3. Try to save

**Expected**: Validation error shown - label text required when labeling is enabled

---

## External URL Routing

### TC-EXTURL-001: Create External Archive

1. Go to `/admin/digital-asset-inventory/archive/add`
2. Select "External Resource" content type
3. Enter URL: `https://docs.google.com/document/d/abc123`
4. Fill in title and archive reason
5. Save

**Expected**: Archive entry created, original URL displayed on Archive Detail Page

### TC-EXTURL-002: External URL Link Routing

1. Create external archive for `https://example.com/doc`
2. Add link to that URL in content (via CKEditor)
3. View the page

**Expected**: Link routes to Archive Detail Page with "(Archived)" label

### TC-EXTURL-003: URL Normalization - Trailing Slash

1. Create external archive for `https://example.com/page`
2. Add link in content with trailing slash: `https://example.com/page/`
3. View the page

**Expected**: Link matches and routes to Archive Detail Page (normalization handles trailing slash)

### TC-EXTURL-004: URL Normalization - Case Insensitive

1. Create external archive for `https://example.com/Page`
2. Add link in content with different case: `https://EXAMPLE.COM/page`
3. View the page

**Expected**: Link matches and routes to Archive Detail Page (normalization handles case)

### TC-EXTURL-005: Archive Badge for External Assets

1. Run site scan to populate inventory
2. Note an external URL in inventory (Google Doc, etc.)
3. Create manual archive entry for that same URL
4. Clear cache and view inventory

**Expected**: "Archived (Public)" badge appears next to the external asset in inventory

### TC-EXTURL-006: Archive Detail Page Not Rewritten

1. Create external archive entry
2. Navigate to `/archive-registry/{id}` (the Archive Detail Page)
3. Check the "Source URL" link

**Expected**: Link shows "Visit Link" and points to original external URL (no "(Archived)" label)

---

## Embed Method Tracking

### TC-EMBED-001: Media Embed via CKEditor

1. Upload a video via Media Library
2. Create a content node
3. In CKEditor, use the media button to embed the video (`<drupal-media>`)
4. Run a scan
5. Navigate to usage page for the video

**Expected**:
- Usage record created with `embed_method='drupal_media'`
- Embed Type column shows "Media Embed"

### TC-EMBED-002: Field Reference

1. Create a content type with a media reference field
2. Upload a video via Media Library
3. Create a content node and reference the video in the media field
4. Run a scan
5. Navigate to usage page for the video

**Expected**:
- Usage record created with `embed_method='field_reference'`
- Embed Type column shows "Field Reference"

### TC-EMBED-003: Text Link

1. Upload a video file to `/sites/default/files/`
2. Create content with a text link: `<a href="/sites/default/files/video.mp4">Download Video</a>`
3. Run a scan
4. Navigate to usage page for the video

**Expected**:
- Usage record created with `embed_method='text_link'`
- Embed Type column shows "Text Link"

### TC-EMBED-004: Menu Link

1. Upload a video file
2. Create a menu link pointing to the video file
3. Run a scan
4. Navigate to usage page for the video

**Expected**:
- Usage record created with `embed_method='menu_link'`
- Embed Type column shows "Menu Link"

### TC-EMBED-005: HTML5 Video Tag

1. Create content with raw HTML5 video: `<video src="/sites/default/files/video.mp4" controls></video>`
2. Run a scan
3. Navigate to usage page for the video

**Expected**:
- Usage record created with `embed_method='html5_video'`
- Embed Type column shows "HTML5 Video"

### TC-EMBED-006: HTML5 Audio Tag

1. Create content with raw HTML5 audio: `<audio src="/sites/default/files/audio.mp3" controls></audio>`
2. Run a scan
3. Navigate to usage page for the audio

**Expected**:
- Usage record created with `embed_method='html5_audio'`
- Embed Type column shows "HTML5 Audio"

### TC-EMBED-007: Same File Multiple Embed Methods

1. Upload a video file
2. Create content that uses the video in three ways:
   - Media embed via CKEditor (`<drupal-media>`)
   - Text link (`<a href>`)
   - HTML5 video tag (`<video src>`)
3. Run a scan
4. Navigate to usage page for the video

**Expected**:
- Three usage records for the same content node
- Each shows different Embed Type: "Media Embed", "Text Link", "HTML5 Video"

### TC-EMBED-008: Embed Type Column Priority

1. Upload a video via Media Library and embed via CKEditor
2. Run a scan
3. Navigate to usage page for the video

**Expected**:
- Embed Type column shows "Media Embed" (not "Hosted Video")
- Column prioritizes `embed_method` over `presentation_type`

---

## Media Library Widget (Edit Forms)

### TC-MLW-001: Edit Form Loads Without Error

1. Create a node with an archived video embedded via `<drupal-media>`
2. Navigate to edit the node

**Expected**:
- Edit form loads correctly
- Media Library widget displays the selected media
- No JavaScript errors in console

### TC-MLW-002: Media Library Modal Opens

1. Create a node with an archived video embedded via media field
2. Edit the node
3. Click to change/edit the media in the media field

**Expected**:
- Media Library modal opens
- Grid of available media is displayed
- Can browse/search for media

### TC-MLW-003: Archived Media Visible in Grid

1. Archive a video
2. Open Media Library modal (via node edit form)
3. Navigate to the Video tab/section

**Expected**:
- Archived video is visible in the grid
- Archive placeholder displays with icon and message
- Placeholder shows archive date

### TC-MLW-004: Can Select Different Media

1. Create a node with archived media
2. Edit the node
3. Open Media Library modal
4. Select a different (non-archived) media item
5. Save

**Expected**:
- New media item is selected
- Node saves successfully
- New media displays on view

### TC-MLW-005: Archived Placeholder Not on Edit Form

1. Create a node with an archived video embedded via `<drupal-media>`
2. Navigate to edit the node
3. Look at the media field preview

**Expected**:
- Normal media preview shows (not archive placeholder)
- Media Library widget functions correctly
- Archive placeholder only shows in Media Library modal grid, not in form widget

### TC-MLW-006: Layout Builder Compatibility

1. Enable Layout Builder on a content type
2. Create a node with an archived media item
3. Enter Layout Builder edit mode

**Expected**:
- Layout Builder loads without error
- Media blocks display correctly
- Can edit media blocks without issues

---

## Uninstall

### TC-UNINST-001: Clean Uninstall

```bash
drush entity:delete digital_asset_archive -y
drush entity:delete digital_asset_usage -y
drush entity:delete digital_asset_item -y
drush pm:uninstall digital_asset_inventory -y
drush cr
```

**Expected**: No errors, clean uninstall
