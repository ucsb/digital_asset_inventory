# Scan Resilience Specification

## Overview

Add phase-level checkpointing and concurrency protection to the Digital Asset Inventory scanner so that interrupted scans can resume from the last completed phase, and concurrent scans are prevented.

**Scope**: `ScanAssetsForm`, `DigitalAssetScanner`, `digital_asset_inventory.services.yml`
**No new database tables or entities required.**

---

## Requirements

### REQ-001: Phase-Level Scan Resumability

**Type:** Event-driven
**Statement:** When a scan is interrupted (browser close, PHP timeout, user cancellation) after one or more phases have completed, the system shall allow the user to resume the scan from the next incomplete phase.
**Rationale:** A full rescan of 4000+ assets takes 30-60 minutes. Restarting from scratch after a failure at phase 3 of 5 wastes the work already done in phases 1-2.
**Acceptance Criteria:**

- [ ] Checkpoint is saved to Drupal State after each phase completes
- [ ] Scan form detects existing checkpoint and offers "Resume Scan" option
- [ ] Resumed scan skips already-completed phases and runs only remaining phases
- [ ] Temp items from completed phases are preserved across resume
- [ ] Final `promoteTemporaryItems()` runs only after ALL phases complete
- [ ] Checkpoint state is cleared after successful scan completion
- [ ] "Start Fresh Scan" option clears checkpoint + temp items and runs full scan
- [ ] Each scan phase enforces INV-1 (find-or-create idempotency) so that re-running a phase after partial completion produces no duplicate temp rows

### REQ-002: Scan Concurrency Protection

**Type:** State-driven
**Statement:** While a scan is in progress, the system shall prevent a second scan from starting and shall display a warning to the user.
**Rationale:** Two concurrent scans would interleave `is_temp=TRUE` items, and the last scan to call `promoteTemporaryItems()` would delete the other's results, producing an incomplete inventory.
**Acceptance Criteria:**

- [ ] Lock is acquired before scan batch starts
- [ ] Lock is released after scan completes (success or failure)
- [ ] Second user attempting to scan sees warning message
- [ ] Scan button is disabled while lock is held
- [ ] Lock has a timeout (2 hours) to prevent permanent lockout from crashed scans

### REQ-003: Memory Management for Large Scans

**Type:** Ubiquitous
**Statement:** The system shall reset entity static caches and Drupal static variables between scan chunks to prevent memory exhaustion during large scans.
**Rationale:** Drupal's entity storage caches every loaded entity in memory, and internal static variables accumulate over long batch runs. Processing 4000+ items in a single Batch API session can exhaust PHP's memory limit even though individual chunks are small.
**Acceptance Criteria:**

- [ ] Entity caches are reset after each `scan*Chunk()` call completes (all entity types loaded during that phase)
- [ ] `drupal_static_reset()` is called after each chunk to clear accumulated static variables
- [ ] Memory usage stays bounded (does not grow linearly with asset count)
- [ ] No functional impact on scan results

### REQ-004: Checkpoint Integrity Validation

**Type:** Event-driven
**Statement:** When a checkpoint exists and the user chooses to resume or finalize, the system shall validate that temporary scan data still exists in the database and has not been partially corrupted before proceeding.
**Rationale:** If temp items were manually deleted or partially removed (database cleanup, module reinstall), resuming would produce a silently incomplete inventory. For a compliance-critical module, silent incompleteness is unacceptable.
**Acceptance Criteria:**

- [ ] On resume or finalize, system counts both `digital_asset_item` and `digital_asset_usage` rows where `is_temp=TRUE`
- [ ] Current item count is compared against `temp_item_count` stored at checkpoint time
- [ ] Current usage count is compared against `temp_usage_count` stored at checkpoint time
- [ ] Validation is mode-aware: resume checks item count only (usage is recoverable via Phase 5 re-run); finalize checks both item and usage counts (no phases re-run)
- [ ] If item count is zero, system shows warning and blocks both resume and finalize
- [ ] If item count is less than stored count, system shows warning about partial data loss and blocks both resume and finalize
- [ ] If usage count is less than stored count during finalize, system blocks finalize and directs user to "Start Fresh Scan"
- [ ] Warning message directs user to "Start Fresh Scan" instead

**Note**: For resume mode, item-count validation is sufficient because remaining phases (including Phase 5) will regenerate usage rows. For finalize mode, both counts must pass because no phases re-run -- missing usage rows would produce an inventory with assets but no usage tracking.

### REQ-005: Finalize After All Phases Complete

**Type:** Event-driven
**Statement:** When a checkpoint exists with `phase == 5` and `phase5_complete == TRUE` (all phases completed) but `promoteTemporaryItems()` never ran (crash between final phase and `batchFinished()`), the system shall offer to finalize the scan without re-running any phases.
**Rationale:** If all 5 phases completed successfully but the atomic swap never executed, re-running phases wastes time and is unnecessary. The system should recognize this state and proceed directly to promotion.
**Acceptance Criteria:**

- [ ] Finalize requires `phase == 5` AND `phase5_complete == TRUE` (explicit completion signal, not phase number alone)
- [ ] Finalize requires integrity validation to pass for both item and usage counts (mode=finalize)
- [ ] If checkpoint phase is 5 but `phase5_complete` is not TRUE, treat as incomplete Phase 5 and offer "Resume Scan" (re-runs Phase 5)
- [ ] Clicking "Finalize Scan" runs only `promoteTemporaryItems()` and clears checkpoint
- [ ] No batch phases are re-executed

---

## Invariants

These invariants must hold for scan resilience to work correctly. They are verified by the existing codebase and must be preserved in any future refactoring.

### INV-1: Phase Idempotency

Each phase MUST implement "find-or-create then update" semantics against `is_temp=TRUE` rows using stable lookup keys. Re-running a phase on the same data updates existing temp rows rather than creating duplicates.

**Verified lookup keys by phase:**

| Phase | Lookup Key | Condition |
| ----- | ---------- | --------- |
| 1 (Managed Files) | `fid` | `is_temp=TRUE` |
| 2 (Orphan Files) | `url_hash` + `source_type='filesystem_only'` | `is_temp=TRUE` |
| 3 (Content) | `url_hash` + `source_type='external'` | `is_temp=TRUE` |
| 4 (Remote Media) | `url_hash` (based on `media:ID`) + `source_type='media_managed'` | `is_temp=TRUE` |
| 5 (Menu Links) | Usage rows keyed by `asset_id` + host entity identity + usage context discriminator fields (per schema) | Parent item is located with `is_temp=TRUE` |

**Phase 5 note**: Phase 5 does not create new `digital_asset_item` rows. It creates `digital_asset_usage` rows for existing temp items found by `fid` (Phase 1) or `url_hash` + `source_type` (Phases 2-4). Usage rows MUST use a find-or-create pattern keyed by `asset_id` + host entity identity (e.g., `entity_type`, `entity_id`) plus any additional discriminator fields defined by the usage schema (e.g., `field_name`, `source`, `context`, `path`, or similar). Re-running Phase 5 MUST update existing usage rows rather than duplicating them.

**Code evidence**: All `findOrCreate*` methods and `scanManagedFilesChunk()` query for existing temp rows before creating new ones. If a match is found, the existing row is updated in place. This means partial-phase residue from a crashed phase is safely overwritten when that phase re-runs on resume.

### INV-2: Promote Safety Under Duplicates

`promoteTemporaryItems()` performs two operations:

1. Delete all rows where `is_temp=0` (old inventory)
2. Set `is_temp=FALSE` on all rows where `is_temp=TRUE` (new inventory)

If duplicate temp rows exist (theoretically possible if INV-1 is violated), they would become duplicate active rows. However, INV-1 prevents this scenario in practice. This invariant documents the dependency: **promote correctness depends on phase idempotency**.

### INV-3: Single Batch Operation Per Phase

Each phase is implemented as a single Drupal Batch API operation with internal chunking via `$context['sandbox']`. The `$context['finished'] >= 1` checkpoint trigger depends on this structure. If a phase is later refactored into multiple batch operations, the checkpoint logic must move to a phase-level finished callback.

### INV-4: Checkpoint Monotonicity

The checkpoint phase number must never decrease within a scan session. `saveCheckpoint()` enforces this internally -- callers never reason about monotonicity.

**Update rules** (all enforced inside `saveCheckpoint()`):

- `$phase > stored_phase`: Update phase and counts. If `$phase < 5`: set `phase5_complete = FALSE` (ignore caller). If `$phase == 5`: require caller `TRUE`, else ignore and log warning (per rule below).
- `$phase == stored_phase`: Update counts only. `phase5_complete` is **sticky TRUE** -- once set to TRUE, it cannot be flipped back to FALSE by a same-phase update.
- `$phase == 5 && $phase5_complete == FALSE`: Ignore and log warning. No state mutation. Phase 5 must always pass TRUE.
- `$phase < stored_phase`: Ignore and log warning. No state mutation.
- `$phase < 1 || $phase > 5`: Ignore and log warning. No state mutation.
- No `session_id` in State: Ignore and log warning. No state mutation.

**Note**: `saveCheckpoint()` does not update `started` -- that timestamp is written once at scan start and never changes. Only `phase`, `temp_item_count`, `temp_usage_count`, and `phase5_complete` are updated per checkpoint.

**Session scoping**: Monotonicity is scoped to a scan session identified by `dai.scan.checkpoint.session_id`. "Active session ID" means the current value of `dai.scan.checkpoint.session_id` in State at the time `saveCheckpoint()` executes. `saveCheckpoint()` refuses to write if the active session ID does not match the stored session ID, or if no session ID exists in State (prevents orphan checkpoint writes outside the intended scan lifecycle).

### INV-5: Lock Does Not Imply Checkpoint Validity

The scan lock prevents concurrency, but resume/finalize eligibility is determined solely by checkpoint state and integrity validation. A lock expiring (e.g., after a crash) does not invalidate the checkpoint or temp rows. Conversely, holding a lock does not guarantee a valid checkpoint exists. These are independent concerns: the lock guards against concurrent writes, while checkpoint + integrity validation guards against data completeness.

---

## Design

### Checkpoint Storage (Drupal State API)

The checkpoint stores only lightweight metadata. The temporary database rows (`is_temp=TRUE`) serve as the source of truth for completed phase data -- no batch `$results` are persisted to State.

| State Key | Type | Purpose |
| --------- | ---- | ------- |
| `dai.scan.checkpoint.session_id` | `string` | Random identifier for the current scan session (set on scan start) |
| `dai.scan.checkpoint.phase` | `int` or `NULL` | Last fully completed phase number (1-5) |
| `dai.scan.checkpoint.started` | `int` (timestamp) | When the current/interrupted scan started |
| `dai.scan.checkpoint.temp_item_count` | `int` | Count of temp `digital_asset_item` rows at checkpoint time |
| `dai.scan.checkpoint.temp_usage_count` | `int` | Count of temp `digital_asset_usage` rows at checkpoint time |
| `dai.scan.checkpoint.phase5_complete` | `bool` | `TRUE` only when Phase 5 completion checkpoint is written (sticky -- never reverts to FALSE) |
| `dai.scan.lock.heartbeat` | `int` (timestamp) | Last batch chunk activity; used for stale lock detection (set on acquire, updated per chunk, cleared on release) |

**Design decision**: Batch `$context['results']` are NOT saved to State. On a 4000+ asset site, accumulated results could be megabytes of serialized data, causing DB bloat, serialization overhead, and slow resume unserialization. The temp DB rows already contain everything needed. The `batchFinished()` callback reconstructs summary counts from the database on both normal completion and resumed completion.

**`temp_usage_count` behavior**: Both counts are stored at every checkpoint. Before Phase 5 completes, `temp_usage_count` reflects usage rows created so far by earlier phases (which may be 0 if only item-creating phases have run). Finalize validation uses `temp_usage_count` only when `phase5_complete == TRUE`, ensuring the count is meaningful.

### Phase Map

| Phase # | Batch Method | Name (for UI) |
| ------- | ------------ | ------------- |
| 1 | `batchProcessManagedFiles` | Managed Files |
| 2 | `batchProcessOrphanFiles` | Orphan Files |
| 3 | `batchProcessContent` | Content (External URLs) |
| 4 | `batchProcessMediaEntities` | Remote Media |
| 5 | `batchProcessMenuLinks` | Menu Links |

### Checkpoint Flow

```text
Normal scan (no checkpoint):
  Phase 1 → checkpoint(1) → Phase 2 → checkpoint(2)
  → ... → Phase 5 → checkpoint(5, phase5_complete=TRUE)
  → promoteTemporaryItems() → clear all checkpoints → done

Interrupted scan (failure during Phase 3):
  Phase 1 → checkpoint(1) → Phase 2 → checkpoint(2)
  → Phase 3 → FAILURE
  → batchFinished(success=FALSE) → release lock
  → temp items from phases 1-2 remain in DB (is_temp=TRUE)
  → checkpoint state: phase=2, temp_item_count=N, temp_usage_count=M

Resumed scan (phases remaining):
  User visits scan form → sees "Resume from Phase 3"
  → validateCheckpointIntegrity(mode='resume') — checks item count
  → IF item count lost → block resume, show warning
  → submitForm('resume') → build batch with phases 3, 4, 5 only
  → Phase 3 → checkpoint(3) → Phase 4 → checkpoint(4)
  → Phase 5 → checkpoint(5, phase5_complete=TRUE)
  → promoteTemporaryItems() → clear all checkpoints → done

Finalize scan (all phases complete, promote never ran):
  Phase 5 completed → checkpoint(5, phase5_complete=TRUE) → CRASH
  → User visits scan form → sees "Finalize Scan"
  → validateCheckpointIntegrity(mode='finalize') — checks item AND usage counts
  → IF any count lost → block finalize, show warning
  → submitForm('finalize') → promoteTemporaryItems() → clear checkpoint → done
```

### Lock Design

- **Lock name**: `digital_asset_inventory_scan`
- **Lock backend**: Drupal's persistent lock service (`@lock.persistent`)
- **Why persistent**: The standard `@lock` service auto-releases all locks in `LockBackendAbstract::__destruct()` when the PHP process ends. Since `submitForm()` acquires the lock and then redirects to the batch progress page (a separate HTTP request), the lock would be released before the batch even starts. `@lock.persistent` (`PersistentDatabaseLockBackend`) stores locks in the `semaphore` table and does NOT auto-release on `__destruct()`, so the lock survives across the form submission request and subsequent batch AJAX requests.
- **Timeout**: 7200 seconds (2 hours) -- this is a failsafe for crashed processes, not expected runtime. Scans typically complete in 30-60 minutes on large sites; the 2-hour ceiling provides buffer for server load, AJAX stalls, or editor delays
- **Acquisition**: In `ScanAssetsForm::submitForm()` before any state mutation or `batch_set()`
- **Release**: In `batchFinished()` for batch scans, and in the finalize path for "Finalize Scan" submissions (both success and failure, exception-safe via `try/finally`)

#### Scan State Definitions

| State | Condition | Meaning |
| ----- | --------- | ------- |
| **Active scan** | Lock held AND `isScanLockStale() == FALSE` | A batch is actively processing chunks |
| **Interrupted scan** | Lock held AND `isScanLockStale() == TRUE` | Scan was abandoned (user navigated away, PHP crash) |
| **No scan** | Lock not held | No scan in progress |

**Separation of concerns**: Checkpoint determines resume/finalize eligibility; lock determines concurrency. These are independent — a lock can exist without a checkpoint (crash before first phase completes), and a checkpoint can exist without a lock (lock expired or was released on failure).

**Heartbeat-based stale lock detection**: The 2-hour TTL is a worst-case failsafe. For abandoned scans (user navigated away, browser closed), a heartbeat mechanism provides much faster recovery:

- **Heartbeat key**: `dai.scan.lock.heartbeat` (Drupal State)
- **Updated**: On lock acquisition (`acquireScanLock()`), at the **start** of every batch chunk (before work), and at the **end** of every batch chunk (after work)
- **Staleness threshold**: `SCAN_LOCK_STALE_THRESHOLD = 120` seconds (2 minutes)
- **Detection**: `isScanLockStale()` uses 3-tier check: (1) heartbeat exists → compare to threshold, (2) no heartbeat but `checkpoint.started` exists → compare to threshold (grace window for startup), (3) neither exists → orphan lock, stale
- **Recovery**: `breakStaleLock()` force-deletes the `semaphore` row and clears the heartbeat, with guardrails (lock must be held + must be stale) and forensic logging

**buildForm() is read-only**: `buildForm()` detects stale vs active locks and shows appropriate UI but NEVER breaks locks. This prevents two tabs passively racing to break each other's lock just by viewing the page. Only `submitForm()` calls `breakStaleLock()`.

**Critical constraint**: Lock acquisition MUST occur before any state mutation. If the lock cannot be acquired, `submitForm()` must check staleness, break if stale and retry, or return immediately without modifying State, building batch operations, or calling `batch_set()`.

```php
// Lock is the FIRST authoritative guard — nothing else runs if this fails.
if (!$this->scanner->acquireScanLock()) {
  // Check if this is a stale lock from an abandoned scan.
  if ($this->scanner->isScanLockStale()) {
    $this->scanner->breakStaleLock();
    // Retry acquisition after breaking stale lock.
    if (!$this->scanner->acquireScanLock()) {
      // Another user grabbed it between break and acquire (race).
      $this->messenger()->addError(...);
      $form_state->setRebuild();
      return;
    }
  }
  else {
    // Active scan in progress — block submission.
    $this->messenger()->addError(...);
    $form_state->setRebuild();
    return;
  }
}
// Only after lock is acquired: set start time, reset stats, build batch, etc.
```

### Scanner Service Changes (`DigitalAssetScanner`)

New methods:

```php
/**
 * Acquires scan lock and sets heartbeat. Returns TRUE if acquired, FALSE if held.
 */
public function acquireScanLock(): bool;

/**
 * Releases scan lock and clears heartbeat.
 */
public function releaseScanLock(): void;

/**
 * Checks if a scan lock is currently held.
 */
public function isScanLocked(): bool;

/**
 * Updates heartbeat timestamp. Called by batch callbacks after each chunk.
 */
public function updateScanHeartbeat(): void;

/**
 * Returns heartbeat timestamp, or NULL if not set.
 */
public function getScanHeartbeat(): ?int;

/**
 * Checks if scan lock is stale using 3-tier logic:
 * 1. Heartbeat exists → compare to threshold
 * 2. No heartbeat, checkpoint.started exists → compare to threshold (grace)
 * 3. Neither exists → orphan lock, return TRUE (stale)
 */
public function isScanLockStale(): bool;

/**
 * Force-breaks a stale scan lock with guardrails and forensic logging.
 * Pre-checks: lock must be held (isScanLocked) and stale (isScanLockStale).
 * Logs: heartbeat, started, now, checkpoint phase, temp item count.
 */
public function breakStaleLock(): void;

/**
 * Saves a phase checkpoint after successful phase completion.
 *
 * Enforces INV-4 (monotonicity) internally:
 * - $phase > stored: update all fields
 * - $phase == stored: update counts only; phase5_complete sticky TRUE
 * - $phase < stored or out of range: ignore and log warning
 * - session_id mismatch: ignore and log warning
 *
 * @param int $phase
 *   The phase number (1-5).
 * @param bool $phase5_complete
 *   TRUE only when Phase 5 has fully completed. Callers for phases 1-4
 *   should omit or pass FALSE. Phase 5 callers pass TRUE on completion.
 *   Callers MUST NOT call saveCheckpoint(5, FALSE) — Phase 5 completion
 *   must pass TRUE, and other phases must pass/omit FALSE.
 *   Once TRUE, cannot revert to FALSE (sticky).
 */
public function saveCheckpoint(int $phase, bool $phase5_complete = FALSE): void;

/**
 * Gets current checkpoint state, or NULL if no checkpoint exists.
 *
 * @return array|null
 *   Array with keys:
 *   - 'session_id' (string): Scan session identifier
 *   - 'phase' (int): Last completed phase (1-5)
 *   - 'started' (int): Scan start timestamp
 *   - 'temp_item_count' (int): Item rows at checkpoint time
 *   - 'temp_usage_count' (int): Usage rows at checkpoint time
 *   - 'phase5_complete' (bool): TRUE only when Phase 5 checkpoint written
 *   Or NULL if no checkpoint exists.
 */
public function getCheckpoint(): ?array;

/**
 * Clears all checkpoint state (after successful scan or fresh start).
 */
public function clearCheckpoint(): void;

/**
 * Validates that temp scan data is intact for checkpoint resume or finalize.
 *
 * Mode-aware validation (both modes compute item and usage counts):
 * - 'resume': Blocks on item loss only; usage mismatch is warning-only
 *   (usage recoverable via Phase 5 re-run)
 * - 'finalize': Blocks on item OR usage loss (no phases re-run)
 *
 * @param string $mode
 *   Either 'resume' or 'finalize'.
 *
 * @return array
 *   Structured result with keys:
 *   - 'ok' (bool): TRUE if integrity check passes (no blocking issues)
 *   - 'reason' (string): 'none'|'missing_temp_items'|'partial_item_loss'
 *     |'missing_phase5_evidence'|'usage_loss'
 *   - 'warnings' (string[]): Non-blocking warnings (e.g., usage mismatch
 *     in resume mode). Empty array if no warnings.
 *   - 'current_item_count' (int)
 *   - 'saved_item_count' (int)
 *   - 'current_usage_count' (int)
 *   - 'saved_usage_count' (int)
 */
public function validateCheckpointIntegrity(string $mode): array;
```

**Side-effect rule**: `validateCheckpointIntegrity()` is read-only. It reads counts and checkpoint values but never clears, modifies, or writes State. All cleanup decisions belong to `submitForm()`.

**Count computation**: Counts MUST be computed via entity query (`->count()->execute()`) or direct DB `SELECT COUNT(*)`, not by loading entities. This keeps validation fast on 4000+ asset sites and avoids cache growth before the scan starts.

**Service injection**: Add `@lock.persistent` and `@state` service arguments to `digital_asset_inventory.scanner` in `services.yml`.

### Memory Management

Each scan phase loads different entity types. Cache resets must cover all entity types loaded during that phase, plus Drupal's internal static variables, to prevent unbounded memory growth.

**Phase-specific cache resets** (at the end of each `scan*Chunk()` method):

| Phase | Entity Types to Reset |
| ----- | --------------------- |
| 1 (Managed Files) | `digital_asset_item`, `digital_asset_usage`, `dai_orphan_reference`, `media`, `file` |
| 2 (Orphan Files) | `digital_asset_item`, `digital_asset_usage`, `dai_orphan_reference`, `file` |
| 3 (Content) | `digital_asset_item`, `digital_asset_usage`, `dai_orphan_reference`, `node`, `paragraph`, `block_content`, `taxonomy_term` |
| 4 (Remote Media) | `digital_asset_item`, `digital_asset_usage`, `dai_orphan_reference`, `media` |
| 5 (Menu Links) | `digital_asset_item`, `digital_asset_usage`, `menu_link_content` |

Implementation pattern:

```php
// At the end of each scan*Chunk() method:
foreach (['digital_asset_item', 'digital_asset_usage', ...] as $entity_type) {
  if ($this->entityTypeManager->hasDefinition($entity_type)) {
    $this->entityTypeManager->getStorage($entity_type)->resetCache();
  }
}

// Also reset Drupal's internal static caches to stabilize memory
// in long batch runs (safe within Batch API context).
drupal_static_reset();
```

The `hasDefinition()` guard ensures safe operation when optional entity types (e.g., `paragraph`, `block_content`) are not installed.

### Scan Form Changes (`ScanAssetsForm`)

#### Form Action Identifiers

All `submitForm()` branching is based on the submitted button `#name` attribute (stable, not translated), not `#value` (which may be translated or changed).

| Constant | `#name` Value | Button Label |
| -------- | ------------- | ------------ |
| `ACTION_SCAN` | `'scan'` | "Scan Site for Digital Assets" |
| `ACTION_RESUME` | `'resume'` | "Resume Scan" |
| `ACTION_FINALIZE` | `'finalize'` | "Finalize Scan" |
| `ACTION_FRESH` | `'fresh'` | "Start Fresh Scan" |

#### `buildForm()` Logic

```text
Step 1: Finalize-first check (takes precedence over lock state)
IF checkpoint exists AND session_id present AND phase present:
  → IF checkpoint.phase == 5 AND checkpoint.phase5_complete == TRUE:
    → Run validateCheckpointIntegrity(mode='finalize')
    → IF integrity fails:
      → Show warning with reason; show only "Start Fresh Scan"
    → ELSE:
      → Show "All scan phases completed but finalization was interrupted."
      → IF lock is held: show note "A stale scan lock will be cleared..."
      → Show two buttons: "Finalize Scan" | "Start Fresh Scan"
    → RETURN (skip lock checks)

Step 2: Lock state check (for non-finalizable cases)
IF scan lock is held:
  → IF heartbeat is stale (isScanLockStale == TRUE):
    → Show "Previous scan appears interrupted (last activity [X] ago)."
    → Show two buttons: "Resume Scan" | "Start Fresh Scan"
    → (Lock is NOT broken here — buildForm() is read-only.)
  → ELSE (heartbeat is fresh — active scan):
    → IF heartbeat exists:
      → Show "A scan is currently running (last activity [X] ago)."
    → ELSE (no heartbeat but within grace window — startup):
      → Show "A scan is starting up. Please wait."
    → Disable submit button

Step 3: Remaining checkpoint states (no lock held)
ELSE IF checkpoint exists:
  → IF checkpoint.session_id is missing OR checkpoint.phase is NULL or 0:
    → Malformed; show "Start Fresh Scan" only

  → ELSE IF checkpoint.phase == 5 AND checkpoint.phase5_complete != TRUE:
    → Treat as incomplete Phase 5; run validateCheckpointIntegrity(mode='resume')
    → IF integrity fails: show warning; show only "Start Fresh Scan"
    → ELSE: show "Resume Scan" (re-runs Phase 5) | "Start Fresh Scan"

  → ELSE (checkpoint.phase 1-4):
    → Run validateCheckpointIntegrity(mode='resume')
    → IF integrity fails: show warning; show only "Start Fresh Scan"
    → ELSE: show "Resume Scan" | "Start Fresh Scan"

Step 4: Normal form
ELSE:
  → Normal form: "Scan Site for Digital Assets" button
```

#### Integrity Decision Table

Let `I_saved` = `checkpoint.temp_item_count`, `I_now` = current temp item count,
`U_saved` = `checkpoint.temp_usage_count`, `U_now` = current temp usage count,
`P5` = `checkpoint.phase5_complete`.

**Resume mode** (checkpoint.phase 1-4, or phase 5 without `phase5_complete`):

| Condition | Action | UI |
| --------- | ------ | -- |
| `I_now == 0` | BLOCK resume | "Temp scan data missing" -- only Start Fresh |
| `I_now < I_saved` | BLOCK resume | "Partial temp data loss" -- only Start Fresh |
| `I_now >= I_saved` | Allow resume | Show Resume + Start Fresh |
| (optional) `U_now < U_saved` | WARN only | Still allow resume; note "usage may be incomplete; remaining phases will rebuild" |

Resume re-runs later phases including Phase 5, so usage loss is recoverable.

**Finalize mode** (checkpoint.phase == 5 AND `phase5_complete == TRUE`):

| Condition | Action | UI |
| --------- | ------ | -- |
| `P5 != TRUE` | BLOCK finalize | N/A (handled by buildForm branching -- offer Resume instead) |
| `I_now == 0` | BLOCK finalize | Only Start Fresh |
| `I_now < I_saved` | BLOCK finalize | Only Start Fresh |
| `U_now == 0 && U_saved > 0` | BLOCK finalize | Only Start Fresh (usage lost) |
| `U_now < U_saved` | BLOCK finalize | Only Start Fresh (partial usage loss) |
| `I_now >= I_saved && U_now >= U_saved && P5 == TRUE` | Allow finalize | Show Finalize + Start Fresh |

Finalize does not rebuild Phase 5, so it must ensure Phase 5 outputs are present.

**UI details for resume state:**

- Phase name (e.g., "Managed Files, Orphan Files")
- Progress fraction (e.g., "2 of 5 phases completed")
- Human-readable age (e.g., "Started 18 minutes ago")

#### `submitForm()` Logic

```text
1. Acquire scan lock (FIRST — before any state mutation)
   → If lock fails:
     → If stale → breakStaleLock() → retry acquire
       → If retry fails → show error, setRebuild(), return
     → If active → show error, setRebuild(), return

2. Re-load checkpoint from State and re-evaluate which branch applies
   (prevents race where UI was rendered, then state changed before submit)

3. Set scan start time in State IMMEDIATELY after lock acquired
   (provides forensic visibility if subsequent steps crash)
   → If action is "Start Fresh Scan" or no checkpoint exists: write started
     (new session timestamp)
   → If action is "Resume Scan" or "Finalize Scan": do NOT overwrite started
     (preserves original scan start time per "started is written once" rule)

4. Session ID handling (for INV-4 scoping):
   → If "Start Fresh Scan" or no checkpoint exists: generate new session_id,
     write to State
   → If "Resume Scan" or "Finalize Scan": reuse existing
     dai.scan.checkpoint.session_id (do not rotate)
   Resume/finalize reuse the existing session_id so that saveCheckpoint()
   remains scoped to the same scan session that produced the temp rows.

5. IF triggering_element is "Finalize Scan" (checkpoint.phase == 5):
   → promoteTemporaryItems()
   → clearCheckpoint()
   → releaseScanLock()
   → reconstruct summary counts from database
   → show success message
   → return (no batch needed)

6. IF triggering_element is "Start Fresh Scan":
   → clearTemporaryItems()
   → clearCheckpoint()
   → resetScanStats()
   → build full 5-phase batch

7. ELSE IF checkpoint exists (Resume):
   → build batch with only phases after checkpoint

8. ELSE (normal scan):
   → resetScanStats()
   → build full 5-phase batch

9. batch_set($batch)
```

**Ordering rationale**: Lock acquisition (step 1), checkpoint re-check (step 2), start time (step 3), and session ID (step 4) are written before any state mutation (steps 5-8). The checkpoint re-load after lock acquisition prevents races where UI state diverged from actual State before submit. The start time and session ID provide forensic visibility if PHP crashes mid-flow. The session ID also ensures that `saveCheckpoint()` calls during the batch are scoped to the correct scan session (INV-4).

#### Checkpoint Saving in Batch Methods

Each `batchProcess*` static method saves checkpoint **once** when its phase finishes (not per chunk):

```php
$scanner = \Drupal::service('digital_asset_inventory.scanner');
// Heartbeat at chunk ENTRY — prevents false-stale during slow chunks.
// If a single chunk takes >120s, the entry heartbeat ensures another tab
// won't falsely treat the lock as stale mid-chunk.
$scanner->updateScanHeartbeat();

// ... chunk work ...

// At the end of each batchProcess* method, when the phase is complete:
if ($context['finished'] >= 1) {
  $is_phase5_complete = ($phase_number === 5);
  $scanner->saveCheckpoint($phase_number, $is_phase5_complete);
}

// Heartbeat at chunk EXIT — confirms chunk completed successfully.
$scanner->updateScanHeartbeat();
```

**Rule**: Only the Phase 5 batch-finished path may pass `TRUE` for `$phase5_complete`. No other code path should infer `phase5_complete` from the stored phase number or set it earlier than Phase 5 completion.

**Important**: The checkpoint saves once per phase, not per chunk. Each batch callback is called multiple times (once per chunk), but `$context['finished'] >= 1` is only true on the final chunk. This prevents unnecessary State writes and ensures checkpoint reflects complete phase data.

**Implementation constraint**: Each phase MUST be implemented as a single batch operation with internal chunking. The `$context['finished'] >= 1` checkpoint trigger depends on this structure. If a phase is later refactored into multiple batch operations, the checkpoint logic must move to a phase-level finished callback to avoid premature checkpointing.

#### `batchFinished()` Changes

```text
IF success:
  → promoteTemporaryItems() (existing)
  → clearCheckpoint()
  → releaseScanLock()
  → reconstruct summary counts from database (existing pattern)
  → show success message (existing)

ELSE (failure):
  → Do NOT clearTemporaryItems() (preserve for resume)
  → Do NOT clearCheckpoint() (preserve for resume)
  → releaseScanLock()
  → show message: "Scan interrupted. You can resume from where it left off
                   on the scan page."
```

**Critical behavioral change**: On failure, do NOT call `clearTemporaryItems()`. Previously, failure cleaned up all temp items. Now, temp items are preserved so the scan can resume.

---

## Edge Cases

### EC-1: Stale Checkpoint

**Scenario**: Checkpoint exists but is very old (days/weeks ago). Site content may have changed.
**Handling**: Show checkpoint age in the UI with human-readable format (e.g., "Started 3 days ago"). User can choose "Start Fresh Scan" to discard stale checkpoint. No automatic expiration -- user decides.

### EC-2: Lock Timeout Without Cleanup

**Scenario**: PHP crashes hard (segfault, OOM kill) or user navigates away -- `batchFinished()` never runs, lock never released.
**Handling**: Heartbeat-based stale detection recovers within 2 minutes: if no heartbeat update for `SCAN_LOCK_STALE_THRESHOLD` (120 seconds), `buildForm()` shows "Previous scan appears interrupted" with Resume/Fresh buttons, and `submitForm()` breaks the stale lock before proceeding. The 2-hour lock TTL in the `semaphore` table serves as a worst-case failsafe (e.g., if State API is also corrupted). Existing checkpoint/temp items from the crashed scan are still valid for resume.

### EC-3: Checkpoint Exists But Temp Items Were Manually Deleted

**Scenario**: Admin ran a database cleanup or manually deleted `is_temp=TRUE` items.
**Handling**: `validateCheckpointIntegrity(mode)` compares current temp row counts against the counts stored at checkpoint time. For resume mode, item count is checked (usage is recoverable). For finalize mode, both item and usage counts are checked. If counts indicate data loss, the "Resume Scan" or "Finalize Scan" button is hidden and a warning with the specific reason is shown. Only the "Start Fresh Scan" button is available. This prevents silent inventory incompleteness, which is unacceptable for a compliance-critical module.

### EC-4: Content Changes Between Scan Start and Resume

**Scenario**: New content added between initial scan (phases 1-2) and resume (phases 3-5).
**Handling**: This is acceptable. Phases 1-2 captured the state at scan time. Phases 3-5 will capture external URLs/media/menu links at resume time. Minor inconsistency is tolerable -- the next full scan will reconcile. This is the same trade-off the current system makes (content can change during a long-running scan).

### EC-5: Form Submit Race Condition

**Scenario**: User double-clicks "Scan Site" button.
**Handling**: Lock prevents second scan from starting. Second submit shows warning. Button disabled via `#attributes['disabled']` is a UI-level guard; lock is the authoritative backend guard.

### EC-5b: Browser Refresh Re-POST (Batch Progress Page)

**Scenario**: User starts a scan, navigates away, resumes in another tab, then returns to the original tab and refreshes the batch progress page. The browser shows "Confirm Form Resubmission" — clicking Continue re-POSTs the original `submitForm()` request.
**Handling**: The lock-first guard in `submitForm()` makes this a harmless no-op. The re-POST calls `acquireScanLock()`, which fails because the other tab holds the lock. `isScanLockStale()` returns FALSE (the other tab's heartbeat is fresh). The handler falls into the active-lock branch: shows "A scan is already in progress" error, calls `setRebuild()`, and returns. No State mutation, no `batch_set()`, no checkpoint or temp row clearing occurs. Clicking Cancel in the browser dialog is equally safe — nothing changes and the other tab continues normally.

### EC-6: Partial Phase Interruption

**Scenario**: Phase 4 crashes at chunk 3 of 10. Partial temp rows from Phase 4 exist alongside complete temp rows from Phases 1-3.
**Handling**: Checkpoint only records Phase 3 as complete. Resume starts Phase 4 from the beginning. Partial Phase 4 temp rows remain in the database. Per INV-1, each phase implements "find-or-create then update" semantics: when Phase 4 re-runs, it queries for existing temp rows by stable lookup key (`url_hash` + `source_type='media_managed'` + `is_temp=TRUE`) before creating new ones. Rows created during the crashed partial run are matched and updated in place; only genuinely new items create new rows. No duplicates are produced. See the Invariants section for the verified lookup keys per phase.

### EC-7: All Phases Complete But Promote Never Ran

**Scenario**: Phase 5 completes and saves checkpoint (with `phase5_complete=TRUE`), but PHP crashes before `batchFinished()` calls `promoteTemporaryItems()`.
**Handling**: Checkpoint shows `phase == 5` AND `phase5_complete == TRUE`. The scan form runs `validateCheckpointIntegrity(mode='finalize')`, which checks both item and usage counts. If integrity passes, "Finalize Scan" is shown. If `phase5_complete` is not TRUE (Phase 5 crashed mid-execution), the form offers "Resume Scan" instead (re-runs Phase 5). Clicking "Finalize Scan" runs `promoteTemporaryItems()` directly (no batch phases), clears the checkpoint, and shows the success message.

### EC-8: Resume-Crash-Resume Cycle

**Scenario**: Scan crashes, user resumes, scan crashes again during a later phase.
**Handling**: Each resume rebuilds remaining phases from the latest checkpoint. Temp items continue accumulating. The checkpoint advances with each completed phase. Multiple resume cycles are safe because the atomic swap only runs after all 5 phases complete.

---

## Atomic Swap Compatibility

The existing atomic swap pattern is fully compatible with checkpointing:

- All 5 phases create items with `is_temp=TRUE` -- unchanged
- `promoteTemporaryItems()` runs only after ALL phases complete -- unchanged
- If scan fails after phase 2, temp items from phases 1-2 remain in DB with `is_temp=TRUE`
- Resume creates phases 3-5 temp items alongside existing 1-2 temp items
- Final `promoteTemporaryItems()` swaps everything at once -- unchanged

**No changes needed** to `promoteTemporaryItems()` or `clearTemporaryItems()` because INV-1 ensures the temp set remains idempotently updated (no duplicate temp rows created by phase re-runs).

---

## Files to Modify

| File | Changes |
| ---- | ------- |
| `src/Form/ScanAssetsForm.php` | Checkpoint saves, resume logic, lock checks (read-only in `buildForm()`, mutations in `submitForm()` only), heartbeat updates per batch chunk, stale lock recovery in `submitForm()`, form rebuild on lock-fail |
| `src/Service/DigitalAssetScanner.php` | Lock methods (acquire/release/check), heartbeat methods (update/get/stale/break), checkpoint methods with `temp_item_count`/`temp_usage_count`/`phase5_complete`, mode-aware `validateCheckpointIntegrity()`, phase-specific entity cache resets + `drupal_static_reset()` in `scan*Chunk()` |
| `digital_asset_inventory.services.yml` | Add `@lock.persistent` and `@state` arguments to scanner service |

---

## Test Cases

### TC-1: Normal Scan (No Resume)

1. Run full scan on site with 100+ assets
2. Verify scan completes normally
3. Verify no checkpoint state remains after completion
4. Verify inventory is correct

### TC-2: Resume After Interruption

1. Start scan, allow phases 1-2 to complete
2. Kill the browser (simulate interruption)
3. Visit scan form -- verify "Resume Scan" option appears with phase info and age
4. Click "Resume Scan"
5. Verify phases 3-5 run, final inventory matches a full fresh scan

### TC-3: Start Fresh After Interruption

1. Start scan, allow phase 1 to complete, interrupt
2. Visit scan form -- verify resume option appears
3. Click "Start Fresh Scan"
4. Verify temp items are cleared, full 5-phase scan runs
5. Verify inventory is correct

### TC-4: Concurrent Scan Prevention

1. Open scan form in Tab A, click "Scan Site"
2. Open scan form in Tab B while Tab A scan is running (within 2 minutes)
3. Verify Tab B shows "A scan is currently running (last activity X ago)" and disabled button
4. Tab B submits (if button not disabled via JS) -- verify lock-first guard blocks with error and form rebuilds to disabled state

### TC-5: Stale Lock Recovery (Navigate Away)

1. Start scan in Tab A, let it begin processing
2. Navigate away from Tab A (do NOT click Cancel)
3. Wait at least 2 minutes (heartbeat staleness threshold)
4. Open scan form in new tab -- verify "Previous scan appears interrupted (last activity X ago)" with Resume/Fresh buttons
5. Click "Resume Scan" -- verify lock is broken, re-acquired, and scan continues from checkpoint
6. Verify `buildForm()` did NOT break the lock (only `submitForm()` did)

### TC-5b: Lock TTL Auto-Expiry (Worst Case)

1. Acquire lock manually (simulate crashed scan with no heartbeat)
2. Wait for 2-hour TTL timeout (or set short timeout for testing)
3. Verify lock expires in `semaphore` table and new scan can start

### TC-6: Memory Stability

1. Run scan on site with 4000+ assets
2. Monitor PHP memory usage during scan
3. Verify memory stays bounded (not linear growth)

### TC-7: Checkpoint After Each Phase

1. Run scan with logging/breakpoints after each phase
2. Verify State API contains correct checkpoint after each phase completes
3. Verify phase number, timestamp, temp item count, and temp usage count are stored (no batch results)
4. Verify `phase5_complete` is FALSE for phases 1-4 and TRUE only after Phase 5 completes

### TC-8: Checkpoint Integrity Validation (Complete Loss)

1. Start scan, allow phase 1 to complete, interrupt
2. Manually delete all `is_temp=TRUE` rows from `digital_asset_item`
3. Visit scan form -- verify warning about lost temp data
4. Verify "Resume Scan" button is NOT shown
5. Verify "Start Fresh Scan" button IS shown
6. Click "Start Fresh Scan" -- verify full scan runs correctly

### TC-9: Checkpoint Integrity Validation (Partial Loss)

1. Start scan, allow phases 1-2 to complete (e.g., 2000 temp rows), interrupt
2. Manually delete 1500 of the `is_temp=TRUE` rows (500 remain)
3. Visit scan form -- verify warning about partial temp data loss
4. Verify "Resume Scan" button is NOT shown
5. Verify "Start Fresh Scan" button IS shown

### TC-10: Lock-First Guard

1. Simulate two near-simultaneous `submitForm()` calls
2. Verify only one acquires the lock
3. Verify the second returns immediately without modifying State or building batch

### TC-11: Resume-Crash-Resume Cycle

1. Start scan, allow phases 1-2 to complete, interrupt
2. Resume scan, allow phase 3 to complete, interrupt again
3. Visit scan form -- verify checkpoint shows phase 3 completed
4. Resume scan -- verify phases 4-5 run
5. Verify final inventory is complete and correct

### TC-12: Finalize After All Phases Complete

1. Start scan, allow all 5 phases to complete
2. Simulate crash before `batchFinished()` runs (checkpoint.phase == 5, phase5_complete == TRUE)
3. Visit scan form -- verify "Finalize Scan" button appears (not "Resume Scan")
4. Click "Finalize Scan"
5. Verify `promoteTemporaryItems()` runs, inventory is correct
6. Verify no batch phases were re-executed
7. Verify checkpoint is cleared

### TC-13: Start Time Forensic Visibility

1. Start scan, acquire lock, crash immediately after start time written but before batch runs
2. Verify `dai.scan.checkpoint.started` exists in State
3. Verify lock eventually expires (2-hour timeout)
4. Verify scan form shows meaningful checkpoint info for debugging

### TC-14: Phase Idempotency (No Duplicate Temp Rows)

1. Start scan, allow phase 1 to run long enough to create a non-trivial number of temp rows, then interrupt mid-phase (simulate crash)
2. Resume scan (phase 1 re-runs from the beginning)
3. After phase 1 completes, query `digital_asset_item` where `is_temp=TRUE`
4. Verify no duplicate rows exist for the same `fid` (phase 1) or `url_hash` + `source_type` (phases 2-4)
5. Verify total temp row count does not exceed a fresh scan in the same environment and that uniqueness-by-key holds (no duplicates)
6. Repeat for phase 5: verify no duplicate `digital_asset_usage` rows for the same `asset_id` + host entity identity + discriminator fields

### TC-15: Finalize Blocked by Usage Loss

1. Start scan, allow all 5 phases to complete (checkpoint.phase == 5, phase5_complete == TRUE)
2. Simulate crash before `batchFinished()` runs
3. Manually delete some `is_temp=TRUE` rows from `digital_asset_usage`
4. Visit scan form -- verify "Finalize Scan" is NOT shown (usage count mismatch)
5. Verify warning about usage data loss
6. Verify only "Start Fresh Scan" button is available

### TC-16: Phase 5 Incomplete vs Complete (phase5_complete Signal)

1. Start scan, allow phases 1-4 to complete, allow Phase 5 to start but crash mid-phase
2. Verify checkpoint.phase == 4 (Phase 5 never completed, so checkpoint stays at 4)
3. Visit scan form -- verify "Resume Scan" is offered (not "Finalize Scan")
4. Alternatively: manually set checkpoint.phase = 5 but leave phase5_complete = FALSE
5. Visit scan form -- verify "Resume Scan" is offered to re-run Phase 5 (not "Finalize Scan")

### TC-17: Checkpoint Monotonicity (INV-4)

1. Manually write checkpoint with phase=3 to Drupal State
2. Call `saveCheckpoint(2)` (lower phase number)
3. Verify stored checkpoint phase remains 3 (not overwritten to 2)
4. Call `saveCheckpoint(3)` (equal phase number) -- verify state updates (counts refresh)
5. Call `saveCheckpoint(4)` (higher phase number) -- verify state updates to phase 4

### TC-18: Heartbeat False-Stale Protection

1. Start a scan, allow it to begin processing (lock acquired, heartbeat set)
2. Simulate a slow chunk that takes >120 seconds (e.g., by adding a sleep in `scanManagedFilesChunk()`)
3. While the slow chunk is processing, open scan form in Tab B within the 120-second window
4. Verify Tab B shows "A scan is currently running" (NOT "interrupted") because the chunk-entry heartbeat refreshed the timestamp before the slow work started
5. After the slow chunk completes, verify Tab B still shows "currently running" (chunk-exit heartbeat also refreshed)
6. Verify no false lock break occurred during the slow chunk

### TC-19: Finalize UI Precedence Over Stale Lock

1. Start scan, allow all 5 phases to complete (checkpoint.phase == 5, phase5_complete == TRUE)
2. Simulate crash before `batchFinished()` runs (lock still held in semaphore table, heartbeat goes stale)
3. Wait at least 2 minutes for heartbeat to become stale
4. Visit scan form -- verify "Finalize Scan" button appears (NOT "Previous scan appears interrupted")
5. Verify lock note is shown: "A stale scan lock will be cleared when you finalize or start fresh."
6. Click "Finalize Scan" -- verify lock is broken, promotion runs, inventory is correct

### TC-20: Grace Rule for Missing Heartbeat During Startup

1. Acquire scan lock (sets heartbeat)
2. Delete only the heartbeat from State (`dai.scan.lock.heartbeat`) but leave `dai.scan.checkpoint.started` (simulating a race where heartbeat wasn't written yet)
3. Within the 2-minute threshold of `started`: verify `isScanLockStale()` returns FALSE (grace window)
4. After 2+ minutes past `started`: verify `isScanLockStale()` returns TRUE (grace expired)
5. Delete both heartbeat and started: verify `isScanLockStale()` returns TRUE (orphan lock)

### TC-21: breakStaleLock() Guardrails

1. Call `breakStaleLock()` when no lock is held -- verify warning logged and no semaphore deletion
2. Call `breakStaleLock()` when lock is held but heartbeat is fresh (not stale) -- verify warning logged and lock preserved
3. Call `breakStaleLock()` when lock is held and stale -- verify lock is broken and forensic log includes heartbeat, started, now, checkpoint phase, and temp item count
