# Documentation Index

This directory contains technical documentation for the Digital Asset Inventory module.

---

## Directory Structure

```text
docs/
├── README.md                              # This file - documentation map
├── architecture/                          # Technical specifications and system design
│   ├── archive-specs/                     # Archive system specifications
│   │   ├── archival-workflow.md           # Complete workflow with status diagrams
│   │   ├── archive-audit-safeguards-spec.md
│   │   ├── archive-feature-toggle-spec.md
│   │   ├── archive-in-use-spec.md         # Archive documents while in use
│   │   ├── archive-invariants.md          # Critical constraints - read first
│   │   ├── archive-registry-detail-page-spec.md
│   │   ├── archive-registry-public-page-spec.md
│   │   ├── archive-ux-spec-index.md       # UX specs index - start here for UI
│   │   ├── archived-link-label-config-spec.md  # Configurable label and external URL routing
│   │   ├── archived-page-document-status-spec.md
│   │   └── dual-purpose-archive-spec.md
│   ├── inventory-specs/                   # Scanner and inventory specifications
│   │   ├── asset-types-categories-spec.md # Asset type and category definitions
│   │   ├── data-integrity-spec.md         # Data integrity and atomic swap patterns
│   │   ├── field-type-scanning-spec.md    # Field type scanning configuration
│   │   ├── csv-export-improvements-spec.md     # CSV export enhancements
│   │   ├── file-path-resolution-spec.md        # Multisite file path resolution
│   │   ├── html5-video-audio-scanning-spec.md  # HTML5 video/audio tag scanning
│   │   ├── index.md                       # Inventory specs index
│   │   ├── scanner-workflow-spec.md       # Scanner phases and batch processing
│   │   └── usage-detection-spec.md        # Usage detection methods and embed tracking
│   └── ui-specs/                          # UI/CSS architecture specifications
│       ├── audio-video-accessibility-signals-spec.md  # A/V accessibility signals
│       ├── css-only-stacked-tables-spec.md    # Responsive tables (no Tablesaw)
│       ├── theme-agnostic-admin-ui-spec.md    # Admin CSS architecture
│       ├── theme-agnostic-public-ui-spec.md   # Public CSS architecture
│       └── usage-page-media-aware-spec.md     # Usage page media enhancements
├── guidance/                              # Quick references for developers and admins
│   └── quick-reference-guide.md
└── testing/                               # Test documentation
    ├── test-cases.md
    ├── status-transition-matrix.md
    ├── unit-testing-spec.md               # Unit test spec (280 tests, 3 classes)
    └── kernel-testing-spec.md             # Kernel test spec (43 tests, 4 classes)
```

---

## Quick Navigation

| I need to... | Go to |
|--------------|-------|
| Understand the archive workflow | [archival-workflow.md](architecture/archive-specs/archival-workflow.md) |
| Find UX specifications | [archive-ux-spec-index.md](architecture/archive-specs/archive-ux-spec-index.md) |
| Learn critical constraints | [archive-invariants.md](architecture/archive-specs/archive-invariants.md) |
| Understand how the scanner works | [scanner-workflow-spec.md](architecture/inventory-specs/scanner-workflow-spec.md) |
| Understand usage detection methods | [usage-detection-spec.md](architecture/inventory-specs/usage-detection-spec.md) |
| Understand HTML5 video/audio scanning | [html5-video-audio-scanning-spec.md](architecture/inventory-specs/html5-video-audio-scanning-spec.md) |
| Understand CSS architecture (admin) | [theme-agnostic-admin-ui-spec.md](architecture/ui-specs/theme-agnostic-admin-ui-spec.md) |
| Understand CSS architecture (public) | [theme-agnostic-public-ui-spec.md](architecture/ui-specs/theme-agnostic-public-ui-spec.md) |
| Understand responsive tables | [css-only-stacked-tables-spec.md](architecture/ui-specs/css-only-stacked-tables-spec.md) |
| Get a quick reference for features | [quick-reference-guide.md](guidance/quick-reference-guide.md) |
| Find test cases | [test-cases.md](testing/test-cases.md) |
| Understand unit test suite (280 tests) | [unit-testing-spec.md](testing/unit-testing-spec.md) |
| Understand kernel test suite (43 tests) | [kernel-testing-spec.md](testing/kernel-testing-spec.md) |
| Review status transition test matrix | [status-transition-matrix.md](testing/status-transition-matrix.md) |

---

## Directory Contents

### architecture/

Technical specifications for developers building or maintaining the module.

#### architecture/ui-specs/

UI and CSS architecture specifications.

| File | Purpose |
|------|---------|
| [theme-agnostic-admin-ui-spec.md](architecture/ui-specs/theme-agnostic-admin-ui-spec.md) | Admin CSS architecture - variables, badges, row indicators |
| [theme-agnostic-public-ui-spec.md](architecture/ui-specs/theme-agnostic-public-ui-spec.md) | Public CSS architecture - variables, namespaced classes, theme overrides |
| [css-only-stacked-tables-spec.md](architecture/ui-specs/css-only-stacked-tables-spec.md) | Responsive stacked tables using CSS-only approach (no Tablesaw) |
| [usage-page-media-aware-spec.md](architecture/ui-specs/usage-page-media-aware-spec.md) | Usage page media enhancements - thumbnail, alt text, Media actions |
| [audio-video-accessibility-signals-spec.md](architecture/ui-specs/audio-video-accessibility-signals-spec.md) | Audio/video accessibility signal tracking and display |

#### architecture/archive-specs/

Archive system specifications and workflow documentation.

| File | Purpose |
|------|---------|
| [archival-workflow.md](architecture/archive-specs/archival-workflow.md) | Complete archive workflow with status diagrams, transition matrices, and violation scenarios |
| [archive-ux-spec-index.md](architecture/archive-specs/archive-ux-spec-index.md) | Index of all UX specifications - start here for UI implementation |
| [archive-invariants.md](architecture/archive-specs/archive-invariants.md) | Critical constraints that must never be violated |
| [archive-audit-safeguards-spec.md](architecture/archive-specs/archive-audit-safeguards-spec.md) | Audit trail and compliance requirements |
| [archive-feature-toggle-spec.md](architecture/archive-specs/archive-feature-toggle-spec.md) | Archive enable/disable feature specification |
| [archive-in-use-spec.md](architecture/archive-specs/archive-in-use-spec.md) | Archiving documents/videos while still referenced in content |
| [archive-registry-public-page-spec.md](architecture/archive-specs/archive-registry-public-page-spec.md) | Public registry page UX specification |
| [archive-registry-detail-page-spec.md](architecture/archive-specs/archive-registry-detail-page-spec.md) | Archive detail page UX specification |
| [archived-link-label-config-spec.md](architecture/archive-specs/archived-link-label-config-spec.md) | Configurable archived link label and external URL routing |
| [archived-page-document-status-spec.md](architecture/archive-specs/archived-page-document-status-spec.md) | Contextual notes for linked document status on archived pages |
| [dual-purpose-archive-spec.md](architecture/archive-specs/dual-purpose-archive-spec.md) | Legacy vs General archive type specification |

**Reading order for new developers:**

1. [archive-invariants.md](architecture/archive-specs/archive-invariants.md) - Understand what you cannot break
2. [archival-workflow.md](architecture/archive-specs/archival-workflow.md) - Understand the complete system
3. [archive-ux-spec-index.md](architecture/archive-specs/archive-ux-spec-index.md) - Understand the UI structure
4. Individual specs as needed

#### architecture/inventory-specs/

Scanner and inventory specifications covering asset discovery, usage detection, and file path resolution.

| File | Purpose |
|------|---------|
| [index.md](architecture/inventory-specs/index.md) | Inventory specs index |
| [scanner-workflow-spec.md](architecture/inventory-specs/scanner-workflow-spec.md) | Five-phase scanner workflow, batch processing, and scan phases |
| [usage-detection-spec.md](architecture/inventory-specs/usage-detection-spec.md) | Usage detection methods, embed tracking, paragraph tracing |
| [html5-video-audio-scanning-spec.md](architecture/inventory-specs/html5-video-audio-scanning-spec.md) | HTML5 video/audio tag scanning and accessibility signal extraction |
| [asset-types-categories-spec.md](architecture/inventory-specs/asset-types-categories-spec.md) | Asset type and category definitions |
| [data-integrity-spec.md](architecture/inventory-specs/data-integrity-spec.md) | Data integrity and atomic swap patterns |
| [field-type-scanning-spec.md](architecture/inventory-specs/field-type-scanning-spec.md) | Field type scanning configuration |
| [file-path-resolution-spec.md](architecture/inventory-specs/file-path-resolution-spec.md) | Multisite-safe file path resolution via `FilePathResolver` trait |
| [csv-export-improvements-spec.md](architecture/inventory-specs/csv-export-improvements-spec.md) | CSV export enhancements and field formatting |

**File path resolution principle:**

> Discover and parse using universal path anchors; generate using site-aware services.

| Concern | Pattern | When to Use |
|---------|---------|-------------|
| Discovery | `/sites/[^/]+/files/` (public) and `/system/files/` (private) | Finding local file URLs in text/HTML/database fields |
| Construction | `getPublicFilesBasePath()` (dynamic) and `file_url_generator` | Building file URLs for the current site |
| Conversion | `/sites/[^/]+/files/` and `/system/files/` via `urlPathToStreamUri()` | Parsing URLs back into stream URIs |

See `src/FilePathResolver.php` trait for implementation details.

---

### guidance/

Quick references for developers and site administrators.

| File | Purpose |
|------|---------|
| [quick-reference-guide.md](guidance/quick-reference-guide.md) | Comprehensive guide covering scanning, filtering, archiving, permissions, routes, and troubleshooting |

---

### testing/

Test documentation, specifications, and test cases.

| File | Purpose |
|------|---------|
| [test-cases.md](testing/test-cases.md) | Manual test cases for module functionality |
| [status-transition-matrix.md](testing/status-transition-matrix.md) | Comprehensive status transition and test case matrix |
| [unit-testing-spec.md](testing/unit-testing-spec.md) | Unit test specification — 280 tests across 3 classes |
| [kernel-testing-spec.md](testing/kernel-testing-spec.md) | Kernel test specification — 43 tests across 4 classes |

---

## Documentation Conventions

### File Naming

- All files use **lowercase with hyphens**: `archive-workflow.md`
- Specification files use `-spec.md` suffix: `archive-feature-toggle-spec.md`
- No spaces or underscores in filenames

### Specification Documents

Specs follow a strict format:

1. **Audience** - Who this spec is for
2. **Instruction Level** - STRICT (follow exactly) or GUIDELINE (recommendations)
3. **Objective** - What the spec achieves
4. **Non-Goals** - What is explicitly out of scope
5. **Requirements** - Numbered, testable requirements
6. **Acceptance Criteria** - How to verify implementation
7. **Implementation Status** - Current state (optional)

### Status Values

When documenting statuses, use exact database values:

- `queued` - Awaiting archive execution
- `archived_public` - Active, visible to public
- `archived_admin` - Active, admin-only
- `archived_deleted` - Terminal state, record preserved
- `exemption_void` - Terminal state, ADA exemption voided

### Warning Flags

Use human-readable labels in user documentation:

| Machine Name | User Label |
|--------------|------------|
| `flag_integrity` | Integrity Issue |
| `flag_usage` | Usage Detected |
| `flag_missing` | File Missing |
| `flag_late_archive` | Late Archive |
| `flag_modified` | Modified |
| `flag_prior_void` | Prior Exemption Voided |

### Archive Types

- **Legacy Archive** - Created before ADA deadline, exemption eligible
- **General Archive** - Created after ADA deadline, no exemption

---

## Diagrams and Workflows

### Status Lifecycle Diagram

Located in: [archival-workflow.md](architecture/archive-specs/archival-workflow.md)

The main workflow diagram uses ASCII art for version control compatibility:

```text
┌────────┐  queue   ┌────────┐  execute   ┌─────────────────┐
│ (none) │ ───────> │ queued │ ─────────> │ archived_public │
└────────┘          └────────┘            └─────────────────┘
```

### Transition Matrices

Status transitions are documented in tables:

| From Status | Action | To Status | Notes |
|-------------|--------|-----------|-------|
| `queued` | Execute | `archived_public` | With visibility choice |

---

## Contributing Documentation

### Adding New Documentation

1. Choose the appropriate directory based on audience
2. Follow naming conventions (lowercase, hyphens)
3. Include appropriate frontmatter (audience, purpose)
4. Update this README if adding new files

### Updating Specifications

1. Specs are authoritative - changes require careful review
2. Update `Implementation Status` section when code changes
3. Do not paraphrase spec language - keep exact wording
4. If specs conflict, escalate rather than improvise

### Markdown Standards

- Use blank lines around lists (MD032)
- Use fenced code blocks with language specifiers (MD040)
- Wrap URLs in angle brackets for emails: `<email@example.com>`
- Tables should be readable in plain text

---

## Related Files

Outside the `docs/` directory:

| File | Purpose |
|------|---------|
| [README.md](../README.md) | Module overview and quick start |
| [ROADMAP.md](../ROADMAP.md) | Planned features for future versions |

---

## Questions?

- **Technical questions**: Start with [archive-invariants.md](architecture/archive-specs/archive-invariants.md)
- **Feature overview**: See [quick-reference-guide.md](guidance/quick-reference-guide.md)
