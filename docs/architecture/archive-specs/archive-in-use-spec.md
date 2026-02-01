# Spec: Archiving In-Use Documents and Videos

> **Status:** Implemented
> **Created:** January 2026
> **Implemented:** January 2026

---

## Problem Statement

The current archive workflow blocks archiving when an asset is "in use" (referenced by active content). While this prevents ambiguity, it creates significant operational burden:

- Documents may be linked from dozens of pages
- Manual unlinking is time-consuming and error-prone
- Delays often prevent timely archiving

For documents and videos, the compliance concern is **continued direct delivery**, not the presence of references. This spec addresses that by separating reference presence from delivery behavior.

---

## Scope

### In Scope

| Item | Description |
|------|-------------|
| Documents | PDF, Word, Excel, PowerPoint, text, CSV |
| Videos | MP4, MOV, WebM, AVI |
| Archiving while in use | Remove usage gate for eligible types |
| Drupal-controlled routing | Route Drupal-generated links to Archive Detail Page |
| UI warnings | Inform users of behavior and limitations |
| Audit logging | Track archived-while-in-use status |

### Out of Scope

| Item | Reason |
|------|--------|
| Images | Redirecting would break page rendering |
| Audio files | Not currently archive-eligible |
| Compressed files | Not currently archive-eligible |
| Removing references | Links remain intact by design |
| Web server configuration | Module operates within Drupal only |

---

## Current State

| Component | Current Behavior | New Behavior |
|-----------|------------------|--------------|
| `validateExecutionGates()` | Blocks if `usage_count > 0` | Skip for documents/videos |
| `ExecuteArchiveForm` | Disables submit when usage detected | Show warning, allow proceed |
| `flag_usage` | Set TRUE when usage detected | **Unchanged** - still set and displayed |
| File delivery | Direct URLs, no interception | Route to Archive Detail Page |
| Public content display | Full placeholder box | Simplified "(Archived)" inline link |
| Media Library display | Full placeholder box | Full placeholder with icon, message, date |

---

## Guiding Principles

> For documents and videos, archived status is determined by **delivery behavior**,
> not by whether references remain.

An eligible asset may be archived while still referenced if Drupal-generated access
is routed through the Archive Detail Page.

### Display Context Principle

> Public visitors need minimal disruption; editors need full context.

| Context | User Need | Display Approach |
|---------|-----------|------------------|
| Public content | Know content is archived, access detail page | Inline "(Archived)" link |
| Media Library | Understand asset status before selection | Full placeholder with icon, message, date |

**Rationale:**
- Public visitors viewing content should see a clean, non-intrusive indicator that links to more information
- Editors selecting media need immediate visual feedback about archived status to make informed decisions
- The archive date in admin UI helps editors understand the timeline and compliance context

---

## Drupal-Only Limitation

### What the Module Can Control

| Aspect | Controlled? | Mechanism |
|--------|-------------|-----------|
| Drupal-rendered links | Yes | Field formatters, Views, templates |
| Media entity references | Yes | Media display formatters |
| CKEditor embedded links | Yes | Text format processing |
| Menu links | Yes | Menu link alter hook or preprocess |
| Redirects | Yes | Redirect module integration (if installed) |
| Direct URL typed by user | **No** | Web server serves directly |
| Bookmarked/saved URLs | **No** | Bypass Drupal entirely |
| CDN/cached responses | **No** | Edge caching |

### Public File Caveat

When archiving assets stored in `public://`:
- Drupal-generated links can be routed to Archive Detail Page
- Direct public URLs may still be accessible via web server
- This is a **Drupal limitation**, not a module deficiency

### Compliance Positioning

In Drupal-only mode, archiving:
- Controls the primary user experience
- Reduces accidental access
- Does not guarantee technical denial of direct binary access

---

## Functional Requirements

### FR-1: Remove Usage Gate for Documents/Videos

**Current gate in `validateExecutionGates()`:**
```php
if ($usage_count > 0) {
  $issues['usage_detected'] = 'File is still referenced...';
}
```

**New behavior:**
- Skip usage gate for documents and videos
- Continue to enforce file existence gate
- Continue to enforce asset type gate

### FR-2: Track Archived-While-In-Use Status (Audit Flag)

On successful archive, record:

| Field | Type | Purpose |
|-------|------|---------|
| `flag_usage` | Boolean | Continue to set TRUE when usage > 0 (existing field, updated on rescan) |
| `archived_while_in_use` | Boolean | TRUE if usage > 0 **at archive time** (immutable snapshot) |
| `usage_count_at_archive` | Integer | Snapshot of usage count **at archive time** |

**Why persist as an audit flag:**

Usage can change after archiving (references added or removed). The `archived_while_in_use` flag captures the condition at archive time and determines:

1. Whether the "Optional Message" collapsible is shown in management UI
2. Audit trail of the archiving decision

**Field behavior:**

| Field | When Set | When Updated |
|-------|----------|--------------|
| `flag_usage` | Archive execution | Every rescan/reconciliation |
| `archived_while_in_use` | Archive execution | **Never** (immutable) |
| `usage_count_at_archive` | Archive execution | **Never** (immutable) |

**Important:** The existing `flag_usage` warning flag must continue to be set and displayed in the Archive Management page. This provides visibility into **current** active references. The `archived_while_in_use` flag reflects the **historical** condition at archive time.

### FR-3: Drupal Link Routing

When a document/video is archived, Drupal-generated links should route to Archive Detail Page:

| Link Source | Routing Method | Display Format |
|-------------|----------------|----------------|
| File field formatter | `hook_preprocess_file_link` | "filename (Archived)" link |
| Media display (public) | `hook_preprocess_media` | "Name (Archived)" inline link |
| Media display (Media Library) | `hook_preprocess_media` | Full placeholder with date |
| CKEditor file links | `ArchiveFileLinkFilter` | "Link text (Archived)" link |
| CKEditor media embeds | `ArchiveFileLinkFilter` | "Name (Archived)" inline link |
| Menu links | `hook_preprocess_menu` | "Menu title (Archived)" link |
| Breadcrumbs | `hook_system_breadcrumb_alter` | "Breadcrumb text (Archived)" link |
| Redirects | Redirect module event subscriber | (if installed) |

**Display Format Notes:**
- Public content displays use simplified "(Archived)" label appended to link text
- Media Library UI shows full placeholder with icon, notice message, and archive date
- View modes `media_library`, `thumbnail`, and `media_library_thumbnail` trigger full placeholder display

### FR-3a: Menu Link Usage Detection

Include menu links in usage detection and reporting:

- Scan menu links for direct file URLs during inventory scan
- Include menu link count in usage report
- Display menu link references in "View usage locations" page
- Menu links count toward `usage_count` and `flag_usage`

### FR-3b: Redirect Usage Detection

Include redirects in usage detection and reporting (if Redirect module installed):

- Scan redirect entities for file URL destinations during inventory scan
- Include redirect count in usage report
- Display redirect references in "View usage locations" page
- Redirects count toward `usage_count` and `flag_usage`

**Note:** Redirect module integration is optional. If the module is not installed, redirect detection is skipped without error.

### FR-4: Audit Logging

Auto-create `dai_archive_note` entries for in-use archiving lifecycle:

| Event | Note Text |
|-------|-----------|
| Queue while in use | "Queued for archive while in use (X references)." |
| Archive while in use | "Archived while in use (X references)." |
| Unarchive | "Unarchived - direct file access restored (was archived with X references)." |
| Exemption void | "Exemption voided - direct file access restored." |

This follows the existing pattern where significant actions (file deletion, manual entry removal) automatically create audit notes.

**Additional tracking:**
- Asset type (document/video)
- `archived_while_in_use = TRUE`
- Usage count at time of archive
- Timestamp and actor

---

## UX Requirements

### Queue Page: Queue Digital Asset for Archiving

**Page Title:** "Queue Digital Asset for Archiving"

**Intro text:** "This step records archive intent. Archiving is completed in a later step."

#### Layout (When In Use)

| Order | Element | Visibility |
|-------|---------|------------|
| 1 | Page intro (subtitle) | Always |
| 2 | Archive Requirements | Collapsed |
| 3 | About the Two-Step Archive Process | Collapsed |
| 4 | Archive validation complete (green) | Always |
| 5 | This item is currently in use (yellow) | When in use |
| 6 | Confirmation checkbox | When in use |
| 7 | Archive Details (reason, description, notes) | Gated by checkbox when in use |
| 8 | File Information | Collapsed, inside gated section |
| 9 | Actions (Queue for Archive / Return to Inventory) | Always |

#### Queue Page: Green Validation Box

```
Archive validation complete

✓ File exists at its original location
✓ In-use archiving is enabled for this asset type

This item meets the system requirements to be queued for archiving.

Queueing does not remove the file. The file remains in its current location until archiving is completed.
```

#### Queue Page: Yellow Warning Box

```
This item is currently in use

Referenced in 1 location. View usage locations

When archived, site links will route to the Archive Detail Page instead of
serving the file directly. Existing references will continue to work and
will display archive context before access.

Note: This file is stored in a public directory. The direct file URL may
still be accessible to users who already have it.
```

#### Queue Page: Confirmation Checkbox

```
☑ I understand that this item is currently in use and that, when archived,
  site links will route to the Archive Detail Page.
```

---

### Execute Page: Execute Digital Asset Archive

**Page Title:** "Execute Digital Asset Archive"

#### Layout (When In Use)

| Order | Element | Visibility |
|-------|---------|------------|
| 1 | Archive validation complete (green) | Always |
| 2 | This item is currently in use (yellow) | When in use |
| 3 | Confirmation checkbox | When in use |
| 4 | File Information | Collapsed |
| 5 | Optional Message to Add on Referencing Pages | Collapsed, when in use |
| 6 | Archive Visibility | Gated by checkbox when in use |
| 7 | Classification (informational) | Gated by checkbox when in use |
| 8 | Actions (Archive Asset / Return to Archive Management) | Always |

#### Execute Page: Green Validation Box

```
Archive validation complete

✓ File exists at its original location
✓ In-use archiving is enabled for this asset type

This item meets the system requirements for archiving.

Note: Archiving does not remove the file. The file remains in its current
location and is classified for ADA Title II compliance purposes.
```

#### Execute Page: Yellow Warning Box

```
This item is currently in use

Referenced in 1 location. View usage locations

Archiving will route site links to the Archive Detail Page instead of
serving the file directly. Existing references will continue to work and
will display archive context before access.

Note: This file is stored in a public directory. The direct file URL may
still be accessible to users who already have it.
```

**Key difference:** Queue page uses "When archived, site links will route..." while Execute page uses "Archiving will route site links..."

#### Execute Page: Confirmation Checkbox

```
☑ I understand that this item is in use and that archiving will route
  site links to the Archive page.
```

---

### Archive Visibility

**Purpose:** Classification choice, not a risk decision.

**UI Treatment:** Standard form section (radios), not collapsible.

**Options:**
- Public — Visible on the public Archive Registry
- Admin-only — Visible only in Archive Management

**Helper text:** "Choose whether this archived document should be visible on the public Archive Registry or only in archive management."

---

### Archive Classification (Informational)

**Purpose:** Explain how the system will classify this archive.

**UI Treatment:** Plain text (no box), not collapsible.

**Copy (Legacy):**
```
Classification (automatic): This document will be classified as a Legacy
Archive (archived before April 24, 2026) and may be eligible for ADA
Title II accessibility exemption.
```

**Copy (General):**
```
Classification (automatic): This document will be classified as a General
Archive (archived after April 24, 2026), retained for reference purposes
without claiming ADA exemption.
```

---

### Optional Message for Referencing Pages

**Purpose:** "Do I need to add context for visitors elsewhere?"

**UI Treatment:** Collapsible info section, collapsed by default.

**Conditional Display:** Only when archived while in use.

**Helper text:**
```
In most cases, no page updates are required. The system automatically
routes links to the Archive page.

You may add the message below on pages where additional context would
be helpful.
```

**Includes:**
- Suggested page-level message
- Guidance to use the Archive Registry URL for manual/external links

---

### Primary Actions

**Queue Page:**
- Primary: "Queue for Archive" (disabled until checkbox checked when in use)
- Secondary: "Return to Inventory"
- Note: "This action adds the asset to the archive queue. Archiving is completed in a later step."

**Execute Page:**
- Primary: "Archive Asset" (disabled until checkbox checked when in use)
- Secondary: "Return to Archive Management"

---

## Configuration

### New Settings

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `allow_archive_in_use` | Boolean | TRUE | Enable archiving documents/videos while in use |

### Admin Toggle

Settings form addition:
```
[x] Allow archiving documents and videos while in use
    When enabled, documents and videos can be archived even when
    referenced by active content.
```

---

## Architectural Separation: Link Routing vs Policy Gate

### Design Principle

The module maintains a clear separation between two independent concerns:

| Concern | Control Mechanism | Affected Behavior |
|---------|-------------------|-------------------|
| **Link Routing** | Archive feature (`enable_archive`) | All site links to archived assets route to Archive Detail Page |
| **Policy Gate** | `allow_archive_in_use` setting | Whether users can CREATE new archives for in-use assets |

### Link Routing Behavior

**Link routing is ALWAYS ON when the archive feature is enabled.**

If an asset has an active archive record (`archived_public` or `archived_admin`), all Drupal-generated links to that asset will route to its Archive Detail Page. This applies regardless of:

- The `allow_archive_in_use` setting
- Whether the asset was archived before or after the setting was changed
- Current usage status

**Rationale:** Consistent user experience. Visitors clicking on an archived document should always see the archive context, not receive an unexpected file download.

### Policy Gate Behavior

**The `allow_archive_in_use` setting controls only ONE thing:** Whether users may create new archives for assets that are still referenced.

| `allow_archive_in_use` | Can Queue/Archive In-Use Assets | Link Routing for Existing Archives |
|------------------------|----------------------------------|-----------------------------------|
| `TRUE` | Yes | Always routes to Archive Detail Page |
| `FALSE` | No (button hidden; form redirects if accessed directly) | Still routes to Archive Detail Page |

**Important:** This setting does NOT affect existing archives. Archives created when the setting was enabled remain valid and continue to route links appropriately.

### Implementation

```php
// Policy gate: controls archive creation workflow
public function isArchiveInUseAllowed() {
    return (bool) $config->get('allow_archive_in_use');
}

// Link routing: always on when archive feature enabled
public function isLinkRoutingEnabled() {
    return (bool) $config->get('enable_archive');
}
```

This separation ensures:

1. **Auditability** — Archives are immutable once created; configuration changes cannot retroactively affect them
2. **Consistency** — Users see the same behavior for all archived assets
3. **Flexibility** — Administrators can change policy without breaking existing archives

---

## Edge Cases: Configuration Changes

### Core Invariant

> Configuration affects what you can do next, not what you already did.
> Archiving and unarchiving must always be reversible, but re-archiving must respect current policy.

System configuration changes do not retroactively invalidate completed actions, but they do constrain future actions.

### Edge Case Matrix

| State | Usage | Config Allows In-Use | Action | Allowed |
|-------|-------|---------------------|--------|---------|
| Archived | In use | ❌ | Unarchive | ✅ |
| Archived | In use | ❌ | Re-archive | ❌ |
| Archived (Admin-Only) | In use | ❌ | Make Public | ❌ |
| Archived (Public) | In use | ❌ | Make Admin-only | ✅ |
| Queued | In use | ❌ | Execute | ❌ |
| Queued | In use | ❌ | Remove from queue | ✅ |
| Active | In use | ❌ | Queue for Archive | ❌ |
| Active | In use | ❌ | Archive (execute) | ❌ |
| Active | Not in use | ❌ | Queue for Archive | ✅ |
| Active | Not in use | ❌ | Archive (execute) | ✅ |
| Queued | Not in use | ❌ | Execute | ✅ |
| Archived | In use | ✅ | Unarchive | ✅ |

### EC1: Config Disabled After Asset Was Archived While In Use

**State:** Archived (admin-only or public), usage detected, config now disallows in-use archiving.

**Behavior:**
- ✅ Allow Unarchive (corrective action, never blocked)
- ❌ Disallow Re-archive unless usage is removed or config is re-enabled
- ⚠️ Show warning during unarchive about re-archive being blocked

### EC2: Asset Queued While In Use, Then Config Disabled Before Execution

**State:** Asset is Queued, usage detected, config toggled OFF before execution.

**Behavior:**
- 🚫 Block Execute action with specific message
- Show "Blocked" badge in warnings column
- Show status message: "This asset was queued while in use, but current settings no longer allow archiving assets that are in use."

**Allowed Actions:**
- ✅ Remove from queue
- ✅ Remove usage references
- ✅ Re-run scanner
- ❌ Execute archive

### EC3: Asset Queued Not In Use, But Usage Detected Later (Re-scan)

**State:** Asset queued (valid at queue time), later scan detects new usage, config disallows in-use archiving.

**Behavior:**
- ⚠️ Show "Blocked" badge
- ❌ Block execution
- ✅ Allow: Remove references, Remove from queue, Re-scan and retry

### EC4: Usage Temporarily Disappears (Race Condition)

**State:** Asset shows no usage, user archives, next scan re-detects usage.

**Behavior:**
- ✅ Archive remains valid
- ⚠️ Show "Usage detected after archiving" indicator
- ❌ Do not auto-unarchive or revoke archive

### EC5: User Tries to Archive Already Archived Asset

**State:** Archived, any usage state, any config state.

**Behavior:**
- ❌ Redirect with message: "This asset is already archived."
- No further action needed.

### EC6: User Tries to Unarchive With Usage Removal Required by Policy

**State:** Archived, usage detected, config disallows in-use archiving.

**Behavior:**
- ✅ Allow unarchive (corrective action)
- ⚠️ Warn that re-archive will be blocked unless usage is removed or config re-enabled
- 🚫 Do NOT require usage removal before unarchive
- 🚫 Do NOT auto-remove usage

### EC7: Asset Archived Admin-Only, Made Public While Config Disallows In-Use

**State:** Archived (Admin-only), usage detected, config disallows in-use archiving.

**Behavior:**
- ❌ Block visibility change to Public
- Hide "Make Public" operation link
- If form accessed directly, redirect with error: "This asset is currently in use. Changing visibility would expose archived content while in use, which is not allowed by current settings."

### EC8: Config Re-enabled After Being Disabled

**State:** Assets previously blocked (queued or active), usage detected, config toggled ON.

**Behavior:**
- ✅ Allow: Archive, Re-archive, Execute queued archives
- ⚠️ Do NOT auto-execute anything
- Users must explicitly re-confirm intent.

### EC9: Manual Archive Entries vs System-Tracked Assets

**State:** Manual archive entry (no usage tracking), config disallows in-use archiving.

**Behavior:**
- Manual entries bypass usage gating (no file to track)
- Still respect visibility rules
- No blocking based on usage since usage cannot be determined

### EC10: Asset Restored from Backup / Re-uploaded

**State:** Archived asset replaced or re-uploaded, file hash changes.

**Behavior:**
- ❌ Treat as modified file (integrity violation)
- Archive status changes to exemption_void (Legacy) or archived_deleted (General)
- New archive requires fresh decision
- Do not inherit previous archive status

### EC11: Asset Archived Public, Changed to Admin-Only While In Use

**State:** Archived (Public), usage detected, config disallows in-use archiving.

**Behavior:**
- ✅ Allow visibility change to Admin-only (always permitted)
- No warning or blocking required
- Reducing visibility is a corrective action (like unarchive)
- Usage indicator remains visible after change

**Rationale:**
Making an archive admin-only reduces exposure, which is the opposite concern of EC7.
When in-use archiving is restricted, the goal is to prevent public exposure of referenced content.
Removing from public view achieves that goal, so it should never be blocked.

### UX Pattern

Whenever an action is disabled due to configuration:
- Never silently disable actions
- Always show explanation: "Why can't I do this?"
- Provide clear path to resolution

---

## Implementation Approach

### Phase 1: Remove Usage Gate (Implemented)

1. ✅ Modify `validateExecutionGates()` to skip usage check for documents/videos
2. ✅ Add `archived_while_in_use` field to `DigitalAssetArchive` entity
3. ✅ Update `ExecuteArchiveForm` to show warning instead of blocking

### Phase 2: Link Routing (Implemented)

Drupal-side link routing for archived documents, videos, and manual entries:

1. ✅ `hook_preprocess_file_link` - Routes file field links to Archive Detail Page, adds "(Archived)" label
2. ✅ `hook_preprocess_media` - Routes media display to Archive Detail Page with context-aware display
3. ✅ `hook_preprocess_menu` - Routes menu links to Archive Detail Page, adds "(Archived)" to title
4. ✅ `hook_system_breadcrumb_alter` - Routes breadcrumb links to Archive Detail Page, adds "(Archived)"
5. ✅ `ArchiveFileLinkFilter` - Text filter for CKEditor content, adds "(Archived)" to link text
6. ✅ Only eligible asset types are redirected (documents, videos, page, external)
7. ✅ Images, audio, and compressed files are NOT redirected
8. ✅ Links automatically revert when archive is removed or setting is disabled

**Important:** The "(Archived)" label is added at render time, not stored in the database. This means:

- Admins cannot permanently remove the label by editing the menu title
- When the file is unarchived, the label automatically disappears
- No manual menu maintenance is required when archive status changes

**Breadcrumb Compatibility:**

Breadcrumb rewriting for archived assets is applied via `hook_system_breadcrumb_alter()` and works with core and most contrib breadcrumb modules.

Due to Drupal's pluggable breadcrumb system, modules that fully replace breadcrumb generation may bypass this alteration. In all cases, archived assets are still consistently redirected to the Archive Detail Page in menus, content fields, and rendered links.

**Centralized Resolver:**

All link routing uses `ArchiveService::resolveArchiveDetailUrlFromUrl()` as the centralized resolver:

- Accepts a `Url` object (route or external/uri style)
- Detects whether the URL points to a file that is archived
- If archived, returns a `Url` object for the Archive Detail Page
- Otherwise returns `NULL`

This ensures consistent behavior across menus, breadcrumbs, file fields, and CKEditor content.

#### Link Display by Context

| Context | Display Format | Example |
|---------|---------------|---------|
| Public content (CKEditor links) | Inline link with "(Archived)" label | `Document Name (Archived)` |
| Public content (media embeds) | Inline link with "(Archived)" label | `Video Name (Archived)` |
| Public content (file fields) | Link with "(Archived)" label | `report.pdf (Archived)` |
| Media Library UI | Full placeholder with icon, message, date | See below |

#### Media Library Placeholder (Admin UI)

When editors browse or select media in the Media Library, archived items display a full placeholder:

```
┌─────────────────────────────────────────────────────────────────┐
│ Document Name (Archived)                                        │
├─────────────────────────────────────────────────────────────────┤
│ ℹ This document is for reference only and was archived on       │
│   January 30, 2026.                                             │
└─────────────────────────────────────────────────────────────────┘
```

The message varies by media type:
- Documents: "This document is for reference only and was archived on [Date]."
- Videos: "This video is for reference only and was archived on [Date]."
- Other files: "This file is for reference only and was archived on [Date]."

This full display alerts editors that they're selecting archived content, while public visitors see only the simplified inline link.

### Phase 3: Audit & Reporting (Implemented)

1. ✅ Log archived-while-in-use events (auto-created notes)
2. ✅ Add filter to Archive Management view
3. ✅ Include in CSV export

---

## Files Modified

| File | Change |
|------|--------|
| `src/Entity/DigitalAssetArchive.php` | Add `archived_while_in_use` field |
| `src/Service/ArchiveService.php` | Modify `validateExecutionGates()`, add `getActiveArchiveByFid()` |
| `src/Form/ArchiveAssetForm.php` | Block queuing when in use and `allow_archive_in_use` disabled |
| `src/Form/ExecuteArchiveForm.php` | Show warning instead of blocking (when queuing was allowed) |
| `config/install/digital_asset_inventory.settings.yml` | Add `allow_archive_in_use` |
| `src/Form/SettingsForm.php` | Add toggle |
| `src/Plugin/Filter/ArchiveFileLinkFilter.php` | Route CKEditor links/embeds with "(Archived)" label |
| `digital_asset_inventory.module` | Add `hook_preprocess_file_link`, `hook_preprocess_media`, `hook_page_attachments` |
| `js/menu_archive_links.js` | Client-side menu link routing (bypasses cache issues) |
| `templates/dai-archived-media-placeholder.html.twig` | Placeholder template (used in Media Library UI) |

---

## Migration Path

1. Ship with `allow_archive_in_use: FALSE` (opt-in)
2. Existing archives unaffected
3. Enable via Settings when ready
4. No database migration required

---

## Rollback & Recovery

### Unarchive Flow

If an in-use document is unarchived:
- Status changes to `archived_deleted`
- Drupal-generated links restore to direct file delivery
- Links continue to function (never broken)

### Exemption Void Flow

If an archived-while-in-use document has its exemption voided:
- Status changes to `exemption_void`
- Drupal-generated links restore to direct file delivery
- File returns to normal active status

**Note:** Link routing is based on active archive status (`archived_public` or `archived_admin`). When status changes to `archived_deleted` or `exemption_void`, routing automatically reverts to direct delivery.

### Disable Policy Gate

Set `allow_archive_in_use: FALSE`:
- Future in-use archives blocked again
- Existing in-use archives remain valid
- Link routing continues for existing archives (always on when archive feature enabled)
- No automatic unarchiving

**Important:** Disabling `allow_archive_in_use` only affects the ability to CREATE new archives for in-use assets. It does NOT affect link routing behavior for existing archives. See "Architectural Separation" section above.

---

## Performance Considerations

| Concern | Mitigation |
|---------|------------|
| Link routing overhead | Cache archive status lookups |
| Text filter processing | Process once, cache result |
| Large usage counts | Display count, don't load all entities |

---

## Admin-Only Visibility Behavior

> "Admin-only controls visibility & disclosure, not storage."

When an archive has `archived_admin` status, the file may still be at a public URL, but the module will not disclose that URL to anonymous users.

### Archive Detail Page Access

| Status | User | Show Metadata | Show File URL/Download | Show Actions |
|--------|------|---------------|------------------------|--------------|
| `archived_public` | Anonymous | ✓ Full | ✓ | ✗ |
| `archived_public` | Admin | ✓ Full | ✓ | ✓ |
| `archived_admin` | Anonymous | Limited | ✗ | ✗ |
| `archived_admin` | Admin | ✓ Full | ✓ | ✓ |

### Anonymous Access to Admin-Only Archives

When anonymous users visit the detail page of an admin-only archive, they see:
- File name and type
- Archived status
- Archive purpose
- Message: "This item is not available in the public Archive Registry."
- For private files: "This item requires authentication to access."
- For public files: "If you need access, please contact the accessibility resources listed on this website."

They do NOT see:
- File URL
- Download link
- Internal identifiers (checksum, IDs)

### Visibility Toggle Warning

When changing Public → Admin-only with active usage:
- Show warning: "This item is referenced in active content (X locations). After making it Admin-only, visitors clicking these links will see an access notice instead of the file."
- Provide "View usage locations" link
- Log the change with previous/new visibility, usage count, and actor

### Archive Management Indicator

Admin-only archives with active usage show an "In use (Admin-only)" badge in the Warnings column with tooltip: "This admin-only archived item is still referenced in public content."

---

## Summary

| Aspect | Behavior |
|--------|----------|
| Documents/Videos in use | Can be queued and archived (configurable via `allow_archive_in_use`) |
| Link routing | Always on when archive feature enabled (not configurable) |
| `allow_archive_in_use` setting | Controls policy gate for NEW archives (blocks both queuing and execution) |
| Admin-only visibility | Controls disclosure, not storage; anonymous users see access notice |
| Links in public content | Show "Name (Archived)" linking to Archive Detail Page |
| Media embeds in content | Show inline "Name (Archived)" link (no placeholder box) |
| Media Library UI | Show full placeholder with icon, message, and archive date |
| Direct public URLs | May still work (Drupal limitation) |
| Private files | Fully controlled via hook_file_download |
| Audit trail | Tracks archived-while-in-use status |
| Rollback | Unarchive restores previous behavior |
