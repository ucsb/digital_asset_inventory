<?php

/**
 * @file
 * Digital Asset Inventory & Archive Management module.
 *
 * Provides digital asset scanning, usage tracking, and
 * ADA Title II–compliant archiving tools for Drupal sites.
 *
 * Copyright (C) 2026
 * The Regents of the University of California
 *
 * This file is part of the Digital Asset Inventory module.
 *
 * The Digital Asset Inventory module is free software: you can
 * redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation;
 * either version 2 of the License, or (at your option) any later version.
 *
 * The Digital Asset Inventory module is distributed in the hope that it
 * will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see:
 * https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt
 */

namespace Drupal\digital_asset_inventory\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\digital_asset_inventory\Service\DigitalAssetScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for scanning digital assets using Drupal core Batch API.
 *
 * Supports scan resumability, concurrency protection, and checkpoint
 * integrity validation. See scan-resilience-spec.md for details.
 */
final class ScanAssetsForm extends FormBase {

  /**
   * Form action constants (stable #name values for submitForm branching).
   */
  const ACTION_SCAN = 'scan';
  const ACTION_RESUME = 'resume';
  const ACTION_FINALIZE = 'finalize';
  const ACTION_FRESH = 'fresh';

  /**
   * Phase map: phase number => [batch method, human-readable label].
   */
  const PHASE_MAP = [
    1 => ['method' => 'batchProcessManagedFiles', 'label' => 'Managed Files'],
    2 => ['method' => 'batchBuildOrphanUsageIndex', 'label' => 'Orphan Usage Index'],
    3 => ['method' => 'batchProcessOrphanFiles', 'label' => 'Orphan Files'],
    4 => ['method' => 'batchProcessContent', 'label' => 'Content (External URLs)'],
    5 => ['method' => 'batchProcessMediaEntities', 'label' => 'Remote Media'],
    6 => ['method' => 'batchProcessMenuLinks', 'label' => 'Menu Links'],
    7 => ['method' => 'batchProcessCsvFields', 'label' => 'CSV Export Fields'],
  ];

  /**
   * The digital asset scanner service.
   *
   * @var \Drupal\digital_asset_inventory\Service\DigitalAssetScanner
   */
  protected $scanner;

  /**
   * Constructs a new ScanAssetsForm.
   *
   * @param \Drupal\digital_asset_inventory\Service\DigitalAssetScanner $scanner
   *   The digital asset scanner service.
   */
  public function __construct(DigitalAssetScanner $scanner) {
    $this->scanner = $scanner;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('digital_asset_inventory.scanner')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_scan_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // buildForm() is read-only — it never breaks locks. Lock breaking happens
    // exclusively in submitForm() when a user clicks Resume or Start Fresh.

    // Step 1: Check for finalizable checkpoint FIRST. This takes precedence
    // over lock state so that the Finalize UI is never hidden behind a stale
    // lock row that was never cleaned up.
    $checkpoint = $this->scanner->getCheckpoint();

    if ($checkpoint && !empty($checkpoint['session_id']) && !empty($checkpoint['phase'])) {
      $phase = $checkpoint['phase'];
      $phase6_complete = $checkpoint['phase6_complete'] ?? $checkpoint['phase5_complete'];

      if ($phase === 7 && $phase6_complete) {
        // All phases complete but promote never ran.
        $integrity = $this->scanner->validateCheckpointIntegrity('finalize');

        $age_text = '';
        if (!empty($checkpoint['started'])) {
          $age_text = \Drupal::service('date.formatter')->formatTimeDiffSince($checkpoint['started']);
        }

        if (!$integrity['ok']) {
          $form['warning'] = [
            '#markup' => '<p><strong>' . $this->t('All scan phases completed, but temporary scan data was lost or corrupted (@reason). Please start a fresh scan.', [
              '@reason' => $integrity['reason'],
            ]) . '</strong></p>',
          ];
          $form['actions'] = ['#type' => 'actions'];
          $form['actions']['fresh'] = [
            '#type' => 'submit',
            '#name' => self::ACTION_FRESH,
            '#value' => $this->t('Start Fresh Scan'),
            '#button_type' => 'primary',
          ];
          return $form;
        }

        // Finalize available. Add lock note if a stale lock is still held.
        $form['status'] = [
          '#markup' => '<p>' . $this->t('All scan phases completed but finalization was interrupted. Started: @age ago.', [
            '@age' => $age_text,
          ]) . '</p>',
        ];
        if ($this->scanner->isScanLocked()) {
          $form['lock_note'] = [
            '#markup' => '<p><em>' . $this->t('Note: A stale scan lock will be cleared when you finalize or start fresh.') . '</em></p>',
          ];
        }
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['finalize'] = [
          '#type' => 'submit',
          '#name' => self::ACTION_FINALIZE,
          '#value' => $this->t('Finalize Scan'),
          '#button_type' => 'primary',
        ];
        $form['actions']['fresh'] = [
          '#type' => 'submit',
          '#name' => self::ACTION_FRESH,
          '#value' => $this->t('Start Fresh Scan'),
        ];
        return $form;
      }
    }

    // Step 2: Check lock state (for non-finalizable cases).
    if ($this->scanner->isScanLocked()) {
      if ($this->scanner->isScanLockStale()) {
        // Lock is stale (abandoned scan). Show interrupted message with
        // Resume/Fresh buttons. The lock will be broken in submitForm().
        $heartbeat = $this->scanner->getScanHeartbeat();
        if ($heartbeat) {
          $activity_age = \Drupal::service('date.formatter')->formatTimeDiffSince($heartbeat);
          $form['warning'] = [
            '#markup' => '<p><strong>' . $this->t('Previous scan appears interrupted (last activity @age ago).', ['@age' => $activity_age]) . '</strong></p>',
          ];
        }
        else {
          $form['warning'] = [
            '#markup' => '<p><strong>' . $this->t('Previous scan appears interrupted.') . '</strong></p>',
          ];
        }

        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['resume'] = [
          '#type' => 'submit',
          '#name' => self::ACTION_RESUME,
          '#value' => $this->t('Resume Scan'),
          '#button_type' => 'primary',
        ];
        $form['actions']['fresh'] = [
          '#type' => 'submit',
          '#name' => self::ACTION_FRESH,
          '#value' => $this->t('Start Fresh Scan'),
        ];
        return $form;
      }
      else {
        // Active scan in progress. Show last activity time, disable buttons.
        $heartbeat = $this->scanner->getScanHeartbeat();
        if ($heartbeat) {
          $activity_age = \Drupal::service('date.formatter')->formatTimeDiffSince($heartbeat);
          $form['warning'] = [
            '#markup' => '<p><strong>' . $this->t('A scan is currently running (last activity @age ago).', ['@age' => $activity_age]) . '</strong></p>',
          ];
        }
        else {
          // No heartbeat but lock is not stale (within grace window).
          $form['warning'] = [
            '#markup' => '<p><strong>' . $this->t('A scan is starting up. Please wait.') . '</strong></p>',
          ];
        }

        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Scan Site for Digital Assets'),
          '#button_type' => 'primary',
          '#disabled' => TRUE,
        ];
        return $form;
      }
    }

    // Step 3: Check remaining checkpoint states (resume cases, no lock held).
    if ($checkpoint) {
      // Malformed checkpoint: missing session_id or phase is 0/NULL.
      if (empty($checkpoint['session_id']) || empty($checkpoint['phase'])) {
        $form['description'] = [
          '#markup' => '<p>' . $this->t('A previous scan checkpoint was found but appears incomplete. Start a fresh scan to rebuild the inventory.') . '</p>',
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['fresh'] = [
          '#type' => 'submit',
          '#name' => self::ACTION_FRESH,
          '#value' => $this->t('Start Fresh Scan'),
          '#button_type' => 'primary',
        ];
        return $form;
      }

      $phase = $checkpoint['phase'];

      // Build checkpoint info string.
      $age_text = '';
      if (!empty($checkpoint['started'])) {
        $age_text = \Drupal::service('date.formatter')->formatTimeDiffSince($checkpoint['started']);
      }

      // Note: phase==7 && phase6_complete (all phases done) is handled in Step 1 above.

      // Incomplete scan (phases 1-6, or phase 7 not yet complete).
      $mode = 'resume';
      $integrity = $this->scanner->validateCheckpointIntegrity($mode);

      if (!$integrity['ok']) {
        $form['warning'] = [
          '#markup' => '<p><strong>' . $this->t('Checkpoint exists but temporary scan data was lost (@reason). Please start a fresh scan.', [
            '@reason' => $integrity['reason'],
          ]) . '</strong></p>',
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['fresh'] = [
          '#type' => 'submit',
          '#name' => self::ACTION_FRESH,
          '#value' => $this->t('Start Fresh Scan'),
          '#button_type' => 'primary',
        ];
        return $form;
      }

      // Build phase info for the status message.
      $completed_phases = [];
      for ($i = 1; $i <= $phase; $i++) {
        $completed_phases[] = self::PHASE_MAP[$i]['label'];
      }

      $total_phases = count(self::PHASE_MAP);
      $form['status'] = [
        '#markup' => '<p>' . $this->t('Previous scan interrupted after Phase @phase of @total (@phases). Started: @age ago.', [
          '@phase' => $phase,
          '@total' => $total_phases,
          '@phases' => implode(', ', $completed_phases),
          '@age' => $age_text,
        ]) . '</p>',
      ];

      // Show integrity warnings if any.
      if (!empty($integrity['warnings'])) {
        foreach ($integrity['warnings'] as $i => $warning) {
          $form['integrity_warning_' . $i] = [
            '#markup' => '<p><em>' . $this->t('@warning', ['@warning' => $warning]) . '</em></p>',
          ];
        }
      }

      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['resume'] = [
        '#type' => 'submit',
        '#name' => self::ACTION_RESUME,
        '#value' => $this->t('Resume Scan'),
        '#button_type' => 'primary',
      ];
      $form['actions']['fresh'] = [
        '#type' => 'submit',
        '#name' => self::ACTION_FRESH,
        '#value' => $this->t('Start Fresh Scan'),
      ];
      return $form;
    }

    // Normal form: no checkpoint, no lock.
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Click the button below to scan the site for digital assets. This will update the inventory table.') . '</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#name' => self::ACTION_SCAN,
      '#value' => $this->t('Scan Site for Digital Assets'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Determine action from triggering element (needed for lock-fail message).
    $triggering_element = $form_state->getTriggeringElement();
    $action = $triggering_element['#name'] ?? self::ACTION_SCAN;

    // Step 1: Acquire lock (FIRST — before any state mutation).
    //
    // This guard also handles the browser re-POST scenario: if a user starts
    // a scan, navigates away, resumes in another tab, then returns to the
    // original tab and refreshes, the browser warns "Returning to that page
    // might cause any action you took to be repeated." If they click Continue,
    // the re-POST lands here. Because the other tab holds a fresh lock,
    // acquireScanLock() fails, isScanLockStale() returns FALSE, and we fall
    // into the active-lock branch below — showing an error and rebuilding
    // the form with disabled buttons. No state mutation, no batch_set(),
    // no checkpoint/temp clearing. The re-POST is a harmless no-op.
    if (!$this->scanner->acquireScanLock()) {
      // Check if this is a stale lock from an abandoned scan.
      if ($this->scanner->isScanLockStale()) {
        // FR-8: Log recovery event before breaking stale lock.
        $checkpoint = $this->scanner->getCheckpoint();
        $this->getLogger('digital_asset_inventory')->notice('Scan recovery detected. Previous session: @session, Checkpoint phase: @phase, Stale-break: yes', [
          '@session' => $checkpoint['session_id'] ?? 'unknown',
          '@phase' => $checkpoint['phase'] ?? 'none',
        ]);

        $this->scanner->breakStaleLock();
        // Retry acquisition after breaking stale lock.
        if (!$this->scanner->acquireScanLock()) {
          // Another user grabbed it between break and acquire (race).
          $this->messenger()->addError(
            $this->t('A scan is already in progress. Please wait for it to complete.')
          );
          $form_state->setRebuild();
          return;
        }
      }
      else {
        // Active scan in progress — block submission. This covers both
        // concurrent users and the browser re-POST scenario described above.
        if ($action === self::ACTION_FRESH) {
          $this->messenger()->addError(
            $this->t('A scan is currently in progress. Starting fresh would discard in-progress results. Please wait for the current scan to complete.')
          );
        }
        else {
          $this->messenger()->addError(
            $this->t('A scan is already in progress. Please wait for it to complete.')
          );
        }
        // Force form rebuild so stale Resume/Fresh buttons are replaced
        // with the locked-state display (disabled button + warning).
        $form_state->setRebuild();
        return;
      }
    }

    // Step 2: Re-load checkpoint from State (prevents race conditions).
    $checkpoint = $this->scanner->getCheckpoint();

    $state = \Drupal::state();

    // Step 3 & 4: Set start time and session ID based on action.
    if ($action === self::ACTION_FRESH || $action === self::ACTION_SCAN || !$checkpoint) {
      // New scan: write fresh start time and session ID.
      $state->set('dai.scan.checkpoint.started', time());
      $state->set('dai.scan.checkpoint.session_id', \Drupal::service('uuid')->generate());
      $state->set('digital_asset_inventory.scan_start', time());
    }
    else {
      // Resume/Finalize: preserve checkpoint.started and session_id,
      // but reset scan_start so duration reflects actual processing time.
      $state->set('digital_asset_inventory.scan_start', time());
    }

    // Step 5: Finalize path (all phases complete, just promote).
    if ($action === self::ACTION_FINALIZE && $checkpoint && $checkpoint['phase'] === 7 && $checkpoint['phase6_complete']) {
      try {
        $this->scanner->promoteTemporaryItems();
        $this->scanner->clearCheckpoint();

        // Reconstruct summary counts.
        $this->showScanCompletionMessage();

        $this->messenger()->addStatus(
          $this->t('Scan finalized successfully. Inventory has been updated.')
        );
      }
      finally {
        $this->scanner->releaseScanLock();
      }
      $form_state->setRedirect('view.digital_assets.page_inventory');
      return;
    }

    // Step 6: Fresh scan.
    if ($action === self::ACTION_FRESH) {
      $this->scanner->clearTemporaryItems();
      $this->scanner->clearCheckpoint();
      // Re-write checkpoint keys for the new session.
      $state->set('dai.scan.checkpoint.started', time());
      $state->set('dai.scan.checkpoint.session_id', \Drupal::service('uuid')->generate());
      $state->set('digital_asset_inventory.scan_start', time());
      $this->scanner->resetScanStats();
      $batch = $this->buildBatch(1);
    }
    // Step 7: Resume from checkpoint.
    // saveCheckpoint() is only called when a phase finishes, so the stored
    // phase number is always the last COMPLETED phase. Resume from the next.
    elseif ($action === self::ACTION_RESUME && $checkpoint) {
      $batch = $this->buildBatch($checkpoint['phase'] + 1);
    }
    // Step 8: Normal scan (no checkpoint).
    else {
      $this->scanner->resetScanStats();
      $batch = $this->buildBatch(1);
    }

    // Step 9: Suspend cron and set batch.
    $this->scanner->suspendCron();
    batch_set($batch);
    $form_state->setRedirect('view.digital_assets.page_inventory');
  }

  /**
   * Builds a batch array starting from the given phase.
   *
   * @param int $start_phase
   *   The phase number to start from (1-5).
   *
   * @return array
   *   Batch definition array.
   */
  protected function buildBatch(int $start_phase): array {
    $operations = [];
    for ($phase = $start_phase; $phase <= 7; $phase++) {
      $method = self::PHASE_MAP[$phase]['method'];
      $operations[] = [
        [static::class, $method],
        [$phase],
      ];
    }

    return [
      'title' => $this->t('Scanning digital assets...'),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
      'error_message' => $this->t('The scan encountered an error.'),
      'progress_message' => $this->t('Processing phase @current of @total...'),
    ];
  }

  /**
   * Batch operation: Process managed files.
   *
   * @param int $phase_number
   *   The phase number for checkpoint saving.
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessManagedFiles(int $phase_number, array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $scanner->resetHeartbeatWriteCount();
    $callbackStartTime = microtime(true);
    $scanner->updateScanHeartbeat();

    $scanner->scanManagedFilesChunk($context, TRUE);

    // Save checkpoint when phase completes.
    if ($context['finished'] >= 1) {
      $scanner->saveCheckpoint($phase_number, $phase_number === 7);
    }

    $scanner->updateScanHeartbeat();

    // FR-8: Per-request timing log.
    $cursor = $context['sandbox']['last_fid'] ?? 'n/a';
    $items = $context['results']['last_chunk_items'] ?? 0;
    $scanner->logBatchTiming($phase_number, $items, $callbackStartTime, $cursor);
  }

  /**
   * Batch operation: Build orphan file usage index.
   *
   * Scans all text-field tables for '/files/' references and builds
   * a usage map stored in State API for Phase 3 (orphan processing).
   *
   * @param int $phase_number
   *   The phase number for checkpoint saving.
   * @param array $context
   *   Batch context array.
   */
  public static function batchBuildOrphanUsageIndex(int $phase_number, array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $scanner->resetHeartbeatWriteCount();
    $callbackStartTime = microtime(true);
    $scanner->updateScanHeartbeat();

    $scanner->buildOrphanUsageIndex($context);

    if ($context['finished'] >= 1) {
      $scanner->saveCheckpoint($phase_number, $phase_number === 7);
    }

    $scanner->updateScanHeartbeat();

    $tables = $context['results']['last_chunk_items'] ?? 0;
    $scanner->logBatchTiming($phase_number, $tables, $callbackStartTime, 'tables');
  }

  /**
   * Batch operation: Process orphan files.
   *
   * @param int $phase_number
   *   The phase number for checkpoint saving.
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessOrphanFiles(int $phase_number, array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $scanner->resetHeartbeatWriteCount();
    $callbackStartTime = microtime(true);
    $scanner->updateScanHeartbeat();

    $scanner->scanOrphanFilesChunkNew($context, TRUE);

    if ($context['finished'] >= 1) {
      $scanner->saveCheckpoint($phase_number, $phase_number === 7);
    }

    $scanner->updateScanHeartbeat();

    // FR-8: Per-request timing log.
    $cursor = $context['sandbox']['orphan_index'] ?? 'n/a';
    $items = $context['results']['last_chunk_items'] ?? 0;
    $scanner->logBatchTiming($phase_number, $items, $callbackStartTime, $cursor);
  }

  /**
   * Batch operation: Process content for external URLs.
   *
   * @param int $phase_number
   *   The phase number for checkpoint saving.
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessContent(int $phase_number, array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $scanner->resetHeartbeatWriteCount();
    $callbackStartTime = microtime(true);
    $scanner->updateScanHeartbeat();

    $scanner->scanContentChunkNew($context, TRUE);

    if ($context['finished'] >= 1) {
      $scanner->saveCheckpoint($phase_number, $phase_number === 7);
    }

    $scanner->updateScanHeartbeat();

    // FR-8: Per-request timing log.
    $cursor = ($context['sandbox']['table_index'] ?? '?') . ':' . ($context['sandbox']['last_entity_id'] ?? '?');
    $items = $context['results']['last_chunk_items'] ?? 0;
    $scanner->logBatchTiming($phase_number, $items, $callbackStartTime, $cursor);
  }

  /**
   * Batch operation: Process remote media entities (YouTube, Vimeo, etc.).
   *
   * @param int $phase_number
   *   The phase number for checkpoint saving.
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessMediaEntities(int $phase_number, array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $scanner->resetHeartbeatWriteCount();
    $callbackStartTime = microtime(true);
    $scanner->updateScanHeartbeat();

    $scanner->scanRemoteMediaChunkNew($context, TRUE);

    if ($context['finished'] >= 1) {
      $scanner->saveCheckpoint($phase_number, $phase_number === 7);
    }

    $scanner->updateScanHeartbeat();

    // FR-8: Per-request timing log.
    $cursor = $context['sandbox']['last_mid'] ?? 'n/a';
    $items = $context['results']['last_chunk_items'] ?? 0;
    $scanner->logBatchTiming($phase_number, $items, $callbackStartTime, $cursor);
  }

  /**
   * Batch operation: Process menu links for file references.
   *
   * @param int $phase_number
   *   The phase number for checkpoint saving.
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessMenuLinks(int $phase_number, array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $scanner->resetHeartbeatWriteCount();
    $callbackStartTime = microtime(true);
    $scanner->updateScanHeartbeat();

    $scanner->scanMenuLinksChunkNew($context, TRUE);

    if ($context['finished'] >= 1) {
      $scanner->saveCheckpoint($phase_number, FALSE);
    }

    $scanner->updateScanHeartbeat();

    // FR-8: Per-request timing log.
    $cursor = $context['sandbox']['last_id'] ?? 'n/a';
    $items = $context['results']['last_chunk_items'] ?? 0;
    $scanner->logBatchTiming($phase_number, $items, $callbackStartTime, $cursor);
  }

  /**
   * Batch operation: Update CSV export fields for all temp items.
   *
   * @param int $phase_number
   *   The phase number for checkpoint saving.
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessCsvFields(int $phase_number, array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $scanner->resetHeartbeatWriteCount();
    $callbackStartTime = microtime(true);
    $scanner->updateScanHeartbeat();

    $scanner->updateCsvExportFieldsBulk($context);

    if ($context['finished'] >= 1) {
      $scanner->saveCheckpoint($phase_number, TRUE);
    }

    $scanner->updateScanHeartbeat();

    $cursor = $context['sandbox']['csv_last_id'] ?? 'n/a';
    $items = $context['results']['last_chunk_items'] ?? 0;
    $scanner->logBatchTiming($phase_number, $items, $callbackStartTime, $cursor);
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   Results from batch operations.
   * @param array $operations
   *   Remaining operations.
   */
  public static function batchFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    $scanner = \Drupal::service('digital_asset_inventory.scanner');
    $logger = \Drupal::logger('digital_asset_inventory');

    if ($success) {
      try {
        // Write legacy orphan count from session-scoped key for backward compatibility.
        $checkpoint = $scanner->getCheckpoint();
        $sessionId = $checkpoint['session_id'] ?? NULL;
        if ($sessionId) {
          $orphanCount = \Drupal::state()->get("dai.scan.{$sessionId}.stats.orphan_count", 0);
          \Drupal::state()->set('digital_asset_inventory.scan_orphan_count', $orphanCount);
        }

        // Atomic swap: replace old inventory with new temp items.
        $scanner->promoteTemporaryItems();

        // Clear checkpoint state (scan completed successfully).
        $scanner->clearCheckpoint();

        // Save last scan timestamp and calculate duration.
        $end_time = time();
        $start_time = \Drupal::state()->get('digital_asset_inventory.scan_start', $end_time);
        $duration = $end_time - $start_time;

        \Drupal::state()->set('digital_asset_inventory.last_scan', $end_time);
        \Drupal::state()->set('digital_asset_inventory.scan_duration', $duration);

        // Reconstruct summary counts from database.
        static::showBatchCompletionMessages($messenger, $scanner, $logger);
      }
      finally {
        $scanner->restoreCron();
        $scanner->releaseScanLock();
      }
    }
    else {
      // Scan failed or cancelled. Preserve temp items and checkpoint for resume.
      // Do NOT call clearTemporaryItems() or clearCheckpoint().
      try {
        $messenger->addWarning(t('Scan interrupted. You can resume from where it left off on the scan page.'));

        $logger->warning('Digital asset scan was cancelled or failed. Remaining operations: @ops', [
          '@ops' => count($operations),
        ]);
      }
      finally {
        $scanner->restoreCron();
        $scanner->releaseScanLock();
      }
    }
  }

  /**
   * Displays scan completion messages with summary counts from the database.
   *
   * Used by both batchFinished() (batch completion) and submitForm() (finalize).
   */
  protected function showScanCompletionMessage(): void {
    $messenger = $this->messenger();
    $scanner = $this->scanner;
    $logger = \Drupal::logger('digital_asset_inventory');
    static::showBatchCompletionMessages($messenger, $scanner, $logger);
  }

  /**
   * Static helper to display completion messages (usable from static context).
   *
   * @param object $messenger
   *   The messenger service.
   * @param \Drupal\digital_asset_inventory\Service\DigitalAssetScanner $scanner
   *   The scanner service.
   * @param object $logger
   *   The logger channel.
   */
  protected static function showBatchCompletionMessages($messenger, DigitalAssetScanner $scanner, $logger): void {
    $database = \Drupal::database();

    // Count file-based local files (file_managed source).
    $local_file_count = $database->select('digital_asset_item', 'dai')
      ->condition('source_type', 'file_managed')
      ->condition('is_temp', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count media files (includes both file-based and remote media).
    $media_count = $database->select('digital_asset_item', 'dai')
      ->condition('source_type', 'media_managed')
      ->condition('is_temp', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    $managed_count = $local_file_count + $media_count;

    $orphan_file_count = $database->select('digital_asset_item', 'dai')
      ->condition('source_type', 'filesystem_only')
      ->condition('is_temp', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    $external_count = $database->select('digital_asset_item', 'dai')
      ->condition('source_type', 'external')
      ->condition('is_temp', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    // Get usage record count.
    $usage_count = $database->select('digital_asset_usage', 'dau')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Get orphaned paragraph count from scan stats.
    $orphan_paragraph_count = $scanner->getOrphanCount();

    $total = $managed_count + $orphan_file_count + $external_count;

    // Log completion summary.
    $logger->notice('Digital asset scan completed: @total assets (@managed local, @orphan_files orphan files, @external external), @usage usage records, @orphan_paragraphs orphaned paragraphs skipped.', [
      '@total' => $total,
      '@managed' => $managed_count,
      '@orphan_files' => $orphan_file_count,
      '@external' => $external_count,
      '@usage' => $usage_count,
      '@orphan_paragraphs' => $orphan_paragraph_count,
    ]);

    // Show user message.
    $messenger->addStatus(t('Digital asset scan complete. @total assets (@local Drupal-managed files, @media Media Library items, @orphan FTP/SFTP, @external external URLs).', [
      '@total' => $total,
      '@local' => $local_file_count,
      '@media' => $media_count,
      '@orphan' => $orphan_file_count,
      '@external' => $external_count,
    ]));

    // Show orphan reference summary if any were created.
    $orphan_ref_total = (int) $database->select('dai_orphan_reference', 'dor')
      ->condition('dor.asset_id', $database->select('digital_asset_item', 'dai')
        ->fields('dai', ['id'])
        ->condition('is_temp', 0), 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($orphan_ref_total > 0) {
      $affected_query = $database->select('dai_orphan_reference', 'dor');
      $affected_query->condition('dor.asset_id', $database->select('digital_asset_item', 'dai')
        ->fields('dai', ['id'])
        ->condition('is_temp', 0), 'IN');
      $affected_query->addExpression('COUNT(DISTINCT asset_id)', 'asset_count');
      $affected_assets = (int) $affected_query->execute()->fetchField();

      $messenger->addStatus(t('Orphan references detected: @total across @assets assets. These do not count as active usage and will not prevent deletion. Any orphan references are cleaned up automatically. Use the "Has Orphan References" filter to review.', [
        '@total' => $orphan_ref_total,
        '@assets' => $affected_assets,
      ]));
    }

    // Show info about skipped orphaned paragraphs if any.
    if ($orphan_paragraph_count > 0) {
      $untracked_orphans = $scanner->getUntrackedOrphans();

      if ($orphan_ref_total > 0) {
        $untracked_count = $orphan_paragraph_count - $orphan_ref_total;
        if ($untracked_count > 0) {
          $msg = t('Note: @count additional orphaned paragraphs were encountered but could not be tracked (detected before asset resolution).', [
            '@count' => $untracked_count,
          ]);
          if (!empty($untracked_orphans)) {
            $ids = array_column($untracked_orphans, 'paragraph_id');
            $msg .= ' ' . t('Paragraph IDs: @ids.', ['@ids' => implode(', ', $ids)]);
          }
          $messenger->addStatus($msg);
        }
      }
      else {
        $msg = t('Note: @count orphaned paragraphs were encountered during usage detection but no trackable orphan references were created. This typically means Drupal has already begun cleaning up the orphan paragraph entities (via cron). Running cron and rescanning will clear these counts.', [
          '@count' => $orphan_paragraph_count,
        ]);
        if (!empty($untracked_orphans)) {
          $ids = array_column($untracked_orphans, 'paragraph_id');
          $msg .= ' ' . t('Paragraph IDs: @ids.', ['@ids' => implode(', ', $ids)]);
        }
        $messenger->addStatus($msg);
      }
    }

    // Check for potential issues and recommend rescan if needed.
    if ($usage_count == 0 && $managed_count > 0) {
      $messenger->addWarning(t('Warning: No usage records were created. This may indicate an issue with the scan. Consider running the scan again.'));
    }
  }

}
