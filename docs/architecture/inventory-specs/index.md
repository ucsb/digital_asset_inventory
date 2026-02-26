# Digital Asset Inventory Specifications - Index

This directory contains technical specifications for the Digital Asset Inventory module's scanning and inventory functionality.

## Overview

The Digital Asset Inventory module discovers, catalogs, and tracks usage of digital assets across a Drupal site. The core scanner service processes five distinct phases to inventory all assets: managed files, orphan files, external URLs, remote media, and menu links.

The scanner targets all primary content entities where files, media, or links may be presented to the public (nodes, paragraphs, taxonomy terms, custom blocks). System configuration, logs, and administrative metadata are intentionally excluded to ensure accurate, actionable compliance reporting.

## Specification Documents

| Document | Description |
| -------- | ----------- |
| [Scanner Workflow](scanner-workflow-spec.md) | Five-phase scanning process, batch processing, atomic swap pattern |
| [Asset Types & Categories](asset-types-categories-spec.md) | Asset type detection, category mapping, URL pattern matching |
| [Usage Detection](usage-detection-spec.md) | How asset usage is tracked across content |
| [Data Integrity](data-integrity-spec.md) | Atomic swap pattern, `is_temp` flag, scan failure recovery |
| [File Path Resolution](file-path-resolution-spec.md) | Multisite-safe file path resolution via `FilePathResolver` trait |
| [Orphan Reference Detection — Phase 1](orphan-reference-detection-phase1-spec.md) | Orphan paragraph detection, tri-state usage classification, `dai_orphan_reference` entity |
| [Orphan Reference Detection — Phase 2 (Outline)](orphan-reference-detection-phase2-spec.md) | Block & media orphan detection, safe paragraph cleanup (not implementation-ready) |
| [Derived Media Thumbnail Usage Detection](derived-media-thumbnail-usage-detection-spec.md) | Relationship-driven detection of Media thumbnail files as derived dependencies |
| [Scan Resilience](scan-resilience-spec.md) | Phase-level checkpointing, concurrency protection, memory management, checkpoint integrity |
| [Revision-Aware Delete Guard](revision-aware-delete-guard-spec.md) | Paragraph revision ghost classification in required field deletion checks |
| [Field-Type Scanning](field-type-scanning-spec.md) | Dynamic entity discovery based on field storage types (future enhancement) |

## Quick Reference

### Scan Phases

| Phase | Method | Source | What It Finds |
| ----- | ------ | ------ | ------------- |
| 1 | `scanManagedFilesChunk` | file_managed table | Drupal-managed files |
| 2 | `scanOrphanFilesChunk` | Filesystem | Untracked files (FTP uploads) |
| 3 | `scanContentChunk` | Text/link fields | External URLs in content |
| 4 | `scanRemoteMediaChunk` | Media entities | Remote videos (YouTube, Vimeo) |
| 5 | `scanMenuLinksChunk` | menu_link_content | File references in menus |

### Location Types

| Location Type | Label | Description |
| ------------- | ----- | ----------- |
| `file_managed` | Upload | Standard Drupal file uploads |
| `media_managed` | Media | Media Library uploads (including remote video) |
| `filesystem_only` | Server | FTP/SFTP uploads outside Drupal |
| `external` | External | URLs to external resources |

### Entity Schema

```text
digital_asset_item
├── id (primary key)
├── uuid
├── fid (file_managed reference, nullable)
├── media_id (media entity reference, nullable)
├── source_type (file_managed|media_managed|filesystem_only|external)
├── url_hash (MD5 hash for uniqueness)
├── file_name
├── file_path (absolute URL)
├── asset_type (pdf, word, youtube, etc.)
├── category (Documents, Videos, Embedded Media, etc.)
├── sort_order (display ordering)
├── mime_type
├── filesize (bytes, NULL for remote)
├── filesize_formatted (human-readable, e.g., "2.5 MB")
├── used_in_csv (pre-computed usage string)
├── is_temp (batch processing flag)
├── is_private (private file system flag)
└── timestamps (created, changed)

digital_asset_usage
├── id (primary key)
├── uuid
├── asset_id (references digital_asset_item.id)
├── entity_type (node, paragraph, block_content, etc.)
├── entity_id
├── field_name
└── count

dai_orphan_reference
├── id (primary key)
├── uuid
├── asset_id (references digital_asset_item.id)
├── source_entity_type (paragraph, etc.)
├── source_entity_id
├── source_revision_id (nullable, future use)
├── source_bundle (paragraph type, e.g., 'text', 'accordion_item')
├── field_name
├── embed_method
├── reference_context (missing_parent_entity|detached_component)
└── detected_on (auto-populated timestamp)
```

## Related Documentation

- Module documentation - Main developer guide with critical rules
- [Archive Specs](../archive-specs/) - Archive system specifications
- [UI Specs](../ui-specs/) - User interface specifications
- [Test Cases](../../testing/test-cases.md) - Test cases including scan tests

## Key Invariants

1. **Atomic swap pattern**: New items created with `is_temp=TRUE`, promoted only on successful scan completion
2. **Foreign key order**: Always delete usage records before asset records
3. **Archive preservation**: Archive records (`digital_asset_archive`) are never deleted during scans
4. **Scan failure recovery**: If scan fails, previous inventory is preserved intact
5. **Menu link scanning**: Menu links (`menu_link_content`) are scanned for file references in Phase 5
6. **Scan resilience**: Phase-level checkpointing enables interrupted scans to resume from the last completed phase; concurrency lock prevents parallel scans
7. **Multisite-safe paths**: All file path discovery uses universal `sites/[^/]+/files` patterns; all URL construction uses dynamic `FileUrlGeneratorInterface`
8. **Orphan references**: Orphan paragraph references never create `digital_asset_usage` rows; they are tracked separately in `dai_orphan_reference`
9. **Deletion order**: `dai_orphan_reference` → `digital_asset_usage` → `digital_asset_item` (logical FK integrity)
