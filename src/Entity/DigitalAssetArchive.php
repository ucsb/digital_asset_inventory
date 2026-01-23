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

namespace Drupal\digital_asset_inventory\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Defines the Digital Asset Archive entity.
 *
 * Tracks documents that have been marked for archive or archived
 * for ADA Title II compliance. This entity survives scanner re-runs
 * and maintains a full archive history.
 *
 * @ContentEntityType(
 *   id = "digital_asset_archive",
 *   label = @Translation("Digital Asset Archive"),
 *   label_collection = @Translation("Digital Asset Archives"),
 *   label_singular = @Translation("digital asset archive"),
 *   label_plural = @Translation("digital asset archives"),
 *   label_count = @PluralTranslation(
 *     singular = "@count digital asset archive",
 *     plural = "@count digital asset archives"
 *   ),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "digital_asset_archive",
 *   admin_permission = "archive digital assets",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "file_name",
 *     "uuid" = "uuid"
 *   }
 * )
 */
class DigitalAssetArchive extends ContentEntityBase {

  use EntityChangedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   *
   * Enforces immutability of archive_classification_date and file_checksum.
   */
  public function preSave($storage) {
    parent::preSave($storage);

    // Skip immutability check for new entities.
    if ($this->isNew()) {
      return;
    }

    // Load the original entity to compare.
    $original = $storage->loadUnchanged($this->id());
    if (!$original) {
      return;
    }

    $original_status = $original->getStatus();
    $new_status = $this->getStatus();

    // Check if this is an archive execution (queued → archived_*).
    $is_archive_execution = (
      $original_status === 'queued' &&
      in_array($new_status, ['archived_public', 'archived_admin'], TRUE)
    );

    // Enforce immutability of archive_classification_date.
    $original_date = $original->get('archive_classification_date')->value;
    $new_date = $this->get('archive_classification_date')->value;

    if ($original_date !== NULL && $original_date !== $new_date) {
      if (!$is_archive_execution) {
        throw new \LogicException(
          'Archive Classification Date is immutable. It can only be set during archive execution.'
        );
      }
    }

    // Enforce immutability of file_checksum.
    // Checksum is the reference point for detecting file modifications.
    // If tampered with, integrity verification becomes meaningless.
    $original_checksum = $original->get('file_checksum')->value;
    $new_checksum = $this->get('file_checksum')->value;

    if ($original_checksum !== NULL && $original_checksum !== $new_checksum) {
      if (!$is_archive_execution) {
        throw new \LogicException(
          'File Checksum is immutable. It can only be set during archive execution.'
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Auto-increment ID - primary key.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The archived asset ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    // Archive UUID - stable public identifier that survives entity cleanup.
    $fields['archive_uuid'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Archive UUID'))
      ->setDescription(t('Stable public identifier for this archive record (used in public URLs).'))
      ->setSettings([
        'max_length' => 36,
        'text_processing' => 0,
      ])
      ->setRequired(TRUE)
      ->setDefaultValueCallback(static::class . '::generateArchiveUuid');

    // Original file ID from file_managed table (nullable - may be deleted).
    $fields['original_fid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Original File ID'))
      ->setDescription(t('The original file ID from file_managed table.'))
      ->setSetting('unsigned', TRUE);

    // Original file path before archiving.
    $fields['original_path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Original Path'))
      ->setDescription(t('The original file path/URL before archiving.'))
      ->setSettings([
        'max_length' => 2048,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setRequired(TRUE);

    // Archive path (file URL at time of archiving - file stays in place).
    $fields['archive_path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Archive Path'))
      ->setDescription(t('The file URL at time of archiving. Files remain at their original location.'))
      ->setSettings([
        'max_length' => 2048,
        'text_processing' => 0,
      ])
      ->setDefaultValue('');

    // File name for display.
    $fields['file_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('File Name'))
      ->setDescription(t('The name of the file.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Archive reason (required - explains why file is being archived).
    // Per ADA Title II, content must be retained for Reference, Research,
    // or Recordkeeping. "Other" allows custom reason.
    $fields['archive_reason'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Archive Reason'))
      ->setDescription(t('The reason for archiving this file (required for ADA compliance).'))
      ->setSettings([
        'allowed_values' => [
          'reference' => 'Reference',
          'research' => 'Research',
          'recordkeeping' => 'Recordkeeping',
          'other' => 'Other',
        ],
      ])
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Custom archive reason (used when archive_reason is 'other').
    $fields['archive_reason_other'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Custom Archive Reason'))
      ->setDescription(t('Custom reason when "Other" is selected.'))
      ->setDefaultValue('')
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -2,
        'settings' => [
          'rows' => 3,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Public description for Archive Registry (required).
    $fields['public_description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Public Description'))
      ->setDescription(t('Description shown on the public Archive Registry. Explain why this document is archived and its relevance.'))
      ->setRequired(TRUE)
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => -1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -1,
        'settings' => [
          'rows' => 4,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Internal notes (optional, admin only).
    $fields['internal_notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Internal Notes'))
      ->setDescription(t('Internal notes visible only to administrators. Not shown on public Archive Registry.'))
      ->setDefaultValue('')
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 0,
        'settings' => [
          'rows' => 3,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // SHA256 checksum for integrity verification.
    $fields['file_checksum'] = BaseFieldDefinition::create('string')
      ->setLabel(t('File Checksum'))
      ->setDescription(t('SHA256 hash of the archived file for integrity verification.'))
      ->setSettings([
        'max_length' => 64,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Asset type (pdf, word, excel, powerpoint; page, external for manual).
    $fields['asset_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Asset Type'))
      ->setDescription(t('The type of asset. File types: pdf, word, excel, powerpoint. Manual types: page, external.'))
      ->setSettings([
        'max_length' => 50,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // MIME type.
    $fields['mime_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('MIME Type'))
      ->setDescription(t('The MIME type of the file.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // File size in bytes.
    $fields['filesize'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('File Size'))
      ->setDescription(t('The size of the file in bytes.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Private file flag - indicates files stored in private:// stream.
    $fields['is_private'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Is Private'))
      ->setDescription(t('Indicates whether the file is stored in the private file system and requires authentication to access.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Archive status (primary status - 6 values).
    // Visibility is encoded in status (archived_public vs archived_admin).
    // Condition flags (flag_usage, flag_missing, flag_integrity) are separate.
    // Note: Transitions to exemption_void preserve archive_classification_date.
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setDescription(t('The primary archive status.'))
      ->setSettings([
        'allowed_values' => [
          'queued' => 'Queued',
          'archived_public' => 'Archived (Public)',
          'archived_admin' => 'Archived (Admin-only)',
          'archived_deleted' => 'Archived (Deleted)',
          'exemption_void' => 'Exemption Void (Modified)',
        ],
      ])
      ->setDefaultValue('queued')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Condition flag: Usage detected (active content references this document).
    $fields['flag_usage'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Usage Detected'))
      ->setDescription(t('Flag indicating active content references this document.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Condition flag: File missing (underlying file no longer exists).
    $fields['flag_missing'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('File Missing'))
      ->setDescription(t('Flag indicating the underlying file no longer exists.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Condition flag: Integrity violation (file checksum mismatch).
    $fields['flag_integrity'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Integrity Violation'))
      ->setDescription(t('Flag indicating file checksum does not match stored value.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Warning flag: Archived after ADA compliance deadline.
    $fields['flag_late_archive'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Archived After Compliance Deadline'))
      ->setDescription(t('Warning flag indicating this item was archived after the ADA compliance deadline.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Warning flag: Content modified after archiving (General Archives only).
    // For Legacy Archives, modification triggers exemption_void status instead.
    // For General Archives, modification triggers archived_deleted + this flag.
    $fields['flag_modified'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Content Modified'))
      ->setDescription(t('Warning flag indicating content was modified after being archived (General Archives only).'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // User who initiated the archive.
    $fields['archived_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Archived By'))
      ->setDescription(t('The user who initiated the archive.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getCurrentUserId')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Created timestamp (when marked for archive).
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time when the asset was marked for archive.'));

    // Changed timestamp.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time when the entity was last updated.'));

    // Archive Classification Date - IMMUTABLE once set.
    // This is the compliance decision point for ADA Title II.
    // Set only during archive execution. MUST NOT be cleared or modified.
    $fields['archive_classification_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Archive Classification Date'))
      ->setDescription(t('The immutable timestamp when the asset was formally classified as archived. This is the ADA compliance decision point.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Deleted date - when file was physically deleted (for archived_deleted status).
    $fields['deleted_date'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Deleted Date'))
      ->setDescription(t('The time when the archived file was physically deleted.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // User who deleted the file.
    $fields['deleted_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Deleted By'))
      ->setDescription(t('The user who deleted the archived file.'))
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Default value callback for 'archived_by' field.
   *
   * @return array
   *   An array of default values.
   */
  public static function getCurrentUserId() {
    return [\Drupal::currentUser()->id()];
  }

  /**
   * Default value callback for 'archive_uuid' field.
   *
   * Generates a stable UUID for public URLs that survives entity changes.
   *
   * @return array
   *   An array containing the generated UUID.
   */
  public static function generateArchiveUuid() {
    return [\Drupal::service('uuid')->generate()];
  }

  /**
   * Gets the archive UUID (stable public identifier).
   *
   * @return string
   *   The archive UUID.
   */
  public function getArchiveUuid() {
    return $this->get('archive_uuid')->value;
  }

  /**
   * Gets the file name.
   *
   * @return string
   *   The file name.
   */
  public function getFileName() {
    return $this->get('file_name')->value;
  }

  /**
   * Sets the file name.
   *
   * @param string $file_name
   *   The file name.
   *
   * @return $this
   */
  public function setFileName($file_name) {
    $this->set('file_name', $file_name);
    return $this;
  }

  /**
   * Gets the original path.
   *
   * @return string
   *   The original path.
   */
  public function getOriginalPath() {
    return $this->get('original_path')->value;
  }

  /**
   * Sets the original path.
   *
   * @param string $path
   *   The original path.
   *
   * @return $this
   */
  public function setOriginalPath($path) {
    $this->set('original_path', $path);
    return $this;
  }

  /**
   * Gets the archive path.
   *
   * @return string
   *   The archive path.
   */
  public function getArchivePath() {
    return $this->get('archive_path')->value;
  }

  /**
   * Sets the archive path.
   *
   * @param string $path
   *   The archive path.
   *
   * @return $this
   */
  public function setArchivePath($path) {
    $this->set('archive_path', $path);
    return $this;
  }

  /**
   * Gets the archive reason key.
   *
   * @return string
   *   The archive reason key (reference, research, recordkeeping).
   */
  public function getArchiveReason() {
    return $this->get('archive_reason')->value;
  }

  /**
   * Gets the archive reason label.
   *
   * @return string
   *   The human-readable archive reason label, or custom reason if "Other".
   */
  public function getArchiveReasonLabel() {
    $reason = $this->getArchiveReason();

    // If "other" is selected, return the custom reason.
    if ($reason === 'other') {
      $custom_reason = $this->getArchiveReasonOther();
      return $custom_reason ?: $this->t('Other');
    }

    $labels = [
      'reference' => $this->t('Reference'),
      'research' => $this->t('Research'),
      'recordkeeping' => $this->t('Recordkeeping'),
    ];

    return $labels[$reason] ?? $reason;
  }

  /**
   * Gets the custom archive reason.
   *
   * @return string
   *   The custom archive reason when "Other" is selected.
   */
  public function getArchiveReasonOther() {
    return $this->get('archive_reason_other')->value;
  }

  /**
   * Sets the custom archive reason.
   *
   * @param string $reason
   *   The custom archive reason.
   *
   * @return $this
   */
  public function setArchiveReasonOther($reason) {
    $this->set('archive_reason_other', $reason);
    return $this;
  }

  /**
   * Sets the archive reason.
   *
   * @param string $reason
   *   The archive reason key (reference, research, recordkeeping).
   *
   * @return $this
   */
  public function setArchiveReason($reason) {
    $this->set('archive_reason', $reason);
    return $this;
  }

  /**
   * Gets the status.
   *
   * @return string
   *   The status (pending, archived, removed).
   */
  public function getStatus() {
    return $this->get('status')->value;
  }

  /**
   * Sets the status.
   *
   * @param string $status
   *   The status.
   *
   * @return $this
   */
  public function setStatus($status) {
    $this->set('status', $status);
    return $this;
  }

  /**
   * Gets the asset type.
   *
   * @return string
   *   The asset type.
   */
  public function getAssetType() {
    return $this->get('asset_type')->value;
  }

  /**
   * Sets the asset type.
   *
   * @param string $type
   *   The asset type.
   *
   * @return $this
   */
  public function setAssetType($type) {
    $this->set('asset_type', $type);
    return $this;
  }

  /**
   * Gets the MIME type.
   *
   * @return string
   *   The MIME type.
   */
  public function getMimeType() {
    return $this->get('mime_type')->value;
  }

  /**
   * Sets the MIME type.
   *
   * @param string $mime_type
   *   The MIME type.
   *
   * @return $this
   */
  public function setMimeType($mime_type) {
    $this->set('mime_type', $mime_type);
    return $this;
  }

  /**
   * Gets the file size.
   *
   * @return int
   *   The file size in bytes.
   */
  public function getFilesize() {
    return $this->get('filesize')->value;
  }

  /**
   * Sets the file size.
   *
   * @param int $filesize
   *   The file size in bytes.
   *
   * @return $this
   */
  public function setFilesize($filesize) {
    $this->set('filesize', $filesize);
    return $this;
  }

  /**
   * Gets the original file ID.
   *
   * @return int|null
   *   The original file ID or NULL if not set.
   */
  public function getOriginalFid() {
    return $this->get('original_fid')->value;
  }

  /**
   * Sets the original file ID.
   *
   * @param int $fid
   *   The original file ID.
   *
   * @return $this
   */
  public function setOriginalFid($fid) {
    $this->set('original_fid', $fid);
    return $this;
  }

  /**
   * Gets the archive classification date.
   *
   * This is the immutable compliance decision timestamp.
   *
   * @return int|null
   *   The classification timestamp or NULL if not yet archived.
   */
  public function getArchiveClassificationDate() {
    return $this->get('archive_classification_date')->value;
  }

  /**
   * Sets the archive classification date.
   *
   * WARNING: This should only be called during archive execution.
   * The field is immutable after being set - enforced in preSave().
   *
   * @param int $timestamp
   *   The classification timestamp.
   *
   * @return $this
   */
  public function setArchiveClassificationDate($timestamp) {
    $this->set('archive_classification_date', $timestamp);
    return $this;
  }

  /**
   * Gets the deleted date.
   *
   * @return int|null
   *   The deletion timestamp or NULL if not deleted.
   */
  public function getDeletedDate() {
    return $this->get('deleted_date')->value;
  }

  /**
   * Sets the deleted date.
   *
   * @param int $timestamp
   *   The deletion timestamp.
   *
   * @return $this
   */
  public function setDeletedDate($timestamp) {
    $this->set('deleted_date', $timestamp);
    return $this;
  }

  /**
   * Gets the user ID who deleted the file.
   *
   * @return int|null
   *   The user ID or NULL if not deleted.
   */
  public function getDeletedBy() {
    return $this->get('deleted_by')->target_id;
  }

  /**
   * Sets the user who deleted the file.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return $this
   */
  public function setDeletedBy($uid) {
    $this->set('deleted_by', $uid);
    return $this;
  }

  /**
   * Checks if the asset is queued for archive.
   *
   * @return bool
   *   TRUE if queued, FALSE otherwise.
   */
  public function isQueued() {
    return $this->getStatus() === 'queued';
  }

  /**
   * Checks if the asset has been successfully archived (any archived status).
   *
   * @return bool
   *   TRUE if archived (public, admin, deleted, or voided), FALSE otherwise.
   */
  public function isArchived() {
    $status = $this->getStatus();
    return in_array($status, ['archived_public', 'archived_admin', 'archived_deleted', 'exemption_void'], TRUE);
  }

  /**
   * Checks if the asset is archived and publicly visible.
   *
   * @return bool
   *   TRUE if archived_public, FALSE otherwise.
   */
  public function isArchivedPublic() {
    return $this->getStatus() === 'archived_public';
  }

  /**
   * Checks if the asset is archived but admin-only.
   *
   * @return bool
   *   TRUE if archived_admin, FALSE otherwise.
   */
  public function isArchivedAdmin() {
    return $this->getStatus() === 'archived_admin';
  }

  /**
   * Checks if the archived file has been deleted.
   *
   * @return bool
   *   TRUE if archived_deleted, FALSE otherwise.
   */
  public function isArchivedDeleted() {
    return $this->getStatus() === 'archived_deleted';
  }

  /**
   * Checks if the exemption has been voided due to file modification.
   *
   * This status is set when an archived file is modified after the
   * ADA compliance deadline, voiding its accessibility exemption.
   *
   * @return bool
   *   TRUE if exemption_void, FALSE otherwise.
   */
  public function isExemptionVoid() {
    return $this->getStatus() === 'exemption_void';
  }

  /**
   * Checks if the asset is in an active archived state (not deleted).
   *
   * @return bool
   *   TRUE if archived_public or archived_admin, FALSE otherwise.
   */
  public function isArchivedActive() {
    $status = $this->getStatus();
    return in_array($status, ['archived_public', 'archived_admin'], TRUE);
  }

  /**
   * Checks if the asset is blocked (queued with warning flags).
   *
   * A blocked item is queued for archive but has issues preventing execution.
   *
   * @return bool
   *   TRUE if queued with warning flags, FALSE otherwise.
   */
  public function isBlocked() {
    return $this->isQueued() && $this->hasWarningFlags();
  }

  /**
   * Checks if the asset has any condition flags set.
   *
   * @return bool
   *   TRUE if any flags are set, FALSE otherwise.
   */
  public function hasWarningFlags() {
    return $this->hasFlagUsage() || $this->hasFlagMissing() || $this->hasFlagIntegrity();
  }

  /**
   * Checks if usage flag is set.
   *
   * @return bool
   *   TRUE if flag_usage is set.
   */
  public function hasFlagUsage() {
    return (bool) $this->get('flag_usage')->value;
  }

  /**
   * Sets the usage flag.
   *
   * @param bool $value
   *   The flag value.
   *
   * @return $this
   */
  public function setFlagUsage($value) {
    $this->set('flag_usage', $value);
    return $this;
  }

  /**
   * Checks if file missing flag is set.
   *
   * @return bool
   *   TRUE if flag_missing is set.
   */
  public function hasFlagMissing() {
    return (bool) $this->get('flag_missing')->value;
  }

  /**
   * Sets the file missing flag.
   *
   * @param bool $value
   *   The flag value.
   *
   * @return $this
   */
  public function setFlagMissing($value) {
    $this->set('flag_missing', $value);
    return $this;
  }

  /**
   * Checks if integrity violation flag is set.
   *
   * @return bool
   *   TRUE if flag_integrity is set.
   */
  public function hasFlagIntegrity() {
    return (bool) $this->get('flag_integrity')->value;
  }

  /**
   * Sets the integrity violation flag.
   *
   * @param bool $value
   *   The flag value.
   *
   * @return $this
   */
  public function setFlagIntegrity($value) {
    $this->set('flag_integrity', $value);
    return $this;
  }

  /**
   * Checks if late archive flag is set.
   *
   * @return bool
   *   TRUE if flag_late_archive is set.
   */
  public function hasFlagLateArchive() {
    return (bool) $this->get('flag_late_archive')->value;
  }

  /**
   * Sets the late archive flag.
   *
   * @param bool $value
   *   The flag value.
   *
   * @return $this
   */
  public function setFlagLateArchive($value) {
    $this->set('flag_late_archive', $value);
    return $this;
  }

  /**
   * Checks if modified flag is set.
   *
   * This flag is used for General Archives (post-deadline) when content
   * is modified after archiving. Legacy Archives use exemption_void status.
   *
   * @return bool
   *   TRUE if flag_modified is set.
   */
  public function hasFlagModified() {
    return (bool) $this->get('flag_modified')->value;
  }

  /**
   * Sets the modified flag.
   *
   * @param bool $value
   *   The flag value.
   *
   * @return $this
   */
  public function setFlagModified($value) {
    $this->set('flag_modified', $value);
    return $this;
  }

  /**
   * Clears all condition flags.
   *
   * @return $this
   */
  public function clearFlags() {
    $this->set('flag_usage', FALSE);
    $this->set('flag_missing', FALSE);
    $this->set('flag_integrity', FALSE);
    $this->set('flag_modified', FALSE);
    return $this;
  }

  /**
   * Checks if the archive has problems (any warning flags).
   *
   * @return bool
   *   TRUE if any warning flags are set, FALSE otherwise.
   */
  public function hasArchiveProblems() {
    return $this->hasWarningFlags();
  }

  /**
   * Gets the public description.
   *
   * @return string
   *   The public description.
   */
  public function getPublicDescription() {
    return $this->get('public_description')->value;
  }

  /**
   * Sets the public description.
   *
   * @param string $description
   *   The public description.
   *
   * @return $this
   */
  public function setPublicDescription($description) {
    $this->set('public_description', $description);
    return $this;
  }

  /**
   * Gets the internal notes.
   *
   * @return string
   *   The internal notes.
   */
  public function getInternalNotes() {
    return $this->get('internal_notes')->value;
  }

  /**
   * Sets the internal notes.
   *
   * @param string $notes
   *   The internal notes.
   *
   * @return $this
   */
  public function setInternalNotes($notes) {
    $this->set('internal_notes', $notes);
    return $this;
  }

  /**
   * Gets the file checksum.
   *
   * @return string
   *   The SHA256 checksum.
   */
  public function getFileChecksum() {
    return $this->get('file_checksum')->value;
  }

  /**
   * Sets the file checksum.
   *
   * @param string $checksum
   *   The SHA256 checksum.
   *
   * @return $this
   */
  public function setFileChecksum($checksum) {
    $this->set('file_checksum', $checksum);
    return $this;
  }

  /**
   * Gets the status label.
   *
   * @return string
   *   The human-readable status label.
   */
  public function getStatusLabel() {
    $status = $this->getStatus();

    $labels = [
      'queued' => $this->t('Queued'),
      'archived_public' => $this->t('Archived (Public)'),
      'archived_admin' => $this->t('Archived (Admin-only)'),
      'archived_deleted' => $this->t('Archived (Deleted)'),
      'exemption_void' => $this->t('Exemption Void'),
    ];
    return $labels[$status] ?? $status;
  }

  /**
   * Gets a detailed status label including warning flags.
   *
   * @return string
   *   The status label with flag indicators.
   */
  public function getDetailedStatusLabel() {
    $label = $this->getStatusLabel();
    $warnings = [];

    if ($this->hasFlagUsage()) {
      $warnings[] = $this->t('Usage Detected');
    }
    if ($this->hasFlagMissing()) {
      $warnings[] = $this->t('File Missing');
    }
    if ($this->hasFlagIntegrity()) {
      $warnings[] = $this->t('Integrity Violation');
    }

    if (!empty($warnings)) {
      $label .= ' (' . implode(', ', $warnings) . ')';
    }

    return $label;
  }

  /**
   * Gets warning flag labels.
   *
   * @return array
   *   Array of warning label strings.
   */
  public function getWarningLabels() {
    $warnings = [];

    if ($this->hasFlagUsage()) {
      $warnings[] = $this->t('Usage Detected');
    }
    if ($this->hasFlagMissing()) {
      $warnings[] = $this->t('File Missing');
    }
    if ($this->hasFlagIntegrity()) {
      $warnings[] = $this->t('Integrity Violation');
    }
    if ($this->hasFlagModified()) {
      $warnings[] = $this->t('Modified');
    }

    return $warnings;
  }

  /**
   * Checks if the archive can be executed.
   *
   * Only queued items can be executed.
   *
   * @return bool
   *   TRUE if execution is possible, FALSE otherwise.
   */
  public function canExecuteArchive() {
    return $this->isQueued();
  }

  /**
   * Checks if the item can be unarchived.
   *
   * Active archived items (public/admin) and voided exemptions can be unarchived.
   * Deleted archives cannot be unarchived.
   *
   * @return bool
   *   TRUE if unarchive is possible, FALSE otherwise.
   */
  public function canUnarchive() {
    return $this->isArchivedActive() || $this->isExemptionVoid();
  }

  /**
   * Checks if the item can be removed from queue.
   *
   * Only queued items can be removed from queue.
   *
   * @return bool
   *   TRUE if removal from queue is possible, FALSE otherwise.
   */
  public function canRemoveFromQueue() {
    return $this->isQueued();
  }

  /**
   * Checks if the archived file can be deleted.
   *
   * Active archived items (public/admin) and voided exemptions can have files deleted.
   * Must be a file-based archive (not manual entry).
   *
   * @return bool
   *   TRUE if file deletion is possible, FALSE otherwise.
   */
  public function canDeleteFile() {
    return ($this->isArchivedActive() || $this->isExemptionVoid()) && $this->isFileArchive();
  }

  /**
   * Checks if visibility can be toggled.
   *
   * Only active archived items can toggle between public and admin-only.
   *
   * @return bool
   *   TRUE if visibility toggle is possible, FALSE otherwise.
   */
  public function canToggleVisibility() {
    return $this->isArchivedActive();
  }

  /**
   * Checks if this archive record is visible on the public Archive page.
   *
   * Only archived_public items appear publicly.
   *
   * @return bool
   *   TRUE if publicly visible, FALSE otherwise.
   */
  public function isPubliclyVisible() {
    return $this->isArchivedPublic();
  }

  /**
   * Checks if this is a manual archive entry (not from scanner).
   *
   * Manual entries use asset_type 'page' or 'external' and are created
   * via ManualArchiveForm. File-based archives have asset types like
   * 'pdf', 'word', 'excel', 'powerpoint', etc.
   *
   * Note: We check asset_type rather than original_fid because orphan files
   * (filesystem_only) don't have a fid but are still file-based archives.
   *
   * @return bool
   *   TRUE if this is a manual entry, FALSE if file-based.
   */
  public function isManualEntry() {
    $asset_type = $this->getAssetType();
    return in_array($asset_type, ['page', 'external'], TRUE);
  }

  /**
   * Checks if this is a file-based archive (from file scanner).
   *
   * @return bool
   *   TRUE if file-based, FALSE if manual entry.
   */
  public function isFileArchive() {
    return !$this->isManualEntry();
  }

  /**
   * Checks if this archive entry can be edited.
   *
   * Only manual entries can be edited after creation.
   * File-based archives are locked for integrity.
   *
   * @return bool
   *   TRUE if editable, FALSE otherwise.
   */
  public function canEdit() {
    return $this->isManualEntry();
  }

  /**
   * Gets the asset type label for display.
   *
   * @return string
   *   Human-readable asset type label.
   */
  public function getAssetTypeLabel() {
    $labels = [
      'pdf' => $this->t('PDF'),
      'word' => $this->t('Word Document'),
      'excel' => $this->t('Excel Spreadsheet'),
      'powerpoint' => $this->t('PowerPoint'),
      'page' => $this->t('Web Page'),
      'external' => $this->t('External Resource'),
    ];
    $type = $this->getAssetType();
    return $labels[$type] ?? $type;
  }

  /**
   * Checks if the file is private (requires authentication).
   *
   * @return bool
   *   TRUE if the file is in the private file system.
   */
  public function isPrivate() {
    return (bool) $this->get('is_private')->value;
  }

  /**
   * Sets the private flag.
   *
   * @param bool $is_private
   *   TRUE if the file is private.
   *
   * @return $this
   */
  public function setIsPrivate($is_private) {
    $this->set('is_private', $is_private);
    return $this;
  }

}
