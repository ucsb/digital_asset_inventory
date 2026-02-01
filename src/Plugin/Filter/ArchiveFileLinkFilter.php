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

namespace Drupal\digital_asset_inventory\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a filter to route file links to Archive Detail Pages.
 *
 * This filter processes CKEditor content and replaces links to actively
 * archived files with links to their Archive Detail Pages. The routing
 * only occurs when:
 * 1. The archive-in-use feature is enabled
 * 2. The linked file has an active archive (archived_public or archived_admin)
 *
 * When either condition is false, the original file URL is preserved.
 *
 * @Filter(
 *   id = "digital_asset_archive_link_filter",
 *   title = @Translation("Route archived file links to Archive Detail Page"),
 *   description = @Translation("Replaces links to archived files with links to their Archive Detail Pages when the archive-in-use feature is enabled."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
 *   weight = 100
 * )
 */
final class ArchiveFileLinkFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The archive service.
   *
   * @var \Drupal\digital_asset_inventory\Service\ArchiveService
   */
  protected $archiveService;

  /**
   * Constructs an ArchiveFileLinkFilter object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ArchiveService $archive_service,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->archiveService = $archive_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('digital_asset_inventory.archive')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    // Always add cache tags so content is invalidated when archives or settings change.
    // This must happen even if routing is currently disabled, so that enabling
    // routing will cause content to be re-processed.
    $result->addCacheTags(['digital_asset_archive_list', 'config:digital_asset_inventory.settings']);
    $result->addCacheContexts(['url.site']);

    // Skip processing if text is empty or routing is disabled.
    if (empty($text) || !$this->archiveService->isLinkRoutingEnabled()) {
      return $result;
    }

    // Process href attributes pointing to files.
    $processed_text = $this->processFileLinks($text);

    // Process drupal-media embeds (videos inserted via Media Library).
    $processed_text = $this->processMediaEmbeds($processed_text);

    $result->setProcessedText($processed_text);

    return $result;
  }

  /**
   * Processes links in the text.
   *
   * @param string $text
   *   The text to process.
   *
   * @return string
   *   The processed text with archived links replaced.
   */
  protected function processFileLinks($text) {
    // Process anchor tags with href attributes.
    // Captures: full tag, attributes before href, href value, attributes after href, content.
    // This handles:
    // - File URLs (/sites/default/files/..., /system/files/...)
    // - Internal page URLs (/node/123, /my-page-alias)
    // - External URLs (https://external.com/...)
    $pattern = '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>(.*?)<\/a>/is';

    return preg_replace_callback($pattern, function ($matches) {
      $full_tag = $matches[0];
      $attrs_before = $matches[1];
      $original_url = $matches[2];
      $attrs_after = $matches[3];
      $link_content = $matches[4];

      // Skip images - they shouldn't be redirected as it would break rendering.
      if ($this->isImageUrl($original_url)) {
        return $full_tag;
      }

      // Skip anchor links and javascript.
      if (strpos($original_url, '#') === 0 || strpos($original_url, 'javascript:') === 0) {
        return $full_tag;
      }

      // Skip mailto and tel links.
      if (strpos($original_url, 'mailto:') === 0 || strpos($original_url, 'tel:') === 0) {
        return $full_tag;
      }

      // Build absolute URL for internal paths.
      $check_url = $original_url;
      if (strpos($original_url, '/') === 0 && strpos($original_url, '//') !== 0) {
        // Internal path - prepend base URL.
        $base_url = \Drupal::request()->getSchemeAndHttpHost();
        $check_url = $base_url . $original_url;
      }

      // Get the archive detail URL if this URL is archived.
      $archive_url = $this->archiveService->getArchiveDetailUrl(NULL, $check_url);

      if ($archive_url) {
        // Build the new anchor tag with "(Archived)" appended to the link text.
        $archived_label = $this->t('Archived');

        // Clean up and combine attributes.
        $all_attrs = trim($attrs_before . ' ' . $attrs_after);
        // Remove any existing dai-archived-link class to avoid duplicates.
        $all_attrs = preg_replace('/\s*class=["\'][^"\']*dai-archived-link[^"\']*["\']/i', '', $all_attrs);
        $all_attrs = trim($all_attrs);

        // Build the new tag with archive URL and "(Archived)" label.
        $new_tag = '<a href="' . htmlspecialchars($archive_url) . '" class="dai-archived-link"';
        if (!empty($all_attrs)) {
          $new_tag .= ' ' . $all_attrs;
        }
        $new_tag .= '>' . $link_content . ' <span class="dai-archived-label">(' . $archived_label . ')</span></a>';

        return $new_tag;
      }

      // No archive found, keep original.
      return $full_tag;
    }, $text);
  }

  /**
   * Processes drupal-media embeds and replaces archived media with links.
   *
   * When a video or document is embedded via Media Library and has an active
   * archive, the media display is replaced with a link to the Archive Detail
   * Page. Other HTML attributes from the original tag are preserved.
   *
   * @param string $text
   *   The text to process.
   *
   * @return string
   *   The processed text with archived media embeds replaced.
   */
  protected function processMediaEmbeds($text) {
    // Match drupal-media tags with data-entity-uuid attribute.
    // For simplified output, we only need the UUID - other attributes aren't preserved.
    $pattern = '/<drupal-media[^>]*data-entity-uuid=["\']([^"\']+)["\'][^>]*><\/drupal-media>/i';

    return preg_replace_callback($pattern, function ($matches) {
      $full_tag = $matches[0];
      $uuid = $matches[1];

      // Load the media entity by UUID.
      $media_storage = \Drupal::entityTypeManager()->getStorage('media');
      $media_entities = $media_storage->loadByProperties(['uuid' => $uuid]);

      if (empty($media_entities)) {
        return $full_tag;
      }

      $media = reset($media_entities);

      // Get the source field for this media type.
      $source = $media->getSource();
      $source_field = $source->getSourceFieldDefinition($media->bundle->entity);

      if (!$source_field) {
        return $full_tag;
      }

      $source_field_name = $source_field->getName();

      // Check if media has a file field (videos, documents).
      if (!$media->hasField($source_field_name)) {
        return $full_tag;
      }

      $field_value = $media->get($source_field_name)->getValue();
      if (empty($field_value[0]['target_id'])) {
        // Remote video (YouTube, Vimeo) - no local file.
        return $full_tag;
      }

      $fid = $field_value[0]['target_id'];

      // Check if this file has an active archive.
      $archive_url = $this->archiveService->getArchiveDetailUrl($fid);

      if ($archive_url) {
        // Get media name for the link text.
        $media_name = $media->getName() ?: $this->t('Archived file');
        $archived_label = $this->t('Archived');

        // For public content, show simplified link: "Name (Archived)" linking to detail page.
        // No icon or message box - just a clean inline link.
        $replacement = sprintf(
          '<a href="%s" class="dai-archived-link">%s <span class="dai-archived-label">(%s)</span></a>',
          htmlspecialchars($archive_url),
          htmlspecialchars($media_name),
          $archived_label
        );

        return $replacement;
      }

      // No archive found, keep original.
      return $full_tag;
    }, $text);
  }

  /**
   * Checks if a URL points to an image file.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if URL points to an image, FALSE otherwise.
   */
  protected function isImageUrl($url) {
    // Extract file extension from URL (ignoring query strings).
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) {
      return FALSE;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // Image extensions that should not be redirected.
    $image_extensions = [
      'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp', 'tiff', 'tif',
    ];

    return in_array($extension, $image_extensions);
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    if ($long) {
      return $this->t('Links to archived files will be routed to the Archive Detail Page when the archive-in-use feature is enabled. This allows archived documents to be accessed through the archive system instead of directly.');
    }
    return $this->t('Links to archived files are routed to the Archive Detail Page.');
  }

}
