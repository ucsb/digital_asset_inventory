<?php

namespace Drupal\Tests\digital_asset_inventory\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;
use Drupal\digital_asset_inventory\Entity\DigitalAssetUsage;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * Base class for Digital Asset Inventory kernel tests.
 *
 * Provides shared setUp, tearDown, and helper methods for all kernel test
 * classes. See docs/testing/kernel-testing-spec.md for full specification.
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
abstract class DigitalAssetKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Disabled because Views config in the module has incomplete schema
   * definitions that are irrelevant to kernel test logic.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   *
   * Includes all declared module dependencies. If contrib modules are not
   * available in the test environment, remove them and digital_asset_inventory
   * from this list, then manually install entity schemas and config in setUp().
   * See kernel-testing-spec.md Section 1.4 for fallback strategies.
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'text',
    'options',
    'filter',
    'serialization',
    'rest',
    'image',
    'media',
    'views',
    'better_exposed_filters',
    'views_data_export',
    'csv_serialization',
    'digital_asset_inventory',
  ];

  /**
   * URIs of test files to clean up in tearDown().
   */
  protected array $testFileUris = [];

  /**
   * The archive service.
   */
  protected ArchiveService $archiveService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Auto-clear dump file at the start of each test run and write the
    // SQLite DB path once. Each kernel test runs in a separate child
    // process, so static variables don't persist. Instead, we use a
    // marker file containing the PHPUnit parent PID (posix_getppid()),
    // which is the same for all child processes in one run but different
    // across runs. When a new PPID is detected, old dump data is cleared.
    if ($this->isDebugEnabled() || getenv('DAI_TEST_DUMP_DB')) {
      $file = static::getDebugDumpFile();
      $dir = dirname($file);
      if (!is_dir($dir)) {
        @mkdir($dir, 0755, TRUE);
      }

      // Detect new run via parent PID and auto-clear old dump data.
      if (function_exists('posix_getppid')) {
        $marker = $file . '.run';
        $ppid = (string) posix_getppid();
        if (!file_exists($marker) || file_get_contents($marker) !== $ppid) {
          file_put_contents($file, '', LOCK_EX);
          file_put_contents($marker, $ppid, LOCK_EX);
        }
      }

      // Write DB path once per run (file is empty after auto-clear).
      if (getenv('DAI_TEST_DUMP_DB') && (!file_exists($file) || filesize($file) === 0)) {
        $db = getenv('SIMPLETEST_DB') ?: '(not set)';
        $this->debugWrite("DAI DEBUG: SIMPLETEST_DB=$db");
      }
    }

    // Install entity schemas (user schema must come before creating users).
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('digital_asset_item');
    $this->installEntitySchema('digital_asset_archive');
    $this->installEntitySchema('dai_archive_note');
    $this->installEntitySchema('digital_asset_usage');
    $this->installConfig(['digital_asset_inventory']);
    $this->installSchema('file', ['file_usage']);

    // Set up current user (archive operations store archived_by as user ID).
    // No permissions granted — these tests exercise service-level logic,
    // not route access.
    $user = User::create([
      'name' => 'test_archiver',
      'mail' => 'test@example.com',
      'status' => 1,
    ]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);

    $this->archiveService = $this->container->get('digital_asset_inventory.archive');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Clean up physical test files created during the test.
    if (!empty($this->testFileUris)) {
      $fs = $this->container->get('file_system');
      foreach ($this->testFileUris as $uri) {
        try {
          $fs->delete($uri);
        }
        catch (\Exception $e) {
          // File may already be deleted by the test (e.g., testReconcileFlagMissing).
        }
      }
    }
    parent::tearDown();
  }

  /**
   * Prints a one-time note about expected warnings and deprecations.
   *
   * Uses a temp marker keyed by parent PID so the note appears only once
   * per PHPUnit run, regardless of how many test classes execute.
   */
  public static function tearDownAfterClass(): void {
    parent::tearDownAfterClass();

    $ppid = function_exists('posix_getppid') ? (string) posix_getppid() : (string) getmypid();
    $marker = sys_get_temp_dir() . '/dai-kernel-test-note-' . $ppid;
    if (!file_exists($marker)) {
      @file_put_contents($marker, '1');
      // Write directly to terminal, bypassing PHPUnit's STDOUT/STDERR
      // capture (PHPUnit 9 treats child-process STDERR as an error).
      // Falls back gracefully on CI where /dev/tty is unavailable.
      $tty = @fopen('/dev/tty', 'w');
      if ($tty) {
        fwrite($tty,
          "\n" .
          "  ┌─────────────────────────────────────────────────────────────────┐\n" .
          "  │  Digital Asset Inventory — Kernel Tests                         │\n" .
          "  │                                                                 │\n" .
          "  │  Warnings and deprecations are expected on Drupal 10.3+ / 11.x  │\n" .
          "  │  and do not indicate test failures.                             │\n" .
          "  │  See tests/README.md § \"Understanding test output\"              │\n" .
          "  └─────────────────────────────────────────────────────────────────┘\n" .
          "\n"
        );
        fclose($tty);
      }
    }
  }

  /**
   * Creates a DigitalAssetItem fixture in the Documents category (archiveable).
   *
   * @param array $overrides
   *   Field values to override defaults.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetItem
   *   The saved entity.
   */
  protected function createDocumentAsset(array $overrides = []): DigitalAssetItem {
    $values = $overrides + [
      'file_name' => 'test-doc.pdf',
      'file_path' => '/sites/default/files/test-doc.pdf',
      'asset_type' => 'pdf',
      'category' => 'Documents',
      'mime_type' => 'application/pdf',
      'source_type' => 'file_managed',
      'is_temp' => FALSE,
    ];
    $asset = DigitalAssetItem::create($values);
    $asset->save();
    return $asset;
  }

  /**
   * Creates a DigitalAssetItem fixture in the Images category (not archiveable).
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetItem
   *   The saved entity.
   */
  protected function createImageAsset(): DigitalAssetItem {
    return $this->createDocumentAsset([
      'file_name' => 'photo.jpg',
      'file_path' => '/sites/default/files/photo.jpg',
      'asset_type' => 'jpg',
      'category' => 'Images',
      'mime_type' => 'image/jpeg',
    ]);
  }

  /**
   * Creates a real physical file for checksum and integrity tests.
   *
   * Uses temporary:// for multisite compatibility — temporary:// is always
   * writable in kernel tests.
   *
   * @param string $filename
   *   The filename to create.
   * @param string $content
   *   The file content.
   *
   * @return string
   *   The file URI (temporary://filename).
   */
  protected function createTestFile(
    string $filename = 'test-doc.pdf',
    string $content = 'test content',
  ): string {
    $fs = $this->container->get('file_system');
    // Use public:// so that generateAbsoluteString() produces URLs with
    // /sites/.../files/ that urlPathToStreamUri() can resolve back to stream
    // URIs. This is critical for verifyIntegrity() which passes NULL for FID.
    $directory = 'public://';
    $fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri = 'public://' . $filename;
    $fs->saveData($content, $uri, FileExists::Replace);
    $this->testFileUris[] = $uri;
    return $uri;
  }

  /**
   * Creates a file_managed entity for a given URI.
   *
   * Required for checksum/integrity tests where resolveSourceUri() looks up
   * the file by FID.
   *
   * @param string $uri
   *   The file URI.
   * @param string $mime
   *   The MIME type.
   *
   * @return \Drupal\file\FileInterface
   *   The saved file entity.
   */
  protected function createFileEntity(
    string $uri,
    string $mime = 'application/pdf',
  ): \Drupal\file\FileInterface {
    $file = File::create([
      'uri' => $uri,
      'filename' => basename($uri),
      'filemime' => $mime,
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Creates a DigitalAssetUsage record referencing an asset.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The asset entity.
   * @param array $overrides
   *   Field values to override defaults.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetUsage
   *   The saved usage entity.
   */
  protected function createUsageRecord(
    DigitalAssetItem $asset,
    array $overrides = [],
  ): DigitalAssetUsage {
    $values = $overrides + [
      'asset_id' => $asset->id(),
      'entity_type' => 'node',
      'entity_id' => 1,
      'field_name' => 'body',
      'count' => 1,
      'embed_method' => 'text_link',
    ];
    $usage = DigitalAssetUsage::create($values);
    $usage->save();
    return $usage;
  }

  /**
   * Creates a document asset with physical file and file_managed entity.
   *
   * Composite helper that wires FID for deterministic path resolution.
   * Always use this for checksum/integrity tests.
   *
   * @param string $filename
   *   The filename.
   * @param string $content
   *   The file content.
   * @param array $overrides
   *   Field values to override defaults.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetItem
   *   The saved asset entity.
   */
  protected function createDocumentAssetWithFile(
    string $filename = 'test-doc.pdf',
    string $content = 'test content',
    array $overrides = [],
  ): DigitalAssetItem {
    $uri = $this->createTestFile($filename, $content);
    $file = $this->createFileEntity($uri);

    // file_path is metadata — resolution uses fid -> file entity -> URI.
    return $this->createDocumentAsset($overrides + [
      'file_name' => $filename,
      'file_path' => '/sites/default/files/' . $filename,
      'fid' => $file->id(),
    ]);
  }

  /**
   * Enables allow_archive_in_use config.
   */
  protected function enableArchiveInUse(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('allow_archive_in_use', TRUE)
      ->save();
  }

  /**
   * Disables allow_archive_in_use config.
   */
  protected function disableArchiveInUse(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('allow_archive_in_use', FALSE)
      ->save();
  }

  /**
   * Sets Legacy mode (ADA compliance deadline far in the future).
   */
  protected function setLegacyMode(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('ada_compliance_deadline', strtotime('3000-01-01'))
      ->save();
  }

  /**
   * Sets General mode (ADA compliance deadline far in the past).
   */
  protected function setGeneralMode(): void {
    $this->config('digital_asset_inventory.settings')
      ->set('ada_compliance_deadline', strtotime('2000-01-01'))
      ->save();
  }

  /**
   * Queues a document asset for archive and returns the archive entity.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The asset to queue.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive
   *   The created archive entity.
   */
  protected function queueAsset(DigitalAssetItem $asset): DigitalAssetArchive {
    return $this->archiveService->markForArchive(
      $asset, 'reference', '', 'Test description for public registry.'
    );
  }

  /**
   * Queues and executes an archive in one step, returns the reloaded entity.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetItem $asset
   *   The asset to archive.
   * @param string $visibility
   *   The visibility ('public' or 'admin').
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive
   *   The reloaded archive entity.
   */
  protected function queueAndExecute(
    DigitalAssetItem $asset,
    string $visibility = 'public',
  ): DigitalAssetArchive {
    $archive = $this->queueAsset($asset);
    $this->archiveService->executeArchive($archive, $visibility);
    // Reload to get updated field values.
    return DigitalAssetArchive::load($archive->id());
  }

  /**
   * Counts all entities of a given type.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return int
   *   The entity count.
   */
  protected function countEntities(string $entity_type_id): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage($entity_type_id)
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  // -----------------------------------------------------------------------
  // Debug dump helpers — opt-in via DAI_TEST_DEBUG=1 environment variable.
  //
  // Drupal kernel tests run in a child process where STDOUT and STDERR are
  // captured by the parent PHPUnit runner. To make debug output visible,
  // dumps are written to tests/artifacts/dai-debug-dump.txt (relative to
  // the module's tests/ directory). Tail the file in a separate terminal:
  //
  //   tail -f modules/custom/digital_asset_inventory/tests/artifacts/dai-debug-dump.txt
  //
  // Output uses fixed "DAI DEBUG:" label prefixes for grep-friendly logs.
  // -----------------------------------------------------------------------

  /**
   * Returns the debug dump file path.
   *
   * Defaults to tests/artifacts/dai-debug-dump.txt inside the module.
   * Override with DAI_TEST_DUMP_FILE environment variable.
   *
   * @return string
   *   The absolute file path.
   */
  protected static function getDebugDumpFile(): string {
    return getenv('DAI_TEST_DUMP_FILE')
      ?: dirname(__DIR__, 2) . '/artifacts/dai-debug-dump.txt';
  }

  /**
   * Checks whether debug output is enabled.
   *
   * @return bool
   *   TRUE if DAI_TEST_DEBUG=1 is set.
   */
  protected function isDebugEnabled(): bool {
    return (bool) getenv('DAI_TEST_DEBUG');
  }

  /**
   * Writes a line to the debug dump file.
   *
   * No-op unless DAI_TEST_DEBUG=1 or DAI_TEST_DUMP_DB=1 is set.
   *
   * @param string $text
   *   The text to write.
   */
  protected function debugWrite(string $text): void {
    $file = static::getDebugDumpFile();
    $dir = dirname($file);
    if (!is_dir($dir)) {
      @mkdir($dir, 0755, TRUE);
    }
    file_put_contents($file, $text . "\n", FILE_APPEND | LOCK_EX);
  }

  /**
   * Dumps the digital_asset_archive table.
   *
   * @param string|null $label
   *   Optional extra label appended to the header.
   */
  protected function dumpArchivesTable(?string $label = NULL): void {
    $this->dumpTable('digital_asset_archive', [
      'id', 'status', 'file_name', 'asset_type', 'archive_reason',
      'flag_integrity', 'flag_usage', 'flag_missing', 'flag_late_archive',
      'flag_modified', 'flag_prior_void', 'file_checksum',
    ], 'archives', $label);
  }

  /**
   * Dumps the digital_asset_item table.
   *
   * @param string|null $label
   *   Optional extra label appended to the header.
   */
  protected function dumpItemsTable(?string $label = NULL): void {
    $this->dumpTable('digital_asset_item', [
      'id', 'file_name', 'file_path', 'asset_type', 'category',
      'source_type', 'fid', 'is_temp', 'is_private',
    ], 'items', $label);
  }

  /**
   * Dumps the digital_asset_usage table.
   *
   * @param string|null $label
   *   Optional extra label appended to the header.
   */
  protected function dumpUsageTable(?string $label = NULL): void {
    $this->dumpTable('digital_asset_usage', [
      'id', 'asset_id', 'entity_type', 'entity_id', 'field_name',
      'count', 'embed_method',
    ], 'usage', $label);
  }

  /**
   * Dumps a database table as a column-aligned text table.
   *
   * Gated by DAI_TEST_DEBUG=1 — returns immediately when not set.
   * Output is written to tests/artifacts/dai-debug-dump.txt by default.
   * Use `tail -f` on that file in a separate terminal to watch live output.
   *
   * @param string $table
   *   The database table name.
   * @param array $columns
   *   Column names to display. If empty, all columns are shown.
   * @param string $short_name
   *   Short name for the label prefix (e.g., 'archives').
   * @param string|null $label
   *   Optional extra label appended to the header.
   */
  protected function dumpTable(
    string $table,
    array $columns = [],
    string $short_name = '',
    ?string $label = NULL,
  ): void {
    if (!$this->isDebugEnabled()) {
      return;
    }

    $db = $this->container->get('database');
    $query = $db->select($table, 't');

    if ($columns) {
      foreach ($columns as $col) {
        $query->addField('t', $col);
      }
    }
    else {
      $query->fields('t');
    }

    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    // Build header line.
    $name = $short_name ?: $table;
    $header = "DAI DEBUG: $name ($table)";
    if ($label) {
      $header .= " — $label";
    }

    if (empty($rows)) {
      $this->debugWrite("\n$header\n  (empty)");
      return;
    }

    // Calculate column widths.
    $cols = array_keys($rows[0]);
    $widths = [];
    foreach ($cols as $col) {
      $widths[$col] = mb_strlen($col);
    }
    foreach ($rows as $row) {
      foreach ($cols as $col) {
        $val = $row[$col] ?? '';
        $len = mb_strlen((string) $val);
        if ($len > $widths[$col]) {
          $widths[$col] = min($len, 40);
        }
      }
    }

    // Build output.
    $output = "\n$header\n";

    // Header row.
    $parts = [];
    foreach ($cols as $col) {
      $parts[] = str_pad($col, $widths[$col]);
    }
    $output .= '  ' . implode(' | ', $parts) . "\n";

    // Separator.
    $parts = [];
    foreach ($cols as $col) {
      $parts[] = str_repeat('-', $widths[$col]);
    }
    $output .= '  ' . implode('-+-', $parts) . "\n";

    // Data rows.
    foreach ($rows as $row) {
      $parts = [];
      foreach ($cols as $col) {
        $val = (string) ($row[$col] ?? '');
        if (mb_strlen($val) > 40) {
          $val = mb_substr($val, 0, 37) . '...';
        }
        $parts[] = str_pad($val, $widths[$col]);
      }
      $output .= '  ' . implode(' | ', $parts) . "\n";
    }

    $this->debugWrite($output);
  }

}
