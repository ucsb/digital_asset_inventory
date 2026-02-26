# Changelog

| Version | Date | Changes |
| ------- | ---- | ------- |
| 1.0.0 | Dec 2025 | Initial release with full feature set |
| 1.0.1 | Dec 2025 | Compressed file support: zip, tar, gz, 7z, rar |
| 1.1.0 | Dec 2025 | Archive system: ADA Title II archive with public Archive Registry |
| 1.2.0 | Dec 2025 | Archive audit safeguards: immutable classification date, visibility toggle, file deletion with record preservation, CSV audit export |
| 1.3.0 | Jan 2026 | Private file support: detection, File Storage/File Access filters, login prompts for anonymous users |
| 1.4.0 | Jan 2026 | Exemption void: automatic detection when Legacy Archive content is modified after archiving |
| 1.5.0 | Jan 2026 | Archive feature toggle: enable/disable setting, Drupal 11 compatibility, manual archive entries, admin menu icon |
| 1.6.0 | Jan 2026 | Location type labels: renamed source types, usage tracking for external assets and manual uploads, category filter fixes |
| 1.7.0 | Jan 2026 | Archived content banner: edit protection with acknowledgment checkbox, automatic exemption voiding |
| 1.8.0 | Jan 2026 | Dual-purpose archive: Legacy Archives (pre-deadline, ADA exempt) vs General Archives (post-deadline) |
| 1.9.0 | Jan 2026 | Archive lifecycle: removed requeue functionality, unarchiving sets `archived_deleted` status |
| 1.10.0 | Jan 2026 | WCAG accessibility: `role` attributes, line-height improvements, visibility defaults to Public |
| 1.11.0 | Jan 2026 | Theme-agnostic admin UI: CSS variables, surface-first patterns, row indicators |
| 1.12.0 | Jan 2026 | Internal notes: append-only notes log, dedicated notes page, `archived_by` records executor |
| 1.13.0 | Jan 2026 | Taxonomy term archiving: page URL autocomplete for manual archive form |
| 1.14.0 | Jan 2026 | Permission simplification: `view digital asset archives` for read-only auditor access |
| 1.15.0 | Jan 2026 | Usage page Media-aware: thumbnail, alt text status, Media actions |
| 1.16.0 | Jan 2026 | Remote video scanning: YouTube, Vimeo via Media Library |
| 1.17.0 | Jan 2026 | Archive-in-use: archive documents/videos while still referenced in content |
| 1.18.0 | Jan 2026 | Menu link scanning: detect file references in menu links |
| 1.19.0 | Jan 2026 | Archive link routing: automatic redirection to Archive Detail Pages |
| 1.20.0 | Jan 2026 | Admin-only visibility: controls disclosure, conditional display for anonymous users |
| 1.21.0 | Feb 2026 | Universal link rewriting: Response Subscriber for all archive links, Twig extension for templates |
| 1.22.0 | Feb 2026 | Archived link label: configurable label text, external URL routing with normalized matching |
| 1.23.0 | Feb 2026 | Archived page banner: contextual notes for linked document and external resource archive status |
| 1.24.0 | Feb 2026 | Terminal state visibility: Archive Detail Page status notices, Archive Management view improvements |
| 1.25.0 | Feb 2026 | HTML5 media scanning: `<video>` and `<audio>` tag detection, embed method tracking, accessibility signals |
| 1.25.1 | Feb 2026 | HTML5 scanning fixes: track element parsing, text link detection, VTT/SRT caption support |
| 1.25.2 | Feb 2026 | Embed method fixes: drupal-media embeds, menu links, Embed Type column, Media Library widget compatibility |
| 1.25.3 | Feb 2026 | Deprecated text filter: `ArchiveFileLinkFilter` replaced by Response Subscriber |
| 1.25.4 | Feb 2026 | Inline image scanning: `<img>`, `<object>`, `<embed>` tag detection, comprehensive embed method audit |
| 1.26.0 | Feb 2026 | Multisite file path resolution: `FilePathResolver` trait for universal discovery, dynamic construction, 5-step conversion |
| 1.27.0 | Feb 2026 | Unit tests: 299 tests across 4 classes covering pure-logic methods with mocked services |
| 1.28.0 | Feb 2026 | Kernel tests: 45 tests across 4 classes with SQLite integration and shared base class |
| 1.29.0 | Feb 2026 | CSV export: dynamic filenames with site slug and date, "Active Use Detected" column, renamed export buttons |
| 1.30.0 | Feb 2026 | Orphan reference detection: `dai_orphan_reference` entity, tri-state usage filter, orphan detail tab, atomic swap extended |
| 1.30.1 | Feb 2026 | Orphan references view: `title_enable` bug fix, Item Type column, Item Category label rename |
| 1.30.2 | Feb 2026 | Inventory display: "Active Usage" column rename, orphan references URL path rename, CSS refinements |
| 1.30.3 | Feb 2026 | Link routing fixes: multiple archived media links on same page, duplicate `aria-label`, SQLite atomic swap compatibility |
| 1.30.4 | Feb 2026 | Views deprecation fix: `numeric` → `entity_target_id` argument plugin for Drupal 12 compatibility |
| 1.30.5 | Feb 2026 | Orphan detection improvements: all 8 call sites tracked, stale reference counting fix, untracked orphan diagnostics |
| 1.31.0 | Feb 2026 | Thumbnail usage detection: Media entity thumbnail files as derived dependencies, forward and reverse detection |
| 1.31.1 | Feb 2026 | Derived dependency providers: `pdf_image_entity` support, non-image thumbnail display fixes, archive thumbnail preservation |
| 1.31.2 | Feb 2026 | Location rename: Source→Location across UI, per-type badge color palette with WCAG AA+ contrast |
| 1.31.3 | Feb 2026 | Media Library fix: archived thumbnail escaping in widget previews, removed debug logging |
| 1.32.0 | Feb 2026 | Scan resilience: phase-level checkpointing, concurrency protection, heartbeat-based stale lock detection, memory management |
| 1.32.1 | Feb 2026 | Scan resilience hardening: finalize UI precedence, grace rule, chunk-entry heartbeat, `breakStaleLock()` guardrails |
| 1.33.0 | Feb 2026 | Dashboard UI: horizontal bar chart, chart/table toggle, responsive stacked tables, colorblind-safe palette |
| 1.33.1 | Feb 2026 | Revision-aware delete guard: paragraph revision ghost classification, blocking vs warning for required field references, audit logging |
| 1.33.2 | Feb 2026 | Usage filter and alt text fixes: "Not In Use" filter excludes only active usage (orphan refs don't block), alt text N/A for link-type embeds, media reference search in alt text fallback, scanner preserves direct file usage during media rescan, dashboard chart counts orphan-only assets as Unused |
