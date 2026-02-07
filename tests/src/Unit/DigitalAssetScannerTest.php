<?php

declare(strict_types=1);

namespace Drupal\Tests\digital_asset_inventory\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\digital_asset_inventory\Service\DigitalAssetScanner;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Test harness exposing protected methods of DigitalAssetScanner.
 *
 * @internal
 */
final class TestableDigitalAssetScanner extends DigitalAssetScanner {

  public function doMapMimeToAssetType(string $mime): string {
    return $this->mapMimeToAssetType($mime);
  }

  public function doMapAssetTypeToCategory(string $assetType): string {
    return $this->mapAssetTypeToCategory($assetType);
  }

  public function doMatchUrlToAssetType(string $url): string {
    return $this->matchUrlToAssetType($url);
  }

  public function doGetCategorySortOrder(string $category): int {
    return $this->getCategorySortOrder($category);
  }

  public function doFormatFileSize(int $bytes): string {
    return $this->formatFileSize($bytes);
  }

  public function doNormalizeVideoUrl(string $url): ?array {
    return $this->normalizeVideoUrl($url);
  }

  public function doExtractUrls(string $text): array {
    return $this->extractUrls($text);
  }

  public function doGetMimeTypeFromExtension(string $ext): string {
    return $this->getMimeTypeFromExtension($ext);
  }

  public function doExtensionToMime(string $ext): string {
    return $this->extensionToMime($ext);
  }

  public function doMapExtensionToAssetType(string $ext): string {
    return $this->mapExtensionToAssetType($ext);
  }

  public function doBuildAccessibilitySignals(array $signals, array $tracks): array {
    return $this->buildAccessibilitySignals($signals, $tracks);
  }

  public function doGetKnownExtensions(): array {
    return $this->getKnownExtensions();
  }

  public function doParseMenuLinkUri(string $uri): ?array {
    return $this->parseMenuLinkUri($uri);
  }

  public function doExtractFileInfoFromPath(string $path): ?array {
    return $this->extractFileInfoFromPath($path);
  }

  public function doExtractLocalFileUrls(string $text, string $tag = 'a'): array {
    return $this->extractLocalFileUrls($text, $tag);
  }

  public function doExtractIframeUrls(string $text): array {
    return $this->extractIframeUrls($text);
  }

  public function doExtractMediaUuids(string $text): array {
    return $this->extractMediaUuids($text);
  }

  public function doCleanMediaUrl(string $url): string {
    return $this->cleanMediaUrl($url);
  }

  public function doResolveMediaUrl(string $url, ?string $baseUrl = NULL): string {
    return $this->resolveMediaUrl($url, $baseUrl);
  }

  public function doDetectVideoIdFromFieldName($value, $field_name, $table_name): ?array {
    return $this->detectVideoIdFromFieldName($value, $field_name, $table_name);
  }

}

/**
 * Tests the DigitalAssetScanner service.
 *
 * @coversDefaultClass \Drupal\digital_asset_inventory\Service\DigitalAssetScanner
 * @group digital_asset_inventory
 */
class DigitalAssetScannerTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\File\FileUrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileUrlGenerator;

  /**
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileSystem;

  /**
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityFieldManager;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * @var \Symfony\Component\DependencyInjection\ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $container;

  /**
   * The scanner under test.
   *
   * @var \Drupal\Tests\digital_asset_inventory\Unit\TestableDigitalAssetScanner
   */
  protected $scanner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->container = $this->createMock(ContainerInterface::class);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->willReturn($logger);

    $this->fileUrlGenerator->method('generateString')
      ->with('public://')
      ->willReturn('/sites/default/files');

    $this->scanner = new TestableDigitalAssetScanner(
      $this->entityTypeManager,
      $this->database,
      $this->configFactory,
      $this->fileUrlGenerator,
      $this->fileSystem,
      $this->entityFieldManager,
      $this->loggerFactory,
      $this->container,
    );
  }

  /**
   * Creates a scanner with a specific asset_types config.
   *
   * Builds fresh mocks per call to keep config fixtures isolated between
   * test cases.
   *
   * @param array|null $assetTypes
   *   The asset_types config value.
   *
   * @return \Drupal\Tests\digital_asset_inventory\Unit\TestableDigitalAssetScanner
   *   A new scanner instance with the given config.
   */
  protected function createScannerWithConfig(?array $assetTypes): TestableDigitalAssetScanner {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('asset_types')
      ->willReturn($assetTypes);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('digital_asset_inventory.settings')
      ->willReturn($config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $fileUrlGenerator->method('generateString')
      ->with('public://')
      ->willReturn('/sites/default/files');

    return new TestableDigitalAssetScanner(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Connection::class),
      $configFactory,
      $fileUrlGenerator,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $loggerFactory,
      $this->createMock(ContainerInterface::class),
    );
  }

  // -----------------------------------------------------------------------
  // 3.2.1 mapMimeToAssetType() — 28 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::mapMimeToAssetType
   * @dataProvider mimeToAssetTypeProvider
   */
  public function testMapMimeToAssetType(string $mime, string $expected): void {
    $this->assertSame($expected, $this->scanner->doMapMimeToAssetType($mime));
  }

  /**
   * Data provider for mapMimeToAssetType().
   */
  public static function mimeToAssetTypeProvider(): array {
    return [
      // Documents.
      'PDF' => ['application/pdf', 'pdf'],
      'Word legacy' => ['application/msword', 'word'],
      'Word OOXML' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word'],
      'Excel legacy' => ['application/vnd.ms-excel', 'excel'],
      'Excel OOXML' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'excel'],
      'PowerPoint legacy' => ['application/vnd.ms-powerpoint', 'powerpoint'],
      'PowerPoint OOXML' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'powerpoint'],
      'Text' => ['text/plain', 'text'],
      'CSV (text)' => ['text/csv', 'csv'],
      'CSV (application)' => ['application/csv', 'csv'],
      'VTT' => ['text/vtt', 'vtt'],
      'SRT (x-subrip)' => ['application/x-subrip', 'srt'],
      'SRT (text)' => ['text/srt', 'srt'],

      // Images.
      'JPEG' => ['image/jpeg', 'jpg'],
      'PNG' => ['image/png', 'png'],
      'GIF' => ['image/gif', 'gif'],
      'SVG' => ['image/svg+xml', 'svg'],
      'WebP' => ['image/webp', 'webp'],

      // Videos.
      'MP4' => ['video/mp4', 'mp4'],
      'WebM' => ['video/webm', 'webm'],
      'QuickTime' => ['video/quicktime', 'mov'],
      'AVI' => ['video/x-msvideo', 'avi'],

      // Audio.
      'MP3' => ['audio/mpeg', 'mp3'],
      'WAV' => ['audio/wav', 'wav'],
      'M4A' => ['audio/mp4', 'm4a'],
      'OGG' => ['audio/ogg', 'ogg'],

      // Edge cases.
      'unknown MIME' => ['application/octet-stream', 'other'],
      'whitespace + case' => ['  APPLICATION/PDF  ', 'pdf'],
    ];
  }

  // -----------------------------------------------------------------------
  // 3.2.2 mapAssetTypeToCategory() — 5 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::mapAssetTypeToCategory
   */
  public function testMapAssetTypeToCategoryKnownType(): void {
    $scanner = $this->createScannerWithConfig(['pdf' => ['category' => 'Documents']]);
    $this->assertSame('Documents', $scanner->doMapAssetTypeToCategory('pdf'));
  }

  /**
   * @covers ::mapAssetTypeToCategory
   */
  public function testMapAssetTypeToCategoryVideoType(): void {
    $scanner = $this->createScannerWithConfig(['mp4' => ['category' => 'Videos']]);
    $this->assertSame('Videos', $scanner->doMapAssetTypeToCategory('mp4'));
  }

  /**
   * @covers ::mapAssetTypeToCategory
   */
  public function testMapAssetTypeToCategoryUnknownType(): void {
    $scanner = $this->createScannerWithConfig(['pdf' => ['category' => 'Documents']]);
    $this->assertSame('Unknown', $scanner->doMapAssetTypeToCategory('xyz'));
  }

  /**
   * @covers ::mapAssetTypeToCategory
   */
  public function testMapAssetTypeToCategoryNullConfig(): void {
    $scanner = $this->createScannerWithConfig(NULL);
    $this->assertSame('Unknown', $scanner->doMapAssetTypeToCategory('pdf'));
  }

  /**
   * @covers ::mapAssetTypeToCategory
   */
  public function testMapAssetTypeToCategoryMissingCategoryKey(): void {
    $scanner = $this->createScannerWithConfig(['pdf' => ['label' => 'PDF']]);
    $this->assertSame('Unknown', $scanner->doMapAssetTypeToCategory('pdf'));
  }

  // -----------------------------------------------------------------------
  // 3.2.3 matchUrlToAssetType() — 13 cases.
  // -----------------------------------------------------------------------

  /**
   * Creates a scanner with the standard URL patterns config fixture.
   *
   * @return \Drupal\Tests\digital_asset_inventory\Unit\TestableDigitalAssetScanner
   *   A scanner instance with URL patterns config.
   */
  protected function createScannerWithUrlPatterns(): TestableDigitalAssetScanner {
    return $this->createScannerWithConfig([
      'google_doc' => ['url_patterns' => ['docs.google.com/document']],
      'google_sheet' => ['url_patterns' => ['docs.google.com/spreadsheets']],
      'youtube' => ['url_patterns' => ['youtube.com', 'youtu.be']],
      'vimeo' => ['url_patterns' => ['vimeo.com']],
      'box_link' => ['url_patterns' => ['box.com']],
    ]);
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeGoogleDoc(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('google_doc', $scanner->doMatchUrlToAssetType('https://docs.google.com/document/d/abc'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeGoogleSheet(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('google_sheet', $scanner->doMatchUrlToAssetType('https://docs.google.com/spreadsheets/d/xyz'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeYouTubeFull(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('youtube', $scanner->doMatchUrlToAssetType('https://www.youtube.com/watch?v=abc'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeYouTubeShort(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('youtube', $scanner->doMatchUrlToAssetType('https://youtu.be/abc'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeVimeo(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('vimeo', $scanner->doMatchUrlToAssetType('https://vimeo.com/123456'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeBox(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('box_link', $scanner->doMatchUrlToAssetType('https://app.box.com/s/abc123'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeNoMatch(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('other', $scanner->doMatchUrlToAssetType('https://example.com/page'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeCaseInsensitive(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('google_doc', $scanner->doMatchUrlToAssetType('HTTPS://DOCS.GOOGLE.COM/DOCUMENT/D/ABC'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeEmptyUrl(): void {
    $scanner = $this->createScannerWithUrlPatterns();
    $this->assertSame('other', $scanner->doMatchUrlToAssetType(''));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeNullConfig(): void {
    $scanner = $this->createScannerWithConfig(NULL);
    $this->assertSame('other', $scanner->doMatchUrlToAssetType('https://docs.google.com/document/d/abc'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeFirstPatternWins(): void {
    // Both types match the same URL; the first-defined type must win.
    $scanner = $this->createScannerWithConfig([
      'type_a' => ['url_patterns' => ['example.com']],
      'type_b' => ['url_patterns' => ['example.com']],
    ]);
    $this->assertSame('type_a', $scanner->doMatchUrlToAssetType('https://example.com/foo'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeNoUrlPatternsKey(): void {
    $scanner = $this->createScannerWithConfig(['pdf' => ['category' => 'Documents']]);
    $this->assertSame('other', $scanner->doMatchUrlToAssetType('https://example.com'));
  }

  /**
   * @covers ::matchUrlToAssetType
   */
  public function testMatchUrlToAssetTypeEmptyUrlPatterns(): void {
    $scanner = $this->createScannerWithConfig(['pdf' => ['url_patterns' => []]]);
    $this->assertSame('other', $scanner->doMatchUrlToAssetType('https://example.com'));
  }

  // -----------------------------------------------------------------------
  // 3.2.4 getCategorySortOrder() — 12 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getCategorySortOrder
   * @dataProvider categorySortOrderProvider
   */
  public function testGetCategorySortOrder(string $category, int $expected): void {
    $this->assertSame($expected, $this->scanner->doGetCategorySortOrder($category));
  }

  /**
   * Data provider for getCategorySortOrder().
   */
  public static function categorySortOrderProvider(): array {
    return [
      'Documents' => ['Documents', 1],
      'Videos' => ['Videos', 2],
      'Audio' => ['Audio', 3],
      'Google Workspace' => ['Google Workspace', 4],
      'Document Services' => ['Document Services', 5],
      'Forms & Surveys' => ['Forms & Surveys', 6],
      'Education Platforms' => ['Education Platforms', 7],
      'Embedded Media' => ['Embedded Media', 8],
      'Images' => ['Images', 9],
      'Other' => ['Other', 10],
      'unknown category' => ['FooBar', 99],
      'empty string' => ['', 99],
    ];
  }

  // -----------------------------------------------------------------------
  // 3.2.5 formatFileSize() — 5 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::formatFileSize
   * @dataProvider fileSizeProvider
   */
  public function testFormatFileSize(int $bytes, string $expected): void {
    $this->assertSame($expected, $this->scanner->doFormatFileSize($bytes));
  }

  /**
   * Data provider for formatFileSize().
   */
  public static function fileSizeProvider(): array {
    return [
      'zero bytes' => [0, '-'],
      '500 bytes' => [500, '500 B'],
      '1 KB' => [1024, '1.00 KB'],
      '1.5 MB' => [1572864, '1.50 MB'],
      '1 GB' => [1073741824, '1.00 GB'],
    ];
  }

  // -----------------------------------------------------------------------
  // normalizeVideoUrl() — 15 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::normalizeVideoUrl
   * @dataProvider normalizeVideoUrlProvider
   */
  public function testNormalizeVideoUrl(string $input, ?array $expected): void {
    $result = $this->scanner->doNormalizeVideoUrl($input);
    if ($expected === NULL) {
      $this->assertNull($result);
    }
    else {
      $this->assertSame($expected, $result);
    }
  }

  /**
   * Data provider for normalizeVideoUrl().
   */
  public static function normalizeVideoUrlProvider(): array {
    $ytCanonical = [
      'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'video_id' => 'dQw4w9WgXcQ',
      'platform' => 'youtube',
    ];
    $vimeoCanonical = [
      'url' => 'https://vimeo.com/123456789',
      'video_id' => '123456789',
      'platform' => 'vimeo',
    ];

    return [
      'YouTube standard watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', $ytCanonical],
      'YouTube short URL' => ['https://youtu.be/dQw4w9WgXcQ', $ytCanonical],
      'YouTube embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', $ytCanonical],
      'YouTube old embed /v/' => ['https://www.youtube.com/v/dQw4w9WgXcQ', $ytCanonical],
      'YouTube shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', $ytCanonical],
      'YouTube no-cookie' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $ytCanonical],
      'YouTube with extra params' => ['https://www.youtube.com/watch?feature=shared&v=dQw4w9WgXcQ', $ytCanonical],
      'Vimeo standard' => ['https://vimeo.com/123456789', $vimeoCanonical],
      'Vimeo player embed' => ['https://player.vimeo.com/video/123456789', $vimeoCanonical],
      'bare YouTube video ID' => ['dQw4w9WgXcQ', $ytCanonical],
      'bare Vimeo video ID' => ['123456789', $vimeoCanonical],
      'empty string' => ['', NULL],
      'non-video URL' => ['https://example.com/page', NULL],
      'YouTube without scheme' => ['www.youtube.com/watch?v=dQw4w9WgXcQ', $ytCanonical],
      'Vimeo with trailing path' => ['https://vimeo.com/123456789/settings', $vimeoCanonical],
    ];
  }

  // -----------------------------------------------------------------------
  // parseMenuLinkUri() — 7 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::parseMenuLinkUri
   * @dataProvider parseMenuLinkUriProvider
   */
  public function testParseMenuLinkUri(string $uri, ?array $expected): void {
    $result = $this->scanner->doParseMenuLinkUri($uri);
    if ($expected === NULL) {
      $this->assertNull($result);
    }
    else {
      $this->assertSame($expected['type'], $result['type']);
      $this->assertSame($expected['stream_uri'], $result['stream_uri']);
      $this->assertArrayHasKey('path', $result);
    }
  }

  /**
   * Data provider for parseMenuLinkUri().
   */
  public static function parseMenuLinkUriProvider(): array {
    return [
      'internal: public file' => [
        'internal:/sites/default/files/doc.pdf',
        ['type' => 'stream', 'stream_uri' => 'public://doc.pdf', 'path' => '/sites/default/files/doc.pdf'],
      ],
      'internal: private file' => [
        'internal:/system/files/doc.pdf',
        ['type' => 'stream', 'stream_uri' => 'private://doc.pdf', 'path' => '/system/files/doc.pdf'],
      ],
      'base: public file' => [
        'base:sites/default/files/doc.pdf',
        ['type' => 'stream', 'stream_uri' => 'public://doc.pdf', 'path' => '/sites/default/files/doc.pdf'],
      ],
      'entity: URI' => ['entity:node/123', NULL],
      'route: URI' => ['route:<nolink>', NULL],
      'HTTPS local file URL' => [
        'https://example.edu/sites/default/files/doc.pdf',
        ['type' => 'stream', 'stream_uri' => 'public://doc.pdf', 'path' => '/sites/default/files/doc.pdf'],
      ],
      'non-file URL' => ['https://example.com/page', NULL],
    ];
  }

  // -----------------------------------------------------------------------
  // extractFileInfoFromPath() — 5 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::extractFileInfoFromPath
   * @dataProvider extractFileInfoFromPathProvider
   */
  public function testExtractFileInfoFromPath(string $path, ?array $expected): void {
    $result = $this->scanner->doExtractFileInfoFromPath($path);
    if ($expected === NULL) {
      $this->assertNull($result);
    }
    else {
      $this->assertSame($expected['type'], $result['type']);
      $this->assertSame($expected['stream_uri'], $result['stream_uri']);
      $this->assertArrayHasKey('path', $result);
      $this->assertSame($expected['path'], $result['path']);
    }
  }

  /**
   * Data provider for extractFileInfoFromPath().
   */
  public static function extractFileInfoFromPathProvider(): array {
    return [
      'public file path' => [
        '/sites/default/files/doc.pdf',
        ['type' => 'stream', 'stream_uri' => 'public://doc.pdf', 'path' => '/sites/default/files/doc.pdf'],
      ],
      'private file path' => [
        '/system/files/doc.pdf',
        ['type' => 'stream', 'stream_uri' => 'private://doc.pdf', 'path' => '/system/files/doc.pdf'],
      ],
      'path with query string' => [
        '/sites/default/files/doc.pdf?v=1',
        ['type' => 'stream', 'stream_uri' => 'public://doc.pdf', 'path' => '/sites/default/files/doc.pdf'],
      ],
      'non-file path' => ['/admin/content', NULL],
      'path with quotes' => [
        '"/sites/default/files/doc.pdf"',
        ['type' => 'stream', 'stream_uri' => 'public://doc.pdf', 'path' => '/sites/default/files/doc.pdf'],
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // extractUrls() — 5 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::extractUrls
   * @dataProvider extractUrlsProvider
   */
  public function testExtractUrls(string $text, array $expected): void {
    $result = $this->scanner->doExtractUrls($text);
    $this->assertSame($expected, array_values($result));
  }

  /**
   * Data provider for extractUrls().
   */
  public static function extractUrlsProvider(): array {
    return [
      'single URL' => [
        'Visit https://example.com/page today',
        ['https://example.com/page'],
      ],
      'multiple URLs' => [
        'https://a.com and http://b.com',
        ['https://a.com', 'http://b.com'],
      ],
      'deduplication' => [
        'https://a.com and https://a.com',
        ['https://a.com'],
      ],
      'no URLs' => ['Just plain text', []],
      'URL in HTML' => [
        '<a href="https://example.com">Link</a>',
        ['https://example.com'],
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // getMimeTypeFromExtension() — 8 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getMimeTypeFromExtension
   * @dataProvider getMimeTypeFromExtensionProvider
   */
  public function testGetMimeTypeFromExtension(string $ext, string $expected): void {
    $this->assertSame($expected, $this->scanner->doGetMimeTypeFromExtension($ext));
  }

  /**
   * Data provider for getMimeTypeFromExtension().
   */
  public static function getMimeTypeFromExtensionProvider(): array {
    return [
      'pdf' => ['pdf', 'application/pdf'],
      'docx' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
      'jpg' => ['jpg', 'image/jpeg'],
      'mp4' => ['mp4', 'video/mp4'],
      'mp3' => ['mp3', 'audio/mpeg'],
      'zip' => ['zip', 'application/zip'],
      'vtt' => ['vtt', 'text/vtt'],
      'unknown' => ['xyz', 'application/octet-stream'],
    ];
  }

  // -----------------------------------------------------------------------
  // extensionToMime() — 6 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::extensionToMime
   * @dataProvider extensionToMimeProvider
   */
  public function testExtensionToMime(string $ext, string $expected): void {
    $this->assertSame($expected, $this->scanner->doExtensionToMime($ext));
  }

  /**
   * Data provider for extensionToMime().
   */
  public static function extensionToMimeProvider(): array {
    return [
      'lowercase pdf' => ['pdf', 'application/pdf'],
      'uppercase PDF' => ['PDF', 'application/pdf'],
      'mixed case Jpg' => ['Jpg', 'image/jpeg'],
      'srt not in this map' => ['srt', 'application/octet-stream'],
      'unknown' => ['xyz', 'application/octet-stream'],
      'rar' => ['rar', 'application/x-rar-compressed'],
    ];
  }

  // -----------------------------------------------------------------------
  // mapExtensionToAssetType() — 7 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::mapExtensionToAssetType
   * @dataProvider mapExtensionToAssetTypeProvider
   */
  public function testMapExtensionToAssetType(string $ext, string $expected): void {
    $this->assertSame($expected, $this->scanner->doMapExtensionToAssetType($ext));
  }

  /**
   * Data provider for mapExtensionToAssetType().
   */
  public static function mapExtensionToAssetTypeProvider(): array {
    return [
      'mp4' => ['mp4', 'mp4'],
      'ogg' => ['ogg', 'ogg'],
      'oga maps to ogg' => ['oga', 'ogg'],
      'vtt' => ['vtt', 'vtt'],
      'srt' => ['srt', 'srt'],
      'unknown passthrough' => ['docx', 'docx'],
      'mkv' => ['mkv', 'mkv'],
    ];
  }

  // -----------------------------------------------------------------------
  // buildAccessibilitySignals() — 6 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::buildAccessibilitySignals
   */
  public function testBuildAccessibilitySignalsAllDetected(): void {
    $signals = ['controls' => TRUE, 'autoplay' => TRUE, 'muted' => TRUE, 'loop' => TRUE];
    $tracks = [['kind' => 'captions', 'src' => 'cap.vtt']];
    $result = $this->scanner->doBuildAccessibilitySignals($signals, $tracks);
    $this->assertSame('detected', $result['controls']);
    $this->assertSame('detected', $result['autoplay']);
    $this->assertSame('detected', $result['muted']);
    $this->assertSame('detected', $result['loop']);
    $this->assertSame('detected', $result['captions']);
  }

  /**
   * @covers ::buildAccessibilitySignals
   */
  public function testBuildAccessibilitySignalsNoneDetected(): void {
    $result = $this->scanner->doBuildAccessibilitySignals([], []);
    $this->assertSame('not_detected', $result['controls']);
    $this->assertSame('not_detected', $result['autoplay']);
    $this->assertSame('not_detected', $result['muted']);
    $this->assertSame('not_detected', $result['loop']);
    $this->assertSame('not_detected', $result['captions']);
  }

  /**
   * @covers ::buildAccessibilitySignals
   */
  public function testBuildAccessibilitySignalsControlsOnly(): void {
    $result = $this->scanner->doBuildAccessibilitySignals(['controls' => TRUE], []);
    $this->assertSame('detected', $result['controls']);
    $this->assertSame('not_detected', $result['autoplay']);
    $this->assertSame('not_detected', $result['captions']);
  }

  /**
   * @covers ::buildAccessibilitySignals
   */
  public function testBuildAccessibilitySignalsSubtitlesTrack(): void {
    $tracks = [['kind' => 'subtitles', 'src' => 'sub.vtt']];
    $result = $this->scanner->doBuildAccessibilitySignals([], $tracks);
    $this->assertSame('detected', $result['captions']);
  }

  /**
   * @covers ::buildAccessibilitySignals
   */
  public function testBuildAccessibilitySignalsDescriptionsTrackNotCaptions(): void {
    $tracks = [['kind' => 'descriptions', 'src' => 'desc.vtt']];
    $result = $this->scanner->doBuildAccessibilitySignals([], $tracks);
    $this->assertSame('not_detected', $result['captions']);
  }

  /**
   * @covers ::buildAccessibilitySignals
   */
  public function testBuildAccessibilitySignalsMixed(): void {
    $signals = ['controls' => TRUE, 'loop' => TRUE];
    $tracks = [['kind' => 'captions', 'src' => 'cap.vtt']];
    $result = $this->scanner->doBuildAccessibilitySignals($signals, $tracks);
    $this->assertSame('detected', $result['controls']);
    $this->assertSame('not_detected', $result['autoplay']);
    $this->assertSame('not_detected', $result['muted']);
    $this->assertSame('detected', $result['loop']);
    $this->assertSame('detected', $result['captions']);
  }

  // -----------------------------------------------------------------------
  // getKnownExtensions() — 1 case.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getKnownExtensions
   */
  public function testGetKnownExtensions(): void {
    $extensions = $this->scanner->doGetKnownExtensions();
    $this->assertNotEmpty($extensions);
    $this->assertContains('pdf', $extensions);
    $this->assertContains('jpg', $extensions);
    $this->assertContains('mp4', $extensions);
    $this->assertContains('mp3', $extensions);
    $this->assertContains('zip', $extensions);
    foreach ($extensions as $ext) {
      $this->assertIsString($ext);
    }
  }

  // -----------------------------------------------------------------------
  // extractLocalFileUrls() — 10 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::extractLocalFileUrls
   * @dataProvider extractLocalFileUrlsProvider
   */
  public function testExtractLocalFileUrls(string $text, string $tag, array $expected): void {
    $result = $this->scanner->doExtractLocalFileUrls($text, $tag);
    $this->assertSame($expected, $result);
  }

  /**
   * Data provider for extractLocalFileUrls().
   */
  public static function extractLocalFileUrlsProvider(): array {
    return [
      'a href public file' => [
        '<a href="/sites/default/files/doc.pdf">Link</a>',
        'a',
        ['public://doc.pdf'],
      ],
      'img src' => [
        '<img src="/sites/default/files/photo.jpg">',
        'img',
        ['public://photo.jpg'],
      ],
      'object data' => [
        '<object data="/sites/default/files/report.pdf"></object>',
        'object',
        ['public://report.pdf'],
      ],
      'embed src' => [
        '<embed src="/sites/default/files/report.pdf">',
        'embed',
        ['public://report.pdf'],
      ],
      'private file' => [
        '<a href="/system/files/doc.pdf">Link</a>',
        'a',
        ['private://doc.pdf'],
      ],
      'query stripped' => [
        '<a href="/sites/default/files/doc.pdf?v=1">Link</a>',
        'a',
        ['public://doc.pdf'],
      ],
      'deduplication' => [
        '<a href="/sites/default/files/doc.pdf">One</a> <a href="/sites/default/files/doc.pdf">Two</a>',
        'a',
        ['public://doc.pdf'],
      ],
      'external URL no match' => [
        '<a href="https://google.com/search">Link</a>',
        'a',
        [],
      ],
      'multi-line tag' => [
        "<img\n  src=\"/sites/default/files/photo.jpg\"\n>",
        'img',
        ['public://photo.jpg'],
      ],
      'HTML entities decoded' => [
        '<a href="/sites/default/files/doc.pdf&amp;v=1">Link</a>',
        'a',
        ['public://doc.pdf&v=1'],
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // extractIframeUrls() — 6 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::extractIframeUrls
   * @dataProvider extractIframeUrlsProvider
   */
  public function testExtractIframeUrls(string $text, array $expected): void {
    $this->assertSame($expected, $this->scanner->doExtractIframeUrls($text));
  }

  /**
   * Data provider for extractIframeUrls().
   */
  public static function extractIframeUrlsProvider(): array {
    return [
      'YouTube iframe' => [
        '<iframe src="https://www.youtube.com/embed/abc"></iframe>',
        ['https://www.youtube.com/embed/abc'],
      ],
      'multiple iframes' => [
        '<iframe src="https://a.com/v"></iframe><iframe src="https://b.com/v"></iframe>',
        ['https://a.com/v', 'https://b.com/v'],
      ],
      'deduplication' => [
        '<iframe src="https://a.com/v"></iframe><iframe src="https://a.com/v"></iframe>',
        ['https://a.com/v'],
      ],
      'HTML entity in URL' => [
        '<iframe src="https://example.com/page?a=1&amp;b=2"></iframe>',
        ['https://example.com/page?a=1&b=2'],
      ],
      'no iframes' => ['<p>Hello</p>', []],
      'empty src' => ['<iframe src=""></iframe>', []],
    ];
  }

  // -----------------------------------------------------------------------
  // extractMediaUuids() — 5 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::extractMediaUuids
   * @dataProvider extractMediaUuidsProvider
   */
  public function testExtractMediaUuids(string $text, array $expected): void {
    $this->assertSame($expected, $this->scanner->doExtractMediaUuids($text));
  }

  /**
   * Data provider for extractMediaUuids().
   */
  public static function extractMediaUuidsProvider(): array {
    return [
      'single UUID' => [
        '<drupal-media data-entity-uuid="12345678-1234-1234-1234-123456789abc"></drupal-media>',
        ['12345678-1234-1234-1234-123456789abc'],
      ],
      'multiple UUIDs' => [
        '<drupal-media data-entity-uuid="11111111-1111-1111-1111-111111111111"></drupal-media><drupal-media data-entity-uuid="22222222-2222-2222-2222-222222222222"></drupal-media>',
        ['11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222'],
      ],
      'deduplication' => [
        '<drupal-media data-entity-uuid="11111111-1111-1111-1111-111111111111"></drupal-media><drupal-media data-entity-uuid="11111111-1111-1111-1111-111111111111"></drupal-media>',
        ['11111111-1111-1111-1111-111111111111'],
      ],
      'no media tags' => ['<p>Hello</p>', []],
      'UUID with extra attrs' => [
        '<drupal-media data-entity-type="media" data-entity-uuid="aabbccdd-1122-3344-5566-aabbccddeeff"></drupal-media>',
        ['aabbccdd-1122-3344-5566-aabbccddeeff'],
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // cleanMediaUrl() — 4 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::cleanMediaUrl
   * @dataProvider cleanMediaUrlProvider
   */
  public function testCleanMediaUrl(string $input, string $expected): void {
    $this->assertSame($expected, $this->scanner->doCleanMediaUrl($input));
  }

  /**
   * Data provider for cleanMediaUrl().
   */
  public static function cleanMediaUrlProvider(): array {
    return [
      'already clean' => [
        'https://example.com/video.mp4',
        'https://example.com/video.mp4',
      ],
      'entity-encoded ampersand' => [
        'https://example.com/video.mp4?a=1&amp;b=2',
        'https://example.com/video.mp4?a=1&b=2',
      ],
      'whitespace' => [
        '  https://example.com/video.mp4  ',
        'https://example.com/video.mp4',
      ],
      'encoded quotes' => [
        'https://example.com/video.mp4?title=a&#039;b',
        "https://example.com/video.mp4?title=a'b",
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // resolveMediaUrl() — 6 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::resolveMediaUrl
   * @dataProvider resolveMediaUrlProvider
   */
  public function testResolveMediaUrl(string $url, ?string $baseUrl, string $expected): void {
    $this->assertSame($expected, $this->scanner->doResolveMediaUrl($url, $baseUrl));
  }

  /**
   * Data provider for resolveMediaUrl().
   */
  public static function resolveMediaUrlProvider(): array {
    return [
      'already absolute' => [
        'https://example.com/v.mp4',
        'https://other.com',
        'https://example.com/v.mp4',
      ],
      'protocol-relative' => [
        '//cdn.example.com/v.mp4',
        'https://other.com',
        'https://cdn.example.com/v.mp4',
      ],
      'root-relative' => [
        '/sites/default/files/v.mp4',
        'https://example.com',
        'https://example.com/sites/default/files/v.mp4',
      ],
      'relative path' => [
        'videos/v.mp4',
        'https://example.com',
        'https://example.com/videos/v.mp4',
      ],
      'has http scheme' => [
        'http://example.com/v.mp4',
        'https://other.com',
        'http://example.com/v.mp4',
      ],
      'empty base_url with absolute URL' => [
        'https://cdn.example.com/v.mp4',
        NULL,
        'https://cdn.example.com/v.mp4',
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // detectVideoIdFromFieldName() — 8 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::detectVideoIdFromFieldName
   * @dataProvider detectVideoIdFromFieldNameProvider
   */
  public function testDetectVideoIdFromFieldName(string $value, string $fieldName, string $tableName, ?array $expected): void {
    $this->assertSame($expected, $this->scanner->doDetectVideoIdFromFieldName($value, $fieldName, $tableName));
  }

  /**
   * Data provider for detectVideoIdFromFieldName().
   */
  public static function detectVideoIdFromFieldNameProvider(): array {
    return [
      'YouTube keyword in field name' => [
        'dQw4w9WgXcQ',
        'field_youtube_id',
        'node__field_youtube_id',
        ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'asset_type' => 'youtube'],
      ],
      'Vimeo keyword in field name' => [
        '123456789',
        'field_vimeo_id',
        'node__field_vimeo_id',
        ['url' => 'https://vimeo.com/123456789', 'asset_type' => 'vimeo'],
      ],
      'generic video_id — YouTube format wins' => [
        'dQw4w9WgXcQ',
        'field_video_id',
        'node__field_video_id',
        ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'asset_type' => 'youtube'],
      ],
      'generic video_id — Vimeo format' => [
        '123456789',
        'field_video_id',
        'node__field_video_id',
        ['url' => 'https://vimeo.com/123456789', 'asset_type' => 'vimeo'],
      ],
      'no keyword match' => [
        'dQw4w9WgXcQ',
        'field_title',
        'node__field_title',
        NULL,
      ],
      'empty value' => [
        '',
        'field_youtube_id',
        'node__field_youtube_id',
        NULL,
      ],
      'value too long' => [
        'this_value_exceeds_20_chars',
        'field_youtube_id',
        'node__field_youtube_id',
        NULL,
      ],
      'YouTube keyword but invalid ID format' => [
        'abc',
        'field_youtube_id',
        'node__field_youtube_id',
        NULL,
      ],
    ];
  }

}
