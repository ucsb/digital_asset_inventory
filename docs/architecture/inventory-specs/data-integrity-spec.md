# Data Integrity Specification

## Overview

The Digital Asset Scanner uses an **atomic swap pattern** to ensure data integrity during scanning. This prevents partial or corrupted data from appearing in the inventory if a scan fails or is interrupted.

## The Problem

Without atomic operations, a scan failure could leave the inventory in an inconsistent state:

```
Scenario: Scan fails mid-process

Before Scan:
  Inventory has 1000 items (complete, accurate)

During Scan (failure at 60%):
  - 600 old items deleted
  - 400 new items created
  - Scan crashes/times out

After Failed Scan:
  Inventory has 400 items (incomplete, inaccurate!)
```

## The Solution: Atomic Swap Pattern

The scanner uses an `is_temp` flag to create a complete shadow inventory before replacing the production data.

### How It Works

```
┌─────────────────────────────────────────────────────────┐
│                    SCAN START                           │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│  Production Data (is_temp=FALSE)                        │
│  ┌─────────────────────────────────────────────────┐   │
│  │ Asset 1, Asset 2, Asset 3 ... Asset 1000        │   │
│  │ (Unchanged during scan - users see this data)   │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────┐
│  Temporary Data Being Built (is_temp=TRUE)              │
│  ┌─────────────────────────────────────────────────┐   │
│  │ New Asset 1, New Asset 2 ...                    │   │
│  │ (Building in background)                         │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
                           │
              ┌────────────┴────────────┐
              ▼                         ▼
┌─────────────────────────┐  ┌─────────────────────────┐
│   SCAN SUCCESS          │  │   SCAN FAILURE          │
└─────────────────────────┘  └─────────────────────────┘
              │                         │
              ▼                         ▼
┌─────────────────────────┐  ┌─────────────────────────┐
│ 1. Delete old permanent │  │ 1. Delete temp items    │
│ 2. Mark temp → permanent│  │ 2. Keep old data intact │
│ 3. Users see new data   │  │ 3. Users see old data   │
└─────────────────────────┘  └─────────────────────────┘
```

## Implementation Details

### The `is_temp` Flag

Both `digital_asset_item` and `digital_asset_usage` entities have an `is_temp` boolean field:

```php
// Entity field definition
$fields['is_temp'] = BaseFieldDefinition::create('boolean')
  ->setLabel(t('Temporary'))
  ->setDescription(t('Indicates if this is a temporary record during scanning.'))
  ->setDefaultValue(FALSE);
```

### Scan Workflow with is_temp

#### Phase 1-5: Creating Temporary Records

All scan phases create records with `is_temp=TRUE`:

```php
public function scanManagedFilesChunk($offset, $limit, $is_temp = FALSE) {
  // ... detection logic ...

  $asset = DigitalAssetItem::create([
    'file_name' => $file->getFilename(),
    'asset_type' => $asset_type,
    'is_temp' => $is_temp,  // TRUE during batch scan
    // ... other fields
  ]);
  $asset->save();
}
```

#### On Success: Promote Temporary Items

```php
public function promoteTemporaryItems() {
  // Step 1: Delete old permanent usage records
  $old_usages = $this->entityTypeManager
    ->getStorage('digital_asset_usage')
    ->loadByProperties(['is_temp' => FALSE]);
  foreach ($old_usages as $usage) {
    $usage->delete();
  }

  // Step 2: Delete old permanent asset items
  $old_items = $this->entityTypeManager
    ->getStorage('digital_asset_item')
    ->loadByProperties(['is_temp' => FALSE]);
  foreach ($old_items as $item) {
    $item->delete();
  }

  // Step 3: Mark temp items as permanent
  $temp_items = $this->entityTypeManager
    ->getStorage('digital_asset_item')
    ->loadByProperties(['is_temp' => TRUE]);
  foreach ($temp_items as $item) {
    $item->set('is_temp', FALSE);
    $item->save();
  }

  // Step 4: Mark temp usages as permanent
  $temp_usages = $this->entityTypeManager
    ->getStorage('digital_asset_usage')
    ->loadByProperties(['is_temp' => TRUE]);
  foreach ($temp_usages as $usage) {
    $usage->set('is_temp', FALSE);
    $usage->save();
  }
}
```

#### On Failure: Clear Temporary Items

```php
public function clearTemporaryItems() {
  // Delete temporary usage records first (foreign key order)
  $temp_usages = $this->entityTypeManager
    ->getStorage('digital_asset_usage')
    ->loadByProperties(['is_temp' => TRUE]);
  foreach ($temp_usages as $usage) {
    $usage->delete();
  }

  // Delete temporary asset items
  $temp_items = $this->entityTypeManager
    ->getStorage('digital_asset_item')
    ->loadByProperties(['is_temp' => TRUE]);
  foreach ($temp_items as $item) {
    $item->delete();
  }

  // Old permanent data remains intact
}
```

## Deletion Order: Foreign Key Constraint

**Critical Rule**: Always delete usage records before asset records.

```
digital_asset_usage.asset_id → digital_asset_item.id
```

If you delete asset items first, you'll have orphaned usage records pointing to non-existent assets.

### Correct Order

```php
// 1. Delete usage records
$usages = $storage_usage->loadByProperties(['is_temp' => FALSE]);
$storage_usage->delete($usages);

// 2. Delete asset items
$items = $storage_item->loadByProperties(['is_temp' => FALSE]);
$storage_item->delete($items);
```

### Incorrect Order (DO NOT DO THIS)

```php
// WRONG: Deleting items first leaves orphaned usages
$storage_item->delete($items);  // Usage records now reference deleted items!
$storage_usage->delete($usages);
```

## Archive Preservation

**Key Invariant**: Archive records (`digital_asset_archive`) are NEVER deleted during scans.

Archives represent deliberate admin actions and must persist regardless of:
- Scan success or failure
- File deletion from file system
- Media entity removal

```php
// Archive entity has NO is_temp field
// Archives are created manually and persist indefinitely
```

## Views Integration

Drupal Views filter out temporary records automatically:

```yaml
# views.view.digital_assets.yml
filters:
  is_temp:
    id: is_temp
    table: digital_asset_item
    field: is_temp
    value: '0'
    operator: '='
```

This ensures users never see incomplete scan data in the UI.

## Scan Statistics

The scanner stores timestamps in Drupal's State API to track scan progress and calculate duration:

- **Start timestamp**: Stored when scan begins, used to calculate how long the scan took
- **End timestamp**: Stored when scan completes successfully
- **Orphan count**: Number of paragraphs without valid parent entities

```php
// At scan start: store the current timestamp
public function resetScanStats() {
  // Save start time so we can calculate duration later
  $this->state->set('digital_asset_inventory.scan_start', \Drupal::time()->getRequestTime());
  $this->orphanCount = 0;
}

// At scan end: retrieve stats for reporting
public function getScanStats() {
  $start = $this->state->get('digital_asset_inventory.scan_start');
  $end = $this->state->get('digital_asset_inventory.scan_end');

  return [
    'start_time' => $start,
    'end_time' => $end,
    'duration' => $end - $start,  // Scan duration in seconds
    'orphan_count' => $this->orphanCount,
  ];
}
```

## Recovery Scenarios

### Scenario 1: Scan Times Out

1. Batch API detects timeout
2. `batchFinished()` called with `$success = FALSE`
3. `clearTemporaryItems()` removes partial data
4. Previous inventory remains intact
5. Admin notified of failure

### Scenario 2: PHP Fatal Error

1. Batch processing terminates unexpectedly
2. Temporary records remain in database
3. Next scan start calls `resetScanStats()`
4. New temp records created (old temps ignored)
5. On success, old temps get deleted with old permanent data

### Scenario 3: User Cancels Scan

1. User navigates away from batch page
2. Batch marked as incomplete
3. Same recovery as timeout scenario

## Concurrency Considerations

The scanner is designed for single-user operation:
- No concurrent scan support
- Starting a new scan while one is running may cause issues
- Admin UI shows "Scan in progress" warning (future enhancement)

## Related Files

- `src/Service/DigitalAssetScanner.php` - `promoteTemporaryItems()`, `clearTemporaryItems()`
- `src/Form/ScanAssetsForm.php` - Batch finished callback
- `src/Entity/DigitalAssetItem.php` - `is_temp` field definition
- `src/Entity/DigitalAssetUsage.php` - `is_temp` field definition
- `config/install/views.view.digital_assets.yml` - `is_temp` filter

## Summary

| Operation | Effect |
| --------- | ------ |
| Scan starts | Reset stats, store start timestamp, begin creating temp records |
| Scan chunk runs | Create/update records with `is_temp=TRUE` |
| Scan succeeds | Store end timestamp, delete old permanent, promote temp to permanent |
| Scan fails | Delete temp records, preserve old permanent data |
| Views display | Filter `is_temp=FALSE` (users see permanent only) |
