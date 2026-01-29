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
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the digital asset item entity.
 *
 * @ContentEntityType(
 *   id = "digital_asset_item",
 *   label = @Translation("Digital Asset Item"),
 *   label_collection = @Translation("Digital Asset Items"),
 *   label_singular = @Translation("digital asset item"),
 *   label_plural = @Translation("digital asset items"),
 *   label_count = @PluralTranslation(
 *     singular = "@count digital asset item",
 *     plural = "@count digital asset items"
 *   ),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "digital_asset_item",
 *   admin_permission = "administer digital assets",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "file_name",
 *     "uuid" = "uuid"
 *   }
 * )
 */
class DigitalAssetItem extends ContentEntityBase {

  use EntityChangedTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Auto-increment ID - primary key.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The digital asset item ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    // File ID (from file_managed) - nullable for external/filesystem assets.
    $fields['fid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('File ID'))
      ->setDescription(t('The file ID from the file_managed table (for managed files only).'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    // Media ID (from media table) - nullable for non-media assets.
    $fields['media_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Media ID'))
      ->setDescription(t('The media entity ID (for media-managed files only).'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    // Source type field.
    $fields['source_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Source Type'))
      ->setDescription(t('The source type of the asset.'))
      ->setSettings([
        'allowed_values' => [
          'file_managed' => 'Local File',
          'media_managed' => 'Media File',
          'filesystem_only' => 'Manual Upload',
          'external' => 'External',
        ],
      ])
      ->setDefaultValue('file_managed')
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // URL hash for unique identification of filesystem/external assets.
    $fields['url_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('URL Hash'))
      ->setDescription(t('Unique hash identifier for filesystem or external assets.'))
      ->setSettings([
        'max_length' => 64,
        'text_processing' => 0,
      ])
      ->setDefaultValue('');

    // File name field.
    $fields['file_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('File Name'))
      ->setDescription(t('The name of the file.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    // File path field.
    $fields['file_path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('File Path'))
      ->setDescription(t('The path to the file.'))
      ->setSettings([
        'max_length' => 2048,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -3,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setRequired(TRUE);

    // Asset type field.
    $fields['asset_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Asset Type'))
      ->setDescription(t('The type of asset (e.g., pdf, word, image, video).'))
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
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Category field.
    $fields['category'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Category'))
      ->setDescription(t('The category of the asset (Documents, Media, Unknown).'))
      ->setSettings([
        'max_length' => 50,
        'text_processing' => 0,
      ])
      ->setDefaultValue('Unknown')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -2,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Sort order field for category ordering.
    $fields['sort_order'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Sort Order'))
      ->setDescription(t('Sort order for category grouping (Documents=1, Videos=2, Audio=3, Images=4, Other=5).'))
      ->setDefaultValue(5);

    // MIME type field.
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
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // File size field (NULL for remote media without local files).
    $fields['filesize'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('File Size'))
      ->setDescription(t('The size of the file in bytes. NULL for remote media.'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => -1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Formatted file size field for CSV export (e.g., "2.5 MB").
    $fields['filesize_formatted'] = BaseFieldDefinition::create('string')
      ->setLabel(t('File Size (Formatted)'))
      ->setDescription(t('Human-readable file size for CSV export.'))
      ->setSettings([
        'max_length' => 50,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Used In field for CSV export - stores "Page Name (URL)" format.
    $fields['used_in_csv'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Used In (CSV)'))
      ->setDescription(t('List of pages where asset is used, formatted for CSV export.'))
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // File location field.
    $fields['location'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Location'))
      ->setDescription(t('The location or section where the file is stored.'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDefaultValue('')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // Temporary flag for batch scanning.
    $fields['is_temp'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Is Temporary'))
      ->setDescription(t('Marks items as temporary during batch scanning.'))
      ->setDefaultValue(FALSE);

    // Private file flag - indicates files stored in private:// stream.
    $fields['is_private'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Is Private'))
      ->setDescription(t('Indicates whether the file is stored in the private file system and requires authentication to access.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // 'created' field.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    // 'changed' field.
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilename() {
    return $this->get('file_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setFilename($filename) {
    $this->set('file_name', $filename);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilepath() {
    return $this->get('file_path')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setFilepath($filepath) {
    $this->set('file_path', $filepath);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAssetType() {
    return $this->get('asset_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setAssetType($asset_type) {
    $this->set('asset_type', $asset_type);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCategory() {
    return $this->get('category')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCategory($category) {
    $this->set('category', $category);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMimeType() {
    return $this->get('mime_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMimeType($mime_type) {
    $this->set('mime_type', $mime_type);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilesize() {
    return $this->get('filesize')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setFilesize($filesize) {
    $this->set('filesize', $filesize);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getLocation() {
    return $this->get('location')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setLocation($location) {
    $this->set('location', $location);
    return $this;
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
