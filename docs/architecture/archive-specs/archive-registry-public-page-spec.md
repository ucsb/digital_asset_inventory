# Spec-Driven Development (SDD)
## Archive Registry – Public Page (/archive-registry)

**Audience:** Developers
**Instruction Level:** STRICT
**Compliance Target:** ADA Title II (April 2026)
**Route:** /archive-registry
**Visibility:** Public

---

## 1. Objective

Implement the **public Archive Registry page** that:

- Presents a transparent, governed list of archived materials
- Clearly distinguishes archived content from active site content
- Communicates that archived materials are retained only for reference, research, or recordkeeping
- Provides a clear, consistent pathway to request accessible versions
- Avoids presenting the archive as an active content library

This page is a **registry of records**, not a document repository.

---

## 2. Non-Goals (Out of Scope)

The implementation MUST NOT:

- Present archived materials as current or authoritative
- Include previews, thumbnails, or inline viewers
- Promote downloads or usage
- Include popularity, sorting by engagement, or usage indicators
- Introduce legal analysis beyond approved copy
- Replace registry context with marketing or editorial language

---

## 3. UX Principles (Hard Requirements)

1. **Status before content**  
   Users must understand what “archived” means before viewing the list.

2. **Registry, not library**  
   The page indexes records; it does not promote consumption.

3. **Plain-language compliance**  
   Legal clarity without legal jargon.

4. **Accessibility support is explicit**  
   Requests for accessible alternatives are clearly supported.

---

## 4. Page Title and Identity

### 4.1 Page Title (H1)

MUST be:

Archive Registry

This title is fixed and not dynamic.

---

### 4.2 Browser / Document Title

MUST be:

Archive Registry – Archived Materials

---

## 5. Required Page Structure (Top → Bottom)

The following order is mandatory.

---

### 5.1 Page Introduction (Critical Context)

This text MUST appear at the top of the page, before any list or filters.

**Approved copy (current implementation):**

This page lists archived materials retained solely for reference, research, or recordkeeping purposes. These materials are not used to meet current information or service needs and are no longer actively maintained.

Legacy Archive items may not meet current accessibility standards under ADA Title II provisions for archived content. General Archive items are retained for reference purposes without claiming accessibility exemption.

Archived materials may be stored in their original format and are not modified after being archived. Some archived materials may link to external resources that are not controlled by this website. Certain archived materials may require login if they were originally stored in private or restricted-access locations.

Archived materials are provided for historical reference only and may not reflect current policies or practices.

For an accessible or alternative format of any archived material listed below, please contact the accessibility resources on this website. Requests will be fulfilled within a reasonable timeframe in accordance with applicable accessibility standards.

**Rules**
- Text must not be collapsed or hidden
- Must appear before the registry listing
- Legacy Archive and General Archive terminology must be explained

---

### 5.2 Registry Section Heading

**Heading (required):**

List of archived materials

---

### 5.3 Archive Registry Listing (Tabular)

The registry MUST be displayed as a table.

#### Required Columns (in order)

| Column | Description |
|------|-------------|
| Name | Asset name (links to archive detail page, not the file) |
| Type | Human-readable type (Document, Web Content, URL, etc.) |
| Archive Type | Legacy Archive or General Archive (badge) |
| Purpose | Archive purpose (Reference, Recordkeeping, Research) |
| Archive Classification Date | Date the item was archived |

#### Column Rules

- "Name" links ONLY to the archive detail page
- No direct file URLs in the table
- Dates must be human-readable
- No icons-only columns
- No hidden metadata
- Archive Type badges: Legacy Archive (blue), General Archive (gray)

---

## 6. Prohibited Registry Features

The following MUST NOT appear:

- Download buttons
- Inline previews
- Thumbnails
- Sorting by popularity or usage
- “Recently viewed” or “Recommended” items
- Filters that imply active use (e.g., “Most used”)
- Pagination labels that imply consumption (e.g., “Browse”)

Pagination, if present, must be neutral and functional.

---

## 7. Accessibility Requirements

- Table headers must be semantic (`<th>`)
- Table must be readable in linear order
- Links must have meaningful accessible names
- No reliance on color alone
- Keyboard navigation supported
- Introductory text must be reachable before the table

---

## 8. Relationship to Archive Detail Pages

- Each registry row links to a dedicated Archive Detail Page
- The detail page is the only location where downloads may occur
- Registry page must never expose direct access to files

---

## 9. Acceptance Criteria

The implementation is correct ONLY IF:

- Page title is “Archive Registry”
- Introductory compliance text matches exactly
- Registry is displayed as a table
- Table columns appear in the specified order
- Name links point to archive detail pages
- No downloads or previews exist on this page
- Accessibility request language is visible above the list
- No additional UX elements are introduced

---

## 10. Final Constraint

DO NOT improve this spec.  
DO NOT paraphrase approved text.  
DO NOT add UX features not explicitly defined.

This page is intentionally conservative and compliance-driven.

---

## 11. Implementation Status

Current implementation (`views.view.public_archive.yml`) includes:

- Page title "Archive Registry"
- Introductory compliance text with Legacy/General Archive explanation
- Table listing with Name, Type, Archive Type, Purpose, Date columns
- Archive Type badges (Legacy Archive = blue, General Archive = gray)
- Name links to archive detail pages
- Filters for archived_public status and file exists (flag_missing = FALSE)
- No download buttons or previews on registry page

Fully implemented per spec requirements.

---

## 12. CSS Implementation

All CSS classes use `dai-` prefix to avoid namespace collisions with other modules or themes.

| Component | CSS Class |
|-----------|-----------|
| Page wrapper | `.dai--public` |
| Intro box | `.dai-archive-registry-intro` |
| Empty state | `.dai-archive-empty` |
| Type badge (Legacy) | `.dai-archive-type-badge--legacy` |
| Type badge (General) | `.dai-archive-type-badge--general` |

CSS variables from `dai-base.css` are used for theme overridability.

---
End of Specification
