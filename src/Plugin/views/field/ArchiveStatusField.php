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
 * A handler to display archive status with accessible tooltips.
 *
 * Renders status badges with title attributes for screen reader accessibility.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_archive_status")
 */
final class ArchiveStatusField extends FieldPluginBase {

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

    $status = $entity->getStatus();

    // Define status labels and descriptions for accessibility.
    // Title attributes provide tooltips for screen readers.
    $status_config = [
      'queued' => [
        'label' => $this->t('Queued'),
        'description' => $this->t('Document is queued for archive, awaiting execution'),
        'class' => 'dai-status-label--queued',
      ],
      'archived_public' => [
        'label' => $this->t('Archived (Public)'),
        'description' => $this->t('Document is archived and visible in the public Archive Registry'),
        'class' => 'dai-status-label--archived_public',
      ],
      'archived_admin' => [
        'label' => $this->t('Archived (Admin-Only)'),
        'description' => $this->t('Document is archived but only visible to administrators'),
        'class' => 'dai-status-label--archived_admin',
      ],
      'archived_deleted' => [
        'label' => $this->t('Archived (Deleted)'),
        'description' => $this->t('File was deleted or document was unarchived; record preserved for audit trail'),
        'class' => 'dai-status-label--archived_deleted',
      ],
      'exemption_void' => [
        'label' => $this->t('Exemption Void'),
        'description' => $this->t('Legacy Archive was modified after archiving; ADA exemption automatically voided'),
        'class' => 'dai-status-label--exemption_void',
      ],
    ];

    $config = $status_config[$status] ?? [
      'label' => $status,
      'description' => $status,
      'class' => '',
    ];

    // Cast TranslatableMarkup to string for proper HTML attribute rendering.
    $label = (string) $config['label'];
    $description = (string) $config['description'];
    $class = $config['class'];

    $markup = '<span class="dai-status-label ' . $class . '" title="' . htmlspecialchars($description, ENT_QUOTES) . '">' . $label . '</span>';

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
