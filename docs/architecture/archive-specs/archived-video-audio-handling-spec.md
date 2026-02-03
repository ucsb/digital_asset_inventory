# Archived Video/Audio Handling Specification

## Overview

This specification defines how archived video and audio files are handled when embedded via HTML5 `<video>` and `<audio>` elements. It addresses display on Archive Detail Pages, placeholder replacement for archived embeds, and relationships between video files and their caption/subtitle files.

**Future Direction**: The long-term goal is to use Drupal Media entities for video/audio management, with enhanced support for captions, transcripts, and other accessibility elements. This spec is designed to be forward-compatible with that migration.

---

## Problem Statement

When a video or audio file is archived while still embedded via HTML5 tags:

1. The `ArchiveLinkResponseSubscriber` rewrites `<source src>` URLs to point to the Archive Detail Page
2. The browser's video/audio player expects a media file, not an HTML page
3. **Result**: Broken player with no useful feedback to users

Additionally:
- Caption files (VTT/SRT) are often paired with specific videos
- Archiving a video without its captions (or vice versa) creates an incomplete archive
- Users need context about related files

---

## Goals

1. **User Experience**: When video/audio source is archived, show a clear link to archive instead of a broken player
2. **Accessibility**: Replacement link must be accessible and keyboard navigable
3. **Context**: Archive Detail Page shows relationships between videos and their caption files
4. **Forward Compatibility**: Design for future Media entity integration
5. **Audit Trail**: Maintain complete archive records for ADA compliance

---

## Scope

### In Scope

- HTML5 `<video>` and `<audio>` element handling when source is archived
- Archive Detail Page enhancements for video/audio/caption files
- Relationship tracking between videos and caption files
- Placeholder replacement in rendered content

### Out of Scope (Future Work)

- Media entity enhancements for captions/transcripts
- Automatic caption file detection from Media fields
- Video thumbnail generation for archived content
- Transcript extraction or generation

---

## Design

### 1. Placeholder Replacement for Archived Embeds

When an HTML5 `<video>` or `<audio>` element's source file is archived, replace the entire element with an accessible placeholder.

#### Detection Logic

In `ArchiveLinkResponseSubscriber`, after processing individual URLs:

1. Find all `<video>` and `<audio>` elements in the HTML
2. For each element, check if ANY `<source src>` points to an archived file
3. If archived, replace the entire element with a placeholder

#### Placeholder HTML Structure

Replace the `<video>` or `<audio>` element with a simple inline link:

```html
<a href="/archive-registry/{uuid}">{filename} (Archived)</a>
```

This is consistent with how other archived content (documents, pages) is displayed throughout the site.

#### Accessibility Requirements

- Link must be keyboard accessible
- Sufficient color contrast (WCAG AAA)
- "(Archived)" suffix clearly indicates status to all users

### 2. Archive Detail Page Enhancements

#### For Video Files (mp4, webm, mov, avi)

Display:

- Standard archive metadata (name, type, reason, description, date)
- Original URL and download link
- **Associated Caption Files** section (if any detected)

```text
Associated Caption Files
------------------------
- big-buck-bunny.vtt (English) - [View Archive] or [Active]
- big-buck-bunny-es.vtt (Spanish) - [View Archive] or [Active]
```

#### For Caption Files (vtt, srt)

Display:

- Standard archive metadata
- Original URL and download link
- **Associated Video Files** section (if any detected)

```text
Associated Video Files
----------------------
- big-buck-bunny.mp4 - [View Archive] or [Active]
```

#### Relationship Detection

Relationships are determined by:

1. **Same-page usage**: Files used on the same entity (node) via HTML5 embeds
2. **Track element pairing**: `<track>` elements within `<video>` tags link captions to videos
3. **Naming convention** (optional heuristic): Files with matching base names (e.g., `video.mp4` and `video.vtt`)

Store relationships in `accessibility_signals` JSON field on usage records:

```json
{
  "controls": "detected",
  "captions": "detected",
  "related_caption_fids": [123, 456],
  "related_video_fid": 789
}
```

### 3. Archive Workflow Considerations

#### When Archiving a Video File

1. Check for associated caption files (from `<track>` elements in usage records)
2. Show informational message: "This video has associated caption files that may also need to be archived"
3. List the caption files with their archive status
4. Do NOT automatically archive captions (user decides)

#### When Archiving a Caption File

1. Check for associated video files
2. Show informational message: "This caption file is used with the following videos"
3. List the video files with their archive status
4. Warn if video is still active: "The associated video is still active. Archiving this caption will remove captions from the video."

#### Archive-in-Use Behavior

When `allow_archive_in_use` is enabled:

- Video/audio files can be archived while HTML5 embeds exist
- The placeholder replacement handles the display
- Caption files can be archived independently

When `allow_archive_in_use` is disabled:

- Files with HTML5 embed usage cannot be archived
- Must remove `<video>`/`<audio>` embeds first

---

## Database Changes

### Usage Record Enhancement

The `accessibility_signals` JSON field already exists. Extend its schema to include relationships:

```json
{
  "controls": "detected|not_detected",
  "autoplay": "detected|not_detected",
  "muted": "detected|not_detected",
  "loop": "detected|not_detected",
  "captions": "detected|not_detected|unknown",
  "related_files": {
    "caption_fids": [123, 456],
    "video_fid": 789
  }
}
```

No new database fields required.

---

## Implementation Phases

### Phase 1: Placeholder Replacement (Priority: High)

1. Enhance `ArchiveLinkResponseSubscriber` to detect `<video>`/`<audio>` elements
2. Check if source files are archived
3. Replace entire element with inline link to Archive Detail Page

### Phase 2: Related Files Display (Priority: Medium)

1. Store related file IDs in `accessibility_signals` during HTML5 scanning
2. Update Archive Detail Page template to show related files section
3. Query related files and their archive status

### Phase 3: Archive Form Enhancements (Priority: Low)

1. Show related files when archiving video/caption files
2. Add informational messages about relationships
3. Warning when archiving caption of active video

---

## Future Considerations: Media Entity Integration

When migrating to Media entities for video management:

### Media Type Enhancements

1. **Caption Field**: Add a field to Video media type for caption files
2. **Transcript Field**: Add a text field for transcript content
3. **Accessibility Metadata**: Track audio description, sign language, etc.

### Archive Integration

1. Media entity archive should include associated files (video + captions)
2. Archive Detail Page shows complete media package
3. Placeholder replacement uses Media embed detection (`<drupal-media>`)

### Migration Path

1. Existing HTML5 embeds continue to work with current implementation
2. New content uses Media entities with enhanced accessibility fields
3. Gradual migration of existing content to Media entities
4. Both systems coexist during transition

---

## Test Cases

### Inline Link Replacement

1. **TC1**: Archive a video file used in `<video>` tag → inline link appears
2. **TC2**: Archive an audio file used in `<audio>` tag → inline link appears
3. **TC3**: Archive a video with multiple sources → inline link appears when primary source archived
4. **TC4**: Unarchive a video → player reappears (link removed)
5. **TC5**: Page with mix of archived and active videos → only archived ones show inline link

### Related Files

1. **TC6**: Archive Detail Page for video shows associated VTT files
2. **TC7**: Archive Detail Page for VTT shows associated video files
3. **TC8**: Related file is also archived → shows "View Archive" link
4. **TC9**: Related file is active → shows "Active" status

### Archive Form

1. **TC10**: Archive video with caption files → shows informational message
2. **TC11**: Archive caption of active video → shows warning
3. **TC12**: Archive video when `allow_archive_in_use` disabled + HTML5 usage → blocked

### Accessibility

1. **TC13**: Link is keyboard navigable
2. **TC14**: Screen reader announces link text with "(Archived)" suffix
3. **TC15**: Link has sufficient color contrast

---

## Design Decisions

The following design decisions were finalized based on requirements analysis:

| Decision Area | Choice | Rationale |
|---------------|--------|-----------|
| **Replacement content** | Simple inline link | Consistent with other archived content; no box/container needed |
| **Replacement behavior** | Replace entire `<video>`/`<audio>` element | Prevents broken player UI; provides clear indication of archived status |
| **Caption co-archiving** | Independent archiving (no automatic) | Users may want to archive video without captions or vice versa; maintains flexibility |
| **Related files archiving** | Show informational messages only | Display relationships but don't force co-archiving; user decides what to archive |
| **Multiple caption languages** | List all, treat independently | Each caption file is a separate asset; users archive individually as needed |
| **Link accessibility** | Keyboard-accessible with "(Archived)" suffix | Ensures WCAG compliance; status clear to all users |

### Decision Details

#### D1: Simple Inline Link Replacement

- Archived video/audio elements are replaced with a simple inline link
- Video `poster` attribute is ignored when generating replacement
- Rationale: Consistent with how other archived content (documents, pages) is displayed; simple and accessible

#### D2: Replace Entire Element

- When ANY `<source>` in a `<video>`/`<audio>` element points to an archived file, the entire element is replaced
- This includes all `<source>` elements, `<track>` elements, and any fallback content
- Rationale: Partial replacement would leave a broken player; complete replacement provides clear user feedback

#### D3: No Automatic Caption Co-Archiving

- Archiving a video does NOT automatically archive associated caption files
- Archiving a caption file does NOT automatically archive the associated video
- Rationale: Different accessibility requirements may apply; users need control over individual file archiving

#### D4: Informational Messages for Related Files

- When archiving a video, show a message listing associated caption files
- When archiving a caption, show a message listing associated videos
- Include archive status of each related file
- Rationale: Users should be aware of relationships but not forced into specific archiving actions

#### D5: Independent Multi-Language Caption Handling

- All caption files (regardless of language) are listed as related to a video
- Each can be archived independently
- Archive Detail Page shows all related captions with their status
- Rationale: Different language versions may have different lifecycle requirements

---

## Caption File Archiving Policy

> **Policy Statement**: Caption files are treated as independent accessibility artifacts. Archiving a caption file does not imply archiving the associated video, but may affect accessibility of active content.

### Recommendation

Allow caption files (VTT/SRT) to be archived independently of video files, with clear warnings and relationship visibility.

### Rationale

Allowing independent archiving is the most defensible and flexible approach for both accessibility and records governance:

- Caption files are standalone digital assets (files with their own URLs, usage, and lifecycle)
- Caption files may be:
  - Replaced with newer versions
  - Language-specific variants
  - Shared across multiple videos
- Automatic coupling creates risk:
  - Archiving a caption could silently remove accessibility from an active video
  - Preventing archiving blocks legitimate cleanup and record-retention workflows
- Independence preserves:
  - Explicit user intent
  - Clear audit trails
  - Reuse across content

### Required Guardrails

If captions are archived independently, the following must apply:

#### 1. Relationship Awareness

When archiving a caption file:

- Detect associated video files
- Display them with current status (Active / Archived)
- Clearly state the impact

**Example warning:**
> This caption file is used by one or more active videos.
> Archiving this file will remove captions from those videos.

#### 2. No Silent Accessibility Regression

Do not allow silent accessibility regression:

- Do not automatically archive videos when captions are archived
- Do not suppress warnings for active videos
- Require explicit confirmation when captions are archived while videos remain active

This keeps the system aligned with WCAG non-interference principles.

#### 3. Placeholder / UX Behavior

If a caption file is archived while the video remains active:

- The video player continues to render
- Captions are no longer available
- No placeholder replacement is triggered (since the video itself is not archived)

This behavior is consistent, predictable, and reversible.

### Final Decision Summary

| Area | Decision | Rationale |
| ---- | -------- | --------- |
| Caption file archiving | Allow caption files to be archived independently | Captions are standalone assets that may be reused, replaced, or language-specific |
| Accessibility safeguards | Warn when archiving captions used by active videos | Prevents silent accessibility regression and supports informed decision-making |
| Coupled archiving | Do not automatically archive related videos | Maintains explicit intent and avoids unintended content changes |
| UX behavior | Do not replace video players when only captions are archived | Placeholder replacement applies only when the media file itself is archived |
| Audit posture | Require explicit user action for each archived file | Preserves a clear and defensible audit trail |

---

## References

- `docs/architecture/archive-specs/archive-in-use-spec.md` - Archive-in-use behavior
- `docs/architecture/inventory-specs/html5-video-audio-scanning-spec.md` - HTML5 scanning implementation
- `src/EventSubscriber/ArchiveLinkResponseSubscriber.php` - Link rewriting logic
- `src/Controller/ArchiveDetailController.php` - Archive Detail Page

---

## Changelog

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | Feb 2026 | Initial specification |
| 1.1 | Feb 2026 | Finalized design decisions: text-only placeholders, entire element replacement, independent caption archiving, informational messages for related files |
| 1.2 | Feb 2026 | Added Caption File Archiving Policy section with guardrails (relationship awareness, no silent accessibility regression, placeholder behavior) and formal policy statement |
| 1.3 | Feb 2026 | Simplified placeholder to inline link (consistent with other archived content); removed box/container styling and CSS |
