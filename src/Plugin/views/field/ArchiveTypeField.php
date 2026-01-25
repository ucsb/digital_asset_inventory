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

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * A handler to display archive type as a badge (Legacy or General).
 *
 * Legacy archives were created before the ADA compliance deadline (April 24, 2026)
 * and are eligible for ADA Title II accessibility exemptions.
 *
 * General archives were created after the deadline and are retained for
 * general reference/recordkeeping purposes without claiming ADA exemption.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_archive_type")
 */
final class ArchiveTypeField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // This is a computed field - do not add to the query.
    // The value is computed from the entity's flag_late_archive field.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Get the entity from the row.
    $entity = $values->_entity;
    if (!$entity) {
      return '';
    }

    // Reload the entity to get fresh data (avoid stale cache).
    /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null $entity */
    $entity = \Drupal::entityTypeManager()
      ->getStorage('digital_asset_archive')
      ->load($entity->id());

    if (!$entity) {
      return '';
    }

    // Determine archive type based on flag_late_archive.
    // flag_late_archive = FALSE means archived before deadline = Legacy Archive
    // flag_late_archive = TRUE means archived after deadline = General Archive
    $is_legacy = !$entity->hasFlagLateArchive();

    if ($is_legacy) {
      $badge_class = 'dai-archive-type-badge dai-archive-type-badge--legacy';
      $label = $this->t('Legacy Archive');
      $title = $this->t('Archived before the ADA compliance deadline');
    }
    else {
      $badge_class = 'dai-archive-type-badge dai-archive-type-badge--general';
      $label = $this->t('General Archive');
      $title = $this->t('Archived after the ADA compliance deadline');
    }

    $markup = '<span class="' . $badge_class . '" title="' . $title . '">' . $label . '</span>';

    // Return render array with entity-specific cache tags for proper invalidation.
    return [
      '#markup' => $markup,
      '#cache' => [
        'tags' => $entity->getCacheTags(),
      ],
    ];
  }

}
