<?php

namespace Drupal\Tests\digital_asset_inventory\Kernel;

use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;
use Drupal\digital_asset_inventory\Entity\DigitalAssetOrphanReference;
use Drupal\digital_asset_inventory\Entity\DigitalAssetUsage;
use Drupal\digital_asset_inventory\Service\DigitalAssetScanner;

/**
 * Tests orphan reference entity CRUD and atomic swap integration.
 *
 * Covers:
 * - Entity CRUD for dai_orphan_reference
 * - Atomic swap: promoteTemporaryItems() deletes orphan refs for old items
 * - Atomic swap: clearTemporaryItems() deletes orphan refs for temp items
 * - Deletion order safety: orphan refs → usage → items
 * - Orphan reference field validation
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
class OrphanReferenceKernelTest extends DigitalAssetKernelTestBase {

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
   * Tests full CRUD lifecycle for DigitalAssetOrphanReference.
   */
  public function testOrphanReferenceCrud(): void {
    $asset = $this->createDocumentAsset();

    // Create.
    $orphan_ref = DigitalAssetOrphanReference::create([
      'asset_id' => $asset->id(),
      'source_entity_type' => 'paragraph',
      'source_entity_id' => 42,
      'source_bundle' => 'text',
      'field_name' => 'field_body',
      'embed_method' => 'text_link',
      'reference_context' => 'detached_component',
    ]);
    $orphan_ref->save();
    $this->assertNotNull($orphan_ref->id());

    // Read.
    $loaded = DigitalAssetOrphanReference::load($orphan_ref->id());
    $this->assertSame(
      (string) $asset->id(),
      (string) $loaded->get('asset_id')->target_id,
      'asset_id should reference the correct asset.'
    );
    $this->assertSame('paragraph', $loaded->get('source_entity_type')->value);
    $this->assertSame('42', (string) $loaded->get('source_entity_id')->value);
    $this->assertSame('text', $loaded->get('source_bundle')->value);
    $this->assertSame('field_body', $loaded->get('field_name')->value);
    $this->assertSame('text_link', $loaded->get('embed_method')->value);
    $this->assertSame('detached_component', $loaded->get('reference_context')->value);
    $this->assertNotNull($loaded->get('detected_on')->value, 'detected_on should be auto-populated.');

    // Update.
    $loaded->set('reference_context', 'missing_parent_entity');
    $loaded->save();
    $reloaded = DigitalAssetOrphanReference::load($orphan_ref->id());
    $this->assertSame('missing_parent_entity', $reloaded->get('reference_context')->value);

    // Debug.
    $this->dumpOrphanRefsTable('CRUD — after update');

    // Delete.
    $id = $reloaded->id();
    $reloaded->delete();
    $this->assertNull(DigitalAssetOrphanReference::load($id));
  }

  /**
   * Tests creating orphan reference via the helper method.
   */
  public function testCreateOrphanReferenceHelper(): void {
    $asset = $this->createDocumentAsset();
    $orphan_ref = $this->createOrphanReference($asset);

    $this->assertNotNull($orphan_ref->id());
    $this->assertSame(
      (string) $asset->id(),
      (string) $orphan_ref->get('asset_id')->target_id
    );
    $this->assertSame('paragraph', $orphan_ref->get('source_entity_type')->value);
    $this->assertSame('text', $orphan_ref->get('source_bundle')->value);
    $this->assertSame('detached_component', $orphan_ref->get('reference_context')->value);
  }

  /**
   * Tests creating orphan reference with custom overrides.
   */
  public function testCreateOrphanReferenceWithOverrides(): void {
    $asset = $this->createDocumentAsset();
    $orphan_ref = $this->createOrphanReference($asset, [
      'source_entity_type' => 'paragraph',
      'source_entity_id' => 123,
      'source_bundle' => 'accordion_item',
      'field_name' => 'field_document',
      'embed_method' => 'field_reference',
      'reference_context' => 'missing_parent_entity',
    ]);

    $loaded = DigitalAssetOrphanReference::load($orphan_ref->id());
    $this->assertSame('123', (string) $loaded->get('source_entity_id')->value);
    $this->assertSame('accordion_item', $loaded->get('source_bundle')->value);
    $this->assertSame('field_document', $loaded->get('field_name')->value);
    $this->assertSame('missing_parent_entity', $loaded->get('reference_context')->value);
  }

  /**
   * Tests multiple orphan references for the same asset.
   */
  public function testMultipleOrphanRefsForSameAsset(): void {
    $asset = $this->createDocumentAsset();

    $ref1 = $this->createOrphanReference($asset, ['source_entity_id' => 10]);
    $ref2 = $this->createOrphanReference($asset, ['source_entity_id' => 20]);
    $ref3 = $this->createOrphanReference($asset, ['source_entity_id' => 30]);

    $this->assertSame(3, $this->countEntities('dai_orphan_reference'));

    // All reference the same asset.
    foreach ([$ref1, $ref2, $ref3] as $ref) {
      $this->assertSame(
        (string) $asset->id(),
        (string) $ref->get('asset_id')->target_id
      );
    }

    $this->dumpOrphanRefsTable('multiple refs — same asset');
  }

  /**
   * Tests orphan references across multiple assets.
   */
  public function testOrphanRefsAcrossMultipleAssets(): void {
    $asset1 = $this->createDocumentAsset(['file_name' => 'doc1.pdf']);
    $asset2 = $this->createDocumentAsset(['file_name' => 'doc2.pdf']);

    $this->createOrphanReference($asset1, ['source_entity_id' => 10]);
    $this->createOrphanReference($asset1, ['source_entity_id' => 20]);
    $this->createOrphanReference($asset2, ['source_entity_id' => 30]);

    $this->assertSame(3, $this->countEntities('dai_orphan_reference'));

    // Count by asset using entity query.
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('dai_orphan_reference');

    $asset1_count = (int) $storage->getQuery()
      ->condition('asset_id', $asset1->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(2, $asset1_count);

    $asset2_count = (int) $storage->getQuery()
      ->condition('asset_id', $asset2->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertSame(1, $asset2_count);
  }

  /**
   * Tests orphan ref → asset relationship and deletion order.
   *
   * Validates entity integrity: delete orphan refs before items.
   */
  public function testOrphanRefDeletionOrder(): void {
    $asset = $this->createDocumentAsset();
    $orphan_ref = $this->createOrphanReference($asset);

    $this->assertSame(
      (string) $asset->id(),
      (string) $orphan_ref->get('asset_id')->target_id
    );

    $this->dumpOrphanRefsTable('deletion order — before delete');
    $this->dumpItemsTable('deletion order — before delete');

    // Delete orphan ref first — should succeed.
    $ref_id = $orphan_ref->id();
    $asset_id = $asset->id();
    $orphan_ref->delete();
    $this->assertNull(DigitalAssetOrphanReference::load($ref_id));

    // Delete asset — should succeed.
    $asset->delete();
    $this->assertNull(DigitalAssetItem::load($asset_id));
  }

  /**
   * Tests full deletion order: orphan refs → usage → items.
   */
  public function testFullDeletionOrderOrphanUsageItem(): void {
    $asset = $this->createDocumentAsset();
    $usage = $this->createUsageRecord($asset);
    $orphan_ref = $this->createOrphanReference($asset);

    $this->assertSame(1, $this->countEntities('digital_asset_item'));
    $this->assertSame(1, $this->countEntities('digital_asset_usage'));
    $this->assertSame(1, $this->countEntities('dai_orphan_reference'));

    // Delete in correct order: orphan refs → usage → items.
    $orphan_ref->delete();
    $this->assertSame(0, $this->countEntities('dai_orphan_reference'));

    $usage->delete();
    $this->assertSame(0, $this->countEntities('digital_asset_usage'));

    $asset->delete();
    $this->assertSame(0, $this->countEntities('digital_asset_item'));
  }

  /**
   * Tests promoteTemporaryItems() deletes orphan refs for old permanent items.
   *
   * Verifies atomic swap: old orphan refs are removed, new (temp→permanent)
   * orphan refs are preserved.
   */
  public function testPromoteDeletesOldOrphanRefs(): void {
    // Create 2 permanent (old) items with orphan refs.
    $old1 = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'old1.pdf']);
    $old2 = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'old2.pdf']);
    $old_ref1 = $this->createOrphanReference($old1, ['source_entity_id' => 100]);
    $old_ref2 = $this->createOrphanReference($old2, ['source_entity_id' => 200]);
    $old_usage1 = $this->createUsageRecord($old1);

    // Create 2 temporary (new) items with orphan refs.
    $new1 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'new1.pdf']);
    $new2 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'new2.pdf']);
    $new_ref1 = $this->createOrphanReference($new1, ['source_entity_id' => 300]);
    $new_ref2 = $this->createOrphanReference($new2, ['source_entity_id' => 400]);
    $new_usage1 = $this->createUsageRecord($new1);

    $this->assertSame(4, $this->countEntities('digital_asset_item'));
    $this->assertSame(2, $this->countEntities('digital_asset_usage'));
    $this->assertSame(4, $this->countEntities('dai_orphan_reference'));

    $this->dumpOrphanRefsTable('before promote');

    // Execute atomic swap.
    $this->scanner->promoteTemporaryItems();

    // Reset caches.
    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('digital_asset_item')->resetCache();
    $etm->getStorage('digital_asset_usage')->resetCache();
    $etm->getStorage('dai_orphan_reference')->resetCache();

    // Old items, usage, and orphan refs should be deleted.
    $this->assertNull(DigitalAssetItem::load($old1->id()), 'Old item 1 should be deleted.');
    $this->assertNull(DigitalAssetItem::load($old2->id()), 'Old item 2 should be deleted.');
    $this->assertNull(DigitalAssetUsage::load($old_usage1->id()), 'Old usage should be deleted.');
    $this->assertNull(DigitalAssetOrphanReference::load($old_ref1->id()), 'Old orphan ref 1 should be deleted.');
    $this->assertNull(DigitalAssetOrphanReference::load($old_ref2->id()), 'Old orphan ref 2 should be deleted.');

    // New items promoted to permanent, orphan refs preserved.
    $promoted1 = DigitalAssetItem::load($new1->id());
    $promoted2 = DigitalAssetItem::load($new2->id());
    $this->assertNotNull($promoted1, 'New item 1 should still exist.');
    $this->assertNotNull($promoted2, 'New item 2 should still exist.');
    $this->assertFalse((bool) $promoted1->get('is_temp')->value, 'New item 1 should be permanent.');
    $this->assertFalse((bool) $promoted2->get('is_temp')->value, 'New item 2 should be permanent.');

    $this->assertNotNull(DigitalAssetOrphanReference::load($new_ref1->id()), 'New orphan ref 1 should be preserved.');
    $this->assertNotNull(DigitalAssetOrphanReference::load($new_ref2->id()), 'New orphan ref 2 should be preserved.');
    $this->assertNotNull(DigitalAssetUsage::load($new_usage1->id()), 'New usage should be preserved.');

    // Final counts.
    $this->assertSame(2, $this->countEntities('digital_asset_item'));
    $this->assertSame(1, $this->countEntities('digital_asset_usage'));
    $this->assertSame(2, $this->countEntities('dai_orphan_reference'));

    $this->dumpOrphanRefsTable('after promote — new refs preserved');
    $this->dumpItemsTable('after promote');
  }

  /**
   * Tests clearTemporaryItems() deletes orphan refs for temp items only.
   *
   * Simulates scan failure — temp items cleaned up, permanent items preserved.
   */
  public function testClearTempDeletesTempOrphanRefs(): void {
    // Create 2 permanent items with orphan refs.
    $perm1 = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'perm1.pdf']);
    $perm2 = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'perm2.pdf']);
    $perm_ref1 = $this->createOrphanReference($perm1, ['source_entity_id' => 100]);
    $perm_ref2 = $this->createOrphanReference($perm2, ['source_entity_id' => 200]);
    $perm_usage = $this->createUsageRecord($perm1);

    // Create 2 temporary items with orphan refs.
    $temp1 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp1.pdf']);
    $temp2 = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp2.pdf']);
    $temp_ref1 = $this->createOrphanReference($temp1, ['source_entity_id' => 300]);
    $temp_ref2 = $this->createOrphanReference($temp2, ['source_entity_id' => 400]);
    $temp_usage = $this->createUsageRecord($temp1);

    $this->assertSame(4, $this->countEntities('digital_asset_item'));
    $this->assertSame(2, $this->countEntities('digital_asset_usage'));
    $this->assertSame(4, $this->countEntities('dai_orphan_reference'));

    $this->dumpOrphanRefsTable('before clearTemp');

    // Simulate scan failure — clear temp items.
    $this->scanner->clearTemporaryItems();

    // Reset caches.
    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('digital_asset_item')->resetCache();
    $etm->getStorage('digital_asset_usage')->resetCache();
    $etm->getStorage('dai_orphan_reference')->resetCache();

    // Temp items, usage, and orphan refs should be deleted.
    $this->assertNull(DigitalAssetItem::load($temp1->id()), 'Temp item 1 should be deleted.');
    $this->assertNull(DigitalAssetItem::load($temp2->id()), 'Temp item 2 should be deleted.');
    $this->assertNull(DigitalAssetUsage::load($temp_usage->id()), 'Temp usage should be deleted.');
    $this->assertNull(DigitalAssetOrphanReference::load($temp_ref1->id()), 'Temp orphan ref 1 should be deleted.');
    $this->assertNull(DigitalAssetOrphanReference::load($temp_ref2->id()), 'Temp orphan ref 2 should be deleted.');

    // Permanent items, usage, and orphan refs should be preserved.
    $this->assertNotNull(DigitalAssetItem::load($perm1->id()), 'Perm item 1 should survive.');
    $this->assertNotNull(DigitalAssetItem::load($perm2->id()), 'Perm item 2 should survive.');
    $this->assertNotNull(DigitalAssetUsage::load($perm_usage->id()), 'Perm usage should survive.');
    $this->assertNotNull(DigitalAssetOrphanReference::load($perm_ref1->id()), 'Perm orphan ref 1 should survive.');
    $this->assertNotNull(DigitalAssetOrphanReference::load($perm_ref2->id()), 'Perm orphan ref 2 should survive.');

    // Final counts.
    $this->assertSame(2, $this->countEntities('digital_asset_item'));
    $this->assertSame(1, $this->countEntities('digital_asset_usage'));
    $this->assertSame(2, $this->countEntities('dai_orphan_reference'));

    $this->dumpOrphanRefsTable('after clearTemp — perm refs preserved');
    $this->dumpItemsTable('after clearTemp — perm items survive');
  }

  /**
   * Tests promote with no orphan refs — no errors.
   */
  public function testPromoteWithNoOrphanRefs(): void {
    $old = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'old.pdf']);
    $new = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'new.pdf']);

    $this->assertSame(0, $this->countEntities('dai_orphan_reference'));

    $this->scanner->promoteTemporaryItems();

    $etm = $this->container->get('entity_type.manager');
    $etm->getStorage('digital_asset_item')->resetCache();

    // New item promoted, old deleted.
    $this->assertNull(DigitalAssetItem::load($old->id()));
    $promoted = DigitalAssetItem::load($new->id());
    $this->assertNotNull($promoted);
    $this->assertFalse((bool) $promoted->get('is_temp')->value);
    $this->assertSame(0, $this->countEntities('dai_orphan_reference'));
  }

  /**
   * Tests clearTemp with no orphan refs — no errors.
   */
  public function testClearTempWithNoOrphanRefs(): void {
    $perm = $this->createDocumentAsset(['is_temp' => FALSE, 'file_name' => 'perm.pdf']);
    $temp = $this->createDocumentAsset(['is_temp' => TRUE, 'file_name' => 'temp.pdf']);

    $this->assertSame(0, $this->countEntities('dai_orphan_reference'));

    $this->scanner->clearTemporaryItems();

    $this->assertNotNull(DigitalAssetItem::load($perm->id()), 'Perm item should survive.');
    $this->assertNull(DigitalAssetItem::load($temp->id()), 'Temp item should be deleted.');
    $this->assertSame(0, $this->countEntities('dai_orphan_reference'));
  }

  /**
   * Tests reference_context values are stored correctly.
   */
  public function testReferenceContextValues(): void {
    $asset = $this->createDocumentAsset();
    $contexts = [
      'missing_parent_entity',
      'detached_component',
    ];

    foreach ($contexts as $i => $context) {
      $ref = $this->createOrphanReference($asset, [
        'source_entity_id' => $i + 1,
        'reference_context' => $context,
      ]);
      $loaded = DigitalAssetOrphanReference::load($ref->id());
      $this->assertSame($context, $loaded->get('reference_context')->value,
        "reference_context '$context' should be stored correctly."
      );
    }

    $this->assertSame(2, $this->countEntities('dai_orphan_reference'));
  }

  /**
   * Tests orphan reference detected_on timestamp is auto-populated.
   */
  public function testDetectedOnAutoPopulated(): void {
    $before = \Drupal::time()->getRequestTime();
    $asset = $this->createDocumentAsset();
    $orphan_ref = $this->createOrphanReference($asset);

    $loaded = DigitalAssetOrphanReference::load($orphan_ref->id());
    $detected_on = (int) $loaded->get('detected_on')->value;

    $this->assertGreaterThanOrEqual($before, $detected_on,
      'detected_on should be at or after the test start time.');
    $this->assertLessThanOrEqual($before + 60, $detected_on,
      'detected_on should be within a reasonable window.');
  }

  /**
   * Tests that asset deletion cascades to orphan refs via manual cleanup.
   *
   * In production, the atomic swap handles cleanup. This test verifies
   * the manual deletion order that uninstall and maintenance operations use.
   */
  public function testManualCleanupDeletionOrder(): void {
    $asset1 = $this->createDocumentAsset(['file_name' => 'a.pdf']);
    $asset2 = $this->createDocumentAsset(['file_name' => 'b.pdf']);

    $this->createUsageRecord($asset1);
    $this->createUsageRecord($asset1, ['entity_id' => 2]);
    $this->createUsageRecord($asset2);

    $this->createOrphanReference($asset1, ['source_entity_id' => 10]);
    $this->createOrphanReference($asset1, ['source_entity_id' => 20]);
    $this->createOrphanReference($asset2, ['source_entity_id' => 30]);

    $this->assertSame(2, $this->countEntities('digital_asset_item'));
    $this->assertSame(3, $this->countEntities('digital_asset_usage'));
    $this->assertSame(3, $this->countEntities('dai_orphan_reference'));

    // Simulate uninstall/reset: delete in dependency order.
    // 1. Delete all orphan references.
    $orphan_storage = $this->container->get('entity_type.manager')
      ->getStorage('dai_orphan_reference');
    $all_orphan_ids = $orphan_storage->getQuery()->accessCheck(FALSE)->execute();
    $orphan_storage->delete($orphan_storage->loadMultiple($all_orphan_ids));
    $this->assertSame(0, $this->countEntities('dai_orphan_reference'));

    // 2. Delete all usage records.
    $usage_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage');
    $all_usage_ids = $usage_storage->getQuery()->accessCheck(FALSE)->execute();
    $usage_storage->delete($usage_storage->loadMultiple($all_usage_ids));
    $this->assertSame(0, $this->countEntities('digital_asset_usage'));

    // 3. Delete all items.
    $item_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item');
    $all_item_ids = $item_storage->getQuery()->accessCheck(FALSE)->execute();
    $item_storage->delete($item_storage->loadMultiple($all_item_ids));
    $this->assertSame(0, $this->countEntities('digital_asset_item'));
  }

}
