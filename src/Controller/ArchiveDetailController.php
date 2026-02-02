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

namespace Drupal\digital_asset_inventory\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for archive detail pages.
 *
 * Provides minimal, read-only reference page for individual archived docs.
 * These pages exist only to support stable linking and contextual clarity.
 *
 * Per ADA compliance requirements, these pages:
 * - Are NOT active content pages
 * - Are NOT in site navigation
 * - Do NOT allow editing, commenting, or revisions
 * - Contain only minimal, non-editorial information
 */
final class ArchiveDetailController extends ControllerBase {

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The archive service.
   *
   * @var \Drupal\digital_asset_inventory\Service\ArchiveService
   */
  protected $archiveService;

  /**
   * Constructs an ArchiveDetailController object.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   */
  public function __construct(
    FileUrlGeneratorInterface $file_url_generator,
    DateFormatterInterface $date_formatter,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    ArchiveService $archive_service,
  ) {
    $this->fileUrlGenerator = $file_url_generator;
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->archiveService = $archive_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_url_generator'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('digital_asset_inventory.archive')
    );
  }

  /**
   * Returns the archive detail page.
   *
   * Implements the Archived Digital Asset Detail Page spec exactly.
   *
   * Visibility behavior:
   * - archived_public: Full details shown to everyone
   * - archived_admin: Anonymous users see limited info (no file URL/download);
   *   admins see full details
   * - archived_deleted/exemption_void: 404 Not Found
   *
   * "Admin-only controls visibility & disclosure, not storage."
   * Even if the file is technically at a public path, Admin-only status means
   * we do not publish that URL in UI.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $digital_asset_archive
   *   The archive entity.
   *
   * @return array
   *   A render array for the archive detail page.
   */
  public function view(DigitalAssetArchive $digital_asset_archive) {
    // Only show detail pages for active archived items (public or admin-only).
    // Deleted and voided archives return 404.
    $is_archived_public = $digital_asset_archive->isArchivedPublic();
    $is_archived_admin = $digital_asset_archive->getStatus() === 'archived_admin';

    if (!$is_archived_public && !$is_archived_admin) {
      throw new NotFoundHttpException();
    }

    // For files marked as missing, only show 404 if publicly visible.
    // Admin-only items with missing files can still show the access notice.
    if ($is_archived_public && $digital_asset_archive->hasFlagMissing()) {
      throw new NotFoundHttpException();
    }

    // Determine if user can view full details (file URL, download link).
    // For admin-only items, only users with 'view digital asset archives' permission
    // can see full details. Anonymous users see limited info.
    $can_view_full_details = TRUE;
    if ($is_archived_admin) {
      $can_view_full_details = $this->currentUser->hasPermission('view digital asset archives');
    }

    $file_name = $digital_asset_archive->getFileName();
    $archive_reason = $this->getFullArchiveReasonLabel($digital_asset_archive);
    $archive_path = $digital_asset_archive->getArchivePath();
    $archived_date = $digital_asset_archive->getArchiveClassificationDate();
    $asset_type = $digital_asset_archive->getAssetType();
    $filesize = $digital_asset_archive->getFilesize();
    $archive_id = $digital_asset_archive->id();
    $is_private = $digital_asset_archive->isPrivate();
    $is_manual_entry = $digital_asset_archive->isManualEntry();

    // Check if user needs to log in to access private file.
    $requires_login = FALSE;
    $login_url = NULL;
    if ($is_private && $this->currentUser->isAnonymous()) {
      $requires_login = TRUE;
      // Build login URL with destination to return here after login.
      // Use default Drupal login page.
      $login_url = Url::fromRoute('user.login', [], [
        'query' => ['destination' => '/archive-registry/' . $archive_id],
      ])->toString();
    }

    // Get human-readable file type label.
    $file_type_label = $this->getFileTypeLabel($asset_type);

    // Format the archived date in ISO 8601 format.
    $formatted_date = $this->t('Unknown');
    if ($archived_date) {
      $formatted_date = $this->dateFormatter->format(
        $archived_date,
        'custom',
        'c'
      );
    }

    // Build the detail URL for copy link functionality.
    $detail_url = '/archive-registry/' . $archive_id;

    // Determine if this is a legacy archive (archived before ADA deadline).
    // Legacy archives show the deadline in the notice; general archives don't.
    $is_legacy_archive = $this->archiveService->isLegacyArchive($digital_asset_archive);

    // Get the compliance deadline from config for display (only shown for legacy archives).
    $deadline_formatted = $this->archiveService->getComplianceDeadlineFormatted();

    // Determine link text and tooltip based on asset type.
    if ($asset_type === 'external') {
      $source_link_text = $this->t('Visit Link');
      $source_tooltip = $archive_path;
    }
    elseif ($asset_type === 'page') {
      // Phase 1: pages show "View Page" - login detection added in Phase 2.
      $source_link_text = $this->t('View Page');
      $source_tooltip = $archive_path;
    }
    else {
      // File-based archive.
      if ($is_private && $this->currentUser->isAnonymous()) {
        $source_link_text = $this->t('Download (Login Required)');
        $source_tooltip = $this->t('You must log in to access this file.');
      }
      else {
        $source_link_text = $this->t('Download');
        $source_tooltip = $archive_path;
      }
    }

    return [
      '#theme' => 'archive_detail',
      '#file_name' => $file_name,
      '#file_type' => $file_type_label,
      '#file_size' => $filesize ? ByteSizeMarkup::create($filesize) : NULL,
      '#archive_reason' => $archive_reason,
      '#archived_date' => $formatted_date,
      '#archive_path' => $archive_path,
      '#detail_url' => $detail_url,
      '#is_private' => $is_private,
      '#requires_login' => $requires_login,
      '#login_url' => $login_url,
      '#compliance_deadline' => $deadline_formatted,
      '#is_manual_entry' => $is_manual_entry,
      '#asset_type' => $asset_type,
      '#source_link_text' => $source_link_text,
      '#source_tooltip' => $source_tooltip,
      '#is_legacy_archive' => $is_legacy_archive,
      // Admin-only visibility control.
      // "Admin-only controls visibility & disclosure, not storage."
      '#is_admin_only' => $is_archived_admin,
      '#can_view_full_details' => $can_view_full_details,
      '#attached' => [
        'library' => ['digital_asset_inventory/archive_detail'],
      ],
      '#cache' => [
        'tags' => $digital_asset_archive->getCacheTags(),
        // Cache varies by URL, user roles, and permissions.
        'contexts' => ['url', 'user.roles:anonymous', 'user.permissions'],
      ],
    ];
  }

  /**
   * Returns a human-readable file type label.
   *
   * @param string $asset_type
   *   The asset type key (e.g., 'pdf', 'word', 'excel').
   *
   * @return string
   *   Human-readable label (e.g., 'PDF file', 'Word file').
   */
  protected function getFileTypeLabel($asset_type) {
    $labels = [
      'pdf' => $this->t('PDF file'),
      'word' => $this->t('Word file'),
      'excel' => $this->t('Excel file'),
      'powerpoint' => $this->t('PowerPoint file'),
      'page' => $this->t('Web page'),
      'external' => $this->t('External link'),
    ];
    return $labels[$asset_type] ?? $this->t('@type file', ['@type' => strtoupper($asset_type)]);
  }

  /**
   * Returns the full descriptive archive reason label.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive
   *   The archive entity.
   *
   * @return string
   *   Full descriptive label for the archive reason.
   */
  protected function getFullArchiveReasonLabel(DigitalAssetArchive $archive) {
    $reason = $archive->getArchiveReason();

    // If "other" is selected, return the custom reason.
    if ($reason === 'other') {
      $custom_reason = $archive->getArchiveReasonOther();
      return $custom_reason ?: $this->t('Other');
    }

    $labels = [
      'reference' => $this->t('Reference - Content retained for informational purposes'),
      'research' => $this->t('Research - Material retained for research or study'),
      'recordkeeping' => $this->t('Recordkeeping - Content retained for compliance or official records'),
    ];

    return $labels[$reason] ?? $this->t('Other');
  }

  /**
   * Title callback for the archive detail page.
   *
   * Per spec: Browser title format is "{File or Page Title} – Archived Material".
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $digital_asset_archive
   *   The archive entity.
   *
   * @return string
   *   The page title.
   */
  public function title(DigitalAssetArchive $digital_asset_archive) {
    $file_name = $digital_asset_archive->getFileName();
    return $file_name . ' – ' . $this->t('Archived Material');
  }

  /**
   * Exports archive records as CSV for audit purposes.
   *
   * Returns a properly formatted CSV even when the archive is empty,
   * avoiding 404 errors from the Views data export.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The CSV response.
   */
  public function exportCsv() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');

    // Load all archive records sorted by archive_classification_date DESC.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('archive_classification_date', 'DESC')
      ->execute();

    $archives = $storage->loadMultiple($ids);

    // Build CSV content.
    $csv_lines = [];

    // CSV header row - audit-ready format.
    $headers = [
      'Archive ID',
      'Name',
      'Asset Type',
      'Archive Type',
      'Archive Classification Date (Critical Compliance Decision)',
      'Current Archive Status',
      'Archived By',
      'File Deletion Date (Post-Archive)',
      'File Deleted By',
      'Reason for Archive Classification',
      'Public Archive Description',
      'File Checksum (SHA-256)',
      'Integrity Issue Detected',
      'Active Usage Detected',
      'File Missing',
      'File Access',
      'Late Archive',
      'Prior Exemption Voided',
      'Exemption Voided / Modified',
      'Archived While In Use',
      'Usage Count at Archive',
      'Original URL',
      'Archive Reference Path',
      'Archive Record Created Date',
    ];
    $csv_lines[] = $this->csvEncodeLine($headers);

    // Add data rows.
    foreach ($archives as $archive) {
      /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive */
      // Get archived_by user name (entity_reference uses target_id).
      $archived_by_id = $archive->get('archived_by')->target_id;
      $archived_by_name = '';
      if ($archived_by_id) {
        $user = $this->entityTypeManager->getStorage('user')->load($archived_by_id);
        $archived_by_name = $user ? $user->getAccountName() : '';
      }

      // Get deleted_by user name (entity_reference uses target_id).
      $deleted_by_id = $archive->get('deleted_by')->target_id;
      $deleted_by_name = '';
      if ($deleted_by_id) {
        $user = $this->entityTypeManager->getStorage('user')->load($deleted_by_id);
        $deleted_by_name = $user ? $user->getAccountName() : '';
      }

      // Format dates.
      $created_date = $archive->get('created')->value;
      $classification_date = $archive->get('archive_classification_date')->value;
      $deleted_date = $archive->get('deleted_date')->value;

      // Get file paths.
      $original_path = $archive->getOriginalPath() ?? '';
      $archive_file_path = $archive->getArchivePath() ?? '';

      // Generate original public URL from file path.
      $original_public_url = '';
      $file_path = $archive_file_path ?: $original_path;
      if ($file_path) {
        if (strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0) {
          $original_public_url = $file_path;
        }
        elseif (strpos($file_path, 'public://') === 0 || strpos($file_path, 'private://') === 0) {
          $original_public_url = $this->fileUrlGenerator->generateAbsoluteString($file_path);
        }
        else {
          $original_public_url = $file_path;
        }
      }

      // Archive reference path is the full public detail page URL.
      // Extract base URL from file URL for consistency.
      $site_base_url = '';
      if ($original_public_url && preg_match('#^(https?://[^/]+)#', $original_public_url, $matches)) {
        $site_base_url = $matches[1];
      }
      $archive_reference_path = $site_base_url . '/archive-registry/' . $archive->id();

      // Determine archive type label.
      $archive_type_label = $archive->hasFlagLateArchive() ? 'General Archive' : 'Legacy Archive';

      $row = [
        $archive->get('archive_uuid')->value ?? '',
        $archive->getFileName(),
        $archive->getAssetTypeLabel(),
        $archive_type_label,
        $classification_date ? $this->dateFormatter->format($classification_date, 'custom', 'c') : '',
        $archive->getStatusLabel(),
        $archived_by_name,
        $deleted_date ? $this->dateFormatter->format($deleted_date, 'custom', 'c') : '',
        $deleted_by_name,
        $archive->getArchiveReasonLabel(),
        $archive->getPublicDescription() ?? '',
        // File-specific fields show N/A for manual entries (pages/external URLs) or queued items (not yet archived).
        $archive->isManualEntry() ? 'N/A (File-only)' : ($archive->isQueued() ? 'N/A (Not yet archived)' : ($archive->getFileChecksum() ?? '')),
        $archive->isManualEntry() ? 'N/A (File-only)' : ($archive->isQueued() ? 'N/A (Not yet archived)' : ($archive->hasFlagIntegrity() ? 'Yes (File checksum does not match the stored value)' : 'No (File checksum matches the stored value)')),
        $this->getUsageDetectedCsvValue($archive),
        // File missing: N/A for manual entries, only Yes when file was actually deleted.
        $archive->isManualEntry() ? 'N/A (File-only)' : (($archive->hasFlagMissing() || !empty($archive->getDeletedDate())) ? 'Yes (Underlying file no longer exists in storage)' : 'No (File exists in storage)'),
        // File access: N/A for manual entries.
        $archive->isManualEntry() ? 'N/A (File-only)' : ($archive->isPrivate() ? 'Private (Login required)' : 'Public'),
        $archive->hasFlagLateArchive() ? 'Yes (Archive classification occurred after the ADA compliance deadline)' : 'No (Archive classification occurred before the ADA compliance deadline)',
        $archive->hasFlagPriorVoid() ? 'Yes (Forced to General Archive due to prior voided exemption)' : 'No',
        $this->getExemptionVoidedCsvValue($archive),
        // Archive-in-use audit fields.
        $archive->wasArchivedWhileInUse() ? 'Yes (Archived with active content references)' : 'No',
        $archive->wasArchivedWhileInUse() ? (string) $archive->getUsageCountAtArchive() : '',
        $original_public_url,
        $archive_reference_path,
        $created_date ? $this->dateFormatter->format($created_date, 'custom', 'c') : '',
      ];
      $csv_lines[] = $this->csvEncodeLine($row);
    }

    $csv_content = implode("\n", $csv_lines);

    // Create response with proper headers.
    $response = new Response($csv_content);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $date = date('Y-m-d');
    $response->headers->set('Content-Disposition', 'attachment; filename="archive-audit-export-' . $date . '.csv"');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

    return $response;
  }

  /**
   * Gets the CSV value for the Active Usage Detected column.
   *
   * Checks flag_usage first, but for archived_deleted items also checks
   * actual usage count since the flag may not be set.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive
   *   The archive entity.
   *
   * @return string
   *   The CSV column value.
   */
  protected function getUsageDetectedCsvValue(DigitalAssetArchive $archive) {
    // Check flag first.
    if ($archive->hasFlagUsage()) {
      return 'Yes (Active content references this document)';
    }

    // For archived_deleted items, also check actual usage count.
    if ($archive->isArchivedDeleted()) {
      $usage_count = $this->archiveService->getUsageCountByArchive($archive);
      if ($usage_count > 0) {
        return 'Yes (Active content references this document)';
      }
    }

    return 'No (No active content references detected)';
  }

  /**
   * Gets the CSV value for the Exemption Voided / Modified column.
   *
   * The value varies based on archive type:
   * - Legacy Archives: Check exemption_void status (ADA exemption language)
   * - General Archives: Check flag_integrity (files) or flag_modified (manual entries)
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive
   *   The archive entity.
   *
   * @return string
   *   The CSV column value.
   */
  protected function getExemptionVoidedCsvValue(DigitalAssetArchive $archive) {
    $is_legacy = !$archive->hasFlagLateArchive();

    if ($is_legacy) {
      // Legacy Archive: Check exemption_void status.
      if ($archive->isExemptionVoid()) {
        // Use appropriate wording for manual entries (pages) vs file-based archives.
        if ($archive->isManualEntry()) {
          return 'Yes (ADA exemption voided: content was modified after the compliance deadline)';
        }
        return 'Yes (ADA exemption voided: file was modified after the compliance deadline)';
      }
      else {
        return 'No (ADA exemption remains valid)';
      }
    }
    else {
      // General Archive: Check flag_integrity (files) or flag_modified (manual entries).
      if ($archive->isManualEntry()) {
        // Manual entries use flag_modified.
        if ($archive->hasFlagModified()) {
          return 'Yes (Content was modified after being archived)';
        }
        else {
          return 'No (Archive has not been modified)';
        }
      }
      else {
        // File-based archives use flag_integrity.
        if ($archive->hasFlagIntegrity()) {
          return 'Yes (File was modified after being archived)';
        }
        else {
          return 'No (Archive has not been modified)';
        }
      }
    }
  }

  /**
   * Encodes a row as CSV with proper escaping.
   *
   * @param array $fields
   *   Array of field values.
   *
   * @return string
   *   CSV-encoded line.
   */
  protected function csvEncodeLine(array $fields) {
    $encoded = [];
    foreach ($fields as $field) {
      // Escape double quotes by doubling them.
      $escaped = str_replace('"', '""', (string) $field);
      // Wrap in quotes if contains comma, quote, or newline.
      if (strpbrk($escaped, ",\"\n\r") !== FALSE) {
        $escaped = '"' . $escaped . '"';
      }
      $encoded[] = $escaped;
    }
    return implode(',', $encoded);
  }

}
