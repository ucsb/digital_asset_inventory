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
 * Defines the digital asset usage entity.
 *
 * Tracks where digital assets are used across the site.
 *
 * @ContentEntityType(
 *   id = "digital_asset_usage",
 *   label = @Translation("Digital Asset Usage"),
 *   label_collection = @Translation("Digital Asset Usage"),
 *   label_singular = @Translation("digital asset usage"),
 *   label_plural = @Translation("digital asset usages"),
 *   label_count = @PluralTranslation(
 *     singular = "@count digital asset usage",
 *     plural = "@count digital asset usages"
 *   ),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "digital_asset_usage",
 *   admin_permission = "administer digital assets",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid"
 *   }
 * )
 */
class DigitalAssetUsage extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Auto-increment ID - primary key.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The digital asset usage ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    // Reference to digital asset item.
    $fields['asset_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Asset'))
      ->setDescription(t('The digital asset item.'))
      ->setSetting('target_type', 'digital_asset_item')
      ->setRequired(TRUE);

    // Entity type using the asset.
    $fields['entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Entity Type'))
      ->setDescription(t('The entity type that uses this asset (e.g., node, block_content).'))
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setRequired(TRUE);

    // Entity ID using the asset.
    $fields['entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Entity ID'))
      ->setDescription(t('The ID of the entity using this asset.'))
      ->setSetting('unsigned', TRUE)
      ->setRequired(TRUE);

    // Field name where asset is referenced.
    $fields['field_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Field Name'))
      ->setDescription(t('The field name containing the asset reference.'))
      ->setSettings([
        'max_length' => 128,
        'text_processing' => 0,
      ])
      ->setDefaultValue('');

    // Usage count (for aggregation).
    $fields['count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Count'))
      ->setDescription(t('The number of times this asset is used in this context.'))
      ->setDefaultValue(1)
      ->setSetting('unsigned', TRUE);

    return $fields;
  }

}
