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
final class ArchiveWarningsField extends FieldPluginBase {

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
    /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null $entity */
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

      // Modified warning for Legacy Archives with exemption_void status.
      // This explains why the exemption was voided (similar to Integrity Issue for files).
      if ($entity->isExemptionVoid()) {
        $warnings[] = '<span class="dai-warning-badge dai-warning-badge--modified" title="' . $this->t('Content was modified after being archived, voiding the ADA exemption') . '">' . $this->t('Modified') . '</span>';
      }

      // Modified flag (General Archives only - indicates content was modified after archiving).
      if ($entity->hasFlagModified()) {
        $warnings[] = '<span class="dai-warning-badge dai-warning-badge--modified" title="' . $this->t('Content was modified after being archived') . '">' . $this->t('Modified') . '</span>';
      }

      // Prior void warning - forced to General Archive due to prior voided exemption.
      // Show this instead of Late Archive when the reason is a prior void.
      if ($entity->hasFlagPriorVoid()) {
        $warnings[] = '<span class="dai-warning-badge dai-warning-badge--prior-void" title="' . $this->t('Forced to General Archive due to prior voided exemption') . '">' . $this->t('Prior Void') . '</span>';
      }
      elseif ($entity->hasFlagLateArchive()) {
        // Late archive warning - only show if not already showing Prior Void.
        $warnings[] = '<span class="dai-warning-badge dai-warning-badge--late" title="' . $this->t('Archive classification occurred after the ADA compliance deadline') . '">' . $this->t('Late Archive') . '</span>';
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
    $archive_service = \Drupal::service('digital_asset_inventory.archive');

    // Check if this is a policy-blocked queued item (EC2/EC3: in use + config disabled).
    if ($entity->isQueued()) {
      $usage_count = $archive_service->getUsageCountByArchive($entity);
      if ($usage_count > 0 && !$archive_service->isArchiveInUseAllowed()) {
        $warnings[] = '<span class="dai-warning-badge dai-warning-badge--blocked" title="' . $this->t('Execution blocked: asset is in use and current settings do not allow archiving in-use assets') . '">' . $this->t('Blocked') . '</span>';
      }
    }

    // Modified flag (General Archives only - indicates content was modified after archiving).
    if ($entity->hasFlagModified()) {
      $warnings[] = '<span class="dai-warning-badge dai-warning-badge--modified" title="' . $this->t('Content was modified after being archived') . '">' . $this->t('Modified') . '</span>';
    }

    // Show usage warning from flag OR by checking actual usage for archived_deleted items.
    if ($entity->hasFlagUsage()) {
      $warnings[] = '<span class="dai-warning-badge dai-warning-badge--usage" title="' . $this->t('Active content references this document') . '">' . $this->t('Usage Detected') . '</span>';
    }
    elseif ($entity->isArchivedDeleted()) {
      // For archived_deleted items, check actual usage count.
      $archive_service = \Drupal::service('digital_asset_inventory.archive');
      $usage_count = $archive_service->getUsageCountByArchive($entity);
      if ($usage_count > 0) {
        $warnings[] = '<span class="dai-warning-badge dai-warning-badge--usage" title="' . $this->t('Active content references this document') . '">' . $this->t('Usage Detected') . '</span>';
      }
    }

    // Show file missing warning only if file was intentionally deleted via Delete File action.
    // Don't show for unarchived items or integrity violations where file still exists.
    $file_was_deleted = !empty($entity->getDeletedDate());
    if ($file_was_deleted) {
      $warnings[] = '<span class="dai-warning-badge dai-warning-badge--missing" title="' . $this->t('File was deleted') . '">' . $this->t('File Deleted') . '</span>';
    }

    if ($entity->hasFlagIntegrity()) {
      $warnings[] = '<span class="dai-warning-badge dai-warning-badge--integrity" title="' . $this->t('File checksum does not match stored value') . '">' . $this->t('Integrity Issue') . '</span>';
    }

    // Prior void warning - forced to General Archive due to prior voided exemption.
    // Show this instead of Late Archive when the reason is a prior void.
    if ($entity->hasFlagPriorVoid()) {
      $warnings[] = '<span class="dai-warning-badge dai-warning-badge--prior-void" title="' . $this->t('Forced to General Archive due to prior voided exemption') . '">' . $this->t('Prior Void') . '</span>';
    }
    elseif ($entity->hasFlagLateArchive()) {
      // Late archive warning - only show if not already showing Prior Void.
      $warnings[] = '<span class="dai-warning-badge dai-warning-badge--late" title="' . $this->t('Archive classification occurred after the ADA compliance deadline') . '">' . $this->t('Late Archive') . '</span>';
    }

    $markup = empty($warnings)
      ? '<span class="dai-no-warnings">-</span>'
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
