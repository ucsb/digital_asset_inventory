# Archive Audit Safeguards – Spec

Digital Asset Inventory Module (ADA Title II)

This spec defines archive behaviors required to protect the institution during audits, complaints, or legal review under ADA Title II.

## 1. Purpose

Ensure that archived digital assets:
- Are defensibly exempt from WCAG 2.1 AA when appropriate
- Remain auditable even after physical deletion
- Are not reintroduced as compliance risks during re-scans

## 2. Definitions

- **Archive Record Created Date**
  The timestamp when the archive record was first created.
  For file-based archives: set during Step 1 (Queue for Archive).
  For manual entries: set when the entry is created.
  Represents the *intent* to archive.

- **Archive Classification Date**
  The immutable timestamp when an asset is formally designated as archived.
  For file-based archives: set during Step 2 (Execute Archive).
  For manual entries: set immediately (same as created date).
  Represents the *formal compliance decision point*.
  Used to determine Legacy vs General Archive (compared against ADA deadline).

- **Public Archive Registry**
  End-user listing at `/archive-registry` and `/archive-registry/{id}`.

- **Archive Management**  
  Administrative system of record at `/admin/digital-asset-inventory/archive`.

## 3. Requirements

### 3.1 Archive Record Retention
- MUST retain archive records permanently
- MUST assign a unique immutable archive UUID
- SHOULD retain deletion metadata when files are removed

### 3.2 Archive Classification Date
- MUST be set only when archive is executed (not when queued)
- MUST be immutable once set
- MUST NOT be derived from scans or file metadata
- MUST be used for determining Legacy vs General Archive type

**Why two dates matter:**
Items can sit in "queued" status while waiting for usage to be removed. Auditors need to know when the formal compliance decision was made (Classification Date), not just when someone started the process (Created Date).

Example timeline:
```
Jan 15: User queues document     → Created Date = Jan 15
Jan 20: User executes archive    → Classification Date = Jan 20
```

### 3.3 Lifecycle Status Model
The system MUST support these states:
- Queued
- Archived (Public)
- Archived (Admin-Only)
- Archived (Deleted) - terminal state
- Exemption Void - terminal state (Legacy Archive modified)

Only explicit, logged transitions are allowed.

**Terminal states:** `Archived (Deleted)` and `Exemption Void` have no transitions out. Records are preserved for audit trail. New entries can be created for the same file/URL with unique UUIDs.

### 3.4 Public vs Admin Behavior
- Deleted archived files MUST NOT appear publicly
- Deleted archived files MUST remain visible in admin archive management
- Public archive MUST NOT serve as audit evidence

### 3.5 Scan Re-Run Behavior
- Archive registry MUST override scan results
- Archived assets MUST:
  - show archive badges
  - be excluded from remediation prompts
  - suppress missing-file errors if deleted

### 3.6 Warnings (Exception Only)
Warnings MUST NOT change status:
- Archived after ADA deadline
- Accessibility request pending
- Public visibility inconsistent

### 3.7 Audit Export
- SHOULD provide archive-only CSV export
- Export MUST include:
  - Archive ID (UUID)
  - Name (filename or title)
  - Asset Type (PDF, Word, Excel, Web Page, External, etc.)
  - Archive Type (Legacy Archive or General Archive)
  - Archive Classification Date
  - Current Archive Status
  - Archived By (username)
  - File Deletion Date (if applicable)
  - File Deleted By (if applicable)
  - Reason for Archive Classification
  - Public Archive Description
  - File Checksum (SHA-256) - N/A for manual entries
  - Warning flags with descriptive values:
    - Integrity Issue Detected
    - Active Usage Detected
    - File Missing
    - Late Archive
    - Prior Exemption Voided
    - Exemption Voided / Modified
  - Original URL
  - Archive Reference Path
  - Archive Record Created Date
- All dates MUST use ISO 8601 format (e.g., `2025-12-29T10:22:41-08:00`)

## 4. Design

### 4.1 Archive Entity Fields (Required)
- archive_uuid (immutable)
- original_filename
- mime_type
- source_type
- purpose
- queued_by
- queued_date
- archived_by
- archive_classification_date (immutable)
- status
- public_visibility
- file_id / file_uri (nullable)
- deleted_date (nullable)
- deleted_by (nullable)

### 4.2 Status Rules
- Archive Classification Date set ONLY on Queued → Archived
- Archived (Deleted) implies:
  - no public visibility
  - no download links
- Deletion NEVER changes classification date

### 4.3 Public Archive Rules
`/archive-registry` MUST list ONLY:
- Archived (Public)
- File exists

Deleted files MUST NOT appear or be linkable.

### 4.4 Archive Management Rules
Admin view MUST:
- Show all records, including deleted
- Rename columns:
  - Archived Date → Archive Classification Date
  - Queued Date → Archive Queued Date
- Display clear status badges and warnings

### 4.5 Scan Integration Rules
When scanning:
- Match assets against archive registry
- Annotate inventory rows with archive state
- Disable remediation prompts for archived assets
- Suppress missing-file warnings for Archived (Deleted)

## 5. Implementation Status

All tasks have been implemented:

- Archive entity with immutable UUID and classification date
- 5-status lifecycle (queued, archived_public, archived_admin, archived_deleted, exemption_void)
- Terminal states enforced (archived_deleted, exemption_void) - no transitions out
- Immutability enforced in entity preSave()
- ArchiveService handles all lifecycle transitions
- Voided exemption re-archive policy: files/URLs with exemption_void are forced to General Archive
- Public archive filters out deleted, admin-only, and voided records
- Admin archive management with status badges and warning flags
- Archive audit CSV export at `/admin/digital-asset-inventory/archive/csv`

## 6. Compliance Principle (One Sentence)

Compliance is determined by the Archive Classification Date, not by file deletion timing.
