<?php

namespace Drupal\digital_asset_inventory\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\AdminContext;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response subscriber that rewrites file URLs to archive detail pages.
 *
 * Processes the final HTML output and replaces URLs pointing to archived files
 * with links to the Archive Detail Page. This catches all file URLs regardless
 * of how they were rendered (templates, Views, custom code).
 *
 * Only processes:
 * - HTML responses (text/html content type)
 * - Non-admin pages
 * - When archive link routing is enabled
 *
 * Skips:
 * - Admin pages (editing contexts)
 * - Non-HTML responses (JSON, XML, etc.)
 * - Responses without a body
 */
class ArchiveLinkResponseSubscriber implements EventSubscriberInterface {

  /**
   * The archive service.
   *
   * @var \Drupal\digital_asset_inventory\Service\ArchiveService
   */
  protected $archiveService;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The admin context service.
   *
   * @var \Drupal\Core\Routing\AdminContext
   */
  protected $adminContext;

  /**
   * The current path stack.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * Cached URL mappings (original URL => archive URL).
   *
   * @var array|null
   */
  protected $urlMappings;

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Routing\AdminContext $admin_context
   *   The admin context service.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path stack.
   */
  public function __construct(
    ArchiveService $archive_service,
    ConfigFactoryInterface $config_factory,
    AdminContext $admin_context,
    CurrentPathStack $current_path
  ) {
    $this->archiveService = $archive_service;
    $this->configFactory = $config_factory;
    $this->adminContext = $admin_context;
    $this->currentPath = $current_path;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Run after most other subscribers but before the final output.
    $events[KernelEvents::RESPONSE][] = ['onResponse', -100];
    return $events;
  }

  /**
   * Processes the response to rewrite archived file URLs.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event) {
    $response = $event->getResponse();
    $request = $event->getRequest();

    // Only process main requests (not subrequests).
    if (!$event->isMainRequest()) {
      return;
    }

    // Only process HTML responses.
    $content_type = $response->headers->get('Content-Type', '');
    if (strpos($content_type, 'text/html') === FALSE) {
      return;
    }

    // Skip if archive link routing is disabled.
    if (!$this->archiveService->isLinkRoutingEnabled()) {
      return;
    }

    // Skip admin pages.
    if ($this->isAdminPage($request)) {
      return;
    }

    // Skip Archive Registry pages - don't rewrite links on the archive pages themselves.
    // This includes /archive-registry (listing) and /archive-registry/{id} (detail).
    $current_path = $this->currentPath->getPath();
    if ($current_path === '/archive-registry' || strpos($current_path, '/archive-registry/') === 0) {
      return;
    }

    // Get the response content.
    $content = $response->getContent();
    if (empty($content)) {
      return;
    }

    // Get URL mappings for archived files.
    $mappings = $this->getUrlMappings();
    if (empty($mappings)) {
      return;
    }

    // Track if we made any replacements.
    $modified = FALSE;

    // Process each archived file URL.
    foreach ($mappings as $original_pattern => $archive_info) {
      // Match anchor tags containing this file URL in href.
      // Handles: relative URLs, absolute URLs with domain, URLs with query strings.
      // Captures: before href, href value (with optional domain prefix and query string), quote, rest of tag, content, closing tag.
      $escaped_pattern = preg_quote($original_pattern, '/');
      // Allow optional protocol and domain prefix, and optional query string suffix.
      $url_pattern = '(?:https?:\/\/[^\/]+)?' . $escaped_pattern . '(?:\?[^"\']*)?';
      $pattern = '/(<a\s[^>]*href=["\'])(' . $url_pattern . ')(["\'])([^>]*>)(.*?)(<\/a>)/is';

      if (preg_match($pattern, $content)) {
        $archive_url = $archive_info['url'];
        $file_name = $archive_info['name'] ?? '';

        // Replace the URL and add "(Archived)" indicator.
        $content = preg_replace_callback(
          $pattern,
          function ($matches) use ($archive_url, $file_name) {
            $before_href = $matches[1];
            $after_href_quote = $matches[3];
            $rest_of_tag = $matches[4];
            $link_content = $matches[5];
            $closing_tag = $matches[6];

            // Check if this is an image link (contains <img).
            $is_image_link = (stripos($link_content, '<img') !== FALSE);
            $label = $this->archiveService->getArchivedLabel();

            // Update or add aria-label on the <a> tag to indicate archived status.
            // This overrides any existing aria-label so screen readers always
            // announce the item is archived, even if the link had a custom label.
            $archived_aria = $file_name
              ? $file_name . ' - ' . $this->t('archived, opens archive detail page')
              : $this->t('archived, opens archive detail page');
            $rest_of_tag = $this->setAriaLabel($rest_of_tag, $archived_aria);

            if ($is_image_link) {
              // For image links, add/update title attribute with file name.
              $archived_title = $file_name ? $file_name . ' (' . $label . ')' : $label;

              // Check if title attribute already exists in the rest of the tag.
              if (preg_match('/\stitle=["\']([^"\']*)["\']/', $rest_of_tag, $title_match)) {
                // Update existing title if it doesn't already mention the label.
                if (stripos($title_match[1], $label) === FALSE && stripos($title_match[1], 'archived') === FALSE) {
                  $new_title = $title_match[1] . ' (' . $label . ')';
                  $rest_of_tag = preg_replace(
                    '/\stitle=["\'][^"\']*["\']/',
                    ' title="' . htmlspecialchars($new_title) . '"',
                    $rest_of_tag
                  );
                }
              }
              else {
                // Add title attribute before the closing >.
                $rest_of_tag = ' title="' . htmlspecialchars($archived_title) . '"' . $rest_of_tag;
              }

              return $before_href . $archive_url . $after_href_quote . $rest_of_tag . $link_content . $closing_tag;
            }
            else {
              // For text links, add visible label if enabled.
              // Screen reader context is now handled by aria-label on the <a> tag,
              // so we no longer need the visually-hidden span.
              if (strpos($link_content, 'dai-archived-label') === FALSE) {
                if ($this->archiveService->shouldShowArchivedLabel()) {
                  $label_with_parens = '(' . $label . ')';
                  $link_content .= ' <span class="dai-archived-label" aria-hidden="true">' . htmlspecialchars($label_with_parens) . '</span>';
                }
              }

              return $before_href . $archive_url . $after_href_quote . $rest_of_tag . $link_content . $closing_tag;
            }
          },
          $content
        );
        $modified = TRUE;
      }
    }

    // Process media entity URLs (/media/{id}).
    // These link to the media canonical page, but if the underlying file is archived,
    // we should redirect to the archive detail page.
    $content = $this->processMediaEntityUrls($content, $modified);

    // Process <video> and <audio> elements - replace entire element with placeholder
    // if ANY source is archived. This must happen BEFORE individual <source> processing.
    $content = $this->processVideoAudioElements($content, $mappings, $modified);

    // Process <object>, <embed>, and <iframe> elements.
    foreach ($mappings as $original_pattern => $archive_info) {
      $escaped_pattern = preg_quote($original_pattern, '/');
      $url_pattern = '(?:https?:\/\/[^\/]+)?' . $escaped_pattern . '(?:\?[^"\']*)?';

      // Match <object data="..."> elements.
      $object_pattern = '/(<object\s[^>]*data=["\'])(' . $url_pattern . ')(["\'][^>]*>)/is';

      if (preg_match($object_pattern, $content)) {
        $archive_url = $archive_info['url'];
        $content = preg_replace($object_pattern, '${1}' . $archive_url . '${3}', $content);
        $modified = TRUE;
      }

      // Match <embed src="..."> elements.
      $embed_pattern = '/(<embed\s[^>]*src=["\'])(' . $url_pattern . ')(["\'][^>]*>)/is';

      if (preg_match($embed_pattern, $content)) {
        $archive_url = $archive_info['url'];
        $content = preg_replace($embed_pattern, '${1}' . $archive_url . '${3}', $content);
        $modified = TRUE;
      }

      // Match <iframe src="..."> elements (for embedded documents).
      $iframe_pattern = '/(<iframe\s[^>]*src=["\'])(' . $url_pattern . ')(["\'][^>]*>)/is';

      if (preg_match($iframe_pattern, $content)) {
        $archive_url = $archive_info['url'];
        $content = preg_replace($iframe_pattern, '${1}' . $archive_url . '${3}', $content);
        $modified = TRUE;
      }
    }

    // Always add cache tag so the page is invalidated when archives change.
    // This ensures pages cached before an archive existed will be refreshed.
    if ($response instanceof CacheableResponseInterface) {
      $response->getCacheableMetadata()->addCacheTags(['digital_asset_archive_list']);
    }

    // Update response content if modified.
    if ($modified) {
      $response->setContent($content);
    }
  }

  /**
   * Checks if the current request is for an admin page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return bool
   *   TRUE if this is an admin page.
   */
  protected function isAdminPage($request) {
    // Check route-based admin context.
    if ($this->adminContext->isAdminRoute()) {
      return TRUE;
    }

    // Check path-based admin detection.
    $path = $this->currentPath->getPath();
    $admin_paths = [
      '/admin',
      '/node/add',
      '/node/*/edit',
      '/node/*/delete',
      '/media/*/edit',
      '/taxonomy/term/*/edit',
      '/user/*/edit',
      '/digital-asset-inventory',
    ];

    foreach ($admin_paths as $admin_path) {
      $pattern = '#^' . str_replace(['*', '/'], ['[^/]+', '\/'], $admin_path) . '#';
      if (preg_match($pattern, $path)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets URL mappings for all actively archived files.
   *
   * @return array
   *   Array of mappings: original URL pattern => ['url' => archive URL, 'fid' => file ID].
   */
  protected function getUrlMappings() {
    if ($this->urlMappings !== NULL) {
      return $this->urlMappings;
    }

    $this->urlMappings = [];

    $storage = \Drupal::entityTypeManager()->getStorage('digital_asset_archive');

    // Get all active file-based archives (documents, videos).
    $file_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ['archived_public', 'archived_admin'], 'IN')
      ->condition('original_fid', NULL, 'IS NOT NULL')
      ->execute();

    // Get all active manual entries (pages, external URLs).
    $manual_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ['archived_public', 'archived_admin'], 'IN')
      ->condition('asset_type', ['page', 'external'], 'IN')
      ->execute();

    $all_ids = array_unique(array_merge($file_ids, $manual_ids));

    if (empty($all_ids)) {
      return $this->urlMappings;
    }

    $archives = $storage->loadMultiple($all_ids);

    foreach ($archives as $archive) {
      $archive_url = '/archive-registry/' . $archive->id();

      // Handle manual entries (pages/external URLs).
      if ($archive->isManualEntry()) {
        $original_path = $archive->getOriginalPath();
        if (!empty($original_path)) {
          $entry_name = $archive->getFileName() ?: $original_path;

          // Check if this is a full URL (starts with http).
          if (strpos($original_path, 'http') === 0) {
            $parsed_url = parse_url($original_path);
            $url_path = $parsed_url['path'] ?? '/';

            // Determine if this looks like an external resource (Google Docs, YouTube, etc.)
            // by checking if it has a path that looks like a file or known external pattern.
            $url_host = strtolower($parsed_url['host'] ?? '');
            $external_hosts = [
              'docs.google.com', 'drive.google.com', 'forms.google.com', 'sites.google.com',
              'youtube.com', 'www.youtube.com', 'youtu.be', 'vimeo.com',
              'dropbox.com', 'box.com', 'onedrive.live.com', 'sharepoint.com',
              'qualtrics.com', 'surveymonkey.com', 'typeform.com',
            ];
            $is_external = in_array($url_host, $external_hosts);

            $mapping_data = [
              'url' => $archive_url,
              'fid' => NULL,
              'name' => $entry_name,
              'is_page' => !$is_external,
              'is_external' => $is_external,
            ];

            // Always add the full URL for matching absolute URLs in content.
            $this->urlMappings[$original_path] = $mapping_data;

            // Add normalized URL for matching variations.
            $normalized_url = $this->archiveService->normalizeUrl($original_path);
            if ($normalized_url !== $original_path) {
              $this->urlMappings[$normalized_url] = $mapping_data;
            }

            // For non-external URLs, also add the path portion for relative URL matching.
            if (!$is_external && !empty($url_path)) {
              $this->urlMappings[$url_path] = $mapping_data;

              // Also add without leading slash for flexibility.
              $without_slash = ltrim($url_path, '/');
              if ($without_slash !== $url_path) {
                $this->urlMappings[$without_slash] = $mapping_data;
              }

              // Resolve path alias to/from system path for complete coverage.
              $this->addPathAliasMappings($url_path, $mapping_data);
            }
          }
          else {
            // Internal path (no http prefix) - normalize and add mapping.
            $normalized_path = $original_path;
            if (strpos($normalized_path, '/') !== 0) {
              $normalized_path = '/' . $normalized_path;
            }

            $mapping_data = [
              'url' => $archive_url,
              'fid' => NULL,
              'name' => $entry_name,
              'is_page' => TRUE,
            ];

            $this->urlMappings[$normalized_path] = $mapping_data;

            // Also add without leading slash for flexibility.
            $without_slash = ltrim($normalized_path, '/');
            if ($without_slash !== $normalized_path) {
              $this->urlMappings[$without_slash] = $mapping_data;
            }

            // Resolve path alias to/from system path for complete coverage.
            $this->addPathAliasMappings($normalized_path, $mapping_data);
          }
        }
        continue;
      }

      // Handle file-based archives.
      $archive_path = $archive->getArchivePath();
      if (empty($archive_path)) {
        continue;
      }

      // Skip images - don't rewrite image URLs.
      $mime_type = $archive->getMimeType();
      if ($mime_type && strpos($mime_type, 'image/') === 0) {
        continue;
      }

      // Add mapping for the stored archive path.
      // Convert stream URI to relative URL path.
      $url_path = $this->streamUriToUrlPath($archive_path);
      if ($url_path) {
        $file_name = $archive->getFileName() ?: basename($url_path);

        // Add both encoded and decoded versions to handle different template outputs.
        $this->urlMappings[$url_path] = [
          'url' => $archive_url,
          'fid' => $archive->getOriginalFid(),
          'name' => $file_name,
        ];

        // Also add URL-encoded version.
        $encoded_path = str_replace(' ', '%20', $url_path);
        if ($encoded_path !== $url_path) {
          $this->urlMappings[$encoded_path] = [
            'url' => $archive_url,
            'fid' => $archive->getOriginalFid(),
            'name' => $file_name,
          ];
        }

        // Add fully encoded version (handles parentheses, etc.).
        $parts = explode('/', $url_path);
        $filename = array_pop($parts);
        $encoded_filename = rawurlencode($filename);
        if ($encoded_filename !== $filename) {
          $full_encoded_path = implode('/', $parts) . '/' . $encoded_filename;
          $this->urlMappings[$full_encoded_path] = [
            'url' => $archive_url,
            'fid' => $archive->getOriginalFid(),
            'name' => $file_name,
          ];
        }
      }
    }

    return $this->urlMappings;
  }

  /**
   * Processes media entity URLs in the content.
   *
   * Finds links to media entities by:
   * 1. data-entity-type="media" with data-entity-uuid (works with any URL/alias)
   * 2. href="/media/{id}" pattern (fallback)
   *
   * Redirects to archive detail page if the media's underlying file is archived.
   *
   * @param string $content
   *   The HTML content.
   * @param bool &$modified
   *   Reference to track if content was modified.
   *
   * @return string
   *   The processed content.
   */
  protected function processMediaEntityUrls($content, &$modified) {
    $media_storage = \Drupal::entityTypeManager()->getStorage('media');
    $file_storage = \Drupal::entityTypeManager()->getStorage('file');

    // First, match by data-entity-type="media" and data-entity-uuid attributes.
    // This works regardless of the URL (aliases, redirects, etc.).
    $uuid_pattern = '/<a\s[^>]*data-entity-type=["\']media["\'][^>]*data-entity-uuid=["\']([a-f0-9-]+)["\'][^>]*>/is';

    if (preg_match_all($uuid_pattern, $content, $uuid_matches, PREG_SET_ORDER)) {
      foreach ($uuid_matches as $match) {
        $uuid = $match[1];

        // Load media by UUID.
        $media_entities = $media_storage->loadByProperties(['uuid' => $uuid]);
        if (empty($media_entities)) {
          continue;
        }
        $media = reset($media_entities);

        // Process this media link.
        $content = $this->processMediaLink($content, $media, $file_storage, $modified);
      }
    }

    // Also check for data-entity-uuid before data-entity-type (attribute order may vary).
    $uuid_pattern_alt = '/<a\s[^>]*data-entity-uuid=["\']([a-f0-9-]+)["\'][^>]*data-entity-type=["\']media["\'][^>]*>/is';

    if (preg_match_all($uuid_pattern_alt, $content, $uuid_matches, PREG_SET_ORDER)) {
      foreach ($uuid_matches as $match) {
        $uuid = $match[1];

        $media_entities = $media_storage->loadByProperties(['uuid' => $uuid]);
        if (empty($media_entities)) {
          continue;
        }
        $media = reset($media_entities);

        $content = $this->processMediaLink($content, $media, $file_storage, $modified);
      }
    }

    // Fallback: Match anchor tags with href="/media/{id}" pattern.
    $id_pattern = '/<a\s[^>]*href=["\']\/media\/(\d+)["\'][^>]*>.*?<\/a>/is';

    if (preg_match_all($id_pattern, $content, $id_matches, PREG_SET_ORDER)) {
      foreach ($id_matches as $match) {
        $media_id = $match[1];

        $media = $media_storage->load($media_id);
        if (!$media) {
          continue;
        }

        $content = $this->processMediaLink($content, $media, $file_storage, $modified);
      }
    }

    return $content;
  }

  /**
   * Processes a single media link in the content.
   *
   * @param string $content
   *   The HTML content.
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param \Drupal\Core\Entity\EntityStorageInterface $file_storage
   *   The file storage.
   * @param bool &$modified
   *   Reference to track if content was modified.
   *
   * @return string
   *   The processed content.
   */
  protected function processMediaLink($content, $media, $file_storage, &$modified) {
    // Get the source file from the media.
    $source = $media->getSource();
    $source_field = $source->getSourceFieldDefinition($media->bundle->entity);
    if (!$source_field) {
      return $content;
    }

    $source_field_name = $source_field->getName();
    if (!$media->hasField($source_field_name)) {
      return $content;
    }

    $field_value = $media->get($source_field_name)->getValue();
    if (empty($field_value[0]['target_id'])) {
      return $content;
    }

    $fid = $field_value[0]['target_id'];

    // Load file to check type (skip images).
    $file = $file_storage->load($fid);
    if (!$file) {
      return $content;
    }

    $mime_type = $file->getMimeType();
    if ($mime_type && strpos($mime_type, 'image/') === 0) {
      return $content;
    }

    // Check if file is archived.
    $archive = $this->archiveService->getActiveArchiveByFid($fid);
    if (!$archive) {
      return $content;
    }

    $archive_url = '/archive-registry/' . $archive->id();
    $file_name = $archive->getFileName() ?: $media->getName();
    $media_uuid = $media->uuid();
    $media_id = $media->id();

    // Build pattern to match this specific media link.
    // Match by UUID or by media ID in href.
    $link_patterns = [
      // By UUID attribute.
      '/(<a\s[^>]*data-entity-uuid=["\'])' . preg_quote($media_uuid, '/') . '(["\'][^>]*href=["\'])([^"\']+)(["\'])([^>]*>)(.*?)(<\/a>)/is',
      // By UUID attribute (href before uuid).
      '/(<a\s[^>]*href=["\'])([^"\']+)(["\'][^>]*data-entity-uuid=["\'])' . preg_quote($media_uuid, '/') . '(["\'][^>]*>)(.*?)(<\/a>)/is',
      // By media ID in href.
      '/(<a\s[^>]*href=["\'])(\/media\/' . $media_id . ')(["\'])([^>]*>)(.*?)(<\/a>)/is',
    ];

    foreach ($link_patterns as $pattern) {
      if (preg_match($pattern, $content, $matches)) {
        $full_match = $matches[0];

        // Determine link content position based on pattern.
        $link_content = '';
        $is_uuid_pattern = (strpos($pattern, 'data-entity-uuid') !== FALSE);

        if ($is_uuid_pattern) {
          // UUID patterns have different capture groups.
          $link_content = $matches[6] ?? $matches[5] ?? '';
        }
        else {
          // ID pattern: groups are (before_href)(url)(quote)(rest)(content)(close).
          $link_content = $matches[5] ?? '';
        }

        // Check if this is an image link.
        $is_image_link = (stripos($link_content, '<img') !== FALSE);
        $label = $this->archiveService->getArchivedLabel();

        // Replace href with archive URL.
        $new_tag = preg_replace(
          '/href=["\'][^"\']+["\']/',
          'href="' . $archive_url . '"',
          $full_match
        );

        // Update or add aria-label to indicate archived status.
        $archived_aria = $file_name
          ? $file_name . ' - ' . $this->t('archived, opens archive detail page')
          : $this->t('archived, opens archive detail page');
        $new_tag = $this->setAriaLabelOnTag($new_tag, $archived_aria);

        if ($is_image_link) {
          // For image links, add/update title attribute.
          $archived_title = $file_name ? $file_name . ' (' . $label . ')' : $label;

          if (preg_match('/\stitle=["\']([^"\']*)["\']/', $new_tag, $title_match)) {
            if (stripos($title_match[1], $label) === FALSE && stripos($title_match[1], 'archived') === FALSE) {
              $new_title = $title_match[1] . ' (' . $label . ')';
              $new_tag = preg_replace(
                '/\stitle=["\'][^"\']*["\']/',
                ' title="' . htmlspecialchars($new_title) . '"',
                $new_tag
              );
            }
          }
          else {
            // Add title attribute after href.
            $new_tag = preg_replace(
              '/(href=["\'][^"\']+["\'])/',
              '$1 title="' . htmlspecialchars($archived_title) . '"',
              $new_tag
            );
          }
        }
        else {
          // For text links, add visible label if enabled.
          // Screen reader context is handled by aria-label on the <a> tag.
          if (strpos($new_tag, 'dai-archived-label') === FALSE) {
            if ($this->archiveService->shouldShowArchivedLabel()) {
              $label_with_parens = '(' . $label . ')';
              $visible_label = ' <span class="dai-archived-label" aria-hidden="true">' . htmlspecialchars($label_with_parens) . '</span>';

              $new_tag = preg_replace(
                '/(<\/a>)$/i',
                $visible_label . '$1',
                $new_tag
              );
            }
          }
        }

        $content = str_replace($full_match, $new_tag, $content);
        $modified = TRUE;
        break; // Only process once per media.
      }
    }

    return $content;
  }

  /**
   * Converts a stream URI to a relative URL path.
   *
   * @param string $uri
   *   The stream URI (e.g., public://files/doc.pdf).
   *
   * @return string|null
   *   The relative URL path (e.g., /sites/default/files/files/doc.pdf).
   */
  protected function streamUriToUrlPath($uri) {
    if (empty($uri)) {
      return NULL;
    }

    // Handle public:// stream.
    if (strpos($uri, 'public://') === 0) {
      $path = substr($uri, 9);
      return '/sites/default/files/' . $path;
    }

    // Handle private:// stream.
    if (strpos($uri, 'private://') === 0) {
      $path = substr($uri, 10);
      return '/system/files/' . $path;
    }

    // If it's already a URL path, return as-is.
    if (strpos($uri, '/sites/default/files/') === 0 || strpos($uri, '/system/files/') === 0) {
      return $uri;
    }

    // Handle full URLs.
    if (preg_match('#/sites/default/files/(.+)$#', $uri, $matches)) {
      return '/sites/default/files/' . $matches[1];
    }
    if (preg_match('#/system/files/(.+)$#', $uri, $matches)) {
      return '/system/files/' . $matches[1];
    }

    return NULL;
  }

  /**
   * Processes <video> and <audio> elements in HTML content.
   *
   * When any <source> within a video/audio element points to an archived file,
   * the entire element is replaced with an accessible placeholder. This prevents
   * broken players when the browser expects a media file but gets an HTML page.
   *
   * @param string $content
   *   The HTML content.
   * @param array $mappings
   *   URL mappings from getUrlMappings().
   * @param bool &$modified
   *   Reference to track if content was modified.
   *
   * @return string
   *   The processed content.
   */
  protected function processVideoAudioElements($content, array $mappings, &$modified) {
    if (empty($mappings)) {
      return $content;
    }

    // Process <video> elements.
    $content = $this->processMediaElements($content, 'video', $mappings, $modified);

    // Process <audio> elements.
    $content = $this->processMediaElements($content, 'audio', $mappings, $modified);

    return $content;
  }

  /**
   * Processes a specific type of media element (video or audio).
   *
   * @param string $content
   *   The HTML content.
   * @param string $tag_name
   *   The tag name ('video' or 'audio').
   * @param array $mappings
   *   URL mappings from getUrlMappings().
   * @param bool &$modified
   *   Reference to track if content was modified.
   *
   * @return string
   *   The processed content.
   */
  protected function processMediaElements($content, $tag_name, array $mappings, &$modified) {
    // Match the entire element including content and closing tag.
    // Pattern handles: <video ...>...</video> and <video ... />
    // Uses non-greedy matching and handles nested elements carefully.
    $pattern = '/<' . $tag_name . '\s[^>]*>.*?<\/' . $tag_name . '>/is';

    // Also handle self-closing <video /> or <audio /> (rare but possible).
    $self_closing_pattern = '/<' . $tag_name . '\s[^>]*\/>/is';

    // Find all matches.
    if (preg_match_all($pattern, $content, $matches)) {
      foreach ($matches[0] as $full_element) {
        $archive_info = $this->findArchivedSourceInElement($full_element, $mappings);
        if ($archive_info) {
          $placeholder = $this->buildMediaPlaceholder($tag_name, $archive_info);
          $content = str_replace($full_element, $placeholder, $content);
          $modified = TRUE;
        }
      }
    }

    // Handle self-closing elements.
    if (preg_match_all($self_closing_pattern, $content, $matches)) {
      foreach ($matches[0] as $full_element) {
        $archive_info = $this->findArchivedSourceInElement($full_element, $mappings);
        if ($archive_info) {
          $placeholder = $this->buildMediaPlaceholder($tag_name, $archive_info);
          $content = str_replace($full_element, $placeholder, $content);
          $modified = TRUE;
        }
      }
    }

    return $content;
  }

  /**
   * Finds if any source URL in a media element is archived.
   *
   * Checks both the src attribute on the element itself and any <source> children.
   *
   * @param string $element_html
   *   The full HTML of the media element.
   * @param array $mappings
   *   URL mappings from getUrlMappings().
   *
   * @return array|null
   *   Archive info array if an archived source was found, NULL otherwise.
   */
  protected function findArchivedSourceInElement($element_html, array $mappings) {
    // Collect all source URLs from the element.
    $source_urls = [];

    // Check for src attribute on the main element.
    if (preg_match('/\ssrc=["\']([^"\']+)["\']/i', $element_html, $match)) {
      $source_urls[] = $match[1];
    }

    // Check for <source src="..."> elements.
    if (preg_match_all('/<source\s[^>]*src=["\']([^"\']+)["\']/i', $element_html, $source_matches)) {
      $source_urls = array_merge($source_urls, $source_matches[1]);
    }

    // Check each source URL against our mappings.
    foreach ($source_urls as $source_url) {
      // Decode HTML entities in the URL.
      $decoded_url = html_entity_decode($source_url);

      foreach ($mappings as $original_pattern => $archive_info) {
        // Skip page and external types - those aren't media files.
        if (!empty($archive_info['is_page']) || !empty($archive_info['is_external'])) {
          continue;
        }

        // Check if the source URL matches this archived file.
        // Handle both exact matches and URL variations.
        if ($this->urlMatchesPattern($decoded_url, $original_pattern)) {
          return $archive_info;
        }
      }
    }

    return NULL;
  }

  /**
   * Checks if a URL matches an archived file pattern.
   *
   * Handles various URL formats: relative, absolute, with/without domain.
   *
   * @param string $url
   *   The URL to check.
   * @param string $pattern
   *   The archived file pattern to match against.
   *
   * @return bool
   *   TRUE if the URL matches the pattern.
   */
  protected function urlMatchesPattern($url, $pattern) {
    // Direct match.
    if ($url === $pattern) {
      return TRUE;
    }

    // Strip domain from URL if present.
    $url_path = preg_replace('#^https?://[^/]+#', '', $url);
    $pattern_path = preg_replace('#^https?://[^/]+#', '', $pattern);

    if ($url_path === $pattern_path) {
      return TRUE;
    }

    // Try URL-decoded comparison.
    if (urldecode($url_path) === urldecode($pattern_path)) {
      return TRUE;
    }

    // Check if pattern appears at the end of URL (handles full URLs with domain).
    if (substr($url, -strlen($pattern)) === $pattern) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Builds the accessible placeholder HTML for an archived media element.
   *
   * @param string $media_type
   *   The media type ('video' or 'audio').
   * @param array $archive_info
   *   Archive info from URL mappings.
   *
   * @return string
   *   The placeholder HTML.
   */
  protected function buildMediaPlaceholder($media_type, array $archive_info) {
    $archive_url = $archive_info['url'];
    $file_name = $archive_info['name'] ?? '';

    // Build simple inline link text with "(Archived)" suffix.
    // Consistent with how other archived content (documents, pages) is displayed.
    $link_text = $file_name ?: $this->t('Archived @type', ['@type' => $media_type]);

    // Check if we should show the archived label.
    if ($this->archiveService->shouldShowArchivedLabel()) {
      $archived_label = $this->archiveService->getArchivedLabel();
      $link_text .= ' (' . $archived_label . ')';
    }

    return '<a href="' . htmlspecialchars($archive_url) . '">' . htmlspecialchars($link_text) . '</a>';
  }

  /**
   * Adds path alias mappings for an internal path.
   *
   * Resolves path aliases to/from system paths to ensure links are matched
   * regardless of whether Views uses the alias or system path.
   * Also handles language prefixes for multilingual sites.
   *
   * @param string $path
   *   The internal path (e.g., /en/recipes/fiery-chili-sauce or /node/9).
   * @param array $mapping_data
   *   The mapping data to add.
   */
  protected function addPathAliasMappings($path, array $mapping_data) {
    $path_alias_manager = \Drupal::service('path_alias.manager');
    $language_manager = \Drupal::languageManager();

    // Get configured languages to detect language prefixes.
    $languages = $language_manager->getLanguages();
    $language_codes = array_keys($languages);
    $is_multilingual = count($languages) > 1;

    // Check if path starts with a language prefix.
    $path_parts = explode('/', trim($path, '/'));
    $first_segment = $path_parts[0] ?? '';
    $path_without_prefix = $path;
    $detected_langcode = NULL;

    if (in_array($first_segment, $language_codes) && count($path_parts) > 1) {
      // Path has a language prefix - extract the path without it.
      $detected_langcode = $first_segment;
      array_shift($path_parts);
      $path_without_prefix = '/' . implode('/', $path_parts);

      // On multilingual sites, do NOT add path without language prefix
      // as it would incorrectly match other language versions.
      // On single-language sites, add it for flexibility.
      if (!$is_multilingual) {
        $this->urlMappings[$path_without_prefix] = $mapping_data;
        $without_slash = ltrim($path_without_prefix, '/');
        $this->urlMappings[$without_slash] = $mapping_data;
      }
    }

    // Use the path without language prefix for alias resolution.
    $lookup_path = $path_without_prefix;

    // Try to resolve alias to system path.
    $system_path = $path_alias_manager->getPathByAlias($lookup_path, $detected_langcode);
    if ($system_path !== $lookup_path) {
      // lookup_path is an alias, system_path is the real path (e.g., /node/9).

      if ($detected_langcode) {
        // Multilingual: only add language-prefixed system path.
        $prefixed_system = '/' . $detected_langcode . $system_path;
        $this->urlMappings[$prefixed_system] = $mapping_data;
        $prefixed_without_slash = ltrim($prefixed_system, '/');
        $this->urlMappings[$prefixed_without_slash] = $mapping_data;
      }
      else {
        // Non-multilingual or no prefix detected: add system path as-is.
        $this->urlMappings[$system_path] = $mapping_data;
        $system_without_slash = ltrim($system_path, '/');
        $this->urlMappings[$system_without_slash] = $mapping_data;
      }
    }
    else {
      // lookup_path might be a system path - try to get its alias.
      $alias_path = $path_alias_manager->getAliasByPath($lookup_path, $detected_langcode);
      if ($alias_path !== $lookup_path) {
        if ($detected_langcode) {
          // Multilingual: only add language-prefixed alias.
          $prefixed_alias = '/' . $detected_langcode . $alias_path;
          $this->urlMappings[$prefixed_alias] = $mapping_data;
          $prefixed_without_slash = ltrim($prefixed_alias, '/');
          $this->urlMappings[$prefixed_without_slash] = $mapping_data;
        }
        else {
          // Non-multilingual or no prefix detected: add alias as-is.
          $this->urlMappings[$alias_path] = $mapping_data;
          $alias_without_slash = ltrim($alias_path, '/');
          $this->urlMappings[$alias_without_slash] = $mapping_data;
        }
      }
    }
  }

  /**
   * Sets or replaces the aria-label attribute on an anchor tag fragment.
   *
   * Used in the main link rewriting callback where $rest_of_tag is the portion
   * of the <a> tag after the href quote.
   *
   * @param string $tag_fragment
   *   The portion of the <a> tag after the href (e.g., ' class="foo">').
   * @param string $aria_label
   *   The aria-label value to set.
   *
   * @return string
   *   The tag fragment with updated aria-label.
   */
  protected function setAriaLabel($tag_fragment, $aria_label) {
    $escaped_label = htmlspecialchars($aria_label);

    // Replace existing aria-label if present.
    if (preg_match('/\saria-label=["\'][^"\']*["\']/', $tag_fragment)) {
      return preg_replace(
        '/\saria-label=["\'][^"\']*["\']/',
        ' aria-label="' . $escaped_label . '"',
        $tag_fragment
      );
    }

    // Add aria-label before the closing >.
    return ' aria-label="' . $escaped_label . '"' . $tag_fragment;
  }

  /**
   * Sets or replaces the aria-label attribute on a full <a> tag.
   *
   * Used in processMediaLink where $new_tag is the entire <a>...</a> element.
   *
   * @param string $tag
   *   The full <a> tag HTML.
   * @param string $aria_label
   *   The aria-label value to set.
   *
   * @return string
   *   The tag with updated aria-label.
   */
  protected function setAriaLabelOnTag($tag, $aria_label) {
    $escaped_label = htmlspecialchars($aria_label);

    // Replace existing aria-label if present.
    if (preg_match('/\saria-label=["\'][^"\']*["\']/', $tag)) {
      return preg_replace(
        '/\saria-label=["\'][^"\']*["\']/',
        ' aria-label="' . $escaped_label . '"',
        $tag
      );
    }

    // Add aria-label after the opening <a.
    return preg_replace(
      '/^(<a\s)/',
      '$1aria-label="' . $escaped_label . '" ',
      $tag
    );
  }

  /**
   * Translates a string.
   *
   * @param string $string
   *   The string to translate.
   * @param array $args
   *   Replacement arguments.
   *
   * @return string
   *   The translated string.
   */
  protected function t($string, array $args = []) {
    return \Drupal::translation()->translate($string, $args);
  }

}
