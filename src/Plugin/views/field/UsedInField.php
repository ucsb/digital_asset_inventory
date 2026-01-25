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
 * A handler to provide the "Used In" field that shows usage count.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_used_in")
 */
final class UsedInField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do not add to the query - this is a computed field.
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

    $asset_id = $entity->id();

    // Add memory-safe check for CSV exports or when memory might be limited.
    if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/csv') !== FALSE) {
      // For CSV exports, simplify the output to avoid memory issues.
      return $this->t('Usage data available');
    }

    $database = \Drupal::database();

    // Count usage records for this asset.
    $usage_count = $database->select('digital_asset_usage', 'dau')
      ->condition('asset_id', $asset_id)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($usage_count > 0) {
      return [
        '#markup' => '<a href="/admin/digital-asset-inventory/usage/' . $asset_id . '">' .
        \Drupal::translation()->formatPlural($usage_count, '1 use', '@count uses') .
        '</a>',
      ];
    }
    else {
      return [
        '#markup' => '<span class="dai-usage-none">' . $this->t('Not used') . '</span>',
      ];
    }
  }

}
