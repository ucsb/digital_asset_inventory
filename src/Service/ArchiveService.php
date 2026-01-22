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

namespace Drupal\digital_asset_inventory\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;

/**
 * Service for archive operations.
 *
 * Handles marking assets for archive, executing the archive process,
 * and managing archive status. Files remain at their original locations;
 * archiving is a compliance classification, not a storage operation.
 */
class ArchiveService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The archive directory path.
   */
  const ARCHIVE_DIRECTORY = 'public://archive';

  /**
   * Document asset types that can be archived.
   */
  const DOCUMENT_TYPES = ['pdf', 'word', 'excel', 'powerpoint'];

  /**
   * Maximum file size (in bytes) for immediate checksum calculation.
   *
   * Files larger than this (50MB) will have checksums calculated via batch.
   */
  const CHECKSUM_SIZE_LIMIT = 52428800;

  /**
   * Constructs an ArchiveService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    FileSystemInterface $file_system,
    FileUrlGeneratorInterface $file_url_generator,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    QueueFactory $queue_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->fileSystem = $file_system;
    $this->fileUrlGenerator = $file_url_generator;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('digital_asset_inventory');
    $this->configFactory = $config_factory;
    $this->queueFactory = $queue_factory;
  }

  /**
   * Checks if an asset can be archived (is a document or video type).
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The digital asset item.
   *
   * @return bool
   *   TRUE if the asset can be archived, FALSE otherwise.
   */
  public function canArchive(DigitalAssetItem $asset) {
    $category = $asset->getCategory();
    return $category === 'Documents' || $category === 'Videos';
  }

  /**
   * Checks if an asset is already queued for archive.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The digital asset item.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The DigitalAssetArchive entity if queued, NULL otherwise.
   */
  public function getPendingArchive(DigitalAssetItem $asset) {
    $fid = $asset->get('fid')->value;
    $original_path = $asset->get('file_path')->value;

    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'queued');

    // Check by fid if available, otherwise by path.
    if ($fid) {
      $query->condition('original_fid', $fid);
    }
    else {
      $query->condition('original_path', $original_path);
    }

    $ids = $query->execute();
    if (!empty($ids)) {
      return $storage->load(reset($ids));
    }

    return NULL;
  }

  /**
   * Checks if an asset has already been archived.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The digital asset item.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The DigitalAssetArchive entity if archived, NULL otherwise.
   */
  public function getArchivedRecord(DigitalAssetItem $asset) {
    $fid = $asset->get('fid')->value;
    $original_path = $asset->get('file_path')->value;

    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ['archived_public', 'archived_admin', 'archived_deleted', 'exemption_void'], 'IN');

    if ($fid) {
      $query->condition('original_fid', $fid);
    }
    else {
      $query->condition('original_path', $original_path);
    }

    $ids = $query->execute();
    if (!empty($ids)) {
      return $storage->load(reset($ids));
    }

    return NULL;
  }

  /**
   * Gets any existing archive record for an asset (any status).
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The digital asset item.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The DigitalAssetArchive entity if exists, NULL otherwise.
   */
  public function getArchiveRecord(DigitalAssetItem $asset) {
    $fid = $asset->get('fid')->value;
    $original_path = $asset->get('file_path')->value;

    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    $query = $storage->getQuery()
      ->accessCheck(FALSE);

    if ($fid) {
      $query->condition('original_fid', $fid);
    }
    else {
      $query->condition('original_path', $original_path);
    }

    $ids = $query->execute();
    if (!empty($ids)) {
      return $storage->load(reset($ids));
    }

    return NULL;
  }

  /**
   * Gets an active archive record for an asset.
   *
   * Checks for records with active statuses: queued, archived_public,
   * archived_admin, or exemption_void. Records with archived_deleted status
   * are considered closed and don't block new archive entries.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The digital asset item.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The active DigitalAssetArchive entity if exists, NULL otherwise.
   */
  public function getActiveArchiveRecord(DigitalAssetItem $asset) {
    $fid = $asset->get('fid')->value;
    $original_path = $asset->get('file_path')->value;

    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    // Only check for active statuses - archived_deleted records are closed
    // and should not block creating new archive entries for the same file.
    $active_statuses = ['queued', 'archived_public', 'archived_admin', 'exemption_void'];
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', $active_statuses, 'IN');

    if ($fid) {
      $query->condition('original_fid', $fid);
    }
    else {
      $query->condition('original_path', $original_path);
    }

    $ids = $query->execute();
    if (!empty($ids)) {
      return $storage->load(reset($ids));
    }

    return NULL;
  }

  /**
   * Gets the usage count for an asset.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The digital asset item.
   *
   * @return int
   *   The usage count.
   */
  public function getUsageCount(DigitalAssetItem $asset) {
    $asset_id = $asset->id();

    return (int) $this->database->select('digital_asset_usage', 'dau')
      ->condition('asset_id', $asset_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Queues an asset for archive (Step 1 of two-step workflow).
   *
   * Always creates a new archive entry. Each archive action gets its own
   * record with a unique UUID for audit trail purposes.
   *
   * Blocks archiving if file has an active archive record (queued,
   * archived_public, archived_admin, or exemption_void). Files with
   * archived_deleted status can be re-archived as new entries.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The digital asset item.
   * @param string $reason
   *   The archive reason (reference, research, recordkeeping, other).
   * @param string $reason_other
   *   Custom reason if "other" is selected.
   * @param string $public_description
   *   Public description for the Archive Registry.
   * @param string $internal_notes
   *   Internal notes (optional).
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive
   *   The created DigitalAssetArchive entity.
   *
   * @throws \Exception
   *   If the asset cannot be archived (validation failed or already has active record).
   */
  public function markForArchive(DigitalAssetItem $asset, $reason, $reason_other = '', $public_description = '', $internal_notes = '') {
    // Validate asset can be archived.
    if (!$this->canArchive($asset)) {
      throw new \Exception('Only document assets can be archived.');
    }

    // Block if ANY active archive record exists.
    // Files with archived_deleted status are considered closed and can be
    // re-archived as new entries (creating distinct audit trails).
    $active_record = $this->getActiveArchiveRecord($asset);
    if ($active_record) {
      $status_label = $active_record->getStatusLabel();
      throw new \Exception("This asset already has an active archive record (status: {$status_label}). You must unarchive it first before archiving again.");
    }

    // Always create a new archive entry for audit trail integrity.
    $archived_asset = DigitalAssetArchive::create([
      'original_fid' => $asset->get('fid')->value,
      'original_path' => $asset->get('file_path')->value,
      'file_name' => $asset->get('file_name')->value,
      'archive_reason' => $reason,
      'archive_reason_other' => $reason_other,
      'public_description' => $public_description,
      'internal_notes' => $internal_notes,
      'asset_type' => $asset->get('asset_type')->value,
      'mime_type' => $asset->get('mime_type')->value,
      'filesize' => $asset->get('filesize')->value,
      'is_private' => $asset->get('is_private')->value,
      'status' => 'queued',
      'archived_by' => $this->currentUser->id(),
    ]);

    $archived_asset->save();

    // Log the appropriate reason.
    $log_reason = ($reason === 'other' && $reason_other) ? $reason_other : $reason;
    $this->logger->notice('User @user queued asset @filename for archive (new record). Reason: @reason', [
      '@user' => $this->currentUser->getAccountName(),
      '@filename' => $asset->get('file_name')->value,
      '@reason' => $log_reason,
    ]);

    return $archived_asset;
  }

  /**
   * Executes the archive process (Step 2 of two-step workflow).
   *
   * This method validates all execution gates before archiving:
   * - File exists at original location
   * - No active usage detected
   * - Asset is a supported document type.
   *
   * Files remain at their original locations. Archiving is a compliance
   * classification, not a storage operation.
   *
   * If validation fails, flags are set and an exception is thrown.
   * Status remains 'queued' if blocked.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   * @param string $visibility
   *   The visibility setting: 'public' or 'admin'. Required, no default.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   *
   * @throws \Exception
   *   If the archive process fails or validation gates fail.
   */
  public function executeArchive(DigitalAssetArchive $archived_asset, string $visibility) {
    if (!$archived_asset->isQueued()) {
      throw new \Exception('Only queued assets can be archived.');
    }

    // Validate visibility parameter - no default, must be explicit.
    if (!in_array($visibility, ['public', 'admin'], TRUE)) {
      throw new \Exception('Visibility must be explicitly set to "public" or "admin".');
    }

    // Validate execution gates before proceeding.
    $blocking_issues = $this->validateExecutionGates($archived_asset);
    if (!empty($blocking_issues)) {
      // Set appropriate flags but keep status as queued.
      if (isset($blocking_issues['file_missing'])) {
        $archived_asset->setFlagMissing(TRUE);
        $archived_asset->save();
        throw new \Exception('Archive blocked: ' . $blocking_issues['file_missing']);
      }
      if (isset($blocking_issues['usage_detected'])) {
        $archived_asset->setFlagUsage(TRUE);
        $archived_asset->save();
        throw new \Exception('Archive blocked: ' . $blocking_issues['usage_detected']);
      }
      // Generic blocking issue.
      throw new \Exception('Archive blocked: ' . implode('; ', $blocking_issues));
    }

    $original_fid = $archived_asset->getOriginalFid();
    $original_path = $archived_asset->getOriginalPath();
    $file_name = $archived_asset->getFileName();
    $filesize = $archived_asset->getFilesize();

    // Get the source file URI to calculate checksum.
    $source_uri = $this->resolveSourceUri($original_path, $original_fid);

    // Calculate checksum for integrity verification.
    // Skip immediate calculation for large files (>50MB) to avoid timeout.
    $checksum = NULL;
    $checksum_pending = FALSE;
    if ($filesize && $filesize > self::CHECKSUM_SIZE_LIMIT) {
      // Large file - queue for batch checksum calculation.
      $checksum_pending = TRUE;
      $this->queueChecksumCalculation($archived_asset);
    }
    else {
      // Small file - calculate checksum immediately.
      $checksum = $this->calculateChecksum($source_uri);
    }

    // Generate the absolute URL for the file (stays at original location).
    $archive_url = $this->fileUrlGenerator->generateAbsoluteString($source_uri);

    // Clear any warning flags since we passed validation.
    $archived_asset->clearFlags();

    // Update archive record with archived status.
    // File stays at its original location - no movement.
    $archived_asset->setArchivePath($archive_url);
    if ($checksum) {
      $archived_asset->setFileChecksum($checksum);
    }

    // Set status based on visibility choice (no default - must be explicit).
    $status = ($visibility === 'public') ? 'archived_public' : 'archived_admin';
    $archived_asset->setStatus($status);

    // Set archive classification date - immutable compliance decision point.
    $classification_time = time();
    $archived_asset->setArchiveClassificationDate($classification_time);

    // Check if archiving after ADA compliance deadline.
    // Get deadline from config (defaults to April 24, 2026 if not set).
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $compliance_deadline = $config->get('ada_compliance_deadline');

    if (!$compliance_deadline) {
      // Default: April 24, 2026 00:00:00 UTC.
      $compliance_deadline = strtotime('2026-04-24 00:00:00 UTC');
    }

    // If archiving after the deadline, set warning flag.
    // This does NOT block the archive - just records it for audit purposes.
    if ($classification_time > $compliance_deadline) {
      $archived_asset->setFlagLateArchive(TRUE);
    }

    // If this file has an existing exemption_void record, force General Archive.
    // Once an exemption has been voided for a file, that file permanently loses
    // eligibility for Legacy Archive status. The voided record remains as
    // immutable audit trail documenting the original exemption violation.
    if (!$archived_asset->hasFlagLateArchive() && $this->hasVoidedExemptionByFid($original_fid)) {
      $archived_asset->setFlagLateArchive(TRUE);
    }

    $archived_asset->save();

    $visibility_label = ($visibility === 'public') ? 'Public' : 'Admin-only';
    if ($checksum_pending) {
      $this->logger->notice('User @user archived file @filename (@visibility). Checksum queued for batch processing (file size: @size). File remains at: @path', [
        '@user' => $this->currentUser->getAccountName(),
        '@filename' => $file_name,
        '@visibility' => $visibility_label,
        '@size' => ByteSizeMarkup::create($filesize),
        '@path' => $archive_url,
      ]);
    }
    else {
      $this->logger->notice('User @user archived file @filename (@visibility) (checksum: @checksum). File remains at: @path', [
        '@user' => $this->currentUser->getAccountName(),
        '@filename' => $file_name,
        '@visibility' => $visibility_label,
        '@checksum' => $checksum,
        '@path' => $archive_url,
      ]);
    }

    return TRUE;
  }

  /**
   * Validates execution gates before archiving.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return array
   *   Array of blocking issues (empty if all gates pass).
   */
  public function validateExecutionGates(DigitalAssetArchive $archived_asset) {
    $issues = [];

    $original_fid = $archived_asset->getOriginalFid();
    $original_path = $archived_asset->getOriginalPath();

    // Gate 1: Check source file exists.
    $source_uri = $this->resolveSourceUri($original_path, $original_fid);
    if (!$source_uri) {
      $issues['file_missing'] = 'Cannot resolve source file path.';
      return $issues;
    }

    $real_path = $this->fileSystem->realpath($source_uri);
    if (!$real_path || !file_exists($real_path)) {
      $issues['file_missing'] = 'Source file does not exist at: ' . $original_path;
      return $issues;
    }

    // Gate 2: Check for active usage.
    $usage_count = $this->getUsageCountByArchive($archived_asset);
    if ($usage_count > 0) {
      $issues['usage_detected'] = 'File is still referenced in ' . $usage_count . ' location(s). Remove references before archiving.';
    }

    return $issues;
  }

  /**
   * Gets the usage count for an archived asset by checking digital_asset_item.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return int
   *   The usage count.
   */
  public function getUsageCountByArchive(DigitalAssetArchive $archived_asset) {
    $original_fid = $archived_asset->getOriginalFid();
    $original_path = $archived_asset->getOriginalPath();

    // Find the corresponding digital_asset_item.
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $query = $storage->getQuery()->accessCheck(FALSE);

    if ($original_fid) {
      $query->condition('fid', $original_fid);
    }
    else {
      $query->condition('file_path', $original_path);
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return 0;
    }

    $asset_id = reset($ids);

    return (int) $this->database->select('digital_asset_usage', 'dau')
      ->condition('asset_id', $asset_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Calculates SHA256 checksum of a file.
   *
   * @param string $uri
   *   The file URI.
   *
   * @return string
   *   The SHA256 checksum.
   *
   * @throws \Exception
   *   If checksum cannot be calculated.
   */
  public function calculateChecksum($uri) {
    $real_path = $this->fileSystem->realpath($uri);
    if (!$real_path || !file_exists($real_path)) {
      throw new \Exception('Cannot calculate checksum: file not found.');
    }

    $hash = hash_file('sha256', $real_path);
    if ($hash === FALSE) {
      throw new \Exception('Failed to calculate SHA256 checksum.');
    }

    return $hash;
  }

  /**
   * Queues a large file for batch checksum calculation.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   */
  protected function queueChecksumCalculation(DigitalAssetArchive $archived_asset) {
    $queue = $this->queueFactory->get('digital_asset_archive_checksum');
    $queue->createItem([
      'archive_id' => $archived_asset->id(),
    ]);
  }

  /**
   * Processes pending checksum calculations for large files.
   *
   * Called via cron or drush command.
   *
   * @return int
   *   Number of checksums calculated.
   */
  public function processPendingChecksums() {
    $queue = $this->queueFactory->get('digital_asset_archive_checksum');
    $processed = 0;

    while ($item = $queue->claimItem(300)) {
      $archive_id = $item->data['archive_id'];
      $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
      $archived_asset = $storage->load($archive_id);

      if ($archived_asset && empty($archived_asset->getFileChecksum())) {
        try {
          $archive_path = $archived_asset->getArchivePath();
          $original_fid = $archived_asset->getOriginalFid();
          $uri = $this->resolveSourceUri($archive_path, $original_fid);

          if ($uri) {
            $checksum = $this->calculateChecksum($uri);
            $archived_asset->setFileChecksum($checksum);
            $archived_asset->save();

            $this->logger->notice('Batch checksum calculated for @filename: @checksum', [
              '@filename' => $archived_asset->getFileName(),
              '@checksum' => $checksum,
            ]);
            $processed++;
          }
        }
        catch (\Exception $e) {
          $this->logger->error('Failed to calculate checksum for @filename: @error', [
            '@filename' => $archived_asset->getFileName(),
            '@error' => $e->getMessage(),
          ]);
        }
      }

      $queue->deleteItem($item);
    }

    return $processed;
  }

  /**
   * Gets archives with pending checksums.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive[]
   *   Array of archives missing checksums.
   */
  public function getArchivesWithPendingChecksums() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ['archived_public', 'archived_admin'], 'IN')
      ->notExists('file_checksum')
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Verifies file integrity against stored checksum.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return bool
   *   TRUE if checksum matches, FALSE otherwise.
   */
  public function verifyIntegrity(DigitalAssetArchive $archived_asset) {
    $stored_checksum = $archived_asset->getFileChecksum();
    if (empty($stored_checksum)) {
      return TRUE;
    }

    $archive_path = $archived_asset->getArchivePath();
    $uri = $this->resolveSourceUri($archive_path, NULL);
    if (!$uri) {
      return FALSE;
    }

    try {
      $current_checksum = $this->calculateChecksum($uri);
      return $stored_checksum === $current_checksum;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if the system is currently in ADA compliance mode.
   *
   * ADA compliance mode is active when the current date is BEFORE the
   * ADA compliance deadline (April 24, 2026 by default). In this mode,
   * archives are created for ADA Title II compliance purposes.
   *
   * After the deadline, the system operates in "General Archive" mode
   * where archives are created for general reference/recordkeeping.
   *
   * @return bool
   *   TRUE if before deadline (ADA compliance mode), FALSE otherwise.
   */
  public function isAdaComplianceMode() {
    return !$this->isAfterComplianceDeadline();
  }

  /**
   * Checks if an archive record is a "Legacy Archive" (pre-deadline).
   *
   * Legacy archives were created before the ADA compliance deadline and
   * are eligible for ADA Title II accessibility exemptions.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return bool
   *   TRUE if archived before deadline (legacy), FALSE otherwise.
   */
  public function isLegacyArchive(DigitalAssetArchive $archived_asset) {
    // flag_late_archive = FALSE means archived before deadline (Legacy Archive).
    // flag_late_archive = TRUE means archived after deadline (General Archive).
    return !$archived_asset->hasFlagLateArchive();
  }

  /**
   * Checks if a file has an existing exemption_void archive record.
   *
   * Files with voided exemptions permanently lose eligibility for Legacy Archive
   * status. Any new archive entry must be classified as General Archive.
   *
   * @param int|null $fid
   *   The file ID to check.
   *
   * @return bool
   *   TRUE if the file has a voided exemption record, FALSE otherwise.
   */
  public function hasVoidedExemptionByFid($fid) {
    if (empty($fid)) {
      return FALSE;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
      $count = $storage->getQuery()
        ->condition('original_fid', $fid)
        ->condition('status', 'exemption_void')
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      return $count > 0;
    }
    catch (\Exception $e) {
      // Log error but don't block - default to allowing Legacy Archive.
      return FALSE;
    }
  }

  /**
   * Gets the formatted ADA compliance deadline.
   *
   * @return string
   *   The deadline formatted as "F j, Y" (e.g., "April 24, 2026").
   */
  public function getComplianceDeadlineFormatted() {
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $deadline = $config->get('ada_compliance_deadline');

    if (!$deadline) {
      // Default: April 24, 2026 00:00:00 UTC.
      $deadline = strtotime('2026-04-24 00:00:00 UTC');
    }

    // Use gmdate() since timestamp is stored in UTC.
    return gmdate('F j, Y', $deadline);
  }

  /**
   * Checks if the current date is after the ADA compliance deadline.
   *
   * Used to determine if integrity violations should trigger exemption void.
   * The deadline is configurable via module settings.
   *
   * @return bool
   *   TRUE if current date is after the deadline, FALSE otherwise.
   */
  protected function isAfterComplianceDeadline() {
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $deadline = $config->get('ada_compliance_deadline');

    if (!$deadline) {
      // Default: April 24, 2026 00:00:00 UTC.
      $deadline = strtotime('2026-04-24 00:00:00 UTC');
    }

    return time() > $deadline;
  }

  /**
   * Re-verifies and updates flags for an archived item.
   *
   * Checks: file exists, checksum matches, no new usage.
   * Sets appropriate flags. If integrity violation detected after ADA
   * compliance deadline, changes status to exemption_void.
   *
   * Skipped for:
   * - Manual entries (URLs) - no files to validate.
   * - Archived (Deleted) items - files were intentionally deleted.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return array
   *   Array of active flags after reconciliation.
   */
  public function reconcileStatus(DigitalAssetArchive $archived_asset) {
    // Handle queued items separately.
    if ($archived_asset->isQueued()) {
      return $this->reconcileQueuedStatus($archived_asset);
    }

    // Only reconcile archived items.
    if (!$archived_asset->isArchived()) {
      return [];
    }

    // Skip manual entries (URLs) - they don't have files to validate.
    if ($archived_asset->isManualEntry()) {
      return [];
    }

    // Skip archived_deleted items - files were intentionally deleted.
    // Per spec: "Suppress missing-file warnings for Archived (Deleted)".
    if ($archived_asset->isArchivedDeleted()) {
      return [];
    }

    // Skip exemption_void items - already voided, no further action needed.
    if ($archived_asset->isExemptionVoid()) {
      return [];
    }

    // Capture original flag states and status to detect changes.
    $original_flags = [
      'flag_usage' => $archived_asset->hasFlagUsage(),
      'flag_missing' => $archived_asset->hasFlagMissing(),
      'flag_integrity' => $archived_asset->hasFlagIntegrity(),
    ];
    $original_status = $archived_asset->getStatus();
    $status_changed = FALSE;

    // Clear all flags before re-checking.
    $archived_asset->clearFlags();
    $active_flags = [];

    $archive_path = $archived_asset->getArchivePath();
    $original_fid = $archived_asset->getOriginalFid();
    $uri = $this->resolveSourceUri($archive_path, $original_fid);

    // Check 1: File exists.
    if (!$uri) {
      $archived_asset->setFlagMissing(TRUE);
      $active_flags[] = 'flag_missing';
    }
    else {
      $real_path = $this->fileSystem->realpath($uri);
      if (!$real_path || !file_exists($real_path)) {
        $archived_asset->setFlagMissing(TRUE);
        $active_flags[] = 'flag_missing';
      }
      else {
        // Check 2: Integrity verification (only if file exists).
        if (!$this->verifyIntegrity($archived_asset)) {
          $archived_asset->setFlagIntegrity(TRUE);
          $active_flags[] = 'flag_integrity';

          // If after ADA compliance deadline and integrity violated on an
          // active archived document, handle based on archive type.
          if ($archived_asset->isArchivedActive() && $this->isAfterComplianceDeadline()) {
            // Check if this is a Legacy Archive (pre-deadline) or General Archive (post-deadline).
            $is_legacy_archive = $this->isLegacyArchive($archived_asset);

            if ($is_legacy_archive) {
              // Legacy Archive: Void the ADA exemption.
              $archived_asset->setStatus('exemption_void');
              $status_changed = TRUE;
              $this->logger->warning('ADA exemption voided for @filename: file modified after compliance deadline. Previous status: @status.', [
                '@filename' => $archived_asset->getFileName(),
                '@status' => $original_status,
              ]);
            }
            else {
              // General Archive: Set to archived_deleted (integrity flag already set above).
              // For file-based archives, flag_integrity indicates the modification.
              // flag_modified is only used for manual entries (pages/external).
              $archived_asset->setStatus('archived_deleted');
              $archived_asset->setDeletedDate(time());
              $archived_asset->setDeletedBy($this->currentUser->id());
              $status_changed = TRUE;
              $this->logger->notice('General archive @filename removed from public view: file modified after archiving (integrity violation). Previous status: @status.', [
                '@filename' => $archived_asset->getFileName(),
                '@status' => $original_status,
              ]);
            }
          }
        }
      }
    }

    // Check 3: Usage detected (check original file references).
    $usage_count = $this->getUsageCountByArchive($archived_asset);
    if ($usage_count > 0) {
      $archived_asset->setFlagUsage(TRUE);
      $active_flags[] = 'flag_usage';
    }

    // Only save if flags or status actually changed to avoid unnecessary cache invalidation.
    $new_flags = [
      'flag_usage' => $archived_asset->hasFlagUsage(),
      'flag_missing' => $archived_asset->hasFlagMissing(),
      'flag_integrity' => $archived_asset->hasFlagIntegrity(),
    ];

    if ($original_flags !== $new_flags || $status_changed) {
      $archived_asset->save();
    }

    return $active_flags;
  }

  /**
   * Re-verifies and updates flags for a queued item.
   *
   * Checks: file exists, active usage (which blocks archiving).
   * Sets appropriate flags to warn user before they attempt to archive.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity (must be queued status).
   *
   * @return array
   *   Array of active flags after reconciliation.
   */
  protected function reconcileQueuedStatus(DigitalAssetArchive $archived_asset) {
    // Skip manual entries (URLs) - they don't have files to validate.
    if ($archived_asset->isManualEntry()) {
      return [];
    }

    // Capture original flag states to detect changes.
    $original_flags = [
      'flag_usage' => $archived_asset->hasFlagUsage(),
      'flag_missing' => $archived_asset->hasFlagMissing(),
    ];

    // Clear flags before re-checking.
    $archived_asset->set('flag_usage', FALSE);
    $archived_asset->set('flag_missing', FALSE);
    $active_flags = [];

    $original_path = $archived_asset->getOriginalPath();
    $original_fid = $archived_asset->getOriginalFid();
    $uri = $this->resolveSourceUri($original_path, $original_fid);

    // Check 1: File exists.
    // For queued items, auto-remove if file is missing since there's nothing to archive.
    $file_missing = FALSE;
    if (!$uri) {
      $file_missing = TRUE;
    }
    else {
      $real_path = $this->fileSystem->realpath($uri);
      if (!$real_path || !file_exists($real_path)) {
        $file_missing = TRUE;
      }
    }

    if ($file_missing) {
      // Auto-remove queued items with missing files.
      $file_name = $archived_asset->getFileName();
      $archived_asset->delete();
      $this->logger->notice('Auto-removed @filename from archive queue (source file no longer exists)', [
        '@filename' => $file_name,
      ]);
      return ['auto_removed'];
    }

    // Check 2: Usage detected (blocks archiving).
    $usage_count = $this->getUsageCountByArchive($archived_asset);
    if ($usage_count > 0) {
      $archived_asset->setFlagUsage(TRUE);
      $active_flags[] = 'flag_usage';
    }

    // Only save if flags actually changed.
    $new_flags = [
      'flag_usage' => $archived_asset->hasFlagUsage(),
      'flag_missing' => $archived_asset->hasFlagMissing(),
    ];

    if ($original_flags !== $new_flags) {
      $archived_asset->save();
    }

    return $active_flags;
  }

  /**
   * Removes an asset from the archive queue.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \Exception
   *   If the asset cannot be removed from queue.
   */
  public function removeFromQueue(DigitalAssetArchive $archived_asset) {
    if (!$archived_asset->canRemoveFromQueue()) {
      throw new \Exception('Only queued or blocked assets can be removed from queue.');
    }

    $file_name = $archived_asset->getFileName();

    // Delete the archive entry entirely.
    $archived_asset->delete();

    $this->logger->notice('User @user removed @filename from archive queue', [
      '@user' => $this->currentUser->getAccountName(),
      '@filename' => $file_name,
    ]);

    return TRUE;
  }

  /**
   * Cancels a pending archive (alias for removeFromQueue).
   *
   * @deprecated in digital_asset_inventory:1.1.0 and is removed from
   *   digital_asset_inventory:2.0.0. Use self::removeFromQueue() instead.
   *
   * @see https://www.drupal.org/node/0
   */
  public function cancelArchive(DigitalAssetArchive $archived_asset) {
    return $this->removeFromQueue($archived_asset);
  }

  /**
   * Unarchives an archived asset.
   *
   * Sets status to 'archived_deleted' to remove from public view while
   * preserving the record for audit trail. The file remains at its location.
   *
   * Note: Unarchiving is a permanent action for this record. To archive the
   * same file again (e.g., after modifications), create a new archive entry.
   * This ensures each archive action has its own audit trail.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \Exception
   *   If the asset cannot be unarchived.
   */
  public function unarchive(DigitalAssetArchive $archived_asset) {
    if (!$archived_asset->canUnarchive()) {
      throw new \Exception('Only active archived assets or voided exemptions can be unarchived.');
    }

    $file_name = $archived_asset->getFileName();

    // Set status to archived_deleted to remove from public view.
    // The record is preserved for audit trail. To archive the same file again,
    // a new archive entry must be created (ensuring distinct audit trails).
    $archived_asset->setStatus('archived_deleted');

    // Clear warning flags - the file still exists, we're just removing from registry.
    // This prevents showing "File Missing" for unarchived items where file is intact.
    $archived_asset->clearFlags();

    $archived_asset->save();

    $this->logger->notice('User @user unarchived @filename (record preserved as deleted)', [
      '@user' => $this->currentUser->getAccountName(),
      '@filename' => $file_name,
    ]);

    return TRUE;
  }

  /**
   * Toggles visibility between public and admin-only.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \Exception
   *   If visibility cannot be toggled.
   */
  public function toggleVisibility(DigitalAssetArchive $archived_asset) {
    if (!$archived_asset->canToggleVisibility()) {
      throw new \Exception('Only active archived assets can have visibility toggled.');
    }

    $file_name = $archived_asset->getFileName();
    $current_status = $archived_asset->getStatus();

    // Toggle between archived_public and archived_admin.
    if ($current_status === 'archived_public') {
      $new_status = 'archived_admin';
      $visibility_label = 'Admin-only';
    }
    else {
      $new_status = 'archived_public';
      $visibility_label = 'Public';
    }

    $archived_asset->setStatus($new_status);
    $archived_asset->save();

    $this->logger->notice('User @user changed visibility of @filename to @visibility', [
      '@user' => $this->currentUser->getAccountName(),
      '@filename' => $file_name,
      '@visibility' => $visibility_label,
    ]);

    return TRUE;
  }

  /**
   * Deletes the physical file for an archived asset.
   *
   * Sets status to archived_deleted, records deletion metadata.
   * The archive record is preserved as an audit trail.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return bool
   *   TRUE on success.
   *
   * @throws \Exception
   *   If the file cannot be deleted.
   */
  public function deleteFile(DigitalAssetArchive $archived_asset) {
    if (!$archived_asset->canDeleteFile()) {
      throw new \Exception('Only active archived or voided file assets can have their files deleted.');
    }

    $file_name = $archived_asset->getFileName();
    $original_fid = $archived_asset->getOriginalFid();
    $archive_path = $archived_asset->getArchivePath();

    // Resolve the file URI.
    $uri = $this->resolveSourceUri($archive_path, $original_fid);
    if (!$uri) {
      throw new \Exception('Cannot resolve file path for deletion.');
    }

    // Delete the physical file.
    $real_path = $this->fileSystem->realpath($uri);
    if ($real_path && file_exists($real_path)) {
      if (!$this->fileSystem->delete($uri)) {
        throw new \Exception('Failed to delete file: ' . $file_name);
      }
    }

    // If there's a managed file entity, delete it too.
    if ($original_fid) {
      $file_storage = $this->entityTypeManager->getStorage('file');
      $file = $file_storage->load($original_fid);
      if ($file) {
        $file->delete();
      }
    }

    // Update archive record to archived_deleted status.
    $archived_asset->setStatus('archived_deleted');
    $archived_asset->setDeletedDate(time());
    $archived_asset->setDeletedBy($this->currentUser->id());
    $archived_asset->save();

    $this->logger->notice('User @user deleted archived file @filename. Archive record preserved.', [
      '@user' => $this->currentUser->getAccountName(),
      '@filename' => $file_name,
    ]);

    return TRUE;
  }

  /**
   * Ensures the archive directory exists.
   *
   * @return bool
   *   TRUE if the directory exists or was created.
   *
   * @throws \Exception
   *   If the directory cannot be created.
   */
  public function ensureArchiveDirectory() {
    $directory = self::ARCHIVE_DIRECTORY;

    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \Exception('Cannot create archive directory: ' . $directory);
    }

    return TRUE;
  }

  /**
   * Resolves the source URI from path or fid.
   *
   * @param string $original_path
   *   The original path (may be URL).
   * @param int|null $original_fid
   *   The original file ID.
   *
   * @return string|null
   *   The resolved URI or NULL.
   */
  protected function resolveSourceUri($original_path, $original_fid) {
    // If we have a fid, get the URI from file_managed.
    if ($original_fid) {
      $file_storage = $this->entityTypeManager->getStorage('file');
      $file = $file_storage->load($original_fid);
      if ($file) {
        return $file->getFileUri();
      }
    }

    // Try to resolve from path.
    // Handle absolute URLs.
    if (strpos($original_path, 'http://') === 0 || strpos($original_path, 'https://') === 0) {
      // Extract relative path from URL.
      if (preg_match('#/sites/default/files/(.+)$#', $original_path, $matches)) {
        return 'public://' . urldecode($matches[1]);
      }
      if (preg_match('#/system/files/(.+)$#', $original_path, $matches)) {
        return 'private://' . urldecode($matches[1]);
      }
    }

    // Handle stream wrappers directly.
    if (strpos($original_path, 'public://') === 0 || strpos($original_path, 'private://') === 0) {
      return $original_path;
    }

    return NULL;
  }

  /**
   * Gets all queued archives (including blocked).
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive[]
   *   Array of queued/blocked DigitalAssetArchive entities.
   */
  public function getPendingArchives() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'queued')
      ->sort('created', 'DESC')
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Gets all queued archives only (not blocked).
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive[]
   *   Array of queued DigitalAssetArchive entities.
   */
  public function getQueuedArchives() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'queued')
      ->sort('created', 'DESC')
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Gets all queued archives with warning flags (blocked).
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive[]
   *   Array of queued DigitalAssetArchive entities with warning flags.
   */
  public function getBlockedArchives() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');

    // Find queued items that have any warning flags set.
    $or_group = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'queued')
      ->orConditionGroup()
      ->condition('flag_usage', TRUE)
      ->condition('flag_missing', TRUE)
      ->condition('flag_integrity', TRUE);

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'queued')
      ->condition($or_group)
      ->sort('created', 'DESC')
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Gets all archived assets.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive[]
   *   Array of archived DigitalAssetArchive entities.
   */
  public function getArchivedAssets() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ['archived_public', 'archived_admin', 'archived_deleted'], 'IN')
      ->sort('archive_classification_date', 'DESC')
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Gets archived assets with problems (warning flags).
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive[]
   *   Array of archived DigitalAssetArchive entities with warning flags.
   */
  public function getArchivedWithProblems() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');

    // Find archived items that have any warning flags set.
    $or_group = $storage->getQuery()
      ->accessCheck(FALSE)
      ->orConditionGroup()
      ->condition('flag_usage', TRUE)
      ->condition('flag_missing', TRUE)
      ->condition('flag_integrity', TRUE);

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ['archived_public', 'archived_admin', 'archived_deleted'], 'IN')
      ->condition($or_group)
      ->sort('archive_classification_date', 'DESC')
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Validates archived and queued files and reconciles their status.
   *
   * Called before archive view renders to check file integrity.
   * Updates warning flags based on: file existence, checksum, new usage.
   * Warning flags are displayed in the UI via badge indicators.
   */
  public function validateArchivedFiles() {
    // Validate archived items.
    $archived_assets = $this->getArchivedAssets();
    foreach ($archived_assets as $archived_asset) {
      $this->reconcileStatus($archived_asset);
    }

    // Also validate queued items to show usage/missing warnings proactively.
    $queued_assets = $this->getQueuedArchives();
    foreach ($queued_assets as $queued_asset) {
      $this->reconcileStatus($queued_asset);
    }
  }

  /**
   * Checks if a file path is in the archive directory.
   *
   * @param string $path
   *   The file path or URI.
   *
   * @return bool
   *   TRUE if the file is in the archive directory.
   */
  public function isArchivePath($path) {
    // Check for archive directory in various path formats.
    $patterns = [
      '#/sites/default/files/archive/#',
      '#^public://archive/#',
      '#/archive/archive_#',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $path)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
