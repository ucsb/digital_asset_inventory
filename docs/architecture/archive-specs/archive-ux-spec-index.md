# Archive UX Specification Index

**Purpose:**  
This document is the authoritative index for all UX specifications related to the **Digital Asset Archive**.

It defines:

- The scope of the archive UX
- How individual specs relate to each other
- Which spec governs which route and UI surface
- The intended mental model for users and developers

All archive-related UX implementations MUST follow the specifications referenced here.

---

## 1. Archive UX Scope

The Archive UX covers **public-facing and internal user interfaces** that present archived materials retained for:

- Reference
- Research
- Recordkeeping

Archived materials:

- Are not active site content
- Are not used to meet current information or service needs
- May not meet current accessibility standards
- Must be remediated or replaced upon request

The archive represents **intentional governance**, not content cleanup.

---

## 2. UX Surfaces Covered

The Archive UX is composed of two primary public-facing surfaces:

1. **Archive Registry (Index of Records)**
2. **Archive Detail Page (Individual Record)**

Each surface has a dedicated, spec-driven document.

---

## 3. Specification Map

### 3.1 Archive Registry – Public Page

**Route:**
`/archive-registry`

**Purpose:**  
Provide a transparent, governed list of archived materials without promoting use, download, or reuse.

**Governing Spec:**
[archive-registry-public-page-spec.md](archive-registry-public-page-spec.md)

**Key Characteristics:**

- Registry of records, not a content library
- Fixed explanatory context at top of page
- Tabular listing only
- No downloads, previews, or copy-link actions
- Links only to archive detail pages

---

### 3.2 Archive Detail Page – Archived Asset

**Route Pattern:**
`/archive-registry/{archive-id}`

**Purpose:**  
Present a single archived record with full context, controlled access, and explicit accessibility support.

**Governing Spec:**
[archive-registry-detail-page-spec.md](archive-registry-detail-page-spec.md)

**Key Characteristics:**

- Asset identity preserved
- Archived status unmistakable and redundant
- Download permitted but visually de-emphasized
- Accessibility request explicitly supported
- Audit-ready public metadata
- Controlled “Copy archive record link” action (record reference only, no file URLs)

---

## 4. Relationship Between Registry and Detail Pages

The two UX surfaces work together as follows:

- The **Archive Registry** answers:
  - *What exists in the archive?*
  - *Why does this archive exist?*

- The **Archive Detail Page** answers:
  - *What is this specific item?*
  - *Why is it archived?*
  - *How can it be referenced or accessed responsibly?*

**Rules:**

- Registry pages MUST NOT link directly to files
- Registry pages MUST NOT expose copy-link actions
- Detail pages are the ONLY location where downloads may occur
- Detail pages are the ONLY location where an archive record link may be copied
- All access paths MUST preserve archive context and compliance language

---

## 5. Shared UX Principles (Apply to All Specs)

All archive UX must adhere to the following principles:

1. **Identity is preserved**  
   Archiving changes status, not the name.

2. **Status is unmistakable**  
   “Archived” must be visible, explicit, and redundant.

3. **Context precedes access**  
   Users must understand what archived means before interacting.

4. **Accessibility support is explicit**  
   Accessible alternatives are provided upon request.

5. **Archives describe, they do not promote**

---

## 6. Accessibility & Compliance Alignment

All Archive UX specs align to:

- ADA Title II (April 2026)
- Plain-language compliance communication
- Good-faith accessibility support
- Auditability without public overexposure of technical detail

Accessibility language is:

- Explicit
- Service-oriented
- Non-apologetic
- Non-legalistic

---

## 7. Prohibited Cross-Cutting Patterns

Across all Archive UX surfaces, the following are prohibited:

- File previews or thumbnails
- Inline document viewers
- Direct file URLs outside approved download actions
- Popularity or usage indicators
- Engagement-driven sorting
- Marketing language
- Breadcrumbs implying active content hierarchy
- Multiple or competing calls to action

---

## 8. Spec Enforcement Rules

- Each UX surface MUST be implemented according to its governing spec
- Specs MUST NOT be merged, paraphrased, or partially applied
- If specs appear to conflict, stop and escalate — do not improvise
- If functionality is not described in a governing spec, it MUST NOT be implemented

---

## 9. How to Use This Index

When implementing archive-related UX:

1. Start with this index
2. Identify the route or surface being built
3. Open the governing spec listed above
4. Implement exactly as written
5. Validate against the acceptance criteria in that spec

---

## 10. Canonical Spec List

The following files are authoritative:

- [archive-ux-spec-index.md](archive-ux-spec-index.md) - This index
- [archive-registry-public-page-spec.md](archive-registry-public-page-spec.md) - Public registry page
- [archive-registry-detail-page-spec.md](archive-registry-detail-page-spec.md) - Detail page
- [archive-invariants.md](archive-invariants.md) - Core invariants
- [archive-audit-safeguards-spec.md](archive-audit-safeguards-spec.md) - Audit requirements
- [archive-feature-toggle-spec.md](archive-feature-toggle-spec.md) - Archive enable/disable feature
- [dual-purpose-archive-spec.md](dual-purpose-archive-spec.md) - Legacy vs General archive types
- [archive-in-use-spec.md](archive-in-use-spec.md) - Archiving documents/videos while in use
- [archived-link-label-config-spec.md](archived-link-label-config-spec.md) - Configurable archived link label and external URL routing

No other document supersedes these without explicit revision.

---

## 11. Implementation Status

All specs have been implemented:

- Public Archive Registry at `/archive-registry` (Views-based)
- Archive Detail Page at `/archive-registry/{id}` (Controller + Twig template)
- 5-status lifecycle with immutable classification date (queued, archived_public, archived_admin, archived_deleted, exemption_void)
- Terminal states (archived_deleted, exemption_void) with no transitions out
- Dual-purpose archives: Legacy Archive (pre-deadline) vs General Archive (post-deadline)
- Archive Type badges in public registry and admin views
- Archive audit CSV export
- Warning flags for integrity, usage, missing files, late archive, modified, and prior exemption voided
- Manual archive entries for pages and external URLs
- Archive feature toggle (enable/disable via settings)
- Private file handling with login prompts for anonymous users
- Exemption void status for Legacy Archives modified after archiving
- Voided exemption re-archive policy: files/URLs with exemption_void are forced to General Archive
- Archive-in-use support: documents and videos can be archived while in use (when enabled via settings)
- Configurable archived link label: administrators can enable/disable and customize the "(Archived)" text suffix
- External URL routing: archived external URLs (manual entries) are routed to Archive Detail Page

---

## 12. Future Enhancements

No pending enhancements at this time. Previously deferred items have been implemented:

- ~~Configurable "(Archived)" Link Text Suffix~~ - Implemented in v1.22.0. See [archived-link-label-config-spec.md](archived-link-label-config-spec.md).

---

End of Archive UX Specification Index
