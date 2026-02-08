<?php

declare(strict_types=1);

namespace Drupal\Tests\digital_asset_inventory\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Drupal\Tests\UnitTestCase;

/**
 * Test harness exposing protected methods of ArchiveService.
 *
 * @internal
 */
final class TestableArchiveService extends ArchiveService {

  public function doIsRedirectEligibleAssetType(string $assetType): bool {
    return $this->isRedirectEligibleAssetType($assetType);
  }

  public function doUrlToStreamUri(string $url): ?string {
    return $this->urlToStreamUri($url);
  }

  public function doIsAfterComplianceDeadline(): bool {
    return $this->isAfterComplianceDeadline();
  }

  protected int $mockUsageCount = 0;

  public function setMockUsageCount(int $count): void {
    $this->mockUsageCount = $count;
  }

  public function getUsageCountByArchive(DigitalAssetArchive $archived_asset) {
    return $this->mockUsageCount;
  }

  protected ?string $mockSourceUri = NULL;

  public function setMockSourceUri(?string $uri): void {
    $this->mockSourceUri = $uri;
  }

  protected function resolveSourceUri($original_path, $original_fid) {
    return $this->mockSourceUri;
  }

  public function setTestFileSystem(FileSystemInterface $fileSystem): void {
    $this->fileSystem = $fileSystem;
  }

}

/**
 * Tests the ArchiveService.
 *
 * @coversDefaultClass \Drupal\digital_asset_inventory\Service\ArchiveService
 * @group digital_asset_inventory
 */
class ArchiveServiceTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileSystem;

  /**
   * @var \Drupal\Core\File\FileUrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileUrlGenerator;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Queue\QueueFactory|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $queueFactory;

  /**
   * The service under test.
   *
   * @var \Drupal\Tests\digital_asset_inventory\Unit\TestableArchiveService
   */
  protected $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->queueFactory = $this->createMock(QueueFactory::class);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->willReturn($logger);

    $this->fileUrlGenerator->method('generateString')
      ->with('public://')
      ->willReturn('/sites/default/files');

    $this->service = new TestableArchiveService(
      $this->entityTypeManager,
      $this->database,
      $this->fileSystem,
      $this->fileUrlGenerator,
      $this->currentUser,
      $this->loggerFactory,
      $this->configFactory,
      $this->queueFactory,
    );
  }

  /**
   * Creates a service with a specific asset_types config.
   *
   * Builds fresh mocks per call to keep config fixtures isolated between
   * test cases.
   *
   * @param array|null $assetTypes
   *   The asset_types config value.
   *
   * @return \Drupal\Tests\digital_asset_inventory\Unit\TestableArchiveService
   *   A new service instance with the given config.
   */
  protected function createServiceWithConfig(?array $assetTypes): TestableArchiveService {
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

    return new TestableArchiveService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(FileSystemInterface::class),
      $fileUrlGenerator,
      $this->createMock(AccountProxyInterface::class),
      $loggerFactory,
      $configFactory,
      $this->createMock(QueueFactory::class),
    );
  }

  // -----------------------------------------------------------------------
  // 3.3.1 normalizeUrl() — 16 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::normalizeUrl
   * @dataProvider normalizeUrlProvider
   */
  public function testNormalizeUrl(string $input, string $expected): void {
    $this->assertSame($expected, $this->service->normalizeUrl($input));
  }

  /**
   * Data provider for normalizeUrl().
   */
  public static function normalizeUrlProvider(): array {
    return [
      // Scheme normalization.
      'HTTPS preserved' => [
        'https://example.com/path',
        'https://example.com/path',
      ],
      'mixed-case scheme' => [
        'HTTPS://Example.COM/Path',
        'https://example.com/Path',
      ],
      'HTTP scheme' => [
        'http://example.com/page',
        'http://example.com/page',
      ],

      // Host normalization.
      'lowercase host' => [
        'https://EXAMPLE.COM/page',
        'https://example.com/page',
      ],
      'subdomain preserved' => [
        'https://www.example.com/page',
        'https://www.example.com/page',
      ],

      // Port handling.
      'default HTTPS port removed' => [
        'https://example.com:443/path',
        'https://example.com/path',
      ],
      'default HTTP port removed' => [
        'http://example.com:80/path',
        'http://example.com/path',
      ],
      'non-default port preserved' => [
        'https://example.com:8080/path',
        'https://example.com:8080/path',
      ],

      // Path normalization.
      'trailing slash removed' => [
        'https://example.com/path/',
        'https://example.com/path',
      ],
      'root path kept' => [
        'https://example.com/',
        'https://example.com/',
      ],
      'path case preserved' => [
        'https://example.com/My/Path',
        'https://example.com/My/Path',
      ],

      // Query and fragment.
      'query string preserved' => [
        'https://example.com/path?key=value',
        'https://example.com/path?key=value',
      ],
      'fragment stripped' => [
        'https://example.com/path#section',
        'https://example.com/path',
      ],

      // Relative URLs.
      'relative path (no host)' => [
        '/path/to/page',
        '/path/to/page',
      ],

      // Scheme-relative URLs.
      'scheme-relative defaults to https' => [
        '//example.com/path',
        'https://example.com/path',
      ],
      'scheme-relative host only defaults to https' => [
        '//example.com',
        'https://example.com/',
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // 3.3.2 isRedirectEligibleAssetType() — 8 cases.
  // -----------------------------------------------------------------------

  /**
   * Creates a service with the standard redirect eligibility config.
   *
   * @return \Drupal\Tests\digital_asset_inventory\Unit\TestableArchiveService
   *   A service instance with category config for redirect eligibility tests.
   */
  protected function createServiceWithRedirectConfig(): TestableArchiveService {
    return $this->createServiceWithConfig([
      'pdf' => ['category' => 'Documents'],
      'word' => ['category' => 'Documents'],
      'mp4' => ['category' => 'Videos'],
      'jpg' => ['category' => 'Images'],
      'mp3' => ['category' => 'Audio'],
      'compressed' => ['category' => 'Other'],
    ]);
  }

  /**
   * @covers ::isRedirectEligibleAssetType
   */
  public function testIsRedirectEligiblePdf(): void {
    $service = $this->createServiceWithRedirectConfig();
    $this->assertTrue($service->doIsRedirectEligibleAssetType('pdf'));
  }

  /**
   * @covers ::isRedirectEligibleAssetType
   */
  public function testIsRedirectEligibleWord(): void {
    $service = $this->createServiceWithRedirectConfig();
    $this->assertTrue($service->doIsRedirectEligibleAssetType('word'));
  }

  /**
   * @covers ::isRedirectEligibleAssetType
   */
  public function testIsRedirectEligibleMp4(): void {
    $service = $this->createServiceWithRedirectConfig();
    $this->assertTrue($service->doIsRedirectEligibleAssetType('mp4'));
  }

  /**
   * @covers ::isRedirectEligibleAssetType
   */
  public function testIsRedirectEligiblePage(): void {
    $service = $this->createServiceWithRedirectConfig();
    $this->assertTrue($service->doIsRedirectEligibleAssetType('page'));
  }

  /**
   * @covers ::isRedirectEligibleAssetType
   */
  public function testIsRedirectEligibleExternal(): void {
    $service = $this->createServiceWithRedirectConfig();
    $this->assertTrue($service->doIsRedirectEligibleAssetType('external'));
  }

  /**
   * @covers ::isRedirectEligibleAssetType
   */
  public function testIsRedirectEligibleJpgIneligible(): void {
    $service = $this->createServiceWithRedirectConfig();
    $this->assertFalse($service->doIsRedirectEligibleAssetType('jpg'));
  }

  /**
   * @covers ::isRedirectEligibleAssetType
   */
  public function testIsRedirectEligibleMp3Ineligible(): void {
    $service = $this->createServiceWithRedirectConfig();
    $this->assertFalse($service->doIsRedirectEligibleAssetType('mp3'));
  }

  /**
   * @covers ::isRedirectEligibleAssetType
   */
  public function testIsRedirectEligibleUnknownType(): void {
    $service = $this->createServiceWithRedirectConfig();
    $this->assertFalse($service->doIsRedirectEligibleAssetType('xyz'));
  }

  // -----------------------------------------------------------------------
  // Multi-config helper.
  // -----------------------------------------------------------------------

  /**
   * Creates a service with arbitrary config key/value pairs.
   *
   * Unlike createServiceWithConfig() which only stubs asset_types, this
   * helper stubs any config key via a callback.
   *
   * @param array $configValues
   *   Associative array of config key => value.
   *
   * @return \Drupal\Tests\digital_asset_inventory\Unit\TestableArchiveService
   *   A new service instance with the given config.
   */
  protected function createServiceWithMultiConfig(array $configValues): TestableArchiveService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function ($key) use ($configValues) {
        return $configValues[$key] ?? NULL;
      });

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

    return new TestableArchiveService(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(Connection::class),
      $this->createMock(FileSystemInterface::class),
      $fileUrlGenerator,
      $this->createMock(AccountProxyInterface::class),
      $loggerFactory,
      $configFactory,
      $this->createMock(QueueFactory::class),
    );
  }

  // -----------------------------------------------------------------------
  // urlToStreamUri() — 7 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::urlToStreamUri
   * @dataProvider urlToStreamUriProvider
   */
  public function testUrlToStreamUri(string $input, ?string $expected): void {
    $service = $this->createServiceWithMultiConfig([]);
    $result = $service->doUrlToStreamUri($input);
    $this->assertSame($expected, $result);
  }

  /**
   * Data provider for urlToStreamUri().
   */
  public static function urlToStreamUriProvider(): array {
    return [
      'plain public path' => [
        '/sites/default/files/doc.pdf',
        'public://doc.pdf',
      ],
      'internal: prefix' => [
        'internal:/sites/default/files/doc.pdf',
        'public://doc.pdf',
      ],
      'internal: without leading slash' => [
        'internal:sites/default/files/doc.pdf',
        'public://doc.pdf',
      ],
      'base: prefix' => [
        'base:sites/default/files/doc.pdf',
        'public://doc.pdf',
      ],
      'private /system/files' => [
        '/system/files/doc.pdf',
        'private://doc.pdf',
      ],
      'non-file path' => [
        '/admin/content',
        NULL,
      ],
      'already stream URI' => [
        'public://doc.pdf',
        'public://doc.pdf',
      ],
    ];
  }

  // -----------------------------------------------------------------------
  // canArchive() — 5 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::canArchive
   * @dataProvider canArchiveProvider
   */
  public function testCanArchive(string $category, bool $expected): void {
    $asset = $this->createMock(DigitalAssetItem::class);
    $asset->method('getCategory')->willReturn($category);

    $service = $this->createServiceWithMultiConfig([]);
    $this->assertSame($expected, $service->canArchive($asset));
  }

  /**
   * Data provider for canArchive().
   */
  public static function canArchiveProvider(): array {
    return [
      'Documents' => ['Documents', TRUE],
      'Videos' => ['Videos', TRUE],
      'Images' => ['Images', FALSE],
      'Audio' => ['Audio', FALSE],
      'Other' => ['Other', FALSE],
    ];
  }

  // -----------------------------------------------------------------------
  // isArchiveInUseAllowed() — 3 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::isArchiveInUseAllowed
   */
  public function testIsArchiveInUseAllowedTrue(): void {
    $service = $this->createServiceWithMultiConfig(['allow_archive_in_use' => TRUE]);
    $this->assertTrue($service->isArchiveInUseAllowed());
  }

  /**
   * @covers ::isArchiveInUseAllowed
   */
  public function testIsArchiveInUseAllowedFalse(): void {
    $service = $this->createServiceWithMultiConfig(['allow_archive_in_use' => FALSE]);
    $this->assertFalse($service->isArchiveInUseAllowed());
  }

  /**
   * @covers ::isArchiveInUseAllowed
   */
  public function testIsArchiveInUseAllowedNull(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $this->assertFalse($service->isArchiveInUseAllowed());
  }

  // -----------------------------------------------------------------------
  // isLinkRoutingEnabled() — 4 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::isLinkRoutingEnabled
   */
  public function testIsLinkRoutingEnabledBothEnabled(): void {
    $service = $this->createServiceWithMultiConfig([
      'enable_archive' => TRUE,
      'allow_archive_in_use' => TRUE,
    ]);
    $this->assertTrue($service->isLinkRoutingEnabled());
  }

  /**
   * @covers ::isLinkRoutingEnabled
   */
  public function testIsLinkRoutingEnabledOnlyEnableArchive(): void {
    $service = $this->createServiceWithMultiConfig([
      'enable_archive' => TRUE,
      'allow_archive_in_use' => FALSE,
    ]);
    $this->assertTrue($service->isLinkRoutingEnabled());
  }

  /**
   * @covers ::isLinkRoutingEnabled
   */
  public function testIsLinkRoutingEnabledOnlyAllowInUse(): void {
    $service = $this->createServiceWithMultiConfig([
      'enable_archive' => FALSE,
      'allow_archive_in_use' => TRUE,
    ]);
    $this->assertTrue($service->isLinkRoutingEnabled());
  }

  /**
   * @covers ::isLinkRoutingEnabled
   */
  public function testIsLinkRoutingEnabledNeither(): void {
    $service = $this->createServiceWithMultiConfig([
      'enable_archive' => FALSE,
      'allow_archive_in_use' => FALSE,
    ]);
    $this->assertFalse($service->isLinkRoutingEnabled());
  }

  // -----------------------------------------------------------------------
  // shouldShowArchivedLabel() — 3 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::shouldShowArchivedLabel
   */
  public function testShouldShowArchivedLabelTrue(): void {
    $service = $this->createServiceWithMultiConfig(['show_archived_label' => TRUE]);
    $this->assertTrue($service->shouldShowArchivedLabel());
  }

  /**
   * @covers ::shouldShowArchivedLabel
   */
  public function testShouldShowArchivedLabelFalse(): void {
    $service = $this->createServiceWithMultiConfig(['show_archived_label' => FALSE]);
    $this->assertFalse($service->shouldShowArchivedLabel());
  }

  /**
   * @covers ::shouldShowArchivedLabel
   */
  public function testShouldShowArchivedLabelNullReturnsFalse(): void {
    // (bool) NULL = FALSE, and FALSE ?? TRUE = FALSE (not null).
    // The ?? TRUE is effectively dead code.
    $service = $this->createServiceWithMultiConfig([]);
    $this->assertFalse($service->shouldShowArchivedLabel());
  }

  // -----------------------------------------------------------------------
  // getArchivedLabel() — 3 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getArchivedLabel
   */
  public function testGetArchivedLabelCustom(): void {
    $service = $this->createServiceWithMultiConfig(['archived_label_text' => 'Legacy']);
    $this->assertSame('Legacy', $service->getArchivedLabel());
  }

  /**
   * @covers ::getArchivedLabel
   */
  public function testGetArchivedLabelEmptyString(): void {
    $service = $this->createServiceWithMultiConfig(['archived_label_text' => '']);
    $service->setStringTranslation($this->getStringTranslationStub());
    $this->assertSame('Archived', $service->getArchivedLabel());
  }

  /**
   * @covers ::getArchivedLabel
   */
  public function testGetArchivedLabelNull(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $service->setStringTranslation($this->getStringTranslationStub());
    $this->assertSame('Archived', $service->getArchivedLabel());
  }

  // -----------------------------------------------------------------------
  // getComplianceDeadlineFormatted() — 3 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::getComplianceDeadlineFormatted
   */
  public function testGetComplianceDeadlineFormattedCustom(): void {
    // Use noon UTC to avoid timezone rollover edge cases.
    $deadline = strtotime('2027-01-15 12:00:00 UTC');
    $service = $this->createServiceWithMultiConfig(['ada_compliance_deadline' => $deadline]);
    $this->assertSame('January 15, 2027', $service->getComplianceDeadlineFormatted());
  }

  /**
   * @covers ::getComplianceDeadlineFormatted
   */
  public function testGetComplianceDeadlineFormattedNull(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $this->assertSame('April 24, 2026', $service->getComplianceDeadlineFormatted());
  }

  /**
   * @covers ::getComplianceDeadlineFormatted
   */
  public function testGetComplianceDeadlineFormattedFalse(): void {
    $service = $this->createServiceWithMultiConfig(['ada_compliance_deadline' => FALSE]);
    $this->assertSame('April 24, 2026', $service->getComplianceDeadlineFormatted());
  }

  // -----------------------------------------------------------------------
  // isAfterComplianceDeadline() + isAdaComplianceMode() — 4 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::isAfterComplianceDeadline
   */
  public function testIsAfterComplianceDeadlineFarFuture(): void {
    // Year 3000 timestamp — we're definitely not past this.
    $service = $this->createServiceWithMultiConfig(['ada_compliance_deadline' => 32503680000]);
    $this->assertFalse($service->doIsAfterComplianceDeadline());
  }

  /**
   * @covers ::isAfterComplianceDeadline
   */
  public function testIsAfterComplianceDeadlineFarPast(): void {
    // Year 2000 timestamp — we're definitely past this.
    $service = $this->createServiceWithMultiConfig(['ada_compliance_deadline' => 946684800]);
    $this->assertTrue($service->doIsAfterComplianceDeadline());
  }

  /**
   * @covers ::isAdaComplianceMode
   */
  public function testIsAdaComplianceModeFarFuture(): void {
    // Before deadline → compliance mode is active.
    $service = $this->createServiceWithMultiConfig(['ada_compliance_deadline' => 32503680000]);
    $this->assertTrue($service->isAdaComplianceMode());
  }

  /**
   * @covers ::isAdaComplianceMode
   */
  public function testIsAdaComplianceModeFarPast(): void {
    // After deadline → compliance mode is inactive.
    $service = $this->createServiceWithMultiConfig(['ada_compliance_deadline' => 946684800]);
    $this->assertFalse($service->isAdaComplianceMode());
  }

  // -----------------------------------------------------------------------
  // isVisibilityToggleBlocked() — 4 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::isVisibilityToggleBlocked
   */
  public function testIsVisibilityToggleBlockedNotAdminStatus(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('getStatus')->willReturn('archived_public');
    $this->assertNull($service->isVisibilityToggleBlocked($archive));
  }

  /**
   * @covers ::isVisibilityToggleBlocked
   */
  public function testIsVisibilityToggleBlockedManualEntry(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('getStatus')->willReturn('archived_admin');
    $archive->method('isManualEntry')->willReturn(TRUE);
    $this->assertNull($service->isVisibilityToggleBlocked($archive));
  }

  /**
   * @covers ::isVisibilityToggleBlocked
   */
  public function testIsVisibilityToggleBlockedInUseNotAllowed(): void {
    $service = $this->createServiceWithMultiConfig(['allow_archive_in_use' => FALSE]);
    $service->setMockUsageCount(3);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('getStatus')->willReturn('archived_admin');
    $archive->method('isManualEntry')->willReturn(FALSE);
    $result = $service->isVisibilityToggleBlocked($archive);
    $this->assertNotNull($result);
    $this->assertSame(3, $result['usage_count']);
    $this->assertArrayHasKey('reason', $result);
  }

  /**
   * @covers ::isVisibilityToggleBlocked
   */
  public function testIsVisibilityToggleBlockedInUseAllowed(): void {
    $service = $this->createServiceWithMultiConfig(['allow_archive_in_use' => TRUE]);
    $service->setMockUsageCount(3);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('getStatus')->willReturn('archived_admin');
    $archive->method('isManualEntry')->willReturn(FALSE);
    $this->assertNull($service->isVisibilityToggleBlocked($archive));
  }

  // -----------------------------------------------------------------------
  // isReArchiveBlocked() — 4 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::isReArchiveBlocked
   */
  public function testIsReArchiveBlockedManualEntry(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('isManualEntry')->willReturn(TRUE);
    $this->assertNull($service->isReArchiveBlocked($archive));
  }

  /**
   * @covers ::isReArchiveBlocked
   */
  public function testIsReArchiveBlockedInUseNotAllowed(): void {
    $service = $this->createServiceWithMultiConfig(['allow_archive_in_use' => FALSE]);
    $service->setMockUsageCount(5);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('isManualEntry')->willReturn(FALSE);
    $result = $service->isReArchiveBlocked($archive);
    $this->assertNotNull($result);
    $this->assertSame(5, $result['usage_count']);
    $this->assertArrayHasKey('reason', $result);
  }

  /**
   * @covers ::isReArchiveBlocked
   */
  public function testIsReArchiveBlockedInUseAllowed(): void {
    $service = $this->createServiceWithMultiConfig(['allow_archive_in_use' => TRUE]);
    $service->setMockUsageCount(5);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('isManualEntry')->willReturn(FALSE);
    $this->assertNull($service->isReArchiveBlocked($archive));
  }

  /**
   * @covers ::isReArchiveBlocked
   */
  public function testIsReArchiveBlockedNoUsage(): void {
    $service = $this->createServiceWithMultiConfig(['allow_archive_in_use' => FALSE]);
    $service->setMockUsageCount(0);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('isManualEntry')->willReturn(FALSE);
    $this->assertNull($service->isReArchiveBlocked($archive));
  }

  // -----------------------------------------------------------------------
  // calculateChecksum() — 3 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::calculateChecksum
   */
  public function testCalculateChecksumSuccess(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $tmpFile = tempnam(sys_get_temp_dir(), 'dai_test_');
    file_put_contents($tmpFile, 'test content for checksum');

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')
      ->with('public://test.pdf')
      ->willReturn($tmpFile);
    $service->setTestFileSystem($fileSystem);

    $expected = hash('sha256', 'test content for checksum');
    $this->assertSame($expected, $service->calculateChecksum('public://test.pdf'));

    unlink($tmpFile);
  }

  /**
   * @covers ::calculateChecksum
   */
  public function testCalculateChecksumFileNotFoundRealpathFalse(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')->willReturn(FALSE);
    $service->setTestFileSystem($fileSystem);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Cannot calculate checksum: file not found.');
    $service->calculateChecksum('public://missing.pdf');
  }

  /**
   * @covers ::calculateChecksum
   */
  public function testCalculateChecksumFileNotFoundNoFile(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')->willReturn('/nonexistent/path/to/file.pdf');
    $service->setTestFileSystem($fileSystem);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Cannot calculate checksum: file not found.');
    $service->calculateChecksum('public://missing.pdf');
  }

  // -----------------------------------------------------------------------
  // verifyIntegrity() — 4 cases.
  // -----------------------------------------------------------------------

  /**
   * @covers ::verifyIntegrity
   */
  public function testVerifyIntegrityEmptyChecksum(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('getFileChecksum')->willReturn('');
    $this->assertTrue($service->verifyIntegrity($archive));
  }

  /**
   * @covers ::verifyIntegrity
   */
  public function testVerifyIntegrityMatch(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $tmpFile = tempnam(sys_get_temp_dir(), 'dai_test_');
    file_put_contents($tmpFile, 'verified content');
    $checksum = hash('sha256', 'verified content');

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')->willReturn($tmpFile);
    $service->setTestFileSystem($fileSystem);
    $service->setMockSourceUri('public://test.pdf');

    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('getFileChecksum')->willReturn($checksum);
    $archive->method('getArchivePath')->willReturn('/sites/default/files/test.pdf');

    $this->assertTrue($service->verifyIntegrity($archive));

    unlink($tmpFile);
  }

  /**
   * @covers ::verifyIntegrity
   */
  public function testVerifyIntegrityMismatch(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $tmpFile = tempnam(sys_get_temp_dir(), 'dai_test_');
    file_put_contents($tmpFile, 'modified content');

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')->willReturn($tmpFile);
    $service->setTestFileSystem($fileSystem);
    $service->setMockSourceUri('public://test.pdf');

    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('getFileChecksum')->willReturn('wrong_checksum_value');
    $archive->method('getArchivePath')->willReturn('/sites/default/files/test.pdf');

    $this->assertFalse($service->verifyIntegrity($archive));

    unlink($tmpFile);
  }

  /**
   * @covers ::verifyIntegrity
   */
  public function testVerifyIntegrityUnresolvableUri(): void {
    $service = $this->createServiceWithMultiConfig([]);
    $service->setMockSourceUri(NULL);

    $archive = $this->createMock(DigitalAssetArchive::class);
    $archive->method('getFileChecksum')->willReturn('abc123def456');
    $archive->method('getArchivePath')->willReturn('/nonexistent/path');

    $this->assertFalse($service->verifyIntegrity($archive));
  }

}
