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
use Drupal\Core\StringTranslation\StringTranslationTrait;
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

  use StringTranslationTrait;

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
      /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null $archive */
      $archive = $storage->load(reset($ids));
      return $archive;
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
      /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null $archive */
      $archive = $storage->load(reset($ids));
      return $archive;
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
      /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null $archive */
      $archive = $storage->load(reset($ids));
      return $archive;
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
    // Only check for active statuses - terminal states (archived_deleted,
    // exemption_void) should not block creating new archive entries.
    // Files with exemption_void can be re-archived but are forced to General Archive.
    $active_statuses = ['queued', 'archived_public', 'archived_admin'];
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
      /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null $archive */
      $archive = $storage->load(reset($ids));
      return $archive;
    }

    return NULL;
  }

  /**
   * Gets an archive record for badge display purposes.
   *
   * Returns archive records with these statuses:
   * - queued, archived_public, archived_admin: Active statuses
   * - exemption_void: Terminal state but still shows badge for user awareness
   *
   * Unlike getActiveArchiveRecord(), this includes exemption_void status
   * so badges can inform users that a file had its ADA exemption voided.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The digital asset item.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The archive record for badge display, or NULL if none found.
   */
  public function getArchiveRecordForBadge(DigitalAssetItem $asset) {
    $fid = $asset->get('fid')->value;
    $original_path = $asset->get('file_path')->value;

    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    // Include active statuses plus exemption_void for badge display.
    // exemption_void shows badge to inform users but still allows re-archiving.
    // archived_deleted is excluded - file was unarchived and should show no badge.
    $badge_statuses = ['queued', 'archived_public', 'archived_admin', 'exemption_void'];
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', $badge_statuses, 'IN');

    if ($fid) {
      $query->condition('original_fid', $fid);
    }
    else {
      $query->condition('original_path', $original_path);
    }

    $ids = $query->execute();
    if (!empty($ids)) {
      /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null $archive */
      $archive = $storage->load(reset($ids));
      return $archive;
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

    // If queued while in use, create an audit note.
    $usage_count = $this->getUsageCount($asset);
    if ($usage_count > 0 && $this->isArchiveInUseAllowed()) {
      $this->createArchiveNote(
        $archived_asset,
        $this->t('Queued for archive while in use (@count references).', ['@count' => $usage_count])
      );
    }

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
      if (isset($blocking_issues['usage_policy_blocked'])) {
        $info = $blocking_issues['usage_policy_blocked'];
        $archived_asset->setFlagUsage(TRUE);
        $archived_asset->save();
        throw new \Exception('Archive blocked: ' . $info['message'] . ' ' . $info['reason']);
      }
      // Generic blocking issue.
      $messages = [];
      foreach ($blocking_issues as $issue) {
        $messages[] = is_array($issue) ? $issue['message'] : $issue;
      }
      throw new \Exception('Archive blocked: ' . implode('; ', $messages));
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

    // Check if archiving while in use.
    $usage_count = $this->getUsageCountByArchive($archived_asset);
    $archived_while_in_use = $usage_count > 0 && $this->isArchiveInUseAllowed();

    // Track archived-while-in-use status for audit trail.
    if ($archived_while_in_use) {
      $archived_asset->setArchivedWhileInUse(TRUE);
      $archived_asset->setUsageCountAtArchive($usage_count);
      // Keep flag_usage TRUE to maintain visibility in Archive Management.
      $archived_asset->setFlagUsage(TRUE);
    }
    else {
      // Clear any warning flags since we passed validation.
      $archived_asset->clearFlags();
    }

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

    // Update archived_by to the user who executed (made the compliance decision).
    $archived_asset->set('archived_by', $this->currentUser->id());

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
    // Use flag_prior_void instead of flag_late_archive to distinguish the reason.
    if (!$archived_asset->hasFlagLateArchive() && $this->hasVoidedExemptionByFid($original_fid)) {
      $archived_asset->setFlagLateArchive(TRUE);
      $archived_asset->setFlagPriorVoid(TRUE);
    }

    $archived_asset->save();

    // Create note in the archive review log.
    $visibility_label = ($visibility === 'public') ? 'Public' : 'Admin-only';
    $archive_type = $archived_asset->hasFlagLateArchive() ? 'General Archive' : 'Legacy Archive';
    $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
    $note = $note_storage->create([
      'archive_id' => $archived_asset->id(),
      'note_text' => 'Archived as ' . $visibility_label . ' (' . $archive_type . ').',
      'author' => $this->currentUser->id(),
    ]);
    $note->save();

    // Add note for archived-while-in-use per spec FR-4.
    if ($archived_while_in_use) {
      $usage_note = $note_storage->create([
        'archive_id' => $archived_asset->id(),
        'note_text' => 'Archived while in use (' . $usage_count . ' references).',
        'author' => $this->currentUser->id(),
      ]);
      $usage_note->save();
    }

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
    // Skip usage gate if allow_archive_in_use is enabled for documents/videos.
    $usage_count = $this->getUsageCountByArchive($archived_asset);
    if ($usage_count > 0) {
      if (!$this->isArchiveInUseAllowed()) {
        // Use specific key to indicate policy-blocked (config disabled).
        // This allows forms to show appropriate messaging.
        $issues['usage_policy_blocked'] = [
          'message' => 'This asset is currently in use and cannot be archived.',
          'usage_count' => $usage_count,
          'reason' => 'Current settings do not allow archiving assets that are in use.',
        ];
      }
      // If allowed, we track this but don't block - see executeArchive().
    }

    return $issues;
  }

  /**
   * Checks if a visibility toggle to public would be blocked.
   *
   * An archived asset cannot be made public if:
   * - It is currently in use (has active references)
   * - AND the allow_archive_in_use setting is disabled
   *
   * This prevents exposing archived content while in use when policy disallows it.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return array|null
   *   NULL if toggle is allowed, or array with blocking info:
   *   - 'usage_count': Number of active references
   *   - 'reason': Why the toggle is blocked
   */
  public function isVisibilityToggleBlocked(DigitalAssetArchive $archived_asset) {
    // Only check when toggling TO public (admin → public).
    if ($archived_asset->getStatus() !== 'archived_admin') {
      return NULL;
    }

    // Manual entries bypass usage gating.
    if ($archived_asset->isManualEntry()) {
      return NULL;
    }

    $usage_count = $this->getUsageCountByArchive($archived_asset);
    if ($usage_count > 0 && !$this->isArchiveInUseAllowed()) {
      return [
        'usage_count' => $usage_count,
        'reason' => 'This asset is currently in use. Changing visibility to Public would expose archived content while in use, which is not allowed by current settings.',
      ];
    }

    return NULL;
  }

  /**
   * Checks if re-archiving would be blocked for a given asset.
   *
   * Used to warn users during unarchive that re-archive may be blocked.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   *
   * @return array|null
   *   NULL if re-archive would be allowed, or array with blocking info:
   *   - 'usage_count': Number of active references
   *   - 'reason': Why re-archive would be blocked
   */
  public function isReArchiveBlocked(DigitalAssetArchive $archived_asset) {
    // Manual entries bypass usage gating.
    if ($archived_asset->isManualEntry()) {
      return NULL;
    }

    $usage_count = $this->getUsageCountByArchive($archived_asset);
    if ($usage_count > 0 && !$this->isArchiveInUseAllowed()) {
      return [
        'usage_count' => $usage_count,
        'reason' => 'This asset is currently in use. Re-archiving will be blocked unless usage is removed or in-use archiving is re-enabled in settings.',
      ];
    }

    return NULL;
  }

  /**
   * Checks if archiving documents/videos while in use is allowed.
   *
   * This setting controls whether users may CREATE new archives for assets
   * that are still referenced by active content. It is a policy gate that
   * affects the archive creation workflow.
   *
   * IMPORTANT: This setting does NOT affect the behavior of existing archives.
   * Link routing (redirecting site links to Archive Detail Pages) is always
   * active for archived assets when the archive feature is enabled.
   *
   * @return bool
   *   TRUE if archiving while in use is allowed, FALSE otherwise.
   */
  public function isArchiveInUseAllowed() {
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    return (bool) $config->get('allow_archive_in_use');
  }

  /**
   * Creates an archive note for audit trail.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archived_asset
   *   The DigitalAssetArchive entity.
   * @param string $note_text
   *   The note text to record.
   * @param int|null $author
   *   The user ID of the author. Defaults to current user.
   */
  public function createArchiveNote(DigitalAssetArchive $archived_asset, string $note_text, ?int $author = NULL): void {
    $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
    $note = $note_storage->create([
      'archive_id' => $archived_asset->id(),
      'note_text' => $note_text,
      'author' => $author ?? $this->currentUser->id(),
    ]);
    $note->save();
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

      // Create note only on first detection of missing file.
      if (!$original_flags['flag_missing']) {
        $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
        $note = $note_storage->create([
          'archive_id' => $archived_asset->id(),
          'note_text' => 'File missing detected: archived file no longer exists at its original location.',
          'author' => 0,
        ]);
        $note->save();
      }
    }
    else {
      $real_path = $this->fileSystem->realpath($uri);
      if (!$real_path || !file_exists($real_path)) {
        $archived_asset->setFlagMissing(TRUE);
        $active_flags[] = 'flag_missing';

        // Create note only on first detection of missing file.
        if (!$original_flags['flag_missing']) {
          $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
          $note = $note_storage->create([
            'archive_id' => $archived_asset->id(),
            'note_text' => 'File missing detected: archived file no longer exists at its original location.',
            'author' => 0,
          ]);
          $note->save();
        }
      }
      else {
        // Check 2: Integrity verification (only if file exists).
        if (!$this->verifyIntegrity($archived_asset)) {
          $archived_asset->setFlagIntegrity(TRUE);
          $active_flags[] = 'flag_integrity';

          // If integrity violated on an active archived document,
          // handle based on archive type (Legacy vs General).
          if ($archived_asset->isArchivedActive()) {
            // Check if this is a Legacy Archive (pre-deadline) or General Archive (post-deadline).
            $is_legacy_archive = $this->isLegacyArchive($archived_asset);

            if ($is_legacy_archive) {
              // Legacy Archive: Void the ADA exemption.
              $archived_asset->setStatus('exemption_void');
              $status_changed = TRUE;
              $this->logger->warning('ADA exemption voided for @filename: file modified after archiving. Previous status: @status.', [
                '@filename' => $archived_asset->getFileName(),
                '@status' => $original_status,
              ]);

              // Create automatic note for audit trail.
              $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
              $note = $note_storage->create([
                'archive_id' => $archived_asset->id(),
                'note_text' => 'Integrity violation detected: file checksum does not match. ADA exemption automatically voided.',
                'author' => 0,
              ]);
              $note->save();

              // If was archived while in use, add note about restoring direct access.
              if ($archived_asset->wasArchivedWhileInUse()) {
                $direct_access_note = $note_storage->create([
                  'archive_id' => $archived_asset->id(),
                  'note_text' => 'Exemption voided - direct file access restored.',
                  'author' => 0,
                ]);
                $direct_access_note->save();
              }
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

              // Create automatic note for audit trail.
              $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
              $note = $note_storage->create([
                'archive_id' => $archived_asset->id(),
                'note_text' => 'Integrity violation detected: file checksum does not match. Archive removed from public view.',
                'author' => 0,
              ]);
              $note->save();
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

    // Create note in the archive review log.
    $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');

    // If this was archived while in use, add note about restoring direct access.
    if ($archived_asset->wasArchivedWhileInUse()) {
      $usage_count = $archived_asset->getUsageCountAtArchive();
      $note = $note_storage->create([
        'archive_id' => $archived_asset->id(),
        'note_text' => 'Unarchived - direct file access restored (was archived with ' . $usage_count . ' references).',
        'author' => $this->currentUser->id(),
      ]);
      $note->save();
    }
    else {
      $note = $note_storage->create([
        'archive_id' => $archived_asset->id(),
        'note_text' => 'Removed from archive registry.',
        'author' => $this->currentUser->id(),
      ]);
      $note->save();
    }

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

    // Create note in the archive review log.
    $from_label = ($current_status === 'archived_public') ? 'Public' : 'Admin-only';
    $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
    $note = $note_storage->create([
      'archive_id' => $archived_asset->id(),
      'note_text' => 'Visibility changed from ' . $from_label . ' to ' . $visibility_label . '.',
      'author' => $this->currentUser->id(),
    ]);
    $note->save();

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

    // Create note in the archive review log.
    $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
    $note = $note_storage->create([
      'archive_id' => $archived_asset->id(),
      'note_text' => 'File permanently deleted.',
      'author' => $this->currentUser->id(),
    ]);
    $note->save();

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

  /**
   * Gets an active archive record by file ID.
   *
   * Only returns archives with status archived_public or archived_admin.
   * Does not return queued, archived_deleted, or exemption_void records.
   *
   * @param int $fid
   *   The file entity ID.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The active archive entity if found, NULL otherwise.
   */
  public function getActiveArchiveByFid($fid) {
    if (empty($fid)) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    // Only active statuses route to archive detail page.
    $active_statuses = ['archived_public', 'archived_admin'];

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('original_fid', $fid)
      ->condition('status', $active_statuses, 'IN')
      ->execute();

    if (!empty($ids)) {
      return $storage->load(reset($ids));
    }

    return NULL;
  }

  /**
   * Gets an active archive record by file URI or path.
   *
   * @param string $uri
   *   The file URI (e.g., public://doc.pdf) or absolute URL.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The active archive entity if found, NULL otherwise.
   */
  public function getActiveArchiveByUri($uri) {
    if (empty($uri)) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
    $active_statuses = ['archived_public', 'archived_admin'];

    // Convert URI to absolute URL for comparison.
    $absolute_url = NULL;
    if (strpos($uri, 'public://') === 0 || strpos($uri, 'private://') === 0) {
      try {
        $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
      }
      catch (\Exception $e) {
        // URI might be invalid.
      }
    }
    elseif (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
      $absolute_url = $uri;
    }

    if ($absolute_url) {
      // Try original_path first (exact match).
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('original_path', $absolute_url)
        ->condition('status', $active_statuses, 'IN')
        ->execute();

      if (!empty($ids)) {
        return $storage->load(reset($ids));
      }

      // Try URL-decoded version (handles %20 vs space, etc.).
      $decoded_url = urldecode($absolute_url);
      if ($decoded_url !== $absolute_url) {
        $ids = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('original_path', $decoded_url)
          ->condition('status', $active_statuses, 'IN')
          ->execute();

        if (!empty($ids)) {
          return $storage->load(reset($ids));
        }
      }

      // Also try archive_path (exact match).
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('archive_path', $absolute_url)
        ->condition('status', $active_statuses, 'IN')
        ->execute();

      if (!empty($ids)) {
        return $storage->load(reset($ids));
      }

      // Try matching by file path suffix (handles http/https and host differences).
      // Extract path from URL and try LIKE query on original_path.
      $url_path = parse_url($absolute_url, PHP_URL_PATH);
      if ($url_path && (strpos($url_path, '/sites/') !== FALSE || strpos($url_path, '/system/files/') !== FALSE)) {
        // Try both encoded and decoded path variations.
        $path_variations = [$url_path];
        $decoded_path = urldecode($url_path);
        if ($decoded_path !== $url_path) {
          $path_variations[] = $decoded_path;
        }
        // Also try re-encoding in case stored path is encoded differently.
        $reencoded_path = str_replace(' ', '%20', $decoded_path);
        if ($reencoded_path !== $url_path && $reencoded_path !== $decoded_path) {
          $path_variations[] = $reencoded_path;
        }

        foreach ($path_variations as $path_to_check) {
          // Use ENDS_WITH to match URLs regardless of scheme/host.
          $ids = $storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('original_path', '%' . $path_to_check, 'LIKE')
            ->condition('status', $active_statuses, 'IN')
            ->execute();

          if (!empty($ids)) {
            return $storage->load(reset($ids));
          }
        }
      }
    }

    // Try to find file entity by URI and then look up by fid.
    // First, convert HTTP URL to stream URI if it's a local file path.
    $stream_uri = $this->urlToStreamUri($uri);

    try {
      $file_ids = $this->entityTypeManager->getStorage('file')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uri', $stream_uri)
        ->execute();

      if (!empty($file_ids)) {
        $fid = reset($file_ids);
        return $this->getActiveArchiveByFid($fid);
      }
    }
    catch (\Exception $e) {
      // Ignore lookup errors.
    }

    return NULL;
  }

  /**
   * Converts an HTTP URL to a Drupal stream URI if it's a local file.
   *
   * @param string $url
   *   The URL to convert (can be HTTP URL, stream URI, or relative path).
   *
   * @return string
   *   The stream URI (public:// or private://) if conversion is possible,
   *   or the original URL if not a local file path.
   */
  protected function urlToStreamUri($url) {
    // If already a stream URI, return as-is.
    if (strpos($url, 'public://') === 0 || strpos($url, 'private://') === 0) {
      return $url;
    }

    // Strip internal: or base: prefixes used by menu links.
    $path = $url;
    if (strpos($url, 'internal:') === 0) {
      $path = substr($url, 9);
    }
    elseif (strpos($url, 'base:') === 0) {
      $path = '/' . substr($url, 5);
    }
    // Extract path from URL if it's an absolute URL.
    elseif (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
      $parsed = parse_url($url);
      $path = $parsed['path'] ?? '';
    }

    // Check for public files path pattern.
    // Matches: /sites/default/files/..., /sites/example.com/files/..., etc.
    if (preg_match('#^/?sites/[^/]+/files/(.+)$#', $path, $matches)) {
      return 'public://' . urldecode($matches[1]);
    }

    // Check for private files path pattern (/system/files/...).
    if (preg_match('#^/?system/files/(.+)$#', $path, $matches)) {
      return 'private://' . urldecode($matches[1]);
    }

    // Not a recognized local file path, return original.
    return $url;
  }

  /**
   * Gets the archive detail page URL for a file if routing should be applied.
   *
   * Returns the archive detail URL only when:
   * 1. Link routing is enabled (archive feature is enabled)
   * 2. The file has an active archive (archived_public or archived_admin)
   * 3. The archive is for a document, video, or manual entry (page/external)
   *
   * Link routing is always active when the archive feature is enabled.
   * This ensures consistent user experience: all archived assets route to
   * their Archive Detail Page regardless of the allow_archive_in_use setting.
   *
   * The allow_archive_in_use setting only controls whether users can create
   * new archives for assets with active references - it does not affect
   * routing behavior for existing archives.
   *
   * When archive feature is disabled, archive is not active, or asset type
   * is not eligible (e.g., images, audio), returns NULL so the original
   * file URL can be used.
   *
   * @param int|null $fid
   *   The file entity ID, or NULL.
   * @param string|null $uri
   *   The file URI or URL, or NULL.
   *
   * @return string|null
   *   The archive detail page URL if routing applies, NULL otherwise.
   */
  public function getArchiveDetailUrl($fid = NULL, $uri = NULL) {
    // Only route if link routing is enabled (archive feature enabled).
    if (!$this->isLinkRoutingEnabled()) {
      return NULL;
    }

    $archive = NULL;

    if ($fid) {
      $archive = $this->getActiveArchiveByFid($fid);
    }

    if (!$archive && $uri) {
      $archive = $this->getActiveArchiveByUri($uri);
    }

    if ($archive) {
      // Only redirect for documents, videos, and manual entries (page/external).
      // Images, audio, and compressed files should NOT be redirected.
      $asset_type = $archive->getAssetType();
      if ($this->isRedirectEligibleAssetType($asset_type)) {
        return '/archive-registry/' . $archive->id();
      }
    }

    return NULL;
  }

  /**
   * Checks if an asset type is eligible for URL redirect routing.
   *
   * Only documents, videos, and manual entries (pages/external) are eligible.
   * Images, audio, and compressed files are NOT eligible as redirecting
   * would break page rendering or serve no useful purpose.
   *
   * Asset type eligibility is determined by the category defined in
   * digital_asset_inventory.settings.yml configuration.
   *
   * @param string $asset_type
   *   The asset type (e.g., 'pdf', 'mp4', 'page', 'jpg').
   *
   * @return bool
   *   TRUE if the asset type should be redirected, FALSE otherwise.
   */
  protected function isRedirectEligibleAssetType($asset_type) {
    // Manual entry types (pages and external resources) are always eligible.
    if (in_array($asset_type, ['page', 'external'])) {
      return TRUE;
    }

    // Get the category from configuration.
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $asset_types = $config->get('asset_types') ?? [];

    // Check if this asset type is in the Documents or Videos category.
    if (isset($asset_types[$asset_type]['category'])) {
      $category = $asset_types[$asset_type]['category'];
      return in_array($category, ['Documents', 'Videos']);
    }

    // Unknown asset type - default to not eligible.
    return FALSE;
  }

  /**
   * Checks if file link routing is enabled for archived files.
   *
   * Link routing redirects Drupal-generated links to the Archive Detail Page
   * for documents/videos that have active archive records. This ensures
   * consistent user context and auditability.
   *
   * Link routing is ALWAYS enabled when the archive feature is enabled.
   * It is not controlled by the allow_archive_in_use setting.
   *
   * Design principle: The allow_archive_in_use setting controls whether
   * users may create new archives for assets that are still referenced.
   * It does NOT affect the behavior of existing archives. All archived
   * assets always route site links to the Archive Detail Page.
   *
   * @return bool
   *   TRUE if link routing is enabled (archive feature enabled), FALSE otherwise.
   */
  public function isLinkRoutingEnabled() {
    // Link routing is always enabled when the archive feature is enabled.
    // This is independent of the allow_archive_in_use policy setting.
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $archive_enabled = (bool) $config->get('enable_archive');

    // For backwards compatibility and to ensure routing works during testing,
    // also enable routing if allow_archive_in_use is enabled (even if
    // enable_archive was somehow not set).
    if (!$archive_enabled) {
      $archive_enabled = (bool) $config->get('allow_archive_in_use');
    }

    return $archive_enabled;
  }

  /**
   * Resolves an archive detail URL from a Drupal Url object.
   *
   * This is a centralized resolver that can be used by menus, breadcrumbs,
   * file fields, CKEditor, etc. to determine if a URL points to an archived
   * file and get the corresponding Archive Detail Page URL.
   *
   * @param \Drupal\Core\Url $url
   *   The URL object to check.
   *
   * @return \Drupal\Core\Url|null
   *   A Url object pointing to the Archive Detail Page if the original URL
   *   points to an archived file, NULL otherwise.
   */
  public function resolveArchiveDetailUrlFromUrl($url) {
    // Skip if routing is disabled.
    if (!$this->isLinkRoutingEnabled()) {
      return NULL;
    }

    // Try to get URL string safely.
    $url_string = NULL;
    try {
      // For unrouted URLs, get the URI directly which may have internal: prefix.
      if (!$url->isRouted()) {
        $uri = $url->getUri();
        // Strip internal: or base: prefix.
        if (strpos($uri, 'internal:') === 0) {
          $url_string = substr($uri, 9);
        }
        elseif (strpos($uri, 'base:') === 0) {
          $url_string = '/' . substr($uri, 5);
        }
        else {
          $url_string = $url->toString();
        }
      }
      else {
        $url_string = $url->toString();
      }
    }
    catch (\Exception $e) {
      return NULL;
    }

    if (empty($url_string)) {
      return NULL;
    }

    // Check if this looks like a file URL.
    if (strpos($url_string, '/sites/') === FALSE && strpos($url_string, '/system/files/') === FALSE) {
      return NULL;
    }

    // Skip image URLs - redirecting would break rendering.
    $path = parse_url($url_string, PHP_URL_PATH);
    if ($path) {
      $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp', 'tiff', 'tif'];
      if (in_array($extension, $image_extensions)) {
        return NULL;
      }
    }

    // Build absolute URL for checking.
    $check_url = $url_string;
    if (strpos($url_string, '/') === 0 && strpos($url_string, '//') !== 0) {
      $base_url = \Drupal::request()->getSchemeAndHttpHost();
      $check_url = $base_url . $url_string;
    }

    // Get the archive detail URL path.
    $archive_path = $this->getArchiveDetailUrl(NULL, $check_url);

    if ($archive_path) {
      return \Drupal\Core\Url::fromUserInput($archive_path);
    }

    return NULL;
  }

}
