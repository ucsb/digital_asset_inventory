# Documentation Index

This directory contains technical documentation for the Digital Asset Inventory module.

---

## Directory Structure

```text
docs/
├── README.md                              # This file - documentation map
├── architecture/                          # Technical specifications and system design
│   └── archive-specs/                     # Archive system specifications
│       ├── archival-workflow.md           # Complete workflow with status diagrams
│       ├── archive-audit-safeguards-spec.md
│       ├── archive-feature-toggle-spec.md
│       ├── archive-invariants.md          # Critical constraints - read first
│       ├── archive-registry-detail-page-spec.md
│       ├── archive-registry-public-page-spec.md
│       ├── archive-ux-spec-index.md       # UX specs index - start here for UI
│       └── dual-purpose-archive-spec.md
├── guidance/                              # Quick references for developers and admins
│   └── quick-reference-guide.md
└── testing/                               # Test documentation
    └── test-cases.md
```

---

## Quick Navigation

| I need to... | Go to |
|--------------|-------|
| Understand the archive workflow | [archival-workflow.md](architecture/archive-specs/archival-workflow.md) |
| Find UX specifications | [archive-ux-spec-index.md](architecture/archive-specs/archive-ux-spec-index.md) |
| Learn critical constraints | [archive-invariants.md](architecture/archive-specs/archive-invariants.md) |
| Get a quick reference for features | [quick-reference-guide.md](guidance/quick-reference-guide.md) |
| Find test cases | [test-cases.md](testing/test-cases.md) |

---

## Directory Contents

### architecture/

Technical specifications for developers building or maintaining the module.

#### architecture/archive-specs/

Archive system specifications and workflow documentation.

| File | Purpose |
|------|---------|
| [archival-workflow.md](architecture/archive-specs/archival-workflow.md) | Complete archive workflow with status diagrams, transition matrices, and violation scenarios |
| [archive-ux-spec-index.md](architecture/archive-specs/archive-ux-spec-index.md) | Index of all UX specifications - start here for UI implementation |
| [archive-invariants.md](architecture/archive-specs/archive-invariants.md) | Critical constraints that must never be violated |
| [archive-audit-safeguards-spec.md](architecture/archive-specs/archive-audit-safeguards-spec.md) | Audit trail and compliance requirements |
| [archive-feature-toggle-spec.md](architecture/archive-specs/archive-feature-toggle-spec.md) | Archive enable/disable feature specification |
| [archive-registry-public-page-spec.md](architecture/archive-specs/archive-registry-public-page-spec.md) | Public registry page UX specification |
| [archive-registry-detail-page-spec.md](architecture/archive-specs/archive-registry-detail-page-spec.md) | Archive detail page UX specification |
| [dual-purpose-archive-spec.md](architecture/archive-specs/dual-purpose-archive-spec.md) | Legacy vs General archive type specification |

**Reading order for new developers:**

1. [archive-invariants.md](architecture/archive-specs/archive-invariants.md) - Understand what you cannot break
2. [archival-workflow.md](architecture/archive-specs/archival-workflow.md) - Understand the complete system
3. [archive-ux-spec-index.md](architecture/archive-specs/archive-ux-spec-index.md) - Understand the UI structure
4. Individual specs as needed

---

### guidance/

Quick references for developers and site administrators.

| File | Purpose |
|------|---------|
| [quick-reference-guide.md](guidance/quick-reference-guide.md) | Comprehensive guide covering scanning, filtering, archiving, permissions, routes, and troubleshooting |

---

### testing/

Test documentation and test cases.

| File | Purpose |
|------|---------|
| [test-cases.md](testing/test-cases.md) | Manual test cases for module functionality |

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
