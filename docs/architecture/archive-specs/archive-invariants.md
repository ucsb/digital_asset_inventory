# Archive Audit Invariants (ADA Title II)

These rules apply to ALL archive-related code in the Digital Asset Inventory module.
They MUST NOT be violated by any contributor.

## Core Invariants

1. Archive records are permanent audit artifacts  
   - `digital_asset_archive` records MUST NOT be automatically deleted
   - File deletion MUST NOT delete or invalidate archive records

2. Archive Classification Date is immutable  
   - Set ONLY during archive execution (Step 2)
   - MUST NOT be edited, recalculated, inferred, or backdated
   - MUST NOT be changed by scans, updates, or migrations

3. Archive registry is authoritative  
   - Inventory scans MUST NOT reclassify archived assets as active
   - Scan results MUST preserve archive status and badges

4. Public archive is NOT the system of record  
   - Admin Archive Management is the authoritative audit source
   - Public archive pages are informational only

5. Deleted archived files must not appear publicly  
   - Deleted files MUST NOT appear in `/archive-registry` listings
   - Deleted files MUST NOT expose download links

6. File deletion ≠ compliance decision  
   - Compliance is determined by Archive Classification Date
   - Deletion date does NOT affect ADA compliance status

7. No silent or inferred archiving
   - Archive classification MUST be an explicit user action
   - Scans MUST NOT auto-archive assets

8. Modified Legacy Archive content loses exemption
   - If file integrity check fails for a Legacy Archive, status MUST change to `exemption_void`
   - Voided documents MUST NOT appear in public Archive Registry
   - `exemption_void` is a terminal state - no operations available
   - Files/URLs with voided exemptions can be re-archived, but MUST be classified as General Archive (never Legacy)

9. Terminal states are permanent
   - `archived_deleted` and `exemption_void` are terminal states with no transitions out
   - Records are preserved for audit trail purposes
   - New archive entries can be created for the same file/URL (distinct records with unique UUIDs)

## Required Mental Model

- Archive = compliance classification
- Deletion = lifecycle management
- Archive record = legal evidence

Developers MUST read and respect this document before modifying:
- Archive entities
- Archive services
- Public archive views
- Scan logic

## Implementation Status

All invariants are enforced in the current codebase:
- `DigitalAssetArchive::preSave()` - Immutability enforcement
- `ArchiveService` - All lifecycle transitions
- `ArchiveService::reconcileStatus()` - Automatic exemption void on integrity failure (Legacy Archives)
- `ArchiveService::hasVoidedExemptionByFid()` - Checks if file has voided exemption (forces General Archive)
- `ManualArchiveForm::hasVoidedExemption()` - Checks if URL has voided exemption (forces General Archive)
- `views.view.public_archive.yml` - Filters out deleted/admin-only/voided
- Scanner preserves archive status across re-runs
