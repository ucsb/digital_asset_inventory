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
 * Filter for archive status based on archive records.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("digital_asset_archive_status_filter")
 */
final class DigitalAssetArchiveStatusFilter extends FilterPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    $form['value'] = [
      '#type' => 'select',
      '#title' => $this->t('Archive Status'),
      '#options' => [
        'All' => $this->t('- Any -'),
        'not_archived' => $this->t('Not Archived'),
        'queued' => $this->t('Queued'),
        'archived_any' => $this->t('Archived (any)'),
        'archived_public' => $this->t('Archived (Public)'),
        'archived_admin' => $this->t('Archived (Admin-Only)'),
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
    $base_table = $this->tableAlias;
    if (empty($base_table)) {
      $base_table = $this->view->storage->get('base_table');
    }

    // Build the subquery condition based on the selected filter value.
    // Match by original_fid when fid is not NULL, or by original_path when fid is NULL.
    // This handles both managed files (have fid) and external/filesystem assets (NULL fid).
    // Note: Manual archive entries (pages/external URLs) won't match inventory items.
    switch ($value) {
      case 'not_archived':
        // Show assets that have NO active archive record (excluding terminal states).
        $this->query->addWhereExpression(
          $this->options['group'],
          "NOT EXISTS (SELECT 1 FROM {digital_asset_archive} daa WHERE ((daa.original_fid = {$base_table}.fid AND {$base_table}.fid IS NOT NULL) OR (daa.original_path = {$base_table}.file_path AND {$base_table}.fid IS NULL)) AND daa.status IN ('queued', 'archived_public', 'archived_admin'))"
        );
        break;

      case 'queued':
        // Show assets that are queued for archive.
        $this->query->addWhereExpression(
          $this->options['group'],
          "EXISTS (SELECT 1 FROM {digital_asset_archive} daa WHERE ((daa.original_fid = {$base_table}.fid AND {$base_table}.fid IS NOT NULL) OR (daa.original_path = {$base_table}.file_path AND {$base_table}.fid IS NULL)) AND daa.status = 'queued')"
        );
        break;

      case 'archived_any':
        // Show assets that are archived (public or admin-only).
        $this->query->addWhereExpression(
          $this->options['group'],
          "EXISTS (SELECT 1 FROM {digital_asset_archive} daa WHERE ((daa.original_fid = {$base_table}.fid AND {$base_table}.fid IS NOT NULL) OR (daa.original_path = {$base_table}.file_path AND {$base_table}.fid IS NULL)) AND daa.status IN ('archived_public', 'archived_admin'))"
        );
        break;

      case 'archived_public':
        // Show assets that are archived with public visibility.
        $this->query->addWhereExpression(
          $this->options['group'],
          "EXISTS (SELECT 1 FROM {digital_asset_archive} daa WHERE ((daa.original_fid = {$base_table}.fid AND {$base_table}.fid IS NOT NULL) OR (daa.original_path = {$base_table}.file_path AND {$base_table}.fid IS NULL)) AND daa.status = 'archived_public')"
        );
        break;

      case 'archived_admin':
        // Show assets that are archived with admin-only visibility.
        $this->query->addWhereExpression(
          $this->options['group'],
          "EXISTS (SELECT 1 FROM {digital_asset_archive} daa WHERE ((daa.original_fid = {$base_table}.fid AND {$base_table}.fid IS NOT NULL) OR (daa.original_path = {$base_table}.file_path AND {$base_table}.fid IS NULL)) AND daa.status = 'archived_admin')"
        );
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    $labels = [
      'not_archived' => $this->t('Not archived'),
      'queued' => $this->t('Queued'),
      'archived_any' => $this->t('Archived (any)'),
      'archived_public' => $this->t('Archived (Public)'),
      'archived_admin' => $this->t('Archived (Admin-Only)'),
    ];
    return $labels[$this->value] ?? '';
  }

}
