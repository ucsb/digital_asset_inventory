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
 * Computed field that shows orphan reference count for CSV export.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_orphan_reference_count")
 */
final class OrphanReferenceCountField extends FieldPluginBase {

  /**
   * Batch prefetched orphan counts.
   *
   * @var array
   */
  private static array $orphanCounts = [];

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do not add to the query - this is a computed field.
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values) {
    $asset_ids = [];
    foreach ($values as $row) {
      if ($row->_entity) {
        $asset_ids[] = $row->_entity->id();
      }
    }

    if (!empty($asset_ids)) {
      $database = \Drupal::database();
      $query = $database->select('dai_orphan_reference', 'dor');
      $query->fields('dor', ['asset_id']);
      $query->condition('asset_id', $asset_ids, 'IN');
      $query->groupBy('asset_id');
      $query->addExpression('COUNT(*)', 'orphan_count');
      self::$orphanCounts = $query->execute()->fetchAllKeyed();
    }
    else {
      self::$orphanCounts = [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity;
    if (!$entity) {
      return '0';
    }

    self::$orphanCounts ??= [];
    return (string) (int) (self::$orphanCounts[$entity->id()] ?? 0);
  }

}
