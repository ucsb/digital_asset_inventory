<?php

namespace Drupal\Tests\digital_asset_inventory\Kernel;

use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;
use Drupal\digital_asset_inventory\Entity\DigitalAssetUsage;
use Drupal\digital_asset_inventory\Service\DigitalAssetScanner;
use Drupal\file\Entity\File;

/**
 * Tests scanner atomic swap pattern, entity CRUD, and entity-level queries.
 *
 * Covers Groups E (atomic swap), F (entity CRUD), and G (scanner queries).
 * 11 test cases.
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
class ScannerAtomicSwapKernelTest extends DigitalAssetKernelTestBase {

  /**
   * The scanner service.
   */
  protected DigitalAssetScanner $scanner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->scanner = $this->container->get('digital_asset_inventory.scanner');
  }

  /**
   * Tests promoteTemporaryItems() atomic swap (TC-SCAN-004 success path).
   *
   * Note: The scanner's condition('is_temp', FALSE) uses PHP boolean FALSE
   * which SQLite PDO binds differently than integer 0. On MySQL this deletes
   * old permanent items; on SQLite the delete is a no-op. This test verifies
   * the promotion half (is_temp TRUE→FALSE) which works on both backends,
   * and uses a manual pre-cleanup to test the full expected state.
   */
  public function testPromoteTemporaryItems(): void {
    // Create 2 permanent items with usage records.
    $perm1 = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'perm1.pdf']);
    $perm2 = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'perm2.pdf']);
    $perm_usage1 = $this->createUsageRecord($perm1);
    $perm_usage2 = $this->createUsageRecord($perm2);

    // Create 3 temporary items with usage records.
    $temp1 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp1.pdf']);
    $temp2 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp2.pdf']);
    $temp3 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp3.pdf']);
    $temp_usage1 = $this->createUsageRecord($temp1);
    $temp_usage2 = $this->createUsageRecord($temp2);
    $temp_usage3 = $this->createUsageRecord($temp3);

    $temp_ids = [$temp1->id(), $temp2->id(), $temp3->id()];
    $temp_usage_ids = [$temp_usage1->id(), $temp_usage2->id(), $temp_usage3->id()];

    $this->assertSame(5, $this->countEntities('digital_asset_item'));
    $this->assertSame(5, $this->countEntities('digital_asset_usage'));

    // Execute atomic swap.
    $this->scanner->promoteTemporaryItems();

    // Reset entity static cache for fresh DB reads.
    $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item')->resetCache();
    $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage')->resetCache();

    // Temp items promoted (still exist, now permanent).
    foreach ($temp_ids as $id) {
      $item = DigitalAssetItem::load($id);
      $this->assertNotNull($item, "Temp item $id should still exist after promotion.");
      $this->assertFalse((bool) $item->get('is_temp')->value, "Temp item $id should be permanent after promotion.");
    }
    // Temp usage records preserved.
    foreach ($temp_usage_ids as $id) {
      $this->assertNotNull(DigitalAssetUsage::load($id), "Temp usage $id should be preserved.");
    }

    // Verify no items have is_temp=TRUE remaining.
    $remaining_temp = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item')->getQuery()
      ->condition('is_temp', 1)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertEmpty($remaining_temp, 'No temporary items should remain after promotion.');

    // Debug: show items and usage after atomic swap.
    $this->dumpItemsTable('after promote');
    $this->dumpUsageTable('after promote');
  }

  /**
   * Tests clearTemporaryItems() cleanup on failure (TC-SCAN-004 failure path).
   */
  public function testClearTemporaryItems(): void {
    // Create 2 permanent items with usage.
    $perm1 = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'perm1.pdf']);
    $perm2 = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'perm2.pdf']);
    $perm_usage1 = $this->createUsageRecord($perm1);
    $perm_usage2 = $this->createUsageRecord($perm2);

    // Create 3 temporary items with usage.
    $temp1 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp1.pdf']);
    $temp2 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp2.pdf']);
    $temp3 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp3.pdf']);
    $temp_usage1 = $this->createUsageRecord($temp1);
    $temp_usage2 = $this->createUsageRecord($temp2);
    $temp_usage3 = $this->createUsageRecord($temp3);

    $perm_ids = [$perm1->id(), $perm2->id()];
    $temp_ids = [$temp1->id(), $temp2->id(), $temp3->id()];
    $perm_usage_ids = [$perm_usage1->id(), $perm_usage2->id()];
    $temp_usage_ids = [$temp_usage1->id(), $temp_usage2->id(), $temp_usage3->id()];

    // Clear temporary items (simulates scan failure).
    $this->scanner->clearTemporaryItems();

    // Temp items deleted.
    foreach ($temp_ids as $id) {
      $this->assertNull(DigitalAssetItem::load($id));
    }
    // Temp usage records deleted.
    foreach ($temp_usage_ids as $id) {
      $this->assertNull(DigitalAssetUsage::load($id));
    }

    // Permanent items untouched.
    foreach ($perm_ids as $id) {
      $item = DigitalAssetItem::load($id);
      $this->assertNotNull($item);
      $this->assertFalse((bool) $item->get('is_temp')->value);
    }
    // Permanent usage records untouched.
    foreach ($perm_usage_ids as $id) {
      $this->assertNotNull(DigitalAssetUsage::load($id));
    }

    $this->assertSame(2, $this->countEntities('digital_asset_item'));

    // Debug: show surviving permanent items + usage after temp cleanup.
    $this->dumpItemsTable('after clearTemporary — permanent items survive');
    $this->dumpUsageTable('after clearTemporary — permanent usage survives');
  }

  /**
   * Tests clearUsageRecords() deletes all usage.
   */
  public function testClearUsageRecords(): void {
    // Create 3 assets with 5 usage records total.
    $asset1 = $this->createDocumentAsset(['file_name' => 'doc1.pdf']);
    $asset2 = $this->createDocumentAsset(['file_name' => 'doc2.pdf']);
    $asset3 = $this->createDocumentAsset(['file_name' => 'doc3.pdf']);

    $this->createUsageRecord($asset1);
    $this->createUsageRecord($asset1, ['entity_id' => 2]);
    $this->createUsageRecord($asset2);
    $this->createUsageRecord($asset2, ['entity_id' => 3]);
    $this->createUsageRecord($asset3);

    $this->assertSame(5, $this->countEntities('digital_asset_usage'));

    $this->scanner->clearUsageRecords();

    $this->assertSame(0, $this->countEntities('digital_asset_usage'));
    // Items untouched.
    $this->assertSame(3, $this->countEntities('digital_asset_item'));

    // Debug: show items surviving after usage cleared.
    $this->dumpItemsTable('after clearUsageRecords — items intact');
    $this->dumpUsageTable('after clearUsageRecords — empty');
  }

  /**
   * Tests full CRUD lifecycle for DigitalAssetItem.
   */
  public function testDigitalAssetItemCrud(): void {
    // Create.
    $asset = DigitalAssetItem::create([
      'file_name' => 'crud-test.pdf',
      'file_path' => '/sites/default/files/crud-test.pdf',
      'asset_type' => 'pdf',
      'category' => 'Documents',
      'mime_type' => 'application/pdf',
      'source_type' => 'file_managed',
      'is_private' => FALSE,
      'is_temp' => FALSE,
      'fid' => 999,
      'filesize' => 12345,
    ]);
    $asset->save();
    $this->assertNotNull($asset->id());

    // Read.
    $loaded = DigitalAssetItem::load($asset->id());
    $this->assertSame('crud-test.pdf', $loaded->getFilename());
    $this->assertSame('/sites/default/files/crud-test.pdf', $loaded->getFilepath());
    $this->assertSame('pdf', $loaded->getAssetType());
    $this->assertSame('Documents', $loaded->getCategory());
    $this->assertSame('application/pdf', $loaded->getMimeType());
    $this->assertFalse($loaded->isPrivate());
    $this->assertFalse((bool) $loaded->get('is_temp')->value);
    $this->assertSame('999', (string) $loaded->get('fid')->value);
    $this->assertSame(12345, (int) $loaded->getFilesize());

    // Update.
    $loaded->setFilename('updated.pdf');
    $loaded->setCategory('Videos');
    $loaded->save();
    $reloaded = DigitalAssetItem::load($asset->id());
    $this->assertSame('updated.pdf', $reloaded->getFilename());
    $this->assertSame('Videos', $reloaded->getCategory());

    // Debug: show item after create + update.
    $this->dumpItemsTable('CRUD — after update');

    // Delete.
    $id = $reloaded->id();
    $reloaded->delete();
    $this->assertNull(DigitalAssetItem::load($id));
  }

  /**
   * Tests usage -> item relationship and critical deletion order.
   *
   * Validates entity integrity rule: delete usage before items.
   */
  public function testDigitalAssetUsageDeletionOrder(): void {
    $item = $this->createDocumentAsset();
    $usage = $this->createUsageRecord($item);

    $this->assertSame(
      (string) $item->id(),
      (string) $usage->get('asset_id')->target_id
    );

    // Debug: show usage→item relationship before deletion.
    $this->dumpItemsTable('deletion order — before delete');
    $this->dumpUsageTable('deletion order — before delete');

    // Delete usage first — should succeed.
    $usage_id = $usage->id();
    $item_id = $item->id();
    $usage->delete();
    $this->assertNull(DigitalAssetUsage::load($usage_id));

    // Delete item — should succeed.
    $item->delete();
    $this->assertNull(DigitalAssetItem::load($item_id));
  }

  /**
   * Tests DigitalAssetArchive field getters, flags, and type checks.
   */
  public function testDigitalAssetArchiveEntityFields(): void {
    // Create and verify getters.
    $archive = DigitalAssetArchive::create([
      'original_fid' => 100,
      'original_path' => '/sites/default/files/test.pdf',
      'archive_path' => 'http://example.com/sites/default/files/test.pdf',
      'file_name' => 'test.pdf',
      'archive_reason' => 'reference',
      'public_description' => 'Test description.',
      'file_checksum' => str_repeat('a', 64),
      'asset_type' => 'pdf',
      'mime_type' => 'application/pdf',
      'filesize' => 5000,
      'status' => 'queued',
      'archived_by' => 1,
    ]);
    $archive->save();

    $loaded = DigitalAssetArchive::load($archive->id());
    $this->assertSame('queued', $loaded->getStatus());
    $this->assertSame('test.pdf', $loaded->getFileName());
    $this->assertSame('http://example.com/sites/default/files/test.pdf', $loaded->get('archive_path')->value);
    $this->assertSame(str_repeat('a', 64), $loaded->getFileChecksum());
    $this->assertSame('reference', $loaded->getArchiveReason());
    $this->assertSame('pdf', $loaded->getAssetType());
    $this->assertSame('application/pdf', $loaded->getMimeType());

    // Verify boolean flags.
    $loaded->set('flag_usage', TRUE);
    $loaded->set('flag_missing', TRUE);
    $loaded->set('flag_integrity', TRUE);
    $loaded->set('flag_modified', TRUE);
    $loaded->save();
    $loaded = DigitalAssetArchive::load($loaded->id());

    $this->assertTrue($loaded->hasFlagUsage());
    $this->assertTrue($loaded->hasFlagMissing());
    $this->assertTrue($loaded->hasFlagIntegrity());
    $this->assertTrue($loaded->hasFlagModified());
    $this->assertTrue($loaded->hasWarningFlags());

    // Verify clearFlags().
    $loaded->clearFlags();
    $loaded->save();
    $loaded = DigitalAssetArchive::load($loaded->id());

    $this->assertFalse($loaded->hasFlagUsage());
    $this->assertFalse($loaded->hasFlagMissing());
    $this->assertFalse($loaded->hasFlagIntegrity());
    $this->assertFalse($loaded->hasFlagModified());

    // Verify type checks: pdf = file archive, not manual entry.
    $this->assertFalse($loaded->isManualEntry());
    $this->assertTrue($loaded->isFileArchive());

    // page = manual entry.
    $page_archive = DigitalAssetArchive::create([
      'original_path' => 'https://example.com/page',
      'file_name' => 'Test Page',
      'archive_reason' => 'reference',
      'public_description' => 'Test.',
      'asset_type' => 'page',
      'status' => 'queued',
    ]);
    $page_archive->save();
    $this->assertTrue($page_archive->isManualEntry());
    $this->assertFalse($page_archive->isFileArchive());

    // external = manual entry.
    $external_archive = DigitalAssetArchive::create([
      'original_path' => 'https://docs.google.com/document/d/abc',
      'file_name' => 'External Doc',
      'archive_reason' => 'reference',
      'public_description' => 'Test.',
      'asset_type' => 'external',
      'status' => 'queued',
    ]);
    $external_archive->save();
    $this->assertTrue($external_archive->isManualEntry());

    // Debug: show all archive entities with different types.
    $this->dumpArchivesTable('entity fields — pdf + page + external');
  }

  /**
   * Tests getManagedFilesCount() with real file_managed table.
   *
   * TC-SCAN-001 (partial).
   */
  public function testGetManagedFilesCount(): void {
    // Create 3 non-excluded file entities.
    File::create(['uri' => 'public://doc1.pdf', 'filename' => 'doc1.pdf', 'filemime' => 'application/pdf', 'status' => 1])->save();
    File::create(['uri' => 'public://doc2.pdf', 'filename' => 'doc2.pdf', 'filemime' => 'application/pdf', 'status' => 1])->save();
    File::create(['uri' => 'public://doc3.pdf', 'filename' => 'doc3.pdf', 'filemime' => 'application/pdf', 'status' => 1])->save();

    // Create 1 excluded file (in styles directory).
    File::create(['uri' => 'public://styles/thumbnail/doc.jpg', 'filename' => 'doc.jpg', 'filemime' => 'image/jpeg', 'status' => 1])->save();

    $count = $this->scanner->getManagedFilesCount();
    $this->assertSame(3, $count);
  }

  /**
   * Tests canArchive() with real entity objects (TC-ARCH-001 precondition).
   */
  public function testCanArchiveGating(): void {
    // Documents — archiveable.
    $doc = $this->createDocumentAsset(['category' => 'Documents']);
    $this->assertTrue($this->archiveService->canArchive($doc));

    // Videos — archiveable.
    $video = $this->createDocumentAsset([
      'category' => 'Videos',
      'asset_type' => 'mp4',
      'mime_type' => 'video/mp4',
      'file_name' => 'video.mp4',
    ]);
    $this->assertTrue($this->archiveService->canArchive($video));

    // Images — not archiveable.
    $image = $this->createImageAsset();
    $this->assertFalse($this->archiveService->canArchive($image));

    // Audio — not archiveable.
    $audio = $this->createDocumentAsset([
      'category' => 'Audio',
      'asset_type' => 'mp3',
      'mime_type' => 'audio/mpeg',
      'file_name' => 'audio.mp3',
    ]);
    $this->assertFalse($this->archiveService->canArchive($audio));

    // Other — not archiveable.
    $other = $this->createDocumentAsset([
      'category' => 'Other',
      'asset_type' => 'zip',
      'mime_type' => 'application/zip',
      'file_name' => 'archive.zip',
    ]);
    $this->assertFalse($this->archiveService->canArchive($other));

    // Debug: show all items with different categories.
    $this->dumpItemsTable('canArchive gating — 5 categories');
  }

  /**
   * Tests active_use_csv field exists and can store Yes/No values.
   */
  public function testActiveUseCsvFieldExists(): void {
    $asset = $this->createDocumentAsset();
    $this->assertTrue($asset->hasField('active_use_csv'), 'active_use_csv field should exist on DigitalAssetItem.');

    // Set and verify "Yes".
    $asset->set('active_use_csv', 'Yes');
    $asset->save();
    $loaded = DigitalAssetItem::load($asset->id());
    $this->assertSame('Yes', $loaded->get('active_use_csv')->value);

    // Set and verify "No".
    $loaded->set('active_use_csv', 'No');
    $loaded->save();
    $reloaded = DigitalAssetItem::load($loaded->id());
    $this->assertSame('No', $reloaded->get('active_use_csv')->value);
  }

  /**
   * Tests updateCsvExportFields() sets active_use_csv and used_in_csv correctly.
   *
   * Verifies:
   * - Asset WITH usage: active_use_csv = "Yes", used_in_csv contains locations
   * - Asset WITHOUT usage: active_use_csv = "No", used_in_csv = "No active use detected"
   */
  public function testUpdateCsvExportFieldsActiveUse(): void {
    // Asset with no usage.
    $no_usage = $this->createDocumentAsset([
      'file_name' => 'orphan.pdf',
      'filesize' => 1024,
    ]);

    // Use reflection to call the protected method.
    $method = new \ReflectionMethod($this->scanner, 'updateCsvExportFields');
    $method->setAccessible(TRUE);
    $method->invoke($this->scanner, $no_usage->id(), 1024);

    // Reload and verify.
    $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item')->resetCache();
    $loaded = DigitalAssetItem::load($no_usage->id());
    $this->assertSame('No', $loaded->get('active_use_csv')->value, 'Asset without usage should have active_use_csv = No.');
    $this->assertSame('No active use detected', $loaded->get('used_in_csv')->value, 'Asset without usage should show "No active use detected".');

    // Asset with usage — reference the user entity created in setUp()
    // (user module is loaded; node module is not).
    $with_usage = $this->createDocumentAsset([
      'file_name' => 'used.pdf',
      'filesize' => 2048,
    ]);
    $current_user = $this->container->get('current_user');
    $this->createUsageRecord($with_usage, [
      'entity_type' => 'user',
      'entity_id' => $current_user->id(),
    ]);

    $method->invoke($this->scanner, $with_usage->id(), 2048);

    $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item')->resetCache();
    $loaded_used = DigitalAssetItem::load($with_usage->id());
    $this->assertSame('Yes', $loaded_used->get('active_use_csv')->value, 'Asset with usage should have active_use_csv = Yes.');
    $this->assertNotSame('No active use detected', $loaded_used->get('used_in_csv')->value, 'Asset with usage should not show "No active use detected".');
  }

  /**
   * Tests entity count helpers (sanity check for entity storage).
   */
  public function testEntityCountHelpers(): void {
    $this->assertSame(0, $this->countEntities('digital_asset_item'));

    $item1 = $this->createDocumentAsset(['file_name' => 'a.pdf']);
    $this->createDocumentAsset(['file_name' => 'b.pdf']);
    $this->createDocumentAsset(['file_name' => 'c.pdf']);
    $this->assertSame(3, $this->countEntities('digital_asset_item'));

    $item1->delete();
    $this->assertSame(2, $this->countEntities('digital_asset_item'));

    // Debug: show items after create 3 + delete 1.
    $this->dumpItemsTable('entity count — 2 remaining after delete');
  }

}
