# Changelog

| Version | Date | Changes |
| ------- | ---- | ------- |
| 1.0.0 | Dec 2025 | Initial release with full feature set |
| 1.0.1 | Dec 2025 | Added compressed file support (zip, tar, gz, 7z, rar) |
| 1.1.0 | Dec 2025 | Added ADA Title II archive system with public Archive Registry |
| 1.2.0 | Dec 2025 | Archive audit safeguards: immutable classification date, visibility toggle, file deletion with record preservation, CSV audit export |
| 1.3.0 | Jan 2026 | Private file support: detection of private files, File Storage/File Access filters, login prompts for anonymous users |
| 1.4.0 | Jan 2026 | Exemption void status: automatic detection when Legacy Archive content is modified after archiving |
| 1.5.0 | Jan 2026 | Archive feature toggle, Drupal 11 compatibility, manual archive entries for pages/URLs, admin menu icon |
| 1.6.0 | Jan 2026 | Source type label updates, usage tracking for external assets and manual uploads, category filter fixes |
| 1.7.0 | Jan 2026 | Archived content banner, edit protection with acknowledgment checkbox, automatic exemption voiding |
| 1.8.0 | Jan 2026 | Dual-purpose archive: Legacy Archives (pre-deadline, ADA exempt) vs General Archives (post-deadline) |
| 1.9.0 | Jan 2026 | Simplified archive lifecycle: removed requeue functionality, unarchiving sets `archived_deleted` status |
| 1.10.0 | Jan 2026 | WCAG accessibility improvements, visibility defaults to Public |
| 1.11.0 | Jan 2026 | Theme-agnostic admin UI with CSS variables for theming |
| 1.12.0 | Jan 2026 | Internal notes system: append-only notes log, dedicated notes page, `archived_by` records executor |
| 1.13.0 | Jan 2026 | Taxonomy term archiving, page URL autocomplete for manual archive form |
| 1.14.0 | Jan 2026 | Permission simplification: `view digital asset archives` for read-only auditor access |
| 1.15.0 | Jan 2026 | Usage page Media-aware enhancements: thumbnail, alt text status, Media actions |
| 1.16.0 | Jan 2026 | Remote video media scanning (YouTube, Vimeo via Media Library) |
| 1.17.0 | Jan 2026 | Archive-in-use support: archive documents/videos while still referenced in content |
| 1.18.0 | Jan 2026 | Menu link file scanning: detect file references in menu links |
| 1.19.0 | Jan 2026 | Archive link routing: automatic redirection to Archive Detail Pages |
| 1.20.0 | Jan 2026 | Admin-only visibility controls disclosure, conditional display for anonymous users |
| 1.21.0 | Feb 2026 | Universal archive link rewriting via Response Subscriber, Twig extension for templates |
| 1.22.0 | Feb 2026 | Configurable archived link label, external URL routing with normalized matching, archive badge for external assets |
| 1.23.0 | Feb 2026 | Archived page banner contextual notes for external resources: detects archived external URLs and displays appropriate status notes |
| 1.24.0 | Feb 2026 | Terminal state visibility on Archive Detail Page, Archive Management view improvements |
| 1.25.0 | Feb 2026 | HTML5 video/audio scanning: detects `<video>` and `<audio>` tags, tracks embed method and accessibility signals |
| 1.25.1 | Feb 2026 | HTML5 scanning bug fixes: track element parsing, text link detection, VTT/SRT caption support |
| 1.25.2 | Feb 2026 | Embed method tracking fixes: drupal-media embeds, menu links, Embed Type column prioritization, Media Library widget compatibility |
| 1.25.3 | Feb 2026 | Deprecated text format filter: `ArchiveFileLinkFilter` no longer needed, Response Subscriber handles all link routing |
| 1.25.4 | Feb 2026 | Inline image, legacy embed scanning, and comprehensive embed method fixes |
| 1.26.0 | Feb 2026 | Multisite file path resolution via `FilePathResolver` trait |
| 1.27.0 | Feb 2026 | Unit test suite: 280 tests across 3 classes (FilePathResolverTest, DigitalAssetScannerTest, ArchiveServiceTest) covering pure-logic methods with mocked services |
| 1.28.0 | Feb 2026 | Kernel test suite: 43 tests across 4 classes (ArchiveWorkflowKernelTest, ArchiveIntegrityKernelTest, ScannerAtomicSwapKernelTest, ConfigFlagsKernelTest) with SQLite integration, shared base class, and opt-in debug dump infrastructure |
