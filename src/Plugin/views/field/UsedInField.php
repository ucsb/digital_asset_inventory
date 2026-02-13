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

use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * A handler to provide the "Active Usage" field that shows usage and orphan reference counts.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_used_in")
 */
final class UsedInField extends FieldPluginBase {

  /**
   * Static cache for orphan counts per request.
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
    // Collect all asset IDs in the current result set.
    $asset_ids = [];
    foreach ($values as $row) {
      if ($row->_entity) {
        $asset_ids[] = $row->_entity->id();
      }
    }

    if (!empty($asset_ids)) {
      // Single grouped query for all assets in the page — no N+1.
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

    // Check for orphan references (from batch prefetch).
    self::$orphanCounts ??= [];
    $orphan_count = (int) (self::$orphanCounts[$asset_id] ?? 0);

    $orphan_tooltip = $this->t('References originating from unreachable content.');
    $usage_tooltip = $this->t('This asset is currently in use on the site. Assets in use cannot be deleted.');

    if ($usage_count > 0 && $orphan_count > 0) {
      // Case B: Both active usage and orphan references.
      $usage_url = Url::fromRoute('view.digital_asset_usage.page_1', ['arg_0' => $asset_id]);
      $orphan_url = Url::fromRoute('view.dai_orphan_references.page_1', ['arg_0' => $asset_id]);
      return [
        '#markup' => '<div class="dai-active-usage-line"><a href="' . $usage_url->toString() . '" title="' . $usage_tooltip . '">' .
          \Drupal::translation()->formatPlural($usage_count, '1 use', '@count uses') .
          '</a></div>' .
          '<div class="dai-orphan-line"><a href="' . $orphan_url->toString() . '" title="' . $orphan_tooltip . '">' .
          \Drupal::translation()->formatPlural($orphan_count, '1 orphan reference', '@count orphan references') .
          '</a></div>',
      ];
    }

    if ($usage_count > 0) {
      // Case A: Active usage only.
      $usage_url = Url::fromRoute('view.digital_asset_usage.page_1', ['arg_0' => $asset_id]);
      return [
        '#markup' => '<a href="' . $usage_url->toString() . '" title="' . $usage_tooltip . '">' .
          \Drupal::translation()->formatPlural($usage_count, '1 use', '@count uses') .
          '</a>',
      ];
    }

    if ($orphan_count > 0) {
      // Case C: Orphan references only (no active usage).
      $orphan_url = Url::fromRoute('view.dai_orphan_references.page_1', ['arg_0' => $asset_id]);
      return [
        '#markup' => '<div class="dai-active-usage-line"><span class="dai-usage-none">' . $this->t('No active usage') . '</span></div>' .
          '<div class="dai-orphan-line"><a href="' . $orphan_url->toString() . '" title="' . $orphan_tooltip . '">' .
          \Drupal::translation()->formatPlural($orphan_count, '1 orphan reference', '@count orphan references') .
          '</a></div>',
      ];
    }

    // Case D: No usage at all.
    return [
      '#markup' => '<span class="dai-usage-none">' . $this->t('No active usage') . '</span>',
    ];
  }

}
