# Spec-Driven Development (SDD)
## Archived Digital Asset – Detail Page

**Audience:** Developers
**Instruction Level:** STRICT  
**Compliance Target:** ADA Title II (April 2026)

---

## 1. Objective

Implement an **Archived Digital Asset – Detail Page** that:

- Clearly distinguishes archived content from active content
- Preserves asset identity while emphasizing archived status
- Discourages casual reuse
- Remains transparent, respectful, and service-oriented
- Demonstrates intentional, auditable compliance

This page represents **governed archival**, not cleanup or avoidance.

---

## 2. Non-Goals (Out of Scope)

The implementation MUST NOT:

- Introduce previews, thumbnails, or inline viewers
- Modify or paraphrase approved wording
- Replace the asset name with a generic title
- Add new CTAs, navigation paths, or UX patterns
- Optimize for engagement, SEO, or downloads
- Introduce legal analysis or policy explanations

---

## 3. UX Principles (Hard Requirements)

1. Identity is preserved  
2. Status is unmistakable  
3. Context precedes access  
4. Accessibility support is explicit  
5. Archives describe, they do not promote  

---

## 4. Page Title Rules

### 4.1 Page Title (H1)

MUST be the original asset name.

Example:  
Resources.docx

MUST NOT be:  
Archived Document

---

### 4.2 Browser / Document Title

MUST follow this format:

Resources.docx – Archived Document

---

## 5. Required Page Structure (Top → Bottom)

The following order is mandatory.

---

### 5.1 Archive Status Banner (Critical)

Exact text:

Archived Document  
This document is retained for reference and recordkeeping purposes only.

Rules:
- First visible element on page
- Neutral color (gray or muted blue)
- Archive or document-lock icon
- Not red
- Not dismissible

---

### 5.2 Document Identity Section

Title (H1):  
Resources.docx

Status Badge (adjacent to title):  
Archived

Summary Row:  
Archived document · Word file · 150.83 KB

Rules:
- “Archived” is status, not identity
- No download action here
- File type must be human-readable

---

### 5.3 Archive Metadata Panel (Public)

Required fields:

| Label | Value |
|------|------|
| Archive Purpose | Reference |
| Date Archived | December 26, 2025 |
| Archive Status | Archived (Unmodified) |

Optional (admin-only or collapsible):
- Archive UUID
- SHA-256 checksum
- Visibility scope (Public / Admin-only)

Rules:
- No legal jargon
- No accessibility disclaimers
- Keep public view minimal

---

### 5.4 Download Action (De-Emphasized)

Button label (exact):

Download Archived Document

Rules:
- Secondary button styling
- No preview
- No inline viewer
- Single download action only

---

### 5.5 Accessibility Request Callout (Required)

Heading (exact):

Need an accessible version?

Body text (conditional by archive type):

**Legacy Archive:**
This archived document is not required to meet current accessibility standards.
If you need an accessible version, please contact us and we will provide it promptly.

**General Archive:**
If you need an accessible version, please contact us and we will provide it promptly.

Rules:
- Appears after download
- Always visible
- Contact method must be trackable
- No apologies or legal framing
- Legacy Archives include exemption statement; General Archives do not

---

### 5.6 Compliance Context Statement

Primary text (conditional by archive type):

**Legacy Archive:**
This document is retained in the archive for reference, research, or recordkeeping purposes only.
It was created before [deadline] and has not been modified since being archived.

**General Archive:**
This document is retained in the archive for reference, research, or recordkeeping purposes only.
It is no longer actively maintained.

Optional (collapsed, Legacy Archive only):

Archived documents are not used to meet current information or service needs and are not required to conform to WCAG 2.1 AA unless an accessible version is requested.

---

### 5.7 Navigation Exit

Exact text:

← Back to Archive Registry

Rules:
- No breadcrumbs
- No links to active content
- Single exit path only

---

### 5.8 Copy Archive Record Link (Controlled Reference Linking)

#### Purpose

Provide a controlled way for users to reference an archived item **without linking directly to the file**.

This feature exists to:
- Preserve archive context and compliance language
- Prevent deep-linking to legacy or inaccessible files
- Support citation, recordkeeping, and internal references
- Reinforce the archive as a registry of records, not a content library

---

#### Availability Rules

- MUST appear **only** on the Archive Detail Page
- MUST NOT appear on the Archive Registry page
- MUST be visually secondary to all other actions
- MUST NOT replace or override the download action

---

#### Action Label (Required Intent)

Allowed labels (choose one):
- Copy archive record link
- Copy link to archive record

Not allowed:
- Copy link
- Share
- Permalink
- Copy document link

---

#### Behavior (Hard Requirements)

- Copies the **archive detail page URL** (e.g. `/archive-registry/{archive-id}`)
- MUST NOT copy:
  - File URLs
  - Download URLs
  - Media entity URLs
- Optional confirmation message (exact text):

Archive record link copied

- No modal dialogs
- No social sharing UI
- No URL shortening

---

#### Helper Text (Strongly Recommended)

Exact text (must not be paraphrased):

Use this link to reference the archived record. Do not link directly to the file.

---

#### Accessibility Requirements

- Action must be keyboard accessible
- Accessible name MUST include “archive record”
- Icon-only buttons are not allowed
- Confirmation message must be announced to screen readers

---

#### Prohibited Behavior

This feature MUST NOT:
- Encourage reuse of archived content
- Be styled as a primary call to action
- Appear alongside file URLs
- Appear on the registry page
- Generate or expose alternative URLs

---

## 6. Accessibility Requirements

- Asset title must be a semantic heading
- Status badge included in accessible name
- No reliance on color alone
- Keyboard-accessible actions
- Linear reading order
- Visible focus states

---

## 7. Status Redundancy Rule (Critical)

“Archived” MUST appear in ALL of the following:

1. Status banner  
2. Title-adjacent badge  
3. Metadata panel  
4. Browser title  

Failure to meet all four is non-compliant.

---

## 8. Prohibited UI Elements

The following MUST NOT appear:

- File previews or thumbnails
- Direct file URLs outside the download button
- Related content links
- Popularity, usage, or analytics indicators
- Multiple CTAs
- Editable fields
- Breadcrumbs into active content

---

## 9. Acceptance Criteria

Implementation is complete ONLY IF:

- Page title equals asset name
- Browser title includes “– Archived Document”
- Status banner is first visible element
- Download is visually secondary
- Accessibility request text matches exactly
- Metadata fields are present and labeled correctly
- Exit navigation returns to registry
- Copy archive record link copies the archive page URL only
- No additional UI elements exist

---

## 10. Final Constraint

DO NOT improve this spec.  
DO NOT paraphrase wording.  
DO NOT introduce new UX ideas.

Implement exactly as written.

---

## 11. Implementation Status

Current implementation (`templates/archive-detail.html.twig`) includes:

- File metadata table (name, type, size, purpose, date)
- Archive Type badge (Legacy Archive or General Archive)
- Download link
- Copy archive record link with confirmation
- Conditional compliance context statement:
  - Legacy Archive: Full ADA notice with deadline reference
  - General Archive: Simplified notice without deadline reference
- Private file login prompt for anonymous users

Simplified from full spec - meets core accessibility and compliance requirements.

---
End of Specification
