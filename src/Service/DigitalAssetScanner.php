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

namespace Drupal\digital_asset_inventory\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\digital_asset_inventory\FilePathResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for scanning and inventorying digital assets.
 */
class DigitalAssetScanner {

  use FilePathResolver;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The service container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * Constructs a DigitalAssetScanner object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    ConfigFactoryInterface $config_factory,
    FileUrlGeneratorInterface $file_url_generator,
    FileSystemInterface $file_system,
    EntityFieldManagerInterface $entity_field_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ContainerInterface $container,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->fileUrlGenerator = $file_url_generator;
    $this->fileSystem = $file_system;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger_factory->get('digital_asset_inventory');
    $this->container = $container;
  }

  /**
   * Gets count of managed files to scan.
   *
   * @return int
   *   The number of managed files.
   */
  public function getManagedFilesCount() {
    $query = $this->database->select('file_managed', 'f');

    // Exclude system-generated files by path.
    $this->excludeSystemGeneratedFiles($query);

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Scans a chunk of managed files.
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of files to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Number of items processed.
   */
  public function scanManagedFilesChunk($offset, $limit, $is_temp = FALSE) {
    $count = 0;
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    $query = $this->database->select('file_managed', 'f');
    $query->fields('f', ['fid', 'uri', 'filemime', 'filename', 'filesize']);

    // Exclude system-generated files by path.
    $this->excludeSystemGeneratedFiles($query);

    $query->range($offset, $limit);
    $results = $query->execute();

    foreach ($results as $file) {
      // Determine asset type using whitelist mapper.
      $asset_type = $this->mapMimeToAssetType($file->filemime);

      // Determine category from asset type.
      $category = $this->mapAssetTypeToCategory($asset_type);

      // Determine sort order from category.
      $sort_order = $this->getCategorySortOrder($category);

      // Check if this file is associated with a Media entity.
      $source_type = 'file_managed';
      $media_id = NULL;
      $all_media_ids = [];

      // Query file_usage for ALL media associations (handles multilingual sites
      // where same file may be used by multiple media entities).
      $media_usages = $this->database->select('file_usage', 'fu')
        ->fields('fu', ['id'])
        ->condition('fid', $file->fid)
        ->condition('type', 'media')
        ->execute()
        ->fetchCol();

      if (!empty($media_usages)) {
        $source_type = 'media_managed';
        // Store first media_id for backwards compatibility (entity field).
        $media_id = reset($media_usages);
        // Store all media IDs for comprehensive usage detection.
        $all_media_ids = $media_usages;
      }

      // Convert URI to absolute URL for storage.
      try {
        $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($file->uri);
      }
      catch (\Exception $e) {
        // Fallback to URI if conversion fails.
        $absolute_url = $file->uri;
      }

      // Check if file is in the private file system.
      $is_private = strpos($file->uri, 'private://') === 0;

      // Find existing TEMP entity by fid field (not entity ID).
      // Only update temp items - never modify permanent items during scan.
      // This ensures permanent items remain intact if scan fails.
      $existing_query = $storage->getQuery()
        ->condition('fid', $file->fid)
        ->condition('is_temp', TRUE)
        ->accessCheck(FALSE)
        ->execute();

      if ($existing_ids = $existing_query) {
        // Update existing temp entity.
        $existing = $storage->load(reset($existing_ids));
        $item = $existing;
        $item->set('source_type', $source_type);
        $item->set('media_id', $media_id);
        $item->set('asset_type', $asset_type);
        $item->set('category', $category);
        $item->set('sort_order', $sort_order);
        $item->set('file_path', $absolute_url);
        $item->set('file_name', $file->filename);
        $item->set('mime_type', $file->filemime);
        $item->set('filesize', $file->filesize);
        $item->set('is_private', $is_private);
      }
      else {
        // Create new entity.
        $item = $storage->create([
          'fid' => $file->fid,
          'source_type' => $source_type,
          'media_id' => $media_id,
          'asset_type' => $asset_type,
          'category' => $category,
          'sort_order' => $sort_order,
          'file_path' => $absolute_url,
          'file_name' => $file->filename,
          'mime_type' => $file->filemime,
          'filesize' => $file->filesize,
          'is_temp' => $is_temp,
          'is_private' => $is_private,
        ]);
      }

      $item->save();
      $asset_id = $item->id();

      // IMPORTANT: Clear existing usage records for this asset before
      // re-scanning. This ensures deleted references don't persist.
      $old_usage_query = $usage_storage->getQuery();
      $old_usage_query->condition('asset_id', $asset_id);
      $old_usage_query->accessCheck(FALSE);
      $old_usage_ids = $old_usage_query->execute();

      if ($old_usage_ids) {
        $old_usages = $usage_storage->loadMultiple($old_usage_ids);
        $usage_storage->delete($old_usages);
      }

      // NOTE: Text field links (<a href>) are now detected in Phase 3 (scanContentChunk)
      // via extractLocalFileUrls() + processLocalFileLink() with proper embed_method tracking.
      // Removed findLocalFileLinkUsage() call here to avoid duplicate detection.

      // Check for direct file/image field usage (not via media).
      // Detects files in direct 'image' or 'file' fields like field_image.
      $direct_file_usage = $this->findDirectFileUsage($file->fid);

      foreach ($direct_file_usage as $ref) {
        // Trace paragraphs to their parent nodes.
        $parent_entity_type = $ref['entity_type'];
        $parent_entity_id = $ref['entity_id'];

        if ($parent_entity_type === 'paragraph') {
          $parent_info = $this->getParentFromParagraph($parent_entity_id);
          if ($parent_info) {
            $parent_entity_type = $parent_info['type'];
            $parent_entity_id = $parent_info['id'];
          }
          else {
            // Paragraph is orphaned - skip this reference.
            continue;
          }
        }

        // Check if usage record already exists for this entity.
        $existing_usage_query = $usage_storage->getQuery();
        $existing_usage_query->condition('asset_id', $asset_id);
        $existing_usage_query->condition('entity_type', $parent_entity_type);
        $existing_usage_query->condition('entity_id', $parent_entity_id);
        $existing_usage_query->accessCheck(FALSE);
        $existing_usage_ids = $existing_usage_query->execute();

        if (!$existing_usage_ids) {
          // Create usage record showing where file is used directly.
          // These are from file/image fields (via findDirectFileUsage).
          $usage_storage->create([
            'asset_id' => $asset_id,
            'entity_type' => $parent_entity_type,
            'entity_id' => $parent_entity_id,
            'field_name' => $ref['field_name'],
            'count' => 1,
            'embed_method' => 'field_reference',
          ])->save();
        }
      }

      // For media files, also find usage via entity reference and media embeds.
      if (!empty($all_media_ids)) {
        // IMPORTANT: Clear existing usage records for this asset before
        // re-scanning. This ensures deleted references don't persist.
        $old_usage_query = $usage_storage->getQuery();
        $old_usage_query->condition('asset_id', $asset_id);
        $old_usage_query->accessCheck(FALSE);
        $old_usage_ids = $old_usage_query->execute();

        if ($old_usage_ids) {
          $old_usages = $usage_storage->loadMultiple($old_usage_ids);
          $usage_storage->delete($old_usages);
        }

        // Find all media references from ALL associated media entities.
        // This handles multilingual sites where same file has multiple media entities.
        $media_references = [];
        foreach ($all_media_ids as $mid) {
          $refs = $this->findMediaUsageViaEntityQuery($mid);
          $media_references = array_merge($media_references, $refs);
        }

        // Deduplicate references by entity+field combination.
        // This preserves field_name info while avoiding duplicate records.
        $unique_refs = [];
        foreach ($media_references as $ref) {
          $field_name = $ref['field_name'] ?? 'media';
          $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . $field_name;
          if (!isset($unique_refs[$key])) {
            $unique_refs[$key] = $ref;
          }
        }
        $media_references = array_values($unique_refs);

        foreach ($media_references as $ref) {
          // Trace paragraphs to their parent nodes.
          $parent_entity_type = $ref['entity_type'];
          $parent_entity_id = $ref['entity_id'];
          $field_name = $ref['field_name'] ?? 'media';

          if ($parent_entity_type === 'paragraph') {
            $parent_info = $this->getParentFromParagraph($parent_entity_id);
            if ($parent_info) {
              $parent_entity_type = $parent_info['type'];
              $parent_entity_id = $parent_info['id'];
            }
            else {
              // Paragraph is orphaned - skip this reference entirely.
              // Orphan count is tracked in getParentFromParagraph().
              continue;
            }
          }

          // Check if usage record already exists for this entity+field.
          $existing_usage_query = $usage_storage->getQuery();
          $existing_usage_query->condition('asset_id', $asset_id);
          $existing_usage_query->condition('entity_type', $parent_entity_type);
          $existing_usage_query->condition('entity_id', $parent_entity_id);
          $existing_usage_query->condition('field_name', $field_name);
          $existing_usage_query->accessCheck(FALSE);
          $existing_usage_ids = $existing_usage_query->execute();

          if (!$existing_usage_ids) {
            // Map reference method to embed_method field value.
            $embed_method = 'field_reference';
            if (isset($ref['method']) && $ref['method'] === 'media_embed') {
              $embed_method = 'drupal_media';
            }

            // Create usage record showing where media is used.
            $usage_storage->create([
              'asset_id' => $asset_id,
              'entity_type' => $parent_entity_type,
              'entity_id' => $parent_entity_id,
              'field_name' => $field_name,
              'count' => 1,
              'embed_method' => $embed_method,
            ])->save();
          }
        }
      }

      // Update CSV export fields: filesize_formatted and used_in_csv.
      $this->updateCsvExportFields($asset_id, $file->filesize);

      $count++;
    }

    return $count;
  }

  /**
   * Maps a MIME type to a normalized asset type using whitelist approach.
   *
   * @param string $mime
   *   The MIME type from file_managed.filemime.
   *
   * @return string
   *   Asset type: pdf, word, excel, powerpoint, text, csv, jpg, png, gif, svg,
   *   webp, mp4, webm, mov, avi, mp3, wav, m4a, ogg, or 'other'.
   */
  protected function mapMimeToAssetType($mime) {
    $mime = strtolower(trim($mime));

    // Whitelist of known MIME types mapped to granular asset types.
    $map = [
      // Documents.
      'application/pdf' => 'pdf',
      'application/msword' => 'word',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
      'application/vnd.ms-excel' => 'excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',
      'application/vnd.ms-powerpoint' => 'powerpoint',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'powerpoint',
      'text/plain' => 'text',
      'text/csv' => 'csv',
      'application/csv' => 'csv',
      // Caption/subtitle files.
      'text/vtt' => 'vtt',
      'application/x-subrip' => 'srt',
      'text/srt' => 'srt',

      // Images - granular types.
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/svg+xml' => 'svg',
      'image/webp' => 'webp',

      // Videos - granular types.
      'video/mp4' => 'mp4',
      'video/webm' => 'webm',
      'video/quicktime' => 'mov',
      'video/x-msvideo' => 'avi',

      // Audio - granular types.
      'audio/mpeg' => 'mp3',
      'audio/wav' => 'wav',
      'audio/mp4' => 'm4a',
      'audio/ogg' => 'ogg',

      // Compressed files - categorized under 'Other' category.
      'application/zip' => 'compressed',
      'application/x-tar' => 'compressed',
      'application/gzip' => 'compressed',
      'application/x-7z-compressed' => 'compressed',
      'application/x-rar-compressed' => 'compressed',
      'application/x-gzip' => 'compressed',
    ];

    // Check exact match first.
    if (isset($map[$mime])) {
      return $map[$mime];
    }

    // Default: unrecognized MIME type.
    return 'other';
  }

  /**
   * Maps asset type to category based on configuration.
   *
   * @param string $asset_type
   *   The asset type (pdf, word, image, etc.).
   *
   * @return string
   *   Category: Documents, Media, or Unknown.
   */
  protected function mapAssetTypeToCategory($asset_type) {
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $asset_types = $config->get('asset_types');

    // Look up category from config.
    if ($asset_types && isset($asset_types[$asset_type]['category'])) {
      return $asset_types[$asset_type]['category'];
    }

    // Default fallback.
    return 'Unknown';
  }

  /**
   * Matches a URL to an asset type based on URL patterns in configuration.
   *
   * @param string $url
   *   The URL to match.
   *
   * @return string
   *   Asset type: google_doc, youtube, vimeo, etc., or 'other'.
   */
  protected function matchUrlToAssetType($url) {
    $url = strtolower(trim($url));
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $asset_types = $config->get('asset_types');

    if (!$asset_types) {
      return 'other';
    }

    // Check each asset type's URL patterns.
    foreach ($asset_types as $type => $settings) {
      if (isset($settings['url_patterns']) && is_array($settings['url_patterns'])) {
        foreach ($settings['url_patterns'] as $pattern) {
          if (strpos($url, $pattern) !== FALSE) {
            return $type;
          }
        }
      }
    }

    return 'other';
  }

  /**
   * Extracts URLs from text content.
   *
   * @param string $text
   *   The text to scan for URLs.
   *
   * @return array
   *   Array of unique URLs found.
   */
  protected function extractUrls($text) {
    $urls = [];

    // Pattern to match URLs.
    $pattern = '/https?:\/\/[^\s<>"{}|\\^`\[\]]+/i';

    if (preg_match_all($pattern, $text, $matches)) {
      $urls = array_unique($matches[0]);
    }

    return $urls;
  }

  /**
   * Normalizes a video URL to a canonical form for consistent tracking.
   *
   * This ensures the same video is tracked as a single asset regardless
   * of URL format (full URL, short URL, embed URL, or video ID).
   *
   * Canonical forms:
   * - YouTube: https://www.youtube.com/watch?v=VIDEO_ID
   * - Vimeo: https://vimeo.com/VIDEO_ID
   *
   * @param string $url
   *   The URL or video ID to normalize.
   *
   * @return array|null
   *   Array with 'url' (canonical), 'video_id', and 'platform', or NULL if not a video URL.
   */
  protected function normalizeVideoUrl($url) {
    $url = trim($url);
    if (empty($url)) {
      return NULL;
    }

    // YouTube patterns.
    $youtube_patterns = [
      // Standard watch URL: youtube.com/watch?v=VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?(?:.*&)?v=([a-zA-Z0-9_-]{11})(?:&|$)/i',
      // Short URL: youtu.be/VIDEO_ID
      '/(?:https?:\/\/)?youtu\.be\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
      // Embed URL: youtube.com/embed/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
      // Old embed URL: youtube.com/v/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/v\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
      // Shorts URL: youtube.com/shorts/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
      // No-cookie domain: youtube-nocookie.com/embed/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube-nocookie\.com\/embed\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
    ];

    foreach ($youtube_patterns as $pattern) {
      if (preg_match($pattern, $url, $matches)) {
        $video_id = $matches[1];
        return [
          'url' => 'https://www.youtube.com/watch?v=' . $video_id,
          'video_id' => $video_id,
          'platform' => 'youtube',
        ];
      }
    }

    // Vimeo patterns.
    $vimeo_patterns = [
      // Standard URL: vimeo.com/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?vimeo\.com\/(\d+)(?:\?|\/|$)/i',
      // Player URL: player.vimeo.com/video/VIDEO_ID
      '/(?:https?:\/\/)?player\.vimeo\.com\/video\/(\d+)(?:\?|$)/i',
    ];

    foreach ($vimeo_patterns as $pattern) {
      if (preg_match($pattern, $url, $matches)) {
        $video_id = $matches[1];
        return [
          'url' => 'https://vimeo.com/' . $video_id,
          'video_id' => $video_id,
          'platform' => 'vimeo',
        ];
      }
    }

    // Check if it's just a YouTube video ID (11 chars, alphanumeric with - and _).
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) {
      return [
        'url' => 'https://www.youtube.com/watch?v=' . $url,
        'video_id' => $url,
        'platform' => 'youtube',
      ];
    }

    // Check if it's just a Vimeo video ID (numeric only, reasonable length).
    if (preg_match('/^\d{1,12}$/', $url)) {
      return [
        'url' => 'https://vimeo.com/' . $url,
        'video_id' => $url,
        'platform' => 'vimeo',
      ];
    }

    return NULL;
  }

  /**
   * Detects video IDs in fields based on naming conventions.
   *
   * This method identifies YouTube/Vimeo video IDs stored in fields that
   * follow naming conventions (e.g., field_youtube_id, field_vimeo_video).
   * It constructs full URLs from the video IDs for tracking.
   *
   * @param string $value
   *   The field value to check.
   * @param string $field_name
   *   The field machine name.
   * @param string $table_name
   *   The database table name (includes entity type prefix).
   *
   * @return array|null
   *   Array with 'url' and 'asset_type' if a video ID is detected, NULL otherwise.
   */
  protected function detectVideoIdFromFieldName($value, $field_name, $table_name) {
    // Skip empty or very long values (video IDs are short).
    $value = trim($value);
    if (empty($value) || strlen($value) > 20) {
      return NULL;
    }

    // Keywords that indicate a YouTube video ID field.
    $youtube_keywords = ['youtube', 'yt_id', 'ytid', 'youtube_id', 'youtubeid'];

    // Keywords that indicate a Vimeo video ID field.
    $vimeo_keywords = ['vimeo', 'vimeo_id', 'vimeoid'];

    // Generic video ID keywords (could be YouTube or Vimeo).
    $generic_video_keywords = ['video_id', 'videoid'];

    // Combine field name and table name for checking.
    $context = strtolower($field_name . ' ' . $table_name);

    // Check for YouTube.
    foreach ($youtube_keywords as $keyword) {
      if (strpos($context, $keyword) !== FALSE) {
        // Validate YouTube video ID format: 11 characters, alphanumeric with - and _.
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $value)) {
          return [
            'url' => 'https://www.youtube.com/watch?v=' . $value,
            'asset_type' => 'youtube',
          ];
        }
      }
    }

    // Check for Vimeo.
    foreach ($vimeo_keywords as $keyword) {
      if (strpos($context, $keyword) !== FALSE) {
        // Validate Vimeo video ID format: numeric only.
        if (preg_match('/^\d+$/', $value)) {
          return [
            'url' => 'https://vimeo.com/' . $value,
            'asset_type' => 'vimeo',
          ];
        }
      }
    }

    // Check for generic video ID keywords.
    foreach ($generic_video_keywords as $keyword) {
      if (strpos($context, $keyword) !== FALSE) {
        // Try YouTube format first (more common).
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $value)) {
          return [
            'url' => 'https://www.youtube.com/watch?v=' . $value,
            'asset_type' => 'youtube',
          ];
        }
        // Try Vimeo format.
        if (preg_match('/^\d+$/', $value)) {
          return [
            'url' => 'https://vimeo.com/' . $value,
            'asset_type' => 'vimeo',
          ];
        }
      }
    }

    return NULL;
  }

  /**
   * Extracts local file URLs from text content.
   *
   * @param string $text
   *   The text to scan for local file URLs.
   * @param string $tag
   *   The HTML tag to match ('a' for links, 'img' for images, 'embed' for
   *   embeds, 'object' for objects). Defaults to 'a'.
   *
   * @return array
   *   Array of unique file URIs found (as public:// or private:// streams).
   */
  protected function extractLocalFileUrls(string $text, string $tag = 'a'): array {
    $uris = [];

    // Supported tags: 'a' (href), 'img' (src), 'embed' (src), 'object' (data).
    // <source src> and <track src> inside video/audio tags are handled
    // by extractHtml5MediaEmbeds() to avoid duplication.

    $attr_map = ['a' => 'href', 'object' => 'data'];
    $attr = $attr_map[$tag] ?? 'src';

    // Decode entities so href/src parsing is reliable.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Extract attribute values and let urlPathToStreamUri() handle
    // public (universal + dynamic fallback) and private (/system/files/)
    // conversion in one place.
    $pattern = '#<' . preg_quote($tag, '#') . '\b[^>]*\b' . preg_quote($attr, '#') . '\s*=\s*["\']([^"\']+)["\']#i';

    if (preg_match_all($pattern, $text, $matches)) {
      foreach ($matches[1] as $value) {
        // Trim wrappers and strip query/fragment early.
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $value = preg_replace('/[?#].*$/', '', $value);

        // Convert to stream URI (public:// or private://) if local.
        if ($uri = $this->urlPathToStreamUri($value)) {
          $uris[$uri] = $uri;
        }
      }
    }

    return array_values($uris);
  }

  /**
   * Extracts URLs from iframe src attributes in text content.
   *
   * @param string $text
   *   The text to scan for iframe tags.
   *
   * @return array
   *   Array of unique URLs found in iframe src attributes.
   */
  protected function extractIframeUrls($text) {
    $urls = [];

    // Pattern to match iframe src attributes.
    // Handles various quote styles and whitespace.
    $pattern = '/<iframe[^>]+src\s*=\s*["\']([^"\']+)["\']/i';

    if (preg_match_all($pattern, $text, $matches)) {
      foreach ($matches[1] as $url) {
        // Decode HTML entities (e.g., &amp; -> &).
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        // Normalize the URL.
        $url = trim($url);
        if (!empty($url)) {
          $urls[$url] = $url;
        }
      }
    }

    return array_values($urls);
  }

  /**
   * Extracts embedded media UUIDs from text content.
   *
   * @param string $text
   *   The text to scan for drupal-media tags.
   *
   * @return array
   *   Array of media UUIDs found.
   */
  protected function extractMediaUuids($text) {
    $uuids = [];

    // Pattern to match <drupal-media data-entity-uuid="..."> tags.
    $pattern = '/<drupal-media[^>]+data-entity-uuid="([a-f0-9\-]+)"[^>]*>/i';

    if (preg_match_all($pattern, $text, $matches)) {
      $uuids = array_unique($matches[1]);
    }

    return $uuids;
  }

  /**
   * Extracts HTML5 video and audio embeds from text content.
   *
   * Parses <video> and <audio> tags to extract:
   * - Source URLs from src attribute and <source> elements
   * - Caption/subtitle URLs from <track> elements
   * - Accessibility signals (controls, autoplay, muted, loop)
   *
   * @param string $text
   *   The text to scan for HTML5 media tags.
   *
   * @return array
   *   Array of media embeds, each with keys:
   *   - type: 'video' or 'audio'
   *   - sources: array of source URLs
   *   - tracks: array of track info (url, kind, srclang, label)
   *   - poster: poster image URL (video only)
   *   - signals: accessibility signals (controls, autoplay, muted, loop)
   *   - raw_html: the original HTML tag for signal detection
   */
  protected function extractHtml5MediaEmbeds(string $text): array {
    $embeds = [];

    // Decode entities so src parsing is reliable.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // De-dupe by normalized source key (type:source1|source2).
    $seen = [];

    // Full video tags.
    if (preg_match_all('/<video[^>]*>.*?<\/video>/is', $text, $video_matches)) {
      foreach ($video_matches[0] as $video_html) {
        $embed = $this->parseHtml5MediaTag($video_html, 'video');
        if (!empty($embed['sources'])) {
          $key = 'video:' . implode('|', $embed['sources']);
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $embeds[] = $embed;
          }
        }
      }
    }

    // Self-closing video tags.
    if (preg_match_all('/<video[^>]+src=["\']([^"\']+)["\'][^>]*\/?>/i', $text, $self_closing_videos)) {
      foreach ($self_closing_videos[0] as $video_html) {
        $embed = $this->parseHtml5MediaTag($video_html, 'video');
        if (!empty($embed['sources'])) {
          $key = 'video:' . implode('|', $embed['sources']);
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $embeds[] = $embed;
          }
        }
      }
    }

    // Full audio tags.
    if (preg_match_all('/<audio[^>]*>.*?<\/audio>/is', $text, $audio_matches)) {
      foreach ($audio_matches[0] as $audio_html) {
        $embed = $this->parseHtml5MediaTag($audio_html, 'audio');
        if (!empty($embed['sources'])) {
          $key = 'audio:' . implode('|', $embed['sources']);
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $embeds[] = $embed;
          }
        }
      }
    }

    // Self-closing audio tags.
    if (preg_match_all('/<audio[^>]+src=["\']([^"\']+)["\'][^>]*\/?>/i', $text, $self_closing_audios)) {
      foreach ($self_closing_audios[0] as $audio_html) {
        $embed = $this->parseHtml5MediaTag($audio_html, 'audio');
        if (!empty($embed['sources'])) {
          $key = 'audio:' . implode('|', $embed['sources']);
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $embeds[] = $embed;
          }
        }
      }
    }

    return $embeds;
  }

  /**
   * Parses an HTML5 media tag to extract sources, tracks, and signals.
   *
   * Decodes entities once at the top so attribute parsing is reliable.
   * All URLs are normalized via cleanMediaUrl() (decode+trim), then
   * query/fragment stripped, then optionally converted to stream URIs
   * via urlPathToStreamUri() for consistent multisite handling.
   *
   * @param string $html
   *   The HTML tag content.
   * @param string $type
   *   The media type ('video' or 'audio').
   *
   * @return array
   *   Parsed media embed data with normalized sources and track URLs.
   */
  protected function parseHtml5MediaTag(string $html, string $type): array {
    // Decode entities so attribute parsing is reliable (&amp;, &quot;, etc.).
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $embed = [
      'type' => $type,
      'sources' => [],
      'tracks' => [],
      'poster' => NULL,
      'signals' => [
        'controls' => FALSE,
        'autoplay' => FALSE,
        'muted' => FALSE,
        'loop' => FALSE,
      ],
      'raw_html' => $html,
    ];

    // Normalize a media URL: decode+trim, strip query/fragment, convert
    // local paths to stream URIs when possible.
    $normalize = function (string $value): string {
      // Decode entities + trim (delegated to existing helper).
      $value = $this->cleanMediaUrl($value);
      // Strip query strings and fragments.
      $value = preg_replace('/[?#].*$/', '', $value);
      // Convert local URLs to stream URIs when possible.
      $uri = $this->urlPathToStreamUri($value);
      return $uri ?: $value;
    };

    // Collect unique sources via associative keys.
    $source_set = [];

    // Extract src attribute from main <video>/<audio> tag.
    if (preg_match('/<' . preg_quote($type, '/') . '[^>]+\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
      $src = $normalize($m[1]);
      $source_set[$src] = TRUE;
    }

    // Extract <source src="..."> elements.
    if (preg_match_all('/<source[^>]+\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
      foreach ($m[1] as $src) {
        $src = $normalize($src);
        $source_set[$src] = TRUE;
      }
    }

    $embed['sources'] = array_keys($source_set);

    // Extract <track ...> elements (video only, but safe for audio too).
    // Match both <track ...> and <track .../> formats.
    if (preg_match_all('/<track\s+([^>]*?)\/?>/i', $html, $track_matches)) {
      foreach ($track_matches[1] as $track_attrs) {
        $track = [
          'url' => NULL,
          'kind' => 'subtitles',
          'srclang' => NULL,
          'label' => NULL,
        ];

        // src can be quoted or unquoted. No &quot; fallback needed since
        // entities are decoded at the top.
        if (preg_match('/\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $track_attrs, $m)) {
          $src_value = $m[1] ?? $m[2] ?? $m[3] ?? '';
          if ($src_value !== '') {
            $track['url'] = $normalize($src_value);
          }
        }

        if (preg_match('/\bkind\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $track_attrs, $m)) {
          $track['kind'] = $m[1] ?? $m[2] ?? $m[3] ?? $track['kind'];
        }
        if (preg_match('/\bsrclang\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $track_attrs, $m)) {
          $track['srclang'] = $m[1] ?? $m[2] ?? $m[3] ?? NULL;
        }
        if (preg_match('/\blabel\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $track_attrs, $m)) {
          $track['label'] = $m[1] ?? $m[2] ?? $m[3] ?? NULL;
        }

        if ($track['url']) {
          $embed['tracks'][] = $track;
          $this->logger->debug('Found track element: url=@url, kind=@kind, label=@label', [
            '@url' => $track['url'],
            '@kind' => $track['kind'],
            '@label' => $track['label'] ?? 'none',
          ]);
        }
      }
    }

    // Extract poster attribute (video only).
    if ($type === 'video' && preg_match('/\bposter\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
      $embed['poster'] = $normalize($m[1]);
    }

    // Extract boolean attributes (signals).
    $embed['signals']['controls'] = (bool) preg_match('/<' . preg_quote($type, '/') . '\b[^>]*\bcontrols\b/i', $html);
    $embed['signals']['autoplay'] = (bool) preg_match('/<' . preg_quote($type, '/') . '\b[^>]*\bautoplay\b/i', $html);
    $embed['signals']['muted'] = (bool) preg_match('/<' . preg_quote($type, '/') . '\b[^>]*\bmuted\b/i', $html);
    $embed['signals']['loop'] = (bool) preg_match('/<' . preg_quote($type, '/') . '\b[^>]*\bloop\b/i', $html);

    return $embed;
  }

  /**
   * Cleans a media URL for matching and normalization.
   *
   * Decodes HTML entities and trims whitespace. Query strings and fragments
   * are intentionally NOT removed here — that responsibility belongs to
   * the caller or the normalization pipeline in parseHtml5MediaTag().
   *
   * @param string $url
   *   The URL to clean.
   *
   * @return string
   *   The cleaned URL (entities decoded, whitespace trimmed).
   */
  protected function cleanMediaUrl($url) {
    $url = html_entity_decode($url);
    $url = trim($url);

    return $url;
  }

  /**
   * Resolves a relative URL to an absolute URL.
   *
   * @param string $url
   *   The URL to resolve.
   * @param string|null $base_url
   *   The base URL to resolve against.
   *
   * @return string
   *   The resolved absolute URL.
   */
  protected function resolveMediaUrl($url, $base_url = NULL) {
    // Already absolute.
    if (parse_url($url, PHP_URL_SCHEME)) {
      return $url;
    }

    // Protocol-relative.
    if (strpos($url, '//') === 0) {
      return 'https:' . $url;
    }

    // Get base URL from config or request.
    if (!$base_url) {
      $base_url = \Drupal::request()->getSchemeAndHttpHost();
    }

    // Root-relative.
    if (strpos($url, '/') === 0) {
      return $base_url . $url;
    }

    // Relative (less common in CKEditor content).
    return $base_url . '/' . $url;
  }

  /**
   * Converts a URL to a Drupal stream URI if it's a local file.
   *
   * @param string $url
   *   The URL to convert.
   *
   * @return string|null
   *   The stream URI (public:// or private://) or NULL if external.
   */
  protected function urlToStreamUri($url) {
    return $this->urlPathToStreamUri($url);
  }

  /**
   * Gets sort order for a category.
   *
   * @param string $category
   *   The category name.
   *
   * @return int
   *   Sort order: Documents=1, Videos=2, Audio=3, Google Workspace=4,
   *   Document Services=5, Forms & Surveys=6, Education Platforms=7,
   *   Embedded Media=8, Images=9, Other=10, Unknown=99.
   */
  protected function getCategorySortOrder($category) {
    $order_map = [
      'Documents' => 1,
      'Videos' => 2,
      'Audio' => 3,
      'Google Workspace' => 4,
      'Document Services' => 5,
      'Forms & Surveys' => 6,
      'Education Platforms' => 7,
      'Embedded Media' => 8,
      'Images' => 9,
      'Other' => 10,
    ];

    return $order_map[$category] ?? 99;
  }

  /**
   * Gets all field tables that should be scanned for external URLs.
   *
   * @return array
   *   Array of table info with keys: table, column, entity_type, field_name.
   */
  protected function getFieldTablesToScan() {
    $tables = [];

    // Get all tables in the database.
    $db_schema = $this->database->schema();

    // Scan for text/long text field tables (node__, paragraph__, etc.).
    $prefixes = ['node__', 'paragraph__', 'taxonomy_term__', 'block_content__'];

    foreach ($prefixes as $prefix) {
      // Find all tables with this prefix.
      $all_tables = $this->database->query("SHOW TABLES LIKE '{$prefix}%'")->fetchCol();

      foreach ($all_tables as $table) {
        // Check if table has a _value column (text field).
        $field_name = str_replace($prefix, '', $table);
        $value_column = $field_name . '_value';

        if ($db_schema->fieldExists($table, $value_column)) {
          // Extract entity type properly - remove the trailing "__".
          $entity_type = str_replace('__', '', $prefix);
          $tables[] = [
            'table' => $table,
            'column' => $value_column,
            'entity_type' => $entity_type,
            'field_name' => $field_name,
            'type' => 'text',
          ];
        }

        // Check if table has a _uri column (link field).
        $uri_column = $field_name . '_uri';
        if ($db_schema->fieldExists($table, $uri_column)) {
          // Extract entity type properly - remove the trailing "__".
          $entity_type = str_replace('__', '', $prefix);
          $tables[] = [
            'table' => $table,
            'column' => $uri_column,
            'entity_type' => $entity_type,
            'field_name' => $field_name,
            'type' => 'link',
          ];
        }
      }
    }

    return $tables;
  }

  /**
   * Gets count of content entities to scan for external URLs.
   *
   * @return int
   *   The number of content entities.
   */
  public function getContentEntitiesCount() {
    // Get all field tables to scan.
    $tables = $this->getFieldTablesToScan();

    if (empty($tables)) {
      return 0;
    }

    // Count unique entity IDs across all tables.
    $entity_ids = [];
    foreach ($tables as $table_info) {
      $results = $this->database->select($table_info['table'], 't')
        ->fields('t', ['entity_id'])
        ->execute();

      foreach ($results as $row) {
        $entity_ids[$row->entity_id] = TRUE;
      }
    }

    return count($entity_ids);
  }

  /**
   * Scans a chunk of content entities for external URLs.
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of entities to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Number of items processed.
   */
  public function scanContentChunk($offset, $limit, $is_temp = FALSE) {
    $count = 0;
    $asset_storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    // Get all field tables to scan.
    $tables = $this->getFieldTablesToScan();

    if (empty($tables)) {
      return 0;
    }

    // Scan each table for URLs.
    foreach ($tables as $table_info) {
      $query = $this->database->select($table_info['table'], 't');
      $query->fields('t', ['entity_id', $table_info['column']]);
      $query->range($offset, $limit);
      $results = $query->execute();

      foreach ($results as $row) {
        $entity_id = $row->entity_id;
        $field_value = $row->{$table_info['column']};

        // NOTE: We no longer scan for embedded media (<drupal-media>) here
        // because entity_usage module already tracks media references.
        // Scanning here would create duplicate usage records.
        // Extract or get URLs based on field type.
        $urls = [];
        // Track URLs found in iframes to exclude from general text_url processing.
        $iframe_urls = [];
        if ($table_info['type'] === 'text') {
          // Extract iframe URLs first - these get 'inline_iframe' embed method.
          $iframe_urls = $this->extractIframeUrls($field_value);

          // Text field - extract URLs from HTML/text.
          $urls = $this->extractUrls($field_value);

          // Remove iframe URLs from general URL list to avoid duplicates.
          // Iframe URLs will be processed separately with inline_iframe embed method.
          if (!empty($iframe_urls)) {
            $urls = array_filter($urls, function ($url) use ($iframe_urls) {
              // Normalize URLs for comparison (handle HTML entity encoding differences).
              $normalized_url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
              foreach ($iframe_urls as $iframe_url) {
                // Check if the URL matches (with or without query params).
                $normalized_iframe = html_entity_decode($iframe_url, ENT_QUOTES, 'UTF-8');
                // Strip query params for comparison since extractUrls may get different variations.
                $url_base = preg_replace('/[?#].*$/', '', $normalized_url);
                $iframe_base = preg_replace('/[?#].*$/', '', $normalized_iframe);
                if ($url_base === $iframe_base || $normalized_url === $normalized_iframe) {
                  return FALSE;
                }
              }
              return TRUE;
            });
          }

          // Also scan for HTML5 video/audio embeds.
          $html5_embeds = $this->extractHtml5MediaEmbeds($field_value);
          if (!empty($html5_embeds)) {
            $this->logger->debug('HTML5 embeds found in @table entity @id: @count embeds', [
              '@table' => $table_info['table'],
              '@id' => $entity_id,
              '@count' => count($html5_embeds),
            ]);
          }
          foreach ($html5_embeds as $embed) {
            $this->logger->debug('Processing HTML5 @type: sources=@sources, tracks=@tracks', [
              '@type' => $embed['type'],
              '@sources' => implode(', ', $embed['sources']),
              '@tracks' => count($embed['tracks']),
            ]);
            $count += $this->processHtml5MediaEmbed(
              $embed,
              $table_info,
              $entity_id,
              $is_temp,
              $asset_storage,
              $usage_storage
            );
          }

          // Also scan for local file links (<a href="/sites/default/files/...">, etc.)
          $local_uris = $this->extractLocalFileUrls($field_value);
          if (!empty($local_uris)) {
            $this->logger->debug('Local file links found in @table entity @id: @uris', [
              '@table' => $table_info['table'],
              '@id' => $entity_id,
              '@uris' => implode(', ', $local_uris),
            ]);
          }
          foreach ($local_uris as $uri) {
            $count += $this->processLocalFileLink(
              $uri,
              $table_info,
              $entity_id,
              $is_temp,
              $asset_storage,
              $usage_storage
            );
          }

          // Scan for inline images (<img src="/sites/default/files/...">, etc.)
          $inline_image_uris = $this->extractLocalFileUrls($field_value, 'img');
          if (!empty($inline_image_uris)) {
            $this->logger->debug('Inline images found in @table entity @id: @uris', [
              '@table' => $table_info['table'],
              '@id' => $entity_id,
              '@uris' => implode(', ', $inline_image_uris),
            ]);
          }
          foreach ($inline_image_uris as $uri) {
            $count += $this->processLocalFileLink(
              $uri,
              $table_info,
              $entity_id,
              $is_temp,
              $asset_storage,
              $usage_storage,
              'inline_image'
            );
          }

          // Scan for legacy embeds (<object data="...">, <embed src="...">, etc.)
          $legacy_embed_tags = [
            'object' => 'inline_object',
            'embed' => 'inline_embed',
          ];
          foreach ($legacy_embed_tags as $legacy_tag => $legacy_method) {
            $legacy_uris = $this->extractLocalFileUrls($field_value, $legacy_tag);
            if (!empty($legacy_uris)) {
              $this->logger->debug('Legacy @tag embeds found in @table entity @id: @uris', [
                '@tag' => $legacy_tag,
                '@table' => $table_info['table'],
                '@id' => $entity_id,
                '@uris' => implode(', ', $legacy_uris),
              ]);
            }
            foreach ($legacy_uris as $uri) {
              $count += $this->processLocalFileLink(
                $uri,
                $table_info,
                $entity_id,
                $is_temp,
                $asset_storage,
                $usage_storage,
                $legacy_method
              );
            }
          }

          // Process iframe URLs with inline_iframe embed method.
          // These were extracted earlier and excluded from the general $urls array.
          if (!empty($iframe_urls)) {
            $this->logger->debug('Iframe embeds found in @table entity @id: @urls', [
              '@table' => $table_info['table'],
              '@id' => $entity_id,
              '@urls' => implode(', ', $iframe_urls),
            ]);
            foreach ($iframe_urls as $iframe_url) {
              $count += $this->processExternalUrl(
                $iframe_url,
                $table_info,
                $entity_id,
                $is_temp,
                $asset_storage,
                $usage_storage,
                'inline_iframe'
              );
            }
          }
        }
        elseif ($table_info['type'] === 'link') {
          // Link field - the value IS the URL.
          if (!empty($field_value) && (strpos($field_value, 'http://') === 0 || strpos($field_value, 'https://') === 0)) {
            $urls = [$field_value];
          }
        }

        // Check for video IDs based on field naming conventions.
        // This catches fields like field_youtube_id that store just the video ID.
        $video_id_info = $this->detectVideoIdFromFieldName(
          $field_value,
          $table_info['field_name'],
          $table_info['table']
        );
        if ($video_id_info) {
          // Add the constructed URL to the list for processing.
          // The asset_type is already known, so we'll handle it specially.
          $urls[] = $video_id_info['url'];
          $this->logger->debug('Video ID detected in @field: @value -> @url', [
            '@field' => $table_info['field_name'],
            '@value' => $field_value,
            '@url' => $video_id_info['url'],
          ]);
        }

        foreach ($urls as $url) {
          // Match URL to asset type.
          $asset_type = $this->matchUrlToAssetType($url);

          // Only process URLs that match known patterns (not 'other').
          if ($asset_type === 'other') {
            continue;
          }

          // Normalize video URLs for consistent tracking.
          // This ensures the same video is tracked as one asset regardless of URL format.
          $display_url = $url;
          $normalized = $this->normalizeVideoUrl($url);
          if ($normalized) {
            // Use canonical URL for hashing and storage.
            $url = $normalized['url'];
            // Update asset type based on detected platform.
            $asset_type = $normalized['platform'];
          }

          // Determine category and sort order.
          $category = $this->mapAssetTypeToCategory($asset_type);
          $sort_order = $this->getCategorySortOrder($category);

          // Create URL hash for uniqueness using normalized URL.
          $url_hash = md5($url);

          // Check if TEMP asset already exists by url_hash.
          // Only update temp items - never modify permanent items during scan.
          $existing_query = $asset_storage->getQuery();
          $existing_query->condition('url_hash', $url_hash);
          $existing_query->condition('source_type', 'external');
          $existing_query->condition('is_temp', TRUE);
          $existing_query->accessCheck(FALSE);
          $existing_ids = $existing_query->execute();

          if ($existing_ids) {
            // Temp asset exists - reuse it.
            $asset_id = reset($existing_ids);
          }
          else {
            // Create new external asset.
            $config = $this->configFactory->get('digital_asset_inventory.settings');
            $asset_types_config = $config->get('asset_types');
            $label = $asset_types_config[$asset_type]['label'] ?? $asset_type;

            $asset = $asset_storage->create([
              'source_type' => 'external',
              'url_hash' => $url_hash,
              'asset_type' => $asset_type,
              'category' => $category,
              'sort_order' => $sort_order,
              'file_path' => $url,
              'file_name' => $label,
              'mime_type' => $label,
              'filesize' => 0,
              'is_temp' => $is_temp,
            ]);
            $asset->save();
            $asset_id = $asset->id();
          }

          // Determine parent entity for paragraphs.
          $parent_entity_type = $table_info['entity_type'];
          $parent_entity_id = $entity_id;

          if ($parent_entity_type === 'paragraph') {
            // Get parent node from paragraph.
            $parent_info = $this->getParentFromParagraph($entity_id);
            if ($parent_info) {
              $parent_entity_type = $parent_info['type'];
              $parent_entity_id = $parent_info['id'];
            }
          }

          // Track usage - check if usage record exists.
          $usage_query = $usage_storage->getQuery();
          $usage_query->condition('asset_id', $asset_id);
          $usage_query->condition('entity_type', $parent_entity_type);
          $usage_query->condition('entity_id', $parent_entity_id);
          $usage_query->condition('field_name', $table_info['field_name']);
          $usage_query->accessCheck(FALSE);
          $usage_ids = $usage_query->execute();

          if (!$usage_ids) {
            // Determine embed method based on how the URL was found.
            $url_embed_method = ($table_info['type'] === 'link') ? 'link_field' : 'text_url';

            // Create usage tracking record.
            $usage_storage->create([
              'asset_id' => $asset_id,
              'entity_type' => $parent_entity_type,
              'entity_id' => $parent_entity_id,
              'field_name' => $table_info['field_name'],
              'count' => 1,
              'embed_method' => $url_embed_method,
            ])->save();
          }

          // Update CSV export fields for external assets.
          // External assets have no filesize (0).
          $this->updateCsvExportFields($asset_id, 0);

          $count++;
        }
      }
    }

    return $count;
  }

  /**
   * Processes a single HTML5 media embed, creating assets and usage records.
   *
   * @param array $embed
   *   The parsed embed data from extractHtml5MediaEmbeds().
   * @param array $table_info
   *   Table information (entity_type, field_name, etc.).
   * @param int $entity_id
   *   The entity ID where the embed was found.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   * @param object $usage_storage
   *   The usage entity storage.
   *
   * @return int
   *   Number of assets processed.
   */
  protected function processHtml5MediaEmbed(array $embed, array $table_info, $entity_id, $is_temp, $asset_storage, $usage_storage) {
    $count = 0;
    $embed_method = $embed['type'] === 'video' ? 'html5_video' : 'html5_audio';

    // Determine parent entity for paragraphs.
    $parent_entity_type = $table_info['entity_type'];
    $parent_entity_id = $entity_id;

    if ($parent_entity_type === 'paragraph') {
      $parent_info = $this->getParentFromParagraph($entity_id);
      if ($parent_info) {
        $parent_entity_type = $parent_info['type'];
        $parent_entity_id = $parent_info['id'];
      }
      else {
        // Paragraph is orphaned (from previous revision) - skip this embed.
        return 0;
      }
    }

    // Process each source URL in the embed.
    foreach ($embed['sources'] as $source_url) {
      // Resolve relative URLs.
      $absolute_url = $this->resolveMediaUrl($source_url);

      // Check if this is a local file.
      $stream_uri = $this->urlToStreamUri($absolute_url);

      if ($stream_uri) {
        // Local file - try to link to existing asset or create filesystem_only.
        $asset_id = $this->findOrCreateLocalAssetForHtml5($stream_uri, $absolute_url, $embed, $is_temp, $asset_storage);
      }
      else {
        // External URL - create external asset.
        $asset_id = $this->findOrCreateExternalAssetForHtml5($absolute_url, $embed, $is_temp, $asset_storage);
      }

      if (!$asset_id) {
        continue;
      }

      // Create usage record with embed method and signals.
      $this->createHtml5UsageRecord(
        $asset_id,
        $parent_entity_type,
        $parent_entity_id,
        $table_info['field_name'],
        $embed_method,
        $embed['signals'],
        $embed['tracks'],
        $usage_storage
      );

      $count++;
    }

    // Process track/caption files as separate assets.
    $this->logger->debug('Processing @count tracks for HTML5 embed', [
      '@count' => count($embed['tracks']),
    ]);
    foreach ($embed['tracks'] as $track) {
      if (!$track['url']) {
        $this->logger->debug('Skipping track with no URL');
        continue;
      }

      $track_url = $this->resolveMediaUrl($track['url']);
      $stream_uri = $this->urlToStreamUri($track_url);

      $this->logger->debug('Track processing: original=@orig, resolved=@resolved, stream_uri=@stream', [
        '@orig' => $track['url'],
        '@resolved' => $track_url,
        '@stream' => $stream_uri ?: 'NULL (external)',
      ]);

      if ($stream_uri) {
        $asset_id = $this->findOrCreateCaptionAsset($stream_uri, $track_url, $track, $is_temp, $asset_storage);
        $this->logger->debug('Caption asset @action: @id', [
          '@action' => $asset_id ? 'found/created' : 'FAILED',
          '@id' => $asset_id ?: 'none',
        ]);
      }
      else {
        // External caption file (rare but possible).
        $asset_id = $this->findOrCreateExternalCaptionAsset($track_url, $track, $is_temp, $asset_storage);
      }

      if ($asset_id) {
        // Create usage record for caption file.
        $this->createHtml5UsageRecord(
          $asset_id,
          $parent_entity_type,
          $parent_entity_id,
          $table_info['field_name'],
          $embed_method,
          [],
          [],
          $usage_storage
        );
        $count++;
      }
    }

    return $count;
  }

  /**
   * Processes a local file link found in text content.
   *
   * Handles <a href="/sites/default/files/..."> and similar patterns.
   * Links the text link to existing assets and creates usage records.
   *
   * @param string $uri
   *   The Drupal stream URI (public:// or private://).
   * @param array $table_info
   *   Information about the source table/field.
   * @param int $entity_id
   *   The entity ID where the link was found.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   * @param object $usage_storage
   *   The usage entity storage.
   * @param string $embed_method
   *   The embed method (text_link, inline_image, etc.). Defaults to text_link.
   *
   * @return int
   *   1 if a usage was created/updated, 0 otherwise.
   */
  protected function processLocalFileLink($uri, array $table_info, $entity_id, $is_temp, $asset_storage, $usage_storage, $embed_method = 'text_link') {
    $this->logger->debug('Processing local file link: @uri in @table entity @id', [
      '@uri' => $uri,
      '@table' => $table_info['table'],
      '@id' => $entity_id,
    ]);

    // Determine parent entity for paragraphs.
    $parent_entity_type = $table_info['entity_type'];
    $parent_entity_id = $entity_id;

    if ($parent_entity_type === 'paragraph') {
      $parent_info = $this->getParentFromParagraph($entity_id);
      if ($parent_info) {
        $parent_entity_type = $parent_info['type'];
        $parent_entity_id = $parent_info['id'];
        $this->logger->debug('Local link traced to parent: @type @id', [
          '@type' => $parent_entity_type,
          '@id' => $parent_entity_id,
        ]);
      }
      else {
        // Paragraph is orphaned (from previous revision) - skip.
        $this->logger->debug('Local link skipped: orphaned paragraph @id', ['@id' => $entity_id]);
        return 0;
      }
    }

    // Check if file exists in file_managed.
    $file = NULL;
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
      $file = reset($files);
    }
    catch (\Exception $e) {
      // File not in file_managed.
    }

    // Find the asset - first try by fid if file exists.
    $asset_id = NULL;
    if ($file) {
      $this->logger->debug('Local link file found in file_managed: fid=@fid', ['@fid' => $file->id()]);
      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('fid', $file->id());
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        $asset_id = reset($existing_ids);
        $this->logger->debug('Local link asset found by fid: @id', ['@id' => $asset_id]);
      }
    }

    // If not found by fid, try by file_path.
    if (!$asset_id) {
      $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
      $url_hash = md5($absolute_url);

      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('url_hash', $url_hash);
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        $asset_id = reset($existing_ids);
      }
    }

    // If no asset found, the file might be on filesystem but not scanned yet.
    // Create a filesystem_only asset.
    if (!$asset_id) {
      $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
      $real_path = $this->fileSystem->realpath($uri);

      if (!$real_path || !file_exists($real_path)) {
        // File doesn't exist - skip.
        return 0;
      }

      // Get file info.
      $filesize = filesize($real_path);
      $filename = basename($uri);
      $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

      // Determine MIME type.
      $mime_type = $this->getMimeTypeFromExtension($extension);
      $asset_type = $this->mapMimeToAssetType($mime_type);
      $category = $this->mapAssetTypeToCategory($asset_type);
      $sort_order = $this->getCategorySortOrder($category);

      // Check if private.
      $is_private = strpos($uri, 'private://') === 0;

      // Create the asset.
      $asset = $asset_storage->create([
        'fid' => $file ? $file->id() : NULL,
        'source_type' => $file ? 'file_managed' : 'filesystem_only',
        'url_hash' => md5($absolute_url),
        'asset_type' => $asset_type,
        'category' => $category,
        'sort_order' => $sort_order,
        'file_path' => $absolute_url,
        'file_name' => $filename,
        'mime_type' => $mime_type,
        'filesize' => $filesize,
        'is_temp' => $is_temp,
        'is_private' => $is_private,
      ]);
      $asset->save();

      // Update CSV export fields.
      $this->updateCsvExportFields($asset->id(), $filesize);

      $asset_id = $asset->id();
    }

    if (!$asset_id) {
      $this->logger->debug('Local link: no asset found for @uri', ['@uri' => $uri]);
      return 0;
    }

    $this->logger->debug('Local link creating usage: asset=@asset, parent=@type/@id, field=@field', [
      '@asset' => $asset_id,
      '@type' => $parent_entity_type,
      '@id' => $parent_entity_id,
      '@field' => $table_info['field_name'],
    ]);

    // Create usage record with appropriate embed_method.
    $this->createHtml5UsageRecord(
      $asset_id,
      $parent_entity_type,
      $parent_entity_id,
      $table_info['field_name'],
      $embed_method,
      [],
      [],
      $usage_storage
    );

    return 1;
  }

  /**
   * Processes an external URL, creating asset and usage records.
   *
   * @param string $url
   *   The external URL to process.
   * @param array $table_info
   *   Table information (entity_type, field_name, etc.).
   * @param int $entity_id
   *   The entity ID where the URL was found.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   * @param object $usage_storage
   *   The usage entity storage.
   * @param string $embed_method
   *   The embed method (e.g., 'inline_iframe', 'text_url', 'link_field').
   *
   * @return int
   *   1 if asset/usage was created, 0 otherwise.
   */
  protected function processExternalUrl($url, array $table_info, $entity_id, $is_temp, $asset_storage, $usage_storage, $embed_method = 'text_url') {
    // Match URL to asset type.
    $asset_type = $this->matchUrlToAssetType($url);

    // Only process URLs that match known patterns (not 'other').
    if ($asset_type === 'other') {
      return 0;
    }

    // Normalize video URLs for consistent tracking.
    $normalized = $this->normalizeVideoUrl($url);
    if ($normalized) {
      $url = $normalized['url'];
      $asset_type = $normalized['platform'];
    }

    // Determine category and sort order.
    $category = $this->mapAssetTypeToCategory($asset_type);
    $sort_order = $this->getCategorySortOrder($category);

    // Create URL hash for uniqueness using normalized URL.
    $url_hash = md5($url);

    // Check if TEMP asset already exists by url_hash.
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('source_type', 'external');
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      $asset_id = reset($existing_ids);
    }
    else {
      // Create new external asset.
      $config = $this->configFactory->get('digital_asset_inventory.settings');
      $asset_types_config = $config->get('asset_types');
      $label = $asset_types_config[$asset_type]['label'] ?? $asset_type;

      $asset = $asset_storage->create([
        'source_type' => 'external',
        'url_hash' => $url_hash,
        'asset_type' => $asset_type,
        'category' => $category,
        'sort_order' => $sort_order,
        'file_path' => $url,
        'file_name' => $label,
        'mime_type' => $label,
        'filesize' => 0,
        'is_temp' => $is_temp,
      ]);
      $asset->save();
      $asset_id = $asset->id();
    }

    // Determine parent entity for paragraphs.
    $parent_entity_type = $table_info['entity_type'];
    $parent_entity_id = $entity_id;

    if ($parent_entity_type === 'paragraph') {
      $parent_info = $this->getParentFromParagraph($entity_id);
      if ($parent_info) {
        $parent_entity_type = $parent_info['type'];
        $parent_entity_id = $parent_info['id'];
      }
    }

    // Track usage - check if usage record exists.
    $usage_query = $usage_storage->getQuery();
    $usage_query->condition('asset_id', $asset_id);
    $usage_query->condition('entity_type', $parent_entity_type);
    $usage_query->condition('entity_id', $parent_entity_id);
    $usage_query->condition('field_name', $table_info['field_name']);
    $usage_query->accessCheck(FALSE);
    $usage_ids = $usage_query->execute();

    if (!$usage_ids) {
      // Create usage tracking record.
      $usage_storage->create([
        'asset_id' => $asset_id,
        'entity_type' => $parent_entity_type,
        'entity_id' => $parent_entity_id,
        'field_name' => $table_info['field_name'],
        'count' => 1,
        'embed_method' => $embed_method,
      ])->save();
    }

    // Update CSV export fields for external assets.
    $this->updateCsvExportFields($asset_id, 0);

    return 1;
  }

  /**
   * Gets MIME type from file extension.
   *
   * @param string $extension
   *   The file extension (without dot).
   *
   * @return string
   *   The MIME type.
   */
  protected function getMimeTypeFromExtension($extension) {
    $mime_map = [
      // Documents.
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'txt' => 'text/plain',
      'csv' => 'text/csv',
      'vtt' => 'text/vtt',
      'srt' => 'text/plain',
      // Images.
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'svg' => 'image/svg+xml',
      'webp' => 'image/webp',
      // Videos.
      'mp4' => 'video/mp4',
      'webm' => 'video/webm',
      'mov' => 'video/quicktime',
      'avi' => 'video/x-msvideo',
      // Audio.
      'mp3' => 'audio/mpeg',
      'wav' => 'audio/wav',
      'm4a' => 'audio/mp4',
      'ogg' => 'audio/ogg',
      // Archives.
      'zip' => 'application/zip',
      'tar' => 'application/x-tar',
      'gz' => 'application/gzip',
      '7z' => 'application/x-7z-compressed',
      'rar' => 'application/x-rar-compressed',
    ];

    return $mime_map[$extension] ?? 'application/octet-stream';
  }

  /**
   * Finds or creates a local asset for HTML5 media embed.
   *
   * @param string $stream_uri
   *   The Drupal stream URI (public:// or private://).
   * @param string $absolute_url
   *   The absolute URL of the file.
   * @param array $embed
   *   The parsed embed data.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   *
   * @return int|null
   *   The asset ID, or NULL if not found/created.
   */
  protected function findOrCreateLocalAssetForHtml5($stream_uri, $absolute_url, array $embed, $is_temp, $asset_storage) {
    $url_hash = md5($absolute_url);

    // Check if file exists in file_managed first.
    $file = NULL;
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $stream_uri]);
      $file = reset($files);
    }
    catch (\Exception $e) {
      // File not in file_managed.
    }

    // If file exists in file_managed, check if asset already exists by fid.
    // This links HTML5 embeds to existing inventory items from managed file scan.
    if ($file) {
      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('fid', $file->id());
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        return reset($existing_ids);
      }
    }

    // Check if asset already exists by url_hash (for filesystem_only files).
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      return reset($existing_ids);
    }

    // No existing asset found - create a new one.
    // This should only happen for filesystem_only files not yet in inventory.

    // Determine asset type from file extension.
    $extension = pathinfo($stream_uri, PATHINFO_EXTENSION);
    $asset_type = $this->mapExtensionToAssetType(strtolower($extension));
    $category = $embed['type'] === 'video' ? 'Videos' : 'Audio';
    $sort_order = $this->getCategorySortOrder($category);

    // Get filename.
    $filename = basename($stream_uri);

    // Determine source type.
    $source_type = $file ? 'file_managed' : 'filesystem_only';

    // Get file size and MIME type.
    $filesize = 0;
    $mime_type = '';
    if ($file) {
      $filesize = $file->getSize() ?: 0;
      $mime_type = $file->getMimeType() ?: '';
    }
    else {
      // Try to get from filesystem.
      $real_path = $this->fileSystem->realpath($stream_uri);
      if ($real_path && file_exists($real_path)) {
        $filesize = filesize($real_path);
        $mime_type = mime_content_type($real_path) ?: '';
      }
    }

    // Check if file is private.
    $is_private = strpos($stream_uri, 'private://') === 0;

    // Create the asset.
    $asset = $asset_storage->create([
      'fid' => $file ? $file->id() : NULL,
      'source_type' => $source_type,
      'url_hash' => $url_hash,
      'asset_type' => $asset_type,
      'category' => $category,
      'sort_order' => $sort_order,
      'file_path' => $absolute_url,
      'file_name' => $filename,
      'mime_type' => $mime_type,
      'filesize' => $filesize,
      'is_temp' => $is_temp,
      'is_private' => $is_private,
    ]);
    $asset->save();

    // Update CSV export fields.
    $this->updateCsvExportFields($asset->id(), $filesize);

    return $asset->id();
  }

  /**
   * Finds or creates an external asset for HTML5 media embed.
   *
   * @param string $absolute_url
   *   The absolute URL of the external media.
   * @param array $embed
   *   The parsed embed data.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   *
   * @return int|null
   *   The asset ID, or NULL if not created.
   */
  protected function findOrCreateExternalAssetForHtml5($absolute_url, array $embed, $is_temp, $asset_storage) {
    $url_hash = md5($absolute_url);

    // Check if temp asset already exists.
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('source_type', 'external');
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      return reset($existing_ids);
    }

    // Determine category based on embed type.
    $category = $embed['type'] === 'video' ? 'Embedded Media' : 'Audio';
    $asset_type = $embed['type'] === 'video' ? 'external_video' : 'external_audio';
    $sort_order = $this->getCategorySortOrder($category);

    // Extract filename from URL.
    $parsed = parse_url($absolute_url);
    $filename = basename($parsed['path'] ?? $absolute_url);
    if (empty($filename) || $filename === '/') {
      $filename = $embed['type'] === 'video' ? 'External Video' : 'External Audio';
    }

    // Create the asset.
    $asset = $asset_storage->create([
      'source_type' => 'external',
      'url_hash' => $url_hash,
      'asset_type' => $asset_type,
      'category' => $category,
      'sort_order' => $sort_order,
      'file_path' => $absolute_url,
      'file_name' => $filename,
      'mime_type' => $embed['type'] . '/*',
      'filesize' => 0,
      'is_temp' => $is_temp,
      'is_private' => FALSE,
    ]);
    $asset->save();

    // Update CSV export fields.
    $this->updateCsvExportFields($asset->id(), 0);

    return $asset->id();
  }

  /**
   * Finds or creates a caption/subtitle file asset.
   *
   * @param string $stream_uri
   *   The Drupal stream URI.
   * @param string $absolute_url
   *   The absolute URL.
   * @param array $track
   *   Track info (kind, srclang, label).
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   *
   * @return int|null
   *   The asset ID, or NULL if not created.
   */
  protected function findOrCreateCaptionAsset($stream_uri, $absolute_url, array $track, $is_temp, $asset_storage) {
    $url_hash = md5($absolute_url);

    // Check if file exists in file_managed first.
    $file = NULL;
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $stream_uri]);
      $file = reset($files);
    }
    catch (\Exception $e) {
      // File not in file_managed.
    }

    // If file exists in file_managed, check if asset already exists by fid.
    if ($file) {
      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('fid', $file->id());
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        return reset($existing_ids);
      }
    }

    // Check if temp asset already exists by url_hash.
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      return reset($existing_ids);
    }

    // No existing asset found - create a new one.

    // Determine asset type from extension.
    $extension = strtolower(pathinfo($stream_uri, PATHINFO_EXTENSION));
    $asset_type = in_array($extension, ['vtt', 'srt']) ? $extension : 'text';
    $category = 'Documents';
    $sort_order = $this->getCategorySortOrder($category);

    // Get filename with language context.
    $filename = basename($stream_uri);
    if ($track['label']) {
      $filename .= ' (' . $track['label'] . ')';
    }
    elseif ($track['srclang']) {
      $filename .= ' (' . strtoupper($track['srclang']) . ')';
    }

    $source_type = $file ? 'file_managed' : 'filesystem_only';

    // Get file info.
    $filesize = 0;
    $mime_type = 'text/plain';
    if ($file) {
      $filesize = $file->getSize() ?: 0;
      $mime_type = $file->getMimeType() ?: 'text/plain';
    }
    else {
      $real_path = $this->fileSystem->realpath($stream_uri);
      if ($real_path && file_exists($real_path)) {
        $filesize = filesize($real_path);
      }
    }

    // Check if private.
    $is_private = strpos($stream_uri, 'private://') === 0;

    // Create the asset.
    $asset = $asset_storage->create([
      'fid' => $file ? $file->id() : NULL,
      'source_type' => $source_type,
      'url_hash' => $url_hash,
      'asset_type' => $asset_type,
      'category' => $category,
      'sort_order' => $sort_order,
      'file_path' => $absolute_url,
      'file_name' => $filename,
      'mime_type' => $mime_type,
      'filesize' => $filesize,
      'is_temp' => $is_temp,
      'is_private' => $is_private,
    ]);
    $asset->save();

    // Update CSV export fields.
    $this->updateCsvExportFields($asset->id(), $filesize);

    return $asset->id();
  }

  /**
   * Finds or creates an external caption file asset.
   *
   * @param string $absolute_url
   *   The absolute URL.
   * @param array $track
   *   Track info.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   *
   * @return int|null
   *   The asset ID.
   */
  protected function findOrCreateExternalCaptionAsset($absolute_url, array $track, $is_temp, $asset_storage) {
    $url_hash = md5($absolute_url);

    // Check if temp asset already exists.
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('source_type', 'external');
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      return reset($existing_ids);
    }

    // Determine asset type.
    $extension = strtolower(pathinfo(parse_url($absolute_url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $asset_type = in_array($extension, ['vtt', 'srt']) ? $extension : 'text';

    // Get filename.
    $filename = basename(parse_url($absolute_url, PHP_URL_PATH));
    if ($track['label']) {
      $filename .= ' (' . $track['label'] . ')';
    }

    // Create the asset.
    $asset = $asset_storage->create([
      'source_type' => 'external',
      'url_hash' => $url_hash,
      'asset_type' => $asset_type,
      'category' => 'Documents',
      'sort_order' => $this->getCategorySortOrder('Documents'),
      'file_path' => $absolute_url,
      'file_name' => $filename,
      'mime_type' => 'text/plain',
      'filesize' => 0,
      'is_temp' => $is_temp,
      'is_private' => FALSE,
    ]);
    $asset->save();

    $this->updateCsvExportFields($asset->id(), 0);

    return $asset->id();
  }

  /**
   * Creates a usage record for HTML5 media embed with signals.
   *
   * @param int $asset_id
   *   The asset ID.
   * @param string $entity_type
   *   The entity type.
   * @param int $entity_id
   *   The entity ID.
   * @param string $field_name
   *   The field name.
   * @param string $embed_method
   *   The embed method (html5_video, html5_audio).
   * @param array $signals
   *   Accessibility signals (controls, autoplay, muted, loop).
   * @param array $tracks
   *   Track elements found (for captions signal).
   * @param object $usage_storage
   *   The usage entity storage.
   */
  protected function createHtml5UsageRecord($asset_id, $entity_type, $entity_id, $field_name, $embed_method, array $signals, array $tracks, $usage_storage) {
    // Always create a new usage record for each embed.
    // Each usage is tracked separately, even if same asset appears multiple times on same page.

    // Build accessibility signals.
    $accessibility_signals = $this->buildAccessibilitySignals($signals, $tracks);

    // Determine presentation type.
    $presentation_type = '';
    if ($embed_method === 'html5_video') {
      $presentation_type = 'VIDEO_HTML5';
    }
    elseif ($embed_method === 'html5_audio') {
      $presentation_type = 'AUDIO_HTML5';
    }

    // Create new usage record.
    $usage = $usage_storage->create([
      'asset_id' => $asset_id,
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'field_name' => $field_name,
      'count' => 1,
      'embed_method' => $embed_method,
      'presentation_type' => $presentation_type,
      'accessibility_signals' => json_encode($accessibility_signals),
      'signals_evaluated' => TRUE,
    ]);
    $usage->save();
  }

  /**
   * Builds accessibility signals array from HTML5 embed data.
   *
   * @param array $signals
   *   Raw signals from HTML parsing (controls, autoplay, muted, loop).
   * @param array $tracks
   *   Track elements found.
   *
   * @return array
   *   Formatted signals array for storage.
   */
  protected function buildAccessibilitySignals(array $signals, array $tracks) {
    $result = [
      'controls' => !empty($signals['controls']) ? 'detected' : 'not_detected',
      'autoplay' => !empty($signals['autoplay']) ? 'detected' : 'not_detected',
      'muted' => !empty($signals['muted']) ? 'detected' : 'not_detected',
      'loop' => !empty($signals['loop']) ? 'detected' : 'not_detected',
    ];

    // Check for captions in tracks.
    $has_captions = FALSE;
    foreach ($tracks as $track) {
      if (in_array($track['kind'], ['captions', 'subtitles'])) {
        $has_captions = TRUE;
        break;
      }
    }
    $result['captions'] = $has_captions ? 'detected' : 'not_detected';

    return $result;
  }

  /**
   * Maps file extension to asset type.
   *
   * @param string $extension
   *   The file extension (lowercase).
   *
   * @return string
   *   The asset type.
   */
  protected function mapExtensionToAssetType($extension) {
    $map = [
      // Video.
      'mp4' => 'mp4',
      'webm' => 'webm',
      'mov' => 'mov',
      'avi' => 'avi',
      'mkv' => 'mkv',
      'ogv' => 'ogv',
      // Audio.
      'mp3' => 'mp3',
      'wav' => 'wav',
      'ogg' => 'ogg',
      'oga' => 'ogg',
      'm4a' => 'm4a',
      'flac' => 'flac',
      'aac' => 'aac',
      // Captions.
      'vtt' => 'vtt',
      'srt' => 'srt',
    ];

    return $map[$extension] ?? $extension;
  }

  /**
   * Finds media usage using Drupal's Entity Query API.
   *
   * This method bypasses entity_usage entirely and uses Entity Query to find
   * where media is used. Entity Query automatically queries only current
   * revisions and excludes deleted entities.
   *
   * @param int $media_id
   *   The media entity ID.
   *
   * @return array
   *   Array of references, each with keys: entity_type, entity_id.
   */
  protected function findMediaUsageViaEntityQuery($media_id) {
    $references = [];

    try {
      // Load the media entity to get its UUID for text field searching.
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if (!$media) {
        return $references;
      }

      $media_uuid = $media->uuid();

      // Get the field manager to find media reference fields.
      $field_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');

      // 1. Check entity reference fields that target media.
      foreach ($field_map as $entity_type_id => $fields) {
        // Skip the media entity type itself.
        if ($entity_type_id === 'media') {
          continue;
        }

        // Check if storage exists for this entity type.
        try {
          $storage = $this->entityTypeManager->getStorage($entity_type_id);
        }
        catch (\Exception $e) {
          continue;
        }

        // Collect media reference fields for this entity type.
        $media_fields = [];
        foreach ($fields as $field_name => $field_info) {
          // Load the field storage definition to check target type.
          try {
            $field_storage = $this->entityTypeManager
              ->getStorage('field_storage_config')
              ->load($entity_type_id . '.' . $field_name);

            if ($field_storage && $field_storage->getSetting('target_type') === 'media') {
              $media_fields[] = $field_name;
            }
          }
          catch (\Exception $e) {
            continue;
          }
        }

        if (empty($media_fields)) {
          continue;
        }

        // Query for entities that reference this media.
        // Query each field separately to capture which field contains the reference.
        foreach ($media_fields as $field_name) {
          try {
            $query = $storage->getQuery()
              ->accessCheck(FALSE)
              ->condition($field_name, $media_id);

            $entity_ids = $query->execute();

            foreach ($entity_ids as $entity_id) {
              $references[] = [
                'entity_type' => $entity_type_id,
                'entity_id' => $entity_id,
                'field_name' => $field_name,
                'method' => 'entity_reference',
              ];
            }
          }
          catch (\Exception $e) {
            // Skip fields that can't be queried.
            continue;
          }
        }
      }

      // 2. Check text fields for CKEditor embeds (<drupal-media> tags).
      $text_field_map = $this->entityFieldManager->getFieldMapByFieldType('text_long');
      $text_with_summary_map = $this->entityFieldManager->getFieldMapByFieldType('text_with_summary');

      // Merge text field maps.
      foreach ($text_with_summary_map as $entity_type_id => $fields) {
        if (!isset($text_field_map[$entity_type_id])) {
          $text_field_map[$entity_type_id] = [];
        }
        $text_field_map[$entity_type_id] = array_merge($text_field_map[$entity_type_id], $fields);
      }

      foreach ($text_field_map as $entity_type_id => $fields) {
        // Skip the media entity type.
        if ($entity_type_id === 'media') {
          continue;
        }

        try {
          $storage = $this->entityTypeManager->getStorage($entity_type_id);
        }
        catch (\Exception $e) {
          continue;
        }

        foreach ($fields as $field_name => $field_info) {
          // Query for entities where text field contains the media UUID.
          try {
            $query = $storage->getQuery()
              ->accessCheck(FALSE)
              ->condition($field_name, '%' . $media_uuid . '%', 'LIKE');

            $entity_ids = $query->execute();

            foreach ($entity_ids as $entity_id) {
              // Verify entity has media embed in current content.
              $entity = $storage->load($entity_id);
              if (!$entity || !$entity->hasField($field_name)) {
                continue;
              }

              $field_value = $entity->get($field_name)->value ?? '';

              // Check if the UUID is actually in the current field value.
              if (strpos($field_value, $media_uuid) !== FALSE) {
                $references[] = [
                  'entity_type' => $entity_type_id,
                  'entity_id' => $entity_id,
                  'field_name' => $field_name,
                  'method' => 'media_embed',
                ];
              }
            }
          }
          catch (\Exception $e) {
            // Skip fields that can't be queried.
            continue;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error finding media usage via entity query: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    // Remove duplicates (same entity might be found via multiple fields).
    $unique_refs = [];
    foreach ($references as $ref) {
      $key = $ref['entity_type'] . ':' . $ref['entity_id'];
      if (!isset($unique_refs[$key])) {
        $unique_refs[$key] = $ref;
      }
    }

    return array_values($unique_refs);
  }

  /**
   * Finds all media references by directly scanning content field tables.
   *
   * This method replaces entity_usage dependency with direct database queries
   * to find where media is actually used in current content.
   *
   * @param int $media_id
   *   The media entity ID.
   *
   * @return array
   *   Array of refs with keys: entity_type, entity_id, field_name, method.
   */
  protected function findMediaReferencesDirectly($media_id) {
    $references = [];

    // Get the media entity's UUID for searching in text fields.
    $media_uuid = NULL;
    try {
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if ($media) {
        $media_uuid = $media->uuid();
      }
    }
    catch (\Exception $e) {
      // Media doesn't exist.
    }

    // 1. Scan entity reference fields that point to media.
    $entity_ref_results = $this->scanEntityReferenceFields($media_id);
    foreach ($entity_ref_results as $ref) {
      $references[] = $ref;
    }

    // 2. Scan text fields for embedded media (<drupal-media> tags).
    if ($media_uuid) {
      $embed_results = $this->scanTextFieldsForMediaEmbed($media_uuid);
      foreach ($embed_results as $ref) {
        $references[] = $ref;
      }
    }

    return $references;
  }

  /**
   * Scans entity reference fields for media references.
   *
   * @param int $media_id
   *   The media entity ID.
   *
   * @return array
   *   Array of references found.
   */
  protected function scanEntityReferenceFields($media_id) {
    $references = [];

    // Get list of fields that actually reference media entities.
    $media_reference_fields = $this->getMediaReferenceFields();

    // Only scan tables for fields that are configured to reference media.
    foreach ($media_reference_fields as $field_info) {
      $table = $field_info['table'];
      $field_name = $field_info['field_name'];
      $entity_type = $field_info['entity_type'];
      $target_id_column = $field_name . '_target_id';

      try {
        $results = $this->database->select($table, 't')
          ->fields('t', ['entity_id'])
          ->condition($target_id_column, $media_id)
          ->execute()
          ->fetchAll();

        foreach ($results as $row) {
          $references[] = [
            'entity_type' => $entity_type,
            'entity_id' => $row->entity_id,
            'field_name' => $field_name,
            'method' => 'entity_reference',
          ];
        }
      }
      catch (\Exception $e) {
        // Skip tables that can't be queried.
      }
    }

    return $references;
  }

  /**
   * Gets all entity reference fields that target media entities.
   *
   * @return array
   *   Array of field info with keys: table, field_name, entity_type.
   */
  protected function getMediaReferenceFields() {
    $media_fields = [];

    try {
      // Load all field storage config entities.
      $field_storage_storage = $this->entityTypeManager->getStorage('field_storage_config');
      $field_storages = $field_storage_storage->loadMultiple();

      foreach ($field_storages as $field_storage) {
        // Check if this field is an entity_reference type.
        if ($field_storage->getType() !== 'entity_reference') {
          continue;
        }

        // Check if this field targets media entities.
        $target_type = $field_storage->getSetting('target_type');
        if ($target_type !== 'media') {
          continue;
        }

        // Get the field name and entity type.
        $field_name = $field_storage->getName();
        $entity_type_id = $field_storage->getTargetEntityTypeId();

        // Build the table name (current revision tables only).
        $table = $entity_type_id . '__' . $field_name;

        // Check if the table exists.
        if ($this->database->schema()->tableExists($table)) {
          $media_fields[] = [
            'table' => $table,
            'field_name' => $field_name,
            'entity_type' => $entity_type_id,
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Fallback: scan common media field patterns if config loading fails.
      $this->logger->warning('Could not load field config, using fallback media field detection');
    }

    return $media_fields;
  }

  /**
   * Finds local file link usage by scanning text fields for file URLs.
   *
   * @param string $file_uri
   *   The file URI (e.g., public://files/document.pdf).
   *
   * @return array
   *   Array of references found, each with entity_type, entity_id, field_name.
   */
  protected function findLocalFileLinkUsage(string $file_uri): array {
    $references = [];
    $db_schema = $this->database->schema();

    // Build multiple search needles to cover multisite, Site Factory, and
    // non-standard public file path configurations. DB LIKE is used for
    // broad discovery; false positives are acceptable (rare, harmless).
    $search_needles = [];

    if (strpos($file_uri, 'public://') === 0) {
      $relative = substr($file_uri, 9);

      // Broad anchor: matches /sites/{any}/files/{relative} across all
      // multisite and Site Factory installations.
      $search_needles[] = '/files/' . $relative;

      // Dynamic base path for current site (explicit match for /files/...
      // or other non-standard configs where /files/ alone could be ambiguous).
      $search_needles[] = $this->getPublicFilesBasePath() . '/' . $relative;
    }
    elseif (strpos($file_uri, 'private://') === 0) {
      $relative = substr($file_uri, 10);

      // Universal private route.
      $search_needles[] = '/system/files/' . $relative;

      // Legacy: some sites link to private files under the public path.
      $search_needles[] = '/files/private/' . $relative;

      // Current site's public base + /private/ fallback.
      $search_needles[] = $this->getPublicFilesBasePath() . '/private/' . $relative;
    }
    else {
      return [];
    }

    // De-dupe needles (e.g., if dynamic base is /sites/default/files,
    // the broad and dynamic needles overlap).
    $search_needles = array_values(array_unique($search_needles));

    // Entity type prefixes to scan (current revision tables only).
    // Includes taxonomy_term for images on taxonomy terms like news categories.
    // Includes block_content for custom blocks like sidebar navigation.
    $prefixes = ['node__', 'paragraph__', 'taxonomy_term__', 'block_content__'];

    foreach ($prefixes as $prefix) {
      $entity_type = str_replace('__', '', $prefix);

      // Find all tables with this prefix.
      $all_tables = $this->database->query("SHOW TABLES LIKE '{$prefix}%'")->fetchCol();

      foreach ($all_tables as $table) {
        $field_name = str_replace($prefix, '', $table);
        $value_column = $field_name . '_value';

        // Check if this table has a _value column (text field).
        if (!$db_schema->fieldExists($table, $value_column)) {
          continue;
        }

        // Search for each needle in the text field.
        foreach ($search_needles as $needle) {
          try {
            $results = $this->database->select($table, 't')
              ->fields('t', ['entity_id'])
              ->condition($value_column, '%' . $this->database->escapeLike($needle) . '%', 'LIKE')
              ->execute()
              ->fetchAll();

            foreach ($results as $row) {
              $references[] = [
                'entity_type' => $entity_type,
                'entity_id' => $row->entity_id,
                'field_name' => $field_name,
                'method' => 'file_link',
              ];
            }
          }
          catch (\Exception $e) {
            // Skip tables that can't be queried.
          }
        }
      }
    }

    // De-dupe by entity_type:entity_id:field_name.
    $unique_refs = [];
    foreach ($references as $ref) {
      $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . $ref['field_name'];
      $unique_refs[$key] = $ref;
    }

    return array_values($unique_refs);
  }

  /**
   * Finds direct file/image field usage for a file.
   *
   * This detects files used in direct 'image' or 'file' field types,
   * NOT via media entities.
   *
   * @param int $file_id
   *   The file ID from file_managed.
   *
   * @return array
   *   Array of references found, each with entity_type, entity_id, field_name.
   */
  protected function findDirectFileUsage($file_id) {
    $references = [];

    try {
      // Scan for direct file/image field usage using file_usage table.
      // file_usage tracks where files are used, regardless of media.
      $file_usages = $this->database->select('file_usage', 'fu')
        ->fields('fu', ['type', 'id', 'module'])
        ->condition('fid', $file_id)
        // Exclude media type as those are handled separately.
        ->condition('type', 'media', '!=')
        ->execute()
        ->fetchAll();

      foreach ($file_usages as $usage) {
        // Only track usage in content entities (node, paragraph, etc.).
        if (in_array($usage->type, ['node', 'paragraph', 'taxonomy_term', 'block_content'])) {
          // Try to find the actual field name that contains this file.
          // This checks the entity's CURRENT/DEFAULT revision.
          $field_name = $this->findFileFieldName($usage->type, $usage->id, $file_id);

          // Skip if file is not in the entity's current revision.
          // 'direct_file' means the file wasn't found - likely from a previous revision.
          if ($field_name === 'direct_file') {
            continue;
          }

          $references[] = [
            'entity_type' => $usage->type,
            'entity_id' => $usage->id,
            'field_name' => $field_name,
            'method' => 'file_usage',
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error finding direct file usage: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $references;
  }

  /**
   * Finds the field name that contains a specific file in an entity.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   * @param int $file_id
   *   The file ID to find.
   *
   * @return string
   *   The field name, or 'direct_file' if not found.
   */
  protected function findFileFieldName($entity_type, $entity_id, $file_id) {
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        return 'direct_file';
      }

      $bundle = $entity->bundle();

      // Get all field definitions for this entity type/bundle.
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

      foreach ($field_definitions as $field_name => $field_definition) {
        $field_type = $field_definition->getType();

        // Check file and image fields.
        if (in_array($field_type, ['file', 'image'])) {
          if ($entity->hasField($field_name)) {
            $field_values = $entity->get($field_name)->getValue();
            foreach ($field_values as $value) {
              if (isset($value['target_id']) && (int) $value['target_id'] === (int) $file_id) {
                return $field_name;
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // Fall back to generic name on error.
    }

    return 'direct_file';
  }

  /**
   * Scans text fields for embedded media tags.
   *
   * @param string $media_uuid
   *   The media entity UUID.
   *
   * @return array
   *   Array of references found.
   */
  protected function scanTextFieldsForMediaEmbed($media_uuid) {
    $references = [];
    $db_schema = $this->database->schema();

    // Entity type prefixes to scan (current revision tables only).
    // Includes taxonomy_term for text fields on taxonomy terms.
    // Includes block_content for custom blocks like sidebar navigation.
    $prefixes = ['node__', 'paragraph__', 'taxonomy_term__', 'block_content__'];

    foreach ($prefixes as $prefix) {
      $entity_type = str_replace('__', '', $prefix);

      // Find all tables with this prefix.
      $all_tables = $this->database->query("SHOW TABLES LIKE '{$prefix}%'")->fetchCol();

      foreach ($all_tables as $table) {
        $field_name = str_replace($prefix, '', $table);
        $value_column = $field_name . '_value';

        // Check if this table has a _value column (text field).
        if (!$db_schema->fieldExists($table, $value_column)) {
          continue;
        }

        // Search for the media UUID in the text field.
        try {
          $results = $this->database->select($table, 't')
            ->fields('t', ['entity_id'])
            ->condition($value_column, '%' . $this->database->escapeLike($media_uuid) . '%', 'LIKE')
            ->execute()
            ->fetchAll();

          foreach ($results as $row) {
            $references[] = [
              'entity_type' => $entity_type,
              'entity_id' => $row->entity_id,
              'field_name' => $field_name,
              'method' => 'media_embed',
            ];
          }
        }
        catch (\Exception $e) {
          // Skip tables that can't be queried.
        }
      }
    }

    return $references;
  }

  /**
   * Gets root parent entity from a paragraph (handles nested paragraphs).
   *
   * Verifies that paragraph chain is actually attached and not orphaned.
   * Handles nested structures like: Node > slideshow > slide > content.
   *
   * @param int $paragraph_id
   *   The paragraph ID.
   *
   * @return array|null
   *   Array with 'type' and 'id' keys, or NULL if orphaned/not found.
   */
  protected function getParentFromParagraph($paragraph_id) {
    try {
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($paragraph_id);

      if (!$paragraph) {
        $this->incrementOrphanCount();
        return NULL;
      }

      // Build the complete paragraph chain from child to root.
      $paragraph_chain = [$paragraph];
      $current = $paragraph;

      // Use getParentEntity() to trace through nested paragraphs.
      if (method_exists($current, 'getParentEntity')) {
        while ($current) {
          $parent = $current->getParentEntity();

          // If parent is NULL at any point, the chain is orphaned.
          if (!$parent) {
            $this->incrementOrphanCount();
            return NULL;
          }

          // If parent is another paragraph, continue tracing.
          if ($parent->getEntityTypeId() === 'paragraph') {
            $paragraph_chain[] = $parent;
            $current = $parent;
            continue;
          }

          // Found a non-paragraph parent (node, block_content, etc.).
          // Now verify the entire chain is properly attached.
          $root_parent = $parent;

          // For nodes, verify the top-level paragraph is still attached.
          if ($root_parent->getEntityTypeId() === 'node') {
            $root_paragraph = end($paragraph_chain);

            // Verify root paragraph is in node's current paragraph fields.
            if (!$this->isParagraphInEntityField($root_paragraph->id(), $root_parent)) {
              $this->incrementOrphanCount();
              return NULL;
            }

            // Also verify each nested paragraph is in its parent's fields.
            for ($i = 0; $i < count($paragraph_chain) - 1; $i++) {
              $child_paragraph = $paragraph_chain[$i];
              $parent_paragraph = $paragraph_chain[$i + 1];

              if (!$this->isParagraphInEntityField($child_paragraph->id(), $parent_paragraph)) {
                $this->incrementOrphanCount();
                return NULL;
              }
            }
          }

          return [
            'type' => $root_parent->getEntityTypeId(),
            'id' => $root_parent->id(),
          ];
        }
      }
      else {
        // Fallback for older Drupal versions: Use parent_type/parent_id fields.
        $parent_type = $paragraph->get('parent_type')->value;
        $parent_id = $paragraph->get('parent_id')->value;

        if ($parent_type && $parent_id) {
          if ($parent_type === 'paragraph') {
            // Recursively trace nested paragraphs.
            return $this->getParentFromParagraph($parent_id);
          }

          // Found non-paragraph parent - verify attachment.
          if ($parent_type === 'node') {
            if (!$this->isParaGraphAttachedToNode($paragraph_id, $parent_id)) {
              return NULL;
            }
          }

          return [
            'type' => $parent_type,
            'id' => $parent_id,
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error tracing paragraph @id: @error', [
        '@id' => $paragraph_id,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Checks if a paragraph is in any paragraph reference field of an entity.
   *
   * @param int $paragraph_id
   *   The paragraph ID to look for.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The parent entity to check.
   *
   * @return bool
   *   TRUE if the paragraph is found in the entity's fields.
   */
  protected function isParagraphInEntityField($paragraph_id, $entity) {
    try {
      // Get all fields from the entity.
      $fields = $entity->getFields();

      foreach ($fields as $field) {
        // Check if this field is an entity reference to paragraphs.
        $definition = $field->getFieldDefinition();

        if ($definition->getType() === 'entity_reference_revisions' ||
            $definition->getType() === 'entity_reference') {
          // Check if target type is paragraph.
          $settings = $definition->getSettings();
          $target_type = $settings['target_type'] ?? NULL;

          if ($target_type === 'paragraph') {
            // Check if this field contains our paragraph.
            foreach ($field as $item) {
              if ($item->target_id == $paragraph_id) {
                return TRUE;
              }
            }
          }
        }
      }

      return FALSE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if a paragraph is actually attached to a node's current revision.
   *
   * @param int $paragraph_id
   *   The paragraph ID.
   * @param int $node_id
   *   The node ID.
   *
   * @return bool
   *   TRUE if the paragraph is attached, FALSE if orphaned.
   */
  protected function isParaGraphAttachedToNode($paragraph_id, $node_id) {
    try {
      // Dynamically find paragraph reference tables.
      $all_tables = $this->database->query("SHOW TABLES LIKE 'node__field_%'")->fetchCol();
      foreach ($all_tables as $table) {
        $field_name = str_replace('node__', '', $table);
        $target_id_column = $field_name . '_target_id';

        if ($this->database->schema()->fieldExists($table, $target_id_column)) {
          // Check if this table references the paragraph.
          try {
            $count = $this->database->select($table, 't')
              ->condition('entity_id', $node_id)
              ->condition($target_id_column, $paragraph_id)
              ->countQuery()
              ->execute()
              ->fetchField();

            if ($count > 0) {
              return TRUE;
            }
          }
          catch (\Exception $e) {
            continue;
          }
        }
      }

      return FALSE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if paragraph revision is in current revision of its parent node.
   *
   * @param int $paragraph_id
   *   The paragraph entity ID.
   * @param int $paragraph_vid
   *   The paragraph revision ID from entity_usage.
   *
   * @return bool
   *   TRUE if the paragraph is in the current revision, FALSE otherwise.
   */
  protected function isParagraphInCurrentRevision($paragraph_id, $paragraph_vid) {
    try {
      // If source_vid is 0, we regenerated from current content scan.
      // Treat as "always current" since we scanned actual field tables.
      if ($paragraph_vid == 0) {
        return TRUE;
      }

      // Load the current paragraph entity (default revision).
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($paragraph_id);

      if (!$paragraph) {
        // Paragraph doesn't exist anymore - skip it.
        return FALSE;
      }

      // Check if the revision ID matches the current paragraph's revision.
      $current_vid = $paragraph->getRevisionId();

      if ($paragraph_vid != $current_vid) {
        // This is an old revision reference.
        return FALSE;
      }

      // Now check if the parent node is using the current revision.
      $parent_info = $this->getParentFromParagraph($paragraph_id);

      if (!$parent_info || $parent_info['type'] !== 'node') {
        // No parent or not a node - include it.
        return TRUE;
      }

      // Load the parent node's current revision.
      $node = $this->entityTypeManager->getStorage('node')->load($parent_info['id']);

      if (!$node) {
        return FALSE;
      }

      // The paragraph is valid if it exists in the current node revision.
      return TRUE;
    }
    catch (\Exception $e) {
      // On error, skip this reference.
      return FALSE;
    }
  }

  /**
   * Gets list of known file extensions for asset types.
   *
   * @return array
   *   Array of file extensions (without dots).
   */
  protected function getKnownExtensions() {
    return [
      // Documents.
      'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
      // Images.
      'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp',
      // Videos.
      'mp4', 'webm', 'mov', 'avi',
      // Audio.
      'mp3', 'wav', 'm4a', 'ogg',
      // Archives.
      'zip', 'tar', 'gz', '7z', 'rar',
    ];
  }

  /**
   * Maps file extension to MIME type.
   *
   * @param string $extension
   *   The file extension (without dot).
   *
   * @return string
   *   The MIME type.
   */
  protected function extensionToMime($extension) {
    $map = [
      // Documents.
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'txt' => 'text/plain',
      'csv' => 'text/csv',
      // Images.
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'svg' => 'image/svg+xml',
      'webp' => 'image/webp',
      // Videos.
      'mp4' => 'video/mp4',
      'webm' => 'video/webm',
      'mov' => 'video/quicktime',
      'avi' => 'video/x-msvideo',
      // Audio.
      'mp3' => 'audio/mpeg',
      'wav' => 'audio/wav',
      'm4a' => 'audio/mp4',
      'ogg' => 'audio/ogg',
      // Archives.
      'zip' => 'application/zip',
      'tar' => 'application/x-tar',
      'gz' => 'application/gzip',
      '7z' => 'application/x-7z-compressed',
      'rar' => 'application/x-rar-compressed',
    ];

    return $map[strtolower($extension)] ?? 'application/octet-stream';
  }

  /**
   * Recursively scans a directory for files with known extensions.
   *
   * @param string $directory
   *   The directory path to scan.
   * @param array $known_extensions
   *   Array of extensions to look for.
   * @param bool $is_private_scan
   *   Whether this is scanning the private directory.
   *
   * @return array
   *   Array of file paths relative to public directory.
   */
  protected function scanDirectoryRecursive($directory, array $known_extensions, $is_private_scan = FALSE) {
    $files = [];

    if (!is_dir($directory)) {
      return $files;
    }

    // Excluded system directories (image derivatives, aggregated files, etc.).
    $excluded_dirs = [
    // Image style derivatives.
      'styles',
    // Media thumbnails.
      'thumbnails',
    // Media type placeholder icons.
      'media-icons',
    // oEmbed thumbnails (YouTube, Vimeo, etc.).
      'oembed_thumbnails',
    // Video poster images.
      'video_thumbnails',
    // Aggregated CSS.
      'css',
    // Aggregated JavaScript.
      'js',
    // Temporary PHP files.
      'php',
    // CTools generated content.
      'ctools',
    // Generated sitemaps.
      'xmlsitemap',
    // Config sync directories.
      'config_',
    // ADA-archived documents.
      'archive',
    ];

    // Only exclude 'private' subdirectory when NOT doing a private scan.
    if (!$is_private_scan) {
      $excluded_dirs[] = 'private';
    }

    // Check if path contains excluded directories (skip subdirs too).
    foreach ($excluded_dirs as $excluded) {
      if (strpos($directory, '/' . $excluded . '/') !== FALSE ||
          strpos($directory, '/' . $excluded) === strlen($directory) - strlen('/' . $excluded)) {
        return $files;
      }
    }

    $items = scandir($directory);

    foreach ($items as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }

      $path = $directory . '/' . $item;

      if (is_dir($path)) {
        // Recursively scan subdirectory.
        $files = array_merge($files, $this->scanDirectoryRecursive($path, $known_extensions, $is_private_scan));
      }
      elseif (is_file($path)) {
        // Check extension.
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, $known_extensions)) {
          $files[] = $path;
        }
      }
    }

    return $files;
  }

  /**
   * Gets count of orphan files on filesystem.
   *
   * @return int
   *   The number of orphan files.
   */
  public function getOrphanFilesCount() {
    $known_extensions = $this->getKnownExtensions();
    $orphan_count = 0;

    // Scan both public and private directories.
    $streams = ['public://', 'private://'];

    foreach ($streams as $stream) {
      $base_path = $this->fileSystem->realpath($stream);

      if (!$base_path || !is_dir($base_path)) {
        continue;
      }

      // Determine if this is a private scan.
      $is_private_scan = ($stream === 'private://');

      // Get all files with known extensions.
      $all_files = $this->scanDirectoryRecursive($base_path, $known_extensions, $is_private_scan);

      foreach ($all_files as $file_path) {
        // Convert to Drupal URI.
        $relative_path = str_replace($base_path . '/', '', $file_path);
        $uri = $stream . $relative_path;

        // Check if file exists in file_managed.
        $exists = $this->database->select('file_managed', 'f')
          ->condition('uri', $uri)
          ->countQuery()
          ->execute()
          ->fetchField();

        if (!$exists) {
          $orphan_count++;
        }
      }
    }

    return $orphan_count;
  }

  /**
   * Scans a chunk of orphan files.
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of files to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Number of items processed.
   */
  public function scanOrphanFilesChunk($offset, $limit, $is_temp = FALSE) {
    $count = 0;
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');
    $known_extensions = $this->getKnownExtensions();

    // Scan both public and private directories.
    $streams = ['public://', 'private://'];
    $orphan_files = [];

    foreach ($streams as $stream) {
      $base_path = $this->fileSystem->realpath($stream);

      if (!$base_path || !is_dir($base_path)) {
        continue;
      }

      // Determine if this is a private scan.
      $is_private_scan = ($stream === 'private://');

      // Get all files with known extensions.
      $all_files = $this->scanDirectoryRecursive($base_path, $known_extensions, $is_private_scan);

      // Filter to only orphan files.
      foreach ($all_files as $file_path) {
        // Construct URI - ensure no double slashes.
        $relative_path = str_replace($base_path, '', $file_path);
        $relative_path = ltrim($relative_path, '/');
        $uri = $stream . $relative_path;

        // Check if file exists in file_managed.
        $exists = $this->database->select('file_managed', 'f')
          ->condition('uri', $uri)
          ->countQuery()
          ->execute()
          ->fetchField();

        if (!$exists) {
          $orphan_files[] = [
            'path' => $file_path,
            'uri' => $uri,
            'relative' => $relative_path,
          ];
        }
      }
    }

    // Process chunk.
    $chunk = array_slice($orphan_files, $offset, $limit);

    foreach ($chunk as $file_info) {
      $file_path = $file_info['path'];
      $uri = $file_info['uri'];
      $filename = basename($file_path);
      $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

      // Get file size.
      $filesize = file_exists($file_path) ? filesize($file_path) : 0;

      // Map extension to MIME type.
      $mime = $this->extensionToMime($extension);

      // Map MIME to asset type.
      $asset_type = $this->mapMimeToAssetType($mime);

      // Determine category and sort order.
      $category = $this->mapAssetTypeToCategory($asset_type);
      $sort_order = $this->getCategorySortOrder($category);

      // Create hash for uniqueness (based on URI).
      $uri_hash = md5($uri);

      // Convert URI to absolute URL for storage.
      try {
        $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
      }
      catch (\Exception $e) {
        // Fallback to URI if conversion fails.
        $absolute_url = $uri;
      }

      // Check if file is in the private file system.
      $is_private = strpos($uri, 'private://') === 0;

      // Check if TEMP asset already exists.
      // Only update temp items - never modify permanent items during scan.
      $existing_query = $storage->getQuery();
      $existing_query->condition('url_hash', $uri_hash);
      $existing_query->condition('source_type', 'filesystem_only');
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        // Update existing temp item.
        $asset_id = reset($existing_ids);
        $asset = $storage->load($asset_id);
        $asset->set('filesize', $filesize);
        $asset->set('file_path', $absolute_url);
        $asset->set('is_private', $is_private);
        $asset->save();
      }
      else {
        // Create new orphan asset.
        $asset = $storage->create([
          'source_type' => 'filesystem_only',
          'url_hash' => $uri_hash,
          'asset_type' => $asset_type,
          'category' => $category,
          'sort_order' => $sort_order,
          'file_path' => $absolute_url,
          'file_name' => $filename,
          'mime_type' => $mime,
          'filesize' => $filesize,
          'is_temp' => $is_temp,
          'is_private' => $is_private,
        ]);
        $asset->save();
        $asset_id = $asset->id();
      }

      // Clear existing usage records for this asset before re-scanning.
      $old_usage_query = $usage_storage->getQuery();
      $old_usage_query->condition('asset_id', $asset_id);
      $old_usage_query->accessCheck(FALSE);
      $old_usage_ids = $old_usage_query->execute();

      if ($old_usage_ids) {
        $old_usages = $usage_storage->loadMultiple($old_usage_ids);
        $usage_storage->delete($old_usages);
      }

      // Scan text fields for links to this orphan file (CKEditor links).
      $file_link_usage = $this->findLocalFileLinkUsage($uri);

      foreach ($file_link_usage as $ref) {
        // Trace paragraphs to their parent nodes.
        $parent_entity_type = $ref['entity_type'];
        $parent_entity_id = $ref['entity_id'];

        if ($parent_entity_type === 'paragraph') {
          $parent_info = $this->getParentFromParagraph($parent_entity_id);
          if ($parent_info) {
            $parent_entity_type = $parent_info['type'];
            $parent_entity_id = $parent_info['id'];
          }
          else {
            // Paragraph is orphaned - skip this reference.
            continue;
          }
        }

        // Check if usage record already exists for this entity.
        $existing_usage_query = $usage_storage->getQuery();
        $existing_usage_query->condition('asset_id', $asset_id);
        $existing_usage_query->condition('entity_type', $parent_entity_type);
        $existing_usage_query->condition('entity_id', $parent_entity_id);
        $existing_usage_query->accessCheck(FALSE);
        $existing_usage_ids = $existing_usage_query->execute();

        if (!$existing_usage_ids) {
          // Create usage record showing where file is linked.
          // These are text links found via findLocalFileLinkUsage().
          $usage_storage->create([
            'asset_id' => $asset_id,
            'entity_type' => $parent_entity_type,
            'entity_id' => $parent_entity_id,
            'field_name' => $ref['field_name'],
            'count' => 1,
            'embed_method' => 'text_link',
          ])->save();
        }
      }

      // Update CSV export fields for orphan files.
      $this->updateCsvExportFields($asset_id, $filesize);

      $count++;
    }

    return $count;
  }

  /**
   * Gets count of media entities (not used anymore for file-based media).
   *
   * @return int
   *   Always returns 0 as file-based media is handled via file_managed.
   */
  public function getMediaEntitiesCount() {
    return 0;
  }

  /**
   * Scans media entities (not used anymore for file-based media).
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of entities to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Always returns 0 as file-based media is handled via file_managed.
   */
  public function scanMediaEntitiesChunk($offset, $limit, $is_temp = FALSE) {
    return 0;
  }

  /**
   * Gets count of remote media entities (oEmbed videos like YouTube, Vimeo).
   *
   * Remote media entities don't have file_managed entries - they store
   * URLs directly in their source field.
   *
   * @return int
   *   The number of remote media entities.
   */
  public function getRemoteMediaCount() {
    try {
      // Get media types that use remote video/oEmbed sources.
      $remote_media_types = $this->getRemoteMediaTypes();

      if (empty($remote_media_types)) {
        return 0;
      }

      // Count media entities of these types.
      $query = $this->entityTypeManager->getStorage('media')->getQuery();
      $query->condition('bundle', $remote_media_types, 'IN');
      $query->accessCheck(FALSE);

      return (int) $query->count()->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Error counting remote media: @error', [
        '@error' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Gets media type IDs that use remote/oEmbed sources.
   *
   * @return array
   *   Array of media type machine names.
   */
  protected function getRemoteMediaTypes() {
    $remote_types = [];

    try {
      // Load all media type configurations.
      $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();

      foreach ($media_types as $type_id => $media_type) {
        // Get the source plugin ID.
        $source_plugin = $media_type->getSource();
        $source_id = $source_plugin->getPluginId();

        // Remote video sources: oembed:video, video_file (remote), etc.
        // The standard Drupal core remote video uses 'oembed:video'.
        if (in_array($source_id, ['oembed:video', 'video_embed_field'])) {
          $remote_types[] = $type_id;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading media types: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $remote_types;
  }

  /**
   * Scans a chunk of remote media entities.
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of entities to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Number of items processed.
   */
  public function scanRemoteMediaChunk($offset, $limit, $is_temp = FALSE) {
    $count = 0;
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    try {
      // Get media types that use remote video/oEmbed sources.
      $remote_media_types = $this->getRemoteMediaTypes();

      if (empty($remote_media_types)) {
        return 0;
      }

      // Query remote media entities.
      $query = $this->entityTypeManager->getStorage('media')->getQuery();
      $query->condition('bundle', $remote_media_types, 'IN');
      $query->accessCheck(FALSE);
      $query->range($offset, $limit);
      $media_ids = $query->execute();

      if (empty($media_ids)) {
        return 0;
      }

      $media_entities = $this->entityTypeManager->getStorage('media')->loadMultiple($media_ids);

      foreach ($media_entities as $media) {
        $media_id = $media->id();
        $media_name = $media->label();

        // Get the source URL from the media entity.
        $source = $media->getSource();
        $source_field_name = $source->getSourceFieldDefinition($media->bundle->entity)->getName();

        // Get the URL value from the source field.
        $source_url = NULL;
        if ($media->hasField($source_field_name) && !$media->get($source_field_name)->isEmpty()) {
          $source_url = $media->get($source_field_name)->value;
        }

        if (empty($source_url)) {
          // Skip media without a source URL.
          continue;
        }

        // Normalize and determine asset type from URL.
        $normalized = $this->normalizeVideoUrl($source_url);
        if ($normalized) {
          // Use canonical URL for storage.
          $source_url = $normalized['url'];
          $asset_type = $normalized['platform'];
        }
        else {
          // Fallback: match URL to asset type from config.
          $asset_type = $this->matchUrlToAssetType($source_url);

          // If URL doesn't match our known patterns, try to detect type.
          if ($asset_type === 'other') {
            if (stripos($source_url, 'youtube.com') !== FALSE || stripos($source_url, 'youtu.be') !== FALSE) {
              $asset_type = 'youtube';
            }
            elseif (stripos($source_url, 'vimeo.com') !== FALSE) {
              $asset_type = 'vimeo';
            }
            else {
              $asset_type = 'youtube';
            }
          }
        }

        // Determine category and sort order.
        $category = $this->mapAssetTypeToCategory($asset_type);
        $sort_order = $this->getCategorySortOrder($category);

        // Create URL hash for uniqueness (based on media ID to avoid duplicates).
        $url_hash = md5('media:' . $media_id);

        // Check if TEMP asset already exists.
        $existing_query = $storage->getQuery();
        $existing_query->condition('url_hash', $url_hash);
        $existing_query->condition('source_type', 'media_managed');
        $existing_query->condition('is_temp', TRUE);
        $existing_query->accessCheck(FALSE);
        $existing_ids = $existing_query->execute();

        if ($existing_ids) {
          // Update existing temp item.
          $asset_id = reset($existing_ids);
          $asset = $storage->load($asset_id);
          $asset->set('file_path', $source_url);
          $asset->set('file_name', $media_name);
          $asset->save();
        }
        else {
          // Create new remote media asset.
          $config = $this->configFactory->get('digital_asset_inventory.settings');
          $asset_types_config = $config->get('asset_types');
          $label = $asset_types_config[$asset_type]['label'] ?? ucfirst($asset_type);

          $asset = $storage->create([
            'source_type' => 'media_managed',
            'media_id' => $media_id,
            'url_hash' => $url_hash,
            'asset_type' => $asset_type,
            'category' => $category,
            'sort_order' => $sort_order,
            'file_path' => $source_url,
            'file_name' => $media_name,
            'mime_type' => $label,
            'filesize' => NULL,
            'is_temp' => $is_temp,
            'is_private' => FALSE,
          ]);
          $asset->save();
          $asset_id = $asset->id();
        }

        // Clear existing usage records for this asset.
        $old_usage_query = $usage_storage->getQuery();
        $old_usage_query->condition('asset_id', $asset_id);
        $old_usage_query->accessCheck(FALSE);
        $old_usage_ids = $old_usage_query->execute();

        if ($old_usage_ids) {
          $old_usages = $usage_storage->loadMultiple($old_usage_ids);
          $usage_storage->delete($old_usages);
        }

        // Find usage via entity query (entity reference fields).
        $media_references = $this->findMediaUsageViaEntityQuery($media_id);

        // Also scan text fields directly (including paragraphs) for drupal-media embeds.
        // This catches embeds in paragraph text fields that entity queries may miss.
        $media_uuid = $media->uuid();
        $text_field_references = $this->scanTextFieldsForMediaEmbed($media_uuid);

        // Merge and deduplicate references.
        $all_references = array_merge($media_references, $text_field_references);
        $media_references = [];
        $seen = [];
        foreach ($all_references as $ref) {
          $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . ($ref['field_name'] ?? '');
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $media_references[] = $ref;
          }
        }

        foreach ($media_references as $ref) {
          // Trace paragraphs to their parent nodes.
          $parent_entity_type = $ref['entity_type'];
          $parent_entity_id = $ref['entity_id'];
          $field_name = $ref['field_name'] ?? 'media';

          if ($parent_entity_type === 'paragraph') {
            $parent_info = $this->getParentFromParagraph($parent_entity_id);
            if ($parent_info) {
              $parent_entity_type = $parent_info['type'];
              $parent_entity_id = $parent_info['id'];
            }
            else {
              // Paragraph is orphaned - skip this reference.
              continue;
            }
          }

          // Check if usage record already exists.
          $existing_usage_query = $usage_storage->getQuery();
          $existing_usage_query->condition('asset_id', $asset_id);
          $existing_usage_query->condition('entity_type', $parent_entity_type);
          $existing_usage_query->condition('entity_id', $parent_entity_id);
          $existing_usage_query->condition('field_name', $field_name);
          $existing_usage_query->accessCheck(FALSE);
          $existing_usage_ids = $existing_usage_query->execute();

          if (!$existing_usage_ids) {
            // Map reference method to embed_method field value.
            $embed_method = 'field_reference';
            if (isset($ref['method']) && $ref['method'] === 'media_embed') {
              $embed_method = 'drupal_media';
            }

            // Create usage record.
            $usage_storage->create([
              'asset_id' => $asset_id,
              'entity_type' => $parent_entity_type,
              'entity_id' => $parent_entity_id,
              'field_name' => $field_name,
              'count' => 1,
              'embed_method' => $embed_method,
            ])->save();
          }
        }

        // Update CSV export fields (NULL filesize for remote media).
        $this->updateCsvExportFields($asset_id, NULL);

        $count++;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error scanning remote media: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $count;
  }

  /**
   * Promotes temporary items to permanent (atomic swap).
   */
  public function promoteTemporaryItems() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    // Get IDs of old non-temporary items (to be deleted).
    $query = $storage->getQuery();
    $query->condition('is_temp', FALSE);
    $query->accessCheck(FALSE);
    $old_item_ids = $query->execute();

    // Delete usage records that reference the OLD items only.
    // New usage records (referencing temp items) are preserved.
    if ($old_item_ids) {
      $usage_query = $usage_storage->getQuery();
      $usage_query->condition('asset_id', $old_item_ids, 'IN');
      $usage_query->accessCheck(FALSE);
      $old_usage_ids = $usage_query->execute();

      if ($old_usage_ids) {
        $old_usage_entities = $usage_storage->loadMultiple($old_usage_ids);
        $usage_storage->delete($old_usage_entities);
      }

      // Now delete the old non-temporary items.
      $entities = $storage->loadMultiple($old_item_ids);
      $storage->delete($entities);
    }

    // Mark all temporary items as permanent.
    $query = $storage->getQuery();
    $query->condition('is_temp', TRUE);
    $query->accessCheck(FALSE);
    $ids = $query->execute();

    if ($ids) {
      $entities = $storage->loadMultiple($ids);
      foreach ($entities as $entity) {
        $entity->set('is_temp', FALSE);
        $entity->save();
      }
    }

    // After promoting items, validate archived files to update warning flags.
    // This ensures that if files were deleted during scanning, archive records
    // are updated with appropriate warnings (File Missing, etc.).
    try {
      $archive_service = $this->container->get('digital_asset_inventory.archive');
      $archive_service->validateArchivedFiles();
    }
    catch (\Exception $e) {
      $this->logger->error('Error validating archived files after scan: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Clears temporary items (on cancel or error).
   *
   * Per Entity Integrity rules: Always delete usage records BEFORE items
   * to maintain foreign key integrity and prevent orphaned data.
   */
  public function clearTemporaryItems() {
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    $query = $storage->getQuery();
    $query->condition('is_temp', TRUE);
    $query->accessCheck(FALSE);
    $temp_item_ids = $query->execute();

    if ($temp_item_ids) {
      // Delete usage records for temp items FIRST (foreign key integrity).
      $usage_query = $usage_storage->getQuery();
      $usage_query->condition('asset_id', $temp_item_ids, 'IN');
      $usage_query->accessCheck(FALSE);
      $usage_ids = $usage_query->execute();

      if ($usage_ids) {
        $usage_entities = $usage_storage->loadMultiple($usage_ids);
        $usage_storage->delete($usage_entities);
      }

      // Now delete the temp items.
      $entities = $storage->loadMultiple($temp_item_ids);
      $storage->delete($entities);
    }
  }

  /**
   * Regenerates entity_usage tracking for a specific media entity.
   *
   * This forces entity_usage to recalculate all references to this media,
   * ensuring we have fresh data instead of stale cache.
   *
   * @param int $media_id
   *   The media entity ID.
   */
  protected function regenerateMediaEntityUsage($media_id) {
    try {
      // Check if entity_usage service exists.
      if (!$this->container->has('entity_usage.usage')) {
        return;
      }

      $entity_usage = $this->container->get('entity_usage.usage');

      // Load the media entity.
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);

      if (!$media) {
        return;
      }

      // First, delete all existing entity_usage records for this media target.
      // This ensures we start fresh and don't have stale data.
      $this->database->delete('entity_usage')
        ->condition('target_id', $media_id)
        ->condition('target_type', 'media')
        ->execute();

      // Now let entity_usage track this media's usage fresh.
      // We need to scan all entities that might reference this media.
      // The entity_usage module provides methods to register sources.
      // Get media UUID for searching in text fields.
      $media_uuid = $media->uuid();

      // Scan for entity reference fields pointing to this media.
      $entity_ref_results = $this->scanEntityReferenceFields($media_id);
      foreach ($entity_ref_results as $ref) {
        // Register this source in entity_usage.
        $entity_usage->registerUsage(
          $media_id,
          'media',
          $ref['entity_id'],
          $ref['entity_type'],
          'en',
        // revision_id (use 0 for default)
          0,
          $ref['method'],
          $ref['field_name'],
        // Count.
          1
        );
      }

      // Scan for embedded media in text fields.
      if ($media_uuid) {
        $embed_results = $this->scanTextFieldsForMediaEmbed($media_uuid);
        foreach ($embed_results as $ref) {
          // Register this source in entity_usage.
          $entity_usage->registerUsage(
            $media_id,
            'media',
            $ref['entity_id'],
            $ref['entity_type'],
            'en',
          // revision_id (use 0 for default)
            0,
            $ref['method'],
            $ref['field_name'],
          // Count.
            1
          );
        }
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Error regenerating entity_usage for media @id: @error', [
        '@id' => $media_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Clears all usage records (called at start of new scan).
   */
  public function clearUsageRecords() {
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    // Delete all usage records.
    $query = $usage_storage->getQuery();
    $query->accessCheck(FALSE);
    $ids = $query->execute();

    if ($ids) {
      $entities = $usage_storage->loadMultiple($ids);
      $usage_storage->delete($entities);
    }
  }

  /**
   * Updates CSV export fields for a digital asset.
   *
   * @param int $asset_id
   *   The digital asset item ID.
   * @param int $filesize
   *   The file size in bytes.
   */
  protected function updateCsvExportFields($asset_id, $filesize) {
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    $asset = $storage->load($asset_id);
    if (!$asset) {
      $this->logger->error('updateCsvExportFields: Asset @id not found', ['@id' => $asset_id]);
      return;
    }

    // Format file size as human-readable (e.g., "2.5 MB", "156 KB").
    $filesize_formatted = $this->formatFileSize($filesize);

    // Check if field exists before setting.
    if (!$asset->hasField('filesize_formatted')) {
      $this->logger->error('Field filesize_formatted does not exist on entity!');
      return;
    }

    $asset->set('filesize_formatted', $filesize_formatted);

    // Build "used in" CSV field - list of "Page Name (URL)" entries.
    $used_in_parts = [];

    // Query usage records for this asset.
    $usage_query = $usage_storage->getQuery();
    $usage_query->condition('asset_id', $asset_id);
    $usage_query->accessCheck(FALSE);
    $usage_ids = $usage_query->execute();

    if ($usage_ids) {
      $usages = $usage_storage->loadMultiple($usage_ids);

      foreach ($usages as $usage) {
        $entity_type = $usage->get('entity_type')->value;
        $entity_id = $usage->get('entity_id')->value;

        // Load the referenced entity to get its title and URL.
        try {
          $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);

          if ($entity) {
            $label = $entity->label();

            // Get absolute URL if entity has a canonical link.
            if ($entity->hasLinkTemplate('canonical')) {
              $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
              $used_in_parts[] = $label . ' (' . $url . ')';
            }
            else {
              // No canonical URL available.
              $used_in_parts[] = $label;
            }
          }
        }
        catch (\Exception $e) {
          // Skip entities that can't be loaded.
        }
      }
    }

    // Build final string - semicolon-separated or "Not used".
    if (!empty($used_in_parts)) {
      // Remove duplicates (same page might be referenced multiple times).
      $used_in_parts = array_unique($used_in_parts);
      $used_in_csv = implode('; ', $used_in_parts);
    }
    else {
      $used_in_csv = 'Not used';
    }

    $asset->set('used_in_csv', $used_in_csv);
    $asset->save();
  }

  /**
   * Formats file size in human-readable format.
   *
   * @param int $bytes
   *   The file size in bytes.
   *
   * @return string
   *   Human-readable size (e.g., "2.5 MB", "156 KB").
   */
  protected function formatFileSize($bytes) {
    if ($bytes == 0) {
      return '-';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    // Format with appropriate decimal places.
    if ($pow == 0) {
      // Bytes - no decimals.
      return round($bytes) . ' ' . $units[$pow];
    }
    else {
      // KB, MB, etc. - 2 decimal places.
      return number_format($bytes, 2) . ' ' . $units[$pow];
    }
  }

  /**
   * Excludes system-generated files from the managed files query.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query object to modify.
   */
  protected function excludeSystemGeneratedFiles($query) {
    // System directories to exclude from managed file scanning.
    $excluded_paths = [
      'public://styles/%',
      'public://thumbnails/%',
      'public://media-icons/%',
      'public://oembed_thumbnails/%',
      'public://video_thumbnails/%',
      'public://css/%',
      'public://js/%',
      'public://php/%',
      'public://ctools/%',
      'public://xmlsitemap/%',
      'public://config_%',
    // Site logos - system configuration files.
      'public://wordmark/%',
    // ADA-archived documents.
      'public://archive/%',
      'private://styles/%',
      'private://thumbnails/%',
      'private://media-icons/%',
      'private://oembed_thumbnails/%',
      'private://video_thumbnails/%',
      'private://css/%',
      'private://js/%',
      'private://php/%',
      'private://ctools/%',
      'private://xmlsitemap/%',
      'private://config_%',
    // ADA-archived documents (private).
      'private://archive/%',
    ];

    // Add NOT LIKE conditions for each excluded path.
    foreach ($excluded_paths as $excluded_path) {
      $query->condition('uri', $excluded_path, 'NOT LIKE');
    }
  }

  /**
   * Increments the orphaned paragraph count for scan statistics.
   *
   * Uses Drupal State API to track counts across batch chunks.
   */
  protected function incrementOrphanCount() {
    $state = \Drupal::state();
    $current = $state->get('digital_asset_inventory.scan_orphan_count', 0);
    $state->set('digital_asset_inventory.scan_orphan_count', $current + 1);
  }

  /**
   * Gets the current orphaned paragraph count.
   *
   * @return int
   *   The number of orphaned paragraphs skipped during scan.
   */
  public function getOrphanCount() {
    return \Drupal::state()->get('digital_asset_inventory.scan_orphan_count', 0);
  }

  /**
   * Resets scan statistics (call at start of new scan).
   */
  public function resetScanStats() {
    $state = \Drupal::state();
    $state->set('digital_asset_inventory.scan_orphan_count', 0);
  }

  /**
   * Gets count of menu links to scan for file references.
   *
   * @return int
   *   The number of menu link content entities.
   */
  public function getMenuLinksCount() {
    // Check if menu_link_content module is enabled.
    if (!$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return 0;
    }

    try {
      $count = $this->entityTypeManager
        ->getStorage('menu_link_content')
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      return (int) $count;
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not count menu links: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Scans a chunk of menu links for file references.
   *
   * Detects menu links that point to files (PDFs, documents, etc.) and creates
   * usage tracking records for them.
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of menu links to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary (unused for menu link scanning).
   *
   * @return int
   *   Number of menu links processed.
   */
  public function scanMenuLinksChunk($offset, $limit, $is_temp = FALSE) {
    // Check if menu_link_content module is enabled.
    if (!$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return 0;
    }

    $count = 0;

    try {
      // Load menu link entities.
      $ids = $this->entityTypeManager
        ->getStorage('menu_link_content')
        ->getQuery()
        ->accessCheck(FALSE)
        ->range($offset, $limit)
        ->execute();

      if (empty($ids)) {
        return 0;
      }

      $menu_links = $this->entityTypeManager
        ->getStorage('menu_link_content')
        ->loadMultiple($ids);

      $asset_storage = $this->entityTypeManager->getStorage('digital_asset_item');
      $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');
      $file_storage = $this->entityTypeManager->getStorage('file');

      foreach ($menu_links as $menu_link) {
        $count++;

        // Get the link field value.
        if (!$menu_link->hasField('link') || $menu_link->get('link')->isEmpty()) {
          continue;
        }

        $link_uri = $menu_link->get('link')->uri;
        if (empty($link_uri)) {
          continue;
        }

        // Convert URI to file path for matching.
        $file_info = $this->parseMenuLinkUri($link_uri);
        if (!$file_info) {
          continue;
        }

        // Find the DigitalAssetItem for this file.
        $asset_id = $this->findAssetIdByFileInfo($file_info, $asset_storage, $file_storage);
        if (!$asset_id) {
          continue;
        }

        // Get menu name for context.
        $menu_name = $menu_link->getMenuName();

        // Check if usage record already exists.
        $usage_query = $usage_storage->getQuery();
        $usage_query->condition('asset_id', $asset_id);
        $usage_query->condition('entity_type', 'menu_link_content');
        $usage_query->condition('entity_id', $menu_link->id());
        $usage_query->accessCheck(FALSE);
        $existing_usage = $usage_query->execute();

        if (!$existing_usage) {
          // Create usage tracking record.
          $usage_storage->create([
            'asset_id' => $asset_id,
            'entity_type' => 'menu_link_content',
            'entity_id' => $menu_link->id(),
            'field_name' => 'link (' . $menu_name . ')',
            'count' => 1,
            'embed_method' => 'menu_link',
          ])->save();

          // Update CSV export fields for the asset.
          $asset = $asset_storage->load($asset_id);
          if ($asset) {
            $filesize = $asset->get('filesize')->value ?? 0;
            $this->updateCsvExportFields($asset_id, $filesize);
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Error scanning menu links: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $count;
  }

  /**
   * Parses a menu link URI to extract file information.
   *
   * @param string $uri
   *   The menu link URI (e.g., 'internal:/sites/default/files/doc.pdf').
   *
   * @return array|null
   *   Array with 'type' (stream or url) and 'path' keys, or NULL if not a file.
   */
  protected function parseMenuLinkUri($uri) {
    // Handle internal URIs (internal:/path).
    if (strpos($uri, 'internal:/') === 0) {
      $path = substr($uri, 9); // Remove 'internal:'
      return $this->extractFileInfoFromPath($path);
    }

    // Handle base URIs (base:path).
    if (strpos($uri, 'base:') === 0) {
      $path = '/' . substr($uri, 5); // Remove 'base:' and add leading slash
      return $this->extractFileInfoFromPath($path);
    }

    // Handle entity URIs (entity:node/123) - not file links.
    if (strpos($uri, 'entity:') === 0) {
      return NULL;
    }

    // Handle route URIs (route:<name>) - not file links.
    if (strpos($uri, 'route:') === 0) {
      return NULL;
    }

    // Handle full URLs (https://...).
    if (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
      // Check if it's a local file URL.
      $parsed = parse_url($uri);
      if (isset($parsed['path'])) {
        return $this->extractFileInfoFromPath($parsed['path']);
      }
    }

    return NULL;
  }

  /**
   * Extracts file info from a URL path.
   *
   * @param string $path
   *   The URL path (e.g., '/sites/default/files/doc.pdf').
   *
   * @return array|null
   *   Array with file info or NULL if not a file path.
   */
  protected function extractFileInfoFromPath(string $path): ?array {
    $path = trim($path, " \t\n\r\0\x0B\"'");
    $path = preg_replace('/[?#].*$/', '', $path);

    // Delegate to trait: handles universal public, dynamic fallback,
    // legacy /private/ under public path, and /system/files/.
    if ($uri = $this->urlPathToStreamUri($path)) {
      return [
        'type' => 'stream',
        'stream_uri' => $uri,
        'path' => $path,
      ];
    }

    return NULL;
  }

  /**
   * Finds a DigitalAssetItem ID by file information.
   *
   * @param array $file_info
   *   File info from parseMenuLinkUri().
   * @param \Drupal\Core\Entity\EntityStorageInterface $asset_storage
   *   The asset storage.
   * @param \Drupal\Core\Entity\EntityStorageInterface $file_storage
   *   The file storage.
   *
   * @return int|null
   *   The asset ID or NULL if not found.
   */
  protected function findAssetIdByFileInfo(array $file_info, $asset_storage, $file_storage) {
    if ($file_info['type'] !== 'stream' || empty($file_info['stream_uri'])) {
      return NULL;
    }

    $stream_uri = $file_info['stream_uri'];

    // First, try to find the file entity by URI.
    $file_ids = $file_storage->getQuery()
      ->condition('uri', $stream_uri)
      ->accessCheck(FALSE)
      ->execute();

    if ($file_ids) {
      $fid = reset($file_ids);

      // Find asset by fid (file_managed source).
      $asset_ids = $asset_storage->getQuery()
        ->condition('fid', $fid)
        ->condition('is_temp', TRUE)
        ->accessCheck(FALSE)
        ->execute();

      if ($asset_ids) {
        return reset($asset_ids);
      }

      // Also check media_id if it's a media file.
      // Media files might be tracked via media_id rather than fid.
      $media_ids = $this->entityTypeManager
        ->getStorage('media')
        ->getQuery()
        ->condition('field_media_file.target_id', $fid)
        ->accessCheck(FALSE)
        ->execute();

      if ($media_ids) {
        $media_id = reset($media_ids);
        $asset_ids = $asset_storage->getQuery()
          ->condition('media_id', $media_id)
          ->condition('is_temp', TRUE)
          ->accessCheck(FALSE)
          ->execute();

        if ($asset_ids) {
          return reset($asset_ids);
        }
      }
    }

    // If file entity not found, try matching by path.
    // This handles orphan files that might be in the inventory.
    $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($stream_uri);
    $asset_ids = $asset_storage->getQuery()
      ->condition('file_path', $absolute_url)
      ->condition('is_temp', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    if ($asset_ids) {
      return reset($asset_ids);
    }

    return NULL;
  }

}
