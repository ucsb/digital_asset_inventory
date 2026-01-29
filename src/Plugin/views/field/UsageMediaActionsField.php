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

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\media\MediaInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views field handler to display Media View/Edit actions per usage row.
 *
 * This field provides quick access to the Media entity management
 * directly from the usage table. Actions are intentionally duplicated
 * from the header to support workflow while reviewing individual usages.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("usage_media_actions_field")
 */
class UsageMediaActionsField extends FieldPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The asset being viewed (cached).
   *
   * @var object|null
   */
  protected $asset = NULL;

  /**
   * Whether the asset is media-backed (cached).
   *
   * @var bool|null
   */
  protected $isMedia = NULL;

  /**
   * The media entity (cached).
   *
   * @var \Drupal\media\MediaInterface|null
   */
  protected $media = NULL;

  /**
   * Constructs a UsageMediaActionsField object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // No additional query modifications needed.
    // We get media info from the asset entity.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Check if asset is media-backed - if not, don't render.
    if (!$this->isMediaBacked()) {
      return [];
    }

    $media = $this->getMedia();
    if (!$media) {
      return [];
    }

    return $this->renderMediaActions($media);
  }

  /**
   * Renders the Media actions (View / Edit).
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return array
   *   A render array.
   */
  protected function renderMediaActions(MediaInterface $media): array {
    $actions = [];
    $view_url = NULL;
    $edit_url = NULL;

    // Get canonical URL.
    try {
      $view_url = $media->toUrl('canonical')->toString();
    }
    catch (\Exception $e) {
      // Skip if URL generation fails.
    }

    // Get edit URL (permission-aware).
    if ($media->access('update', $this->currentUser)) {
      try {
        $edit_url = $media->toUrl('edit-form')->toString();
      }
      catch (\Exception $e) {
        // Skip if URL generation fails.
      }
    }

    // Only show View link if it differs from Edit.
    // Some Drupal configs set canonical = edit-form (no public view page).
    if ($view_url && $view_url !== $edit_url) {
      $actions[] = '<a href="' . htmlspecialchars($view_url) . '" class="media-action media-action--view">' . $this->t('View') . '</a>';
    }

    // Show Edit link if user has permission.
    if ($edit_url) {
      $actions[] = '<a href="' . htmlspecialchars($edit_url) . '" class="media-action media-action--edit">' . $this->t('Edit') . '</a>';
    }

    if (empty($actions)) {
      return [];
    }

    return [
      '#markup' => '<span class="media-actions">' . implode(' <span class="media-actions__divider" aria-hidden="true">|</span> ', $actions) . '</span>',
    ];
  }

  /**
   * Checks if the current asset is media-backed.
   *
   * @return bool
   *   TRUE if the asset is media-backed.
   */
  protected function isMediaBacked(): bool {
    if ($this->isMedia !== NULL) {
      return $this->isMedia;
    }

    $asset = $this->getAsset();
    if (!$asset) {
      $this->isMedia = FALSE;
      return FALSE;
    }

    $source_type = $asset->get('source_type')->value ?? '';
    $media_id = $asset->get('media_id')->value ?? NULL;

    $this->isMedia = ($source_type === 'media_managed' && !empty($media_id));

    return $this->isMedia;
  }

  /**
   * Gets the asset entity being viewed.
   *
   * @return object|null
   *   The asset entity, or NULL if not found.
   */
  protected function getAsset() {
    if ($this->asset !== NULL) {
      return $this->asset;
    }

    // Get asset_id from view argument.
    $asset_id = $this->view->args[0] ?? NULL;
    if (!$asset_id) {
      return NULL;
    }

    try {
      $this->asset = $this->entityTypeManager
        ->getStorage('digital_asset_item')
        ->load($asset_id);
    }
    catch (\Exception $e) {
      $this->asset = NULL;
    }

    return $this->asset;
  }

  /**
   * Gets the media entity for the current asset.
   *
   * @return \Drupal\media\MediaInterface|null
   *   The media entity, or NULL if not found.
   */
  protected function getMedia(): ?MediaInterface {
    if ($this->media !== NULL) {
      return $this->media;
    }

    $asset = $this->getAsset();
    if (!$asset) {
      return NULL;
    }

    $media_id = $asset->get('media_id')->value ?? NULL;
    if (!$media_id) {
      return NULL;
    }

    try {
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if ($media instanceof MediaInterface) {
        $this->media = $media;
      }
    }
    catch (\Exception $e) {
      $this->media = NULL;
    }

    return $this->media;
  }

}
