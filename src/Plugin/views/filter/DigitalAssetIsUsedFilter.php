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

namespace Drupal\digital_asset_inventory\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Filter for "In Use" status based on usage records.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("digital_asset_is_used_filter")
 */
final class DigitalAssetIsUsedFilter extends FilterPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    $form['value'] = [
      '#type' => 'select',
      '#title' => $this->t('In Use'),
      '#options' => [
        'All' => $this->t('- Any -'),
        '1' => $this->t('Yes'),
        '0' => $this->t('No'),
      ],
      '#default_value' => $this->value ?? 'All',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['value']['default'] = 'All';
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    $value = $input[$this->options['expose']['identifier']] ?? 'All';
    if ($value === 'All' || $value === '') {
      return FALSE;
    }
    return parent::acceptExposedInput($input);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Extract value - handle both direct value and array format.
    $value = $this->value;
    if (is_array($value)) {
      $value = reset($value);
    }

    // Don't filter if no value selected.
    if ($value === '' || $value === NULL || $value === 'All') {
      return;
    }

    $this->ensureMyTable();

    // Get the base table alias reliably.
    // First try tableAlias from ensureMyTable(), then fall back to view's base table.
    $base_table = $this->tableAlias;
    if (empty($base_table)) {
      $base_table = $this->view->storage->get('base_table');
    }

    if ($value === '1' || $value === 1) {
      // Show assets that ARE in use (have usage records).
      $this->query->addWhereExpression($this->options['group'], "EXISTS (SELECT 1 FROM {digital_asset_usage} dau WHERE dau.asset_id = {$base_table}.id)");
    }
    elseif ($value === '0' || $value === 0) {
      // Show assets that are NOT in use (no usage records).
      $this->query->addWhereExpression($this->options['group'], "NOT EXISTS (SELECT 1 FROM {digital_asset_usage} dau WHERE dau.asset_id = {$base_table}.id)");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    if ($this->value === '1' || $this->value === 1) {
      return $this->t('In use');
    }
    elseif ($this->value === '0' || $this->value === 0) {
      return $this->t('Not in use');
    }
    return '';
  }

}
