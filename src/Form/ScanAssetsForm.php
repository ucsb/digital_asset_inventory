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
        [
          [static::class, 'batchProcessMenuLinks'],
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
   * Batch operation: Process remote media entities (YouTube, Vimeo, etc.).
   *
   * Remote media entities (oEmbed videos) don't have entries in file_managed,
   * so they need to be scanned separately from file-based media.
   *
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessMediaEntities(array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');

    if (!isset($context['sandbox']['progress'])) {
      // First run - initialize.
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $scanner->getRemoteMediaCount();
      $context['results']['remote_media_count'] = 0;
    }

    // Process in chunks of 25 (media entities are heavier).
    $limit = 25;
    $count = $scanner->scanRemoteMediaChunk(
      $context['sandbox']['progress'],
      $limit,
      // is_temp = TRUE.
      TRUE
    );

    $context['sandbox']['progress'] += $count;
    $context['results']['remote_media_count'] += $count;

    // Update progress.
    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
      $context['message'] = t('Processed @current of @total remote media items...', [
        '@current' => $context['sandbox']['progress'],
        '@total' => $context['sandbox']['max'],
      ]);
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Batch operation: Process menu links for file references.
   *
   * Scans menu_link_content entities for links to files and creates
   * usage tracking records for them.
   *
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcessMenuLinks(array &$context) {
    $scanner = \Drupal::service('digital_asset_inventory.scanner');

    if (!isset($context['sandbox']['progress'])) {
      // First run - initialize.
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = $scanner->getMenuLinksCount();
      $context['results']['menu_link_count'] = 0;
    }

    // Process in chunks of 50.
    $limit = 50;
    $count = $scanner->scanMenuLinksChunk(
      $context['sandbox']['progress'],
      $limit,
      // is_temp = TRUE (for consistency, though not used for menu link scanning).
      TRUE
    );

    $context['sandbox']['progress'] += $count;
    $context['results']['menu_link_count'] += $count;

    // Update progress.
    if ($context['sandbox']['max'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
      $context['message'] = t('Scanned @current of @total menu links for file references...', [
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

      // Combined managed count for backwards compatibility.
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
      $messenger->addStatus(t('Digital asset scan complete. Found @total assets (@managed local files, @orphan orphan files, @external external URLs). Created @usage usage records.', [
        '@total' => $total,
        '@managed' => $managed_count,
        '@orphan' => $orphan_file_count,
        '@external' => $external_count,
        '@usage' => $usage_count,
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
        // Count affected assets (distinct asset_ids with orphan refs).
        $affected_query = $database->select('dai_orphan_reference', 'dor');
        $affected_query->condition('dor.asset_id', $database->select('digital_asset_item', 'dai')
          ->fields('dai', ['id'])
          ->condition('is_temp', 0), 'IN');
        $affected_query->addExpression('COUNT(DISTINCT asset_id)', 'asset_count');
        $affected_assets = (int) $affected_query->execute()->fetchField();

        $messenger->addStatus(t('Orphan references detected: @total orphan references across @assets assets. Use the "Has Orphan References" filter in the inventory to view them.', [
          '@total' => $orphan_ref_total,
          '@assets' => $affected_assets,
        ]));
      }

      // Show info about skipped orphaned paragraphs if any.
      if ($orphan_paragraph_count > 0) {
        // Get untracked orphan details for diagnostic reporting.
        $untracked_orphans = $scanner->getUntrackedOrphans();

        if ($orphan_ref_total > 0) {
          // Some orphan paragraphs created records, some may not have.
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
          // All orphan paragraphs were skipped without creating records.
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
