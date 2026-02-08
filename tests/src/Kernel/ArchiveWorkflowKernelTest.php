<?php

namespace Drupal\Tests\digital_asset_inventory\Kernel;

use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;

/**
 * Tests archive workflow state machine transitions with real entities.
 *
 * Covers Groups A (state machine), B (archive type), and C (usage policy).
 * 13 test cases covering the full archive lifecycle.
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
class ArchiveWorkflowKernelTest extends DigitalAssetKernelTestBase {

  /**
   * Tests queue and execute as public (F1 + F2).
   *
   * TC-ARCH-001, TC-ARCH-002.
   */
  public function testQueueAndExecutePublic(): void {
    $asset = $this->createDocumentAssetWithFile();

    // Step 1: Queue.
    $archive = $this->archiveService->markForArchive(
      $asset, 'reference', '', 'Test description.'
    );
    $this->assertSame('queued', $archive->getStatus());
    $this->assertSame(
      (string) $this->container->get('current_user')->id(),
      (string) $archive->get('archived_by')->target_id
    );
    $this->assertSame('test-doc.pdf', $archive->getFileName());
    $this->assertSame('reference', $archive->getArchiveReason());

    // Step 2: Execute as public.
    $this->archiveService->executeArchive($archive, 'public');
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertSame('archived_public', $archive->getStatus());
    $this->assertNotNull($archive->get('archive_classification_date')->value);
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $archive->getFileChecksum());
    $this->assertTrue($archive->isArchivedPublic());
    $this->assertTrue($archive->isArchivedActive());

    // Debug: show all three tables after a full queue+execute cycle.
    $this->dumpArchivesTable('after execute');
    $this->dumpItemsTable();
    $this->dumpUsageTable();
  }

  /**
   * Tests queue and execute as admin (F1 + F3).
   *
   * TC-ARCH-003.
   */
  public function testQueueAndExecuteAdmin(): void {
    $asset = $this->createDocumentAssetWithFile();

    $archive = $this->queueAndExecute($asset, 'admin');

    $this->assertSame('archived_admin', $archive->getStatus());
    $this->assertTrue($archive->isArchivedAdmin());
    $this->assertTrue($archive->isArchivedActive());
    $this->assertFalse($archive->isArchivedPublic());

    // Debug: show archive state after admin execute.
    $this->dumpArchivesTable('after execute admin');
  }

  /**
   * Tests execute blocked by usage policy (F2 blocked + AIU-3).
   *
   * TC-ARCH-005, TC-AIU-003.
   */
  public function testExecuteBlockedByUsage(): void {
    $asset = $this->createDocumentAssetWithFile();
    $archive = $this->queueAsset($asset);
    $this->createUsageRecord($asset);

    // Ensure allow_archive_in_use is FALSE (default).
    $this->disableArchiveInUse();

    // Validate gates should report usage_policy_blocked.
    $gates = $this->archiveService->validateExecutionGates($archive);
    $this->assertNotEmpty($gates);
    $this->assertArrayHasKey('usage_policy_blocked', $gates);
    $this->assertGreaterThan(0, $gates['usage_policy_blocked']['usage_count']);

    // Debug: show state when usage blocks execution.
    $this->dumpArchivesTable('execute blocked by usage');
    $this->dumpUsageTable();

    // Execute should throw exception.
    $this->expectException(\Exception::class);
    $this->archiveService->executeArchive($archive, 'public');
  }

  /**
   * Tests toggle visibility between public and admin (F5 + F6).
   *
   * TC-ARCH-004.
   */
  public function testToggleVisibility(): void {
    $asset = $this->createDocumentAssetWithFile();
    $archive = $this->queueAndExecute($asset, 'public');

    $this->assertTrue($archive->isArchivedPublic());

    // Toggle public -> admin.
    $this->archiveService->toggleVisibility($archive);
    $archive = DigitalAssetArchive::load($archive->id());
    $this->assertTrue($archive->isArchivedAdmin());

    // Toggle admin -> public.
    $this->archiveService->toggleVisibility($archive);
    $archive = DigitalAssetArchive::load($archive->id());
    $this->assertTrue($archive->isArchivedPublic());

    // Debug: show archive after full toggle cycle.
    $this->dumpArchivesTable('after toggle public→admin→public');
  }

  /**
   * Tests unarchive transitions to archived_deleted and clears flags (F7 + F8).
   *
   * TC-ARCH-007.
   */
  public function testUnarchive(): void {
    $asset = $this->createDocumentAssetWithFile();
    $archive = $this->queueAndExecute($asset, 'public');

    $this->archiveService->unarchive($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertSame('archived_deleted', $archive->getStatus());
    $this->assertTrue($archive->isArchivedDeleted());
    // Entity still exists (preserved for audit trail).
    $this->assertNotNull(DigitalAssetArchive::load($archive->id()));
    // Flags cleared.
    $this->assertFalse($archive->hasFlagUsage());
    $this->assertFalse($archive->hasFlagMissing());
    $this->assertFalse($archive->hasFlagIntegrity());

    // Debug: show archive after unarchive.
    $this->dumpArchivesTable('after unarchive from public');
  }

  /**
   * Tests remove from queue deletes entity entirely (F4).
   *
   * TC-ARCH-008.
   */
  public function testRemoveFromQueue(): void {
    $asset = $this->createDocumentAsset();
    $archive = $this->queueAsset($asset);
    $archive_id = $archive->id();

    // Debug: show queued state before removal.
    $this->dumpArchivesTable('before remove from queue');

    $this->archiveService->removeFromQueue($archive);

    $this->assertNull(DigitalAssetArchive::load($archive_id));

    // Debug: confirm empty table after removal.
    $this->dumpArchivesTable('after remove from queue');
  }

  /**
   * Tests terminal states block operations (F15 + F16).
   *
   * TC-VOID-007.
   */
  public function testTerminalStatesBlockOperations(): void {
    // Create two assets with files.
    $asset1 = $this->createDocumentAssetWithFile('term-test-1.pdf');
    $asset2 = $this->createDocumentAssetWithFile('term-test-2.pdf');

    $archive1 = $this->queueAndExecute($asset1, 'public');
    $archive2 = $this->queueAndExecute($asset2, 'public');

    // Unarchive first -> archived_deleted.
    $this->archiveService->unarchive($archive1);
    $archive1 = DigitalAssetArchive::load($archive1->id());

    // Manually set second to exemption_void.
    $archive2->setStatus('exemption_void');
    $archive2->save();
    $archive2 = DigitalAssetArchive::load($archive2->id());

    // archived_deleted: all operations blocked.
    $this->assertFalse($archive1->canExecuteArchive());
    $this->assertFalse($archive1->canUnarchive());
    $this->assertFalse($archive1->canToggleVisibility());
    $this->assertFalse($archive1->canRemoveFromQueue());

    // exemption_void: only unarchive is allowed (corrective action).
    $this->assertFalse($archive2->canExecuteArchive());
    $this->assertTrue($archive2->canUnarchive());
    $this->assertFalse($archive2->canToggleVisibility());
    $this->assertFalse($archive2->canRemoveFromQueue());

    // Debug: show both terminal state archives.
    $this->dumpArchivesTable('terminal states — archived_deleted + exemption_void');
  }

  /**
   * Tests Legacy Archive classification before deadline (AT-1).
   *
   * TC-DUAL-001.
   */
  public function testLegacyArchiveBeforeDeadline(): void {
    $this->setLegacyMode();
    $asset = $this->createDocumentAssetWithFile();

    $archive = $this->queueAndExecute($asset);

    $this->assertFalse($archive->hasFlagLateArchive());
    $this->assertTrue($this->archiveService->isLegacyArchive($archive));

    // Debug: show Legacy Archive classification.
    $this->dumpArchivesTable('Legacy Archive — before deadline');
  }

  /**
   * Tests General Archive classification after deadline (AT-2).
   *
   * TC-DUAL-002.
   */
  public function testGeneralArchiveAfterDeadline(): void {
    $this->setGeneralMode();
    $asset = $this->createDocumentAssetWithFile();

    $archive = $this->queueAndExecute($asset);

    $this->assertTrue($archive->hasFlagLateArchive());
    $this->assertFalse($this->archiveService->isLegacyArchive($archive));

    // Debug: show General Archive classification.
    $this->dumpArchivesTable('General Archive — after deadline');
  }

  /**
   * Tests archive succeeds when allow_archive_in_use is enabled (AIU-20 + AIU-21).
   *
   * TC-AIU-004, TC-AIU-005.
   */
  public function testArchiveInUseAllowed(): void {
    $this->enableArchiveInUse();
    $asset = $this->createDocumentAssetWithFile();
    $this->createUsageRecord($asset);

    // Queue succeeds.
    $archive = $this->queueAsset($asset);
    $this->assertSame('queued', $archive->getStatus());

    // Execute succeeds.
    $this->archiveService->executeArchive($archive, 'public');
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertSame('archived_public', $archive->getStatus());
    $this->assertTrue((bool) $archive->get('archived_while_in_use')->value);
    $this->assertGreaterThan(0, (int) $archive->get('usage_count_at_archive')->value);

    // Debug: show archive + usage state for in-use archive.
    $this->dumpArchivesTable('archive in use allowed');
    $this->dumpUsageTable();
  }

  /**
   * Tests visibility toggle blocked when in use + config disabled (EC7).
   *
   * TC-AIU-006.
   */
  public function testToggleVisibilityBlockedWhenInUse(): void {
    $this->enableArchiveInUse();
    $asset = $this->createDocumentAssetWithFile();
    $this->createUsageRecord($asset);

    // Execute as admin (works because in-use is allowed).
    $archive = $this->queueAndExecute($asset, 'admin');

    // Now disable archive in use.
    $this->disableArchiveInUse();

    $blocked = $this->archiveService->isVisibilityToggleBlocked($archive);
    $this->assertNotNull($blocked);
    $this->assertArrayHasKey('usage_count', $blocked);
    $this->assertGreaterThan(0, $blocked['usage_count']);
    $this->assertArrayHasKey('reason', $blocked);
    $this->assertNotEmpty($blocked['reason']);

    // Debug: show archive + usage state when toggle is blocked.
    $this->dumpArchivesTable('toggle blocked by usage');
    $this->dumpUsageTable();
  }

  /**
   * Tests unarchive always allowed even with in-use + config disabled (EC1 + AIU-5).
   *
   * TC-AIU-008.
   */
  public function testUnarchiveAlwaysAllowed(): void {
    $this->enableArchiveInUse();
    $asset = $this->createDocumentAssetWithFile();
    $this->createUsageRecord($asset);

    $archive = $this->queueAndExecute($asset, 'public');

    // Disable config.
    $this->disableArchiveInUse();

    // Unarchive still succeeds.
    $this->archiveService->unarchive($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertSame('archived_deleted', $archive->getStatus());

    // Debug: show archive state after unarchive with config disabled.
    $this->dumpArchivesTable('unarchive always allowed — config disabled');
  }

  /**
   * Tests usage matching by FID and by file_path.
   *
   * Validates that getUsageCountByArchive() works via both FID-based and
   * path-based matching strategies.
   */
  public function testUsageMatchingByFidAndPath(): void {
    // Case 1: Match by FID.
    $uri = $this->createTestFile('doc-a.pdf');
    $file = $this->createFileEntity($uri);
    $asset1 = $this->createDocumentAsset([
      'file_name' => 'doc-a.pdf',
      'file_path' => '/sites/default/files/doc-a.pdf',
      'fid' => $file->id(),
    ]);
    $this->createUsageRecord($asset1);
    $archive1 = $this->queueAsset($asset1);

    $count1 = $this->archiveService->getUsageCountByArchive($archive1);
    $this->assertSame(1, $count1);

    // Case 2: Match by path (no FID).
    $asset2 = $this->createDocumentAsset([
      'file_name' => 'doc-b.pdf',
      'file_path' => '/sites/default/files/doc-b.pdf',
    ]);
    $this->createUsageRecord($asset2);
    $archive2 = $this->queueAsset($asset2);

    $count2 = $this->archiveService->getUsageCountByArchive($archive2);
    $this->assertSame(1, $count2);

    // Debug: show archives + usage for both matching strategies.
    $this->dumpArchivesTable('usage matching by FID and path');
    $this->dumpUsageTable();
    $this->dumpItemsTable();
  }

  /**
   * Tests warning flags survive visibility toggle.
   *
   * Toggling public↔admin should not clear warning flags.
   */
  public function testWarningFlagsSurviveVisibilityToggle(): void {
    $asset = $this->createDocumentAssetWithFile();
    $this->createUsageRecord($asset);

    // Archive in-use so flag_usage gets set during execute.
    $this->enableArchiveInUse();
    $archive = $this->queueAndExecute($asset, 'public');
    $this->assertTrue($archive->hasFlagUsage());

    // Toggle public → admin.
    $this->archiveService->toggleVisibility($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertTrue($archive->isArchivedAdmin());
    $this->assertTrue($archive->hasFlagUsage(), 'flag_usage should survive toggle to admin.');

    // Toggle admin → public.
    $this->archiveService->toggleVisibility($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertTrue($archive->isArchivedPublic());
    $this->assertTrue($archive->hasFlagUsage(), 'flag_usage should survive toggle to public.');

    // Debug: show flag state after each toggle cycle.
    $this->dumpArchivesTable('after toggle cycle — flags should persist');
    $this->dumpUsageTable();
  }

  /**
   * Tests unarchive from archived_admin transitions to archived_deleted.
   */
  public function testUnarchiveFromAdminVisibility(): void {
    $asset = $this->createDocumentAssetWithFile();
    $archive = $this->queueAndExecute($asset, 'admin');

    $this->assertTrue($archive->isArchivedAdmin());

    $this->archiveService->unarchive($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertSame('archived_deleted', $archive->getStatus());
    $this->assertTrue($archive->isArchivedDeleted());
    $this->assertFalse($archive->hasFlagUsage());
    $this->assertFalse($archive->hasFlagMissing());
    $this->assertFalse($archive->hasFlagIntegrity());

    // Debug: show archive state after admin → deleted transition.
    $this->dumpArchivesTable('after unarchive from admin');
  }

  /**
   * Tests queued status operation restrictions.
   *
   * Queued items can be executed or removed, but cannot be toggled
   * or unarchived.
   */
  public function testQueuedStatusOperationRestrictions(): void {
    $asset = $this->createDocumentAsset();
    $archive = $this->queueAsset($asset);

    $this->assertSame('queued', $archive->getStatus());

    // Allowed operations.
    $this->assertTrue($archive->canExecuteArchive());
    $this->assertTrue($archive->canRemoveFromQueue());

    // Blocked operations.
    $this->assertFalse($archive->canToggleVisibility());
    $this->assertFalse($archive->canUnarchive());

    // Debug: show queued archive state.
    $this->dumpArchivesTable('queued — operation restrictions');
  }

}
