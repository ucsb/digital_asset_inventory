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

namespace Drupal\digital_asset_inventory\Plugin\views\field;

use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * A handler to provide proper display of archive reason with full labels.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_archive_reason")
 */
class ArchiveReasonField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Get the entity - either from cache or load it.
    $entity = $this->loadArchiveEntity($values);

    // If we have the entity, get the reason label.
    if ($entity instanceof DigitalAssetArchive) {
      $reason = $entity->getArchiveReason();

      // If "other" is selected, return the custom reason.
      if ($reason === 'other') {
        $custom_reason = $entity->getArchiveReasonOther();
        if ($custom_reason) {
          return $custom_reason;
        }
        return $this->t('Other');
      }

      // Return full descriptive labels for standard reasons.
      $labels = [
        'reference' => $this->t('Reference - Content retained for informational purposes'),
        'research' => $this->t('Research - Material retained for research or study'),
        'recordkeeping' => $this->t('Recordkeeping - Content retained for compliance or official records'),
      ];

      return $labels[$reason] ?? $reason;
    }

    // Fallback to raw value if entity not available.
    $value = $this->getValue($values);

    $labels = [
      'reference' => $this->t('Reference - Content retained for informational purposes'),
      'research' => $this->t('Research - Material retained for research or study'),
      'recordkeeping' => $this->t('Recordkeeping - Content retained for compliance or official records'),
    ];

    return $labels[$value] ?? $value;
  }

  /**
   * Loads the archive entity from the result row.
   *
   * @param \Drupal\views\ResultRow $values
   *   The result row.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The entity or NULL.
   */
  protected function loadArchiveEntity(ResultRow $values) {
    // Check if entity is already loaded.
    if (isset($values->_entity) && $values->_entity instanceof DigitalAssetArchive) {
      return $values->_entity;
    }

    // Try different ways Views might store the ID.
    $id = NULL;
    if (isset($values->id)) {
      $id = $values->id;
    }
    elseif (isset($values->digital_asset_archive_id)) {
      $id = $values->digital_asset_archive_id;
    }

    if ($id) {
      $entity = \Drupal::entityTypeManager()
        ->getStorage('digital_asset_archive')
        ->load($id);
      if ($entity instanceof DigitalAssetArchive) {
        // Cache it for other fields.
        $values->_entity = $entity;
        return $entity;
      }
    }

    return NULL;
  }

}