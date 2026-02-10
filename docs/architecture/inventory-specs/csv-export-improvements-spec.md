# CSV Export Improvements Specification

## Overview

Update the Digital Asset Inventory CSV export so that:

1. The downloaded filename includes the Drupal site name and download date.
2. A new column clearly indicates whether active usage was detected.
3. The existing "Used In" column uses audit-safe wording.

All changes must be host-agnostic and must not require any new configuration.

---

## Part 1: CSV Filename Update

### Current Behavior

CSV downloads as: `digital-assets.csv`

### Target Filename Format

```text
digital-assets__{site-name-slug}__{YYYY-MM-DD}.csv
```

**Example:**

```text
digital-assets__ucsb-web-theme__2026-02-07.csv
```

### Site Name Source

- Use Drupal site name from `system.site:name`
- Do NOT use host/domain.
- Do NOT introduce any configuration.

### Slugification Rules

Convert the Drupal site name to a file-safe slug:

1. Transliterate to ASCII using Drupal's transliteration service
   (`\Drupal::service('transliteration')->transliterate()`)
2. Convert to lowercase
3. Trim whitespace
4. Replace any sequence of non-alphanumeric characters with a single hyphen (`-`)
5. Collapse multiple hyphens into one
6. Remove leading/trailing hyphens
7. If the result is empty, use `drupal-site`

### Date Rules

- Use the site's configured timezone.
- Format date as ISO: `YYYY-MM-DD`
- Date represents the **download date**, not scan date.

### HTTP Headers

Apply the filename via response headers:

- `Content-Type: text/csv`
- `Content-Disposition: attachment; filename="digital-assets__{site-name-slug}__{YYYY-MM-DD}.csv"`

---

## Part 2: CSV Column Updates

### Current Columns

| # | Column |
|---|--------|
| 1 | File Name |
| 2 | File URL |
| 3 | Asset Type |
| 4 | Category |
| 5 | MIME Type |
| 6 | Source |
| 7 | File Size |
| 8 | Used In |

### Final Column Order

| # | Column | Change |
|---|--------|--------|
| 1 | File Name | — |
| 2 | File URL | — |
| 3 | Asset Type | — |
| 4 | Category | — |
| 5 | MIME Type | — |
| 6 | Source | — |
| 7 | File Size | — |
| 8 | **Active Use Detected** | **New** |
| 9 | Used In | Label change |

Header label must be exactly: `Active Use Detected` (capitalization and spacing are significant).

---

## New Column: "Active Use Detected"

### Values

| Value | Meaning |
|-------|---------|
| `Yes` | One or more supported usage references detected |
| `No` | No supported usage references detected |

### Mapping Logic

Use the same underlying detection logic as the scan view filter (`has_detected_usage` boolean rendered as Yes/No):

- Scan view "In Use" = CSV "Active Use Detected = Yes"
- Scan view "Not In Use" = CSV "Active Use Detected = No"

---

## Update "Used In" Column Wording

### Required Change

Replace the value `Not used` with `No active use detected`.

### Other Values

If usage exists, keep the existing list of detected locations (no formatting changes).

---

## CSV Structure Rules

- The first row must remain the header row.
- Do NOT add title rows, metadata rows, or comments above the header.
- Preserve existing delimiter, quoting, and escaping behavior.
- CSV column order is considered a stable interface and must not change without an explicit spec update.

---

## Implementation Approach

### Dynamic Filename: Response Event Subscriber

The Views `data_export` plugin sets a static filename in config (`views.view.digital_assets.yml` line 1373). To make it dynamic without modifying the plugin, use a response event subscriber.

**New file:** `src/EventSubscriber/CsvExportFilenameSubscriber.php`

- Listen to `KernelEvents::RESPONSE` at priority `-50` (after Views rendering)
- Guard conditions:
  - Request path matches a known CSV export path (inventory or archive audit)
  - Response has `Content-Type: text/csv`
  - Response has `Content-Disposition` header starting with `attachment`
- Handles both inventory (`/admin/digital-asset-inventory/csv` → `digital-assets`) and archive audit (`/admin/digital-asset-inventory/archive/csv` → `archived-assets-audit`) via `PATH_PREFIX_MAP` constant
- Build filename from `ConfigFactoryInterface` (site name) and `DateFormatterInterface` (timezone-aware date)
- This change must not introduce response caching differences or alter cache metadata
- Register in `digital_asset_inventory.services.yml` with `event_subscriber` tag

**Rationale:** Matches the existing `ArchiveLinkResponseSubscriber` pattern (services.yml lines 58–67). No changes to Views config filename needed.

### "Active Use Detected" Column: Pre-computed Entity Field

**Pattern:** Follow the established convention of `filesize_formatted` and `used_in_csv` — string fields on `DigitalAssetItem` populated during scan, read at export time with zero query overhead.

**Entity change:** Add `active_use_csv` base field (string, max_length 5) to `DigitalAssetItem.php` between `filesize_formatted` (line 271) and `used_in_csv` (line 274).

**Scanner change:** In `DigitalAssetScanner::updateCsvExportFields()` (line 4267), after the existing `$usage_ids` query, set:

```php
$asset->set('active_use_csv', !empty($usage_ids) ? 'Yes' : 'No');
```

**Views config change:** Add `active_use_csv` field to the `data_export_csv_inventory` display between `filesize_formatted` and `used_in_csv` in `views.view.digital_assets.yml`.

### "No active use detected" Label

In `DigitalAssetScanner::updateCsvExportFields()` line 4307, change `'Not used'` to `'No active use detected'`. Takes effect on next scan via atomic swap — no data migration needed.

### Update Hook

`digital_asset_inventory_update_10044()` in `digital_asset_inventory.install`:

1. Install `active_use_csv` field via `entityDefinitionUpdateManager()->installFieldStorageDefinition()`
2. Import updated Views config via `FileStorage` + `config.storage->write()`
3. Return message noting a re-scan is needed to populate the new column

---

## Files Changed

| File | Type | Description |
|------|------|-------------|
| `src/Entity/DigitalAssetItem.php` | Edit | Add `active_use_csv` base field definition |
| `src/Service/DigitalAssetScanner.php` | Edit | Set `active_use_csv` value; change "Not used" → "No active use detected" |
| `src/EventSubscriber/CsvExportFilenameSubscriber.php` | New | Dynamic filename via Content-Disposition rewriting |
| `digital_asset_inventory.services.yml` | Edit | Register new event subscriber |
| `config/install/views.view.digital_assets.yml` | Edit | Add "Active Use Detected" column to CSV export display |
| `digital_asset_inventory.install` | Edit | Add update hook 10044 |

---

## Acceptance Criteria

1. CSV downloads with filename: `digital-assets__{site-name-slug}__{YYYY-MM-DD}.csv`
2. CSV includes a new column named **Active Use Detected** immediately before "Used In".
3. Rows with no detected usage show:
   - Active Use Detected = `No`
   - Used In = `No active use detected`
4. Rows with detected usage show:
   - Active Use Detected = `Yes`
   - Used In = populated with existing detected locations
5. No configuration changes are required.
6. Behavior is identical across Pantheon, Acquia, and other hosting environments.

---

## Testing Notes

- Verify correct `Content-Disposition` filename with site name slug and today's date
- Verify slug handles edge cases: accented names, emoji-only names, punctuation-only names, empty site name (should fall back to `drupal-site`)
- Verify date uses site timezone (not UTC); test with non-UTC timezone near midnight
- Verify "Active Use Detected" column is position 8 (before "Used In")
- Verify Yes/No values align with Used In column content
- Verify archive audit CSV (`/admin/digital-asset-inventory/archive/csv`) also gets dynamic filename: `archived-assets-audit__{site-name-slug}__{YYYY-MM-DD}.csv`
- Verify CSV opens cleanly in Excel and Google Sheets (no title rows, proper quoting)
- Verify CSV header row order matches the column order defined in this spec
- Verify no new queries are introduced per asset in `updateCsvExportFields()` — reuse existing `$usage_ids`

---

## Risk Assessment

| Area | Risk | Mitigation |
| --- | --- | --- |
| Filename subscriber | Filename not applied or wrong export renamed | Strict guard: exact path + `text/csv` content type + existing `Content-Disposition`; explicit exclusion of archive CSV path |
| Slugification | Empty or invalid slug from unusual site names | Drupal transliteration service + regex cleanup + `drupal-site` fallback; unit tests for edge cases |
| Timezone | Off-by-one-day filename near midnight | `DateFormatterInterface` respects site timezone; document that date is download date |
| Entity field | Blank column if update hook not run or scan not triggered | Update hook installs field storage; return message instructs re-scan; kernel test verifies field |
| Semantic drift | CSV and UI disagree on "In Use" status over time | Both derive from same `$usage_ids` query in `updateCsvExportFields()`; spec locks mapping |
| Views config | Column order changes from manual Views UI edits | Spec locks column order; test asserts header order; update hook imports config |
| Wording change | Mixed "Not used" / "No active use detected" in existing data | Acceptable: atomic swap replaces all rows on next scan; update hook message states re-scan required |

Net assessment: Low operational risk, manageable implementation risk, strong audit posture.

---

## Scope

No changes are required to the scan UI or filters; this change affects CSV export only.

## Non-Goals

- Do not change scan logic or detection scope.
- Do not add timestamps, reference counts, or metadata rows.
- Do not alter CSV content beyond filename and specified column/label changes.
