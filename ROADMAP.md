# Digital Asset Inventory - Feature Roadmap

This document outlines planned future enhancements for the Digital Asset Inventory module. Features are organized by version with a focus on automation and reporting capabilities.

## Version 2.0 - Automation

### Drush Commands

Command-line interface for asset management operations:

- **`drush dai:scan`** - Run asset scan from CLI
  - Options for full or incremental scan
  - Verbose output mode for debugging
  - Return exit codes for CI/CD integration

- **`drush dai:status`** - Show scan/archive statistics
  - Total assets scanned
  - Used vs. unused asset counts
  - Archive counts by status (pending, executed, cancelled)
  - Last scan timestamp

- **`drush dai:archive-validate`** - Validate all archived files
  - Verify checksums against stored values
  - Report missing or corrupted archive files
  - Output validation report

### Scheduled Scans

Automated scanning via Drupal's cron system:

- **Cron Hook Implementation**
  - `hook_cron()` integration for automatic scanning
  - Smart scheduling to avoid peak traffic times
  - Incremental scans for large file systems

- **Configurable Scan Frequency**
  - Daily scanning option
  - Weekly scanning option (with day selection)
  - Monthly scanning option (with date selection)
  - Manual-only option (disable automated scans)

- **Settings Form Updates**
  - New "Automation" tab in settings
  - Frequency selection dropdown
  - Next scheduled scan display
  - Last automated scan results summary

### Email Notifications

Keep stakeholders informed of inventory status:

- **Scan Completion Notifications**
  - Summary of assets found/updated
  - List of newly unused assets
  - Scan duration and performance metrics

- **Archive Integrity Alerts**
  - Immediate notification on checksum failures
  - List of affected archived files
  - Recommended remediation steps

- **Configurable Recipients**
  - Email address list in settings
  - Role-based notification option
  - Per-notification-type recipient configuration

---

## Version 2.1 - Reporting & Analytics

### Dashboard Page

Visual overview of asset inventory status:

- **Asset Count by Category** (Pie Chart)
  - Images, documents, videos, audio, other
  - Interactive legend with click-to-filter

- **Storage Usage by Type** (Bar Chart)
  - File size totals per category
  - Comparison of used vs. archived storage

- **Recent Scan History**
  - Last 10 scans with timestamps
  - Assets found/changed per scan
  - Quick-access to scan details

- **Archive Status Summary**
  - Pending archives awaiting execution
  - Recently executed archives
  - Cancelled archive count
  - Total storage reclaimed

### Broken Link Checker

Validate external URLs referenced in assets:

- **URL Validation During Scan**
  - HTTP HEAD requests to verify URLs
  - Configurable timeout thresholds
  - Respect robots.txt and rate limiting

- **Flag Broken/Unreachable Links**
  - Mark assets with broken external references
  - Track link status history
  - Differentiate between 404, timeout, and other failures

- **Link Status Column**
  - New column in inventory view
  - Status indicators (valid, broken, unchecked)
  - Filter assets by link status

### Duplicate Detection

Identify redundant files consuming storage:

- **Checksum-Based Detection**
  - Compare SHA-256 hashes across all assets
  - Group duplicate files together
  - Identify original vs. copies

- **Duplicate Storage Report**
  - Total wasted storage from duplicates
  - List of duplicate groups
  - Largest duplicate sets highlighted

- **Bulk Duplicate Management**
  - Select which copy to keep
  - Batch archive/delete duplicates
  - Update references to point to canonical file

---

## Version 2.2 - Advanced Features

### Bulk Operations

Efficient management of multiple assets:

- **Bulk Archive from Inventory**
  - Multi-select assets for archiving
  - Single reason/justification for batch
  - Progress indicator for large batches

- **Bulk Delete Unused Assets**
  - Safety confirmation with asset list
  - Option to archive instead of delete
  - Undo period before permanent deletion

- **Views Bulk Operations Integration**
  - Custom VBO actions for archive/delete
  - Integration with existing Views configurations
  - Permission-based action availability

### REST API

Programmatic access to inventory data:

- **GET Endpoints**
  - `GET /api/dai/inventory` - List all assets
  - `GET /api/dai/inventory/{id}` - Single asset details
  - `GET /api/dai/archive` - List archived items
  - `GET /api/dai/archive/{id}` - Archive entry details
  - `GET /api/dai/stats` - Summary statistics

- **POST Endpoints**
  - `POST /api/dai/scan` - Trigger new scan
  - `POST /api/dai/archive` - Create archive entry
  - JSON request/response format

- **Authentication**
  - OAuth 2.0 support
  - API key authentication option
  - Permission-based endpoint access

### Archive Review Reminders

Scheduled review of archived items:

- **Review Date Assignment**
  - Set review date when archiving
  - Default review period setting (e.g., 1 year)
  - Editable review dates on existing archives

- **Dashboard Widget**
  - Upcoming reviews (next 30 days)
  - Overdue reviews highlighted
  - Quick links to archive details

- **Email Reminders**
  - Configurable reminder lead time
  - Weekly digest of upcoming reviews
  - Direct links to review items

---

## Contributing

Feature requests and feedback are welcome. Please submit issues through the project's issue tracker with the "enhancement" label.

## Changelog

| Version | Focus Area | Status |
|---------|------------|--------|
| 1.0 | Core scanning and archival | Released |
| 2.0 | Automation | Planned |
| 2.1 | Reporting & Analytics | Planned |
| 2.2 | Advanced Features | Planned |
