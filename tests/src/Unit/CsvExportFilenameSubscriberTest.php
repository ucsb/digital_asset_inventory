<?php

declare(strict_types=1);

namespace Drupal\Tests\digital_asset_inventory\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\digital_asset_inventory\EventSubscriber\CsvExportFilenameSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test harness exposing protected methods of CsvExportFilenameSubscriber.
 *
 * @internal
 */
final class TestableCsvExportFilenameSubscriber extends CsvExportFilenameSubscriber {

  public function doGetSiteNameSlug(): string {
    return $this->getSiteNameSlug();
  }

  public function doBuildFilename(string $prefix): string {
    return $this->buildFilename($prefix);
  }

}

/**
 * Unit tests for CsvExportFilenameSubscriber.
 *
 * Tests slugification logic, filename building, and response event guards.
 * 18 test cases.
 *
 * @group digital_asset_inventory
 * @coversDefaultClass \Drupal\digital_asset_inventory\EventSubscriber\CsvExportFilenameSubscriber
 */
class CsvExportFilenameSubscriberTest extends UnitTestCase {

  /**
   * The subscriber under test.
   */
  protected TestableCsvExportFilenameSubscriber $subscriber;

  /**
   * The mocked config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The mocked transliteration service.
   */
  protected TransliterationInterface $transliteration;

  /**
   * The mocked date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The mocked time service.
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->dateFormatter = $this->createMock(DateFormatterInterface::class);
    $this->transliteration = $this->createMock(TransliterationInterface::class);
    $this->time = $this->createMock(TimeInterface::class);

    // Default: transliterate returns the input unchanged.
    $this->transliteration->method('transliterate')
      ->willReturnCallback(fn($string) => $string);

    $this->subscriber = new TestableCsvExportFilenameSubscriber(
      $this->configFactory,
      $this->dateFormatter,
      $this->transliteration,
      $this->time,
    );
  }

  /**
   * Sets the site name in the mock config.
   */
  protected function setSiteName(string $name): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('name')
      ->willReturn($name);
    $this->configFactory->method('get')
      ->with('system.site')
      ->willReturn($config);
  }

  // -----------------------------------------------------------------------
  // getSiteNameSlug() — 11 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getSiteNameSlug
   * @dataProvider siteNameSlugProvider
   */
  public function testGetSiteNameSlug(string $siteName, string $expected): void {
    $this->setSiteName($siteName);
    $this->assertSame($expected, $this->subscriber->doGetSiteNameSlug());
  }

  /**
   * Data provider for getSiteNameSlug().
   */
  public static function siteNameSlugProvider(): array {
    return [
      'simple name' => ['UCSB Web Theme', 'ucsb-web-theme'],
      'already lowercase' => ['my site', 'my-site'],
      'with punctuation' => ['My Site! (v2.0)', 'my-site-v2-0'],
      'multiple spaces' => ['My    Site', 'my-site'],
      'leading/trailing spaces' => ['  My Site  ', 'my-site'],
      'numbers only' => ['12345', '12345'],
      'single word' => ['Drupal', 'drupal'],
      'hyphens preserved' => ['my-site-name', 'my-site-name'],
      'special chars only' => ['!!!@@@###', 'drupal-site'],
      'empty string' => ['', 'drupal-site'],
      'mixed alphanumeric and symbols' => ['Site #1 — Production', 'site-1-production'],
    ];
  }

  /**
   * Tests transliteration of accented characters.
   *
   * @covers ::getSiteNameSlug
   */
  public function testSlugTransliteratesAccents(): void {
    $this->setSiteName('Café Résumé');
    // Mock transliteration converting accented chars to ASCII.
    $this->transliteration = $this->createMock(TransliterationInterface::class);
    $this->transliteration->method('transliterate')
      ->willReturn('Cafe Resume');

    $this->subscriber = new TestableCsvExportFilenameSubscriber(
      $this->configFactory,
      $this->dateFormatter,
      $this->transliteration,
      $this->time,
    );

    $this->assertSame('cafe-resume', $this->subscriber->doGetSiteNameSlug());
  }

  // -----------------------------------------------------------------------
  // buildFilename() — 2 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::buildFilename
   */
  public function testBuildFilenameInventory(): void {
    $this->setSiteName('UCSB Web Theme');
    $this->time->method('getRequestTime')->willReturn(1707350400);
    $this->dateFormatter->method('format')
      ->willReturn('2026-02-08');

    $this->assertSame(
      'digital-assets__ucsb-web-theme__2026-02-08.csv',
      $this->subscriber->doBuildFilename('digital-assets')
    );
  }

  /**
   * @covers ::buildFilename
   */
  public function testBuildFilenameArchiveAudit(): void {
    $this->setSiteName('My University');
    $this->time->method('getRequestTime')->willReturn(1707350400);
    $this->dateFormatter->method('format')
      ->willReturn('2026-02-08');

    $this->assertSame(
      'archived-assets-audit__my-university__2026-02-08.csv',
      $this->subscriber->doBuildFilename('archived-assets-audit')
    );
  }

  // -----------------------------------------------------------------------
  // onResponse() guard conditions — 5 cases.
  // -----------------------------------------------------------------------

  /**
   * Creates a ResponseEvent for testing.
   */
  protected function createResponseEvent(
    string $path,
    string $contentType = 'text/csv',
    string $disposition = 'attachment; filename="test.csv"',
  ): ResponseEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $request = Request::create($path);
    $response = new Response('csv,data');
    $response->headers->set('Content-Type', $contentType);
    $response->headers->set('Content-Disposition', $disposition);

    return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseSkipsUnknownPath(): void {
    $event = $this->createResponseEvent('/admin/some-other-page');
    $this->subscriber->onResponse($event);

    // Disposition unchanged.
    $this->assertSame(
      'attachment; filename="test.csv"',
      $event->getResponse()->headers->get('Content-Disposition')
    );
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseSkipsNonCsvContentType(): void {
    $event = $this->createResponseEvent(
      '/admin/digital-asset-inventory/csv',
      'text/html',
    );
    $this->subscriber->onResponse($event);

    $this->assertSame(
      'attachment; filename="test.csv"',
      $event->getResponse()->headers->get('Content-Disposition')
    );
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseSkipsNonAttachmentDisposition(): void {
    $event = $this->createResponseEvent(
      '/admin/digital-asset-inventory/csv',
      'text/csv',
      'inline',
    );
    $this->subscriber->onResponse($event);

    $this->assertSame(
      'inline',
      $event->getResponse()->headers->get('Content-Disposition')
    );
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseRewritesInventoryCsvFilename(): void {
    $this->setSiteName('Demo');
    $this->time->method('getRequestTime')->willReturn(1707350400);
    $this->dateFormatter->method('format')->willReturn('2026-02-09');

    $event = $this->createResponseEvent('/admin/digital-asset-inventory/csv');
    $this->subscriber->onResponse($event);

    $this->assertSame(
      'attachment; filename="digital-assets__demo__2026-02-09.csv"',
      $event->getResponse()->headers->get('Content-Disposition')
    );
  }

  /**
   * @covers ::onResponse
   */
  public function testOnResponseRewritesArchiveAuditCsvFilename(): void {
    $this->setSiteName('Demo');
    $this->time->method('getRequestTime')->willReturn(1707350400);
    $this->dateFormatter->method('format')->willReturn('2026-02-09');

    $event = $this->createResponseEvent('/admin/digital-asset-inventory/archive/csv');
    $this->subscriber->onResponse($event);

    $this->assertSame(
      'attachment; filename="archived-assets-audit__demo__2026-02-09.csv"',
      $event->getResponse()->headers->get('Content-Disposition')
    );
  }

}
