# Digital Asset Inventory - Feature Roadmap

This document outlines shipped features and planned future enhancements for the Digital Asset Inventory module. Features are organized by version.

---

## Version 1.0 - Core Platform (Released)

### Scanner Engine

- **7-phase scanning** via Drupal Batch API with time-budgeted processing
  - Phase 1: Managed files (`file_managed`)
  - Phase 2: Orphan usage index (pre-built text-field scan)
  - Phase 3: Orphan files (filesystem discovery)
  - Phase 4: External URLs (Google Docs, YouTube, Vimeo, Box, etc.)
  - Phase 5: Remote media (oEmbed video entities)
  - Phase 6: Menu links (file references in navigation)
  - Phase 7: CSV export field population (deferred label resolution)

- **Performance optimizations**
  - Raw SQL bulk writes for usage and orphan reference records
  - Bulk entity reads via `loadMultiple()` with preloading
  - Paragraph parent cache per callback
  - Field table list caching per PHP process
  - Memory management with entity cache resets and `drupal_static_reset()`

- **Scan resilience**
  - Phase-level checkpointing via Drupal State API
  - Resume from last completed phase after interruption
  - Finalize path when all phases complete but promotion was interrupted
  - 3-tier stale lock detection (session heartbeat → legacy heartbeat → checkpoint timestamp → orphan lock)
  - Cron suspension during scanning with automatic restore

### Exclusion System

- **Built-in exclusions** for system-generated directories (not editable):
  - Drupal core: `styles/`, `media-icons/`, `oembed_thumbnails/`, `css/`, `js/`, `php/`, `config_*`
  - Contrib: `thumbnails/`, `video_thumbnails/`, `ctools/`, `xmlsitemap/`
  - Site-specific: `wordmark/`
- **Configurable exclusions** via Settings → Scanner Settings
  - Admin textarea for additional directory names (one per line)
  - Applied to both `public://` and `private://` file systems
  - Validation: rejects nested paths and single-character names
  - Warning when excluded directories contain archived assets

### Archive System

- **ADA compliance classification**
  - Legacy Archives: pre-deadline, ADA Title II exempt
  - General Archives: post-deadline, no exemption claimed
  - Configurable compliance deadline
  - Automatic exemption voiding on file modification
- **Archive integrity monitoring**
  - Checksum-based file integrity verification after every scan
  - Flag detection: missing file, integrity violation, usage changes
  - Automatic status reconciliation (exemption void, modified removal)
- **Archive link routing** for in-use archived documents
- **Manual archive entries** for web pages and external resources
- **Archive notes** with audit trail

### Settings UI

- **Archive Settings** (open by default)
  - Enable/disable archive, manual archive, archive-in-use
  - Link Display Settings (collapsed): archived label toggle and custom text
  - Classification Settings (collapsed): ADA compliance deadline with note
- **Scanner Settings** (open by default)
  - Time budget per request (1–30 seconds, default 10)
  - Abandoned scan timeout (120–7200 seconds, default 900)
  - Built-in excluded directories (collapsed, categorized, read-only)
  - Additional excluded directories (editable textarea)

### Accessibility Detection

- **Alt text evaluation** via `AltTextEvaluator` service
  - Per-usage alt text status: detected, not detected, decorative, not evaluated, not applicable
  - Source tracking: content override, inline image, media field, template-managed
  - Alt text preview (truncated to 120 chars) in Views
  - Views field (`UsageAltTextField`) and asset info header integration
- **Audio/video accessibility signals** via `buildAccessibilitySignals()`
  - Controls attribute, autoplay, muted, loop detection
  - Subtitle/caption track detection (VTT, SRT)

### Dashboard

- Asset count by category (Documents, Images, Videos, Audio, Google Workspace, etc.)
- Usage breakdown (in use vs. unused) with chart
- Top assets by usage count
- Location breakdown by source type (managed, orphan, external, remote media)
- Storage usage by category
- Archive status, type, and reason breakdowns (when archive is enabled)
- Interactive charts via `drupalSettings` / JavaScript

### Data Export

- CSV export with denormalized fields (filesize formatted, active use, used-in labels)
- Configurable export filename via event subscriber

### Testing

- 299 unit tests across 4 test classes (45 methods, 372 assertions)
- Kernel tests for atomic swap and entity CRUD operations

### Maintenance

- Pre-release update hooks (10001–10068) consolidated and removed — `hook_install()` and `config/install/` represent the final v1.0 state
- New update hooks start from `10069` for post-release changes

---

## Version 2.0 - Automation

### Drush Commands

Command-line interface for asset management operations:

- **`drush dai:scan`** — Run asset scan from CLI
  - Full scan (all 7 phases) or resume from checkpoint
  - Verbose output mode with per-phase progress
  - Exit codes for CI/CD integration (0 = success, 1 = error, 2 = already running)
  - Must implement the same cron suspension and lock management as the Batch API path
  - Designed to be called from any external scheduler (system crontab, Acquia/Pantheon scheduled tasks, Ultimate Cron, CI/CD pipelines). No built-in `hook_cron()` — scheduling is the site's responsibility.

- **`drush dai:status`** — Show scan and archive statistics
  - Total assets by source type (managed, orphan, external, remote media)
  - Used vs. unused asset counts
  - Archive counts by status (queued, archived, voided, deleted)
  - Last scan timestamp and duration
  - Checkpoint state (if interrupted scan exists)
  - Useful for monitoring scheduled scan health from scripts

- **`drush dai:archive-validate`** — Validate all archived files
  - Runs `validateArchivedFiles()` standalone (already runs automatically post-scan)
  - Report: missing files, integrity failures, exemption voids
  - Exit code reflects validation result for scripted health checks

---

## Version 2.1 - Reporting & Analytics

### Accessibility Compliance Reporting

Build on the existing alt text and video accessibility detection:

- **Bulk Remediation Report**
  - Filterable list of images with missing or decorative alt text
  - Prioritized by usage count (most-referenced assets first)
  - Export to CSV for remediation workflows

- **Document Accessibility Summary**
  - PDF count with tagged/untagged status (requires PDF parsing library)
  - Documents linked from high-traffic pages prioritized
  - Archive eligibility suggestions for inaccessible legacy documents

- **Remote Video Caption Status**
  - YouTube auto-captions vs. manual caption detection
  - Vimeo caption availability check

---

## Version 2.2 - Integration & Advanced Features

### Bulk Operations

Efficient management of multiple assets:

- **Bulk Archive from Inventory**
  - Multi-select assets for archiving with shared reason/justification
  - Progress indicator for large batches
  - Validation: skip already-archived or ineligible assets

- **Bulk Unused Asset Cleanup**
  - Filter to unused assets, select for archive or deletion
  - Safety confirmation with asset list preview
  - Configurable grace period before permanent deletion

- **Views Bulk Operations (VBO) Integration**
  - Custom VBO action plugins for archive, delete, re-scan
  - Permission-based action availability
  - Works with existing inventory Views configurations

### Archive Review Reminders

Scheduled review of archived items:

- **Review Date Assignment**
  - Optional review date when archiving (default: 1 year from archive date)
  - Editable review dates on existing archives
  - Configurable default review period in settings

- **Review Dashboard Widget**
  - Upcoming reviews (next 30 days)
  - Overdue reviews highlighted
  - Quick links to archive detail pages

- **Email Reminders**
  - Configurable reminder lead time (e.g., 2 weeks before review date)
  - Weekly digest of upcoming and overdue reviews
  - Integrates with v2.0 email notification system

---

## Future Considerations

Items not yet scoped for a specific version:

- **Incremental scanning** — Detect changed files since last scan using filesystem timestamps or database change tracking, avoiding full re-scan for minor content updates.
- **Configurable exclusion by file extension** — Allow admins to exclude specific file types from scanning (e.g., `.log`, `.tmp`).

---

## Changelog

| Version | Focus Area | Status |
|---------|------------|--------|
| 1.0 | Core platform: scanning, archival, accessibility detection, dashboard, CSV export | Released |
| 2.0 | Drush CLI | Planned |
| 2.1 | Accessibility compliance reporting | Planned |
| 2.2 | Bulk operations, archive review reminders | Planned |
