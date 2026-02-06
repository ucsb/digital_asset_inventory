# Asset Types and Categories Specification

## Overview

The Digital Asset Inventory classifies assets by **type** (specific format) and **category** (grouping for display and filtering). This specification documents how assets are classified and the configuration that controls this mapping.

## Classification Hierarchy

```text
Category (display grouping)
└── Asset Type (specific format)
    └── MIME Type / URL Pattern (detection method)
```

Example:
```text
Documents
├── pdf
│   └── application/pdf
├── word
│   ├── application/msword
│   └── application/vnd.openxmlformats-officedocument.wordprocessingml.document
└── excel
    ├── application/vnd.ms-excel
    └── application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
```

## Categories and Sort Order

Categories control how assets are grouped in the inventory view.

| Sort Order | Category | Description |
| ---------- | -------- | ----------- |
| 1 | Documents | PDFs, Word, Excel, PowerPoint, text files |
| 2 | Videos | Local video files (MP4, WebM, MOV, AVI) |
| 3 | Audio | Audio files (MP3, WAV, M4A, OGG) |
| 4 | Google Workspace | Google Docs, Sheets, Slides, Drive, Forms, Sites |
| 5 | Document Services | DocuSign, Box, Dropbox, OneDrive, SharePoint, Adobe Acrobat |
| 6 | Forms & Surveys | Qualtrics, Microsoft Forms, SurveyMonkey, Typeform |
| 7 | Education Platforms | Canvas, Panopto, Kaltura |
| 8 | Embedded Media | YouTube, Vimeo, Zoom recordings, SlideShare, Prezi, Issuu, Canva |
| 9 | Images | JPEG, PNG, GIF, SVG, WebP |
| 10 | Other | Compressed files, unrecognized formats |
| 99 | Unknown | Fallback for unmapped types |

## Asset Types by Category

### Documents

| Asset Type | Label | Extensions | MIME Types |
| ---------- | ----- | ---------- | ---------- |
| pdf | PDFs | .pdf | application/pdf |
| word | Word Documents | .doc, .docx | application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document |
| excel | Excel Spreadsheets | .xls, .xlsx | application/vnd.ms-excel, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet |
| powerpoint | PowerPoint Presentations | .ppt, .pptx | application/vnd.ms-powerpoint, application/vnd.openxmlformats-officedocument.presentationml.presentation |
| text | Text Files | .txt | text/plain |
| csv | CSV Files | .csv | text/csv, application/csv |

### Videos

| Asset Type | Label | Extensions | MIME Types |
| ---------- | ----- | ---------- | ---------- |
| mp4 | MP4 Videos | .mp4 | video/mp4 |
| webm | WebM Videos | .webm | video/webm |
| mov | QuickTime Videos | .mov | video/quicktime |
| avi | AVI Videos | .avi | video/x-msvideo |

### Audio

| Asset Type | Label | Extensions | MIME Types |
| ---------- | ----- | ---------- | ---------- |
| mp3 | MP3 Audio | .mp3 | audio/mpeg |
| wav | WAV Audio | .wav | audio/wav, audio/x-wav |
| m4a | M4A Audio | .m4a | audio/mp4, audio/x-m4a |
| ogg | OGG Audio | .ogg | audio/ogg |

### Images

| Asset Type | Label | Extensions | MIME Types |
| ---------- | ----- | ---------- | ---------- |
| jpg | JPEG Images | .jpg, .jpeg | image/jpeg |
| png | PNG Images | .png | image/png |
| gif | GIF Images | .gif | image/gif |
| svg | SVG Images | .svg | image/svg+xml |
| webp | WebP Images | .webp | image/webp |

### Other (Local Files)

| Asset Type | Label | Extensions | MIME Types |
| ---------- | ----- | ---------- | ---------- |
| compressed | Compressed Files | .zip, .tar, .gz, .7z, .rar | application/zip, application/x-tar, application/gzip, application/x-7z-compressed, application/x-rar-compressed |

### Google Workspace (External)

| Asset Type | Label | URL Patterns |
| ---------- | ----- | ------------ |
| google_doc | Google Docs | docs.google.com/document |
| google_sheet | Google Sheets | docs.google.com/spreadsheets |
| google_slide | Google Slides | docs.google.com/presentation |
| google_drive | Google Drive | drive.google.com |
| google_form | Google Forms | docs.google.com/forms |
| google_site | Google Sites | sites.google.com |

### Document Services (External)

| Asset Type | Label | URL Patterns |
| ---------- | ----- | ------------ |
| docusign | DocuSign Documents | docusign.com |
| box_link | Box Files | box.com |
| dropbox | Dropbox Links | dropbox.com |
| onedrive | OneDrive Files | onedrive.live.com, 1drv.ms |
| sharepoint | SharePoint | sharepoint.com |
| adobe_acrobat | Adobe Acrobat | acrobat.adobe.com |

### Forms & Surveys (External)

| Asset Type | Label | URL Patterns |
| ---------- | ----- | ------------ |
| qualtrics | Qualtrics Forms | qualtrics.com |
| microsoft_forms | Microsoft Forms | forms.office.com, forms.microsoft.com |
| surveymonkey | SurveyMonkey | surveymonkey.com |
| typeform | Typeform | typeform.com |

### Education Platforms (External)

| Asset Type | Label | URL Patterns |
| ---------- | ----- | ------------ |
| canvas | Canvas | instructure.com |
| panopto | Panopto | panopto.com |
| kaltura | Kaltura | kaltura.com, mediaspace.* |

### Embedded Media (External)

| Asset Type | Label | URL Patterns |
| ---------- | ----- | ------------ |
| youtube | YouTube | youtube.com, youtu.be |
| vimeo | Vimeo | vimeo.com, player.vimeo.com |
| zoom_recording | Zoom Recordings | zoom.us/rec/, zoom.us/recording/ |
| slideshare | SlideShare | slideshare.net |
| prezi | Prezi Presentations | prezi.com |
| issuu | Issuu Publications | issuu.com |
| canva | Canva Designs | canva.com |

## Type Detection Methods

### Method 1: MIME Type Mapping (Local Files)

Used for files in `file_managed` table.

```php
protected function mapMimeToAssetType($mime_type) {
  // Whitelist mapping - only recognized types get specific types
  $mime_map = [
    'application/pdf' => 'pdf',
    'application/msword' => 'word',
    // ... etc
  ];
  return $mime_map[$mime_type] ?? 'other';
}
```

**Whitelist approach**: Unrecognized MIME types map to 'other', ensuring only known types are specifically categorized.

### Method 2: Extension to MIME (Orphan Files)

Used for filesystem files not in `file_managed`.

```php
protected function extensionToMime($extension) {
  $ext_map = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    // ... etc
  ];
  return $ext_map[$extension] ?? 'application/octet-stream';
}
```

### Method 3: URL Pattern Matching (External URLs)

Used for external resources found in content.

```php
protected function matchUrlToAssetType($url) {
  foreach ($asset_types as $type => $settings) {
    if (isset($settings['url_patterns'])) {
      foreach ($settings['url_patterns'] as $pattern) {
        if (strpos($url, $pattern) !== FALSE) {
          return $type;
        }
      }
    }
  }
  return 'other';
}
```

**Pattern matching**: Case-insensitive substring matching. First matching pattern wins.

### Method 4: Remote Video Detection

Used for Media Library remote videos.

```php
// Detect from source URL
if (strpos($source_url, 'youtube.com') !== FALSE ||
    strpos($source_url, 'youtu.be') !== FALSE) {
  $asset_type = 'youtube';
}
elseif (strpos($source_url, 'vimeo.com') !== FALSE) {
  $asset_type = 'vimeo';
}
```

## Configuration

Asset types are configured in `digital_asset_inventory.settings.yml`:

```yaml
asset_types:
  pdf:
    label: 'PDFs'
    category: 'Documents'
    extensions:
      - pdf
    mimes:
      - 'application/pdf'

  google_doc:
    label: 'Google Docs'
    category: 'Google Workspace'
    extensions: []
    mimes: []
    url_patterns:
      - 'docs.google.com/document'

  # ... additional types
```

### Configuration Fields

| Field | Type | Description |
| ----- | ---- | ----------- |
| label | string | Human-readable display name |
| category | string | Category for grouping |
| extensions | array | File extensions (local files) |
| mimes | array | MIME types (local files) |
| url_patterns | array | URL substrings (external URLs) |

## Category Mapping

```php
protected function mapAssetTypeToCategory($asset_type) {
  $config = $this->configFactory->get('digital_asset_inventory.settings');
  $asset_types = $config->get('asset_types');

  if ($asset_types && isset($asset_types[$asset_type]['category'])) {
    return $asset_types[$asset_type]['category'];
  }

  return 'Unknown';
}
```

## Adding New Asset Types

### Adding a New Local File Type

1. Add MIME mapping in `mapMimeToAssetType()`
2. Add extension mapping in `extensionToMime()` (for orphan detection)
3. Add configuration in `digital_asset_inventory.settings.yml`:
   ```yaml
   new_type:
     label: 'New Type'
     category: 'Documents'  # or appropriate category
     extensions:
       - ext
     mimes:
       - 'application/new-type'
   ```

### Adding a New External Service

1. Add configuration in `digital_asset_inventory.settings.yml`:
   ```yaml
   new_service:
     label: 'New Service'
     category: 'Document Services'  # or appropriate category
     extensions: []
     mimes: []
     url_patterns:
       - 'newservice.com/'
   ```

2. No code changes required - URL pattern matching uses configuration

### Adding a New Category

1. Add to `getCategorySortOrder()`:
   ```php
   case 'New Category':
     return 11;  // Choose appropriate sort order
   ```

2. Update configuration for types in the new category

## Special Cases

### Unknown MIME Types

Files with unrecognized MIME types are classified as:
- Asset Type: `other`
- Category: `Unknown`
- Still inventoried and tracked

### Unmatched External URLs

URLs that don't match any configured pattern:
- Not inventoried (skipped)
- Prevents noise from generic external links
- Only known services are tracked

### Remote Video Fallback

Remote videos that don't match YouTube/Vimeo patterns:
- Asset Type: `youtube` (default)
- Manual verification may be needed

## File Size Display

| Asset Source | File Size Display |
| ------------ | ----------------- |
| Local files | Formatted (e.g., "2.5 MB") |
| External URLs | "-" (dash) |
| Remote video media | "-" (dash) |

## Related Files

- `config/install/digital_asset_inventory.settings.yml` - Configuration
- `src/Service/DigitalAssetScanner.php` - Type detection methods
- `src/Entity/DigitalAssetItem.php` - Entity definition
