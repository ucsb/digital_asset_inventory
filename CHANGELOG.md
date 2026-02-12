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
| 1.27.0 | Feb 2026 | Unit test suite: 299 tests across 4 classes (FilePathResolverTest, DigitalAssetScannerTest, ArchiveServiceTest, CsvExportFilenameSubscriberTest) covering pure-logic methods with mocked services |
| 1.28.0 | Feb 2026 | Kernel test suite: 45 tests across 4 classes (ArchiveWorkflowKernelTest, ArchiveIntegrityKernelTest, ScannerAtomicSwapKernelTest, ConfigFlagsKernelTest) with SQLite integration, shared base class, and opt-in debug dump infrastructure |
| 1.29.0 | Feb 2026 | CSV export improvements: Dynamic filenames with site name slug and date for both inventory and archive audit CSV exports. New "Active Use Detected" column (Yes/No). "Not used" wording changed to "No active use detected". CSV export buttons renamed. Archive management page header wording improvements. Deprecated `ArchiveFileLinkFilter` unset from text formats via update hook. CSS flexbox wrap for archive header buttons. New unit tests: `CsvExportFilenameSubscriberTest` (19 tests). New kernel tests for `active_use_csv` field (2 tests). |
| 1.30.0 | Feb 2026 | Orphan reference detection (Phase 1): New `dai_orphan_reference` entity, tri-state usage filter, orphan reference detail tab, `source_bundle` field, batch prefetch orphan counts, atomic swap extended for orphan refs |
| 1.30.1 | Feb 2026 | Orphan references view fixes: `title_enable` bug, Item Type column, Item Category label rename |
| 1.30.2 | Feb 2026 | Inventory column and display refinements: "Active Usage" column rename, orphan references URL path rename, CSS refinements |
| 1.30.3 | Feb 2026 | Archive link routing and atomic swap bug fixes: (1) Fixed multiple links to the same archived media entity on a page — only the first link was rewritten, subsequent links were skipped. Refactored `processMediaEntityUrls()` to deduplicate by UUID and `processMediaLink()` to use `preg_match_all` so all links are found and rewritten. (2) Fixed duplicate `aria-label` attributes on rewritten links when CKEditor produces bare `aria-label` (no value). `setAriaLabelOnTag()` and `setAriaLabel()` now strip bare attributes before adding valued ones. (3) Fixed `promoteTemporaryItems()` SQLite compatibility: `condition('is_temp', FALSE)` does not match `is_temp=0` in SQLite — changed to integer `0`. |
| 1.30.4 | Feb 2026 | Views argument plugin deprecation fix: Updated `plugin_id: numeric` to `plugin_id: entity_target_id` in `views.view.digital_asset_usage` and `views.view.dai_orphan_references` contextual filters. The `numeric` argument plugin was deprecated in Drupal 10.3 for entity reference fields (see [drupal.org/node/3441945](https://www.drupal.org/node/3441945)) and will be removed in Drupal 12. Update hook 10055 syncs existing sites. |
| 1.30.5 | Feb 2026 | Orphan reference detection improvements: (1) Restructured `processHtml5MediaEmbed` to resolve assets before paragraph check — all 8 `getParentFromParagraph()` call sites now create `dai_orphan_reference` records (previously site #4 was skipped). (2) Fixed stale reference counting: deleted paragraphs referenced in `file_usage` no longer increment the orphan paragraph count. (3) Added untracked orphan diagnostics: scan summary now reports paragraph IDs when orphans cannot be tracked. (4) Updated orphan references view explanatory text. Update hook 10056. |
