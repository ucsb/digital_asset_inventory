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
 * A handler to display archive warnings, but only for file-based entries.
 *
 * Manual entries (URLs) don't show file-based warnings like "File Missing"
 * since they don't have actual files to monitor.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_archive_warnings")
 */
class ArchiveWarningsField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // This is a computed field - do not add to the query.
    // The value is computed from the entity in render().
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Get the entity from the row.
    $entity = $values->_entity;
    if (!$entity) {
      return '-';
    }

    // Reload the entity to get fresh data (avoid stale cache).
    $entity = \Drupal::entityTypeManager()
      ->getStorage('digital_asset_archive')
      ->load($entity->id());

    if (!$entity) {
      return '-';
    }

    // For manual entries (URLs), only show non-file-based warnings.
    // Manual entries don't have files to monitor, but can still be late archives or modified.
    if ($entity->isManualEntry()) {
      $warnings = [];

      // Modified flag (General Archives only - indicates content was modified after archiving).
      if ($entity->hasFlagModified()) {
        $warnings[] = '<span class="warning-badge warning-badge--modified" title="' . $this->t('Content was modified after being archived') . '">' . $this->t('Modified') . '</span>';
      }

      // Late archive warning applies to manual entries too.
      if ($entity->hasFlagLateArchive()) {
        $warnings[] = '<span class="warning-badge warning-badge--late" title="' . $this->t('Archive classification occurred after the ADA compliance deadline') . '">' . $this->t('Late Archive') . '</span>';
      }

      $markup = empty($warnings) ? '-' : implode(' ', $warnings);

      return [
        '#markup' => $markup,
        '#cache' => [
          'tags' => $entity->getCacheTags(),
          'max-age' => 0,
        ],
      ];
    }

    // For file-based entries, show warning badges if any flags are set.
    $warnings = [];

    // Exemption Void status (Legacy Archives only - General Archives use archived_deleted + flag_modified).
    if ($entity->isExemptionVoid()) {
      $warnings[] = '<span class="warning-badge warning-badge--void" title="' . $this->t('ADA exemption voided: file was modified after the compliance deadline') . '">' . $this->t('Exemption Voided') . '</span>';
    }

    // Modified flag (General Archives only - indicates content was modified after archiving).
    if ($entity->hasFlagModified()) {
      $warnings[] = '<span class="warning-badge warning-badge--modified" title="' . $this->t('Content was modified after being archived') . '">' . $this->t('Modified') . '</span>';
    }

    if ($entity->hasFlagUsage()) {
      $warnings[] = '<span class="warning-badge warning-badge--usage" title="' . $this->t('Active content references this document') . '">' . $this->t('Usage Detected') . '</span>';
    }

    // Show file missing warning only if file was intentionally deleted via Delete File action.
    // Don't show for unarchived items or integrity violations where file still exists.
    $file_was_deleted = !empty($entity->getDeletedDate());
    if ($file_was_deleted) {
      $warnings[] = '<span class="warning-badge warning-badge--missing" title="' . $this->t('File was deleted') . '">' . $this->t('File Deleted') . '</span>';
    }

    if ($entity->hasFlagIntegrity()) {
      $warnings[] = '<span class="warning-badge warning-badge--integrity" title="' . $this->t('File checksum does not match stored value') . '">' . $this->t('Integrity Issue') . '</span>';
    }

    // Show late archive warning if archived after ADA compliance deadline.
    if ($entity->hasFlagLateArchive()) {
      $warnings[] = '<span class="warning-badge warning-badge--late" title="' . $this->t('Archive classification occurred after the ADA compliance deadline') . '">' . $this->t('Late Archive') . '</span>';
    }

    $markup = empty($warnings)
      ? '<span class="no-warnings">-</span>'
      : implode(' ', $warnings);

    // Return render array with entity-specific cache tags for proper invalidation.
    return [
      '#markup' => $markup,
      '#cache' => [
        'tags' => $entity->getCacheTags(),
        'max-age' => 0,
      ],
    ];
  }

}
