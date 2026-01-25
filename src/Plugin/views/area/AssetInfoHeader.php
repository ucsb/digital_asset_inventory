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
final class AssetInfoHeader extends AreaPluginBase {

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
      $filesize = $asset->get('filesize')->value;
      $source_type = $asset->get('source_type')->value;
      $media_id = $asset->get('media_id')->value;
      $is_private = (bool) $asset->get('is_private')->value;

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

      // Format file size.
      $size_display = $filesize ? ByteSizeMarkup::create($filesize) : '';

      // For media files, get the media title.
      $media_title = NULL;
      if ($source_type === 'media_managed' && $media_id) {
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        if ($media) {
          $media_title = $media->label();
        }
      }

      // Build header HTML using CSS classes.
      $html = '<div class="asset-info-header">';
      $html .= '<div class="asset-info-header__wrapper">';
      $html .= '<div class="asset-info-header__content">';

      // Display name (media title or file name).
      if ($media_title && $media_title !== $file_name) {
        // Show both media title and file name for media files.
        $html .= '<div class="asset-info-header__title">';
        $html .= '<span class="asset-info-header__name">' . htmlspecialchars($media_title) . '</span>';
        $html .= ' <span class="asset-info-header__filename">(' . htmlspecialchars($file_name) . ')</span>';
        $html .= '</div>';
      }
      else {
        // Just show file name.
        $html .= '<div class="asset-info-header__title">';
        $html .= '<span class="asset-info-header__name">' . htmlspecialchars($file_name) . '</span>';
        $html .= '</div>';
      }

      // Metadata with | divider (aria-hidden for screen readers).
      $html .= '<div class="asset-info-header__meta">';
      $metadata = [];
      $metadata[] = htmlspecialchars($type_label);
      if ($size_display) {
        $metadata[] = $size_display;
      }
      $metadata[] = $source_label;
      $metadata[] = $is_private
        ? $this->t('Private (Accessible only to logged-in or authorized users)')
        : $this->t('Public (Accessible to anyone without logging in)');
      $html .= implode(' <span class="asset-info-header__divider" aria-hidden="true">|</span> ', $metadata);
      $html .= '</div>';

      // URL on next line.
      $html .= '<div class="asset-info-header__url">';
      $html .= '<a href="' . htmlspecialchars($file_path) . '">' . htmlspecialchars($file_path) . '</a>';
      $html .= '</div>';

      $html .= '</div>';
      $html .= '</div>';
      $html .= '</div>';

      return [
        '#markup' => $html,
        '#attached' => [
          'library' => ['digital_asset_inventory/admin'],
        ],
        '#cache' => [
          'tags' => ['digital_asset_item:' . $asset_id, 'media_list'],
        ],
      ];
    }
    catch (\Exception $e) {
      return [];
    }
  }

}
