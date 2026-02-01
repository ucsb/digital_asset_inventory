# Digital Asset Archival Workflow

This document describes the workflows for archiving digital assets, including
files (PDFs, documents, videos) and manual entries (web pages, external URLs)
for ADA Title II compliance.

## Table of Contents

- [Overview](#overview)
  - [Dual-Purpose Archive System](#dual-purpose-archive-system)
- [Status Reference](#status-reference)
  - [Archive Statuses](#archive-statuses)
  - [Warning Flags](#warning-flags)
- [Complete Status Transition Matrix](#complete-status-transition-matrix)
  - [File-Based Archives](#file-based-archives-documents--videos)
  - [Manual Entries](#manual-entries-web-pages--external-urls)
  - [Operations by Current Status](#operations-by-current-status)
  - [Status Lifecycle Diagram](#status-lifecycle-diagram)
- [Warning Scenarios Matrix](#warning-scenarios-matrix)
  - [Warning Flags by Scenario](#warning-flags-by-scenario)
  - [User Actions for Each Warning](#user-actions-for-each-warning)
- [ADA Compliance Matrix](#ada-compliance-matrix)
  - [Violation Status by Scenario](#violation-status-by-scenario)
  - [Compliance Violation Scenarios](#compliance-violation-scenarios)
- [Workflow Diagrams](#workflow-diagrams)
  - [Complete System Overview](#complete-system-overview)
  - [File-Based Asset Archival](#file-based-asset-archival-workflow)
  - [Manual Entry Archival](#manual-entry-archival-workflow)
- [Detailed Workflows](#detailed-workflows)
  - [File-Based Asset Archival (Two-Step Process)](#file-based-asset-archival-two-step-process)
  - [Manual Page/URL Archival (Direct Process)](#manual-pageurl-archival-direct-process)
  - [Post-Archive Behavior for Internal Pages](#post-archive-behavior-for-internal-pages)
- [Best Practices](#best-practices)

---

## Overview

The Digital Asset Inventory module provides two distinct archival workflows:

1. **File-Based Archival**: A two-step process for documents and videos tracked
   in the asset inventory (PDFs, Word, Excel, PowerPoint, videos).

2. **Manual Entry Archival**: A direct process for web pages and external
   resources not part of the file inventory.

Both workflows result in entries on the Archive Registry.

### Dual-Purpose Archive System

The archive system supports two types of archives based on when content was
archived relative to the ADA compliance deadline (default: April 24, 2026):

| Archive Type | When Created | Purpose | ADA Exemption | Badge Color |
|--------------|--------------|---------|---------------|-------------|
| **Legacy Archive** | Before deadline | ADA Title II compliance | Yes (if unmodified) | Blue |
| **General Archive** | After deadline | Reference/recordkeeping | No | Gray |

**Key Distinctions:**

- **Legacy Archives** qualify for ADA Title II accessibility exemption if kept
  unmodified for Reference, Research, or Recordkeeping purposes. Modification
  voids the exemption (status → `exemption_void`).

- **General Archives** are retained for reference purposes without claiming
  ADA exemption. If modified, they are removed from public view with audit flag
  (status → `archived_deleted` + `flag_integrity` for files, `flag_modified` for manual entries).

Archive type is determined automatically by `flag_late_archive`:

- `flag_late_archive = FALSE` → Legacy Archive
- `flag_late_archive = TRUE` → General Archive

---

## Status Reference

### Archive Statuses

The system uses 5 distinct statuses:

| Status | DB Value | Description | Public Visibility |
|--------|----------|-------------|-------------------|
| **Queued** | `queued` | Awaiting archive execution; file-based archives only (documents/videos) | No |
| **Archived (Public)** | `archived_public` | Active archive, visible to public | Yes |
| **Archived (Admin-Only)** | `archived_admin` | Active archive, admin-only | No |
| **Archived (Deleted)** | `archived_deleted` | Terminal state: file deleted (file-based), entry removed (manual), unarchived, or General Archive modified | No |
| **Exemption Void** | `exemption_void` | Terminal state: Legacy Archive modified after archiving; ADA exemption permanently voided | No |

**Important Notes:**

Both `archived_deleted` and `exemption_void` are **permanent terminal states** for the archive record:

- No operations available on the record
- No transitions to other statuses
- Record preserved for audit trail

| Terminal State | Record Status | New Entry Allowed? | New Entry Type |
|----------------|---------------|-------------------|----------------|
| `archived_deleted` | Terminal, no ops | Yes | Based on current date (Legacy or General) |
| `exemption_void` | Terminal, no ops | Yes | **Always General Archive** |

- Each archive action gets its own record with unique UUID for audit trail integrity
- The same file/URL may be archived again as a **new record** with new UUID, new timestamps, and new compliance history

**Re-Archive Rules (Compliance Protection):**

| Current Status | Can Reactivate Record? | Can Archive Same File/URL? | New Archive Type |
|----------------|------------------------|----------------------------|------------------|
| `archived_public` / `archived_admin` | — | — | Unarchive first (→ `archived_deleted`) |
| `archived_deleted` (file-based) | **No** | **Yes** (new record) | Based on current date |
| `archived_deleted` (manual entry) | **No** | **Yes** (new record) | Based on current date |
| `exemption_void` | **No** | **Yes** (new record) | **Always General Archive** |
| New file (file-based) | — | **Yes** | Based on current date |
| New manual entry (page/URL) | — | **Yes** | Based on current date |

**Important - Voided Exemptions Permanently Block Legacy Archive:**

Once a file/URL has an `exemption_void` record, it **permanently loses eligibility for Legacy Archive status**:

- The `exemption_void` record remains as immutable audit trail documenting the original violation
- New archive entries for the same file/URL are always classified as **General Archive**
- This applies even if archiving before the ADA compliance deadline
- The voided record and new General Archive are separate records with distinct UUIDs

**Why this policy?**

- Prevents gaming the system (can't void exemption then re-archive as Legacy)
- Maintains audit trail integrity (violation is permanently documented)
- Ensures compliance accountability (consequences persist)

**To archive a file/URL with a voided exemption:**

1. The "Queue for Archive" button (files) or "Add Manual Entry" (URLs) will be available
2. Create a **new archive entry** - it will be classified as General Archive
3. User receives a warning explaining why it's forced to General Archive
4. The original `exemption_void` record remains for audit

**Note:** Files/URLs with only `archived_deleted` records can be archived again with normal classification (Legacy or General based on current date).

### Warning Flags

Warning flags indicate conditions but do not change status automatically.
**Yes = Problem detected**.

| Flag | DB Field | Meaning | Applies To |
|------|----------|---------|------------|
| **Usage Detected** | `flag_usage` | Active content references this document | File-based (queued) |
| **File Deleted** | (via `deleted_date`) | File was intentionally deleted | File-based |
| **Integrity Issue** | `flag_integrity` | File checksum mismatch (modified) | File-based |
| **Modified** | `flag_modified` | Content modified after archiving | Manual entries |
| **Late Archive** | `flag_late_archive` | Archived after ADA deadline | All types |
| **Prior Exemption Voided** | `flag_prior_void` | Forced to General Archive due to prior voided exemption | All types |

---

## Complete Status Transition Matrix

### File-Based Archives (Documents & Videos)

| From Status | Action | To Status | Notes |
|-------------|--------|-----------|-------|
| (none) | Queue for Archive | `queued` | Step 1 of two-step workflow |
| `queued` | Execute Archive (Public) | `archived_public` | Step 2 - public visibility |
| `queued` | Execute Archive (Admin) | `archived_admin` | Step 2 - admin visibility |
| `queued` | Remove from Queue | (deleted) | Cancels pending archive, deletes record |
| `archived_public` | Toggle Visibility | `archived_admin` | Make admin-only |
| `archived_admin` | Toggle Visibility | `archived_public` | Make public |
| `archived_public` | Unarchive | `archived_deleted` | Remove from public view, preserve record |
| `archived_admin` | Unarchive | `archived_deleted` | Remove from registry, preserve record |
| `archived_public` | Delete File | `archived_deleted` | Physically delete file, preserve record |
| `archived_admin` | Delete File | `archived_deleted` | Physically delete file, preserve record |
| `archived_public` | Integrity Violation (Legacy) | `exemption_void` | Automatic: file checksum changed |
| `archived_admin` | Integrity Violation (Legacy) | `exemption_void` | Automatic: file checksum changed |
| `archived_public` | Integrity Violation (General) | `archived_deleted` | Automatic: file modified (no exemption) |
| `archived_admin` | Integrity Violation (General) | `archived_deleted` | Automatic: file modified (no exemption) |
| `exemption_void` | (none) | — | **Terminal state - no transitions out** |
| `archived_deleted` | (none) | — | **Terminal state - no transitions out** |

### Manual Entries (Web Pages & External URLs)

| From Status | Action | To Status | Notes |
|-------------|--------|-----------|-------|
| (none) | Add Manual Entry (Public) | `archived_public` | Direct archive with public visibility |
| (none) | Add Manual Entry (Admin) | `archived_admin` | Direct archive with admin visibility |
| `archived_public` | Toggle Visibility | `archived_admin` | Make admin-only |
| `archived_admin` | Toggle Visibility | `archived_public` | Make public |
| `archived_public` | Edit Entry | — | Update title, description, notes (no status change) |
| `archived_admin` | Edit Entry | — | Update title, description, notes (no status change) |
| `archived_public` | Remove Entry | `archived_deleted` | Remove from registry, preserve record |
| `archived_admin` | Remove Entry | `archived_deleted` | Remove from registry, preserve record |
| `archived_public` | Content Edited (Legacy) | `exemption_void` | Automatic: page content saved |
| `archived_admin` | Content Edited (Legacy) | `exemption_void` | Automatic: page content saved |
| `archived_public` | Content Edited (General) | `archived_deleted` | Automatic: page modified (no exemption) |
| `archived_admin` | Content Edited (General) | `archived_deleted` | Automatic: page modified (no exemption) |
| `exemption_void` | (none) | — | **Terminal state - no transitions out** |
| `archived_deleted` | (none) | — | **Terminal state - no transitions out** |

### Operations by Current Status

**File-Based Archives:**

| Status | Available Operations |
|--------|---------------------|
| **Queued** | Execute Archive, Remove from Queue |
| **Archived (Public)** | Toggle Visibility (→ Admin), Unarchive, Delete File |
| **Archived (Admin-Only)** | Toggle Visibility (→ Public), Unarchive, Delete File |
| **Exemption Void** | **None** (terminal state) |
| **Archived (Deleted)** | **None** (terminal state) |

**Manual Entries:**

| Status | Available Operations |
|--------|---------------------|
| **Archived (Public)** | Edit Entry, Toggle Visibility (→ Admin), Remove Entry |
| **Archived (Admin-Only)** | Edit Entry, Toggle Visibility (→ Public), Remove Entry |
| **Exemption Void** | **None** (terminal state) |
| **Archived (Deleted)** | **None** (terminal state) |

**Note:** Both `exemption_void` and `archived_deleted` are terminal states with no available operations. Records are preserved for audit trail purposes only.

### Status Lifecycle Diagram

```
                              ┌─────────────┐
                              │   (Start)   │
                              └──────┬──────┘
                                     │
                    ┌────────────────┴────────────────┐
                    │                                 │
                    ▼                                 ▼
           ┌────────────────┐                ┌────────────────┐
           │ File-Based     │                │ Manual Entry   │
           │ (Two-Step)     │                │ (Direct)       │
           └───────┬────────┘                └───────┬────────┘
                   │                                 │
                   ▼                                 │
           ╔════════════════╗                        │
           ║    QUEUED      ║                        │
           ╚═══════╤════════╝                        │
                   │                                 │
           ┌───────┴───────┐                         │
           │               │                         │
           ▼               ▼                         │
       ┌──────┐    ┌───────────────────────┐         │
       │Remove│    │    Execute Archive    │         │
       │from  │    │  (Select Visibility)  │◄────────┘
       │Queue │    └───────────┬───────────┘
       └──┬───┘           ┌────┴────┐
          │            Public    Admin
          ▼               │        │
      (Deleted)           ▼        ▼
                       ╔═════╗    ╔═════╗
                       ║PUBLC╠◄tg►╣ADMIN║
                       ╚══╤══╝    ╚══╤══╝
                          │         │
                          └────┬────┘
                              │
                 ┌────────────┼────────────┐
                 │            │            │
                 ▼            ▼            ▼
          (Legacy mod)  (Unarchive)  (General mod)
                 │            │            │
                 ▼            ▼            ▼
    ╔══════════════════════════════════════════════════════════╗
    ║                    TERMINAL STATES                       ║
    ╠═════════════════════════╦════════════════════════════════╣
    ║     EXEMPTION VOID      ║       ARCHIVED (DELETED)       ║
    ║  (Legacy Archive only)  ║  (General modified, unarchive, ║
    ║                         ║   delete file, remove entry)   ║
    ║  • ADA exemption lost   ║                                ║
    ║  • No operations        ║  • No operations               ║
    ║  • Audit trail only     ║  • Audit trail only            ║
    ║                         ║                                ║
    ║  Re-archive: new entry  ║  Re-archive: new entry         ║
    ║  FORCED General Archive ║  with new UUID                 ║
    ╚═════════════════════════╩════════════════════════════════╝
```

---

## Warning Scenarios Matrix

### Warning Flags by Scenario

| Scenario | Status | Late Archive | Usage | Integrity | Modified | File Deleted | Prior Void | ADA Violation |
|----------|--------|--------------|-------|-----------|----------|--------------|------------|---------------|
| File queued, no issues | `queued` | — | No | — | — | — | — | No |
| File queued, still in use | `queued` | — | **Yes** | — | — | — | — | No |
| Legacy archived, no issues | `archived_*` | No | No | No | — | No | No | No |
| General archived, no issues | `archived_*` | **Yes** | No | No | — | No | No | No |
| Legacy file modified after archiving | `exemption_void` | No | — | **Yes** | — | — | — | **YES** |
| General file modified | `archived_deleted` | Yes | — | **Yes** | — | — | — | No |
| Legacy page edited after archiving | `exemption_void` | No | — | — | — | — | — | **YES** |
| General page edited | `archived_deleted` | Yes | — | — | **Yes** | — | — | No |
| File intentionally deleted | `archived_deleted` | — | — | — | — | **Yes** | — | No |
| File unarchived | `archived_deleted` | — | — | — | — | No | — | No |
| Manual entry removed | `archived_deleted` | — | — | — | — | — | — | No |
| Re-archived with prior voided exemption | `archived_*` | **Yes** | — | — | — | — | **Yes** | No |

### User Actions for Each Warning

| Warning | Displayed Badge | Recommended Action |
|---------|-----------------|-------------------|
| **Usage Detected** | "Usage Detected" (yellow) | Remove file references from content, re-scan, then execute archive |
| **Integrity Issue** | "Integrity Issue" (red) | Investigate file modification; if intentional, unarchive and create new entry |
| **Modified** | "Modified" (orange) | Advisory for General Archives; no action required |
| **Late Archive** | "Late Archive" (gray) | Advisory only; indicates General Archive classification |
| **File Deleted** | "File Deleted" (gray) | No action; record preserved for audit |
| **Exemption Voided** | "Exemption Voided" (red) | **ADA VIOLATION** - Legacy Archive was modified; remediate content to meet WCAG 2.1 AA or unarchive |
| **Prior Exemption Voided** | "Prior Void" (gray) | Advisory; file/URL had a previous voided exemption and was forced to General Archive |

---

## ADA Compliance Matrix

### Violation Status by Scenario

| Scenario | Entry Type | Archive Type | ADA Exemption Status | Violation Status |
|----------|------------|--------------|---------------------|------------------|
| Archived before deadline, unmodified | Both | Legacy | Valid | None |
| File modified after archiving (checksum changed) | File | Legacy | Voided | **VIOLATION** |
| Page content edited after archiving | Manual | Legacy | Voided | **VIOLATION** |
| Archived after deadline, unmodified | Both | General | Not claimed | None |
| File modified (checksum changed) | File | General | Not claimed | None (modification tracked) |
| Page content edited | Manual | General | Not claimed | None (modification tracked) |
| Re-archived with prior voided exemption | Both | General (forced) | Not claimed | None (flag_prior_void set) |
| Queued but not executed | File | — | Not yet claimed | None |
| Unarchived / Deleted | Both | — | Not applicable | None |

### Compliance Violation Scenarios

These scenarios represent ADA Title II compliance violations that require immediate attention:

#### Violation 1: Legacy Archive File Modified

```
┌─────────────────────────────────────────────────────────────────────────┐
│  ❌ ADA COMPLIANCE VIOLATION                                            │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  Scenario: A Legacy Archive (pre-deadline) file was modified after     │
│            being archived.                                              │
│                                                                         │
│  Detection: Automatic via checksum verification during reconciliation   │
│                                                                         │
│  System Response:                                                       │
│    • Status changes to: exemption_void                                  │
│    • flag_integrity set to: TRUE                                        │
│    • "Exemption Voided" badge displayed                                 │
│    • Removed from public Archive Registry                               │
│                                                                         │
│  Required Action:                                                       │
│    1. Investigate the file modification                                 │
│    2. Either:                                                           │
│       a. Remediate the document to meet WCAG 2.1 AA standards, OR      │
│       b. Restore the original unmodified file and create new archive   │
│    3. If remediated, content no longer needs archive exemption         │
│                                                                         │
│  Legal Implication:                                                     │
│    Modified content must meet current accessibility standards.          │
│    The archive exemption is permanently voided for this record.         │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

#### Violation 2: Legacy Archive Page Edited

```
┌─────────────────────────────────────────────────────────────────────────┐
│  ❌ ADA COMPLIANCE VIOLATION                                            │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  Scenario: A Legacy Archive manual entry (internal page) was edited    │
│            after being archived.                                        │
│                                                                         │
│  Detection: Automatic via hook_entity_update when content is saved      │
│                                                                         │
│  System Response:                                                       │
│    • Status changes to: exemption_void                                  │
│    • Internal note added with modification timestamp                    │
│    • "Exemption Voided" badge displayed                                 │
│    • Removed from public Archive Registry                               │
│    • User sees warning message                                          │
│                                                                         │
│  Required Action:                                                       │
│    1. Review the content changes made                                   │
│    2. Either:                                                           │
│       a. Remediate the page to meet WCAG 2.1 AA standards, OR          │
│       b. Revert changes and create new archive entry if needed         │
│    3. Document the remediation in internal notes                        │
│                                                                         │
│  Prevention:                                                            │
│    • Edit protection warning appears before saving                      │
│    • User must acknowledge checkbox to proceed                          │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

#### Non-Violation: General Archive Modified

```
┌─────────────────────────────────────────────────────────────────────────┐
│  ✅ NOT A COMPLIANCE VIOLATION                                          │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  Scenario: A General Archive (post-deadline) was modified.              │
│                                                                         │
│  Why Not a Violation:                                                   │
│    General Archives do not claim ADA exemption. They are retained       │
│    for reference purposes only. No accessibility exemption exists       │
│    to be voided.                                                        │
│                                                                         │
│  System Response:                                                       │
│    • Status changes to: archived_deleted                                │
│    • flag_modified (manual) or flag_integrity (file) set to TRUE        │
│    • "Modified" badge displayed for audit tracking                      │
│    • Removed from public Archive Registry                               │
│                                                                         │
│  Recommended Action:                                                    │
│    • No immediate action required                                       │
│    • Review during quarterly audit                                      │
│    • Create new archive entry if content should remain archived         │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

### ADA Compliance Decision Tree

```
                        ┌─────────────────────────┐
                        │ Is content archived?    │
                        └───────────┬─────────────┘
                               ┌────┴────┐
                              No        Yes
                               │         │
                               ▼         ▼
                    ┌──────────────┐  ┌─────────────────────────┐
                    │ Must meet    │  │ Was it archived BEFORE  │
                    │ WCAG 2.1 AA  │  │ the ADA deadline?       │
                    └──────────────┘  └───────────┬─────────────┘
                                             ┌────┴────┐
                                            No        Yes
                                             │         │
                                             ▼         ▼
                              ┌──────────────────┐  ┌─────────────────────────┐
                              │ GENERAL ARCHIVE  │  │ Has content been        │
                              │ No exemption     │  │ modified since archiving│
                              │ claimed          │  └───────────┬─────────────┘
                              │                  │         ┌────┴────┐
                              │ If modified:     │        No        Yes
                              │ → archived_      │         │         │
                              │   deleted        │         ▼         ▼
                              │ → flag for audit │  ┌────────────┐  ┌────────────────┐
                              │ → NO violation   │  │ LEGACY     │  │ ❌ VIOLATION   │
                              └──────────────────┘  │ ARCHIVE    │  │                │
                                                    │            │  │ Exemption      │
                                                    │ Exemption  │  │ VOIDED         │
                                                    │ VALID      │  │                │
                                                    │            │  │ Must remediate │
                                                    │ ✅ OK      │  │ or restore     │
                                                    └────────────┘  └────────────────┘
```

---

## Workflow Diagrams

### Complete System Overview

```
                        ╔═══════════════════════════════════════╗
                        ║         DIGITAL ASSET ARCHIVE         ║
                        ║            SYSTEM WORKFLOW            ║
                        ╚═══════════════════════════════════════╝
                                        │
                ┌───────────────────────┴────────────────────────┐
                │                                                │
                ▼                                                ▼
    ┌─────────────────────────────┐            ┌─────────────────────────────┐
    │   Digital Asset Inventory   │            │    Archive Management       │
    │ /admin/digital-asset-       │            │ /admin/digital-asset-       │
    │       inventory             │            │   inventory/archive         │
    └──────────────┬──────────────┘            └──────────────┬──────────────┘
                   │                                          │
                   ▼                                          ▼
    ╔══════════════════════════════╗            ╔══════════════════════════════╗
    ║   FILE-BASED ARCHIVAL        ║            ║   MANUAL ENTRY ARCHIVAL      ║
    ║     (Two-Step Process)       ║            ║      (Direct Process)        ║
    ╚══════════════════════════════╝            ╚══════════════════════════════╝
                   │                                          │
                   │                                          │
         ┌─────────┴─────────┐                      ┌─────────┴─────────┐
         ▼                   ▼                      ▼                   ▼
    ┌─────────┐         ┌─────────┐          ┌──────────┐        ┌──────────┐
    │ Step 1: │         │ Step 2: │          │ Add      │        │ Create   │
    │  Queue  │────────►│ Execute │          │ Manual   │───────►│ Archive  │
    │         │         │ Archive │          │ Entry    │        │ Record   │
    └─────────┘         └─────────┘          └──────────┘        └──────────┘
         │                   │                      │                   │
         │                   │                      │                   │
         └───────────────────┴──────────────────────┴───────────────────┘
                                        │
                                        ▼
                         ╔══════════════════════════════╗
                         ║    SELECT VISIBILITY         ║
                         ╚══════════════════════════════╝
                                        │
                          ┌─────────────┴─────────────┐
                          │                           │
                          ▼                           ▼
                 ┌─────────────────┐         ┌─────────────────┐
                 │ ARCHIVED        │         │ ARCHIVED        │
                 │ (PUBLIC)        │         │ (ADMIN-ONLY)    │
                 └────────┬────────┘         └────────┬────────┘
                          │                           │
                          └──────────┬────────────────┘
                                     │
                                     ▼
                      ┌──────────────────────────────────┐
                      │  ARCHIVE TYPE DETERMINED BY DATE │
                      └──────────────┬───────────────────┘
                                ┌────┴────┐
                         Before         After
                         Deadline       Deadline
                                │             │
                                ▼             ▼
                      ┌──────────────┐  ┌──────────────┐
                      │ LEGACY       │  │ GENERAL      │
                      │ ARCHIVE      │  │ ARCHIVE      │
                      │              │  │              │
                      │ • ADA exempt │  │ • No exempt  │
                      │ • Blue badge │  │ • Gray badge │
                      └──────────────┘  └──────────────┘
```

### File-Based Asset Archival Workflow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    STEP 1: QUEUE FOR ARCHIVE                                 │
│                    Location: /admin/digital-asset-inventory                  │
└─────────────────────────────────────────────────────────────────────────────┘

                              ┌──────────────┐
                              │    START     │
                              │ User selects │
                              │    asset     │
                              └──────┬───────┘
                                     │
                                     ▼
                         ┌───────────────────────┐
                         │  Is Document or Video │
                         │  (PDF, Word, Excel,   │
                         │   PowerPoint, Video)? │
                         └───────────┬───────────┘
                                ┌────┴────┐
                               No        Yes
                                │         │
                                ▼         ▼
                    ┌───────────────┐  ┌───────────────────┐
                    │ ✗ Cannot      │  │ Has Active        │
                    │   Archive     │  │ Archive Record?   │
                    │   This Type   │  └─────────┬─────────┘
                    └───────────────┘       ┌────┴────┐
                                          Yes       No
                                           │         │
                                           ▼         ▼
                               ┌───────────────┐  ┌───────────────┐
                               │ ✗ Already Has │  │ Display       │
                               │   Active      │  │ Archive Form  │
                               │   Archive     │  └───────┬───────┘
                               └───────────────┘          │
                                                          ▼
                              ┌────────────────────────────────────────────────┐
                              │              ARCHIVE FORM                       │
                              ├────────────────────────────────────────────────┤
                              │                                                │
                              │  Archive Type Notice:                          │
                              │  ┌────────────────────────────────────────┐    │
                              │  │ Before deadline: "Legacy Archive"     │    │
                              │  │ After deadline:  "General Archive"    │    │
                              │  └────────────────────────────────────────┘    │
                              │                                                │
                              │  Archive Reason (required):                    │
                              │  ○ Reference  ○ Research                       │
                              │  ○ Recordkeeping  ○ Other                      │
                              │                                                │
                              │  Public Description (required, 20+ chars)      │
                              │  Internal Notes (optional)                     │
                              │                                                │
                              │  ┌─────────────────┐  ┌─────────────────┐      │
                              │  │ Queue for       │  │ Cancel          │      │
                              │  │ Archive         │  │                 │      │
                              │  └────────┬────────┘  └─────────────────┘      │
                              └───────────┼────────────────────────────────────┘
                                          │
                                          ▼
                              ┌────────────────────────┐
                              │  Create Archive Record │
                              │  Status: QUEUED        │
                              └────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────────┐
│                    STEP 2: EXECUTE ARCHIVE                                   │
│                    Location: /admin/digital-asset-inventory/archive          │
└─────────────────────────────────────────────────────────────────────────────┘

                              ┌──────────────┐
                              │    START     │
                              │ Review Queue │
                              └──────┬───────┘
                                     │
                                     ▼
                         ┌───────────────────────┐
                         │  Usage Count = 0?     │
                         │  (No content refs)    │
                         └───────────┬───────────┘
                                ┌────┴────┐
                               No        Yes
                                │         │
                                ▼         │
        ┌───────────────────────────────────────────┐      │
        │  ⚠ USAGE DETECTED - Blocks Archive        │      │
        ├───────────────────────────────────────────┤      │
        │  1. View usage locations                  │      │
        │  2. Edit content to remove references     │      │
        │  3. Re-run Digital Asset Inventory scan   │      │
        │  4. Return to Archive Management          │      │
        └─────────────────────┬─────────────────────┘      │
                              │ (Loop until usage = 0)     │
                              └────────────────────────────┤
                                                           │
                                                           ▼
                              ┌────────────────────────────────────────────────┐
                              │              EXECUTE ARCHIVE FORM               │
                              ├────────────────────────────────────────────────┤
                              │  Select Visibility:                            │
                              │  ○ Public - Visible at /archive-registry       │
                              │  ○ Admin-only - Admin management only          │
                              │                                                │
                              │  ┌─────────────────┐  ┌─────────────────┐      │
                              │  │ Archive Now     │  │ Cancel          │      │
                              │  └────────┬────────┘  └─────────────────┘      │
                              └───────────┼────────────────────────────────────┘
                                          │
                                          ▼
                              ┌────────────────────────┐
                              │  System Actions:       │
                              │  • Capture SHA-256     │
                              │  • Set classification  │
                              │    date (immutable)    │
                              │  • Check ADA deadline  │
                              │  • Set late flag if    │
                              │    after deadline      │
                              └───────────┬────────────┘
                                          │
                                          ▼
                              ┌────────────────────────┐
                              │  ✓ ARCHIVE COMPLETE    │
                              └────────────────────────┘
```

### Manual Entry Archival Workflow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    MANUAL ENTRY ARCHIVAL (Direct Process)                    │
│                    Location: /admin/digital-asset-inventory/archive          │
└─────────────────────────────────────────────────────────────────────────────┘

                              ┌──────────────┐
                              │    START     │
                              │ Add Manual   │
                              │ Entry        │
                              └──────┬───────┘
                                     │
                                     ▼
              ┌────────────────────────────────────────────────────────────────┐
              │                    MANUAL ARCHIVE FORM                          │
              ├────────────────────────────────────────────────────────────────┤
              │                                                                │
              │  Archive Type Notice:                                          │
              │  ┌────────────────────────────────────────────────────────┐    │
              │  │ Before deadline: "Legacy Archive - ADA exempt eligible"│    │
              │  │ After deadline:  "General Archive - reference only"   │    │
              │  └────────────────────────────────────────────────────────┘    │
              │                                                                │
              │  Title (required): Descriptive title                           │
              │                                                                │
              │  URL (required):                                               │
              │  ┌────────────────────────────────────────────────────────┐    │
              │  │ node/123, /about-us, or https://example.com/page      │    │
              │  └────────────────────────────────────────────────────────┘    │
              │  ⚠ File URLs (pdf, doc, mp4, etc.) are BLOCKED               │
              │                                                                │
              │  Content Type: ○ Web Page  ○ External Resource                 │
              │  Archive Reason: ○ Reference  ○ Research  ○ Recordkeeping     │
              │  Public Description (required, 20+ chars)                      │
              │  Internal Notes (optional)                                     │
              │  Visibility: ○ Public  ○ Admin-only                           │
              │                                                                │
              │  ┌───────────────────────┐  ┌─────────────────┐                │
              │  │ Add to Archive        │  │ Cancel          │                │
              │  └───────────┬───────────┘  └─────────────────┘                │
              └──────────────┼─────────────────────────────────────────────────┘
                             │
                             ▼
              ┌────────────────────────────┐
              │  Validate URL              │
              │  • Resolve internal paths  │
              │  • Block file URLs         │
              │  • Block duplicates        │
              └─────────────┬──────────────┘
                       ┌────┴────┐
                    Invalid    Valid
                       │         │
                       ▼         ▼
              ┌────────────┐  ┌────────────────────────┐
              │ ✗ Error    │  │ Create Archive Record  │
              │   Message  │  │ Status: archived_*     │
              └────────────┘  │ (No queue step)        │
                              └────────────────────────┘
```

---

## Detailed Workflows

### File-Based Asset Archival (Two-Step Process)

This workflow applies to documents and videos tracked in the Digital Asset
Inventory: PDFs, Word documents, Excel spreadsheets, PowerPoint presentations,
and video files.

#### Step 1: Queue for Archive

**Location**: `/admin/digital-asset-inventory`

1. **Navigate** to the Digital Asset Inventory
2. **Locate** the document to archive
3. **Click** "Queue for Archive" button
4. **Complete** the archive form:
   - **Archive Reason** (required): Reference, Research, Recordkeeping, or Other
   - **Public Description** (required): Minimum 20 characters
   - **Internal Notes** (optional): Admin-only notes
5. **Submit** to create a queued archive record

**Validation Checks:**

- Asset must be a document or video type
- Asset must not have an active archive record (queued, archived, or voided)
- Archive reason must be selected
- Public description must be provided

**Result**: Archive record created with status "Queued"

#### Step 2: Execute Archive

**Location**: `/admin/digital-asset-inventory/archive`

**Prerequisites**: Asset must have zero usage (no content references)

1. **Navigate** to Archive Management
2. **Review** queued items
3. **If usage detected** (blocks archive):
   - View usage locations via "View usage" link
   - Edit content to remove or update file references
   - Run a new Digital Asset Inventory scan
   - Return to Archive Management
4. **When usage = 0**, click "Archive Asset"
5. **Select visibility**: Public or Admin-only
6. **Confirm** to execute the archive

**System Actions:**

- Captures SHA-256 file checksum for integrity monitoring
- Records immutable archive classification date
- Sets `flag_late_archive` if after ADA compliance deadline
- Updates status to `archived_public` or `archived_admin`

### Manual Page/URL Archival (Direct Process)

This workflow applies to web pages and external resources not tracked in the
file inventory.

**Location**: `/admin/digital-asset-inventory/archive` → "Add Manual Entry"

1. **Navigate** to Archive Management
2. **Click** "Add Manual Entry" button
3. **Complete** the manual archive form
4. **Submit** to create the archive record directly (no queue step)

**URL Validation:**

- Internal paths (`node/123`) are converted to absolute URLs
- **Blocked URLs:**
  - File URLs (pdf, doc, docx, xls, xlsx, ppt, pptx, mp4, webm, mov, avi)
  - Media entity paths (`/media/123`)
  - Duplicate URLs already in active archive

**Result**: Archive record created directly with archived status

### Post-Archive Behavior for Internal Pages

When an internal page (Web Page type) is archived, additional safeguards apply:

#### Archived Content Banner

An "Archived Material" banner automatically appears at the top of archived pages.

**Banner behavior:**

- Appears on full page views only (not teasers, search results)
- Uses `#prefix` to ensure it appears before all page content
- Cache tags ensure immediate display after archiving

#### Edit Protection

When editing archived content, warnings and acknowledgment are required.

**Warning messages by archive type:**

| Archive Type | Warning Message |
|--------------|-----------------|
| Legacy Archive | "This content is currently recorded as archived for ADA Title II purposes. If you save changes, it will no longer qualify as archived/exempt and must meet current WCAG 2.1 AA accessibility requirements." |
| General Archive | "This content is currently archived. If you save changes, it will be flagged as modified in the Archive Registry for audit tracking purposes." |

#### Automatic Status Change on Edit

When archived content is saved:

| Archive Type | New Status | Flag Set | ADA Implication |
|--------------|------------|----------|-----------------|
| Legacy Archive | `exemption_void` | — | **VIOLATION** - must remediate |
| General Archive | `archived_deleted` | `flag_modified` | No violation - audit trail only |

---

## Best Practices

### Before Archiving

1. **Verify content is truly obsolete** - Ensure the document/page is no longer
   actively needed
2. **Check for active usage** - Use the inventory to see where the file is
   referenced
3. **Remove or update references** - Remove file from content or replace with
   disclaimer
4. **Run a fresh scan** - Re-scan assets to confirm usage count is zero

### Archive Description Guidelines

Write public descriptions that:

- Explain why the content is archived
- State when the content was created (if known)
- Note what the content covers
- Provide contact information for accessibility requests

**Example:**
> This 2023 Annual Report has been archived for recordkeeping purposes. It
> contains financial and operational data from fiscal year 2022-2023. If you
> require an accessible version, please contact <finance@example.edu>.

### Regular Maintenance

| Frequency | Task |
|-----------|------|
| Weekly | Review queued items and execute archives with zero usage |
| Monthly | Run integrity checks to detect modified files |
| Quarterly | Export archive audit CSV for compliance records |
| Annually | Review archive registry for items that may need removal |

### Handling Compliance Violations

When an "Exemption Voided" badge appears:

1. **Investigate immediately** - Determine what was modified and why
2. **Document the finding** - Add internal notes about the modification
3. **Choose a resolution**:
   - **Remediate**: Make content meet WCAG 2.1 AA standards
   - **Restore**: If modification was accidental, restore original and create new archive
   - **Remove**: Unarchive if content is no longer needed
4. **Update records** - Document the resolution in internal notes

---

## Related Documentation

- [README.md](../../README.md) - Documentation index
- [quick-reference-guide.md](../../guidance/quick-reference-guide.md) - User guide for scanning, archiving, troubleshooting
- [test-cases.md](../../testing/test-cases.md) - Manual test cases

### Specification Files

- [archive-ux-spec-index.md](archive-ux-spec-index.md) - UX specifications index
- [archive-invariants.md](archive-invariants.md) - Critical constraints
- [archive-audit-safeguards-spec.md](archive-audit-safeguards-spec.md) - Audit trail requirements
- [archive-feature-toggle-spec.md](archive-feature-toggle-spec.md) - Archive enable/disable feature
- [archive-registry-public-page-spec.md](archive-registry-public-page-spec.md) - Public registry page spec
- [archive-registry-detail-page-spec.md](archive-registry-detail-page-spec.md) - Detail page spec
- [dual-purpose-archive-spec.md](dual-purpose-archive-spec.md) - Dual-purpose archive specification
