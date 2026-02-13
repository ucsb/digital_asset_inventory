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
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\FilePathResolver;
use Drupal\digital_asset_inventory\Service\AltTextEvaluator;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views area handler to display asset information in the header.
 *
 * For Media-backed images, displays:
 * - Thumbnail (64-96px)
 * - Media title and filename
 * - Metadata (type, size, source, access)
 * - Media ID
 * - Media alt text status
 * - View/Edit Media actions
 * - File URL (secondary)
 * - Alt text summary strip (for images with usages)
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("digital_asset_info_header")
 */
final class AssetInfoHeader extends AreaPluginBase {

  use FilePathResolver;

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
   * The alt text evaluator service.
   *
   * @var \Drupal\digital_asset_inventory\Service\AltTextEvaluator
   */
  protected $altTextEvaluator;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

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
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\digital_asset_inventory\Service\AltTextEvaluator $alt_text_evaluator
   *   The alt text evaluator service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    AltTextEvaluator $alt_text_evaluator,
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->altTextEvaluator = $alt_text_evaluator;
    $this->fileUrlGenerator = $file_url_generator;
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
      $container->get('current_user'),
      $container->get('digital_asset_inventory.alt_text_evaluator'),
      $container->get('file_url_generator')
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
      $mime_type = $asset->get('mime_type')->value ?? '';

      // Determine if this is an image.
      $is_image = str_starts_with($mime_type, 'image/');
      $is_media = $source_type === 'media_managed' && $media_id;

      // Load media entity if applicable.
      $media = NULL;
      $media_title = NULL;
      $is_derived_thumbnail = FALSE;
      if ($is_media) {
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        if ($media) {
          $media_title = $media->label();
          // Detect derived thumbnails: asset is an image but the parent media's
          // source field is non-image (e.g., JPEG preview of a PDF document).
          if ($is_image) {
            $source_field_name = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
            if ($source_field_name && $media->hasField($source_field_name)) {
              $source_field_type = $media->get($source_field_name)->getFieldDefinition()->getType();
              $is_derived_thumbnail = ($source_field_type !== 'image');
            }
          }
        }
      }

      // Get human-readable source type label.
      $source_labels = [
        'file_managed' => $this->t('Upload'),
        'media_managed' => $this->t('Media'),
        'filesystem_only' => $this->t('Server'),
        'external' => $this->t('External'),
      ];
      $source_label = $source_labels[$source_type] ?? $source_type;

      // Get human-readable asset type.
      $type_label = ucfirst(str_replace('_', ' ', $asset_type));

      // Format file size.
      $size_display = $filesize ? ByteSizeMarkup::create($filesize) : '';

      // Build header HTML.
      $html = '<div class="asset-info-header">';
      $html .= '<div class="asset-info-header__wrapper">';

      // For images, use flex layout with thumbnail on left.
      if ($is_image) {
        $html .= '<div class="asset-info-header__content asset-info-header__content--with-thumbnail">';

        // Thumbnail section.
        $html .= $this->buildThumbnail($asset, $media, $file_path, $mime_type);
      }
      else {
        $html .= '<div class="asset-info-header__content">';
      }

      // Details section.
      $html .= '<div class="asset-info-header__details">';

      // Display name (media title or file name).
      if ($is_derived_thumbnail) {
        // Derived thumbnail: show file name as primary name.
        $html .= '<div class="asset-info-header__title">';
        $html .= '<span class="asset-info-header__name">' . htmlspecialchars($file_name) . '</span>';
        $html .= '</div>';
      }
      elseif ($media_title && $media_title !== $file_name) {
        $html .= '<div class="asset-info-header__title">';
        $html .= '<span class="asset-info-header__name">' . htmlspecialchars($media_title) . '</span>';
        $html .= ' <span class="asset-info-header__filename">(' . htmlspecialchars($file_name) . ')</span>';
        $html .= '</div>';
      }
      else {
        $html .= '<div class="asset-info-header__title">';
        $html .= '<span class="asset-info-header__name">' . htmlspecialchars($file_name) . '</span>';
        $html .= '</div>';
      }

      // Metadata with | divider.
      // Pattern: JPG | 691.17 KB | Media File | Media ID: 123 | Public
      $html .= '<div class="asset-info-header__meta">';
      $metadata = [];
      $metadata[] = htmlspecialchars($type_label);
      if ($size_display) {
        $metadata[] = $size_display;
      }
      $metadata[] = $source_label;
      // Include Media ID in metadata line (muted, technical).
      if ($is_media && $media_id) {
        $metadata[] = '<span class="asset-info-header__media-id">' . $this->t('Media ID: @id', ['@id' => $media_id]) . '</span>';
      }
      $metadata[] = $is_private
        ? $this->t('Private (Accessible only to logged-in or authorized users)')
        : $this->t('Public (Accessible to anyone without logging in)');
      $html .= implode(' <span class="asset-info-header__divider" aria-hidden="true">|</span> ', $metadata);
      $html .= '</div>';

      // Media-specific information.
      if ($is_media && $media) {
        // Media alt text status (for images only).
        // Label is muted, status value is emphasized.
        if ($is_image) {
          $alt_result = $this->altTextEvaluator->getMediaAltText($media);
          // Skip alt text display for non-image media (e.g., derived thumbnails
          // of PDF media where the source field has no alt property).
          if ($alt_result['status'] !== AltTextEvaluator::STATUS_NOT_EVALUATED) {
            $html .= '<div class="asset-info-header__alt-status">';
            $html .= '<span class="asset-info-header__alt-label">' . $this->t('Media alt text:') . '</span> ';
            if ($alt_result['status'] === AltTextEvaluator::STATUS_DETECTED) {
              $html .= '<span class="asset-info-header__alt-value asset-info-header__alt-value--detected">' . $this->t('detected') . '</span>';
            }
            else {
              $html .= '<span class="asset-info-header__alt-value asset-info-header__alt-value--not-detected">' . $this->t('not detected') . '</span>';
            }
            $html .= '</div>';
          }
        }

        // Alt text summary (inline, for images with usages).
        if ($is_image) {
          $html .= $this->buildAltTextSummary($asset, $asset_id);
        }

        // For derived thumbnails, show parent media context above actions.
        if ($is_derived_thumbnail && $media_title) {
          $html .= '<div class="asset-info-header__derived-from">';
          $html .= '<span class="asset-info-header__derived-label">' . $this->t('Derived from:') . '</span> ';
          $html .= htmlspecialchars($media_title);
          $html .= '</div>';
        }

        // Media actions (View / Edit).
        $html .= $this->buildMediaActions($media);
      }
      else {
        // Non-media images: show alt text summary if applicable.
        if ($is_image) {
          $html .= $this->buildAltTextSummary($asset, $asset_id);
        }
      }

      // URL on next line (de-emphasized for Media items).
      $url_class = $is_media ? 'asset-info-header__url asset-info-header__url--secondary' : 'asset-info-header__url';
      $html .= '<div class="' . $url_class . '">';
      $html .= '<span class="asset-info-header__url-label">' . $this->t('Direct file URL:') . '</span> ';
      $html .= '<a href="' . htmlspecialchars($file_path) . '">' . htmlspecialchars($file_path) . '</a>';
      $html .= '</div>';

      $html .= '</div>'; // End details.
      $html .= '</div>'; // End content.
      $html .= '</div>'; // End wrapper.
      $html .= '</div>'; // End header.

      return [
        '#markup' => $html,
        '#attached' => [
          'library' => ['digital_asset_inventory/admin'],
        ],
        '#cache' => [
          'tags' => array_merge(
            ['digital_asset_item:' . $asset_id],
            $media ? $media->getCacheTags() : ['media_list']
          ),
        ],
      ];
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Builds the thumbnail HTML for images.
   *
   * @param object $asset
   *   The digital asset item.
   * @param \Drupal\media\MediaInterface|null $media
   *   The media entity, if applicable.
   * @param string $file_path
   *   The file path/URL.
   * @param string $mime_type
   *   The MIME type.
   *
   * @return string
   *   The thumbnail HTML.
   */
  protected function buildThumbnail($asset, ?MediaInterface $media, string $file_path, string $mime_type): string {
    $html = '<div class="asset-info-header__thumbnail">';

    // Skip thumbnail for SVG (show icon instead).
    if ($mime_type === 'image/svg+xml') {
      $html .= '<div class="asset-info-header__thumbnail-icon">';
      $html .= '<span class="asset-info-header__icon asset-info-header__icon--svg">SVG</span>';
      $html .= '</div>';
      $html .= '</div>';
      return $html;
    }

    // Skip thumbnail for very large files (> 15MB).
    $filesize = $asset->get('filesize')->value ?? 0;
    if ($filesize > 15 * 1024 * 1024) {
      $html .= '<div class="asset-info-header__thumbnail-icon">';
      $html .= '<span class="asset-info-header__icon asset-info-header__icon--large">' . $this->t('Large file') . '</span>';
      $html .= '</div>';
      $html .= '</div>';
      return $html;
    }

    // Try to get image style URL.
    $thumbnail_url = NULL;

    if ($media) {
      // Get thumbnail from media entity.
      $thumbnail_url = $this->getMediaThumbnailUrl($media);
    }

    if (!$thumbnail_url) {
      // Try to get URI from file entity (for file_managed assets).
      $fid = $asset->get('fid')->value ?? NULL;
      if ($fid) {
        $thumbnail_url = $this->getFileEntityThumbnailUrl($fid);
      }
    }

    if (!$thumbnail_url) {
      // Fall back to using the image style directly on the file path.
      $thumbnail_url = $this->getImageStyleUrl($file_path);
    }

    if ($thumbnail_url) {
      // Thumbnail is decorative (alt text shown separately).
      $html .= '<img src="' . htmlspecialchars($thumbnail_url) . '" alt="" class="asset-info-header__thumbnail-img" loading="lazy">';
    }
    else {
      // Fallback icon.
      $html .= '<div class="asset-info-header__thumbnail-icon">';
      $html .= '<span class="asset-info-header__icon asset-info-header__icon--image">' . $this->t('Image') . '</span>';
      $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Gets the thumbnail URL from a file entity.
   *
   * Works with both Drupal 10 and Drupal 11.
   *
   * @param int $fid
   *   The file entity ID.
   *
   * @return string|null
   *   The thumbnail URL, or NULL if not available.
   */
  protected function getFileEntityThumbnailUrl(int $fid): ?string {
    try {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if (!$file) {
        return NULL;
      }

      $uri = $file->getFileUri();
      if (!$uri) {
        return NULL;
      }

      // Use image style to generate thumbnail.
      return $this->getImageStyleUrlFromUri($uri);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the thumbnail URL from a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return string|null
   *   The thumbnail URL, or NULL if not available.
   */
  protected function getMediaThumbnailUrl(MediaInterface $media): ?string {
    try {
      $source = $media->getSource();
      $source_field = $source->getConfiguration()['source_field'] ?? NULL;

      if (!$source_field || !$media->hasField($source_field)) {
        return NULL;
      }

      $field = $media->get($source_field);
      if ($field->isEmpty()) {
        return NULL;
      }

      // Only image source fields can generate image style thumbnails.
      // Non-image media (PDF, video) produce broken image style URLs.
      $field_type = $field->getFieldDefinition()->getType();
      if ($field_type !== 'image') {
        return NULL;
      }

      $first_item = $field->first();
      if (!$first_item) {
        return NULL;
      }

      // Get the file entity.
      $file = $first_item->entity ?? NULL;
      if (!$file) {
        return NULL;
      }

      $uri = $file->getFileUri();
      return $this->getImageStyleUrl($uri);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the image style URL for a file path or URI.
   *
   * @param string $uri_or_path
   *   The file URI or path (URL).
   *
   * @return string|null
   *   The styled image URL, or NULL if not available.
   */
  protected function getImageStyleUrl(string $uri_or_path): ?string {
    try {
      // Convert path/URL to URI if needed.
      $uri = $this->convertPathToUri($uri_or_path);
      if (!$uri) {
        return NULL;
      }

      return $this->getImageStyleUrlFromUri($uri);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets the image style URL from a Drupal file URI.
   *
   * Works with both Drupal 10 and Drupal 11.
   *
   * @param string $uri
   *   The file URI (e.g., public://image.jpg or private://image.jpg).
   *
   * @return string|null
   *   The styled image URL, or NULL if not available.
   */
  protected function getImageStyleUrlFromUri(string $uri): ?string {
    try {
      // Try to use the 'thumbnail' image style (100x100).
      // This is a core image style available in all Drupal installations.
      $style = ImageStyle::load('thumbnail');
      if (!$style) {
        // Fall back to 'medium' style (220x220).
        $style = ImageStyle::load('medium');
      }

      if (!$style) {
        return NULL;
      }

      // ImageStyle::buildUrl() works the same in D10 and D11.
      return $style->buildUrl($uri);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Converts a file path or URL to a Drupal stream URI.
   *
   * Delegates to the FilePathResolver trait's urlPathToStreamUri() for
   * multisite-safe conversion with dynamic base path fallback.
   *
   * @param string $path_or_url
   *   The file path or URL.
   *
   * @return string|null
   *   The stream URI (e.g., public://image.jpg), or NULL if conversion fails.
   */
  protected function convertPathToUri(string $path_or_url): ?string {
    return $this->urlPathToStreamUri($path_or_url);
  }

  /**
   * Builds the Media actions HTML (View / Edit).
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return string
   *   The actions HTML.
   */
  protected function buildMediaActions(MediaInterface $media): string {
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
      $actions[] = '<a href="' . htmlspecialchars($view_url) . '" class="asset-info-header__action">' . $this->t('View Media') . '</a>';
    }

    // Show Edit link if user has permission.
    if ($edit_url) {
      $actions[] = '<a href="' . htmlspecialchars($edit_url) . '" class="asset-info-header__action">' . $this->t('Edit Media') . '</a>';
    }

    if (empty($actions)) {
      return '';
    }

    // Join with separator for visual distinction.
    $separator = ' <span class="asset-info-header__action-divider" aria-hidden="true">|</span> ';
    return '<div class="asset-info-header__media-actions">' . implode($separator, $actions) . '</div>';
  }

  /**
   * Builds the alt text summary strip for images.
   *
   * @param object $asset
   *   The digital asset item.
   * @param int $asset_id
   *   The asset ID.
   *
   * @return string
   *   The summary strip HTML.
   */
  protected function buildAltTextSummary($asset, int $asset_id): string {
    // Query usage records for this asset.
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');
    $query = $usage_storage->getQuery()
      ->condition('asset_id', $asset_id)
      ->accessCheck(FALSE);
    $usage_ids = $query->execute();

    if (empty($usage_ids)) {
      return '';
    }

    $usages = $usage_storage->loadMultiple($usage_ids);

    // Evaluate alt text for each usage.
    $counts = [
      'detected' => 0,
      'not_detected' => 0,
      'decorative' => 0,
      'not_evaluated' => 0,
    ];

    foreach ($usages as $usage) {
      $entity_type = $usage->get('entity_type')->value;
      $entity_id = $usage->get('entity_id')->value;
      $field_name = $usage->get('field_name')->value;

      try {
        $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
        if ($entity) {
          $result = $this->altTextEvaluator->evaluateForUsage($asset, $entity, $field_name);
          $status = $result['status'] ?? AltTextEvaluator::STATUS_NOT_EVALUATED;
          if (isset($counts[$status])) {
            $counts[$status]++;
          }
          else {
            $counts['not_evaluated']++;
          }
        }
        else {
          $counts['not_evaluated']++;
        }
      }
      catch (\Exception $e) {
        $counts['not_evaluated']++;
      }
    }

    // Build inline summary: "Alt text summary: 1 with alt · 2 not evaluated"
    // Only show non-zero counts.
    $parts = [];
    if ($counts['detected'] > 0) {
      $parts[] = $this->t('@count with alt', ['@count' => $counts['detected']]);
    }
    if ($counts['not_detected'] > 0) {
      $parts[] = $this->t('@count missing alt', ['@count' => $counts['not_detected']]);
    }
    if ($counts['decorative'] > 0) {
      $parts[] = $this->t('@count decorative', ['@count' => $counts['decorative']]);
    }
    if ($counts['not_evaluated'] > 0) {
      $parts[] = $this->t('@count not evaluated', ['@count' => $counts['not_evaluated']]);
    }

    if (empty($parts)) {
      return '';
    }

    $html = '<div class="asset-info-header__alt-summary">';
    $html .= '<span class="asset-info-header__alt-summary-label">' . $this->t('Alt text summary:') . '</span> ';
    $html .= implode(' <span class="asset-info-header__alt-summary-sep" aria-hidden="true">·</span> ', $parts);
    $html .= '</div>';

    return $html;
  }

}
