# Status Transition & Test Case Matrix

Digital Asset Inventory Module - Comprehensive Testing Reference

---

## Configuration Flags

| Flag | Purpose | Default | Impact |
|------|---------|---------|--------|
| `enable_archive` | Master toggle for archive feature | TRUE | When FALSE: all archive routes return 403, menu links hidden, link routing disabled |
| `allow_archive_in_use` | Policy gate for archiving in-use assets | TRUE | When FALSE: blocks NEW archives for in-use assets only; existing archives unaffected |

**Key Principle:** `enable_archive` controls the entire feature; `allow_archive_in_use` controls only the policy gate for creating new archives.

---

## Status Values

| Status | DB Value | Terminal? | Public Visibility | Operations Available |
|--------|----------|-----------|-------------------|---------------------|
| Queued | `queued` | No | No | Execute, Remove from Queue |
| Archived (Public) | `archived_public` | No | Yes | Toggle Visibility, Unarchive, Delete File/Remove Entry |
| Archived (Admin-Only) | `archived_admin` | No | No | Toggle Visibility, Unarchive, Delete File/Remove Entry |
| Archived (Deleted) | `archived_deleted` | **Yes** | No | **None** |
| Exemption Void | `exemption_void` | **Yes** | No | **None** |

---

## Warning Flags

| Flag | Field | Meaning | Applies To | Badge Color |
|------|-------|---------|------------|-------------|
| Usage Detected | `flag_usage` | Active content references this document | File-based | Red |
| Blocked | (computed) | Execution blocked by policy | Queued + in use + config disabled | Red |
| File Deleted | `deleted_date` | File was intentionally deleted | File-based | Gray |
| Integrity Issue | `flag_integrity` | File checksum mismatch | File-based | Red |
| Modified | `flag_modified` | Content modified after archiving | Manual entries | Orange |
| Late Archive | `flag_late_archive` | Archived after ADA deadline | All | Gray |
| Prior Void | `flag_prior_void` | Forced to General due to prior voided exemption | All | Gray |

---

## Complete Status Transition Matrix

### File-Based Archives (Documents & Videos)

| # | From Status | Action | To Status | Conditions | Notes |
|---|-------------|--------|-----------|------------|-------|
| F1 | (none) | Queue for Archive | `queued` | Asset is document/video, no active archive | Step 1 |
| F2 | `queued` | Execute Archive (Public) | `archived_public` | Usage = 0 OR `allow_archive_in_use` = TRUE | Step 2 |
| F3 | `queued` | Execute Archive (Admin) | `archived_admin` | Usage = 0 OR `allow_archive_in_use` = TRUE | Step 2 |
| F4 | `queued` | Remove from Queue | (deleted) | Always allowed | Deletes record |
| F5 | `archived_public` | Toggle Visibility | `archived_admin` | Always allowed | |
| F6 | `archived_admin` | Toggle Visibility | `archived_public` | Usage = 0 OR `allow_archive_in_use` = TRUE | Blocked if in use + config disabled |
| F7 | `archived_public` | Unarchive | `archived_deleted` | Always allowed | Corrective action |
| F8 | `archived_admin` | Unarchive | `archived_deleted` | Always allowed | Corrective action |
| F9 | `archived_public` | Delete File | `archived_deleted` | Always allowed | Physical deletion |
| F10 | `archived_admin` | Delete File | `archived_deleted` | Always allowed | Physical deletion |
| F11 | `archived_public` | Integrity Violation (Legacy) | `exemption_void` | Automatic | Checksum changed |
| F12 | `archived_admin` | Integrity Violation (Legacy) | `exemption_void` | Automatic | Checksum changed |
| F13 | `archived_public` | Integrity Violation (General) | `archived_deleted` | Automatic | `flag_integrity` = TRUE |
| F14 | `archived_admin` | Integrity Violation (General) | `archived_deleted` | Automatic | `flag_integrity` = TRUE |
| F15 | `exemption_void` | Any | — | **Blocked** | Terminal state |
| F16 | `archived_deleted` | Any | — | **Blocked** | Terminal state |

### Manual Entries (Web Pages & External URLs)

| # | From Status | Action | To Status | Conditions | Notes |
|---|-------------|--------|-----------|------------|-------|
| M1 | (none) | Add Manual Entry (Public) | `archived_public` | URL valid, not file URL | Direct archive |
| M2 | (none) | Add Manual Entry (Admin) | `archived_admin` | URL valid, not file URL | Direct archive |
| M3 | `archived_public` | Toggle Visibility | `archived_admin` | Always allowed | |
| M4 | `archived_admin` | Toggle Visibility | `archived_public` | Always allowed | No usage gate for manual |
| M5 | `archived_public` | Edit Entry | `archived_public` | Always allowed | Update metadata only |
| M6 | `archived_admin` | Edit Entry | `archived_admin` | Always allowed | Update metadata only |
| M7 | `archived_public` | Remove Entry | `archived_deleted` | Always allowed | Preserves record |
| M8 | `archived_admin` | Remove Entry | `archived_deleted` | Always allowed | Preserves record |
| M9 | `archived_public` | Content Edited (Legacy) | `exemption_void` | Automatic | Via hook_entity_update |
| M10 | `archived_admin` | Content Edited (Legacy) | `exemption_void` | Automatic | Via hook_entity_update |
| M11 | `archived_public` | Content Edited (General) | `archived_deleted` | Automatic | `flag_modified` = TRUE |
| M12 | `archived_admin` | Content Edited (General) | `archived_deleted` | Automatic | `flag_modified` = TRUE |
| M13 | `exemption_void` | Any | — | **Blocked** | Terminal state |
| M14 | `archived_deleted` | Any | — | **Blocked** | Terminal state |

---

## Configuration + Usage Test Matrix

### `enable_archive` = FALSE (Feature Disabled)

| Test | Action | Expected Result |
|------|--------|-----------------|
| EA-1 | Access `/admin/digital-asset-inventory/archive` | 403 Forbidden |
| EA-2 | Access `/archive-registry` | 403 Forbidden |
| EA-3 | Access archive detail page | 403 Forbidden |
| EA-4 | View inventory page | No "Queue for Archive" buttons |
| EA-5 | Admin menu | No Archive menu item |
| EA-6 | Links to archived files | Direct file delivery (no routing) |

### `enable_archive` = TRUE, `allow_archive_in_use` = FALSE

| Test | State | Action | Expected Result |
|------|-------|--------|-----------------|
| AIU-1 | File not in use | Queue for Archive | ✅ Allowed |
| AIU-2 | File not in use | Execute Archive | ✅ Allowed |
| AIU-3 | Queued, now in use | Execute Archive | ❌ Blocked, "Blocked" badge |
| AIU-4 | Queued, now not in use | Execute Archive | ✅ Allowed |
| AIU-5 | Archived, in use | Unarchive | ✅ Allowed (corrective) |
| AIU-6 | Archived (Admin), in use | Make Public | ❌ Blocked |
| AIU-7 | Archived (Public), in use | Make Admin-only | ✅ Allowed (corrective) |
| AIU-8 | Archived, in use | Link routing | ✅ Routes to Archive Detail Page |
| AIU-9 | Manual entry | Any action | ✅ Bypasses usage gate |

**Note:** Queue button is hidden in inventory UI when file is in use. Form also redirects with error if URL accessed directly.

### `enable_archive` = TRUE, `allow_archive_in_use` = TRUE

| Test | State | Action | Expected Result |
|------|-------|--------|-----------------|
| AIU-20 | File in use | Queue for Archive | ✅ Allowed with warning |
| AIU-21 | File in use | Execute Archive | ✅ Allowed with warning |
| AIU-22 | Queued, in use | Execute Archive | ✅ Allowed with confirmation checkbox |
| AIU-23 | Archived, in use | Toggle Visibility | ✅ Allowed |
| AIU-24 | Archived, in use | Unarchive | ✅ Allowed |

---

## Archive Type Test Matrix

| Test | Archive Timing | Archive Type | Badge | ADA Exemption |
|------|----------------|--------------|-------|---------------|
| AT-1 | Before deadline | Legacy Archive | Blue | Yes (if unmodified) |
| AT-2 | After deadline | General Archive | Gray | No |
| AT-3 | Before deadline, prior void | General Archive (forced) | Gray + "Prior Void" | No |
| AT-4 | After deadline, prior void | General Archive | Gray + "Prior Void" | No |

### Modification Behavior by Archive Type

| Test | Archive Type | Entry Type | Modification Event | New Status | Flag Set |
|------|--------------|------------|-------------------|------------|----------|
| MOD-1 | Legacy | File | Checksum changed | `exemption_void` | `flag_integrity` |
| MOD-2 | Legacy | Manual | Content edited | `exemption_void` | — |
| MOD-3 | General | File | Checksum changed | `archived_deleted` | `flag_integrity` |
| MOD-4 | General | Manual | Content edited | `archived_deleted` | `flag_modified` |

---

## Re-Archive Test Matrix

| Test | Previous Status | New Entry Allowed? | New Entry Type |
|------|-----------------|-------------------|----------------|
| RA-1 | `archived_public` | No (must unarchive first) | — |
| RA-2 | `archived_admin` | No (must unarchive first) | — |
| RA-3 | `archived_deleted` (before deadline) | Yes | Legacy Archive |
| RA-4 | `archived_deleted` (after deadline) | Yes | General Archive |
| RA-5 | `exemption_void` (before deadline) | Yes | **General Archive (forced)** |
| RA-6 | `exemption_void` (after deadline) | Yes | General Archive |

---

## Edge Case Test Matrix

| EC# | Scenario | Initial State | Config Change | Action | Expected |
|-----|----------|---------------|---------------|--------|----------|
| EC1 | Unarchive after config disabled | Archived, in use | `allow_archive_in_use` → FALSE | Unarchive | ✅ Allowed + warning about re-archive |
| EC2 | Execute queued after config disabled | Queued, in use | `allow_archive_in_use` → FALSE | Execute | ❌ Blocked + "Blocked" badge |
| EC3 | Usage detected after queue | Queued (was not in use) | Rescan detects usage | Execute | ❌ Blocked (if config disabled) |
| EC6 | Unarchive while blocked | Archived, in use, config disabled | — | Unarchive | ✅ Allowed + warning |
| EC7 | Make Public while blocked | Archived (Admin), in use, config disabled | — | Make Public | ❌ Blocked |
| EC8 | Re-enable config | Previously blocked | `allow_archive_in_use` → TRUE | Archive | ✅ Allowed |
| EC9 | Manual entry | Any | Any | Archive | ✅ Bypasses usage gate |
| EC11 | Make Admin-only while blocked | Archived (Public), in use, config disabled | — | Make Admin-only | ✅ Allowed (corrective) |

---

## Link Routing Test Matrix

| Test | Archive Status | `enable_archive` | Link Source | Expected Display |
|------|----------------|------------------|-------------|------------------|
| LR-1 | `archived_public` | TRUE | CKEditor link | "Text (Archived)" → Archive Detail |
| LR-2 | `archived_public` | TRUE | File field | "filename (Archived)" → Archive Detail |
| LR-3 | `archived_public` | TRUE | Media embed (public) | "Name (Archived)" inline link |
| LR-4 | `archived_public` | TRUE | Media Library UI | Full placeholder with icon/date |
| LR-5 | `archived_public` | TRUE | Menu link | "Title (Archived)" → Archive Detail |
| LR-6 | `archived_public` | TRUE | Breadcrumb | "Text (Archived)" → Archive Detail |
| LR-7 | `archived_admin` | TRUE | Any | Same routing (admin-only affects disclosure) |
| LR-8 | `archived_deleted` | TRUE | Any | Direct file delivery (no routing) |
| LR-9 | `exemption_void` | TRUE | Any | Direct file delivery (no routing) |
| LR-10 | `archived_public` | FALSE | Any | Direct file delivery (feature disabled) |

---

## Warning Badge Display Matrix

| Status | Usage | Config Allows | Integrity | Modified | Late | Prior Void | Deleted | Badges Shown |
|--------|-------|---------------|-----------|----------|------|------------|---------|--------------|
| `queued` | Yes | Yes | — | — | — | — | — | Usage Detected |
| `queued` | Yes | No | — | — | — | — | — | Blocked, Usage Detected |
| `queued` | No | — | — | — | — | — | — | (none) |
| `archived_*` | Yes | — | No | — | No | No | — | Usage Detected |
| `archived_*` | Yes | — | No | — | Yes | No | — | Usage Detected, Late Archive |
| `archived_*` | No | — | Yes | — | — | — | — | Integrity Issue |
| `archived_*` | No | — | — | Yes | — | — | — | Modified |
| `archived_*` | No | — | — | — | — | Yes | — | Prior Void |
| `archived_deleted` | — | — | — | — | — | — | Yes | File Deleted |
| `exemption_void` | — | — | — | — | — | — | — | (status indicates violation) |

---

## Admin-Only Visibility Test Matrix

| Test | Status | User | Shows Metadata | Shows File URL | Shows Actions |
|------|--------|------|----------------|----------------|---------------|
| AO-1 | `archived_public` | Anonymous | Full | Yes | No |
| AO-2 | `archived_public` | Admin | Full | Yes | Yes |
| AO-3 | `archived_admin` | Anonymous | Limited | **No** | No |
| AO-4 | `archived_admin` | Admin | Full | Yes | Yes |
| AO-5 | `archived_admin` (private file) | Anonymous | Limited + "requires authentication" | No | No |
| AO-6 | `archived_admin` (public file) | Anonymous | Limited + "contact accessibility" | No | No |

---

## Identified Workflow Issues

### Issue 1: Blocked Badge Only Shows for Queued Items
**Current:** "Blocked" badge only displays for queued items when in use + config disabled.
**Status:** Working as designed - blocked state only applies to pending executions.

### Issue 2: Usage Flag vs Current Usage
**Current:** `flag_usage` is set at archive time and updated on rescan. `archived_while_in_use` is immutable.
**Status:** Working as designed - two separate concerns (historical vs current).

### Issue 3: Terminal States Have No Operations
**Current:** `archived_deleted` and `exemption_void` records show no action buttons.
**Status:** Correct - terminal states preserve audit trail only.

### Issue 4: Make Public Blocked When In Use + Config Disabled
**Current:** EC7 blocks visibility change Admin → Public when in use + config disabled.
**Status:** Correct - prevents public exposure of in-use content when policy restricts it.

### Issue 5: Make Admin-Only Always Allowed
**Current:** EC11 allows Public → Admin-only even when in use + config disabled.
**Status:** Correct - reducing visibility is a corrective action.

### Issue 6: Link Routing Independent of `allow_archive_in_use`
**Current:** Links always route to Archive Detail when `enable_archive` = TRUE.
**Status:** Correct - architectural separation ensures consistent UX.

---

## Test Execution Checklist

### Basic Status Transitions
- [ ] F1-F4: Queue workflow (queue, execute, remove)
- [ ] F5-F6: Visibility toggle
- [ ] F7-F10: Unarchive and Delete File
- [ ] F11-F14: Integrity violations (Legacy vs General)
- [ ] F15-F16: Terminal states block operations

### Manual Entry Workflow
- [ ] M1-M2: Add manual entry
- [ ] M3-M4: Visibility toggle (no usage gate)
- [ ] M5-M6: Edit entry
- [ ] M7-M8: Remove entry
- [ ] M9-M12: Content modification (Legacy vs General)
- [ ] M13-M14: Terminal states

### Configuration Tests
- [ ] EA-1 to EA-6: Feature disabled tests
- [ ] AIU-1 to AIU-11: `allow_archive_in_use` = FALSE
- [ ] AIU-20 to AIU-24: `allow_archive_in_use` = TRUE

### Edge Cases
- [ ] EC1, EC6: Unarchive always allowed
- [ ] EC2, EC3: Blocked execution scenarios
- [ ] EC7: Make Public blocked
- [ ] EC8: Re-enable config
- [ ] EC9: Manual entry bypasses usage
- [ ] EC11: Make Admin-only allowed

### Link Routing
- [ ] LR-1 to LR-6: Various link sources
- [ ] LR-7: Admin-only routing
- [ ] LR-8, LR-9: Terminal states no routing
- [ ] LR-10: Feature disabled no routing
