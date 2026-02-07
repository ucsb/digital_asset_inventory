<?php

namespace Drupal\digital_asset_inventory;

/**
 * Trait for resolving public file paths in Drupal.
 *
 * Principle: Discover and parse using universal path anchors; generate using
 * site-aware services.
 *
 * Separates three concerns:
 * - **Discovery** (finding file URLs in text): Universal regex anchors
 *   (`/sites/[^/]+/files/` and `/system/files/`) that match ALL Drupal
 *   installations, plus a dynamic fallback for non-standard public file
 *   paths (e.g., `/files/...` on some hosting setups).
 * - **Construction** (building URLs from stream URIs): Dynamic
 *   `getPublicFilesBasePath()` to produce the correct URL for the current
 *   site's configuration.
 * - **Conversion** (URL-to-stream-URI): Same universal + dynamic patterns
 *   as discovery, converting matched paths back to `public://` or
 *   `private://` stream URIs.
 *
 * The `/system/files/` route for private files is universal across all Drupal
 * setups and does not need dynamic resolution.
 *
 * Requires implementing classes to have $this->fileUrlGenerator available
 * (an instance of \Drupal\Core\File\FileUrlGeneratorInterface).
 */
trait FilePathResolver {

  /**
   * Cached public files base path.
   *
   * @var string|null
   */
  protected ?string $publicFilesBasePath = NULL;

  /**
   * Gets the public files base path dynamically.
   *
   * Uses FileUrlGeneratorInterface to resolve the actual configured path
   * (e.g., /sites/default/files, /sites/mysite.com/files, /files, etc.).
   *
   * Use this for **construction** (building URLs), not discovery.
   *
   * @return string
   *   The public files base path with leading slash, no trailing slash.
   */
  protected function getPublicFilesBasePath(): string {
    if ($this->publicFilesBasePath === NULL) {
      $url = $this->fileUrlGenerator->generateString('public://');
      // Ensure leading slash, strip trailing slash.
      $this->publicFilesBasePath = '/' . ltrim(rtrim($url, '/'), '/');
    }
    return $this->publicFilesBasePath;
  }

  /**
   * Gets the preg_quote'd version of the public files base path.
   *
   * Suitable for use in regex patterns with '#' as the delimiter.
   * Use for construction-related regex (matching the current site's path)
   * or as a dynamic discovery fallback for non-standard public file paths.
   *
   * @return string
   *   The escaped base path for use in regex (includes leading slash).
   */
  protected function getPublicFilesBasePathRegex(): string {
    return preg_quote($this->getPublicFilesBasePath(), '#');
  }

  /**
   * Gets a universal regex fragment matching public file paths.
   *
   * Returns `sites/[^/]+/files` which matches ALL standard Drupal
   * installations:
   * - Default: sites/default/files
   * - Multisite: sites/mysite.com/files
   * - Site Factory: sites/factorysite/files
   *
   * This is a **fragment** without a leading slash — call sites must prepend
   * `/` or `/?` as needed for their context. For non-standard public file
   * paths (e.g., `/files/...`), use `getPublicFilesBasePathRegex()` as an
   * additional fallback.
   *
   * @return string
   *   A regex fragment (no delimiters, no leading slash) for use inside a
   *   larger pattern.
   */
  protected function getPublicFilesPathPattern(): string {
    return 'sites/[^/]+/files';
  }

  /**
   * Converts a public stream relative path to a URL path.
   *
   * This is a **construction** method — it builds the correct URL for the
   * current site's configuration.
   *
   * @param string $relative
   *   The relative path within public:// (e.g., 'document.pdf').
   *
   * @return string
   *   The URL path (e.g., /sites/default/files/document.pdf).
   */
  protected function publicStreamToUrlPath(string $relative): string {
    return $this->getPublicFilesBasePath() . '/' . $relative;
  }

  /**
   * Extracts all local file URLs from text content.
   *
   * Canonical discovery helper that finds file URLs using universal pattern
   * anchors, with a dynamic fallback for the current site's public files
   * base path. Handles HTML entity encoding (e.g., &amp; in query strings).
   *
   * Pattern priority (universal first, dynamic fallback second):
   * 1. /sites/[^/]+/files/... — matches all standard Drupal installations
   * 2. {dynamic_base}/...     — covers /files or other non-standard configs
   * 3. /system/files/...      — universal private file route
   *
   * @param string $text
   *   The text content to scan (HTML, plain text, etc.).
   *
   * @return array
   *   Array of unique file URL paths found in the text.
   */
  protected function extractLocalFileUrlsFromText(string $text): array {
    // Decode HTML entities so href/src parsing is reliable.
    // Handles &amp; in query strings, &#039; in paths, etc.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $matches = [];

    $publicBase = $this->getPublicFilesBasePathRegex();

    $patterns = [
      // Universal public: multisite + Site Factory + default.
      '#/sites/[^/]+/files/[^"\'>\s\?\#]+#i',

      // Dynamic public base for current site (covers /files or other configs).
      '#(?:' . $publicBase . ')/[^"\'>\s\?\#]+#i',

      // Private: universal route.
      '#/system/files/[^"\'>\s\?\#]+#i',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match_all($pattern, $text, $found)) {
        foreach ($found[0] as $url) {
          $url = preg_replace('/[?#].*$/', '', $url);
          $matches[] = $url;
        }
      }
    }

    return array_values(array_unique($matches));
  }

  /**
   * Extracts all local file URLs and converts them to stream URIs.
   *
   * Convenience wrapper around extractLocalFileUrlsFromText() that returns
   * Drupal stream URIs (public://, private://) instead of URL paths.
   *
   * @param string $text
   *   The text content to scan (HTML, plain text, etc.).
   *
   * @return array
   *   Array of unique stream URIs found in the text.
   */
  protected function extractLocalFileUrisFromText(string $text): array {
    $uris = [];
    foreach ($this->extractLocalFileUrlsFromText($text) as $url) {
      if ($uri = $this->urlPathToStreamUri($url)) {
        $uris[] = $uri;
      }
    }
    return array_values(array_unique($uris));
  }

  /**
   * Converts a URL or path to a Drupal stream URI.
   *
   * Uses universal patterns for **conversion** (URL-to-stream-URI), with a
   * dynamic fallback for the current site's public files base path.
   *
   * Conversion order (first match wins):
   * 1. Legacy private: `sites/[^/]+/files/private/` → `private://`
   * 2. Universal public: `sites/[^/]+/files/` → `public://`
   * 3. Dynamic private: `{current_base}/private/` → `private://`
   * 4. Dynamic public: `{current_base}/` → `public://` (covers /files, etc.)
   * 5. Universal private: `/system/files/` → `private://`
   *
   * Uses rawurldecode() on path segments to correctly mirror URL encoding
   * semantics (Drupal uses rawurlencode in paths).
   *
   * Handles full absolute URLs, relative paths, and already-resolved
   * stream URIs (returned as-is).
   *
   * @param string $url_or_path
   *   The URL or path to convert.
   *
   * @return string|null
   *   The stream URI (public:// or private://) or NULL if not a local file.
   */
  protected function urlPathToStreamUri(string $url_or_path): ?string {
    // Trim common wrappers (quotes/whitespace from HTML parsing).
    $url_or_path = trim($url_or_path, " \t\n\r\0\x0B\"'");

    // If it's already a stream URI, return as-is.
    if (strpos($url_or_path, 'public://') === 0 || strpos($url_or_path, 'private://') === 0) {
      return $url_or_path;
    }

    // Handle scheme-relative URLs (//example.edu/sites/site/files/a.pdf).
    if (strpos($url_or_path, '//') === 0) {
      $url_or_path = 'https:' . $url_or_path;
    }

    // Extract path component from full URLs.
    $path = $url_or_path;
    if (strpos($url_or_path, 'http://') === 0 || strpos($url_or_path, 'https://') === 0) {
      $parsed = parse_url($url_or_path);
      $path = $parsed['path'] ?? '';
    }

    // Strip query strings and fragments.
    $path = preg_replace('/[?#].*$/', '', $path);

    // Legacy: some sites serve private files under the public files path
    // (e.g., /sites/default/files/private/doc.pdf → private://doc.pdf).
    // Must check before the general public pattern to avoid mapping to
    // public://private/doc.pdf.
    if (preg_match('#/?sites/[^/]+/files/private/(.+)$#', $path, $matches)) {
      return 'private://' . rawurldecode($matches[1]);
    }

    // Check for public files path (universal pattern).
    if (preg_match('#/?sites/[^/]+/files/(.+)$#', $path, $matches)) {
      return 'public://' . rawurldecode($matches[1]);
    }

    // Dynamic base + /private/ legacy fallback.
    $base_with_private = $this->getPublicFilesBasePath() . '/private/';
    if (strpos($path, $base_with_private) === 0) {
      $relative = substr($path, strlen($base_with_private));
      return 'private://' . rawurldecode($relative);
    }

    // Check for current site's public base path (dynamic fallback).
    // Covers non-standard configs like /files/... that the universal
    // pattern would miss. Prefix match avoids accidental mid-path hits.
    $base_with_slash = $this->getPublicFilesBasePath() . '/';
    if (strpos($path, $base_with_slash) === 0) {
      $relative = substr($path, strlen($base_with_slash));
      return 'public://' . rawurldecode($relative);
    }

    // Check for private file path: /system/files/...
    if (preg_match('#/?system/files/(.+)$#', $path, $matches)) {
      return 'private://' . rawurldecode($matches[1]);
    }

    return NULL;
  }

}
