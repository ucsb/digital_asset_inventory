# Scan Resilience Enhancements — Pantheon 504 Fix

## Problem Statement

The Digital Asset Inventory scan fails on Pantheon for sites with 5,000+ files due to HTTP 504 timeouts. Pantheon enforces a **~59-second request timeout** for web requests (including Batch API AJAX calls). The current implementation processes chunks that can exceed this limit, causing the AJAX callback to be killed mid-execution. Additionally, the 120-second stale-lock threshold is too aggressive — it falsely marks active scans as stale when a single slow chunk takes longer than expected.

**Observed failures:**

| Site | Files | Environment | Result |
|------|-------|-------------|--------|
| Site A | 5,473 | Pantheon | Failed at ~40 min |
| Site B | 5,016 | Local | Completed in 8 min |
| Site B | 5,016 | Pantheon | Failed at ~5 min |
| UCSB Main | 2,815 | Pantheon | Completed in 5 min |

**Log evidence:** `Stale scan lock broken. Heartbeat: 1771980790, Started: 1771980520, Now: 1771981522, Checkpoint phase: 1, Temp items: 3628` — heartbeat went 732 seconds without update during Phase 1, triggering a false stale-lock break on what was likely an active scan.

---

## Root Cause Analysis

Three interacting problems produce the failures:

**1. Unbounded chunk duration.** Each `batchProcess*` callback processes a fixed number of items regardless of elapsed time. On Pantheon, a single Batch API AJAX request must complete within ~59 seconds. If a chunk loads and processes hundreds of entities with complex field data, it easily exceeds this. When Pantheon kills the request, `batchFinished()` never runs, the lock is never released, and the heartbeat is never updated.

**2. Heartbeat too coarse.** The heartbeat is updated only at chunk entry and exit. If a chunk runs for 3+ minutes (common on Pantheon for entity-heavy phases), the heartbeat goes stale mid-chunk. A second tab or the same user revisiting the form sees a stale lock and breaks it — while the original batch is still processing in another request.

**3. Per-item State writes.** `incrementOrphanCount()` performs a Drupal State `get` + `set` on every orphan encountered. On a 5,000-file site this can mean thousands of extra database round-trips during Phase 2, adding significant wall-clock time per chunk.

These compound on Pantheon because the platform has lower per-request time budgets and higher database latency than local environments.

---

## Scope

This spec covers **eight functional requirements (FR-1 through FR-8)** and **two lock-behavior refinements (LR-1, LR-2)**. All changes are confined to:

- `src/Service/DigitalAssetScanner.php`
- `src/Form/ScanAssetsForm.php`
- `digital_asset_inventory.services.yml`
- `config/install/digital_asset_inventory.settings.yml` (new config key)

No new database tables. No changes to scan phase logic, entity schemas, or the definition of "in use." No changes to `promoteTemporaryItems()` or `clearTemporaryItems()`.

---

## Relationship to Existing Spec

This document **extends** the Scan Resilience Specification (v1). All invariants (INV-1 through INV-5), requirements (REQ-001 through REQ-005), edge cases, and checkpoint/lock semantics from the base spec remain in effect. Where this document specifies different defaults or behaviors, it takes precedence. Specifically:

- **Stale threshold** changes from a hardcoded 120 seconds to a configurable default of 900 seconds (FR-3).
- **Heartbeat key** changes from a global `dai.scan.lock.heartbeat` to a session-scoped `dai.scan.{session_id}.heartbeat` (FR-1).
- **Chunk processing** gains a wall-clock time budget that causes early yield (FR-5), replacing the current fixed-count-per-chunk model. The budget itself is configurable for cross-platform tuning (FR-7).
- **Statistics tracking** moves from per-item State writes to sandbox-based aggregation (FR-4).
- **Cache resets** become explicitly frequency-bounded (FR-6).
- **Batch timing diagnostics** are added via per-request debug logging and 504-recovery notice logging (FR-8).

All other base spec behaviors — checkpoint monotonicity, integrity validation, form action branching, finalize logic, lock-first guards — are **unchanged**.

---

## FR-1: Session-Scoped Heartbeat

### Problem

`dai.scan.lock.heartbeat` is a global key. In edge cases (stale session + new session starting before cleanup), the new session's heartbeat overwrites the old one, or stale-check reads the wrong session's heartbeat.

### Design

Replace the global heartbeat key with a session-scoped key:

```
dai.scan.{session_id}.heartbeat    (int timestamp)
```

Where `{session_id}` is the value of `dai.scan.checkpoint.session_id`.

**Rules:**
- `acquireScanLock()` sets the session ID in State (if starting fresh) and initializes `dai.scan.{session_id}.heartbeat`.
- `updateScanHeartbeat()` writes to `dai.scan.{session_id}.heartbeat` using the active session ID from State.
- `releaseScanLock()` deletes `dai.scan.{session_id}.heartbeat` for the active session.
- `isScanLockStale()` reads the active session ID, then reads `dai.scan.{session_id}.heartbeat`. If no session ID exists but a lock is held, fall through to the existing 3-tier grace logic (using `dai.scan.checkpoint.started`).
- `breakStaleLock()` deletes the session-scoped heartbeat for the broken session's ID before clearing the lock.

**Cleanup on scan completion or fresh start:** delete `dai.scan.{session_id}.heartbeat` and any `dai.scan.{session_id}.stats.*` keys for that session.

### Acceptance Criteria

- [ ] Two simulated sessions cannot overwrite each other's heartbeat
- [ ] `isScanLockStale()` reads the correct session's heartbeat
- [ ] Log entries include `session_id` for diagnostics
- [ ] Session-scoped keys are cleaned up on completion, fresh start, and stale-break

---

## FR-2: Heartbeat Rate-Limiting Inside the Work Loop

### Problem

Heartbeat updates only at chunk entry/exit are too coarse. A chunk that processes 500 entities over 90 seconds has no heartbeat activity for that entire duration. On Pantheon with the current 120s threshold, this triggers false stale detection.

### Design

Add an intra-chunk heartbeat mechanism. The scanner maintains a private timestamp tracking the last heartbeat write. During entity processing loops, the heartbeat is refreshed if more than N seconds have elapsed since the last write.

```php
// In DigitalAssetScanner:
private int $lastHeartbeatWrite = 0;

private const HEARTBEAT_INTERVAL_SECONDS = 2;

/**
 * Conditionally updates heartbeat if interval has elapsed.
 * 
 * Call this inside entity processing loops (e.g., per item or per small batch).
 * The method is cheap to call frequently — it only writes to State when the
 * interval has actually elapsed.
 */
public function maybeUpdateHeartbeat(): void {
  $now = time();
  if (($now - $this->lastHeartbeatWrite) >= self::HEARTBEAT_INTERVAL_SECONDS) {
    $this->updateScanHeartbeat();
    $this->lastHeartbeatWrite = $now;
  }
}
```

**Integration points:** Each `scan*Chunk()` method calls `$this->maybeUpdateHeartbeat()` inside its entity processing loop. The existing chunk-entry and chunk-exit heartbeat calls in `batchProcess*` remain as bookends.

**Write frequency bound:** At most 1 State write per 2 seconds, regardless of how many items are processed. On a fast local machine processing 200 items/second, this means ~1 write per 400 items instead of per-item.

### Acceptance Criteria

- [ ] During a long chunk, heartbeat updates occur every ~2 seconds
- [ ] State writes do not exceed 1 per 2 seconds
- [ ] No false stale-lock breaks during sustained work in a single chunk
- [ ] `maybeUpdateHeartbeat()` adds negligible overhead when called frequently (just a `time()` comparison)

---

## FR-3: Configurable Stale Threshold

### Problem

The hardcoded 120-second `SCAN_LOCK_STALE_THRESHOLD` is too aggressive for Pantheon. A single slow chunk or a brief network hiccup causes false stale detection.

### Design

Move the threshold to Drupal configuration:

**Config key:** `digital_asset_inventory.settings.scan_lock_stale_threshold_seconds`

**Default:** `900` (15 minutes)

**Validation:**
- Minimum: 120 seconds
- Maximum: 7200 seconds
- If value is outside range or non-numeric: use default (900) and log a warning

**Reading the value:**

```php
public function getStaleLockThreshold(): int {
  $config = $this->configFactory->get('digital_asset_inventory.settings');
  $threshold = $config->get('scan_lock_stale_threshold_seconds');
  
  if (!is_numeric($threshold) || $threshold < 120 || $threshold > 7200) {
    $this->logger->warning('Invalid stale threshold @value, using default 900s.', [
      '@value' => $threshold,
    ]);
    return 900;
  }
  
  return (int) $threshold;
}
```

**Service injection:** Add `@config.factory` to scanner service arguments in `services.yml`.

**`isScanLockStale()` update:** Replace `self::SCAN_LOCK_STALE_THRESHOLD` (120) with `$this->getStaleLockThreshold()`.

### Rationale for 900 Seconds

With FR-2 (intra-chunk heartbeat every 2s) and FR-5 (time-budgeted chunks yielding every ~4s), the heartbeat should update frequently under normal operation. A 15-minute window provides ample buffer for:
- Pantheon's occasional slow database responses
- Brief platform-level pauses (container rescheduling, etc.)
- Edge cases where a batch request takes unusually long but is still progressing

If the heartbeat hasn't updated in 15 minutes, the scan is genuinely dead.

### Acceptance Criteria

- [ ] Threshold is configurable via `drush cset` or settings form without code changes
- [ ] Invalid values fall back to 900 with a log warning
- [ ] Default of 900 eliminates false stale detection on Pantheon for observed workloads
- [ ] `isScanLockStale()` uses the configured value

---

## FR-4: Sandbox-Based Scan Statistics

### Problem

`incrementOrphanCount()` performs `State::get()` + `State::set()` per orphan item. On a site with 2,000 orphans, this produces 4,000 extra database queries during Phase 2 alone.

### Design

Replace per-item State writes with batch sandbox accumulation.

**Within batch operation callbacks:**

```php
// Initialize counter in sandbox (once per callback chain):
if (!isset($context['sandbox']['orphan_count'])) {
  $context['sandbox']['orphan_count'] = 0;
}

// Per orphan detected:
$context['sandbox']['orphan_count']++;

// At callback exit (once per Batch API request):
if ($context['sandbox']['orphan_count'] > 0) {
  $scanner->persistOrphanCount($sessionId, $context['sandbox']['orphan_count']);
}
```

**State key:** `dai.scan.{session_id}.stats.orphan_count`

**`persistOrphanCount()` behavior:** A single `State::set()` call per batch request, writing the running total from the sandbox. This replaces potentially hundreds of `get`+`set` pairs with exactly one `set`.

**Backward compatibility:** The existing `digital_asset_inventory.scan_orphan_count` key should be written once at scan completion (in `batchFinished()`) for any code that reads it. During the scan, only the session-scoped key is used.

**Extension to other stats:** The same pattern applies to any future per-item counters. The general rule: accumulate in `$context['sandbox']`, persist once per batch callback.

### Acceptance Criteria

- [ ] Zero per-orphan State writes during scanning
- [ ] At most 1 State write for orphan count per batch request
- [ ] Final orphan count matches what per-item writes would have produced
- [ ] `digital_asset_inventory.scan_orphan_count` is written once at scan completion for backward compatibility

---

## FR-5: Time-Budgeted Chunk Processing

### Problem

Current chunk sizing is item-count-based (e.g., "process 50 entities per chunk"). Processing time per entity varies wildly — a node with 30 paragraph references and embedded media takes 10-100x longer than a simple file entity. On Pantheon, a chunk of 50 complex nodes can exceed the 59-second request timeout.

### Design

Replace fixed item counts with a wall-clock time budget. Each batch callback yields control back to the Batch API when the budget is exhausted, regardless of how many items remain in the current "chunk."

**Default time budget:** 4 seconds per batch callback (configurable via FR-7).

**Fallback constant:** `DigitalAssetScanner::BATCH_TIME_BUDGET_SECONDS = 4` (used only if config is unavailable)

**Implementation pattern for each `scan*Chunk()` method:**

```php
public function scanManagedFilesChunk(array &$context): void {
  $startTime = microtime(true);
  $budget = $this->getBatchTimeBudget();  // Configurable via FR-7.
  $itemsThisCallback = 0;
  
  // Initialize cursor on first call.
  if (!isset($context['sandbox']['last_id'])) {
    $context['sandbox']['last_id'] = 0;
    $context['sandbox']['total'] = $this->countManagedFiles();
    $context['sandbox']['processed'] = 0;
  }
  
  // Fetch next batch of IDs (fetch more than we'll process — we'll yield early).
  $ids = $this->getManagedFileIds($context['sandbox']['last_id'], 100);
  
  if (empty($ids)) {
    $context['finished'] = 1;
    return;
  }
  
  foreach ($ids as $id) {
    // Time check BEFORE processing each item.
    if ((microtime(true) - $startTime) >= $budget) {
      break;
    }
    
    $this->processManagedFile($id);
    $this->maybeUpdateHeartbeat();
    
    $context['sandbox']['last_id'] = $id;
    $context['sandbox']['processed']++;
    $itemsThisCallback++;
  }
  
  // Progress calculation for Batch API.
  $context['finished'] = $context['sandbox']['processed'] / $context['sandbox']['total'];
  if ($context['finished'] >= 1) {
    $context['finished'] = 1;
  }
  
  // Per-request timing log (FR-8).
  $this->logger->debug('Batch request complete. Phase: @phase, Items: @items, Elapsed: @elapsed s, Cursor: @cursor, Budget: @budget s', [
    '@phase' => 1,
    '@items' => $itemsThisCallback,
    '@elapsed' => round(microtime(true) - $startTime, 2),
    '@cursor' => $context['sandbox']['last_id'],
    '@budget' => $budget,
  ]);
}
```

**Cursor requirements:**
- Cursor is the last processed entity ID (`last_id`), stored in `$context['sandbox']`.
- Entity queries must use `->condition('id', $lastId, '>')` with `->sort('id', 'ASC')` to ensure monotonic, gap-safe pagination.
- Cursor survives across batch requests via the sandbox (Drupal serializes `$context` between requests).

**Yield semantics:** When the time budget is reached mid-iteration, the method breaks out of the loop, sets `$context['finished']` to a fractional value, and returns. The Batch API will call the same callback again in a new HTTP request, which resumes from `$context['sandbox']['last_id']`.

**Why 4 seconds:** Pantheon's request timeout is ~59 seconds. The Batch API itself has overhead (serialization, redirect, AJAX handling). A 4-second budget for scan work leaves ample room for Drupal's batch infrastructure, database commits, and response delivery. Even with 10x overhead, the total request stays well under 59 seconds.

**Configurable via FR-7:** The time budget is configurable per environment (see FR-7). The 4-second default is conservative enough for any Pantheon tier. On local or generous VPS environments, administrators can increase the budget to reduce total batch requests and scan duration.

### Acceptance Criteria

- [ ] No single batch request exceeds 4 seconds of scan work (may exceed by at most 1 item's processing time)
- [ ] Large scans proceed via many short requests instead of few long ones
- [ ] Cursor is monotonic and resume-safe (no items skipped or double-processed)
- [ ] Scans survive Pantheon 504s and resume from checkpoint without restarting Phase 1
- [ ] A site with 5,000+ files completes scanning on Pantheon without timeout
- [ ] `$context['finished']` accurately reflects progress for the batch progress bar

---

## FR-6: Bounded Cache Reset Strategy

### Problem

The base spec calls for entity cache resets after each `scan*Chunk()` call. With time-budgeted chunks yielding every 4 seconds, cache resets happen much more frequently than before (potentially every 4 seconds instead of every few minutes). `resetCache()` and `drupal_static_reset()` have non-trivial cost.

### Design

Cache resets occur once per batch callback (at callback exit), not per item. This aligns with the existing spec's "after each `scan*Chunk()` call" language — each batch callback invokes the chunk method once.

**Additional guard:** If a callback processes fewer than 50 items (very fast yield), skip the full `drupal_static_reset()` and only reset the entity storage caches for the phase's entity types. This prevents reset overhead from dominating short callbacks.

```php
// At exit of each scan*Chunk() method:
$resetTypes = ['digital_asset_item', 'digital_asset_usage', ...]; // phase-specific
foreach ($resetTypes as $entityType) {
  if ($this->entityTypeManager->hasDefinition($entityType)) {
    $this->entityTypeManager->getStorage($entityType)->resetCache();
  }
}

// Only do full static reset if we processed a meaningful number of items,
// to avoid overhead dominating very short callbacks.
if ($context['sandbox']['chunk_items_processed'] >= 50) {
  drupal_static_reset();
}
```

### Acceptance Criteria

- [ ] Memory growth is bounded during large scans (no linear growth with asset count)
- [ ] Cache reset does not dominate callback runtime on short-yield callbacks
- [ ] Entity storage caches are always reset per callback (all phases)
- [ ] `drupal_static_reset()` is called when chunk processed >= 50 items

---

## FR-7: Configurable Time Budget

### Problem

The 4-second time budget (FR-5) is conservative and works well across all hosting platforms. However, on generous environments (dedicated VPS, local development, or platforms with 120+ second timeouts), the short budget means more HTTP round-trips than necessary, increasing total scan duration due to per-request Batch API overhead (serialization, redirect, AJAX handling). Conversely, some edge-case hosting configurations may have even tighter timeouts (e.g., a reverse proxy set to 30 seconds), where 4 seconds leaves less headroom.

### Design

Move the time budget from a class constant to Drupal configuration, alongside the stale threshold (FR-3).

**Config key:** `digital_asset_inventory.settings.scan_batch_time_budget_seconds`

**Default:** `4` (seconds)

**Validation:**
- Minimum: 1 second (below this, overhead dominates and scans barely progress)
- Maximum: 30 seconds (above this, risk of timeout on most hosted platforms)
- If value is outside range or non-numeric: use default (4) and log a warning

**Reading the value:**

```php
public function getBatchTimeBudget(): int {
  $config = $this->configFactory->get('digital_asset_inventory.settings');
  $budget = $config->get('scan_batch_time_budget_seconds');

  if (!is_numeric($budget) || $budget < 1 || $budget > 30) {
    $this->logger->warning('Invalid time budget @value, using default 4s.', [
      '@value' => $budget,
    ]);
    return 4;
  }

  return (int) $budget;
}
```

**Integration with FR-5:** Replace `self::BATCH_TIME_BUDGET_SECONDS` in all `scan*Chunk()` methods with `$this->getBatchTimeBudget()`. The constant is retained as a fallback default but is no longer the runtime source of truth.

**Tuning guidance (for documentation/README):**

| Environment | Recommended Budget | Rationale |
|-------------|-------------------|-----------|
| Pantheon | 4s (default) | ~59s request timeout, need headroom for Batch API overhead |
| Acquia | 4–8s | Similar timeout profile to Pantheon |
| Platform.sh | 4–8s | 60s default timeout, configurable |
| Dedicated VPS / AWS | 10–15s | Higher timeouts, fewer round-trips = faster total scan |
| Local / DDEV / Lando | 15–20s | No practical timeout, minimize round-trips |
| Aggressive reverse proxy | 2–3s | If proxy timeout is 30s or lower |

### Acceptance Criteria

- [ ] Time budget is configurable via `drush cset` or config form without code changes
- [ ] Invalid values fall back to 4 with a log warning
- [ ] `scan*Chunk()` methods read the configured budget, not a hardcoded constant
- [ ] Changing the budget from 4 to 15 on a local environment measurably reduces total batch requests
- [ ] Changing the budget from 4 to 2 on a tight-timeout environment keeps requests within limits

---

## FR-8: Per-Request Batch Timing Logs

### Problem

When a scan fails on a new hosting platform or under changed server conditions, there is no way to determine how close each batch request was to the timeout limit. Diagnosing whether the issue is chunk duration, Batch API overhead, or database latency requires adding ad-hoc logging and redeploying — slow and impractical on production.

### Design

Add structured debug-level logging at the end of each batch callback. Every `batchProcess*` static method logs a summary line when it returns control to the Batch API.

**Log level:** `debug` (does not appear in production logs unless debug logging is explicitly enabled)

**Log format:**

```php
$this->logger->debug('Batch request complete. Phase: @phase, Items processed: @items, Elapsed: @elapsed s, Cursor: @cursor, Heartbeat writes: @hb_writes, Budget: @budget s', [
  '@phase' => $phaseNumber,
  '@items' => $itemsProcessedThisCallback,
  '@elapsed' => round(microtime(true) - $callbackStartTime, 2),
  '@cursor' => $context['sandbox']['last_id'] ?? 'n/a',
  '@hb_writes' => $heartbeatWriteCount,
  '@budget' => $this->getBatchTimeBudget(),
]);
```

**Fields:**

| Field | Source | Purpose |
|-------|--------|---------|
| Phase | Phase number (1-5) | Identify which phase is slow |
| Items processed | Counter incremented per item in the callback | Throughput measurement |
| Elapsed | `microtime(true)` delta from callback entry | How close to budget/timeout |
| Cursor | `$context['sandbox']['last_id']` | Resume point for debugging skips |
| Heartbeat writes | Counter from `maybeUpdateHeartbeat()` | Verify heartbeat is firing |
| Budget | `getBatchTimeBudget()` | Confirm active configuration |

**Additional log on 504 recovery:** When `buildForm()` detects a stale lock after an apparent AJAX failure, log the recovery context:

```php
$this->logger->notice('Scan recovery detected. Previous session: @session, Checkpoint phase: @phase, Stale-break: @broke', [
  '@session' => $checkpoint['session_id'] ?? 'unknown',
  '@phase' => $checkpoint['phase'] ?? 'none',
  '@broke' => $staleLockBroken ? 'yes' : 'no',
]);
```

This log fires in `submitForm()` when a stale lock is broken before resuming, providing a clear trail of 504-recovery events.

**Implementation notes:**
- The `$callbackStartTime` is set at the top of each `batchProcess*` method (same location as the existing chunk-entry heartbeat).
- The `$heartbeatWriteCount` is tracked as a local counter inside `maybeUpdateHeartbeat()` (or via a getter on the scanner), reset per callback.
- Debug-level logging ensures zero overhead in production unless explicitly enabled via Drupal's logging configuration or a module like `dblog` with level filtering.

### Acceptance Criteria

- [ ] Every batch callback logs phase, items processed, elapsed time, cursor, heartbeat writes, and budget
- [ ] Logs are at `debug` level (not visible in default production logging)
- [ ] Elapsed time accurately reflects wall-clock duration of the callback
- [ ] Recovery events (stale-break before resume) are logged at `notice` level with session context
- [ ] On a test scan, logs clearly show whether requests are approaching the timeout boundary
- [ ] Enabling debug logging does not materially impact scan performance

---

## LR-1: Stale Check Uses Session-Scoped Heartbeat

This is the lock-behavior counterpart of FR-1. `isScanLockStale()` is updated to:

1. Read `dai.scan.checkpoint.session_id`.
2. If session ID exists → read `dai.scan.{session_id}.heartbeat` → compare against configured threshold (FR-3).
3. If no session-scoped heartbeat → fall back to `dai.scan.checkpoint.started` with grace window.
4. If neither exists → orphan lock → stale.

This replaces the current global heartbeat read. The 3-tier fallback logic is preserved but now session-aware.

### Acceptance Criteria

- [ ] Stale check reads the correct session's heartbeat, not a global key
- [ ] Grace-window fallback still works when heartbeat is missing but `started` exists
- [ ] Orphan lock (no heartbeat, no started) is correctly detected as stale

---

## LR-2: Fast Stale-Lock Break

### Problem

`breakStaleLock()` currently runs `SELECT COUNT(*)` queries against `digital_asset_item` and `digital_asset_usage` tables for forensic logging. On a site with 5,000+ temp rows, these queries add seconds to the stale-break operation — which itself runs in a form submission that has its own timeout budget.

### Design

Replace fresh count queries with cached checkpoint values:

```php
public function breakStaleLock(): void {
  // Guardrails (unchanged).
  if (!$this->isScanLocked() || !$this->isScanLockStale()) {
    $this->logger->warning('breakStaleLock() called without meeting preconditions.');
    return;
  }
  
  // Read checkpoint values for logging (no fresh DB queries).
  $checkpoint = $this->getCheckpoint();
  $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
  $heartbeat = $sessionId 
    ? $this->state->get("dai.scan.{$sessionId}.heartbeat") 
    : NULL;
  $started = $checkpoint['started'] ?? NULL;
  
  $this->logger->warning('Breaking stale scan lock. Session: @session, Heartbeat: @hb, Started: @started, Now: @now, Phase: @phase, Saved item count: @items, Saved usage count: @usage', [
    '@session' => $sessionId ?? 'unknown',
    '@hb' => $heartbeat ?? 'none',
    '@started' => $started ?? 'none',
    '@now' => time(),
    '@phase' => $checkpoint['phase'] ?? 'none',
    '@items' => $checkpoint['temp_item_count'] ?? 'unknown',
    '@usage' => $checkpoint['temp_usage_count'] ?? 'unknown',
  ]);
  
  // Delete lock from semaphore table.
  $this->persistentLock->release('digital_asset_inventory_scan');
  
  // Clean up session-scoped keys.
  if ($sessionId) {
    $this->state->delete("dai.scan.{$sessionId}.heartbeat");
  }
}
```

### Acceptance Criteria

- [ ] `breakStaleLock()` performs zero `SELECT COUNT(*)` queries
- [ ] Forensic log includes session ID, heartbeat, started, phase, and saved counts from checkpoint
- [ ] Missing values are logged as "unknown" or "none" — never queried fresh
- [ ] Stale-break completes in <100ms even on large sites

---

## Updated Services Configuration

```yaml
# digital_asset_inventory.services.yml (updated arguments)
digital_asset_inventory.scanner:
  class: Drupal\digital_asset_inventory\Service\DigitalAssetScanner
  arguments:
    - '@entity_type.manager'
    - '@database'
    - '@logger.factory'
    - '@lock.persistent'
    - '@state'
    - '@config.factory'    # NEW — for FR-3 configurable threshold
    # ... existing arguments
```

```yaml
# config/install/digital_asset_inventory.settings.yml (new keys)
scan_lock_stale_threshold_seconds: 900
scan_batch_time_budget_seconds: 4
```

```yaml
# config/schema/digital_asset_inventory.schema.yml (additions)
digital_asset_inventory.settings:
  type: config_object
  label: 'Digital Asset Inventory settings'
  mapping:
    scan_lock_stale_threshold_seconds:
      type: integer
      label: 'Stale lock detection threshold in seconds (120-7200, default 900)'
    scan_batch_time_budget_seconds:
      type: integer
      label: 'Time budget per batch callback in seconds (1-30, default 4)'
```

---

## Implementation Priority

Ordered by impact on the 504 problem:

| Priority | Requirement | Impact | Effort |
|----------|-------------|--------|--------|
| **P0** | FR-5: Time-budgeted chunks | Directly prevents 504 timeouts | High — refactors all 5 scan*Chunk methods |
| **P0** | FR-2: Intra-chunk heartbeat | Prevents false stale breaks during remaining long operations | Low — add `maybeUpdateHeartbeat()` calls |
| **P0** | FR-3: Configurable stale threshold | Immediate relief while FR-5 rolls out | Low — config + getter |
| **P0** | FR-7: Configurable time budget | Enables per-environment tuning of FR-5 | Low — config + getter, builds on FR-5 |
| **P0** | FR-8: Per-request batch timing logs | Essential for diagnosing timeout issues on any platform | Low — debug log lines in existing callbacks |
| **P1** | FR-4: Sandbox statistics | Reduces DB pressure per chunk | Medium — refactor orphan counting |
| **P1** | FR-1: Session-scoped heartbeat | Prevents cross-session heartbeat corruption | Medium — key migration |
| **P1** | LR-2: Fast stale-break | Prevents stale-break itself from timing out | Low — remove count queries |
| **P2** | FR-6: Bounded cache resets | Performance tuning for frequent yields | Low — conditional guard |
| **P2** | LR-1: Session-scoped stale check | Correctness improvement | Low — follows FR-1 |

**Recommended rollout:**
- **Phase 1 (critical):** FR-5 + FR-7 + FR-2 + FR-3 + FR-8 + LR-2 — addresses the 504 problem directly and provides the diagnostic tooling to validate the fix across environments
- **Phase 2 (hardening):** FR-1 + FR-4 + LR-1 + FR-6 — reduces DB pressure and improves correctness

---

## Files to Modify

| File | Changes |
|------|---------|
| `src/Service/DigitalAssetScanner.php` | `maybeUpdateHeartbeat()`, `getStaleLockThreshold()`, `getBatchTimeBudget()`, `persistOrphanCount()`, session-scoped heartbeat keys, refactor `scan*Chunk()` methods to time-budgeted loops with configurable budget, per-callback debug timing logs, cache reset guards, fast `breakStaleLock()` |
| `src/Form/ScanAssetsForm.php` | Update heartbeat key references to session-scoped, pass session ID context, add recovery `notice` log on stale-break before resume |
| `digital_asset_inventory.services.yml` | Add `@config.factory` argument |
| `config/install/digital_asset_inventory.settings.yml` | Add `scan_lock_stale_threshold_seconds: 900` and `scan_batch_time_budget_seconds: 4` |
| `config/schema/digital_asset_inventory.schema.yml` | Schema for new config keys (`scan_lock_stale_threshold_seconds`, `scan_batch_time_budget_seconds`) |

---

## Test Plan

### Unit Tests

| Test | Validates |
|------|-----------|
| Two simulated sessions with different IDs do not overwrite each other's heartbeat | FR-1 |
| `maybeUpdateHeartbeat()` writes at most once per 2-second interval | FR-2 |
| Invalid stale threshold values fall back to 900 with log warning | FR-3 |
| Sandbox orphan counter increments correctly across multiple items | FR-4 |
| Orphan count persisted exactly once per callback exit | FR-4 |
| `isScanLockStale()` uses configured threshold, not hardcoded 120 | FR-3 |
| Checkpoint monotonicity preserved with time-budgeted yields | INV-4 |
| `breakStaleLock()` does not execute any count queries | LR-2 |
| Invalid time budget values (0, -1, 50, "abc") fall back to 4 with log warning | FR-7 |
| `getBatchTimeBudget()` returns configured value when valid (1-30) | FR-7 |
| Batch callback debug log includes all required fields (phase, items, elapsed, cursor, heartbeat writes, budget) | FR-8 |
| Recovery log fires at `notice` level when stale lock is broken before resume | FR-8 |

### Kernel Tests

| Test | Validates |
|------|-----------|
| Batch callback yields before 4-second budget (± 1 item tolerance) | FR-5 |
| Cursor resumes correctly after yield — no items skipped or duplicated | FR-5 |
| Full scan produces identical results with time-budgeted vs unbounded chunks | FR-5 |
| Memory stays bounded over 1,000+ items with frequent yields | FR-6 |
| Cache reset skipped for short callbacks (<50 items) | FR-6 |
| Setting budget to 15s results in fewer total batch requests than budget of 4s on same dataset | FR-7 |
| Setting budget to 1s results in more total batch requests but each callback stays under 2s elapsed | FR-7 |
| Debug log elapsed time accurately reflects wall-clock duration (within 100ms tolerance) | FR-8 |
| Debug logs are not emitted when logging level is above debug | FR-8 |

### Manual Test Plan (Pantheon and Other Environments)

| Test | Environment | Expected |
|------|-------------|----------|
| Scan site with ~2,800 files | Pantheon | Completes without 504 |
| Scan site with ~5,000 files | Pantheon | Completes without 504 (many short batch requests visible in progress bar) |
| Scan site with ~5,500 files | Pantheon | Completes without 504 |
| Scan site with ~5,000 files, budget set to 15s | Local / VPS | Completes faster (fewer batch requests) than with 4s budget |
| Scan site with ~5,000 files, budget set to 2s | Pantheon | Completes without 504 (extra headroom for tight environments) |
| Induce 504 by setting budget to 0.5s artificially | Pantheon | Scan still progresses via resume after AJAX error |
| Open two tabs during scan | Any | Second tab shows "scan running" — no false stale break |
| Navigate away mid-scan, wait 15+ min, return | Any | Shows "Previous scan appears interrupted" — correct stale detection |
| Navigate away mid-scan, return within 5 min | Any | Shows "scan running" — no false stale (threshold = 900s) |
| Enable debug logging, run scan, inspect logs | Any | Each batch request logs phase, items, elapsed, cursor, heartbeat writes, budget |
| Trigger stale-break resume, inspect logs | Any | Recovery log at `notice` level includes previous session ID, phase, and stale-break confirmation |
| Run scan on Acquia with default config (4s budget, 900s threshold) | Acquia | Completes without timeout for 5,000+ files |
| Run scan on Platform.sh with default config | Platform.sh | Completes without timeout for 5,000+ files |

---

## Key Design Decisions

**Why 4-second default budget, not adaptive?** Adaptive budgets (e.g., "80% of remaining timeout") require knowing the platform's actual timeout, which isn't exposed to PHP. A fixed default is predictable and safe. FR-7 makes this configurable (1–30 seconds) so administrators can tune per environment — increase to 15s on a local machine for faster scans, decrease to 2s on an aggressive reverse proxy. The tradeoff at lower values is more batch requests (and thus more total overhead), but each request is safe.

**Why debug-level timing logs (FR-8)?** Production Drupal sites typically log at `warning` or above. Debug-level logging adds zero overhead unless explicitly enabled, but becomes invaluable when diagnosing timeout issues on a new platform. The per-request log line answers the critical question — "how close are my batch requests to the timeout?" — without requiring a code change or redeploy. The `notice`-level recovery log fires only on actual 504 recoveries, which are rare enough to warrant default visibility.

**Why not replace Batch API with a queue worker?** A queue-based approach (Drupal Queue + cron) would eliminate the AJAX timeout problem entirely but requires significant architectural changes, a reliable cron runner, and a different UX for progress reporting. This is a valid future direction but out of scope for this fix. The time-budgeted Batch API approach solves the immediate problem with minimal architectural change.

**Why 900 seconds for stale threshold?** With intra-chunk heartbeats every 2 seconds, a 15-minute gap means the scan is genuinely dead (not just slow). The old 120-second threshold was designed for chunk-boundary-only heartbeats and is inappropriate with the new rate-limited approach. 900 seconds provides a 450x safety margin over the 2-second heartbeat interval.

**Why not per-environment threshold?** Pantheon multi-dev environments share the same database and config. A per-environment key would require runtime detection of the environment, adding complexity. The 900-second default is safe for all environments — local development just means stale scans take longer to detect, which is acceptable since local scans rarely crash.
