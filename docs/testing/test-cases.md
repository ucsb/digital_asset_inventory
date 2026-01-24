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

### TC-SCAN-004: Scan Failure Preserves Data

1. Run a successful scan and verify assets are displayed with usage counts
2. Note the current asset count and usage records
3. Temporarily add code to force scan failure (throw exception after processing 1 item)
4. Run scan again - it should fail with error
5. Navigate to inventory

**Expected**: Previous inventory data is preserved (same assets and usage counts as step 2). Scan failure should not wipe out existing data.

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

**Expected**: Status: "Archived (Admin)", NOT visible at `/archive-registry`

### TC-ARCH-004: Toggle Visibility

1. Find archived document (Public or Admin)
2. Click "Make Admin-only" or "Make Public"

**Expected**: Status toggles between "Archived (Public)" and "Archived (Admin)"

### TC-ARCH-005: Archive Blocked by Usage

1. Queue a document
2. Reference document in content
3. Run scan
4. Try to execute archive

**Expected**: "Usage Detected" warning, archive blocked

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
- Appears in Archive Management with status "Archived (Admin)"
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

---

## CSV Exports

### TC-CSV-001: Inventory Export

1. Navigate to `/admin/digital-asset-inventory`
2. Click "Download Report (csv)"

**Expected**: CSV with columns: File Name, File Path, Asset Type,
Category, MIME Type, File Size, Used In

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

Test each filter: Category, Asset Type, Source Type, File Storage, In Use

### TC-VIEW-002: Archive Filters

Filter by Archive Type: Legacy Archive, General Archive

Filter by Status: Queued, Archived (Public), Archived (Admin),
Archived (Deleted), Exemption Void

Filter by Asset Type: Documents, Videos, Web Pages, External Resources

Filter by Purpose: Reference, Research, Recordkeeping, Other

### TC-VIEW-003: Empty State

- No scan yet: Filters hidden, "Last Scan: Never" shown
- Filters return nothing: Warning message displayed

### TC-VIEW-004: Responsive Tables

1. View inventory on mobile viewport (< 768px)

**Expected**: Tables stack vertically using Tablesaw responsive mode

### TC-VIEW-004a: Usage Detail Page

1. Navigate to `/admin/digital-asset-inventory`
2. Click on the "Used In" count for any asset with usage > 0

**Expected**:
- Asset info header displays at top with bordered box containing:
  - Asset name (media title + filename in parentheses for media files)
  - File type, size, source separated by `|` dividers
  - Clickable file URL
- Usage table shows columns: Content Title, Content Type, Field Name, Required
- Content Title links to the content page (opens in same window)
- Field Name shows actual field label (e.g., "Hero Image", not "media")
- Required shows "Yes" or "No" based on field configuration
- "Back to Inventory" link appears at bottom

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

1. Log in as user without `archive digital assets`
2. Navigate to Archive Management

**Expected**: Access denied

### TC-PERM-004: Admin Permission

1. Log in as user with `administer digital assets`
2. Navigate to `/admin/config/accessibility/digital-asset-inventory`

**Expected**: Settings page accessible

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
