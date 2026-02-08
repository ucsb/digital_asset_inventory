<?php

namespace Drupal\Tests\digital_asset_inventory\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;

/**
 * Tests integrity verification, auto-void, and immutability constraints.
 *
 * Covers Groups D (integrity enforcement) and D-Bonus (prior void,
 * immutability). 6 test cases.
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
class ArchiveIntegrityKernelTest extends DigitalAssetKernelTestBase {

  /**
   * Tests Legacy Archive integrity violation voids exemption (F11 + MOD-1).
   *
   * TC-VOID-001, TC-DUAL-008.
   */
  public function testLegacyIntegrityViolationVoidsExemption(): void {
    $this->setLegacyMode();
    $asset = $this->createDocumentAssetWithFile('integrity-test.pdf', 'original content');
    $archive = $this->queueAndExecute($asset);

    // Precondition: checksum is valid and integrity passes.
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $archive->getFileChecksum());
    $this->assertTrue($this->archiveService->verifyIntegrity($archive));

    // Overwrite physical file to cause checksum mismatch.
    $fs = $this->container->get('file_system');
    $fs->saveData(
      'modified content',
      'public://integrity-test.pdf',
      FileExists::Replace
    );

    // Reconcile should detect integrity violation and void exemption.
    $this->archiveService->reconcileStatus($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertSame('exemption_void', $archive->getStatus());
    $this->assertTrue($archive->isExemptionVoid());
    $this->assertTrue($archive->hasFlagIntegrity());

    // Debug: show archive state after exemption void.
    $this->dumpArchivesTable('after integrity violation');
  }

  /**
   * Tests General Archive integrity violation deletes (F13 + MOD-3).
   *
   * TC-DUAL-017.
   */
  public function testGeneralIntegrityViolationDeletes(): void {
    $this->setGeneralMode();
    $asset = $this->createDocumentAssetWithFile('general-test.pdf', 'original content');
    $archive = $this->queueAndExecute($asset);

    // Overwrite physical file.
    $fs = $this->container->get('file_system');
    $fs->saveData(
      'modified content',
      'public://general-test.pdf',
      FileExists::Replace
    );

    $this->archiveService->reconcileStatus($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertSame('archived_deleted', $archive->getStatus());
    $this->assertTrue($archive->isArchivedDeleted());
    $this->assertTrue($archive->hasFlagIntegrity());

    // Debug: show archive state after General integrity violation.
    $this->dumpArchivesTable('after General integrity violation');
  }

  /**
   * Tests reconcileStatus detects missing file.
   *
   * TC-ARCH-006.
   */
  public function testReconcileFlagMissing(): void {
    $asset = $this->createDocumentAssetWithFile('missing-test.pdf');
    $archive = $this->queueAndExecute($asset);

    // Delete the physical file.
    $this->container->get('file_system')->delete('public://missing-test.pdf');

    $this->archiveService->reconcileStatus($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertTrue($archive->hasFlagMissing());

    // Debug: show archive state after missing file detection.
    $this->dumpArchivesTable('after reconcile — flag_missing set');
  }

  /**
   * Tests reconcileStatus with no modifications preserves state.
   */
  public function testReconcileNoChangeLeavesStateUnchanged(): void {
    $asset = $this->createDocumentAssetWithFile();
    $archive = $this->queueAndExecute($asset);

    // Count notes after initial archive execution.
    $initial_note_count = $this->countEntities('dai_archive_note');

    $this->archiveService->reconcileStatus($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    // Status and flags unchanged.
    $this->assertSame('archived_public', $archive->getStatus());
    $this->assertFalse($archive->hasFlagMissing());
    $this->assertFalse($archive->hasFlagIntegrity());

    // No new notes created beyond the initial archive notes.
    $this->assertSame($initial_note_count, $this->countEntities('dai_archive_note'));

    // Debug: show unchanged archive state.
    $this->dumpArchivesTable('after reconcile — no change');
  }

  /**
   * Tests prior voided exemption forces General Archive (AT-3 + RA-5).
   *
   * TC-VOID-006.
   */
  public function testPriorVoidForcesGeneralArchive(): void {
    $this->setLegacyMode();

    // Create first asset, archive, and void its exemption.
    $asset1 = $this->createDocumentAssetWithFile('void-test.pdf', 'original');
    $archive1 = $this->queueAndExecute($asset1);

    // Manually set to exemption_void (simulates integrity violation).
    $archive1->setStatus('exemption_void');
    $archive1->save();

    // Create second asset pointing to the same FID as the first.
    $fid = $asset1->get('fid')->value;
    $asset2 = $this->createDocumentAsset([
      'file_name' => 'void-test.pdf',
      'file_path' => '/sites/default/files/void-test.pdf',
      'fid' => $fid,
    ]);

    // Queue and execute the new archive.
    $archive2 = $this->queueAndExecute($asset2);

    // Should be forced to General Archive due to prior void.
    $this->assertTrue($archive2->hasFlagPriorVoid());
    $this->assertTrue($archive2->hasFlagLateArchive());

    // Debug: show both archives — voided original + forced General new.
    $this->dumpArchivesTable('prior void forces General — both records');
  }

  /**
   * Tests immutability constraints on archive_classification_date and file_checksum.
   *
   * TC-ARCH-014, TC-DUAL-018, TC-DUAL-021.
   */
  public function testImmutabilityConstraints(): void {
    $asset = $this->createDocumentAssetWithFile();
    $archive = $this->queueAndExecute($asset);

    // Debug: show archive with immutable fields before mutation attempts.
    $this->dumpArchivesTable('immutability — before mutation attempts');

    // Test archive_classification_date immutability.
    // Drupal wraps preSave() LogicException in EntityStorageException.
    $fresh_archive = DigitalAssetArchive::load($archive->id());
    try {
      $fresh_archive->set('archive_classification_date', time() + 1000);
      $fresh_archive->save();
      $this->fail('Expected exception for immutable archive_classification_date.');
    }
    catch (\Exception $e) {
      // The LogicException may be the direct exception or wrapped in
      // EntityStorageException — check the full exception chain.
      $message = $e->getMessage();
      if ($e->getPrevious()) {
        $message .= ' ' . $e->getPrevious()->getMessage();
      }
      $this->assertMatchesRegularExpression('/immutable/i', $message);
    }

    // Test file_checksum immutability (fresh load to avoid stale state).
    $fresh_archive = DigitalAssetArchive::load($archive->id());
    try {
      $fresh_archive->set('file_checksum', str_repeat('a', 64));
      $fresh_archive->save();
      $this->fail('Expected exception for immutable file_checksum.');
    }
    catch (\Exception $e) {
      $message = $e->getMessage();
      if ($e->getPrevious()) {
        $message .= ' ' . $e->getPrevious()->getMessage();
      }
      $this->assertMatchesRegularExpression('/immutable/i', $message);
    }
  }

  /**
   * Tests reconcileStatus sets flag_usage when active references exist.
   */
  public function testReconcileSetsUsageFlag(): void {
    $asset = $this->createDocumentAssetWithFile();
    $archive = $this->queueAndExecute($asset);

    // No usage yet — flag should be clear.
    $this->assertFalse($archive->hasFlagUsage());

    // Add usage record, then reconcile.
    $this->createUsageRecord($asset);
    $flags = $this->archiveService->reconcileStatus($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertTrue($archive->hasFlagUsage());
    $this->assertContains('flag_usage', $flags);
    // Status unchanged — usage flag is advisory, not a status transition.
    $this->assertSame('archived_public', $archive->getStatus());

    // Debug: show flag state after reconcile detected usage.
    $this->dumpArchivesTable('after reconcile — flag_usage set');
    $this->dumpUsageTable();
  }

  /**
   * Tests reconcileStatus clears flag_usage after references are removed.
   */
  public function testReconcileClearsUsageFlag(): void {
    $asset = $this->createDocumentAssetWithFile();
    $usage = $this->createUsageRecord($asset);

    // Archive with in-use allowed so flag_usage is set at execute.
    $this->enableArchiveInUse();
    $archive = $this->queueAndExecute($asset);
    $this->assertTrue($archive->hasFlagUsage());

    // Remove usage record, then reconcile.
    $usage->delete();
    // Reset entity static cache so getUsageCountByArchive sees fresh data.
    $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage')->resetCache();

    $flags = $this->archiveService->reconcileStatus($archive);
    $archive = DigitalAssetArchive::load($archive->id());

    $this->assertFalse($archive->hasFlagUsage());
    $this->assertNotContains('flag_usage', $flags);
    $this->assertSame('archived_public', $archive->getStatus());

    // Debug: show flag state after reconcile cleared usage flag.
    $this->dumpArchivesTable('after reconcile — flag_usage cleared');
    $this->dumpUsageTable('after usage deleted');
  }

}
