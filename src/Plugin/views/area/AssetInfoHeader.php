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

namespace Drupal\digital_asset_inventory\Plugin\views\area;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views area handler to display asset information in the header.
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("digital_asset_info_header")
 */
class AssetInfoHeader extends AreaPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an AssetInfoHeader object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    // Get the asset_id from the view's argument.
    $asset_id = NULL;
    if (!empty($this->view->args[0])) {
      $asset_id = $this->view->args[0];
    }

    if (!$asset_id) {
      return [];
    }

    try {
      $asset = $this->entityTypeManager
        ->getStorage('digital_asset_item')
        ->load($asset_id);

      if (!$asset) {
        return [];
      }

      $file_name = $asset->get('file_name')->value;
      $file_path = $asset->get('file_path')->value;
      $asset_type = $asset->get('asset_type')->value;
      $category = $asset->get('category')->value;
      $filesize = $asset->get('filesize')->value;
      $source_type = $asset->get('source_type')->value;

      // Get human-readable source type label.
      $source_labels = [
        'file_managed' => $this->t('Local File'),
        'media_managed' => $this->t('Media File'),
        'filesystem_only' => $this->t('Manual Upload'),
        'external' => $this->t('External'),
      ];
      $source_label = $source_labels[$source_type] ?? $source_type;

      // Get human-readable asset type.
      $type_label = ucfirst(str_replace('_', ' ', $asset_type));

      // Build the header HTML.
      $html = '<div class="asset-info-header messages messages--status">';
      $html .= '<h3>' . $this->t('File Information') . '</h3>';
      $html .= '<dl class="asset-info-list" style="display: grid; grid-template-columns: auto 1fr; gap: 0.5em 1em; margin: 0;">';

      $html .= '<dt><strong>' . $this->t('File Name') . ':</strong></dt>';
      $html .= '<dd>' . htmlspecialchars($file_name) . '</dd>';

      $html .= '<dt><strong>' . $this->t('File URL') . ':</strong></dt>';
      $html .= '<dd><a href="' . htmlspecialchars($file_path) . '" target="_blank" rel="noopener">' . htmlspecialchars($file_path) . '</a></dd>';

      $html .= '<dt><strong>' . $this->t('Type') . ':</strong></dt>';
      $html .= '<dd>' . htmlspecialchars($type_label) . ' (' . htmlspecialchars($category) . ')</dd>';

      if ($filesize) {
        $html .= '<dt><strong>' . $this->t('File Size') . ':</strong></dt>';
        $html .= '<dd>' . ByteSizeMarkup::create($filesize) . '</dd>';
      }

      $html .= '<dt><strong>' . $this->t('Source') . ':</strong></dt>';
      $html .= '<dd>' . $source_label . '</dd>';

      $html .= '</dl>';
      $html .= '<p style="margin-top: 1em;"><a href="/admin/digital-asset-inventory">&larr; ' . $this->t('Back to Digital Asset Inventory') . '</a></p>';
      $html .= '</div>';

      return [
        '#markup' => $html,
        '#cache' => [
          'tags' => ['digital_asset_item:' . $asset_id],
        ],
      ];
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
