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
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Digital Asset Orphan Reference entity.
 *
 * Tracks references to digital assets from orphan entities (e.g., paragraphs
 * no longer attached to any reachable host content). These references do not
 * count as active usage and are stored separately for visibility and audit.
 *
 * @ContentEntityType(
 *   id = "dai_orphan_reference",
 *   label = @Translation("Digital Asset Orphan Reference"),
 *   label_collection = @Translation("Digital Asset Orphan References"),
 *   label_singular = @Translation("digital asset orphan reference"),
 *   label_plural = @Translation("digital asset orphan references"),
 *   label_count = @PluralTranslation(
 *     singular = "@count digital asset orphan reference",
 *     plural = "@count digital asset orphan references"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "dai_orphan_reference",
 *   admin_permission = "administer digital assets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid"
 *   }
 * )
 */
class DigitalAssetOrphanReference extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Auto-increment ID - primary key.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The orphan reference ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    // Reference to digital asset item.
    $fields['asset_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Asset'))
      ->setDescription(t('The digital asset item.'))
      ->setSetting('target_type', 'digital_asset_item')
      ->setRequired(TRUE);

    // Source entity type (e.g., 'paragraph').
    $fields['source_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source Entity Type'))
      ->setDescription(t('The entity type of the orphan source (e.g., paragraph).'))
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setRequired(TRUE);

    // Source entity bundle (e.g., 'text', 'accordion_item').
    $fields['source_bundle'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source Bundle'))
      ->setDescription(t('The bundle of the orphan source entity (e.g., paragraph type).'))
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setDefaultValue('');

    // Source entity ID.
    $fields['source_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Source Entity ID'))
      ->setDescription(t('The ID of the orphan source entity.'))
      ->setSetting('unsigned', TRUE)
      ->setRequired(TRUE);

    // Source revision ID (for future soft/hard distinction).
    $fields['source_revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Source Revision ID'))
      ->setDescription(t('The revision ID of the orphan source entity.'))
      ->setSetting('unsigned', TRUE);

    // Field name containing the reference.
    $fields['field_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Field Name'))
      ->setDescription(t('The field containing the asset reference.'))
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setDefaultValue('');

    // Embed method (mirrors usage embed_method).
    $fields['embed_method'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Embed Method'))
      ->setDescription(t('How the asset is referenced (field_reference, text_link, etc.).'))
      ->setSettings([
        'max_length' => 32,
        'text_processing' => 0,
      ])
      ->setDefaultValue('field_reference');

    // Reference context (why this is an orphan).
    $fields['reference_context'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Reference Context'))
      ->setDescription(t('Why this reference is orphaned (missing_parent_entity, detached_component, etc.).'))
      ->setSettings([
        'max_length' => 32,
        'text_processing' => 0,
      ])
      ->setRequired(TRUE);

    // Timestamp when detected.
    $fields['detected_on'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Detected On'))
      ->setDescription(t('The time the orphan reference was detected.'));

    return $fields;
  }

}
