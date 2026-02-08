<?php

declare(strict_types=1);

namespace Drupal\Tests\digital_asset_inventory\Unit;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\digital_asset_inventory\FilePathResolver;
use Drupal\Tests\UnitTestCase;

/**
 * Test harness hosting the FilePathResolver trait.
 *
 * @internal
 */
final class TestFilePathResolverHost {

  use FilePathResolver;

  public function __construct(
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public function doGetPublicFilesBasePath(): string {
    return $this->getPublicFilesBasePath();
  }

  public function doGetPublicFilesBasePathRegex(): string {
    return $this->getPublicFilesBasePathRegex();
  }

  public function doGetPublicFilesPathPattern(): string {
    return $this->getPublicFilesPathPattern();
  }

  public function doPublicStreamToUrlPath(string $relative): string {
    return $this->publicStreamToUrlPath($relative);
  }

  public function doUrlPathToStreamUri(string $url): ?string {
    return $this->urlPathToStreamUri($url);
  }

  public function doExtractLocalFileUrlsFromText(string $text): array {
    return $this->extractLocalFileUrlsFromText($text);
  }

  public function doExtractLocalFileUrisFromText(string $text): array {
    return $this->extractLocalFileUrisFromText($text);
  }

}

/**
 * Tests the FilePathResolver trait.
 *
 * @coversDefaultClass \Drupal\digital_asset_inventory\FilePathResolver
 * @group digital_asset_inventory
 */
class FilePathResolverTest extends UnitTestCase {

  /**
   * The mock file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileUrlGenerator;

  /**
   * The test host using the FilePathResolver trait.
   *
   * @var \Drupal\Tests\digital_asset_inventory\Unit\TestFilePathResolverHost
   */
  protected $host;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $this->fileUrlGenerator->method('generateString')
      ->with('public://')
      ->willReturn('/sites/default/files');

    $this->host = new TestFilePathResolverHost($this->fileUrlGenerator);
  }

  /**
   * Creates a host with a custom base path.
   *
   * @param string $basePath
   *   The base path to configure.
   *
   * @return \Drupal\Tests\digital_asset_inventory\Unit\TestFilePathResolverHost
   *   A new host configured with the given base path.
   */
  protected function createHostWithBasePath(string $basePath): TestFilePathResolverHost {
    $generator = $this->createMock(FileUrlGeneratorInterface::class);
    $generator->method('generateString')
      ->with('public://')
      ->willReturn($basePath);
    return new TestFilePathResolverHost($generator);
  }

  // -----------------------------------------------------------------------
  // 3.1.1 getPublicFilesBasePath() — 4 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getPublicFilesBasePath
   */
  public function testGetPublicFilesBasePathDefault(): void {
    $this->assertSame('/sites/default/files', $this->host->doGetPublicFilesBasePath());
  }

  /**
   * @covers ::getPublicFilesBasePath
   */
  public function testGetPublicFilesBasePathMultisite(): void {
    $host = $this->createHostWithBasePath('/sites/example.edu/files');
    $this->assertSame('/sites/example.edu/files', $host->doGetPublicFilesBasePath());
  }

  /**
   * @covers ::getPublicFilesBasePath
   */
  public function testGetPublicFilesBasePathLeadingSlashMissing(): void {
    $host = $this->createHostWithBasePath('sites/default/files');
    $this->assertSame('/sites/default/files', $host->doGetPublicFilesBasePath());
  }

  /**
   * @covers ::getPublicFilesBasePath
   */
  public function testGetPublicFilesBasePathTrailingSlashPresent(): void {
    $host = $this->createHostWithBasePath('/sites/default/files/');
    $this->assertSame('/sites/default/files', $host->doGetPublicFilesBasePath());
  }

  // -----------------------------------------------------------------------
  // 3.1.2 getPublicFilesBasePathRegex() — 2 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getPublicFilesBasePathRegex
   */
  public function testGetPublicFilesBasePathRegexDefault(): void {
    $this->assertSame('/sites/default/files', $this->host->doGetPublicFilesBasePathRegex());
  }

  /**
   * @covers ::getPublicFilesBasePathRegex
   */
  public function testGetPublicFilesBasePathRegexDotInHost(): void {
    $host = $this->createHostWithBasePath('/sites/example.edu/files');
    $this->assertSame('/sites/example\.edu/files', $host->doGetPublicFilesBasePathRegex());
  }

  // -----------------------------------------------------------------------
  // 3.1.3 getPublicFilesPathPattern() — 1 case.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getPublicFilesPathPattern
   */
  public function testGetPublicFilesPathPatternReturnsUniversalFragment(): void {
    $this->assertSame('sites/[^/]+/files', $this->host->doGetPublicFilesPathPattern());
  }

  // -----------------------------------------------------------------------
  // 3.1.4 publicStreamToUrlPath() — 2 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::publicStreamToUrlPath
   */
  public function testPublicStreamToUrlPathSimple(): void {
    $this->assertSame('/sites/default/files/document.pdf', $this->host->doPublicStreamToUrlPath('document.pdf'));
  }

  /**
   * @covers ::publicStreamToUrlPath
   */
  public function testPublicStreamToUrlPathNested(): void {
    $this->assertSame(
      '/sites/default/files/archive/2025/report.pdf',
      $this->host->doPublicStreamToUrlPath('archive/2025/report.pdf')
    );
  }

  // -----------------------------------------------------------------------
  // 3.1.5 urlPathToStreamUri() — 22 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::urlPathToStreamUri
   * @dataProvider urlPathToStreamUriProvider
   */
  public function testUrlPathToStreamUri(string $input, ?string $expected, ?string $basePath = NULL): void {
    $host = $basePath !== NULL ? $this->createHostWithBasePath($basePath) : $this->host;
    $this->assertSame($expected, $host->doUrlPathToStreamUri($input));
  }

  /**
   * Data provider for urlPathToStreamUri().
   */
  public static function urlPathToStreamUriProvider(): array {
    return [
      // Universal patterns.
      'universal public — default site' => [
        '/sites/default/files/doc.pdf',
        'public://doc.pdf',
      ],
      'universal public — multisite' => [
        '/sites/example.edu/files/doc.pdf',
        'public://doc.pdf',
      ],
      'universal public — nested path' => [
        '/sites/default/files/archive/2025/report.pdf',
        'public://archive/2025/report.pdf',
      ],
      'legacy private under public' => [
        '/sites/default/files/private/doc.pdf',
        'private://doc.pdf',
      ],
      'legacy private — multisite' => [
        '/sites/example.edu/files/private/secret.pdf',
        'private://secret.pdf',
      ],
      'universal private — system/files' => [
        '/system/files/doc.pdf',
        'private://doc.pdf',
      ],
      'universal private — nested' => [
        '/system/files/reports/q4.pdf',
        'private://reports/q4.pdf',
      ],

      // Dynamic fallback (non-standard /files base path).
      'dynamic public' => [
        '/files/doc.pdf',
        'public://doc.pdf',
        '/files',
      ],
      'dynamic private' => [
        '/files/private/doc.pdf',
        'private://doc.pdf',
        '/files',
      ],

      // Full URL handling.
      'HTTPS absolute URL' => [
        'https://example.edu/sites/default/files/doc.pdf',
        'public://doc.pdf',
      ],
      'HTTP absolute URL' => [
        'http://example.edu/sites/default/files/doc.pdf',
        'public://doc.pdf',
      ],
      'scheme-relative URL' => [
        '//example.edu/sites/default/files/doc.pdf',
        'public://doc.pdf',
      ],

      // Passthrough and edge cases.
      'already public stream URI' => [
        'public://doc.pdf',
        'public://doc.pdf',
      ],
      'already private stream URI' => [
        'private://doc.pdf',
        'private://doc.pdf',
      ],
      'unrecognized path' => [
        '/some/random/path.pdf',
        NULL,
      ],
      'empty string' => [
        '',
        NULL,
      ],
      'external URL (no local path)' => [
        'https://cdn.example.com/file.pdf',
        NULL,
      ],

      // Input sanitization.
      'wrapped in double quotes' => [
        '"/sites/default/files/doc.pdf"',
        'public://doc.pdf',
      ],
      'wrapped in single quotes' => [
        "'/sites/default/files/doc.pdf'",
        'public://doc.pdf',
      ],
      'leading/trailing whitespace' => [
        '  /sites/default/files/doc.pdf  ',
        'public://doc.pdf',
      ],
      'URL-encoded path' => [
        '/sites/default/files/My%20Report.pdf',
        'public://My Report.pdf',
      ],
      'query string stripped' => [
        'https://example.edu/sites/default/files/doc.pdf?itok=abc123',
        'public://doc.pdf',
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // 3.1.6 extractLocalFileUrlsFromText() — 11 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::extractLocalFileUrlsFromText
   * @dataProvider extractLocalFileUrlsProvider
   */
  public function testExtractLocalFileUrlsFromText(string $text, array $expected, ?string $basePath = NULL): void {
    $host = $basePath !== NULL ? $this->createHostWithBasePath($basePath) : $this->host;
    $this->assertSame($expected, $host->doExtractLocalFileUrlsFromText($text));
  }

  /**
   * Data provider for extractLocalFileUrlsFromText().
   */
  public static function extractLocalFileUrlsProvider(): array {
    return [
      'single public file link' => [
        '<a href="/sites/default/files/doc.pdf">Download</a>',
        ['/sites/default/files/doc.pdf'],
      ],
      'multiple files' => [
        '<a href="/sites/default/files/doc.pdf">PDF</a> <a href="/sites/default/files/report.xlsx">Excel</a>',
        ['/sites/default/files/doc.pdf', '/sites/default/files/report.xlsx'],
      ],
      'multisite path' => [
        '<a href="/sites/example.edu/files/doc.pdf">Download</a>',
        ['/sites/example.edu/files/doc.pdf'],
      ],
      'private file (system/files)' => [
        '<a href="/system/files/doc.pdf">Download</a>',
        ['/system/files/doc.pdf'],
      ],
      'HTML entity decoded but ampersand kept in path' => [
        '<a href="/sites/default/files/doc.pdf&amp;v=1">Download</a>',
        ['/sites/default/files/doc.pdf&v=1'],
      ],
      'query string stripped' => [
        '<a href="/sites/default/files/doc.pdf?itok=abc">Download</a>',
        ['/sites/default/files/doc.pdf'],
      ],
      'dynamic base fallback' => [
        '<a href="/files/doc.pdf">Download</a>',
        ['/files/doc.pdf'],
        '/files',
      ],
      'deduplication' => [
        '<a href="/sites/default/files/doc.pdf">Link 1</a> <a href="/sites/default/files/doc.pdf">Link 2</a>',
        ['/sites/default/files/doc.pdf'],
      ],
      'no files found' => [
        '<p>Hello world</p>',
        [],
      ],
      'mixed public and private' => [
        '<a href="/sites/default/files/doc.pdf">Public</a> <a href="/system/files/secret.pdf">Private</a>',
        ['/sites/default/files/doc.pdf', '/system/files/secret.pdf'],
      ],
      'image src attribute' => [
        '<img src="/sites/default/files/photo.jpg">',
        ['/sites/default/files/photo.jpg'],
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // 3.1.7 extractLocalFileUrisFromText() — 5 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::extractLocalFileUrisFromText
   * @dataProvider extractLocalFileUrisProvider
   */
  public function testExtractLocalFileUrisFromText(string $text, array $expected, ?string $basePath = NULL): void {
    $host = $basePath !== NULL ? $this->createHostWithBasePath($basePath) : $this->host;
    $this->assertSame($expected, $host->doExtractLocalFileUrisFromText($text));
  }

  /**
   * Data provider for extractLocalFileUrisFromText().
   */
  public static function extractLocalFileUrisProvider(): array {
    return [
      'public file' => [
        '<a href="/sites/default/files/doc.pdf">Download</a>',
        ['public://doc.pdf'],
      ],
      'private file' => [
        '<a href="/system/files/doc.pdf">Download</a>',
        ['private://doc.pdf'],
      ],
      'mixed' => [
        '<a href="/sites/default/files/doc.pdf">Public</a> <a href="/system/files/secret.pdf">Private</a>',
        ['public://doc.pdf', 'private://secret.pdf'],
      ],
      'no files' => [
        '<p>Plain text</p>',
        [],
      ],
      'dynamic base path' => [
        '<a href="/files/doc.pdf">Download</a>',
        ['public://doc.pdf'],
        '/files',
      ],
    ];
  }

}
