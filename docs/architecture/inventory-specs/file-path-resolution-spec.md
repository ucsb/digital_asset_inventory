# File Path Resolution Specification

## Overview

The Digital Asset Inventory module must resolve file paths across standard Drupal (`sites/default/files`), multisite (`sites/mysite.com/files`), Site Factory (`sites/factorysite/files`), and non-standard configurations (`/files`). The `FilePathResolver` trait (`src/FilePathResolver.php`) centralizes all file path logic around three distinct concerns.

## Design Principle

> **Discover and parse using universal path anchors; generate using site-aware services.**

This principle separates file path operations into three concerns, each using the appropriate strategy:

| Concern | Strategy | Example |
|---------|----------|---------|
| **Discovery** — finding file URLs in text | Universal regex patterns that match ALL Drupal installations | `sites/[^/]+/files` matches any site |
| **Construction** — building URLs for the current site | Dynamic path from `FileUrlGeneratorInterface` | `getPublicFilesBasePath()` returns `/sites/mysite.com/files` |
| **Conversion** — URL path to stream URI | Universal patterns first, dynamic fallback second | `/sites/any/files/doc.pdf` becomes `public://doc.pdf` |

## Architecture

### FilePathResolver Trait

**Location:** `src/FilePathResolver.php`

**Requirement:** Implementing classes must have `$this->fileUrlGenerator` available (an instance of `FileUrlGeneratorInterface`).

#### Methods

| Method | Returns | Concern | Purpose |
|--------|---------|---------|---------|
| `getPublicFilesBasePath()` | `/sites/default/files` (dynamic) | Construction | Build URL paths for the current site |
| `getPublicFilesBasePathRegex()` | Escaped path for regex | Construction / Discovery fallback | Regex matching the current site's path |
| `getPublicFilesPathPattern()` | `sites/[^/]+/files` (fragment) | Discovery | Universal regex fragment for finding file URLs |
| `publicStreamToUrlPath($relative)` | `/sites/default/files/$relative` | Construction | Convert stream-relative path to URL path |
| `urlPathToStreamUri($url)` | `public://...` or `private://...` or `NULL` | Conversion | Convert any URL format to a Drupal stream URI |
| `extractLocalFileUrlsFromText($text)` | Array of file URL paths | Discovery | Find all local file URLs in HTML/text |
| `extractLocalFileUrisFromText($text)` | Array of stream URIs | Discovery + Conversion | Find file URLs and convert to stream URIs |

#### Dynamic Path Resolution

`getPublicFilesBasePath()` resolves the path at runtime:

```php
$url = $this->fileUrlGenerator->generateString('public://');
// Returns: /sites/default/files, /sites/mysite.com/files, /files, etc.
```

The result is cached in `$publicFilesBasePath` for the lifetime of the service instance (one HTTP request).

### Conversion Order

`urlPathToStreamUri()` uses a 5-step first-match-wins strategy:

| Step | Pattern | Result | Why This Order |
|------|---------|--------|----------------|
| 1 | `sites/[^/]+/files/private/` | `private://` | Legacy private paths must match before general public pattern |
| 2 | `sites/[^/]+/files/` | `public://` | Universal public — matches all standard Drupal installations |
| 3 | `{dynamic_base}/private/` | `private://` | Covers non-standard configs (e.g., `/files/private/`) |
| 4 | `{dynamic_base}/` | `public://` | Covers non-standard public paths (e.g., `/files/`) |
| 5 | `/system/files/` | `private://` | Universal private file route (all Drupal setups) |

**Input normalization** (applied before matching):
- Trim quotes and whitespace wrappers
- Pass through existing stream URIs (`public://`, `private://`) as-is
- Handle scheme-relative URLs (`//example.edu/...` prepended with `https:`)
- Extract path component from full URLs (`https://...`)
- Strip query strings and fragments (`?itok=...`, `#page=2`)
- Use `rawurldecode()` (not `urldecode()`) for path segments to mirror Drupal's encoding

### Discovery Patterns

`extractLocalFileUrlsFromText()` finds file URLs in HTML/text using three patterns:

| Priority | Pattern | What It Matches |
|----------|---------|-----------------|
| 1 | `/sites/[^/]+/files/[^"'>\\s?#]+` | Public files (all standard Drupal installations) |
| 2 | `{dynamic_base}/[^"'>\\s?#]+` | Public files (non-standard configs like `/files/`) |
| 3 | `/system/files/[^"'>\\s?#]+` | Private files (universal route) |

HTML entities are decoded before matching (`&amp;`, `&#039;`), and query strings are stripped from results.

### Module-Level Helper

For procedural code (`.module` file), a standalone helper wraps the same logic:

```php
function _digital_asset_inventory_get_public_files_base_path(): string
```

Uses `\Drupal::service('file_url_generator')` with a `static` cache for the request lifetime. Returns the same dynamic path as `getPublicFilesBasePath()`.

## Implementing Classes

| Class | Injection Method | Primary Usage |
|-------|-----------------|---------------|
| `DigitalAssetScanner` | Constructor via `services.yml` | Discovery in text fields, conversion for scanned URLs, construction for DB search needles |
| `ArchiveService` | Constructor via `services.yml` | Conversion for archive path resolution, construction for archive path matching |
| `ArchiveLinkResponseSubscriber` | Constructor via `services.yml` | Construction for URL rewriting in response HTML |
| `DeleteAssetForm` | Constructor + `create()` | Conversion for resolving file paths during deletion |
| `AssetInfoHeader` | Constructor + `create()` (Views plugin) | Conversion for thumbnail image style URL generation |

### Services Configuration

All service-based classes receive `@file_url_generator` via `digital_asset_inventory.services.yml`. Form and Views plugin classes inject it through `ContainerInterface::get('file_url_generator')` in their `create()` methods.

## Scanner Method Integration

Each scanner method that works with file paths delegates to the trait:

| Scanner Method | Trait Method Used | Purpose |
|----------------|-------------------|---------|
| `extractLocalFileUrls()` | `urlPathToStreamUri()` | Convert `<a>`, `<img>`, `<object>`, `<embed>` URLs to stream URIs |
| `extractHtml5MediaEmbeds()` | `urlPathToStreamUri()` (via `parseHtml5MediaTag`) | Convert `<video>`, `<audio>`, `<source>`, `<track>` URLs |
| `findLocalFileLinkUsage()` | `getPublicFilesBasePath()` | Build DB search needles for the current site |
| `extractFileInfoFromPath()` | `urlPathToStreamUri()` | Single-call conversion for menu link paths |
| `processLocalFileLink()` | (indirect — receives already-converted URIs) | Looks up files by URI, generates URLs via `fileUrlGenerator` |

## Edge Cases

### Stream URI Pass-Through

Both `urlPathToStreamUri()` and downstream methods like `resolveMediaUrl()` detect existing stream URIs (`public://...`, `private://...`) and return them as-is, preventing double-conversion.

### Prefix Match for Dynamic Fallback

The dynamic base path fallback (steps 3 and 4) uses strict prefix matching (`strpos($path, $base) === 0`) instead of substring search (`!== FALSE`) to prevent accidental mid-path hits (e.g., a path like `/other/files/sites/default/files/doc.pdf` matching at the wrong position).

### Legacy Private Path Detection

Some Drupal sites serve private files under the public files path (e.g., `/sites/default/files/private/doc.pdf`). Step 1 checks for this pattern before the general public pattern (step 2) to avoid incorrectly mapping to `public://private/doc.pdf`.

### HTML Entity Decoding

Discovery methods decode HTML entities before regex matching to handle `&amp;` in query strings and `&#039;` in paths. This is applied once at the entry point, not at each pattern match.

### URL Encoding

`rawurldecode()` is used for path segments (not `urldecode()`) to correctly mirror Drupal's use of `rawurlencode()` in file URLs. The difference matters for `+` characters in filenames.

## Testing

For multisite testing procedures, see [Test Cases - TC-SCAN-MULTI](../../testing/test-cases.md#tc-scan-multi-multisite-file-path-resolution).

### Key Verification Points

1. **Discovery**: Content containing `/sites/anysite/files/doc.pdf` is detected regardless of the current site's `file_public_path` setting
2. **Construction**: URLs generated by the scanner reflect the current site's configuration
3. **Conversion**: URLs from any Drupal installation format are correctly converted to `public://` or `private://` stream URIs
4. **Backward compatibility**: Data stored with old `/sites/default/files/` paths still matches via the universal `sites/[^/]+/files` discovery pattern
5. **No hardcoded paths**: `grep -r "sites/default/files" src/` returns only comments and docblock examples
