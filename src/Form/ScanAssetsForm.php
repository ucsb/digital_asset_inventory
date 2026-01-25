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
 */
final class ScanAssetsForm extends FormBase {

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
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Click the button below to scan the site for digital assets. This will update the inventory table.') . '</p>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Scan Site for Digital Assets'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Reset scan statistics (orphan counts, etc.).
    $this->scanner->resetScanStats();

    // Store scan start time.
    \Drupal::state()->set('digital_asset_inventory.scan_start', time());

    $batch = [
      'title' => $this->t('Scanning digital assets...'),
      'operations' => [
        [
          [static::class, 'batchProcessManagedFiles'],
          [],
        ],
        [
          [static::class, 'batchProcessOrphanFiles'],
          [],
        ],
        [
          [static::class, 'batchProcessContent'],
          [],
        ],
        [
          [static::class, 'batchProcessMediaEntities'],
          [],
        ],
      ],
      'finished' => [static::class, 'batchFinished'],
      'error_message' => $this->t('The scan encountered an error.'),
      'progress_message' => $this->t('Processing phase @current of @total...'),
    ];

    batch_set($batch);

    // Redirect to inventory page after completion.
    $form_state->setRedirect('view.digital_assets.page_inventory');
  }

  /**
   * Batch operation: Process managed files.
   *
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessManagedFiles(array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');

    if (!isset($context['sandbox']['progress'])) {
      // First run - initialize.
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $scanner->getManagedFilesCount();
      $context['results']['managed_count'] = 0;
    }

    // Process in chunks of 50.
    $limit = 50;
    $count = $scanner->scanManagedFilesChunk(
      $context['sandbox']['progress'],
      $limit,
    // is_temp = TRUE.
      TRUE
    );

    $context['sandbox']['progress'] += $count;
    $context['results']['managed_count'] += $count;

    // Update progress.
    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
      $context['message'] = t('Processed @current of @total managed files...', [
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['max'],
      ]);
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Batch operation: Process orphan files.
   *
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessOrphanFiles(array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');

    if (!isset($context['sandbox']['progress'])) {
      // First run - initialize.
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $scanner->getOrphanFilesCount();
      $context['results']['orphan_count'] = 0;
    }

    // Process in chunks of 50.
    $limit = 50;
    $count = $scanner->scanOrphanFilesChunk(
      $context['sandbox']['progress'],
      $limit,
    // is_temp = TRUE.
      TRUE
    );

    $context['sandbox']['progress'] += $count;
    $context['results']['orphan_count'] += $count;

    // Update progress.
    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
      $context['message'] = t('Processed @current of @total orphan files...', [
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['max'],
      ]);
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Batch operation: Process content for external URLs.
   *
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessContent(array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');

    if (!isset($context['sandbox']['progress'])) {
      // First run - initialize.
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $scanner->getContentEntitiesCount();
      $context['results']['content_count'] = 0;
    }

    // Process in chunks of 25 (text processing is heavier).
    $limit = 25;
    $count = $scanner->scanContentChunk(
      $context['sandbox']['progress'],
      $limit,
    // is_temp = TRUE.
      TRUE
    );

    // Increment by limit, not count (count is URLs found)
    $context['sandbox']['progress'] += $limit;
    $context['results']['content_count'] += $count;

    // Update progress.
    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
      $context['message'] = t('Scanned @current of @total content items for external URLs...', [
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['max'],
      ]);
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Batch operation: Process media entities.
   *
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessMediaEntities(array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');

    if (!isset($context['sandbox']['progress'])) {
      // First run - initialize.
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $scanner->getMediaEntitiesCount();
      $context['results']['media_count'] = 0;
    }

    // Process in chunks of 25 (media entities are heavier).
    $limit = 25;
    $count = $scanner->scanMediaEntitiesChunk(
      $context['sandbox']['progress'],
      $limit,
    // is_temp = TRUE.
      TRUE
    );

    $context['sandbox']['progress'] += $count;
    $context['results']['media_count'] += $count;

    // Update progress.
    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
      $context['message'] = t('Processed @current of @total media entities...', [
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['max'],
      ]);
    }
    else {
      $context['finished'] = 1;
    }
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
      // Atomic swap: replace old inventory with new temp items.
      $scanner->promoteTemporaryItems();

      // Save last scan timestamp and calculate duration.
      $end_time = time();
      $start_time = \Drupal::state()->get('digital_asset_inventory.scan_start', $end_time);
      $duration = $end_time - $start_time;

      \Drupal::state()->set('digital_asset_inventory.last_scan', $end_time);
      \Drupal::state()->set('digital_asset_inventory.scan_duration', $duration);

      // Get actual asset counts by source type from the database.
      $database = \Drupal::database();

      $managed_count = $database->select('digital_asset_item', 'dai')
        ->condition('source_type', ['file_managed', 'media_managed'], 'IN')
        ->condition('is_temp', 0)
        ->countQuery()
        ->execute()
        ->fetchField();

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
      $messenger->addStatus(t('Digital asset scan complete. Found @total assets (@managed local files, @orphan orphan files, @external external URLs). Created @usage usage records.', [
        '@total' => $total,
        '@managed' => $managed_count,
        '@orphan' => $orphan_file_count,
        '@external' => $external_count,
        '@usage' => $usage_count,
      ]));

      // Show info about skipped orphaned paragraphs if any.
      if ($orphan_paragraph_count > 0) {
        $messenger->addStatus(t('Note: @count orphaned paragraphs were skipped during usage detection (old revisions or deleted content).', [
          '@count' => $orphan_paragraph_count,
        ]));
      }

      // Check for potential issues and recommend rescan if needed.
      if ($usage_count == 0 && $managed_count > 0) {
        $messenger->addWarning(t('Warning: No usage records were created. This may indicate an issue with the scan. Consider running the scan again.'));
      }
    }
    else {
      // Scan was cancelled or failed - clean up temp items.
      $scanner->clearTemporaryItems();
      $messenger->addWarning(t('Scan cancelled or failed. Previous inventory preserved. Please try running the scan again.'));

      // Log the failure.
      $logger->warning('Digital asset scan was cancelled or failed. Remaining operations: @ops', [
        '@ops' => count($operations),
      ]);
    }
  }

}
