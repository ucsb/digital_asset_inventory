<?php

namespace Drupal\Tests\digital_asset_inventory\Kernel;

/**
 * Tests configuration flags control service behavior correctly.
 *
 * Covers link routing gates, archive-in-use policy, archived label display,
 * label text customization, and default deadline classification.
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
class ConfigFlagsKernelTest extends DigitalAssetKernelTestBase {

  /**
   * Tests link routing is disabled when both config flags are FALSE (defaults).
   */
  public function testLinkRoutingDisabledByDefault(): void {
    // Defaults: enable_archive=false, allow_archive_in_use=false.
    $this->assertFalse($this->archiveService->isLinkRoutingEnabled());
  }

  /**
   * Tests link routing is enabled when enable_archive is TRUE.
   */
  public function testLinkRoutingEnabledWithArchiveEnabled(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('enable_archive', TRUE)
      ->save();

    $this->assertTrue($this->archiveService->isLinkRoutingEnabled());
  }

  /**
   * Tests link routing fallback: enable_archive=FALSE + allow_archive_in_use=TRUE.
   *
   * Backwards compatibility: routing should still activate if only
   * allow_archive_in_use is enabled.
   */
  public function testLinkRoutingFallbackToArchiveInUse(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('enable_archive', FALSE)
      ->set('allow_archive_in_use', TRUE)
      ->save();

    $this->assertTrue($this->archiveService->isLinkRoutingEnabled());
  }

  /**
   * Tests isArchiveInUseAllowed() reflects config toggle.
   */
  public function testArchiveInUseAllowedToggle(): void {
    // Default: FALSE.
    $this->assertFalse($this->archiveService->isArchiveInUseAllowed());

    // Enable.
    $this->enableArchiveInUse();
    $this->assertTrue($this->archiveService->isArchiveInUseAllowed());

    // Disable again.
    $this->disableArchiveInUse();
    $this->assertFalse($this->archiveService->isArchiveInUseAllowed());
  }

  /**
   * Tests shouldShowArchivedLabel() defaults to TRUE.
   */
  public function testShowArchivedLabelEnabledByDefault(): void {
    $this->assertTrue($this->archiveService->shouldShowArchivedLabel());
  }

  /**
   * Tests shouldShowArchivedLabel() returns FALSE when disabled.
   */
  public function testShowArchivedLabelDisabled(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('show_archived_label', FALSE)
      ->save();

    $this->assertFalse($this->archiveService->shouldShowArchivedLabel());
  }

  /**
   * Tests getArchivedLabel() returns 'Archived' by default.
   */
  public function testArchivedLabelDefaultText(): void {
    $this->assertSame('Archived', $this->archiveService->getArchivedLabel());
  }

  /**
   * Tests getArchivedLabel() returns custom text when configured.
   */
  public function testArchivedLabelCustomText(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('archived_label_text', 'Legacy Document')
      ->save();

    $this->assertSame('Legacy Document', $this->archiveService->getArchivedLabel());
  }

  /**
   * Tests getArchivedLabel() falls back to 'Archived' when set to empty string.
   */
  public function testArchivedLabelEmptyFallback(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('archived_label_text', '')
      ->save();

    $this->assertSame('Archived', $this->archiveService->getArchivedLabel());
  }

  /**
   * Tests default deadline puts us in ADA compliance mode (before April 2026).
   *
   * The default ada_compliance_deadline is not set in config install, so the
   * service falls back to April 24, 2026. Since the current date is before
   * that deadline, isAdaComplianceMode() should return TRUE.
   */
  public function testDefaultDeadlineClassification(): void {
    // With no deadline set in config, the service defaults to April 24, 2026.
    // Current date (Feb 2026) is before the deadline → compliance mode.
    $this->assertTrue($this->archiveService->isAdaComplianceMode());

    // Verify that setLegacyMode/setGeneralMode correctly override.
    $this->setGeneralMode();
    $this->assertFalse($this->archiveService->isAdaComplianceMode());

    $this->setLegacyMode();
    $this->assertTrue($this->archiveService->isAdaComplianceMode());
  }

}
