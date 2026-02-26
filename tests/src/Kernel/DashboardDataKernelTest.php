<?php

namespace Drupal\Tests\digital_asset_inventory\Kernel;

use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;
use Drupal\digital_asset_inventory\Service\DashboardDataService;

/**
 * Kernel tests for the DashboardDataService SQL aggregation methods.
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
class DashboardDataKernelTest extends DigitalAssetKernelTestBase {

  /**
   * The dashboard data service under test.
   */
  protected DashboardDataService $dashboardData;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->dashboardData = $this->container->get('digital_asset_inventory.dashboard_data');
  }

  /**
   * Creates a DigitalAssetArchive entity directly for aggregation testing.
   *
   * Bypasses ArchiveService — we only need rows for SQL aggregation, not full
   * workflow validation.
   *
   * @param array $overrides
   *   Field values to override defaults.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive
   *   The saved archive entity.
   */
  protected function createArchiveRecord(array $overrides = []): DigitalAssetArchive {
    $values = $overrides + [
      'file_name' => 'archived-doc.pdf',
      'original_path' => '/sites/default/files/archived-doc.pdf',
      'archive_reason' => 'reference',
      'public_description' => 'Test archive description.',
      'status' => 'archived_public',
      'asset_type' => 'pdf',
      'flag_late_archive' => 0,
    ];
    $archive = DigitalAssetArchive::create($values);
    $archive->save();
    return $archive;
  }

  // -----------------------------------------------------------------------
  // Empty state tests.
  // -----------------------------------------------------------------------

  /**
   * Tests that all count methods return 0 on an empty database.
   */
  public function testEmptyStateCounts(): void {
    $this->assertSame(0, $this->dashboardData->getTotalAssetCount());
    $this->assertSame(0, $this->dashboardData->getTotalArchivedCount());

    $usage = $this->dashboardData->getUsageBreakdown();
    $this->assertSame(0, $usage['in_use']);
    $this->assertSame(0, $usage['orphan_only']);
    $this->assertSame(0, $usage['unused']);

    $type = $this->dashboardData->getArchiveTypeBreakdown();
    $this->assertSame(0, $type['legacy']);
    $this->assertSame(0, $type['general']);
  }

  /**
   * Tests that all breakdown methods return empty arrays on an empty database.
   */
  public function testEmptyStateBreakdowns(): void {
    $this->assertSame([], $this->dashboardData->getCategoryBreakdown());
    $this->assertSame([], $this->dashboardData->getStorageByCategory());
    $this->assertSame([], $this->dashboardData->getTopAssetsByUsage());
    $this->assertSame([], $this->dashboardData->getLocationBreakdown());
    $this->assertSame([], $this->dashboardData->getArchiveStatusBreakdown());
    $this->assertSame([], $this->dashboardData->getArchiveReasonBreakdown());
  }

  // -----------------------------------------------------------------------
  // getTotalAssetCount() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests that is_temp=1 items are excluded from the total asset count.
   */
  public function testTotalAssetCountExcludesTemp(): void {
    // 3 real assets.
    $this->createDocumentAsset();
    $this->createDocumentAsset(['file_name' => 'doc2.pdf']);
    $this->createImageAsset();

    // 1 temp asset — should be excluded.
    $this->createDocumentAsset([
      'file_name' => 'temp.pdf',
      'is_temp' => 1,
    ]);

    $this->assertSame(3, $this->dashboardData->getTotalAssetCount());
  }

  /**
   * Tests that total asset count spans multiple categories.
   */
  public function testTotalAssetCountMultipleCategories(): void {
    // 2 Documents.
    $this->createDocumentAsset();
    $this->createDocumentAsset(['file_name' => 'doc2.pdf']);

    // 2 Images.
    $this->createImageAsset();
    $this->createDocumentAsset([
      'file_name' => 'photo2.jpg',
      'category' => 'Images',
      'asset_type' => 'jpg',
    ]);

    // 1 Video.
    $this->createDocumentAsset([
      'file_name' => 'video.mp4',
      'category' => 'Videos',
      'asset_type' => 'mp4',
      'sort_order' => 2,
    ]);

    $this->assertSame(5, $this->dashboardData->getTotalAssetCount());
  }

  // -----------------------------------------------------------------------
  // getTotalArchivedCount() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests that only archived_public/archived_admin statuses are counted.
   */
  public function testTotalArchivedCountActiveOnly(): void {
    $this->createArchiveRecord(['status' => 'archived_public']);
    $this->createArchiveRecord(['status' => 'archived_admin', 'file_name' => 'admin.pdf']);
    $this->createArchiveRecord(['status' => 'queued', 'file_name' => 'queued.pdf']);
    $this->createArchiveRecord(['status' => 'archived_deleted', 'file_name' => 'deleted.pdf']);
    $this->createArchiveRecord(['status' => 'exemption_void', 'file_name' => 'void.pdf']);

    $this->assertSame(2, $this->dashboardData->getTotalArchivedCount());
  }

  /**
   * Tests that non-active statuses yield 0.
   */
  public function testTotalArchivedCountNoActive(): void {
    $this->createArchiveRecord(['status' => 'queued', 'file_name' => 'queued.pdf']);
    $this->createArchiveRecord(['status' => 'archived_deleted', 'file_name' => 'deleted.pdf']);

    $this->assertSame(0, $this->dashboardData->getTotalArchivedCount());
  }

  // -----------------------------------------------------------------------
  // getCategoryBreakdown() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests GROUP BY and ORDER BY sort_order in category breakdown.
   */
  public function testCategoryBreakdownGrouping(): void {
    // 3 Documents (sort_order=1).
    $this->createDocumentAsset(['sort_order' => 1]);
    $this->createDocumentAsset(['file_name' => 'doc2.pdf', 'sort_order' => 1]);
    $this->createDocumentAsset(['file_name' => 'doc3.pdf', 'sort_order' => 1]);

    // 1 Video (sort_order=2).
    $this->createDocumentAsset([
      'file_name' => 'video.mp4',
      'category' => 'Videos',
      'sort_order' => 2,
    ]);

    // 2 Images (sort_order=4).
    $this->createDocumentAsset([
      'file_name' => 'img1.jpg',
      'category' => 'Images',
      'sort_order' => 4,
    ]);
    $this->createDocumentAsset([
      'file_name' => 'img2.jpg',
      'category' => 'Images',
      'sort_order' => 4,
    ]);

    $breakdown = $this->dashboardData->getCategoryBreakdown();

    $this->assertCount(3, $breakdown);
    // Ordered by sort_order: Documents(1), Videos(2), Images(4).
    $this->assertSame('Documents', $breakdown[0]['category']);
    $this->assertSame(3, $breakdown[0]['count']);
    $this->assertSame('Videos', $breakdown[1]['category']);
    $this->assertSame(1, $breakdown[1]['count']);
    $this->assertSame('Images', $breakdown[2]['category']);
    $this->assertSame(2, $breakdown[2]['count']);
  }

  /**
   * Tests that temp items are excluded from category breakdown.
   */
  public function testCategoryBreakdownExcludesTemp(): void {
    $this->createDocumentAsset(['sort_order' => 1]);
    $this->createDocumentAsset(['file_name' => 'doc2.pdf', 'sort_order' => 1]);
    // Temp document — excluded.
    $this->createDocumentAsset([
      'file_name' => 'temp.pdf',
      'is_temp' => 1,
      'sort_order' => 1,
    ]);

    $breakdown = $this->dashboardData->getCategoryBreakdown();
    $this->assertCount(1, $breakdown);
    $this->assertSame(2, $breakdown[0]['count']);
  }

  /**
   * Tests secondary alphabetical sort when sort_order is the same.
   */
  public function testCategoryBreakdownSortOrder(): void {
    // Audio and Other both at sort_order=5.
    $this->createDocumentAsset([
      'file_name' => 'sound.mp3',
      'category' => 'Audio',
      'sort_order' => 5,
    ]);
    $this->createDocumentAsset([
      'file_name' => 'archive.zip',
      'category' => 'Other',
      'sort_order' => 5,
    ]);

    $breakdown = $this->dashboardData->getCategoryBreakdown();
    $this->assertCount(2, $breakdown);
    // "Audio" before "Other" alphabetically.
    $this->assertSame('Audio', $breakdown[0]['category']);
    $this->assertSame('Other', $breakdown[1]['category']);
  }

  // -----------------------------------------------------------------------
  // getUsageBreakdown() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests that an asset with a usage record is classified as in_use.
   */
  public function testUsageBreakdownInUse(): void {
    $used = $this->createDocumentAsset();
    $this->createUsageRecord($used);
    $this->createDocumentAsset(['file_name' => 'unused.pdf']);

    $breakdown = $this->dashboardData->getUsageBreakdown();
    $this->assertSame(1, $breakdown['in_use']);
    $this->assertSame(0, $breakdown['orphan_only']);
    $this->assertSame(1, $breakdown['unused']);
  }

  /**
   * Tests that an asset with only orphan refs is classified as orphan_only.
   */
  public function testUsageBreakdownOrphanOnly(): void {
    $asset = $this->createDocumentAsset();
    $this->createOrphanReference($asset);

    $breakdown = $this->dashboardData->getUsageBreakdown();
    $this->assertSame(0, $breakdown['in_use']);
    $this->assertSame(1, $breakdown['orphan_only']);
    $this->assertSame(0, $breakdown['unused']);
  }

  /**
   * Tests mixed usage classifications.
   */
  public function testUsageBreakdownMixed(): void {
    // 1 used.
    $used = $this->createDocumentAsset();
    $this->createUsageRecord($used);

    // 1 orphan_only.
    $orphan = $this->createDocumentAsset(['file_name' => 'orphan.pdf']);
    $this->createOrphanReference($orphan);

    // 1 unused.
    $this->createDocumentAsset(['file_name' => 'unused.pdf']);

    $breakdown = $this->dashboardData->getUsageBreakdown();
    $this->assertSame(1, $breakdown['in_use']);
    $this->assertSame(1, $breakdown['orphan_only']);
    $this->assertSame(1, $breakdown['unused']);
  }

  /**
   * Tests that temp assets with usage are excluded from usage breakdown.
   */
  public function testUsageBreakdownExcludesTemp(): void {
    // 1 real unused asset.
    $this->createDocumentAsset();

    // 1 temp asset with usage — should be excluded.
    $temp = $this->createDocumentAsset([
      'file_name' => 'temp.pdf',
      'is_temp' => 1,
    ]);
    $this->createUsageRecord($temp);

    $breakdown = $this->dashboardData->getUsageBreakdown();
    $this->assertSame(0, $breakdown['in_use']);
    $this->assertSame(1, $breakdown['unused']);
  }

  // -----------------------------------------------------------------------
  // getStorageByCategory() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests SUM(filesize) grouped by category.
   */
  public function testStorageByCategorySum(): void {
    $this->createDocumentAsset(['filesize' => 1024, 'sort_order' => 1]);
    $this->createDocumentAsset([
      'file_name' => 'doc2.pdf',
      'filesize' => 1024,
      'sort_order' => 1,
    ]);
    $this->createDocumentAsset([
      'file_name' => 'photo.jpg',
      'category' => 'Images',
      'filesize' => 2048,
      'sort_order' => 4,
    ]);

    $storage = $this->dashboardData->getStorageByCategory();
    $this->assertCount(2, $storage);

    // Ordered by total_bytes DESC: Images(2048) and Documents(2048) — equal,
    // so order depends on DB. Just check totals.
    $totals = [];
    foreach ($storage as $item) {
      $totals[$item['category']] = $item['total_bytes'];
    }
    $this->assertSame(2048, $totals['Documents']);
    $this->assertSame(2048, $totals['Images']);
  }

  /**
   * Tests that NULL and zero filesize are excluded from storage.
   */
  public function testStorageByCategoryExcludesNullAndZero(): void {
    // NULL filesize — excluded.
    $this->createDocumentAsset(['sort_order' => 1]);
    // Zero filesize — excluded.
    $this->createDocumentAsset([
      'file_name' => 'zero.pdf',
      'filesize' => 0,
      'sort_order' => 1,
    ]);
    // Valid filesize.
    $this->createDocumentAsset([
      'file_name' => 'valid.pdf',
      'filesize' => 500,
      'sort_order' => 1,
    ]);

    $storage = $this->dashboardData->getStorageByCategory();
    $this->assertCount(1, $storage);
    $this->assertSame(500, $storage[0]['total_bytes']);
  }

  /**
   * Tests that temp items are excluded from storage.
   */
  public function testStorageByCategoryExcludesTemp(): void {
    $this->createDocumentAsset(['filesize' => 1000, 'sort_order' => 1]);
    $this->createDocumentAsset([
      'file_name' => 'temp.pdf',
      'filesize' => 9999,
      'is_temp' => 1,
      'sort_order' => 1,
    ]);

    $storage = $this->dashboardData->getStorageByCategory();
    $this->assertCount(1, $storage);
    $this->assertSame(1000, $storage[0]['total_bytes']);
  }

  // -----------------------------------------------------------------------
  // getTopAssetsByUsage() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests ORDER BY usage_count DESC in top assets.
   */
  public function testTopAssetsByUsageOrdering(): void {
    $a1 = $this->createDocumentAsset(['file_name' => 'top.pdf']);
    $a2 = $this->createDocumentAsset(['file_name' => 'mid.pdf']);
    $a3 = $this->createDocumentAsset(['file_name' => 'low.pdf']);

    // 5 usages for a1.
    for ($i = 0; $i < 5; $i++) {
      $this->createUsageRecord($a1, ['entity_id' => $i + 1]);
    }
    // 3 usages for a2.
    for ($i = 0; $i < 3; $i++) {
      $this->createUsageRecord($a2, ['entity_id' => $i + 100]);
    }
    // 1 usage for a3.
    $this->createUsageRecord($a3, ['entity_id' => 200]);

    $top = $this->dashboardData->getTopAssetsByUsage(10);
    $this->assertCount(3, $top);
    $this->assertSame('top.pdf', $top[0]['file_name']);
    $this->assertSame(5, $top[0]['usage_count']);
    $this->assertSame('mid.pdf', $top[1]['file_name']);
    $this->assertSame(3, $top[1]['usage_count']);
    $this->assertSame('low.pdf', $top[2]['file_name']);
    $this->assertSame(1, $top[2]['usage_count']);
  }

  /**
   * Tests LIMIT and temp exclusion in top assets.
   */
  public function testTopAssetsByUsageLimitAndExcludesTemp(): void {
    // 4 real assets with usage.
    for ($i = 1; $i <= 4; $i++) {
      $asset = $this->createDocumentAsset(['file_name' => "real-$i.pdf"]);
      $this->createUsageRecord($asset, ['entity_id' => $i]);
    }

    // 1 temp asset with usage — should be excluded.
    $temp = $this->createDocumentAsset([
      'file_name' => 'temp.pdf',
      'is_temp' => 1,
    ]);
    $this->createUsageRecord($temp, ['entity_id' => 99]);

    $top = $this->dashboardData->getTopAssetsByUsage(2);
    $this->assertCount(2, $top);

    // Verify no temp asset in results.
    foreach ($top as $item) {
      $this->assertNotSame('temp.pdf', $item['file_name']);
    }
  }

  // -----------------------------------------------------------------------
  // getLocationBreakdown() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests source_type to human label mapping.
   */
  public function testLocationBreakdownLabelMapping(): void {
    $this->createDocumentAsset(['source_type' => 'file_managed']);
    $this->createDocumentAsset([
      'file_name' => 'media.pdf',
      'source_type' => 'media_managed',
    ]);
    $this->createDocumentAsset([
      'file_name' => 'server.pdf',
      'source_type' => 'filesystem_only',
    ]);
    $this->createDocumentAsset([
      'file_name' => 'ext.pdf',
      'source_type' => 'external',
    ]);

    $breakdown = $this->dashboardData->getLocationBreakdown();
    $labels = array_column($breakdown, 'source_type');

    $this->assertContains('Upload', $labels);
    $this->assertContains('Media', $labels);
    $this->assertContains('Server', $labels);
    $this->assertContains('External', $labels);
  }

  /**
   * Tests ORDER BY count DESC in location breakdown.
   */
  public function testLocationBreakdownOrderByCount(): void {
    // 3 uploads.
    $this->createDocumentAsset(['source_type' => 'file_managed']);
    $this->createDocumentAsset(['file_name' => 'u2.pdf', 'source_type' => 'file_managed']);
    $this->createDocumentAsset(['file_name' => 'u3.pdf', 'source_type' => 'file_managed']);
    // 2 media.
    $this->createDocumentAsset(['file_name' => 'm1.pdf', 'source_type' => 'media_managed']);
    $this->createDocumentAsset(['file_name' => 'm2.pdf', 'source_type' => 'media_managed']);
    // 1 external.
    $this->createDocumentAsset(['file_name' => 'e1.pdf', 'source_type' => 'external']);

    $breakdown = $this->dashboardData->getLocationBreakdown();
    $this->assertSame('Upload', $breakdown[0]['source_type']);
    $this->assertSame(3, $breakdown[0]['count']);
    $this->assertSame('Media', $breakdown[1]['source_type']);
    $this->assertSame(2, $breakdown[1]['count']);
    $this->assertSame('External', $breakdown[2]['source_type']);
    $this->assertSame(1, $breakdown[2]['count']);
  }

  // -----------------------------------------------------------------------
  // getArchiveStatusBreakdown() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests status to human label mapping.
   */
  public function testArchiveStatusBreakdownLabelMapping(): void {
    $this->createArchiveRecord(['status' => 'queued', 'file_name' => 'q.pdf']);
    $this->createArchiveRecord(['status' => 'archived_public', 'file_name' => 'pub.pdf']);
    $this->createArchiveRecord(['status' => 'archived_admin', 'file_name' => 'adm.pdf']);
    $this->createArchiveRecord(['status' => 'archived_deleted', 'file_name' => 'del.pdf']);
    $this->createArchiveRecord(['status' => 'exemption_void', 'file_name' => 'void.pdf']);

    $breakdown = $this->dashboardData->getArchiveStatusBreakdown();
    $labels = array_column($breakdown, 'status');

    $this->assertContains('Queued', $labels);
    $this->assertContains('Archived (Public)', $labels);
    $this->assertContains('Archived (Admin-Only)', $labels);
    $this->assertContains('Archived (Deleted)', $labels);
    $this->assertContains('Exemption Void', $labels);
  }

  /**
   * Tests correct counts per archive status.
   */
  public function testArchiveStatusBreakdownCounts(): void {
    // 3 public.
    $this->createArchiveRecord(['file_name' => 'pub1.pdf']);
    $this->createArchiveRecord(['file_name' => 'pub2.pdf']);
    $this->createArchiveRecord(['file_name' => 'pub3.pdf']);
    // 2 queued.
    $this->createArchiveRecord(['status' => 'queued', 'file_name' => 'q1.pdf']);
    $this->createArchiveRecord(['status' => 'queued', 'file_name' => 'q2.pdf']);

    $breakdown = $this->dashboardData->getArchiveStatusBreakdown();
    // Ordered by count DESC: public(3), queued(2).
    $this->assertSame('Archived (Public)', $breakdown[0]['status']);
    $this->assertSame(3, $breakdown[0]['count']);
    $this->assertSame('Queued', $breakdown[1]['status']);
    $this->assertSame(2, $breakdown[1]['count']);
  }

  // -----------------------------------------------------------------------
  // getArchiveTypeBreakdown() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests legacy vs general archive classification.
   */
  public function testArchiveTypeBreakdownLegacyVsGeneral(): void {
    // 3 legacy (flag_late_archive=0).
    $this->createArchiveRecord(['flag_late_archive' => 0, 'file_name' => 'l1.pdf']);
    $this->createArchiveRecord(['flag_late_archive' => 0, 'file_name' => 'l2.pdf']);
    $this->createArchiveRecord(['flag_late_archive' => 0, 'file_name' => 'l3.pdf']);

    // 1 general (flag_late_archive=1).
    $this->createArchiveRecord(['flag_late_archive' => 1, 'file_name' => 'g1.pdf']);

    $type = $this->dashboardData->getArchiveTypeBreakdown();
    $this->assertSame(3, $type['legacy']);
    $this->assertSame(1, $type['general']);
  }

  /**
   * Tests that non-active statuses are excluded from archive type counts.
   */
  public function testArchiveTypeBreakdownExcludesNonActive(): void {
    // Active.
    $this->createArchiveRecord(['status' => 'archived_public', 'flag_late_archive' => 0]);

    // Non-active — should be excluded.
    $this->createArchiveRecord([
      'status' => 'queued',
      'flag_late_archive' => 0,
      'file_name' => 'q.pdf',
    ]);
    $this->createArchiveRecord([
      'status' => 'archived_deleted',
      'flag_late_archive' => 1,
      'file_name' => 'd.pdf',
    ]);

    $type = $this->dashboardData->getArchiveTypeBreakdown();
    $this->assertSame(1, $type['legacy']);
    $this->assertSame(0, $type['general']);
  }

  // -----------------------------------------------------------------------
  // getArchiveReasonBreakdown() tests.
  // -----------------------------------------------------------------------

  /**
   * Tests that only active statuses are counted in reason breakdown.
   */
  public function testArchiveReasonBreakdownActiveOnly(): void {
    // 2 active reference.
    $this->createArchiveRecord(['archive_reason' => 'reference', 'file_name' => 'r1.pdf']);
    $this->createArchiveRecord(['archive_reason' => 'reference', 'file_name' => 'r2.pdf']);

    // 1 active research.
    $this->createArchiveRecord([
      'archive_reason' => 'research',
      'status' => 'archived_admin',
      'file_name' => 'res.pdf',
    ]);

    // Non-active — excluded.
    $this->createArchiveRecord([
      'archive_reason' => 'reference',
      'status' => 'queued',
      'file_name' => 'q.pdf',
    ]);
    $this->createArchiveRecord([
      'archive_reason' => 'research',
      'status' => 'archived_deleted',
      'file_name' => 'd.pdf',
    ]);

    $reasons = $this->dashboardData->getArchiveReasonBreakdown();
    $this->assertCount(2, $reasons);

    $totals = [];
    foreach ($reasons as $item) {
      $totals[$item['reason']] = $item['count'];
    }
    $this->assertSame(2, $totals['Reference']);
    $this->assertSame(1, $totals['Research']);
  }

  /**
   * Tests reason to human label mapping.
   */
  public function testArchiveReasonBreakdownLabelMapping(): void {
    $this->createArchiveRecord(['archive_reason' => 'reference', 'file_name' => 'r.pdf']);
    $this->createArchiveRecord(['archive_reason' => 'research', 'file_name' => 'res.pdf']);
    $this->createArchiveRecord(['archive_reason' => 'recordkeeping', 'file_name' => 'rk.pdf']);
    $this->createArchiveRecord(['archive_reason' => 'other', 'file_name' => 'o.pdf']);

    $reasons = $this->dashboardData->getArchiveReasonBreakdown();
    $labels = array_column($reasons, 'reason');

    $this->assertContains('Reference', $labels);
    $this->assertContains('Research', $labels);
    $this->assertContains('Recordkeeping', $labels);
    $this->assertContains('Other', $labels);
  }

  /**
   * Tests ORDER BY count DESC in reason breakdown.
   */
  public function testArchiveReasonBreakdownOrderByCount(): void {
    // 3 reference.
    $this->createArchiveRecord(['archive_reason' => 'reference', 'file_name' => 'r1.pdf']);
    $this->createArchiveRecord(['archive_reason' => 'reference', 'file_name' => 'r2.pdf']);
    $this->createArchiveRecord(['archive_reason' => 'reference', 'file_name' => 'r3.pdf']);

    // 1 research.
    $this->createArchiveRecord(['archive_reason' => 'research', 'file_name' => 'res.pdf']);

    $reasons = $this->dashboardData->getArchiveReasonBreakdown();
    $this->assertSame('Reference', $reasons[0]['reason']);
    $this->assertSame(3, $reasons[0]['count']);
    $this->assertSame('Research', $reasons[1]['reason']);
    $this->assertSame(1, $reasons[1]['count']);
  }

}
