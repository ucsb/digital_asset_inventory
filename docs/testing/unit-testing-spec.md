# Unit Testing Specification

## Overview

This specification defines the PHPUnit unit testing strategy for the Digital Asset Inventory module. Tests run from the **Drupal site root** using Drupal's autoloader and `Drupal\Tests\UnitTestCase` base class.

**Scope:** Pure-logic methods and lightly-mocked service methods. Database queries, entity storage, and full Drupal bootstrap are out of scope for unit tests (covered by kernel tests — see `kernel-testing-spec.md`).

**Targets:** Four test classes covering 299 cases across `FilePathResolver`, `DigitalAssetScanner`, `ArchiveService`, and `CsvExportFilenameSubscriber`.

---

## 1. Infrastructure

### 1.1 Directory Structure

```text
digital_asset_inventory/
├── phpunit.xml.dist           ← module-scoped test suites + source coverage config
├── tests/
│   ├── README.md                 ← testing guide for contributors
│   ├── artifacts/                ← debug dumps from kernel tests (.gitignore'd)
│   └── src/
│       ├── Unit/
│       │   ├── FilePathResolverTest.php
│       │   ├── DigitalAssetScannerTest.php
│       │   └── ArchiveServiceTest.php
│       └── Kernel/
│           ├── DigitalAssetKernelTestBase.php   (shared setUp, helpers, debug dumps)
│           ├── ArchiveIntegrityKernelTest.php   (checksums, auto-void, immutability)
│           ├── ArchiveWorkflowKernelTest.php    (state machine, usage policy)
│           ├── ConfigFlagsKernelTest.php        (config flag → service behavior)
│           └── ScannerAtomicSwapKernelTest.php  (atomic swap, entity CRUD, gating)
└── src/
    ├── FilePathResolver.php
    └── Service/
        ├── DigitalAssetScanner.php
        └── ArchiveService.php
```

No module-level `composer.json` changes are needed. The site-level Drupal installation provides PHPUnit, `Drupal\Tests\UnitTestCase`, and all required autoloading.

### 1.2 PHPUnit Configuration

The module's `phpunit.xml.dist` defines both test suites and source coverage scope. It is **not** used as the primary configuration — core's `phpunit.xml.dist` provides bootstrap and autoloading. Environment variables (e.g., `SIMPLETEST_DB`) are not defined here — they must be supplied at runtime (see `tests/README.md`).

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         colors="true"
         beStrictAboutTestsThatDoNotTestAnything="true"
         failOnRisky="true"
         failOnWarning="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/src/Unit</directory>
    </testsuite>
    <testsuite name="kernel">
      <directory>tests/src/Kernel</directory>
    </testsuite>
  </testsuites>
  <source>
    <include>
      <directory suffix=".php">src</directory>
    </include>
    <exclude>
      <directory>src/Plugin</directory>
      <directory>src/Controller</directory>
      <directory>src/Form</directory>
      <directory>src/Entity</directory>
      <directory>src/EventSubscriber</directory>
    </exclude>
  </source>
</phpunit>
```

**Source exclusions:** Plugins, controllers, forms, and event subscribers depend heavily on Drupal's render/routing/entity systems and are better covered by functional tests. Entity classes are exercised indirectly via kernel tests.

### 1.3 tests/README.md

The `tests/README.md` orients contributors to the testing workflow, covering unit tests, kernel tests (with SQLite setup), platform notes (macOS, Linux, WSL, Lando, DDEV), and the debug dump infrastructure. See the actual file for the full guide — it is the canonical reference for running tests.

---

## 2. Conventions

### 2.1 Base Class

All test classes extend `Drupal\Tests\UnitTestCase`, which provides:

- PHPUnit assertions
- `$this->getStringTranslationStub()` for `t()` calls
- `$this->randomMachineName()` for test data
- `$this->createMock()` convenience wrappers

### 2.2 Class Annotations

```php
/**
 * @coversDefaultClass \Drupal\digital_asset_inventory\FilePathResolver
 * @group digital_asset_inventory
 */
class FilePathResolverTest extends UnitTestCase {
```

- `@group digital_asset_inventory` — filter with `--group digital_asset_inventory`
- `@coversDefaultClass` — enables `@covers ::methodName` shorthand on test methods

### 2.3 Test Method Naming

```
test<MethodName><Scenario>
```

Examples:

- `testUrlPathToStreamUriUniversalPublic()`
- `testMapMimeToAssetTypeUnknownMime()`
- `testNormalizeUrlRemovesDefaultPort()`

### 2.4 Data Providers

Use data providers for methods with many input/output pairs:

```php
/**
 * @covers ::mapMimeToAssetType
 * @dataProvider mimeToAssetTypeProvider
 */
public function testMapMimeToAssetType(string $mime, string $expected): void {
  $this->assertSame($expected, $this->scanner->doMapMimeToAssetType($mime));
}

public static function mimeToAssetTypeProvider(): array {
  return [
    'PDF' => ['application/pdf', 'pdf'],
    'Word legacy' => ['application/msword', 'word'],
    // ...
  ];
}
```

Data provider keys should be human-readable labels describing the case.

### 2.5 Trait Testing Pattern

`FilePathResolver` is a trait. Test it via a concrete test harness class defined in the test file:

```php
use Drupal\digital_asset_inventory\FilePathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;

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

  // Expose protected methods for testing.
  public function doGetPublicFilesBasePath(): string {
    return $this->getPublicFilesBasePath();
  }

  public function doUrlPathToStreamUri(string $url): ?string {
    return $this->urlPathToStreamUri($url);
  }

  // ... one public wrapper per protected trait method.
}
```

The mock `fileUrlGenerator` is configured in `setUp()` to return the desired base path (e.g., `/sites/default/files` or `/files`).

### 2.6 Service Subclass Pattern

For `DigitalAssetScanner` and `ArchiveService`, protected methods cannot be called directly. Use a test subclass that exposes them:

```php
/**
 * Test harness exposing protected methods of DigitalAssetScanner.
 *
 * @internal
 */
final class TestableDigitalAssetScanner extends DigitalAssetScanner {

  public function doMapMimeToAssetType(string $mime): string {
    return $this->mapMimeToAssetType($mime);
  }

  // ... one public wrapper per protected method under test.
}
```

Constructor arguments are mocked in `setUp()`. Harness classes are marked `@internal` and `final` to signal they are test infrastructure only.

### 2.7 Config Fixture Isolation

Config-dependent tests use a helper that builds **fresh mocks per call** to keep config fixtures isolated between test cases. PHPUnit mocks configured with `->method()` cannot be reconfigured, so each config scenario requires a new scanner/service instance with its own mock graph:

```php
protected function createScannerWithConfig(?array $assetTypes): TestableDigitalAssetScanner {
  $config = $this->createMock(ImmutableConfig::class);
  $config->method('get')
    ->with('asset_types')
    ->willReturn($assetTypes);

  $configFactory = $this->createMock(ConfigFactoryInterface::class);
  $configFactory->method('get')
    ->with('digital_asset_inventory.settings')
    ->willReturn($config);

  // ... build fresh logger, fileUrlGenerator, and all other mocks ...

  return new TestableDigitalAssetScanner(/* fresh mocks */);
}
```

### 2.8 Assertions

- Prefer `assertSame()` over `assertEquals()` for type-strict comparisons.
- Use `assertNull()` explicitly (not `assertSame(null, ...)`).
- Use `assertCount()` for array length checks.
- Use `assertContains()` / `assertNotContains()` for array membership.

---

## 3. Test Coverage Plan

### 3.1 FilePathResolverTest (47 cases)

**File:** `tests/src/Unit/FilePathResolverTest.php`

Tests the `FilePathResolver` trait via `TestFilePathResolverHost` (see section 2.5).

#### setUp()

```php
protected function setUp(): void {
  parent::setUp();

  $this->fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
  // Default: standard Drupal single-site layout.
  $this->fileUrlGenerator->method('generateString')
    ->with('public://')
    ->willReturn('/sites/default/files');

  $this->host = new TestFilePathResolverHost($this->fileUrlGenerator);
}
```

A helper method reconfigures for alternate base paths:

```php
protected function createHostWithBasePath(string $basePath): TestFilePathResolverHost {
  $generator = $this->createMock(FileUrlGeneratorInterface::class);
  $generator->method('generateString')
    ->with('public://')
    ->willReturn($basePath);
  return new TestFilePathResolverHost($generator);
}
```

#### 3.1.1 getPublicFilesBasePath() — 4 cases

| # | Case | generateString returns | Expected |
|---|------|----------------------|----------|
| 1 | Default site | `/sites/default/files` | `/sites/default/files` |
| 2 | Multisite | `/sites/example.edu/files` | `/sites/example.edu/files` |
| 3 | Leading slash missing | `sites/default/files` | `/sites/default/files` |
| 4 | Trailing slash present | `/sites/default/files/` | `/sites/default/files` |

#### 3.1.2 getPublicFilesBasePathRegex() — 2 cases

Uses `preg_quote($path, '#')`. With `#` as delimiter, `/` is not escaped (not a PCRE metacharacter); only `.` is escaped.

| # | Case | Expected |
|---|------|----------|
| 1 | Default path (no special chars) | `/sites/default/files` |
| 2 | Dot in host escaped | `/sites/example\.edu/files` |

#### 3.1.3 getPublicFilesPathPattern() — 1 case

| # | Case | Expected |
|---|------|----------|
| 1 | Returns universal fragment | `sites/[^/]+/files` |

#### 3.1.4 publicStreamToUrlPath() — 2 cases

| # | Case | Input | Expected |
|---|------|-------|----------|
| 1 | Simple filename | `document.pdf` | `/sites/default/files/document.pdf` |
| 2 | Nested path | `archive/2025/report.pdf` | `/sites/default/files/archive/2025/report.pdf` |

#### 3.1.5 urlPathToStreamUri() — 22 cases

**Data provider:** `urlPathToStreamUriProvider()`

*Universal patterns:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 1 | Universal public — default site | `/sites/default/files/doc.pdf` | `public://doc.pdf` |
| 2 | Universal public — multisite | `/sites/example.edu/files/doc.pdf` | `public://doc.pdf` |
| 3 | Universal public — nested path | `/sites/default/files/archive/2025/report.pdf` | `public://archive/2025/report.pdf` |
| 4 | Legacy private under public | `/sites/default/files/private/doc.pdf` | `private://doc.pdf` |
| 5 | Legacy private — multisite | `/sites/example.edu/files/private/secret.pdf` | `private://secret.pdf` |
| 6 | Universal private — system/files | `/system/files/doc.pdf` | `private://doc.pdf` |
| 7 | Universal private — nested | `/system/files/reports/q4.pdf` | `private://reports/q4.pdf` |

*Dynamic fallback (tested with non-standard `/files` base path):*

| # | Case | Base path | Input | Expected |
|---|------|-----------|-------|----------|
| 8 | Dynamic public | `/files` | `/files/doc.pdf` | `public://doc.pdf` |
| 9 | Dynamic private | `/files` | `/files/private/doc.pdf` | `private://doc.pdf` |

*Full URL handling:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 10 | HTTPS absolute URL | `https://example.edu/sites/default/files/doc.pdf` | `public://doc.pdf` |
| 11 | HTTP absolute URL | `http://example.edu/sites/default/files/doc.pdf` | `public://doc.pdf` |
| 12 | Scheme-relative URL | `//example.edu/sites/default/files/doc.pdf` | `public://doc.pdf` |

*Passthrough and edge cases:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 13 | Already public stream URI | `public://doc.pdf` | `public://doc.pdf` |
| 14 | Already private stream URI | `private://doc.pdf` | `private://doc.pdf` |
| 15 | Unrecognized path | `/some/random/path.pdf` | `null` |
| 16 | Empty string | `` | `null` |
| 17 | External URL (no local path) | `https://cdn.example.com/file.pdf` | `null` |

*Input sanitization:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 18 | Wrapped in double quotes | `"/sites/default/files/doc.pdf"` | `public://doc.pdf` |
| 19 | Wrapped in single quotes | `'/sites/default/files/doc.pdf'` | `public://doc.pdf` |
| 20 | Leading/trailing whitespace | `  /sites/default/files/doc.pdf  ` | `public://doc.pdf` |
| 21 | URL-encoded path | `/sites/default/files/My%20Report.pdf` | `public://My Report.pdf` |
| 22 | Query string stripped | `https://example.edu/sites/default/files/doc.pdf?itok=abc123` | `public://doc.pdf` |

#### 3.1.6 extractLocalFileUrlsFromText() — 11 cases

**Data provider:** `extractLocalFileUrlsProvider()`

| # | Case | Text (abbreviated) | Expected URLs |
|---|------|-------------------|---------------|
| 1 | Single public file link | `<a href="/sites/default/files/doc.pdf">` | `['/sites/default/files/doc.pdf']` |
| 2 | Multiple files | Two `<a>` tags with different files | Both paths |
| 3 | Multisite path | `<a href="/sites/example.edu/files/doc.pdf">` | `['/sites/example.edu/files/doc.pdf']` |
| 4 | Private file (system/files) | `<a href="/system/files/doc.pdf">` | `['/system/files/doc.pdf']` |
| 5 | HTML entity decoded, ampersand kept | `<a href="/sites/default/files/doc.pdf&amp;v=1">` | `['/sites/default/files/doc.pdf&v=1']` |
| 6 | Query string stripped | `<a href="/sites/default/files/doc.pdf?itok=abc">` | `['/sites/default/files/doc.pdf']` |
| 7 | Dynamic base fallback | Text containing `/files/doc.pdf` (with `/files` base path) | `['/files/doc.pdf']` |
| 8 | Deduplication | Same file referenced twice | Single entry |
| 9 | No files found | `<p>Hello world</p>` | `[]` |
| 10 | Mixed public and private | Both types in one text block | Both paths |
| 11 | Image src attribute | `<img src="/sites/default/files/photo.jpg">` | `['/sites/default/files/photo.jpg']` |

**Note on case 5 vs 6:** The regex character class does not include `&` as a stop character, so decoded `&v=1` becomes part of the matched path. The post-match strip (`preg_replace('/[?#].*$/', '')`) only removes from `?` or `#` onward. This is intentional — `&` in a path segment is valid and should be preserved.

#### 3.1.7 extractLocalFileUrisFromText() — 5 cases

Test method accepts optional `$basePath` parameter (same pattern as URL extraction).

| # | Case | Text | Expected URIs | Base path |
|---|------|------|---------------|-----------|
| 1 | Public file | Link to `/sites/default/files/doc.pdf` | `['public://doc.pdf']` | default |
| 2 | Private file | Link to `/system/files/doc.pdf` | `['private://doc.pdf']` | default |
| 3 | Mixed | Both public and private links | Both URIs | default |
| 4 | No files | Plain text | `[]` | default |
| 5 | Dynamic base path | Link to `/files/doc.pdf` | `['public://doc.pdf']` | `/files` |

---

### 3.2 DigitalAssetScannerTest (162 cases)

**File:** `tests/src/Unit/DigitalAssetScannerTest.php`

Tests `DigitalAssetScanner` via `TestableDigitalAssetScanner` subclass (see section 2.6).

#### setUp()

All eight constructor dependencies are mocked. The default scanner instance (used by pure-logic tests) has no config behavior configured. Config-dependent tests use `createScannerWithConfig()` (see section 2.7).

```php
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

  // Default logger stub.
  $logger = $this->createMock(LoggerChannelInterface::class);
  $this->loggerFactory->method('get')->willReturn($logger);

  // Default file URL generator for FilePathResolver trait.
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
```

#### 3.2.1 mapMimeToAssetType() — 28 cases (pure map, no mocks)

**Data provider:** `mimeToAssetTypeProvider()`

Every entry in the hardcoded map, plus edge cases:

*Documents:*

| # | MIME | Expected |
|---|------|----------|
| 1 | `application/pdf` | `pdf` |
| 2 | `application/msword` | `word` |
| 3 | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` | `word` |
| 4 | `application/vnd.ms-excel` | `excel` |
| 5 | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` | `excel` |
| 6 | `application/vnd.ms-powerpoint` | `powerpoint` |
| 7 | `application/vnd.openxmlformats-officedocument.presentationml.presentation` | `powerpoint` |
| 8 | `text/plain` | `text` |
| 9 | `text/csv` | `csv` |
| 10 | `application/csv` | `csv` |
| 11 | `text/vtt` | `vtt` |
| 12 | `application/x-subrip` | `srt` |
| 13 | `text/srt` | `srt` |

*Images:*

| # | MIME | Expected |
|---|------|----------|
| 14 | `image/jpeg` | `jpg` |
| 15 | `image/png` | `png` |
| 16 | `image/gif` | `gif` |
| 17 | `image/svg+xml` | `svg` |
| 18 | `image/webp` | `webp` |

*Videos:*

| # | MIME | Expected |
|---|------|----------|
| 19 | `video/mp4` | `mp4` |
| 20 | `video/webm` | `webm` |
| 21 | `video/quicktime` | `mov` |
| 22 | `video/x-msvideo` | `avi` |

*Audio:*

| # | MIME | Expected |
|---|------|----------|
| 23 | `audio/mpeg` | `mp3` |
| 24 | `audio/wav` | `wav` |
| 25 | `audio/mp4` | `m4a` |
| 26 | `audio/ogg` | `ogg` |

*Edge cases:*

| # | MIME | Expected |
|---|------|----------|
| 27 | `application/octet-stream` (unknown) | `other` |
| 28 | `  APPLICATION/PDF  ` (whitespace + case) | `pdf` |

#### 3.2.2 mapAssetTypeToCategory() — 5 cases (config mock)

Uses `createScannerWithConfig()` (see section 2.7) to provide isolated config fixtures per test.

| # | Case | Config asset_types | Input | Expected |
|---|------|-------------------|-------|----------|
| 1 | Known type | `['pdf' => ['category' => 'Documents']]` | `pdf` | `Documents` |
| 2 | Video type | `['mp4' => ['category' => 'Videos']]` | `mp4` | `Videos` |
| 3 | Unknown type (not in config) | `['pdf' => ['category' => 'Documents']]` | `xyz` | `Unknown` |
| 4 | Null config | Config returns `null` | `pdf` | `Unknown` |
| 5 | Missing category key | `['pdf' => ['label' => 'PDF']]` | `pdf` | `Unknown` |

#### 3.2.3 matchUrlToAssetType() — 13 cases (config mock)

Uses `createScannerWithConfig()` for isolated config fixtures. The implementation iterates `asset_types` in PHP array insertion order (`foreach`), so the first matching type wins.

**Standard config fixture** (via `createScannerWithUrlPatterns()` helper):

```php
$assetTypes = [
  'google_doc'   => ['url_patterns' => ['docs.google.com/document']],
  'google_sheet' => ['url_patterns' => ['docs.google.com/spreadsheets']],
  'youtube'      => ['url_patterns' => ['youtube.com', 'youtu.be']],
  'vimeo'        => ['url_patterns' => ['vimeo.com']],
  'box_link'     => ['url_patterns' => ['box.com']],
];
```

| # | Case | Input URL | Expected |
|---|------|-----------|----------|
| 1 | Google Doc | `https://docs.google.com/document/d/abc` | `google_doc` |
| 2 | Google Sheet | `https://docs.google.com/spreadsheets/d/xyz` | `google_sheet` |
| 3 | YouTube full | `https://www.youtube.com/watch?v=abc` | `youtube` |
| 4 | YouTube short | `https://youtu.be/abc` | `youtube` |
| 5 | Vimeo | `https://vimeo.com/123456` | `vimeo` |
| 6 | Box | `https://app.box.com/s/abc123` | `box_link` |
| 7 | No match | `https://example.com/page` | `other` |
| 8 | Case insensitive | `HTTPS://DOCS.GOOGLE.COM/DOCUMENT/D/ABC` | `google_doc` |
| 9 | Empty URL | `` | `other` |
| 10 | Null config | Config returns `null` | `other` |
| 11 | First pattern wins (overlapping) | Both `type_a` and `type_b` match `example.com`; first-defined wins | `type_a` |
| 12 | No url_patterns key | `['pdf' => ['category' => 'Documents']]` | `other` |
| 13 | Empty url_patterns array | `['pdf' => ['url_patterns' => []]]` | `other` |

**Note on case 11:** Uses a dedicated overlapping fixture (`type_a` and `type_b` both with pattern `example.com`) to prove that array insertion order determines precedence.

#### 3.2.4 getCategorySortOrder() — 12 cases (pure map, no mocks)

**Data provider:** `categorySortOrderProvider()`

| # | Category | Expected |
|---|----------|----------|
| 1 | `Documents` | `1` |
| 2 | `Videos` | `2` |
| 3 | `Audio` | `3` |
| 4 | `Google Workspace` | `4` |
| 5 | `Document Services` | `5` |
| 6 | `Forms & Surveys` | `6` |
| 7 | `Education Platforms` | `7` |
| 8 | `Embedded Media` | `8` |
| 9 | `Images` | `9` |
| 10 | `Other` | `10` |
| 11 | Unknown category | `99` |
| 12 | Empty string | `99` |

#### 3.2.5 formatFileSize() — 5 cases (pure logic, no mocks)

**Data provider:** `fileSizeProvider()`

| # | Input (bytes) | Expected |
|---|---------------|----------|
| 1 | `0` | `-` |
| 2 | `500` | `500 B` |
| 3 | `1024` | `1.00 KB` |
| 4 | `1572864` (1.5 MB) | `1.50 MB` |
| 5 | `1073741824` (1 GB) | `1.00 GB` |

#### 3.2.6 normalizeVideoUrl() — 15 cases (pure regex, no deps)

**Data provider:** `normalizeVideoUrlProvider()`

Tests YouTube (standard watch, short URL, embed, old /v/, shorts, no-cookie, extra params) and Vimeo (standard, player embed) URL normalization. Also covers bare video IDs (11-char alphanumeric → YouTube, numeric → Vimeo), empty string, non-video URL, schemeless YouTube, and Vimeo with trailing path.

#### 3.2.7 parseMenuLinkUri() — 7 cases (delegates to extractFileInfoFromPath/urlPathToStreamUri)

**Data provider:** `parseMenuLinkUriProvider()`

Tests `internal:` prefix (public/private), `base:` prefix, `entity:` (returns NULL), `route:` (returns NULL), HTTPS local file URL, and non-file URL.

#### 3.2.8 extractFileInfoFromPath() — 5 cases

**Data provider:** `extractFileInfoFromPathProvider()`

Tests public file path, private file path, path with query string (stripped), non-file path (NULL), and path with quotes (trimmed).

#### 3.2.9 extractUrls() — 5 cases (pure regex)

**Data provider:** `extractUrlsProvider()`

Tests single URL, multiple URLs, deduplication, no URLs, and URL in HTML attribute.

#### 3.2.10 getMimeTypeFromExtension() — 8 cases (pure map)

**Data provider:** `getMimeTypeFromExtensionProvider()`

Tests pdf, docx, jpg, mp4, mp3, zip, vtt, and unknown extension (→ `application/octet-stream`).

#### 3.2.11 extensionToMime() — 6 cases (pure map with strtolower)

**Data provider:** `extensionToMimeProvider()`

Tests lowercase, uppercase, mixed case, srt (not in this map → octet-stream), unknown, and rar. Verifies `strtolower()` normalization.

#### 3.2.12 mapExtensionToAssetType() — 7 cases (pure map)

**Data provider:** `mapExtensionToAssetTypeProvider()`

Tests mp4, ogg, oga→ogg alias, vtt, srt, unknown passthrough (returns input), and mkv.

#### 3.2.13 buildAccessibilitySignals() — 6 cases (pure logic)

Tests all signals detected, none detected, controls only, subtitles track (counts as captions), descriptions track (not captions), and mixed signals + captions.

#### 3.2.14 getKnownExtensions() — 1 case (snapshot)

Verifies array is non-empty, contains expected entries (pdf, jpg, mp4, mp3, zip), and all elements are strings.

#### 3.2.15 extractLocalFileUrls() — 10 cases (regex + urlPathToStreamUri delegation)

**Data provider:** `extractLocalFileUrlsProvider()`

Tests `<a href>` public file, `<img src>`, `<object data>`, `<embed src>`, private file, query stripped, deduplication, external URL (no match), multi-line tag, and HTML entities decoded.

#### 3.2.16 extractIframeUrls() — 6 cases (pure regex)

**Data provider:** `extractIframeUrlsProvider()`

Tests YouTube iframe, multiple iframes, deduplication, HTML entity in URL, no iframes, and empty src.

#### 3.2.17 extractMediaUuids() — 5 cases (pure regex)

**Data provider:** `extractMediaUuidsProvider()`

Tests single UUID, multiple UUIDs, deduplication, no media tags, and UUID with extra attributes.

#### 3.2.18 cleanMediaUrl() — 4 cases (html_entity_decode + trim)

**Data provider:** `cleanMediaUrlProvider()`

Tests already clean URL, entity-encoded ampersand, whitespace, and encoded quotes.

#### 3.2.19 resolveMediaUrl() — 6 cases (with explicit $base_url)

**Data provider:** `resolveMediaUrlProvider()`

Tests already absolute URL, protocol-relative, root-relative, relative path, HTTP scheme, and empty base_url with absolute URL. Skips NULL base_url + relative URL (calls `\Drupal::request()`).

#### 3.2.20 detectVideoIdFromFieldName() — 8 cases (pure logic, keyword + format matching)

**Data provider:** `detectVideoIdFromFieldNameProvider()`

Tests YouTube keyword detection (field/table name contains "youtube"), Vimeo keyword detection ("vimeo"), generic "video_id" keyword with YouTube-format-wins priority, no-keyword-match → NULL, empty value → NULL, value too long (>20 chars) → NULL, YouTube keyword with invalid ID format (not 11-char alphanumeric) → NULL.

---

### 3.3 ArchiveServiceTest (71 cases)

**File:** `tests/src/Unit/ArchiveServiceTest.php`

Tests `ArchiveService` via `TestableArchiveService` subclass.

#### setUp()

All eight constructor dependencies are mocked. Config-dependent tests use `createServiceWithConfig()` (same isolation pattern as scanner tests, see section 2.7).

```php
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
```

#### 3.3.1 normalizeUrl() — 16 cases (pure logic, no mocks)

`normalizeUrl()` is a public method — called directly without a harness wrapper.

**Data provider:** `normalizeUrlProvider()`

*Scheme normalization:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 1 | HTTPS preserved | `https://example.com/path` | `https://example.com/path` |
| 2 | Mixed-case scheme | `HTTPS://Example.COM/Path` | `https://example.com/Path` |
| 3 | HTTP scheme | `http://example.com/page` | `http://example.com/page` |

*Host normalization:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 4 | Lowercase host | `https://EXAMPLE.COM/page` | `https://example.com/page` |
| 5 | Subdomain preserved | `https://www.example.com/page` | `https://www.example.com/page` |

*Port handling:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 6 | Default HTTPS port removed | `https://example.com:443/path` | `https://example.com/path` |
| 7 | Default HTTP port removed | `http://example.com:80/path` | `http://example.com/path` |
| 8 | Non-default port preserved | `https://example.com:8080/path` | `https://example.com:8080/path` |

*Path normalization:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 9 | Trailing slash removed | `https://example.com/path/` | `https://example.com/path` |
| 10 | Root path kept | `https://example.com/` | `https://example.com/` |
| 11 | Path case preserved | `https://example.com/My/Path` | `https://example.com/My/Path` |

*Query and fragment:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 12 | Query string preserved | `https://example.com/path?key=value` | `https://example.com/path?key=value` |
| 13 | Fragment stripped | `https://example.com/path#section` | `https://example.com/path` |

*Relative URLs:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 14 | Relative path (no host) | `/path/to/page` | `/path/to/page` |

*Scheme-relative URLs:*

| # | Case | Input | Expected |
|---|------|-------|----------|
| 15 | Scheme-relative defaults to https | `//example.com/path` | `https://example.com/path` |
| 16 | Scheme-relative host only defaults to https | `//example.com` | `https://example.com/` |

**Note on cases 15–16:** `parse_url('//example.com/...')` returns a host but no scheme. The implementation defaults missing scheme to `'https'`. The host-only variant confirms the root path `/` is preserved (the trailing-slash stripper skips `/`).

#### 3.3.2 isRedirectEligibleAssetType() — 8 cases (config mock)

Config provides `asset_types` with category mappings via `createServiceWithRedirectConfig()` helper:

```php
$assetTypes = [
  'pdf'        => ['category' => 'Documents'],
  'word'       => ['category' => 'Documents'],
  'mp4'        => ['category' => 'Videos'],
  'jpg'        => ['category' => 'Images'],
  'mp3'        => ['category' => 'Audio'],
  'compressed' => ['category' => 'Other'],
];
```

The implementation checks: manual entry types (`page`, `external`) are always eligible; for other types, only `Documents` and `Videos` categories qualify. Comparison is strict (`in_array` with exact string match).

| # | Case | Input | Expected |
|---|------|-------|----------|
| 1 | PDF (Documents) | `pdf` | `true` |
| 2 | Word (Documents) | `word` | `true` |
| 3 | MP4 (Videos) | `mp4` | `true` |
| 4 | Page (manual entry) | `page` | `true` |
| 5 | External (manual entry) | `external` | `true` |
| 6 | JPG (Images — ineligible) | `jpg` | `false` |
| 7 | MP3 (Audio — ineligible) | `mp3` | `false` |
| 8 | Unknown type (not in config) | `xyz` | `false` |

#### 3.3.3 urlToStreamUri() — 7 cases (strips internal:/base: prefixes, delegates to trait)

**Data provider:** `urlToStreamUriProvider()`

Tests plain public path, `internal:` prefix (with and without leading slash), `base:` prefix, private `/system/files`, non-file path (NULL), and already-stream-URI passthrough. Uses `createServiceWithMultiConfig()`.

#### 3.3.4 canArchive() — 5 cases (entity mock)

**Data provider:** `canArchiveProvider()`

Mocks `DigitalAssetItem::getCategory()` and tests Documents (TRUE), Videos (TRUE), Images (FALSE), Audio (FALSE), Other (FALSE).

#### 3.3.5 isArchiveInUseAllowed() — 3 cases (config)

Tests TRUE, FALSE, and NULL (not set → FALSE) for `allow_archive_in_use` config key.

#### 3.3.6 isLinkRoutingEnabled() — 4 cases (config)

Tests both enabled, only `enable_archive`, only `allow_archive_in_use` (fallback), and neither. Verifies backwards-compatibility fallback logic.

#### 3.3.7 shouldShowArchivedLabel() — 3 cases (config)

Tests TRUE, FALSE, and NULL. Verifies that NULL returns FALSE (the `??` operator applies to the already-cast boolean result).

#### 3.3.8 getArchivedLabel() — 3 cases (config + StringTranslation)

Tests custom label (`'Legacy'`), empty string (fallback to `'Archived'`), and NULL (fallback). Empty/NULL cases inject `StringTranslationTrait` stub via `setStringTranslation()`.

#### 3.3.9 getComplianceDeadlineFormatted() — 3 cases (config)

Tests custom deadline (→ `'January 15, 2027'`), NULL (→ default `'April 24, 2026'`), and FALSE (falsy → default).

#### 3.3.10 isAfterComplianceDeadline() + isAdaComplianceMode() — 4 cases

Tests with far-future timestamp (year 3000 → not after, compliance mode active) and far-past timestamp (year 2000 → after, compliance mode inactive). Avoids wall-clock dependency by using extreme timestamps.

#### 3.3.11 isVisibilityToggleBlocked() — 4 cases (entity mock + config)

Tests the blocking logic for toggling archive visibility from Admin-only to Public. Uses `DigitalAssetArchive` mock and overridden `getUsageCountByArchive()` in harness. Cases: status != archived_admin → NULL, manual entry bypass → NULL, in-use + not allowed → blocked array with usage_count and reason, in-use + allowed → NULL.

#### 3.3.12 isReArchiveBlocked() — 4 cases (entity mock + config)

Tests the blocking logic for re-archiving. Uses same harness overrides. Cases: manual entry bypass → NULL, in-use + not allowed → blocked array, in-use + allowed → NULL, no usage → NULL.

#### 3.3.13 calculateChecksum() — 3 cases (temp file + fileSystem mock)

Tests SHA256 checksum calculation using real temp files for `file_exists()` and `hash_file()` PHP builtins. Uses `setTestFileSystem()` harness setter to inject configured `FileSystemInterface` mock. Cases: file exists → correct SHA256 hash, `realpath()` returns FALSE → throws Exception, `realpath()` returns non-existent path → throws Exception.

#### 3.3.14 verifyIntegrity() — 4 cases (entity mock + temp file)

Tests archived file integrity verification. Uses overridden `resolveSourceUri()` and `setTestFileSystem()` in harness, plus `DigitalAssetArchive` mock (`getFileChecksum()`, `getArchivePath()`). Cases: empty stored checksum → TRUE (skip check), matching checksum → TRUE, mismatching checksum → FALSE, unresolvable URI → FALSE.

---

## 4. Verification

### 4.1 Run All Unit Tests

From the Drupal site root:

```bash
./vendor/bin/phpunit web/modules/custom/digital_asset_inventory/tests/src/Unit
```

If the site doesn't have a `phpunit.xml` that bootstraps Drupal:

```bash
./vendor/bin/phpunit --bootstrap web/core/tests/bootstrap.php \
  web/modules/custom/digital_asset_inventory/tests/src/Unit
```

### 4.2 Run a Single Test Class

```bash
./vendor/bin/phpunit --bootstrap web/core/tests/bootstrap.php \
  web/modules/custom/digital_asset_inventory/tests/src/Unit/FilePathResolverTest.php
```

### 4.3 Run by Group

```bash
./vendor/bin/phpunit --bootstrap web/core/tests/bootstrap.php \
  --group digital_asset_inventory
```

### 4.4 Run with Coverage

```bash
./vendor/bin/phpunit --bootstrap web/core/tests/bootstrap.php \
  --coverage-text \
  web/modules/custom/digital_asset_inventory/tests/src/Unit
```

Requires Xdebug or PCOV. The `<source>` block in `phpunit.xml.dist` scopes coverage to `src/` excluding plugins, controllers, forms, and entities.

---

## 5. CI Integration

> **Status:** Not yet implemented. Documented here for future reference.

When the module is extracted to its own repository, a GitHub Actions workflow can run tests against multiple PHP versions:

```yaml
name: PHPUnit
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.1', '8.2', '8.3']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: pcov
      - name: Install Drupal site for test bootstrap
        run: |
          composer create-project drupal/recommended-project drupal-site --no-interaction
          ln -s $GITHUB_WORKSPACE drupal-site/web/modules/custom/digital_asset_inventory
      - name: Run tests
        run: |
          cd drupal-site
          ./vendor/bin/phpunit --bootstrap web/core/tests/bootstrap.php \
            web/modules/custom/digital_asset_inventory/tests/src/Unit
```

When running inside a consuming Drupal site (e.g., `UCSBWebTheme.Drupal10`), tests run directly from the site root using the site's autoloader and test discovery (via `@group` annotation).

---

## Summary

| Test Class | Methods | Cases | Mocking |
|------------|---------|-------|---------|
| `FilePathResolverTest` | 7 | 47 | `FileUrlGeneratorInterface` only |
| `DigitalAssetScannerTest` | 20 | 162 | All 8 constructor deps; config isolated via `createScannerWithConfig()` |
| `ArchiveServiceTest` | 14 | 71 | All 8 constructor deps; config isolated via `createServiceWithConfig()` / `createServiceWithMultiConfig()`; entity mocks for `DigitalAssetArchive`; temp files for checksum tests |
| `CsvExportFilenameSubscriberTest` | 4 | 19 | `ConfigFactoryInterface`, `DateFormatterInterface`, `TransliterationInterface`, `TimeInterface`; test harness subclass exposing protected methods |
| **Total** | **45** | **299** | |
