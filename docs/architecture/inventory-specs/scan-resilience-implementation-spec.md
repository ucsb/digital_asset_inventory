# Scan Resilience Enhancements

## Context

The Digital Asset Inventory scanner fails on Pantheon hosting for sites with 5,000+ files due to HTTP 504 timeouts (~59s request limit). Three interacting root causes: unbounded chunk duration, coarse heartbeat causing false stale detection, and per-item State writes adding thousands of extra DB round-trips.

This plan implements 8 functional requirements (FR-1 through FR-8), 2 lock-behavior refinements (LR-1, LR-2), across 7 files. All invariants (INV-1 through INV-5) and requirements (REQ-001 through REQ-005) from the base Scan Resilience Specification remain in effect.

---

## Files to Modify

| File | Changes |
|------|---------|
| `config/install/digital_asset_inventory.settings.yml` | Add 2 new config keys |
| `config/schema/digital_asset_inventory.schema.yml` | Schema for 2 new config keys |
| `digital_asset_inventory.install` | Update hook for existing sites |
| `digital_asset_inventory.services.yml` | Verify/add `@config.factory` argument |
| `src/Form/SettingsForm.php` | "Scanner Settings" details group |
| `src/Service/DigitalAssetScanner.php` | Core: all FR/LR changes |
| `src/Form/ScanAssetsForm.php` | Thin batch callbacks, recovery log |

---

## Step 1: Config, Schema, Settings Form, Update Hook

**Implements:** FR-3 (configurable stale threshold), FR-7 (configurable time budget)

**Why first:** All subsequent steps depend on config being available.

### 1a. `config/install/digital_asset_inventory.settings.yml`

Add these two keys. Place them before `asset_types` (or wherever scanner-related config logically groups):

```yaml
# Scanner settings.
scan_lock_stale_threshold_seconds: 900
scan_batch_time_budget_seconds: 4
```

### 1b. `config/schema/digital_asset_inventory.schema.yml`

Add under `digital_asset_inventory.settings` → `mapping`:

```yaml
    scan_lock_stale_threshold_seconds:
      type: integer
      label: 'Stale lock detection threshold in seconds (120-7200, default 900)'
    scan_batch_time_budget_seconds:
      type: integer
      label: 'Time budget per batch callback in seconds (1-30, default 4)'
```

### 1c. `src/Form/SettingsForm.php`

Add a "Scanner Settings" details group. Follow the same pattern as existing "Archive Settings" and "Archive Classification Settings" groups in this file.

In `buildForm()`, add after the last existing details group:

```php
$form['scanner'] = [
  '#type' => 'details',
  '#title' => $this->t('Scanner Settings'),
  '#open' => FALSE,
  '#attributes' => ['role' => 'group'],
];

$form['scanner']['scan_batch_time_budget_seconds'] = [
  '#type' => 'number',
  '#title' => $this->t('Batch time budget (seconds)'),
  '#description' => $this->t('Maximum seconds of scan work per batch request. Lower values (2-4) are safer for hosting with strict timeouts (e.g., Pantheon). Higher values (10-20) reduce total scan time on generous environments. Default: 4.'),
  '#default_value' => $config->get('scan_batch_time_budget_seconds') ?? 4,
  '#min' => 1,
  '#max' => 30,
];

$form['scanner']['scan_lock_stale_threshold_seconds'] = [
  '#type' => 'number',
  '#title' => $this->t('Stale lock threshold (seconds)'),
  '#description' => $this->t('How long before an unresponsive scan is considered abandoned. The scanner sends heartbeats every 2 seconds during active work. Default: 900 (15 minutes).'),
  '#default_value' => $config->get('scan_lock_stale_threshold_seconds') ?? 900,
  '#min' => 120,
  '#max' => 7200,
];
```

In `submitForm()`, add saves for both values (follow the pattern used for other settings in this method):

```php
$config->set('scan_batch_time_budget_seconds', $form_state->getValue('scan_batch_time_budget_seconds'));
$config->set('scan_lock_stale_threshold_seconds', $form_state->getValue('scan_lock_stale_threshold_seconds'));
```

### 1d. `digital_asset_inventory.install`

Add update hook. **IMPORTANT:** Check the last update hook number in this file and use the next sequential number. Do NOT hardcode `10067` — inspect the file first.

```php
/**
 * Add scan resilience configuration defaults.
 */
function digital_asset_inventory_update_NEXT_NUMBER() {
  $config = \Drupal::configFactory()->getEditable('digital_asset_inventory.settings');
  if ($config->get('scan_lock_stale_threshold_seconds') === NULL) {
    $config->set('scan_lock_stale_threshold_seconds', 900);
  }
  if ($config->get('scan_batch_time_budget_seconds') === NULL) {
    $config->set('scan_batch_time_budget_seconds', 4);
  }
  $config->save();
  return t('Added scan resilience configuration defaults.');
}
```

### 1e. `digital_asset_inventory.services.yml`

Verify the scanner service definition. It needs these arguments (among any existing ones):

- `@lock.persistent`
- `@state`
- `@config.factory`

If `@config.factory` is missing, add it. Check the constructor of `DigitalAssetScanner.php` to confirm which position it should be in. If the constructor doesn't accept `ConfigFactoryInterface` yet, that will be added in Step 2.

---

## Step 2: Scanner — Configurable Getters

**Implements:** FR-3, FR-7 (runtime config reading)

**File:** `src/Service/DigitalAssetScanner.php`

### 2a. Constructor update (if needed)

If `@config.factory` is not already injected, add it:

```php
use Drupal\Core\Config\ConfigFactoryInterface;

// In constructor:
protected ConfigFactoryInterface $configFactory,
```

Update the `services.yml` argument list to match the constructor position.

### 2b. Constants (fallback defaults only)

Update the existing stale threshold constant and add the time budget constant. These serve as fallbacks if config is unavailable:

```php
/**
 * Fallback stale lock threshold. Runtime value comes from config via getStaleLockThreshold().
 */
const SCAN_LOCK_STALE_THRESHOLD = 900;

/**
 * Fallback time budget. Runtime value comes from config via getBatchTimeBudget().
 */
const BATCH_TIME_BUDGET_SECONDS = 4;
```

### 2c. Getter methods

```php
/**
 * Gets the configured stale lock threshold.
 *
 * Reads from config with validation. Falls back to 900 on invalid values.
 */
public function getStaleLockThreshold(): int {
  $config = $this->configFactory->get('digital_asset_inventory.settings');
  $threshold = $config->get('scan_lock_stale_threshold_seconds');

  if (!is_numeric($threshold) || $threshold < 120 || $threshold > 7200) {
    $this->logger->warning('Invalid stale threshold @value, using default 900s.', [
      '@value' => $threshold ?? 'NULL',
    ]);
    return self::SCAN_LOCK_STALE_THRESHOLD;
  }

  return (int) $threshold;
}

/**
 * Gets the configured batch time budget.
 *
 * Reads from config with validation. Falls back to 4 on invalid values.
 */
public function getBatchTimeBudget(): int {
  $config = $this->configFactory->get('digital_asset_inventory.settings');
  $budget = $config->get('scan_batch_time_budget_seconds');

  if (!is_numeric($budget) || $budget < 1 || $budget > 30) {
    $this->logger->warning('Invalid time budget @value, using default 4s.', [
      '@value' => $budget ?? 'NULL',
    ]);
    return self::BATCH_TIME_BUDGET_SECONDS;
  }

  return (int) $budget;
}
```

### 2d. Update `isScanLockStale()`

Find the existing `isScanLockStale()` method. Replace every reference to `self::SCAN_LOCK_STALE_THRESHOLD` with `$this->getStaleLockThreshold()`.

---

## Step 3: Intra-Chunk Heartbeat

**Implements:** FR-2 (rate-limited heartbeat inside work loops)

**File:** `src/Service/DigitalAssetScanner.php`

### 3a. Properties

Add to the class:

```php
/**
 * Timestamp of the last heartbeat write in this request.
 *
 * Resets to 0 every batch callback because the scanner service is
 * re-instantiated per HTTP request by Batch API. This is correct —
 * the first maybeUpdateHeartbeat() call in each callback will always
 * write, ensuring the heartbeat is fresh at callback entry.
 */
private int $lastHeartbeatWrite = 0;

/**
 * Count of heartbeat writes in this callback (for FR-8 diagnostic logging).
 */
private int $heartbeatWriteCount = 0;

private const HEARTBEAT_INTERVAL_SECONDS = 2;
```

### 3b. Methods

```php
/**
 * Conditionally updates heartbeat if interval has elapsed.
 *
 * Cheap to call per-item — only writes to State when the interval
 * has actually elapsed. At most 1 State write per 2 seconds.
 */
public function maybeUpdateHeartbeat(): void {
  $now = time();
  if (($now - $this->lastHeartbeatWrite) >= self::HEARTBEAT_INTERVAL_SECONDS) {
    $this->updateScanHeartbeat();
    $this->lastHeartbeatWrite = $now;
    $this->heartbeatWriteCount++;
  }
}

/**
 * Returns the number of heartbeat writes in this callback.
 */
public function getHeartbeatWriteCount(): int {
  return $this->heartbeatWriteCount;
}

/**
 * Resets heartbeat write counter. Call at start of each batch callback.
 */
public function resetHeartbeatWriteCount(): void {
  $this->heartbeatWriteCount = 0;
}
```

### 3c. Integration

Do NOT add `maybeUpdateHeartbeat()` calls yet — that happens in Step 4 when the scan*Chunk() methods are refactored. Adding calls to the old method signatures would create an intermediate broken state.

---

## Step 4: Time-Budgeted Chunk Processing

**Implements:** FR-5 (time-budgeted chunks), FR-6 (bounded cache resets), FR-8 (timing logs)

**File:** `src/Service/DigitalAssetScanner.php` and `src/Form/ScanAssetsForm.php`

**This is the largest and most complex change. Read fully before starting.**

### 4a. Shared Helper for ID-Cursor Phases (Phases 1, 4, 5)

Phases 1, 4, and 5 share an identical pattern: ID-based cursor, entity/DB query with `> last_id ORDER BY id ASC LIMIT N`, time-budget loop, exhaustion guard. Extract this into a reusable method to reduce duplication and ensure consistent behavior:

```php
/**
 * Processes entities using an ID-based cursor with time budget.
 *
 * Handles cursor management, time budget enforcement, heartbeat updates,
 * exhaustion guards, and progress calculation. Phases with simple monotonic
 * ID cursors (1, 4, 5) should use this instead of implementing their own loop.
 *
 * @param array &$context
 *   Batch API context array.
 * @param string $cursorKey
 *   Sandbox key for the cursor (e.g., 'last_fid', 'last_mid', 'last_id').
 * @param string $totalKey
 *   Sandbox key for the total count (e.g., 'total_files', 'total_media').
 * @param callable $countFn
 *   Function returning total item count. Called once on first invocation.
 *   Signature: function(): int
 * @param callable $queryFn
 *   Function returning array of IDs to process.
 *   Signature: function(int $lastId, int $limit): array
 * @param callable $processFn
 *   Function to process a single item.
 *   Signature: function(int $id): void
 *
 * @return int
 *   Number of items processed in this callback.
 */
protected function processWithTimeBudget(
  array &$context,
  string $cursorKey,
  string $totalKey,
  callable $countFn,
  callable $queryFn,
  callable $processFn,
): int {
  $budget = $this->getBatchTimeBudget();
  $startTime = microtime(true);
  $itemsThisCallback = 0;

  // Initialize on first call.
  if (!isset($context['sandbox'][$cursorKey])) {
    $context['sandbox'][$cursorKey] = 0;
    $context['sandbox'][$totalKey] = ($countFn)();
    $context['sandbox']['processed'] = 0;
  }

  $lastId = $context['sandbox'][$cursorKey];

  // Fetch a batch of IDs (fetch more than we'll likely process in one budget window).
  $ids = ($queryFn)($lastId, 100);

  // Exhaustion guard: no more items means phase is done.
  if (empty($ids)) {
    $context['finished'] = 1;
    return $itemsThisCallback;
  }

  foreach ($ids as $id) {
    // Time check BEFORE processing — never start an item we can't finish within budget.
    if ((microtime(true) - $startTime) >= $budget) {
      break;
    }

    ($processFn)($id);
    $this->maybeUpdateHeartbeat();

    $context['sandbox'][$cursorKey] = $id;
    $context['sandbox']['processed']++;
    $itemsThisCallback++;
  }

  // Progress calculation.
  $total = $context['sandbox'][$totalKey];
  if ($total > 0) {
    $context['finished'] = $context['sandbox']['processed'] / $total;
  }
  // Clamp to 1 if we've processed everything.
  if ($context['finished'] >= 1) {
    $context['finished'] = 1;
  }

  return $itemsThisCallback;
}
```

### 4b. Phase 1 — Managed Files

Refactor `scanManagedFilesChunk()`. New signature:

```php
public function scanManagedFilesChunk(array &$context, bool $is_temp): void
```

**Implementation:**

```php
public function scanManagedFilesChunk(array &$context, bool $is_temp): void {
  // Initialize sandbox orphan counter for FR-4.
  if (!isset($context['sandbox']['orphan_paragraph_count'])) {
    $context['sandbox']['orphan_paragraph_count'] = 0;
  }

  $itemsThisCallback = $this->processWithTimeBudget(
    $context,
    'last_fid',
    'total_files',
    // Count function — must replicate existing exclusion conditions exactly.
    fn() => $this->countManagedFiles(),
    // Query function — must replicate existing exclusion conditions exactly.
    fn(int $lastFid, int $limit) => $this->getManagedFileIdsAfter($lastFid, $limit),
    // Process function — existing per-file logic.
    fn(int $fid) => $this->processManagedFile($fid, $is_temp, $context),
  );

  // FR-4: Persist orphan count once per callback (cumulative total from sandbox).
  $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
  if ($sessionId && $context['sandbox']['orphan_paragraph_count'] > 0) {
    $this->persistOrphanCount($sessionId, $context['sandbox']['orphan_paragraph_count']);
  }

  // FR-6: Cache resets.
  $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'media', 'file']);
  if ($itemsThisCallback >= 50) {
    drupal_static_reset();
  }
}
```

**CRITICAL — Exclusion conditions:** The `countManagedFiles()` and `getManagedFileIdsAfter()` methods (new or refactored from existing code) MUST replicate the exact same WHERE conditions as the current `scanManagedFilesChunk()`. Inspect the current code for any conditions on file status, MIME type, URI scheme, etc. If converting from entity query to direct DB query, verify parity. Missing a condition could cause the scan to process files it shouldn't or skip files it should.

**`getManagedFileIdsAfter()` pattern:**

```php
protected function getManagedFileIdsAfter(int $lastFid, int $limit): array {
  // Direct DB query for IDs only (no entity loading).
  $query = $this->database->select('file_managed', 'fm')
    ->fields('fm', ['fid'])
    ->condition('fid', $lastFid, '>')
    ->orderBy('fid', 'ASC')
    ->range(0, $limit);

  // ADD ALL EXISTING EXCLUSION CONDITIONS HERE.
  // Check the current scanManagedFilesChunk() for any ->condition() calls
  // on uri, filemime, status, etc. and replicate them exactly.

  return $query->execute()->fetchCol();
}
```

**Orphan counting (FR-4):** Find the existing `incrementOrphanCount()` calls inside the per-file processing logic (~4 calls based on prior analysis). Replace each with:

```php
// OLD:
$this->incrementOrphanCount();
// NEW:
$context['sandbox']['orphan_paragraph_count']++;
```

This requires `$context` to be passed through to the processing function. The `processManagedFile()` method must accept `$context` by reference or the orphan counter must be accumulated via a different mechanism. Options:

- **Option A (preferred):** Pass `&$context` to `processManagedFile()`. The `processWithTimeBudget` helper's `$processFn` closure captures `$context` by reference.
- **Option B:** Use a scanner property `private int $orphanCount = 0;` incremented during processing, then copy to sandbox at callback exit. Simpler but property resets per request (fine — sandbox persists the cumulative total).

### 4c. Phase 2 — Orphan Files (Filesystem-Based)

This phase cannot use `processWithTimeBudget()` because it has no DB IDs. Custom implementation.

New signature:

```php
public function scanOrphanFilesChunk(array &$context, bool $is_temp): void
```

**Design decisions:**
- Rebuild the orphan file list from filesystem each callback (not cached in sandbox).
- Rationale: Storing 5,000 file paths (~500KB-1MB) in `$context['sandbox']` would be serialized into the `batch` table on every round-trip. On Pantheon with higher DB latency, this serialization overhead could itself approach the time budget. Filesystem reads via `scanDirectoryRecursive()` typically complete in <1 second.
- Track `$context['sandbox']['orphan_index']` as an integer offset into the list.
- The orphan list is "best-effort consistent" — if files are created/deleted between callbacks, the index may skip or repeat an item. This matches the trade-off documented in base spec EC-4 (content changes between scan start and resume). The next full scan reconciles.

```php
public function scanOrphanFilesChunk(array &$context, bool $is_temp): void {
  $budget = $this->getBatchTimeBudget();
  $startTime = microtime(true);
  $itemsThisCallback = 0;

  // Rebuild orphan file list each callback (filesystem read is fast;
  // avoids serializing thousands of paths through the batch table).
  $orphanFiles = $this->buildOrphanFileList();

  // Initialize on first call.
  if (!isset($context['sandbox']['orphan_index'])) {
    $context['sandbox']['orphan_index'] = 0;
    $context['sandbox']['orphan_total'] = count($orphanFiles);
  }

  $index = $context['sandbox']['orphan_index'];
  $total = count($orphanFiles);

  // Exhaustion guard.
  if ($index >= $total || empty($orphanFiles)) {
    $context['finished'] = 1;
    return;
  }

  // Process items from current index until budget exhausted.
  while ($index < $total) {
    if ((microtime(true) - $startTime) >= $budget) {
      break;
    }

    $this->processOrphanFile($orphanFiles[$index], $is_temp);
    $this->maybeUpdateHeartbeat();

    $index++;
    $itemsThisCallback++;
  }

  $context['sandbox']['orphan_index'] = $index;

  // Progress.
  if ($total > 0) {
    $context['finished'] = $index / $total;
  }
  if ($index >= $total) {
    $context['finished'] = 1;
  }

  // FR-6: Cache resets.
  $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'file']);
  if ($itemsThisCallback >= 50) {
    drupal_static_reset();
  }
}
```

**NOTE on `buildOrphanFileList()`:** This should return the same sorted array each call (deterministic sort order). If the existing `scanDirectoryRecursive()` or equivalent already returns sorted results, use that. If not, sort the result before returning. This ensures the index cursor is meaningful even if filesystem ordering varies between calls.

### 4d. Phase 3 — Content / External URLs (Multi-Table)

This phase cannot use `processWithTimeBudget()` because it has a compound cursor (table index + entity ID). Custom implementation using table-by-table cursor.

New signature:

```php
public function scanContentChunk(array &$context, bool $is_temp): void
```

**Design decisions:**
- Use a compound cursor: `table_index` (which field table) + `last_entity_id` (cursor within that table).
- Do NOT build a flat work list of all `(table, entity_id, column)` tuples — that could be tens of thousands of entries serialized on every round-trip.
- Store only the table list in sandbox. This is small (typically 10-30 entries, ~50 bytes each = ~1.5KB).
- Process one table at a time. When a table's entities are exhausted, advance to the next table and reset the entity cursor.

**IMPORTANT — Table list contents:** When storing the table list in `$context['sandbox']['tables']`, store only the minimal data needed for processing (table name, column name, entity type). Do NOT store rich field definition objects or schema metadata — trim to simple associative arrays before storing in sandbox.

```php
public function scanContentChunk(array &$context, bool $is_temp): void {
  $budget = $this->getBatchTimeBudget();
  $startTime = microtime(true);
  $itemsThisCallback = 0;

  // Initialize on first call.
  if (!isset($context['sandbox']['table_index'])) {
    // Build table list and store minimal info only.
    $tables = $this->getFieldTablesToScan();
    $context['sandbox']['tables'] = array_map(fn($t) => [
      'table' => $t['table'],
      'column' => $t['column'],
      'entity_type' => $t['entity_type'],
    ], $tables);
    $context['sandbox']['table_index'] = 0;
    $context['sandbox']['last_entity_id'] = 0;
    $context['sandbox']['tables_completed'] = 0;
    $context['sandbox']['total_tables'] = count($context['sandbox']['tables']);

    // Per-table entity count for progress bar.
    // One COUNT(*) query per table — cheap, runs once per table on first entry.
    $context['sandbox']['current_table_total'] = 0;
    $context['sandbox']['current_table_processed'] = 0;
  }

  $tables = $context['sandbox']['tables'];
  $tableIndex = $context['sandbox']['table_index'];

  // Exhaustion guard: all tables done.
  if ($tableIndex >= count($tables)) {
    $context['finished'] = 1;
    return;
  }

  // If entering a new table, count its entities for progress.
  if ($context['sandbox']['current_table_processed'] === 0 && $context['sandbox']['current_table_total'] === 0) {
    $context['sandbox']['current_table_total'] = $this->countEntitiesInFieldTable(
      $tables[$tableIndex]['table'],
      $tables[$tableIndex]['column']
    );
  }

  $lastEntityId = $context['sandbox']['last_entity_id'];

  // Process entities from current table.
  while ((microtime(true) - $startTime) < $budget) {
    $rows = $this->getFieldTableRows(
      $tables[$tableIndex]['table'],
      $tables[$tableIndex]['column'],
      $lastEntityId,
      50
    );

    // Current table exhausted — advance to next.
    if (empty($rows)) {
      $context['sandbox']['tables_completed']++;
      $context['sandbox']['table_index']++;
      $context['sandbox']['last_entity_id'] = 0;
      $context['sandbox']['current_table_total'] = 0;
      $context['sandbox']['current_table_processed'] = 0;
      $tableIndex = $context['sandbox']['table_index'];

      // All tables done.
      if ($tableIndex >= count($tables)) {
        $context['finished'] = 1;
        break;
      }

      // Count next table's entities.
      $context['sandbox']['current_table_total'] = $this->countEntitiesInFieldTable(
        $tables[$tableIndex]['table'],
        $tables[$tableIndex]['column']
      );
      $lastEntityId = 0;
      continue;
    }

    foreach ($rows as $row) {
      if ((microtime(true) - $startTime) >= $budget) {
        break 2;
      }

      $this->processContentRow($row, $tables[$tableIndex], $is_temp);
      $this->maybeUpdateHeartbeat();

      $lastEntityId = $row->entity_id;
      $context['sandbox']['last_entity_id'] = $lastEntityId;
      $context['sandbox']['current_table_processed']++;
      $itemsThisCallback++;
    }
  }

  // Progress calculation:
  // finished = (tables_completed + (current_table_processed / current_table_total)) / total_tables
  $totalTables = $context['sandbox']['total_tables'];
  if ($totalTables > 0) {
    $tableProgress = 0;
    $currentTotal = $context['sandbox']['current_table_total'];
    if ($currentTotal > 0) {
      $tableProgress = $context['sandbox']['current_table_processed'] / $currentTotal;
    }
    $context['finished'] = ($context['sandbox']['tables_completed'] + $tableProgress) / $totalTables;
  }
  if ($context['finished'] >= 1) {
    $context['finished'] = 1;
  }

  // FR-6: Cache resets.
  $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'node', 'paragraph', 'block_content', 'taxonomy_term']);
  if ($itemsThisCallback >= 50) {
    drupal_static_reset();
  }
}
```

**`getFieldTableRows()` pattern:**

```php
protected function getFieldTableRows(string $table, string $column, int $lastEntityId, int $limit): array {
  return $this->database->select($table, 't')
    ->fields('t')
    ->condition('entity_id', $lastEntityId, '>')
    ->orderBy('entity_id', 'ASC')
    ->range(0, $limit)
    ->execute()
    ->fetchAll();
}
```

**`countEntitiesInFieldTable()` pattern:**

```php
protected function countEntitiesInFieldTable(string $table, string $column): int {
  return (int) $this->database->select($table, 't')
    ->countQuery()
    ->execute()
    ->fetchField();
}
```

### 4e. Phase 4 — Remote Media

Uses `processWithTimeBudget()`:

```php
public function scanRemoteMediaChunk(array &$context, bool $is_temp): void {
  $itemsThisCallback = $this->processWithTimeBudget(
    $context,
    'last_mid',
    'total_media',
    fn() => $this->countRemoteMedia(),
    fn(int $lastMid, int $limit) => $this->getRemoteMediaIdsAfter($lastMid, $limit),
    fn(int $mid) => $this->processRemoteMedia($mid, $is_temp),
  );

  // FR-6: Cache resets.
  $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'media']);
  if ($itemsThisCallback >= 50) {
    drupal_static_reset();
  }
}
```

**`getRemoteMediaIdsAfter()` pattern:** Same as `getManagedFileIdsAfter()` but queries media table with existing conditions. Replicate all existing WHERE conditions exactly.

### 4f. Phase 5 — Menu Links

Uses `processWithTimeBudget()`:

```php
public function scanMenuLinksChunk(array &$context, bool $is_temp): void {
  $itemsThisCallback = $this->processWithTimeBudget(
    $context,
    'last_id',
    'total_menu_links',
    fn() => $this->countMenuLinks(),
    fn(int $lastId, int $limit) => $this->getMenuLinkIdsAfter($lastId, $limit),
    fn(int $id) => $this->processMenuLink($id, $is_temp),
  );

  // FR-6: Cache resets.
  $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'menu_link_content']);
  if ($itemsThisCallback >= 50) {
    drupal_static_reset();
  }
}
```

### 4g. Cache Reset Helper (FR-6)

```php
/**
 * Resets entity storage caches for the given entity types.
 *
 * Called at the end of each scan*Chunk(). The hasDefinition() guard
 * ensures safe operation when optional entity types (paragraph,
 * block_content) are not installed.
 */
protected function resetPhaseEntityCaches(array $entityTypes): void {
  foreach ($entityTypes as $entityType) {
    if ($this->entityTypeManager->hasDefinition($entityType)) {
      $this->entityTypeManager->getStorage($entityType)->resetCache();
    }
  }
}
```

### 4h. Batch Timing Log Method (FR-8)

```php
/**
 * Logs per-request batch timing diagnostics.
 *
 * Debug-level — zero overhead in production unless debug logging is enabled.
 *
 * @param int $phase
 *   Phase number (1-5).
 * @param int $itemsProcessed
 *   Items processed in this callback.
 * @param float $callbackStartTime
 *   microtime(true) at callback entry.
 * @param string|int $cursor
 *   Current cursor value (varies by phase).
 */
public function logBatchTiming(int $phase, int $itemsProcessed, float $callbackStartTime, string|int $cursor): void {
  $this->logger->debug('Batch request complete. Phase: @phase, Items: @items, Elapsed: @elapsed s, Cursor: @cursor, Heartbeat writes: @hb, Budget: @budget s', [
    '@phase' => $phase,
    '@items' => $itemsProcessed,
    '@elapsed' => round(microtime(true) - $callbackStartTime, 2),
    '@cursor' => $cursor,
    '@hb' => $this->getHeartbeatWriteCount(),
    '@budget' => $this->getBatchTimeBudget(),
  ]);
}
```

**NOTE:** The cursor value is passed explicitly by the batch callback because the cursor key varies by phase (`last_fid`, `orphan_index`, `table_index:last_entity_id`, `last_mid`, `last_id`). This avoids coupling the logging method to phase internals.

### 4i. Update Batch Callbacks in `ScanAssetsForm.php`

Each `batchProcess*` static method becomes a thin wrapper. The pattern is identical for all 5 phases — only the scanner method name and phase number change.

**Example — Phase 1:**

```php
public static function batchProcessManagedFiles(int $phase_number, array &$context) {
  $scanner = \Drupal::service('digital_asset_inventory.scanner');
  $scanner->resetHeartbeatWriteCount();
  $callbackStartTime = microtime(true);
  $scanner->updateScanHeartbeat();  // Bookend entry.

  $scanner->scanManagedFilesChunk($context, TRUE);

  // Checkpoint when phase completes.
  if ($context['finished'] >= 1) {
    $scanner->saveCheckpoint($phase_number, $phase_number === 5);
  }

  $scanner->updateScanHeartbeat();  // Bookend exit.

  // FR-8: Per-request timing log. Extract cursor for this phase.
  $cursor = $context['sandbox']['last_fid'] ?? 'n/a';
  $items = $context['results']['last_chunk_items'] ?? 0;
  $scanner->logBatchTiming($phase_number, $items, $callbackStartTime, $cursor);
}
```

**Cursor extraction per phase** (for the `logBatchTiming` call):

| Phase | Cursor expression |
|-------|-------------------|
| 1 | `$context['sandbox']['last_fid'] ?? 'n/a'` |
| 2 | `$context['sandbox']['orphan_index'] ?? 'n/a'` |
| 3 | `($context['sandbox']['table_index'] ?? '?') . ':' . ($context['sandbox']['last_entity_id'] ?? '?')` |
| 4 | `$context['sandbox']['last_mid'] ?? 'n/a'` |
| 5 | `$context['sandbox']['last_id'] ?? 'n/a'` |

**Item count tracking:** Each `scan*Chunk()` method must store `$itemsThisCallback` somewhere the batch callback can read it. Options:

- Store in `$context['results']['last_chunk_items']` at the end of each `scan*Chunk()`.
- Or have the batch callback compute it from sandbox delta (more complex, not recommended).

Add this line at the end of each `scan*Chunk()` method:

```php
$context['results']['last_chunk_items'] = $itemsThisCallback;
```

For phases using `processWithTimeBudget()`, capture the return value:

```php
$itemsThisCallback = $this->processWithTimeBudget(...);
$context['results']['last_chunk_items'] = $itemsThisCallback;
```

---

## Step 5: Fast Stale-Lock Break

**Implements:** LR-2

**File:** `src/Service/DigitalAssetScanner.php`

Modify `breakStaleLock()`:

- **Remove** all `SELECT COUNT(*)` queries against `digital_asset_item` and `digital_asset_usage` tables.
- **Replace** with cached checkpoint values from `$this->getCheckpoint()`.
- Log "unknown" for any missing values — never query fresh.

```php
public function breakStaleLock(): void {
  if (!$this->isScanLocked() || !$this->isScanLockStale()) {
    $this->logger->warning('breakStaleLock() called without meeting preconditions.');
    return;
  }

  $checkpoint = $this->getCheckpoint();
  $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
  $heartbeat = $sessionId
    ? $this->state->get("dai.scan.{$sessionId}.heartbeat")
    : $this->state->get('dai.scan.lock.heartbeat');  // Legacy fallback.
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
  // Also clean legacy global key if present.
  $this->state->delete('dai.scan.lock.heartbeat');
}
```

---

## Step 6: Session-Scoped Heartbeat

**Implements:** FR-1 (session-scoped keys), LR-1 (session-scoped stale check)

**File:** `src/Service/DigitalAssetScanner.php`

### 6a. `acquireScanLock()`

After acquiring the lock, initialize the session-scoped heartbeat:

```php
// After successful lock acquisition:
$sessionId = $this->state->get('dai.scan.checkpoint.session_id');
if ($sessionId) {
  $this->state->set("dai.scan.{$sessionId}.heartbeat", time());
}
// Also set legacy global key for backward compatibility during rollout.
$this->state->set('dai.scan.lock.heartbeat', time());
```

### 6b. `updateScanHeartbeat()`

Write to session-scoped key, with fallback:

```php
public function updateScanHeartbeat(): void {
  $now = time();
  $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
  if ($sessionId) {
    $this->state->set("dai.scan.{$sessionId}.heartbeat", $now);
  }
  // Legacy global key — maintain during transition. Remove in future release.
  $this->state->set('dai.scan.lock.heartbeat', $now);
}
```

### 6c. `getScanHeartbeat()`

Read session-scoped first, fall back to legacy:

```php
public function getScanHeartbeat(): ?int {
  $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
  if ($sessionId) {
    $heartbeat = $this->state->get("dai.scan.{$sessionId}.heartbeat");
    if ($heartbeat !== NULL) {
      return (int) $heartbeat;
    }
  }
  // Legacy fallback — handles scans started before this update.
  $legacy = $this->state->get('dai.scan.lock.heartbeat');
  return $legacy !== NULL ? (int) $legacy : NULL;
}
```

### 6d. `releaseScanLock()`

Clean up both keys:

```php
// In releaseScanLock(), before or after releasing the persistent lock:
$sessionId = $this->state->get('dai.scan.checkpoint.session_id');
if ($sessionId) {
  $this->state->delete("dai.scan.{$sessionId}.heartbeat");
  // Clean up any session-scoped stats keys.
  $this->state->delete("dai.scan.{$sessionId}.stats.orphan_count");
}
// Legacy global key cleanup.
$this->state->delete('dai.scan.lock.heartbeat');
```

### 6e. `isScanLockStale()` — Migration-Safe 4-Tier Fallback (LR-1)

Update the existing method. Replace the heartbeat reading logic with:

```php
public function isScanLockStale(): bool {
  if (!$this->isScanLocked()) {
    return FALSE;
  }

  $threshold = $this->getStaleLockThreshold();
  $now = time();

  // Tier 1: Session-scoped heartbeat.
  $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
  if ($sessionId) {
    $heartbeat = $this->state->get("dai.scan.{$sessionId}.heartbeat");
    if ($heartbeat !== NULL) {
      return ($now - (int) $heartbeat) > $threshold;
    }
  }

  // Tier 2: Legacy global heartbeat (handles scans started before session-scoped keys).
  $legacyHeartbeat = $this->state->get('dai.scan.lock.heartbeat');
  if ($legacyHeartbeat !== NULL) {
    return ($now - (int) $legacyHeartbeat) > $threshold;
  }

  // Tier 3: checkpoint.started (grace window for startup or missing heartbeat).
  $started = $this->state->get('dai.scan.checkpoint.started');
  if ($started !== NULL) {
    return ($now - (int) $started) > $threshold;
  }

  // Tier 4: No heartbeat, no started — orphan lock. Stale.
  return TRUE;
}
```

### 6f. Session Key Cleanup Helper

```php
/**
 * Cleans up all session-scoped State keys for a given session.
 *
 * Call on scan completion, fresh start, or stale-break.
 */
protected function cleanupSessionKeys(string $sessionId): void {
  $this->state->delete("dai.scan.{$sessionId}.heartbeat");
  $this->state->delete("dai.scan.{$sessionId}.stats.orphan_count");
}
```

Call `cleanupSessionKeys()` in:
- `clearCheckpoint()` (scan completion or fresh start) — get session ID before clearing.
- `breakStaleLock()` — if session ID is available.

---

## Step 7: Sandbox-Based Stats

**Implements:** FR-4

**File:** `src/Service/DigitalAssetScanner.php`

### 7a. `persistOrphanCount()`

```php
/**
 * Persists the orphan count for the current session.
 *
 * Called once per batch callback with the cumulative total from sandbox.
 * Replaces the per-item incrementOrphanCount() to reduce DB writes.
 *
 * @param string $sessionId
 *   Active scan session ID.
 * @param int $count
 *   Cumulative orphan count (from $context['sandbox']).
 */
public function persistOrphanCount(string $sessionId, int $count): void {
  $this->state->set("dai.scan.{$sessionId}.stats.orphan_count", $count);
}
```

### 7b. Replace `incrementOrphanCount()` Calls

In `scanManagedFilesChunk()` (or the per-file processing method it delegates to), find every call to `incrementOrphanCount()` and replace with:

```php
$context['sandbox']['orphan_paragraph_count']++;
```

**IMPORTANT:** Verify the actual call sites by searching for `incrementOrphanCount` in `DigitalAssetScanner.php`. The prior analysis identified ~4 calls in the managed files processing logic. Each one must be replaced.

If the `incrementOrphanCount()` calls are inside a method that doesn't have access to `$context` (deep in the call chain), use Option B from Step 4b: add a scanner property `private int $currentOrphanCount = 0;` and copy to sandbox at callback exit.

### 7c. Write Legacy Key at Scan Completion

In `batchFinished()` in `ScanAssetsForm.php`, after all phases complete:

```php
// Write legacy orphan count for backward compatibility.
$sessionId = $scanner->getCheckpoint()['session_id'] ?? NULL;
if ($sessionId) {
  $orphanCount = \Drupal::state()->get("dai.scan.{$sessionId}.stats.orphan_count", 0);
  \Drupal::state()->set('digital_asset_inventory.scan_orphan_count', $orphanCount);
}
```

---

## Step 8: Recovery Log in Form

**Implements:** FR-8 (recovery logging)

**File:** `src/Form/ScanAssetsForm.php`

In `submitForm()`, when a stale lock is broken before resume, add a notice-level log:

```php
// After breakStaleLock() succeeds and before retry:
$checkpoint = $this->scanner->getCheckpoint();
$this->logger->notice('Scan recovery detected. Previous session: @session, Checkpoint phase: @phase, Stale-break: yes', [
  '@session' => $checkpoint['session_id'] ?? 'unknown',
  '@phase' => $checkpoint['phase'] ?? 'none',
]);
```

---

## Step Dependency Map

```
Step 1 (Config/Schema/Settings/Install)
  └── Step 2 (Configurable Getters)
        ├── Step 3 (Intra-Chunk Heartbeat)
        │     └── Step 4 (Time-Budgeted Chunks) ← LARGEST, depends on 2+3
        │           └── Step 7 (Sandbox Stats) ← needs $context access from Step 4
        │           └── Step 8 (Recovery Log)
        ├── Step 5 (Fast Stale-Break) ← independent after Step 2
        └── Step 6 (Session-Scoped Heartbeat) ← independent after Step 2
```

Steps 5, 6, and 4 can be worked in parallel after Step 2 completes. Steps 7 and 8 depend on Step 4.

---

## Verification Checklist

After implementation, run these checks:

### Automated Tests

```bash
# Run all existing tests — they must pass unchanged.
cd web && SIMPLETEST_DB="sqlite://localhost//tmp/dai-kernel-$$.sqlite" \
../vendor/bin/phpunit -c core/phpunit.xml.dist \
  --group digital_asset_inventory \
  modules/custom/digital_asset_inventory/tests/src
```

### Config Verification

```bash
drush config:get digital_asset_inventory.settings scan_lock_stale_threshold_seconds
# Expected: 900

drush config:get digital_asset_inventory.settings scan_batch_time_budget_seconds
# Expected: 4

# Test config validation
drush cset digital_asset_inventory.settings scan_batch_time_budget_seconds 50
# Should fall back to 4 with warning in log
```

### Code Verification

Check these specific things before deploying:

1. **Exclusion parity:** Compare the WHERE conditions in new `getManagedFileIdsAfter()`, `getRemoteMediaIdsAfter()`, `getMenuLinkIdsAfter()` against the original `scan*Chunk()` methods. Every condition must be replicated.

2. **`$context['finished'] = 1` guards:** Verify every phase has an exhaustion guard that forces `finished = 1` when no more items exist. Search for `$context['finished'] = 1` — should appear at least once per phase.

3. **`incrementOrphanCount()` removal:** Search for `incrementOrphanCount` — should have zero calls remaining in scan processing paths (keep the method itself if other code references it, but mark deprecated).

4. **Legacy heartbeat key:** Search for `dai.scan.lock.heartbeat` — should appear in read (fallback) and delete (cleanup) paths, not just write paths.

5. **Checkpoint saves:** Verify each batch callback's `if ($context['finished'] >= 1)` block calls `saveCheckpoint()` with the correct phase number and `phase5_complete` flag.

### Manual Pantheon Testing

| Test | Expected |
|------|----------|
| Scan site with ~2,800 files | Completes without 504 |
| Scan site with ~5,000+ files | Completes without 504; progress bar moves steadily |
| Open second tab during scan | Shows "scan running" — no false stale break |
| Navigate away, return within 5 min | Shows "scan running" |
| Navigate away, wait 15+ min, return | Shows "Previous scan appears interrupted" |
| Enable debug logging, run scan | Batch timing logs show phase, items, elapsed, cursor per request |
| Settings form → Scanner Settings | Both fields present, collapsed by default, saves correctly |
| `drush updatedb` on existing site | New config keys added with defaults |

---

## Key Design Decisions Summary

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Scanner manages cursor, not form | Testability. Batch callbacks are thin wrappers. |
| 2 | Shared `processWithTimeBudget()` helper | Phases 1/4/5 share identical pattern. Reduces duplication and bug surface for the largest change. |
| 3 | Phase 2: Rebuild orphan list each callback | Avoids serializing ~500KB-1MB of paths through batch table on every round-trip. Filesystem read is <1s. |
| 4 | Phase 3: Table-by-table cursor | Avoids serializing tens of thousands of (table, entity_id, column) tuples. Compound cursor is just two integers. |
| 5 | Phase 3: COUNT(*) per table for progress | One cheap query per table gives accurate progress bar instead of erratic jumps. |
| 6 | Migration-safe 4-tier heartbeat fallback | Handles mid-deployment: scan started on old code (global heartbeat), deployment happens, new code checks stale. Legacy fallback removed in future release. |
| 7 | `$lastHeartbeatWrite` resets to 0 per callback | Scanner re-instantiated per HTTP request by Batch API. First `maybeUpdateHeartbeat()` always writes. Correct behavior, documented in code comment. |
| 8 | All 5 phases have exhaustion guards | Prevents phase from never completing if total was computed at scan start but items changed mid-scan. |
| 9 | Debug-level timing logs | Zero production overhead unless enabled. Invaluable for diagnosing timeouts without code changes. |
| 10 | Cursor passed explicitly to `logBatchTiming()` | Avoids coupling logging method to phase-specific sandbox key names. |
| 11 | Orphan count: cumulative sandbox total persisted once per callback | Sandbox persists across callbacks. State write is the running total, not a delta. One write per callback vs. hundreds per item. |
