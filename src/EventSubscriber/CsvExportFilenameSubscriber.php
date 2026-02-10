<?php

namespace Drupal\digital_asset_inventory\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rewrites the CSV export filename to include site name and download date.
 *
 * Listens to KernelEvents::RESPONSE after Views rendering and replaces the
 * static "digital-assets.csv" filename with a dynamic one:
 * digital-assets__{site-name-slug}__{YYYY-MM-DD}.csv
 *
 * Guard conditions ensure only module CSV exports are affected:
 * - Request path matches a known CSV export path
 * - Response Content-Type is text/csv
 * - Response has Content-Disposition starting with "attachment"
 *
 * Handles both:
 * - Inventory CSV: /admin/digital-asset-inventory/csv
 * - Archive audit CSV: /admin/digital-asset-inventory/archive/csv
 */
class CsvExportFilenameSubscriber implements EventSubscriberInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    DateFormatterInterface $date_formatter,
    TransliterationInterface $transliteration,
    TimeInterface $time,
  ) {
    $this->configFactory = $config_factory;
    $this->dateFormatter = $date_formatter;
    $this->transliteration = $transliteration;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after Views rendering (priority -50).
    return [
      KernelEvents::RESPONSE => ['onResponse', -50],
    ];
  }

  /**
   * Map of request paths to filename prefixes.
   */
  protected const PATH_PREFIX_MAP = [
    '/admin/digital-asset-inventory/csv' => 'digital-assets',
    '/admin/digital-asset-inventory/archive/csv' => 'archived-assets-audit',
  ];

  /**
   * Rewrites the Content-Disposition filename for CSV exports.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $response = $event->getResponse();

    // Guard: Only act on known CSV export paths.
    $path = $request->getPathInfo();
    $prefix = self::PATH_PREFIX_MAP[$path] ?? NULL;
    if ($prefix === NULL) {
      return;
    }

    // Guard: Response must be text/csv.
    $content_type = $response->headers->get('Content-Type', '');
    if (strpos($content_type, 'text/csv') === FALSE) {
      return;
    }

    // Guard: Response must have Content-Disposition starting with "attachment".
    $disposition = $response->headers->get('Content-Disposition', '');
    if (strpos($disposition, 'attachment') !== 0) {
      return;
    }

    // Build the dynamic filename.
    $filename = $this->buildFilename($prefix);

    // Set the new Content-Disposition header.
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
  }

  /**
   * Builds the dynamic CSV filename.
   *
   * Format: {prefix}__{site-name-slug}__{YYYY-MM-DD}.csv
   *
   * @param string $prefix
   *   The filename prefix (e.g., "digital-assets", "archive-audit-export").
   *
   * @return string
   *   The filename.
   */
  protected function buildFilename(string $prefix): string {
    $slug = $this->getSiteNameSlug();
    $date = $this->getFormattedDate();

    return $prefix . '__' . $slug . '__' . $date . '.csv';
  }

  /**
   * Converts the Drupal site name to a file-safe slug.
   *
   * @return string
   *   The slugified site name, or "drupal-site" as fallback.
   */
  protected function getSiteNameSlug(): string {
    $site_name = $this->configFactory->get('system.site')->get('name') ?? '';

    // 1. Transliterate to ASCII.
    $slug = $this->transliteration->transliterate($site_name, 'en');

    // 2. Convert to lowercase.
    $slug = strtolower($slug);

    // 3. Trim whitespace.
    $slug = trim($slug);

    // 4. Replace non-alphanumeric sequences with a single hyphen.
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

    // 5. Collapse multiple hyphens (already handled by step 4, but explicit).
    $slug = preg_replace('/-+/', '-', $slug);

    // 6. Remove leading/trailing hyphens.
    $slug = trim($slug, '-');

    // 7. Fallback if empty.
    if ($slug === '') {
      $slug = 'drupal-site';
    }

    return $slug;
  }

  /**
   * Gets the current date formatted as YYYY-MM-DD in the site's timezone.
   *
   * @return string
   *   The formatted date.
   */
  protected function getFormattedDate(): string {
    return $this->dateFormatter->format(
      $this->time->getRequestTime(),
      'custom',
      'Y-m-d',
    );
  }

}
