<?php

/**
 * @file
 * Digital Asset Inventory & Archive Management module.
 *
 * Provides digital asset scanning, usage tracking, and
 * ADA Title II–compliant archiving tools for Drupal sites.
 *
 * Copyright (C) 2026
 * The Regents of the University of California
 *
 * This file is part of the Digital Asset Inventory module.
 *
 * The Digital Asset Inventory module is free software: you can
 * redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation;
 * either version 2 of the License, or (at your option) any later version.
 *
 * The Digital Asset Inventory module is distributed in the hope that it
 * will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see:
 * https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt
 */

namespace Drupal\digital_asset_inventory\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\digital_asset_inventory\FilePathResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for scanning and inventorying digital assets.
 */
class DigitalAssetScanner {

  use FilePathResolver;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The service container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * The lock backend.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Timestamp of the last heartbeat write in this request.
   *
   * Resets to 0 every batch callback because the scanner service is
   * re-instantiated per HTTP request by Batch API. This is correct —
   * the first maybeUpdateHeartbeat() call in each callback will always
   * write, ensuring the heartbeat is fresh at callback entry.
   */
  private int $lastHeartbeatWrite = 0;

  /**
   * Count of heartbeat writes in this callback (for FR-8 diagnostic logging).
   */
  private int $heartbeatWriteCount = 0;

  /**
   * Orphan paragraph count accumulated during this request.
   *
   * Incremented by getParentFromParagraph() when orphans are detected.
   * Copied to sandbox at callback exit by scanManagedFilesChunk().
   * Resets to 0 per HTTP request (scanner re-instantiated by Batch API).
   */
  private int $currentOrphanCount = 0;

  /**
   * Per-callback cache of paragraph parent lookups.
   *
   * Key: paragraph entity ID.
   * Value: Parent info array from getParentFromParagraph(), or NULL if not found.
   * Cleared per callback when entity caches are reset.
   */
  private array $paragraphParentCache = [];

  /**
   * In-memory buffer of usage records pending bulk INSERT.
   *
   * Each entry is an associative array of column => value.
   * Flushed to the database at the end of each batch callback
   * via flushUsageBuffer().
   */
  private array $usageBuffer = [];

  /**
   * In-memory buffer of orphan reference records pending bulk INSERT.
   *
   * Each entry is an associative array of column => value.
   * Flushed to the database at the end of each batch callback
   * via flushOrphanRefBuffer().
   */
  private array $orphanRefBuffer = [];

  /**
   * Cached list of entity text-field tables for LIKE-based link detection.
   *
   * Built once per PHP process by getTextFieldTables(). Each entry:
   *   ['table' => 'node__body', 'entity_type' => 'node',
   *    'field_name' => 'body', 'value_column' => 'body_value']
   *
   * @var array|null
   *   NULL = not yet built; [] = built but empty.
   */
  private ?array $textFieldTableCache = NULL;

  /**
   * Cached list of file/image field data tables.
   *
   * Built once per PHP process by getFileFieldTables(). Each entry:
   *   ['table' => 'node__field_image', 'column' => 'field_image_target_id',
   *    'entity_type' => 'node', 'field_name' => 'field_image']
   *
   * @var array[]|null
   *   NULL = not yet built.
   */
  private ?array $fileFieldTableCache = NULL;

  /**
   * Cached list of entity reference field tables targeting media.
   *
   * Built once per PHP process by getMediaReferenceFieldTables(). Each entry:
   *   ['table' => 'node__field_hero_media', 'column' => 'field_hero_media_target_id',
   *    'entity_type' => 'node', 'field_name' => 'field_hero_media']
   *
   * @var array[]|null
   *   NULL = not yet built.
   */
  private ?array $mediaRefFieldTableCache = NULL;

  private const HEARTBEAT_INTERVAL_SECONDS = 2;

  /**
   * Constructs a DigitalAssetScanner object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    ConfigFactoryInterface $config_factory,
    FileUrlGeneratorInterface $file_url_generator,
    FileSystemInterface $file_system,
    EntityFieldManagerInterface $entity_field_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ContainerInterface $container,
    LockBackendInterface $lock,
    StateInterface $state,
    ModuleHandlerInterface $module_handler = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->fileUrlGenerator = $file_url_generator;
    $this->fileSystem = $file_system;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger_factory->get('digital_asset_inventory');
    $this->container = $container;
    $this->lock = $lock;
    $this->state = $state;
    // Fallback to global service for backward compat (e.g., before services.yml
    // is updated). Wrapped in try/catch for unit tests without a container.
    if ($module_handler) {
      $this->moduleHandler = $module_handler;
    }
    else {
      try {
        $this->moduleHandler = \Drupal::moduleHandler();
      }
      catch (\Exception $e) {
        // Unit test environment — moduleHandler will be NULL.
        // suspendCron()/restoreCron() will no-op safely.
      }
    }
  }

  /**
   * Gets the configured stale lock threshold.
   *
   * Reads from config with validation. Falls back to 900 on invalid values.
   */
  public function getStaleLockThreshold(): int {
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $threshold = $config->get('scan_lock_stale_threshold_seconds');

    if (!is_numeric($threshold) || $threshold < 120 || $threshold > 7200) {
      $this->logger->warning('Invalid stale threshold @value, using default 900s.', [
        '@value' => $threshold ?? 'NULL',
      ]);
      return self::SCAN_LOCK_STALE_THRESHOLD;
    }

    return (int) $threshold;
  }

  /**
   * Gets the configured batch time budget.
   *
   * Reads from config with validation. Falls back to 4 on invalid values.
   */
  public function getBatchTimeBudget(): int {
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $budget = $config->get('scan_batch_time_budget_seconds');

    if (!is_numeric($budget) || $budget < 1 || $budget > 30) {
      $this->logger->warning('Invalid time budget @value, using default 10s.', [
        '@value' => $budget ?? 'NULL',
      ]);
      return self::BATCH_TIME_BUDGET_SECONDS;
    }

    return (int) $budget;
  }

  /**
   * Conditionally updates heartbeat if interval has elapsed.
   *
   * Cheap to call per-item — only writes to State when the interval
   * has actually elapsed. At most 1 State write per 2 seconds.
   */
  public function maybeUpdateHeartbeat(): void {
    $now = time();
    if (($now - $this->lastHeartbeatWrite) >= self::HEARTBEAT_INTERVAL_SECONDS) {
      $this->updateScanHeartbeat();
      $this->lastHeartbeatWrite = $now;
      $this->heartbeatWriteCount++;
    }
  }

  /**
   * Returns the number of heartbeat writes in this callback.
   */
  public function getHeartbeatWriteCount(): int {
    return $this->heartbeatWriteCount;
  }

  /**
   * Resets heartbeat write counter. Call at start of each batch callback.
   */
  public function resetHeartbeatWriteCount(): void {
    $this->heartbeatWriteCount = 0;
  }

  /**
   * Processes entities using an ID-based cursor with time budget.
   *
   * Uses bulk loading via $loadFn to minimize DB round-trips.
   * On Pantheon with remote DB, this reduces 100 individual loads (~22s)
   * to 1 bulk load (~0.5s).
   *
   * @param array &$context
   *   Batch API context array.
   * @param string $cursorKey
   *   Sandbox key for the cursor (e.g., 'last_fid', 'last_mid', 'last_id').
   * @param string $totalKey
   *   Sandbox key for the total count (e.g., 'total_files', 'total_media').
   * @param callable $countFn
   *   Function returning total item count. Called once on first invocation.
   *   Signature: function(): int
   * @param callable $queryFn
   *   Function returning array of IDs to process.
   *   Signature: function(int $lastId, int $limit): array
   * @param callable $loadFn
   *   Function to bulk-load entities/rows by IDs.
   *   Signature: function(array $ids): array
   *   Returns array of loaded items keyed by ID.
   * @param callable $processFn
   *   Function to process a single loaded item (not an ID).
   *   Signature: function(mixed $entity): void
   *
   * @return int
   *   Number of items processed in this callback.
   */
  protected function processWithTimeBudget(
    array &$context,
    string $cursorKey,
    string $totalKey,
    callable $countFn,
    callable $queryFn,
    callable $loadFn,
    callable $processFn,
    ?callable $preloadFn = NULL,
  ): int {
    $budget = $this->getBatchTimeBudget();
    $startTime = microtime(true);
    $itemsThisCallback = 0;

    // Initialize on first call.
    if (!isset($context['sandbox'][$cursorKey])) {
      $context['sandbox'][$cursorKey] = 0;
      $context['sandbox'][$totalKey] = ($countFn)();
      $context['sandbox']['processed'] = $context['sandbox']['processed'] ?? 0;
    }

    $lastId = $context['sandbox'][$cursorKey];

    // Fetch a batch of IDs.
    $ids = ($queryFn)($lastId, 100);

    // Exhaustion guard: no more items means phase is done.
    if (empty($ids)) {
      $context['finished'] = 1;
      return $itemsThisCallback;
    }

    // BULK LOAD all items in one query.
    // loadMultiple() uses SELECT ... WHERE id IN (...) — one DB round-trip.
    $entities = ($loadFn)($ids);

    // Run batch pre-queries if provided (see scan-bulk-reads-spec.md Fix 1).
    // $preloaded is passed as second argument to processFn.
    $preloaded = $preloadFn ? ($preloadFn)($entities) : NULL;

    foreach ($ids as $id) {
      // Time check BEFORE processing.
      if ((microtime(true) - $startTime) >= $budget) {
        break;
      }

      // Skip if item failed to load (deleted between ID query and load).
      if (!isset($entities[$id])) {
        $context['sandbox'][$cursorKey] = $id;
        $context['sandbox']['processed']++;
        continue;
      }

      ($processFn)($entities[$id], $preloaded);
      $this->maybeUpdateHeartbeat();

      $context['sandbox'][$cursorKey] = $id;
      $context['sandbox']['processed']++;
      $itemsThisCallback++;
    }

    // Progress calculation.
    $total = $context['sandbox'][$totalKey];
    if ($total > 0) {
      $context['finished'] = $context['sandbox']['processed'] / $total;
    }
    // Clamp to 1 if we've processed everything.
    if ($context['finished'] >= 1) {
      $context['finished'] = 1;
    }

    return $itemsThisCallback;
  }

  /**
   * Resets entity storage caches for the given entity types.
   *
   * Called at the end of each scan*Chunk(). The hasDefinition() guard
   * ensures safe operation when optional entity types (paragraph,
   * block_content) are not installed.
   */
  protected function resetPhaseEntityCaches(array $entityTypes): void {
    foreach ($entityTypes as $entityType) {
      if ($this->entityTypeManager->hasDefinition($entityType)) {
        $this->entityTypeManager->getStorage($entityType)->resetCache();
      }
    }
    // Clear paragraph parent cache to prevent stale lookups across callbacks.
    $this->paragraphParentCache = [];
    // Clear bulk read caches (rebuilt on demand from DB schema).
    $this->fileFieldTableCache = NULL;
    $this->mediaRefFieldTableCache = NULL;
  }

  // =====================================================================
  // Raw SQL write methods — bypass Entity API for temp scan items.
  // Safe because temp items have no hook subscribers, no cache consumers,
  // and no validation requirements. See scan-bulk-write-spec.md.
  // =====================================================================

  /**
   * Inserts a digital_asset_item row via raw SQL, bypassing Entity API.
   *
   * Safe for temp items only — no hooks, validation, or cache invalidation.
   * Generates a UUID automatically. Returns the auto-increment ID.
   *
   * @param array $fields
   *   Associative array of column => value. Must NOT include 'id' or 'uuid'.
   *   Required keys: source_type, file_name, file_path, is_temp.
   *
   * @return int
   *   The auto-generated entity ID.
   */
  protected function rawInsertAssetItem(array $fields): int {
    $fields['uuid'] = \Drupal::service('uuid')->generate();
    $now = \Drupal::time()->getRequestTime();
    $fields += [
      'created' => $now,
      'changed' => $now,
      'filesize_formatted' => $this->formatFileSize($fields['filesize'] ?? 0),
      'active_use_csv' => '',
      'used_in_csv' => '',
      'location' => '',
    ];
    return (int) $this->database->insert('digital_asset_item')
      ->fields($fields)
      ->execute();
  }

  /**
   * Updates specific columns on a digital_asset_item row via raw SQL.
   *
   * @param int $id
   *   The entity ID.
   * @param array $fields
   *   Associative array of column => value to update.
   */
  protected function rawUpdateAssetItem(int $id, array $fields): void {
    $fields['changed'] = \Drupal::time()->getRequestTime();
    $this->database->update('digital_asset_item')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Adds a usage record to the in-memory buffer for bulk INSERT.
   *
   * @param array $fields
   *   Associative array with keys: asset_id, entity_type, entity_id,
   *   field_name, count, embed_method. Optional: presentation_type,
   *   accessibility_signals, signals_evaluated.
   */
  protected function bufferUsageRecord(array $fields): void {
    $fields += [
      'count' => 1,
      'presentation_type' => '',
      'accessibility_signals' => '',
      'signals_evaluated' => 0,
      'embed_method' => 'field_reference',
    ];
    $this->usageBuffer[] = $fields;
  }

  /**
   * Flushes the usage record buffer to the database via bulk INSERT.
   *
   * Inserts all buffered records in a single multi-row INSERT statement.
   * On Pantheon, 100 individual entity saves (~20s) become 1 bulk INSERT (~0.1s).
   *
   * Clears the buffer after successful insertion.
   */
  protected function flushUsageBuffer(): void {
    if (empty($this->usageBuffer)) {
      return;
    }

    $columns = [
      'uuid', 'asset_id', 'entity_type', 'entity_id', 'field_name',
      'count', 'embed_method', 'presentation_type',
      'accessibility_signals', 'signals_evaluated',
    ];

    $insert = $this->database->insert('digital_asset_usage')->fields($columns);

    foreach ($this->usageBuffer as $record) {
      $insert->values([
        'uuid' => \Drupal::service('uuid')->generate(),
        'asset_id' => $record['asset_id'],
        'entity_type' => $record['entity_type'],
        'entity_id' => $record['entity_id'],
        'field_name' => $record['field_name'] ?? '',
        'count' => $record['count'] ?? 1,
        'embed_method' => $record['embed_method'] ?? 'field_reference',
        'presentation_type' => $record['presentation_type'] ?? '',
        'accessibility_signals' => $record['accessibility_signals'] ?? '',
        'signals_evaluated' => $record['signals_evaluated'] ?? 0,
      ]);
    }

    $insert->execute();
    $this->usageBuffer = [];
  }

  /**
   * Deletes all usage records for a given asset ID via raw SQL.
   *
   * Replaces the entity query + loadMultiple + delete pattern.
   *
   * @param int $asset_id
   *   The digital_asset_item entity ID.
   */
  protected function rawDeleteUsageByAssetId(int $asset_id): void {
    $this->database->delete('digital_asset_usage')
      ->condition('asset_id', $asset_id)
      ->execute();
  }

  /**
   * Deletes all orphan reference records for a given asset ID via raw SQL.
   *
   * @param int $asset_id
   *   The digital_asset_item entity ID.
   */
  protected function rawDeleteOrphanRefsByAssetId(int $asset_id): void {
    $this->database->delete('dai_orphan_reference')
      ->condition('asset_id', $asset_id)
      ->execute();
  }

  /**
   * Inserts an orphan reference record via raw SQL.
   *
   * @param int $asset_id
   *   The digital_asset_item entity ID.
   * @param string $source_entity_type
   *   The orphan source entity type (e.g., 'paragraph').
   * @param int $source_entity_id
   *   The orphan source entity ID.
   * @param string $source_bundle
   *   The bundle of the source entity.
   * @param string $field_name
   *   The field containing the reference.
   * @param string $embed_method
   *   How the asset is referenced.
   * @param string $reference_context
   *   Why this reference is orphaned.
   */
  protected function rawInsertOrphanReference(
    int $asset_id,
    string $source_entity_type,
    int $source_entity_id,
    string $source_bundle,
    string $field_name,
    string $embed_method,
    string $reference_context,
  ): void {
    $this->database->insert('dai_orphan_reference')
      ->fields([
        'uuid' => \Drupal::service('uuid')->generate(),
        'asset_id' => $asset_id,
        'source_entity_type' => $source_entity_type,
        'source_entity_id' => $source_entity_id,
        'source_bundle' => $source_bundle,
        'field_name' => $field_name,
        'embed_method' => $embed_method,
        'reference_context' => $reference_context,
      ])
      ->execute();
  }

  /**
   * Adds an orphan reference record to the in-memory buffer.
   *
   * Buffered records are flushed via flushOrphanRefBuffer() at the end
   * of each batch callback, producing a single transaction with a shared
   * prepared statement instead of per-item Entity API saves.
   *
   * @param int $asset_id
   *   The digital_asset_item entity ID.
   * @param string $source_entity_type
   *   The orphan source entity type (e.g., 'paragraph').
   * @param int $source_entity_id
   *   The orphan source entity ID.
   * @param string $source_bundle
   *   The bundle of the source entity.
   * @param string $field_name
   *   The field containing the reference.
   * @param string $embed_method
   *   How the asset is referenced.
   * @param string $reference_context
   *   Why this reference is orphaned.
   */
  protected function bufferOrphanReference(
    int $asset_id,
    string $source_entity_type,
    int $source_entity_id,
    string $source_bundle,
    string $field_name,
    string $embed_method,
    string $reference_context,
  ): void {
    $this->orphanRefBuffer[] = [
      'asset_id' => $asset_id,
      'source_entity_type' => $source_entity_type,
      'source_entity_id' => $source_entity_id,
      'source_bundle' => $source_bundle,
      'field_name' => $field_name,
      'embed_method' => $embed_method,
      'reference_context' => $reference_context,
    ];
  }

  /**
   * Flushes the orphan reference buffer to the database via bulk INSERT.
   *
   * Follows the same pattern as flushUsageBuffer(). Drupal's Insert builder
   * wraps multiple ->values() calls in a single transaction with a shared
   * prepared statement, reducing per-row cost from ~0.15s to ~0.02s.
   *
   * Clears the buffer after successful insertion.
   */
  protected function flushOrphanRefBuffer(): void {
    if (empty($this->orphanRefBuffer)) {
      return;
    }

    $columns = [
      'uuid', 'asset_id', 'source_entity_type', 'source_entity_id',
      'source_bundle', 'field_name', 'embed_method', 'reference_context',
    ];

    $insert = $this->database->insert('dai_orphan_reference')->fields($columns);

    foreach ($this->orphanRefBuffer as $record) {
      $insert->values([
        'uuid' => \Drupal::service('uuid')->generate(),
        'asset_id' => $record['asset_id'],
        'source_entity_type' => $record['source_entity_type'],
        'source_entity_id' => $record['source_entity_id'],
        'source_bundle' => $record['source_bundle'],
        'field_name' => $record['field_name'],
        'embed_method' => $record['embed_method'],
        'reference_context' => $record['reference_context'],
      ]);
    }

    $insert->execute();
    $this->orphanRefBuffer = [];
  }

  // =====================================================================
  // End raw SQL write methods.
  // =====================================================================

  // =====================================================================
  // Bulk read methods — pre-query data for entire batches.
  // Replaces per-item queries in processManagedFile().
  // See scan-bulk-reads-spec.md Fix 1.
  // =====================================================================

  /**
   * Pre-queries all read data for a batch of managed files.
   *
   * Called once per callback by processWithTimeBudget() via preloadFn.
   * Returns lookup maps that processManagedFile() uses instead of
   * per-item queries.
   *
   * @param array $file_rows
   *   Keyed by fid => stdClass file row from loadManagedFileRows().
   *
   * @return array
   *   Associative array with keys:
   *   - 'file_usage': fid => [file_usage rows]
   *   - 'existing_temp': fid => digital_asset_item.id
   *   - 'media_entity_refs': media_id => [ref arrays]
   *   - 'media_embed_refs': media_uuid => [ref arrays]
   *   - 'file_field_refs': fid => [ref arrays]
   *   - 'media_uuids': media_id => uuid string
   */
  protected function preloadManagedFileBatch(array $file_rows): array {
    $fids = array_keys($file_rows);
    if (empty($fids)) {
      return [];
    }

    $result = [
      'file_usage' => $this->bulkQueryFileUsage($fids),
      'existing_temp' => $this->bulkQueryExistingTempItems($fids),
      'file_field_refs' => $this->bulkQueryFileFieldRefs($fids),
      'media_entity_refs' => [],
      'media_embed_refs' => [],
      'media_uuids' => [],
    ];

    // Extract media IDs from file_usage (type = 'media').
    $all_media_ids = [];
    foreach ($result['file_usage'] as $fid => $usages) {
      foreach ($usages as $row) {
        if ($row->type === 'media') {
          $all_media_ids[] = (int) $row->id;
        }
      }
    }
    $all_media_ids = array_unique($all_media_ids);

    // Bulk pre-query media entity references and CKEditor embeds.
    if (!empty($all_media_ids)) {
      $result['media_entity_refs'] = $this->bulkQueryMediaEntityRefs($all_media_ids);
      $result['media_uuids'] = $this->bulkQueryMediaUuids($all_media_ids);
      $result['media_embed_refs'] = $this->bulkQueryMediaEmbedRefs($result['media_uuids']);
    }

    return $result;
  }

  /**
   * Bulk-queries file_usage for a batch of fids.
   *
   * Returns ALL usage rows (media, file, node, etc.) — callers filter in PHP.
   * Replaces per-item queries in processManagedFile() and findDirectFileUsage().
   *
   * @param array $fids
   *   Array of file IDs.
   *
   * @return array
   *   Keyed by fid => array of stdClass rows (id, type, module, count).
   */
  protected function bulkQueryFileUsage(array $fids): array {
    $map = [];
    if (empty($fids)) {
      return $map;
    }

    $rows = $this->database->select('file_usage', 'fu')
      ->fields('fu', ['fid', 'id', 'type', 'module', 'count'])
      ->condition('fid', $fids, 'IN')
      ->execute()
      ->fetchAll();

    foreach ($rows as $row) {
      $map[$row->fid][] = $row;
    }

    return $map;
  }

  /**
   * Bulk-queries existing temp items by fid.
   *
   * @param array $fids
   *   Array of file IDs.
   *
   * @return array
   *   Keyed by fid => digital_asset_item.id.
   */
  protected function bulkQueryExistingTempItems(array $fids): array {
    if (empty($fids)) {
      return [];
    }

    return $this->database->select('digital_asset_item', 'dai')
      ->fields('dai', ['fid', 'id'])
      ->condition('fid', $fids, 'IN')
      ->condition('is_temp', 1)
      ->execute()
      ->fetchAllKeyed();  // fid => id
  }

  /**
   * Returns all file/image field data tables on the site.
   *
   * Cached for the scan duration. File fields have a _display column,
   * image fields have an _alt column — both have _target_id.
   *
   * @return array[]
   *   Array of ['table', 'column', 'entity_type', 'field_name'].
   */
  protected function getFileFieldTables(): array {
    if ($this->fileFieldTableCache !== NULL) {
      return $this->fileFieldTableCache;
    }

    $this->fileFieldTableCache = [];
    $db_schema = $this->database->schema();

    $prefixes = [
      'node__' => 'node',
      'taxonomy_term__' => 'taxonomy_term',
      'block_content__' => 'block_content',
    ];
    if ($this->moduleHandler->moduleExists('paragraphs')) {
      $prefixes['paragraph__'] = 'paragraph';
    }

    foreach ($prefixes as $prefix => $entity_type) {
      // Use Schema::findTables() for cross-database compatibility (D10/D11).
      $tables = $db_schema->findTables($prefix . '%');

      foreach ($tables as $table) {
        $field_name = substr($table, strlen($prefix));
        $target_col = $field_name . '_target_id';

        if ($db_schema->fieldExists($table, $target_col)) {
          // File fields have _display, image fields have _alt.
          $is_file_field = $db_schema->fieldExists($table, $field_name . '_display')
            || $db_schema->fieldExists($table, $field_name . '_alt');

          if ($is_file_field) {
            $this->fileFieldTableCache[] = [
              'table' => $table,
              'column' => $target_col,
              'entity_type' => $entity_type,
              'field_name' => $field_name,
            ];
          }
        }
      }
    }

    return $this->fileFieldTableCache;
  }

  /**
   * Bulk-queries file/image field tables for a batch of fids.
   *
   * For each file/image field table, runs one SELECT ... WHERE target_id IN (...).
   * Also serves as a current-revision filter: fids in file_usage but NOT in
   * any field data table are stale entries from old revisions and should be skipped.
   *
   * @param array $fids
   *   Array of file IDs.
   *
   * @return array
   *   Keyed by fid => [['entity_type', 'entity_id', 'field_name', 'bundle']].
   */
  protected function bulkQueryFileFieldRefs(array $fids): array {
    $map = [];
    if (empty($fids)) {
      return $map;
    }

    foreach ($this->getFileFieldTables() as $field_info) {
      try {
        $rows = $this->database->select($field_info['table'], 'f')
          ->fields('f', ['entity_id', $field_info['column'], 'bundle'])
          ->condition($field_info['column'], $fids, 'IN')
          ->execute()
          ->fetchAll();

        foreach ($rows as $row) {
          $fid = $row->{$field_info['column']};
          $map[$fid][] = [
            'entity_type' => $field_info['entity_type'],
            'entity_id' => (int) $row->entity_id,
            'field_name' => $field_info['field_name'],
            'bundle' => $row->bundle,
          ];
        }
      }
      catch (\Exception $e) {
        continue;
      }
    }

    return $map;
  }

  /**
   * Returns all entity reference field tables that target media entities.
   *
   * Cached for the scan duration.
   *
   * @return array[]
   *   Array of ['table', 'column', 'entity_type', 'field_name'].
   */
  protected function getMediaReferenceFieldTables(): array {
    if ($this->mediaRefFieldTableCache !== NULL) {
      return $this->mediaRefFieldTableCache;
    }

    $this->mediaRefFieldTableCache = [];
    $db_schema = $this->database->schema();

    $prefixes = [
      'node__' => 'node',
      'taxonomy_term__' => 'taxonomy_term',
      'block_content__' => 'block_content',
    ];
    if ($this->moduleHandler->moduleExists('paragraphs')) {
      $prefixes['paragraph__'] = 'paragraph';
    }

    // Load all field storage configs to find media-targeting entity_reference fields.
    $all_field_storages = $this->entityTypeManager
      ->getStorage('field_storage_config')
      ->loadMultiple();

    $media_field_names = [];
    foreach ($all_field_storages as $field_storage) {
      if ($field_storage->getType() === 'entity_reference'
          && $field_storage->getSetting('target_type') === 'media') {
        $media_field_names[$field_storage->getTargetEntityTypeId()][] = $field_storage->getName();
      }
    }

    foreach ($prefixes as $prefix => $entity_type) {
      $field_names = $media_field_names[$entity_type] ?? [];
      foreach ($field_names as $field_name) {
        $table = $entity_type . '__' . $field_name;
        $column = $field_name . '_target_id';

        if ($db_schema->tableExists($table) && $db_schema->fieldExists($table, $column)) {
          $this->mediaRefFieldTableCache[] = [
            'table' => $table,
            'column' => $column,
            'entity_type' => $entity_type,
            'field_name' => $field_name,
          ];
        }
      }
    }

    return $this->mediaRefFieldTableCache;
  }

  /**
   * Bulk-queries entity reference fields for a batch of media IDs.
   *
   * Replaces per-media EntityQueries in findMediaUsageViaEntityQuery() Part 1.
   *
   * @param array $media_ids
   *   Array of media entity IDs.
   *
   * @return array
   *   Keyed by media_id => [['entity_type', 'entity_id', 'field_name', 'bundle', 'method']].
   */
  protected function bulkQueryMediaEntityRefs(array $media_ids): array {
    $map = [];
    if (empty($media_ids)) {
      return $map;
    }

    foreach ($this->getMediaReferenceFieldTables() as $field_info) {
      try {
        $rows = $this->database->select($field_info['table'], 'f')
          ->fields('f', ['entity_id', $field_info['column'], 'bundle'])
          ->condition($field_info['column'], $media_ids, 'IN')
          ->execute()
          ->fetchAll();

        foreach ($rows as $row) {
          $mid = (int) $row->{$field_info['column']};
          $map[$mid][] = [
            'entity_type' => $field_info['entity_type'],
            'entity_id' => (int) $row->entity_id,
            'field_name' => $field_info['field_name'],
            'bundle' => $row->bundle,
            'method' => 'entity_reference',
          ];
        }
      }
      catch (\Exception $e) {
        continue;
      }
    }

    return $map;
  }

  /**
   * Bulk-queries media entity UUIDs for a batch of media IDs.
   *
   * @param array $media_ids
   *   Array of media entity IDs.
   *
   * @return array
   *   Keyed by media_id => uuid string.
   */
  protected function bulkQueryMediaUuids(array $media_ids): array {
    if (empty($media_ids)) {
      return [];
    }

    return $this->database->select('media', 'm')
      ->fields('m', ['mid', 'uuid'])
      ->condition('mid', $media_ids, 'IN')
      ->execute()
      ->fetchAllKeyed();  // mid => uuid
  }

  /**
   * Bulk-queries text fields for CKEditor <drupal-media> embeds.
   *
   * Replaces per-media LIKE queries in findMediaUsageViaEntityQuery() Part 2.
   * Queries each text-field table once with a broad LIKE '%data-entity-uuid%',
   * then matches specific UUIDs in PHP.
   *
   * @param array $media_uuids
   *   Keyed by media_id => uuid string.
   *
   * @return array
   *   Keyed by media_uuid => [['entity_type', 'entity_id', 'field_name', 'method']].
   */
  protected function bulkQueryMediaEmbedRefs(array $media_uuids): array {
    $map = [];
    if (empty($media_uuids)) {
      return $map;
    }

    $tables = $this->getTextFieldTables();

    foreach ($tables as $t) {
      try {
        $rows = $this->database->select($t['table'], 'tbl')
          ->fields('tbl', ['entity_id', $t['value_column']])
          ->condition($t['value_column'], '%data-entity-uuid%', 'LIKE')
          ->execute()
          ->fetchAll();
      }
      catch (\Exception $e) {
        continue;
      }

      if (empty($rows)) {
        continue;
      }

      // Match specific UUIDs in PHP.
      $value_col = $t['value_column'];
      foreach ($rows as $row) {
        $text = $row->$value_col;
        foreach ($media_uuids as $mid => $uuid) {
          if (strpos($text, $uuid) !== FALSE) {
            $map[$uuid][] = [
              'entity_type' => $t['entity_type'],
              'entity_id' => (int) $row->entity_id,
              'field_name' => $t['field_name'],
              'method' => 'media_embed',
            ];
          }
        }
      }
    }

    return $map;
  }

  // =====================================================================
  // End bulk read methods.
  // =====================================================================

  /**
   * Logs per-request batch timing diagnostics.
   *
   * Debug-level — zero overhead in production unless debug logging is enabled.
   *
   * @param int $phase
   *   Phase number (1-6).
   * @param int $itemsProcessed
   *   Items processed in this callback.
   * @param float $callbackStartTime
   *   microtime(true) at callback entry.
   * @param string|int $cursor
   *   Current cursor value (varies by phase).
   */
  public function logBatchTiming(int $phase, int $itemsProcessed, float $callbackStartTime, string|int $cursor): void {
    $this->logger->debug('Batch request complete. Phase: @phase, Items: @items, Elapsed: @elapsed s, Cursor: @cursor, Heartbeat writes: @hb, Time Budget: @budget s', [
      '@phase' => $phase,
      '@items' => $itemsProcessed,
      '@elapsed' => round(microtime(true) - $callbackStartTime, 2),
      '@cursor' => $cursor,
      '@hb' => $this->getHeartbeatWriteCount(),
      '@budget' => $this->getBatchTimeBudget(),
    ]);
  }

  /**
   * Gets count of managed files to scan.
   *
   * @return int
   *   The number of managed files.
   */
  public function getManagedFilesCount() {
    $query = $this->database->select('file_managed', 'f');

    // Exclude system-generated files by path.
    $this->excludeSystemGeneratedFiles($query);

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Bulk-loads managed file rows by fid.
   *
   * Uses a single DB query instead of per-fid loads. Returns stdClass
   * objects keyed by fid, matching the format processManagedFile() expects.
   *
   * @param array $fids
   *   Array of file IDs to load.
   *
   * @return array
   *   Array of stdClass objects keyed by fid.
   */
  protected function loadManagedFileRows(array $fids): array {
    if (empty($fids)) {
      return [];
    }
    $rows = $this->database->select('file_managed', 'f')
      ->fields('f', ['fid', 'uri', 'filemime', 'filename', 'filesize'])
      ->condition('fid', $fids, 'IN')
      ->execute()
      ->fetchAllAssoc('fid');
    return $rows;
  }

  /**
   * Gets managed file IDs after a given fid, for cursor-based pagination.
   *
   * @param int $lastFid
   *   Last processed file ID (exclusive lower bound).
   * @param int $limit
   *   Maximum number of IDs to return.
   *
   * @return array
   *   Array of fid values.
   */
  protected function getManagedFileIdsAfter(int $lastFid, int $limit): array {
    $query = $this->database->select('file_managed', 'f')
      ->fields('f', ['fid'])
      ->condition('fid', $lastFid, '>')
      ->orderBy('fid', 'ASC')
      ->range(0, $limit);

    // Exclude system-generated files by path (same conditions as getManagedFilesCount).
    $this->excludeSystemGeneratedFiles($query);

    return $query->execute()->fetchCol();
  }

  /**
   * Processes a single managed file record.
   *
   * @param object $file
   *   A stdClass file record with properties: fid, uri, filemime, filename, filesize.
   *   Bulk-loaded by the caller via loadManagedFileRows().
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param array &$context
   *   Batch API context (used for orphan counting in sandbox).
   */
  protected function processManagedFile(object $file, bool $is_temp, array &$context, ?array $preloaded = NULL): void {
    // Storage references kept for registerDerivedFileUsage() which still uses Entity API.
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    // Determine asset type using whitelist mapper.
    $asset_type = $this->mapMimeToAssetType($file->filemime);
    $category = $this->mapAssetTypeToCategory($asset_type);
    $sort_order = $this->getCategorySortOrder($category);

    // Check if this file is associated with a Media entity.
    // Use pre-loaded file_usage data when available (bulk reads, Fix 1).
    $source_type = 'file_managed';
    $media_id = NULL;
    $all_media_ids = [];

    if ($preloaded) {
      foreach (($preloaded['file_usage'][$file->fid] ?? []) as $row) {
        if ($row->type === 'media') {
          $all_media_ids[] = $row->id;
        }
      }
    }
    else {
      $all_media_ids = $this->database->select('file_usage', 'fu')
        ->fields('fu', ['id'])
        ->condition('fid', $file->fid)
        ->condition('type', 'media')
        ->execute()
        ->fetchCol();
    }

    if (!empty($all_media_ids)) {
      $source_type = 'media_managed';
      $media_id = reset($all_media_ids);
    }

    // Convert URI to absolute URL for storage.
    try {
      $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($file->uri);
    }
    catch (\Exception $e) {
      $absolute_url = $file->uri;
    }

    $is_private = strpos($file->uri, 'private://') === 0;

    // --- Raw SQL item insert/update (bypasses Entity API) ---
    $item_fields = [
      'fid' => $file->fid,
      'source_type' => $source_type,
      'media_id' => $media_id,
      'asset_type' => $asset_type,
      'category' => $category,
      'sort_order' => $sort_order,
      'file_path' => $absolute_url,
      'file_name' => $file->filename,
      'mime_type' => $file->filemime,
      'filesize' => $file->filesize,
      'is_temp' => $is_temp ? 1 : 0,
      'is_private' => $is_private ? 1 : 0,
    ];

    // Check for existing TEMP item by fid — use pre-loaded data or raw query.
    $existing_id = $preloaded
      ? ($preloaded['existing_temp'][$file->fid] ?? NULL)
      : $this->database->select('digital_asset_item', 'dai')
          ->fields('dai', ['id'])
          ->condition('fid', $file->fid)
          ->condition('is_temp', 1)
          ->execute()
          ->fetchField();

    if ($existing_id) {
      $this->rawUpdateAssetItem((int) $existing_id, $item_fields);
      $asset_id = (int) $existing_id;
    }
    else {
      $asset_id = $this->rawInsertAssetItem($item_fields);
    }

    // Clear existing usage and orphan records — single raw SQL DELETEs.
    $this->rawDeleteUsageByAssetId($asset_id);
    $this->rawDeleteOrphanRefsByAssetId($asset_id);

    // Track unique usage keys within this item to avoid duplicates
    // across direct file usage and media usage code paths.
    $seen_usage = [];

    // Check for direct file/image field usage.
    // Use pre-loaded data when available (bulk reads, Fix 1).
    if ($preloaded) {
      $direct_file_usage = [];
      foreach (($preloaded['file_usage'][$file->fid] ?? []) as $row) {
        if ($row->type === 'media') {
          continue;
        }
        if (!in_array($row->type, ['node', 'paragraph', 'taxonomy_term', 'block_content'])) {
          continue;
        }
        // Find field name from pre-queried file field refs.
        // If fid is not in any current-revision field table, this is a stale
        // file_usage entry from an old revision — skip it.
        $field_name = 'direct_file';
        foreach (($preloaded['file_field_refs'][$file->fid] ?? []) as $ref) {
          if ($ref['entity_type'] === $row->type && $ref['entity_id'] === (int) $row->id) {
            $field_name = $ref['field_name'];
            break;
          }
        }
        if ($field_name === 'direct_file') {
          continue;
        }
        $direct_file_usage[] = [
          'entity_type' => $row->type,
          'entity_id' => (int) $row->id,
          'field_name' => $field_name,
          'method' => 'file_usage',
        ];
      }
    }
    else {
      $direct_file_usage = $this->findDirectFileUsage($file->fid);
    }

    foreach ($direct_file_usage as $ref) {
      $parent_entity_type = $ref['entity_type'];
      $parent_entity_id = $ref['entity_id'];

      if ($parent_entity_type === 'paragraph') {
        if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
        }
        $parent_info = $this->paragraphParentCache[$parent_entity_id];
        if ($parent_info && empty($parent_info['orphan'])) {
          $parent_entity_type = $parent_info['type'];
          $parent_entity_id = $parent_info['id'];
        }
        elseif ($parent_info && !empty($parent_info['orphan'])) {
          $this->createOrphanReference($asset_id, 'paragraph', $parent_entity_id, $ref['field_name'], 'field_reference', $parent_info['context']);
          continue;
        }
        else {
          continue;
        }
      }

      $usage_key = $parent_entity_type . ':' . $parent_entity_id . ':' . $ref['field_name'];
      if (!isset($seen_usage[$usage_key])) {
        $seen_usage[$usage_key] = TRUE;
        $this->bufferUsageRecord([
          'asset_id' => $asset_id,
          'entity_type' => $parent_entity_type,
          'entity_id' => $parent_entity_id,
          'field_name' => $ref['field_name'],
          'embed_method' => 'field_reference',
        ]);
      }
    }

    // For media files, also find usage via entity reference and media embeds.
    // Use pre-loaded data when available (bulk reads, Fix 1).
    if (!empty($all_media_ids)) {
      $media_references = [];
      if ($preloaded) {
        foreach ($all_media_ids as $mid) {
          // Part 1: entity reference fields.
          foreach (($preloaded['media_entity_refs'][$mid] ?? []) as $ref) {
            $media_references[] = $ref;
          }
          // Part 2: CKEditor <drupal-media> embeds.
          $uuid = $preloaded['media_uuids'][$mid] ?? NULL;
          if ($uuid) {
            foreach (($preloaded['media_embed_refs'][$uuid] ?? []) as $ref) {
              $media_references[] = $ref;
            }
          }
        }
      }
      else {
        foreach ($all_media_ids as $mid) {
          $refs = $this->findMediaUsageViaEntityQuery($mid);
          $media_references = array_merge($media_references, $refs);
        }
      }

      $unique_refs = [];
      foreach ($media_references as $ref) {
        $field_name = $ref['field_name'] ?? 'media';
        $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . $field_name;
        if (!isset($unique_refs[$key])) {
          $unique_refs[$key] = $ref;
        }
      }
      $media_references = array_values($unique_refs);

      foreach ($media_references as $ref) {
        $parent_entity_type = $ref['entity_type'];
        $parent_entity_id = $ref['entity_id'];
        $field_name = $ref['field_name'] ?? 'media';

        if ($parent_entity_type === 'paragraph') {
          if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
            $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
          }
          $parent_info = $this->paragraphParentCache[$parent_entity_id];
          if ($parent_info && empty($parent_info['orphan'])) {
            $parent_entity_type = $parent_info['type'];
            $parent_entity_id = $parent_info['id'];
          }
          elseif ($parent_info && !empty($parent_info['orphan'])) {
            $ref_embed = (isset($ref['method']) && $ref['method'] === 'media_embed') ? 'drupal_media' : 'field_reference';
            $this->createOrphanReference($asset_id, 'paragraph', $parent_entity_id, $field_name, $ref_embed, $parent_info['context']);
            continue;
          }
          else {
            continue;
          }
        }

        $embed_method = 'field_reference';
        if (isset($ref['method']) && $ref['method'] === 'media_embed') {
          $embed_method = 'drupal_media';
        }

        $usage_key = $parent_entity_type . ':' . $parent_entity_id . ':' . $field_name;
        if (!isset($seen_usage[$usage_key])) {
          $seen_usage[$usage_key] = TRUE;
          $this->bufferUsageRecord([
            'asset_id' => $asset_id,
            'entity_type' => $parent_entity_type,
            'entity_id' => $parent_entity_id,
            'field_name' => $field_name,
            'embed_method' => $embed_method,
          ]);
        }
      }

      // Detect thumbnail file references on Media entities.
      // registerDerivedFileUsage() still uses Entity API — called infrequently.
      $media_storage = $this->entityTypeManager->getStorage('media');
      $media_entities = $media_storage->loadMultiple($all_media_ids);
      foreach ($media_entities as $media_entity) {
        if (!$media_entity->hasField('thumbnail') || $media_entity->get('thumbnail')->isEmpty()) {
          continue;
        }
        $thumbnail_fid = $media_entity->get('thumbnail')->target_id;
        if (!$thumbnail_fid || (int) $thumbnail_fid === (int) $file->fid) {
          continue;
        }
        $this->registerDerivedFileUsage(
          (int) $thumbnail_fid,
          (int) $media_entity->id(),
          'thumbnail',
          'derived_thumbnail',
          $is_temp,
          $storage,
          $usage_storage
        );
      }

      // Provider: pdf_image_entity.
      if ($this->entityTypeManager->hasDefinition('pdf_image_entity')) {
        $pdf_image_ids = $this->entityTypeManager->getStorage('pdf_image_entity')
          ->getQuery()
          ->condition('referenced_entity_type', 'media')
          ->condition('referenced_entity_id', $all_media_ids, 'IN')
          ->accessCheck(FALSE)
          ->execute();

        if (!empty($pdf_image_ids)) {
          $pdf_images = $this->entityTypeManager->getStorage('pdf_image_entity')
            ->loadMultiple($pdf_image_ids);
          foreach ($pdf_images as $pdf_image) {
            $image_fid = $pdf_image->get('image_file_id')->value;
            $ref_mid = $pdf_image->get('referenced_entity_id')->value;
            if (!$image_fid || (int) $image_fid === (int) $file->fid) {
              continue;
            }
            $this->registerDerivedFileUsage(
              (int) $image_fid,
              (int) $ref_mid,
              'pdf_thumbnail',
              'derived_thumbnail',
              $is_temp,
              $storage,
              $usage_storage
            );
          }
        }
      }
    }

    // Reverse thumbnail check.
    if (empty($all_media_ids)) {
      $thumbnail_media_ids = $this->entityTypeManager->getStorage('media')
        ->getQuery()
        ->condition('thumbnail.target_id', $file->fid)
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($thumbnail_media_ids)) {
        $first_mid = reset($thumbnail_media_ids);
        $this->rawUpdateAssetItem($asset_id, [
          'source_type' => 'media_managed',
          'media_id' => $first_mid,
        ]);

        $media_storage = $this->entityTypeManager->getStorage('media');
        foreach ($media_storage->loadMultiple($thumbnail_media_ids) as $thumb_media) {
          $this->bufferUsageRecord([
            'asset_id' => $asset_id,
            'entity_type' => 'media',
            'entity_id' => $thumb_media->id(),
            'field_name' => 'thumbnail',
            'embed_method' => 'derived_thumbnail',
          ]);
        }
      }

      // Provider: pdf_image_entity — reverse detection.
      if (empty($thumbnail_media_ids) && $this->entityTypeManager->hasDefinition('pdf_image_entity')) {
        $pdf_image_ids = $this->entityTypeManager->getStorage('pdf_image_entity')
          ->getQuery()
          ->condition('image_file_id', (string) $file->fid)
          ->accessCheck(FALSE)
          ->execute();

        if (!empty($pdf_image_ids)) {
          $pdf_images = $this->entityTypeManager->getStorage('pdf_image_entity')
            ->loadMultiple($pdf_image_ids);
          $first = reset($pdf_images);
          $first_mid = (int) $first->get('referenced_entity_id')->value;

          $this->rawUpdateAssetItem($asset_id, [
            'source_type' => 'media_managed',
            'media_id' => $first_mid,
          ]);

          foreach ($pdf_images as $pdf_image) {
            $ref_mid = (int) $pdf_image->get('referenced_entity_id')->value;
            $this->bufferUsageRecord([
              'asset_id' => $asset_id,
              'entity_type' => 'media',
              'entity_id' => $ref_mid,
              'field_name' => 'pdf_thumbnail',
              'embed_method' => 'derived_thumbnail',
            ]);
          }
        }
      }
    }

    // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).
  }

  /**
   * Scans managed files using time-budgeted cursor-based processing.
   *
   * @param array &$context
   *   Batch API context array.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */
  public function scanManagedFilesChunk(array &$context, bool $is_temp): void {
    // Initialize sandbox orphan counter for FR-4.
    if (!isset($context['sandbox']['orphan_paragraph_count'])) {
      $context['sandbox']['orphan_paragraph_count'] = 0;
    }

    $itemsThisCallback = $this->processWithTimeBudget(
      $context,
      'last_fid',
      'total_files',
      fn() => $this->getManagedFilesCount(),
      fn(int $lastFid, int $limit) => $this->getManagedFileIdsAfter($lastFid, $limit),
      fn(array $ids) => $this->loadManagedFileRows($ids),
      fn(object $file, ?array $preloaded) => $this->processManagedFile($file, $is_temp, $context, $preloaded),
      fn(array $entities) => $this->preloadManagedFileBatch($entities),
    );

    // Flush buffered usage records in one bulk INSERT.
    $this->flushUsageBuffer();

    // Flush buffered orphan reference records in one bulk INSERT.
    $this->flushOrphanRefBuffer();

    // FR-4: Accumulate orphan count from this callback into sandbox,
    // then persist the running total once per callback (not per item).
    $context['sandbox']['orphan_paragraph_count'] += $this->currentOrphanCount;
    $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
    if ($sessionId && $context['sandbox']['orphan_paragraph_count'] > 0) {
      $this->persistOrphanCount($sessionId, $context['sandbox']['orphan_paragraph_count']);
    }

    // FR-6: Cache resets.
    $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'media', 'file']);
    if ($itemsThisCallback >= 50) {
      drupal_static_reset();
    }

    $context['results']['last_chunk_items'] = $itemsThisCallback;
  }

  /**
   * Scans a chunk of managed files (legacy offset/limit signature).
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of files to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Number of items processed.
   *
   * @deprecated Use scanManagedFilesChunk(array &$context, bool $is_temp) instead.
   */
  public function scanManagedFilesChunkLegacy($offset, $limit, $is_temp = FALSE) {
    $count = 0;
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    $query = $this->database->select('file_managed', 'f');
    $query->fields('f', ['fid', 'uri', 'filemime', 'filename', 'filesize']);

    // Exclude system-generated files by path.
    $this->excludeSystemGeneratedFiles($query);

    $query->range($offset, $limit);
    $results = $query->execute();

    foreach ($results as $file) {
      // Determine asset type using whitelist mapper.
      $asset_type = $this->mapMimeToAssetType($file->filemime);

      // Determine category from asset type.
      $category = $this->mapAssetTypeToCategory($asset_type);

      // Determine sort order from category.
      $sort_order = $this->getCategorySortOrder($category);

      // Check if this file is associated with a Media entity.
      $source_type = 'file_managed';
      $media_id = NULL;
      $all_media_ids = [];

      // Query file_usage for ALL media associations (handles multilingual sites
      // where same file may be used by multiple media entities).
      $media_usages = $this->database->select('file_usage', 'fu')
        ->fields('fu', ['id'])
        ->condition('fid', $file->fid)
        ->condition('type', 'media')
        ->execute()
        ->fetchCol();

      if (!empty($media_usages)) {
        $source_type = 'media_managed';
        // Store first media_id for backwards compatibility (entity field).
        $media_id = reset($media_usages);
        // Store all media IDs for comprehensive usage detection.
        $all_media_ids = $media_usages;
      }

      // Convert URI to absolute URL for storage.
      try {
        $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($file->uri);
      }
      catch (\Exception $e) {
        // Fallback to URI if conversion fails.
        $absolute_url = $file->uri;
      }

      // Check if file is in the private file system.
      $is_private = strpos($file->uri, 'private://') === 0;

      // Find existing TEMP entity by fid field (not entity ID).
      // Only update temp items - never modify permanent items during scan.
      // This ensures permanent items remain intact if scan fails.
      $existing_query = $storage->getQuery()
        ->condition('fid', $file->fid)
        ->condition('is_temp', TRUE)
        ->accessCheck(FALSE)
        ->execute();

      if ($existing_ids = $existing_query) {
        // Update existing temp entity.
        $existing = $storage->load(reset($existing_ids));
        $item = $existing;
        $item->set('source_type', $source_type);
        $item->set('media_id', $media_id);
        $item->set('asset_type', $asset_type);
        $item->set('category', $category);
        $item->set('sort_order', $sort_order);
        $item->set('file_path', $absolute_url);
        $item->set('file_name', $file->filename);
        $item->set('mime_type', $file->filemime);
        $item->set('filesize', $file->filesize);
        $item->set('is_private', $is_private);
      }
      else {
        // Create new entity.
        $item = $storage->create([
          'fid' => $file->fid,
          'source_type' => $source_type,
          'media_id' => $media_id,
          'asset_type' => $asset_type,
          'category' => $category,
          'sort_order' => $sort_order,
          'file_path' => $absolute_url,
          'file_name' => $file->filename,
          'mime_type' => $file->filemime,
          'filesize' => $file->filesize,
          'is_temp' => $is_temp,
          'is_private' => $is_private,
        ]);
      }

      $item->save();
      $asset_id = $item->id();

      // IMPORTANT: Clear existing usage records for this asset before
      // re-scanning. This ensures deleted references don't persist.
      $old_usage_query = $usage_storage->getQuery();
      $old_usage_query->condition('asset_id', $asset_id);
      $old_usage_query->accessCheck(FALSE);
      $old_usage_ids = $old_usage_query->execute();

      if ($old_usage_ids) {
        $old_usages = $usage_storage->loadMultiple($old_usage_ids);
        $usage_storage->delete($old_usages);
      }

      // NOTE: Text field links (<a href>) are now detected in Phase 3 (scanContentChunk)
      // via extractLocalFileUrls() + processLocalFileLink() with proper embed_method tracking.
      // Removed findLocalFileLinkUsage() call here to avoid duplicate detection.

      // Check for direct file/image field usage (not via media).
      // Detects files in direct 'image' or 'file' fields like field_image.
      $direct_file_usage = $this->findDirectFileUsage($file->fid);

      foreach ($direct_file_usage as $ref) {
        // Trace paragraphs to their parent nodes.
        $parent_entity_type = $ref['entity_type'];
        $parent_entity_id = $ref['entity_id'];

        if ($parent_entity_type === 'paragraph') {
          if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
        }
        $parent_info = $this->paragraphParentCache[$parent_entity_id];
          if ($parent_info && empty($parent_info['orphan'])) {
            $parent_entity_type = $parent_info['type'];
            $parent_entity_id = $parent_info['id'];
          }
          elseif ($parent_info && !empty($parent_info['orphan'])) {
            // Orphan detected — create orphan reference record.
            $this->createOrphanReference($asset_id, 'paragraph', $parent_entity_id, $ref['field_name'], 'field_reference', $parent_info['context']);
            continue;
          }
          else {
            // Paragraph not found (NULL) — skip.
            continue;
          }
        }

        // Check if usage record already exists for this entity.
        $existing_usage_query = $usage_storage->getQuery();
        $existing_usage_query->condition('asset_id', $asset_id);
        $existing_usage_query->condition('entity_type', $parent_entity_type);
        $existing_usage_query->condition('entity_id', $parent_entity_id);
        $existing_usage_query->accessCheck(FALSE);
        $existing_usage_ids = $existing_usage_query->execute();

        if (!$existing_usage_ids) {
          // Create usage record showing where file is used directly.
          // These are from file/image fields (via findDirectFileUsage).
          $usage_storage->create([
            'asset_id' => $asset_id,
            'entity_type' => $parent_entity_type,
            'entity_id' => $parent_entity_id,
            'field_name' => $ref['field_name'],
            'count' => 1,
            'embed_method' => 'field_reference',
          ])->save();
        }
      }

      // For media files, also find usage via entity reference and media embeds.
      if (!empty($all_media_ids)) {
        // Clear only NON-direct-file usage records before re-scanning media
        // references. Direct file/image field usage (from findDirectFileUsage
        // above) must be preserved since the media scan does not rediscover
        // those references. Only delete records that will be re-created by
        // the media reference scan below.
        $old_usage_query = $usage_storage->getQuery();
        $old_usage_query->condition('asset_id', $asset_id);
        $old_usage_query->accessCheck(FALSE);
        $old_usage_ids = $old_usage_query->execute();

        if ($old_usage_ids) {
          $old_usages = $usage_storage->loadMultiple($old_usage_ids);
          // Collect IDs of direct file usage records to preserve.
          $direct_field_keys = [];
          foreach ($direct_file_usage as $ref) {
            $direct_field_keys[] = ($ref['entity_type'] ?? '') . ':' . ($ref['entity_id'] ?? '') . ':' . ($ref['field_name'] ?? '');
          }
          foreach ($old_usages as $old_usage) {
            $key = $old_usage->get('entity_type')->value . ':' . $old_usage->get('entity_id')->value . ':' . $old_usage->get('field_name')->value;
            if (!in_array($key, $direct_field_keys)) {
              $old_usage->delete();
            }
          }
        }

        // Find all media references from ALL associated media entities.
        // This handles multilingual sites where same file has multiple media entities.
        $media_references = [];
        foreach ($all_media_ids as $mid) {
          $refs = $this->findMediaUsageViaEntityQuery($mid);
          $media_references = array_merge($media_references, $refs);
        }

        // Deduplicate references by entity+field combination.
        // This preserves field_name info while avoiding duplicate records.
        $unique_refs = [];
        foreach ($media_references as $ref) {
          $field_name = $ref['field_name'] ?? 'media';
          $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . $field_name;
          if (!isset($unique_refs[$key])) {
            $unique_refs[$key] = $ref;
          }
        }
        $media_references = array_values($unique_refs);

        foreach ($media_references as $ref) {
          // Trace paragraphs to their parent nodes.
          $parent_entity_type = $ref['entity_type'];
          $parent_entity_id = $ref['entity_id'];
          $field_name = $ref['field_name'] ?? 'media';

          if ($parent_entity_type === 'paragraph') {
            if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
        }
        $parent_info = $this->paragraphParentCache[$parent_entity_id];
            if ($parent_info && empty($parent_info['orphan'])) {
              $parent_entity_type = $parent_info['type'];
              $parent_entity_id = $parent_info['id'];
            }
            elseif ($parent_info && !empty($parent_info['orphan'])) {
              // Orphan detected — create orphan reference record.
              $ref_embed = (isset($ref['method']) && $ref['method'] === 'media_embed') ? 'drupal_media' : 'field_reference';
              $this->createOrphanReference($asset_id, 'paragraph', $parent_entity_id, $field_name, $ref_embed, $parent_info['context']);
              continue;
            }
            else {
              // Paragraph not found (NULL) — skip.
              continue;
            }
          }

          // Check if usage record already exists for this entity+field.
          $existing_usage_query = $usage_storage->getQuery();
          $existing_usage_query->condition('asset_id', $asset_id);
          $existing_usage_query->condition('entity_type', $parent_entity_type);
          $existing_usage_query->condition('entity_id', $parent_entity_id);
          $existing_usage_query->condition('field_name', $field_name);
          $existing_usage_query->accessCheck(FALSE);
          $existing_usage_ids = $existing_usage_query->execute();

          if (!$existing_usage_ids) {
            // Map reference method to embed_method field value.
            $embed_method = 'field_reference';
            if (isset($ref['method']) && $ref['method'] === 'media_embed') {
              $embed_method = 'drupal_media';
            }

            // Create usage record showing where media is used.
            $usage_storage->create([
              'asset_id' => $asset_id,
              'entity_type' => $parent_entity_type,
              'entity_id' => $parent_entity_id,
              'field_name' => $field_name,
              'count' => 1,
              'embed_method' => $embed_method,
            ])->save();
          }
        }

        // Detect thumbnail file references on Media entities.
        // Thumbnail files live in excluded directories but are discovered
        // through entity relationships (relationship-driven inclusion).
        $media_storage = $this->entityTypeManager->getStorage('media');
        $media_entities = $media_storage->loadMultiple($all_media_ids);
        foreach ($media_entities as $media_entity) {
          if (!$media_entity->hasField('thumbnail') || $media_entity->get('thumbnail')->isEmpty()) {
            continue;
          }
          $thumbnail_fid = $media_entity->get('thumbnail')->target_id;
          if (!$thumbnail_fid || (int) $thumbnail_fid === (int) $file->fid) {
            // Skip if no target or same file already being processed.
            continue;
          }
          $this->registerDerivedFileUsage(
            (int) $thumbnail_fid,
            (int) $media_entity->id(),
            'thumbnail',
            'derived_thumbnail',
            $is_temp,
            $storage,
            $usage_storage
          );
        }

        // Provider: pdf_image_entity — detect contrib-generated PDF preview images.
        // Activates only when the entity type is discoverable (capability check).
        if ($this->entityTypeManager->hasDefinition('pdf_image_entity')) {
          $pdf_image_ids = $this->entityTypeManager->getStorage('pdf_image_entity')
            ->getQuery()
            ->condition('referenced_entity_type', 'media')
            ->condition('referenced_entity_id', $all_media_ids, 'IN')
            ->accessCheck(FALSE)
            ->execute();

          if (!empty($pdf_image_ids)) {
            $pdf_images = $this->entityTypeManager->getStorage('pdf_image_entity')
              ->loadMultiple($pdf_image_ids);
            foreach ($pdf_images as $pdf_image) {
              $image_fid = $pdf_image->get('image_file_id')->value;
              $ref_mid = $pdf_image->get('referenced_entity_id')->value;
              if (!$image_fid || (int) $image_fid === (int) $file->fid) {
                continue;
              }
              $this->registerDerivedFileUsage(
                (int) $image_fid,
                (int) $ref_mid,
                'pdf_thumbnail',
                'derived_thumbnail',
                $is_temp,
                $storage,
                $usage_storage
              );
            }
          }
        }
      }

      // Reverse thumbnail check: detect if this file IS a Media thumbnail.
      // Handles thumbnail files in non-excluded directories that may lack
      // file_usage entries (e.g., contributed module-generated PDF previews).
      // Without this check, such files appear as "Not In Use" and could be
      // accidentally deleted, breaking pages that display the thumbnail.
      if (empty($all_media_ids)) {
        $thumbnail_media_ids = $this->entityTypeManager->getStorage('media')
          ->getQuery()
          ->condition('thumbnail.target_id', $file->fid)
          ->accessCheck(FALSE)
          ->execute();

        if (!empty($thumbnail_media_ids)) {
          // Update source_type to media_managed since this is a media file.
          $first_mid = reset($thumbnail_media_ids);
          $item->set('source_type', 'media_managed');
          $item->set('media_id', $first_mid);
          $item->save();

          $media_storage = $this->entityTypeManager->getStorage('media');
          foreach ($media_storage->loadMultiple($thumbnail_media_ids) as $thumb_media) {
            // Dedup check for derived_thumbnail usage.
            $existing_usage_query = $usage_storage->getQuery();
            $existing_usage_query->condition('asset_id', $asset_id);
            $existing_usage_query->condition('entity_type', 'media');
            $existing_usage_query->condition('entity_id', $thumb_media->id());
            $existing_usage_query->condition('field_name', 'thumbnail');
            $existing_usage_query->condition('embed_method', 'derived_thumbnail');
            $existing_usage_query->accessCheck(FALSE);
            if (!$existing_usage_query->execute()) {
              $usage_storage->create([
                'asset_id' => $asset_id,
                'entity_type' => 'media',
                'entity_id' => $thumb_media->id(),
                'field_name' => 'thumbnail',
                'count' => 1,
                'embed_method' => 'derived_thumbnail',
              ])->save();
            }
          }
        }

        // Provider: pdf_image_entity — reverse detection for preview images.
        if (empty($thumbnail_media_ids) && $this->entityTypeManager->hasDefinition('pdf_image_entity')) {
          $pdf_image_ids = $this->entityTypeManager->getStorage('pdf_image_entity')
            ->getQuery()
            ->condition('image_file_id', (string) $file->fid)
            ->accessCheck(FALSE)
            ->execute();

          if (!empty($pdf_image_ids)) {
            $pdf_images = $this->entityTypeManager->getStorage('pdf_image_entity')
              ->loadMultiple($pdf_image_ids);
            $first = reset($pdf_images);
            $first_mid = (int) $first->get('referenced_entity_id')->value;

            $item->set('source_type', 'media_managed');
            $item->set('media_id', $first_mid);
            $item->save();

            foreach ($pdf_images as $pdf_image) {
              $ref_mid = (int) $pdf_image->get('referenced_entity_id')->value;
              $existing = $usage_storage->getQuery()
                ->condition('asset_id', $asset_id)
                ->condition('entity_type', 'media')
                ->condition('entity_id', $ref_mid)
                ->condition('field_name', 'pdf_thumbnail')
                ->condition('embed_method', 'derived_thumbnail')
                ->accessCheck(FALSE)
                ->execute();

              if (!$existing) {
                $usage_storage->create([
                  'asset_id' => $asset_id,
                  'entity_type' => 'media',
                  'entity_id' => $ref_mid,
                  'field_name' => 'pdf_thumbnail',
                  'count' => 1,
                  'embed_method' => 'derived_thumbnail',
                ])->save();
              }
            }
          }
        }
      }

      // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

      $count++;
    }

    // Flush buffered records before cache reset.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();

    // Reset entity caches to prevent memory exhaustion in long batch runs.
    $this->resetEntityCaches([
      'digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference',
      'media', 'file',
    ]);

    return $count;
  }

  /**
   * Maps a MIME type to a normalized asset type using whitelist approach.
   *
   * @param string $mime
   *   The MIME type from file_managed.filemime.
   *
   * @return string
   *   Asset type: pdf, word, excel, powerpoint, text, csv, jpg, png, gif, svg,
   *   webp, mp4, webm, mov, avi, mp3, wav, m4a, ogg, or 'other'.
   */
  protected function mapMimeToAssetType($mime) {
    $mime = strtolower(trim($mime));

    // Whitelist of known MIME types mapped to granular asset types.
    $map = [
      // Documents.
      'application/pdf' => 'pdf',
      'application/msword' => 'word',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
      'application/vnd.ms-excel' => 'excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',
      'application/vnd.ms-powerpoint' => 'powerpoint',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'powerpoint',
      'text/plain' => 'text',
      'text/csv' => 'csv',
      'application/csv' => 'csv',
      // Caption/subtitle files.
      'text/vtt' => 'vtt',
      'application/x-subrip' => 'srt',
      'text/srt' => 'srt',

      // Images - granular types.
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/svg+xml' => 'svg',
      'image/webp' => 'webp',

      // Videos - granular types.
      'video/mp4' => 'mp4',
      'video/webm' => 'webm',
      'video/quicktime' => 'mov',
      'video/x-msvideo' => 'avi',

      // Audio - granular types.
      'audio/mpeg' => 'mp3',
      'audio/wav' => 'wav',
      'audio/mp4' => 'm4a',
      'audio/ogg' => 'ogg',

      // Compressed files - categorized under 'Other' category.
      'application/zip' => 'compressed',
      'application/x-tar' => 'compressed',
      'application/gzip' => 'compressed',
      'application/x-7z-compressed' => 'compressed',
      'application/x-rar-compressed' => 'compressed',
      'application/x-gzip' => 'compressed',
    ];

    // Check exact match first.
    if (isset($map[$mime])) {
      return $map[$mime];
    }

    // Default: unrecognized MIME type.
    return 'other';
  }

  /**
   * Maps asset type to category based on configuration.
   *
   * @param string $asset_type
   *   The asset type (pdf, word, image, etc.).
   *
   * @return string
   *   Category: Documents, Media, or Unknown.
   */
  protected function mapAssetTypeToCategory($asset_type) {
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $asset_types = $config->get('asset_types');

    // Look up category from config.
    if ($asset_types && isset($asset_types[$asset_type]['category'])) {
      return $asset_types[$asset_type]['category'];
    }

    // Default fallback.
    return 'Unknown';
  }

  /**
   * Matches a URL to an asset type based on URL patterns in configuration.
   *
   * @param string $url
   *   The URL to match.
   *
   * @return string
   *   Asset type: google_doc, youtube, vimeo, etc., or 'other'.
   */
  protected function matchUrlToAssetType($url) {
    $url = strtolower(trim($url));
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $asset_types = $config->get('asset_types');

    if (!$asset_types) {
      return 'other';
    }

    // Check each asset type's URL patterns.
    foreach ($asset_types as $type => $settings) {
      if (isset($settings['url_patterns']) && is_array($settings['url_patterns'])) {
        foreach ($settings['url_patterns'] as $pattern) {
          if (strpos($url, $pattern) !== FALSE) {
            return $type;
          }
        }
      }
    }

    return 'other';
  }

  /**
   * Extracts URLs from text content.
   *
   * @param string $text
   *   The text to scan for URLs.
   *
   * @return array
   *   Array of unique URLs found.
   */
  protected function extractUrls($text) {
    $urls = [];

    // Pattern to match URLs.
    $pattern = '/https?:\/\/[^\s<>"{}|\\^`\[\]]+/i';

    if (preg_match_all($pattern, $text, $matches)) {
      $urls = array_unique($matches[0]);
    }

    return $urls;
  }

  /**
   * Normalizes a video URL to a canonical form for consistent tracking.
   *
   * This ensures the same video is tracked as a single asset regardless
   * of URL format (full URL, short URL, embed URL, or video ID).
   *
   * Canonical forms:
   * - YouTube: https://www.youtube.com/watch?v=VIDEO_ID
   * - Vimeo: https://vimeo.com/VIDEO_ID
   *
   * @param string $url
   *   The URL or video ID to normalize.
   *
   * @return array|null
   *   Array with 'url' (canonical), 'video_id', and 'platform', or NULL if not a video URL.
   */
  protected function normalizeVideoUrl($url) {
    $url = trim($url);
    if (empty($url)) {
      return NULL;
    }

    // YouTube patterns.
    $youtube_patterns = [
      // Standard watch URL: youtube.com/watch?v=VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?(?:.*&)?v=([a-zA-Z0-9_-]{11})(?:&|$)/i',
      // Short URL: youtu.be/VIDEO_ID
      '/(?:https?:\/\/)?youtu\.be\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
      // Embed URL: youtube.com/embed/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
      // Old embed URL: youtube.com/v/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/v\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
      // Shorts URL: youtube.com/shorts/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
      // No-cookie domain: youtube-nocookie.com/embed/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?youtube-nocookie\.com\/embed\/([a-zA-Z0-9_-]{11})(?:\?|$)/i',
    ];

    foreach ($youtube_patterns as $pattern) {
      if (preg_match($pattern, $url, $matches)) {
        $video_id = $matches[1];
        return [
          'url' => 'https://www.youtube.com/watch?v=' . $video_id,
          'video_id' => $video_id,
          'platform' => 'youtube',
        ];
      }
    }

    // Vimeo patterns.
    $vimeo_patterns = [
      // Standard URL: vimeo.com/VIDEO_ID
      '/(?:https?:\/\/)?(?:www\.)?vimeo\.com\/(\d+)(?:\?|\/|$)/i',
      // Player URL: player.vimeo.com/video/VIDEO_ID
      '/(?:https?:\/\/)?player\.vimeo\.com\/video\/(\d+)(?:\?|$)/i',
    ];

    foreach ($vimeo_patterns as $pattern) {
      if (preg_match($pattern, $url, $matches)) {
        $video_id = $matches[1];
        return [
          'url' => 'https://vimeo.com/' . $video_id,
          'video_id' => $video_id,
          'platform' => 'vimeo',
        ];
      }
    }

    // Check if it's just a YouTube video ID (11 chars, alphanumeric with - and _).
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $url)) {
      return [
        'url' => 'https://www.youtube.com/watch?v=' . $url,
        'video_id' => $url,
        'platform' => 'youtube',
      ];
    }

    // Check if it's just a Vimeo video ID (numeric only, reasonable length).
    if (preg_match('/^\d{1,12}$/', $url)) {
      return [
        'url' => 'https://vimeo.com/' . $url,
        'video_id' => $url,
        'platform' => 'vimeo',
      ];
    }

    return NULL;
  }

  /**
   * Detects video IDs in fields based on naming conventions.
   *
   * This method identifies YouTube/Vimeo video IDs stored in fields that
   * follow naming conventions (e.g., field_youtube_id, field_vimeo_video).
   * It constructs full URLs from the video IDs for tracking.
   *
   * @param string $value
   *   The field value to check.
   * @param string $field_name
   *   The field machine name.
   * @param string $table_name
   *   The database table name (includes entity type prefix).
   *
   * @return array|null
   *   Array with 'url' and 'asset_type' if a video ID is detected, NULL otherwise.
   */
  protected function detectVideoIdFromFieldName($value, $field_name, $table_name) {
    // Skip empty or very long values (video IDs are short).
    $value = trim($value);
    if (empty($value) || strlen($value) > 20) {
      return NULL;
    }

    // Keywords that indicate a YouTube video ID field.
    $youtube_keywords = ['youtube', 'yt_id', 'ytid', 'youtube_id', 'youtubeid'];

    // Keywords that indicate a Vimeo video ID field.
    $vimeo_keywords = ['vimeo', 'vimeo_id', 'vimeoid'];

    // Generic video ID keywords (could be YouTube or Vimeo).
    $generic_video_keywords = ['video_id', 'videoid'];

    // Combine field name and table name for checking.
    $context = strtolower($field_name . ' ' . $table_name);

    // Check for YouTube.
    foreach ($youtube_keywords as $keyword) {
      if (strpos($context, $keyword) !== FALSE) {
        // Validate YouTube video ID format: 11 characters, alphanumeric with - and _.
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $value)) {
          return [
            'url' => 'https://www.youtube.com/watch?v=' . $value,
            'asset_type' => 'youtube',
          ];
        }
      }
    }

    // Check for Vimeo.
    foreach ($vimeo_keywords as $keyword) {
      if (strpos($context, $keyword) !== FALSE) {
        // Validate Vimeo video ID format: numeric only.
        if (preg_match('/^\d+$/', $value)) {
          return [
            'url' => 'https://vimeo.com/' . $value,
            'asset_type' => 'vimeo',
          ];
        }
      }
    }

    // Check for generic video ID keywords.
    foreach ($generic_video_keywords as $keyword) {
      if (strpos($context, $keyword) !== FALSE) {
        // Try YouTube format first (more common).
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $value)) {
          return [
            'url' => 'https://www.youtube.com/watch?v=' . $value,
            'asset_type' => 'youtube',
          ];
        }
        // Try Vimeo format.
        if (preg_match('/^\d+$/', $value)) {
          return [
            'url' => 'https://vimeo.com/' . $value,
            'asset_type' => 'vimeo',
          ];
        }
      }
    }

    return NULL;
  }

  /**
   * Extracts local file URLs from text content.
   *
   * @param string $text
   *   The text to scan for local file URLs.
   * @param string $tag
   *   The HTML tag to match ('a' for links, 'img' for images, 'embed' for
   *   embeds, 'object' for objects). Defaults to 'a'.
   *
   * @return array
   *   Array of unique file URIs found (as public:// or private:// streams).
   */
  protected function extractLocalFileUrls(string $text, string $tag = 'a'): array {
    $uris = [];

    // Supported tags: 'a' (href), 'img' (src), 'embed' (src), 'object' (data).
    // <source src> and <track src> inside video/audio tags are handled
    // by extractHtml5MediaEmbeds() to avoid duplication.

    $attr_map = ['a' => 'href', 'object' => 'data'];
    $attr = $attr_map[$tag] ?? 'src';

    // Decode entities so href/src parsing is reliable.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Extract attribute values and let urlPathToStreamUri() handle
    // public (universal + dynamic fallback) and private (/system/files/)
    // conversion in one place.
    $pattern = '#<' . preg_quote($tag, '#') . '\b[^>]*\b' . preg_quote($attr, '#') . '\s*=\s*["\']([^"\']+)["\']#i';

    if (preg_match_all($pattern, $text, $matches)) {
      foreach ($matches[1] as $value) {
        // Trim wrappers and strip query/fragment early.
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $value = preg_replace('/[?#].*$/', '', $value);

        // Convert to stream URI (public:// or private://) if local.
        if ($uri = $this->urlPathToStreamUri($value)) {
          $uris[$uri] = $uri;
        }
      }
    }

    return array_values($uris);
  }

  /**
   * Extracts URLs from iframe src attributes in text content.
   *
   * @param string $text
   *   The text to scan for iframe tags.
   *
   * @return array
   *   Array of unique URLs found in iframe src attributes.
   */
  protected function extractIframeUrls($text) {
    $urls = [];

    // Pattern to match iframe src attributes.
    // Handles various quote styles and whitespace.
    $pattern = '/<iframe[^>]+src\s*=\s*["\']([^"\']+)["\']/i';

    if (preg_match_all($pattern, $text, $matches)) {
      foreach ($matches[1] as $url) {
        // Decode HTML entities (e.g., &amp; -> &).
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        // Normalize the URL.
        $url = trim($url);
        if (!empty($url)) {
          $urls[$url] = $url;
        }
      }
    }

    return array_values($urls);
  }

  /**
   * Extracts embedded media UUIDs from text content.
   *
   * @param string $text
   *   The text to scan for drupal-media tags.
   *
   * @return array
   *   Array of media UUIDs found.
   */
  protected function extractMediaUuids($text) {
    $uuids = [];

    // Pattern to match <drupal-media data-entity-uuid="..."> tags.
    $pattern = '/<drupal-media[^>]+data-entity-uuid="([a-f0-9\-]+)"[^>]*>/i';

    if (preg_match_all($pattern, $text, $matches)) {
      $uuids = array_unique($matches[1]);
    }

    return $uuids;
  }

  /**
   * Extracts HTML5 video and audio embeds from text content.
   *
   * Parses <video> and <audio> tags to extract:
   * - Source URLs from src attribute and <source> elements
   * - Caption/subtitle URLs from <track> elements
   * - Accessibility signals (controls, autoplay, muted, loop)
   *
   * @param string $text
   *   The text to scan for HTML5 media tags.
   *
   * @return array
   *   Array of media embeds, each with keys:
   *   - type: 'video' or 'audio'
   *   - sources: array of source URLs
   *   - tracks: array of track info (url, kind, srclang, label)
   *   - poster: poster image URL (video only)
   *   - signals: accessibility signals (controls, autoplay, muted, loop)
   *   - raw_html: the original HTML tag for signal detection
   */
  protected function extractHtml5MediaEmbeds(string $text): array {
    $embeds = [];

    // Decode entities so src parsing is reliable.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // De-dupe by normalized source key (type:source1|source2).
    $seen = [];

    // Full video tags.
    if (preg_match_all('/<video[^>]*>.*?<\/video>/is', $text, $video_matches)) {
      foreach ($video_matches[0] as $video_html) {
        $embed = $this->parseHtml5MediaTag($video_html, 'video');
        if (!empty($embed['sources'])) {
          $key = 'video:' . implode('|', $embed['sources']);
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $embeds[] = $embed;
          }
        }
      }
    }

    // Self-closing video tags.
    if (preg_match_all('/<video[^>]+src=["\']([^"\']+)["\'][^>]*\/?>/i', $text, $self_closing_videos)) {
      foreach ($self_closing_videos[0] as $video_html) {
        $embed = $this->parseHtml5MediaTag($video_html, 'video');
        if (!empty($embed['sources'])) {
          $key = 'video:' . implode('|', $embed['sources']);
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $embeds[] = $embed;
          }
        }
      }
    }

    // Full audio tags.
    if (preg_match_all('/<audio[^>]*>.*?<\/audio>/is', $text, $audio_matches)) {
      foreach ($audio_matches[0] as $audio_html) {
        $embed = $this->parseHtml5MediaTag($audio_html, 'audio');
        if (!empty($embed['sources'])) {
          $key = 'audio:' . implode('|', $embed['sources']);
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $embeds[] = $embed;
          }
        }
      }
    }

    // Self-closing audio tags.
    if (preg_match_all('/<audio[^>]+src=["\']([^"\']+)["\'][^>]*\/?>/i', $text, $self_closing_audios)) {
      foreach ($self_closing_audios[0] as $audio_html) {
        $embed = $this->parseHtml5MediaTag($audio_html, 'audio');
        if (!empty($embed['sources'])) {
          $key = 'audio:' . implode('|', $embed['sources']);
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $embeds[] = $embed;
          }
        }
      }
    }

    return $embeds;
  }

  /**
   * Parses an HTML5 media tag to extract sources, tracks, and signals.
   *
   * Decodes entities once at the top so attribute parsing is reliable.
   * All URLs are normalized via cleanMediaUrl() (decode+trim), then
   * query/fragment stripped, then optionally converted to stream URIs
   * via urlPathToStreamUri() for consistent multisite handling.
   *
   * @param string $html
   *   The HTML tag content.
   * @param string $type
   *   The media type ('video' or 'audio').
   *
   * @return array
   *   Parsed media embed data with normalized sources and track URLs.
   */
  protected function parseHtml5MediaTag(string $html, string $type): array {
    // Decode entities so attribute parsing is reliable (&amp;, &quot;, etc.).
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $embed = [
      'type' => $type,
      'sources' => [],
      'tracks' => [],
      'poster' => NULL,
      'signals' => [
        'controls' => FALSE,
        'autoplay' => FALSE,
        'muted' => FALSE,
        'loop' => FALSE,
      ],
      'raw_html' => $html,
    ];

    // Normalize a media URL: decode+trim, strip query/fragment, convert
    // local paths to stream URIs when possible.
    $normalize = function (string $value): string {
      // Decode entities + trim (delegated to existing helper).
      $value = $this->cleanMediaUrl($value);
      // Strip query strings and fragments.
      $value = preg_replace('/[?#].*$/', '', $value);
      // Convert local URLs to stream URIs when possible.
      $uri = $this->urlPathToStreamUri($value);
      return $uri ?: $value;
    };

    // Collect unique sources via associative keys.
    $source_set = [];

    // Extract src attribute from main <video>/<audio> tag.
    if (preg_match('/<' . preg_quote($type, '/') . '[^>]+\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
      $src = $normalize($m[1]);
      $source_set[$src] = TRUE;
    }

    // Extract <source src="..."> elements.
    if (preg_match_all('/<source[^>]+\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
      foreach ($m[1] as $src) {
        $src = $normalize($src);
        $source_set[$src] = TRUE;
      }
    }

    $embed['sources'] = array_keys($source_set);

    // Extract <track ...> elements (video only, but safe for audio too).
    // Match both <track ...> and <track .../> formats.
    if (preg_match_all('/<track\s+([^>]*?)\/?>/i', $html, $track_matches)) {
      foreach ($track_matches[1] as $track_attrs) {
        $track = [
          'url' => NULL,
          'kind' => 'subtitles',
          'srclang' => NULL,
          'label' => NULL,
        ];

        // src can be quoted or unquoted. No &quot; fallback needed since
        // entities are decoded at the top.
        if (preg_match('/\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $track_attrs, $m)) {
          $src_value = $m[1] ?? $m[2] ?? $m[3] ?? '';
          if ($src_value !== '') {
            $track['url'] = $normalize($src_value);
          }
        }

        if (preg_match('/\bkind\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $track_attrs, $m)) {
          $track['kind'] = $m[1] ?? $m[2] ?? $m[3] ?? $track['kind'];
        }
        if (preg_match('/\bsrclang\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $track_attrs, $m)) {
          $track['srclang'] = $m[1] ?? $m[2] ?? $m[3] ?? NULL;
        }
        if (preg_match('/\blabel\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $track_attrs, $m)) {
          $track['label'] = $m[1] ?? $m[2] ?? $m[3] ?? NULL;
        }

        if ($track['url']) {
          $embed['tracks'][] = $track;
        }
      }
    }

    // Extract poster attribute (video only).
    if ($type === 'video' && preg_match('/\bposter\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
      $embed['poster'] = $normalize($m[1]);
    }

    // Extract boolean attributes (signals).
    $embed['signals']['controls'] = (bool) preg_match('/<' . preg_quote($type, '/') . '\b[^>]*\bcontrols\b/i', $html);
    $embed['signals']['autoplay'] = (bool) preg_match('/<' . preg_quote($type, '/') . '\b[^>]*\bautoplay\b/i', $html);
    $embed['signals']['muted'] = (bool) preg_match('/<' . preg_quote($type, '/') . '\b[^>]*\bmuted\b/i', $html);
    $embed['signals']['loop'] = (bool) preg_match('/<' . preg_quote($type, '/') . '\b[^>]*\bloop\b/i', $html);

    return $embed;
  }

  /**
   * Cleans a media URL for matching and normalization.
   *
   * Decodes HTML entities and trims whitespace. Query strings and fragments
   * are intentionally NOT removed here — that responsibility belongs to
   * the caller or the normalization pipeline in parseHtml5MediaTag().
   *
   * @param string $url
   *   The URL to clean.
   *
   * @return string
   *   The cleaned URL (entities decoded, whitespace trimmed).
   */
  protected function cleanMediaUrl($url) {
    $url = html_entity_decode($url);
    $url = trim($url);

    return $url;
  }

  /**
   * Resolves a relative URL to an absolute URL.
   *
   * @param string $url
   *   The URL to resolve.
   * @param string|null $base_url
   *   The base URL to resolve against.
   *
   * @return string
   *   The resolved absolute URL.
   */
  protected function resolveMediaUrl($url, $base_url = NULL) {
    // Already absolute.
    if (parse_url($url, PHP_URL_SCHEME)) {
      return $url;
    }

    // Protocol-relative.
    if (strpos($url, '//') === 0) {
      return 'https:' . $url;
    }

    // Get base URL from config or request.
    if (!$base_url) {
      $base_url = \Drupal::request()->getSchemeAndHttpHost();
    }

    // Root-relative.
    if (strpos($url, '/') === 0) {
      return $base_url . $url;
    }

    // Relative (less common in CKEditor content).
    return $base_url . '/' . $url;
  }

  /**
   * Converts a URL to a Drupal stream URI if it's a local file.
   *
   * @param string $url
   *   The URL to convert.
   *
   * @return string|null
   *   The stream URI (public:// or private://) or NULL if external.
   */
  protected function urlToStreamUri($url) {
    return $this->urlPathToStreamUri($url);
  }

  /**
   * Gets sort order for a category.
   *
   * @param string $category
   *   The category name.
   *
   * @return int
   *   Sort order: Documents=1, Videos=2, Audio=3, Google Workspace=4,
   *   Document Services=5, Forms & Surveys=6, Education Platforms=7,
   *   Embedded Media=8, Images=9, Other=10, Unknown=99.
   */
  protected function getCategorySortOrder($category) {
    $order_map = [
      'Documents' => 1,
      'Videos' => 2,
      'Audio' => 3,
      'Google Workspace' => 4,
      'Document Services' => 5,
      'Forms & Surveys' => 6,
      'Education Platforms' => 7,
      'Embedded Media' => 8,
      'Images' => 9,
      'Other' => 10,
    ];

    return $order_map[$category] ?? 99;
  }

  /**
   * Counts distinct entity IDs in a field table.
   *
   * @param string $table
   *   The field table name.
   *
   * @return int
   *   Number of distinct entity IDs.
   */
  protected function countEntitiesInFieldTable(string $table): int {
    return (int) $this->database->select($table, 't')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Gets rows from a field table after a given entity ID.
   *
   * @param string $table
   *   The field table name.
   * @param string $column
   *   The value column name.
   * @param int $lastEntityId
   *   Last processed entity ID (exclusive lower bound).
   * @param int $limit
   *   Maximum number of rows to return.
   *
   * @return array
   *   Array of row objects.
   */
  protected function getFieldTableRows(string $table, string $column, int $lastEntityId, int $limit): array {
    return $this->database->select($table, 't')
      ->fields('t')
      ->condition('entity_id', $lastEntityId, '>')
      ->orderBy('entity_id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

  /**
   * Processes a single row from a content field table.
   *
   * Extracts external URLs, HTML5 media, local file links, inline images,
   * legacy embeds, and iframe embeds from the field value.
   *
   * @param object $row
   *   Database row with entity_id and field value columns.
   * @param array $table_info
   *   Table info with 'table', 'column', 'entity_type', 'field_name', 'type'.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */
  protected function processContentRow(object $row, array $table_info, bool $is_temp): void {
    $asset_storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    $entity_id = $row->entity_id;
    $field_value = $row->{$table_info['column']};

    $urls = [];
    $iframe_urls = [];
    if ($table_info['type'] === 'text') {
      // Extract iframe URLs first - these get 'inline_iframe' embed method.
      $iframe_urls = $this->extractIframeUrls($field_value);

      // Text field - extract URLs from HTML/text.
      $urls = $this->extractUrls($field_value);

      // Remove iframe URLs from general URL list to avoid duplicates.
      if (!empty($iframe_urls)) {
        $urls = array_filter($urls, function ($url) use ($iframe_urls) {
          $normalized_url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
          foreach ($iframe_urls as $iframe_url) {
            $normalized_iframe = html_entity_decode($iframe_url, ENT_QUOTES, 'UTF-8');
            $url_base = preg_replace('/[?#].*$/', '', $normalized_url);
            $iframe_base = preg_replace('/[?#].*$/', '', $normalized_iframe);
            if ($url_base === $iframe_base || $normalized_url === $normalized_iframe) {
              return FALSE;
            }
          }
          return TRUE;
        });
      }

      // Scan for HTML5 video/audio embeds.
      $html5_embeds = $this->extractHtml5MediaEmbeds($field_value);
      foreach ($html5_embeds as $embed) {
        $this->processHtml5MediaEmbed(
          $embed,
          $table_info,
          $entity_id,
          $is_temp,
          $asset_storage,
          $usage_storage
        );
      }

      // Scan for local file links.
      $local_uris = $this->extractLocalFileUrls($field_value);
      foreach ($local_uris as $uri) {
        $this->processLocalFileLink(
          $uri,
          $table_info,
          $entity_id,
          $is_temp,
          $asset_storage,
          $usage_storage
        );
      }

      // Scan for inline images.
      $inline_image_uris = $this->extractLocalFileUrls($field_value, 'img');
      foreach ($inline_image_uris as $uri) {
        $this->processLocalFileLink(
          $uri,
          $table_info,
          $entity_id,
          $is_temp,
          $asset_storage,
          $usage_storage,
          'inline_image'
        );
      }

      // Scan for legacy embeds.
      $legacy_embed_tags = [
        'object' => 'inline_object',
        'embed' => 'inline_embed',
      ];
      foreach ($legacy_embed_tags as $legacy_tag => $legacy_method) {
        $legacy_uris = $this->extractLocalFileUrls($field_value, $legacy_tag);
        foreach ($legacy_uris as $uri) {
          $this->processLocalFileLink(
            $uri,
            $table_info,
            $entity_id,
            $is_temp,
            $asset_storage,
            $usage_storage,
            $legacy_method
          );
        }
      }

      // Process iframe URLs with inline_iframe embed method.
      if (!empty($iframe_urls)) {
        foreach ($iframe_urls as $iframe_url) {
          $this->processExternalUrl(
            $iframe_url,
            $table_info,
            $entity_id,
            $is_temp,
            $asset_storage,
            $usage_storage,
            'inline_iframe'
          );
        }
      }
    }
    elseif ($table_info['type'] === 'link') {
      // Link field - the value IS the URL.
      if (!empty($field_value) && (strpos($field_value, 'http://') === 0 || strpos($field_value, 'https://') === 0)) {
        $urls = [$field_value];
      }
    }

    // Check for video IDs based on field naming conventions.
    $video_id_info = $this->detectVideoIdFromFieldName(
      $field_value,
      $table_info['field_name'],
      $table_info['table']
    );
    if ($video_id_info) {
      $urls[] = $video_id_info['url'];
    }

    foreach ($urls as $url) {
      $asset_type = $this->matchUrlToAssetType($url);
      if ($asset_type === 'other') {
        continue;
      }

      $display_url = $url;
      $normalized = $this->normalizeVideoUrl($url);
      if ($normalized) {
        $url = $normalized['url'];
        $asset_type = $normalized['platform'];
      }

      $category = $this->mapAssetTypeToCategory($asset_type);
      $sort_order = $this->getCategorySortOrder($category);
      $url_hash = md5($url);

      // Check if TEMP asset already exists by url_hash.
      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('url_hash', $url_hash);
      $existing_query->condition('source_type', 'external');
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        $asset_id = reset($existing_ids);
      }
      else {
        $config = $this->configFactory->get('digital_asset_inventory.settings');
        $asset_types_config = $config->get('asset_types');
        $label = $asset_types_config[$asset_type]['label'] ?? $asset_type;

        $asset = $asset_storage->create([
          'source_type' => 'external',
          'url_hash' => $url_hash,
          'asset_type' => $asset_type,
          'category' => $category,
          'sort_order' => $sort_order,
          'file_path' => $url,
          'file_name' => $label,
          'mime_type' => $label,
          'filesize' => 0,
          'is_temp' => $is_temp,
        ]);
        $asset->save();
        $asset_id = $asset->id();
      }

      // Determine parent entity for paragraphs.
      $parent_entity_type = $table_info['entity_type'];
      $parent_entity_id = $entity_id;

      if ($parent_entity_type === 'paragraph') {
        if (!array_key_exists($entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$entity_id] = $this->getParentFromParagraph($entity_id);
        }
        $parent_info = $this->paragraphParentCache[$entity_id];
        if ($parent_info && empty($parent_info['orphan'])) {
          $parent_entity_type = $parent_info['type'];
          $parent_entity_id = $parent_info['id'];
        }
        elseif ($parent_info && !empty($parent_info['orphan'])) {
          $orphan_embed = ($table_info['type'] === 'link') ? 'link_field' : 'text_url';
          $this->createOrphanReference($asset_id, 'paragraph', $entity_id, $table_info['field_name'], $orphan_embed, $parent_info['context']);
          return;
        }
        else {
          return;
        }
      }

      // Track usage.
      $usage_query = $usage_storage->getQuery();
      $usage_query->condition('asset_id', $asset_id);
      $usage_query->condition('entity_type', $parent_entity_type);
      $usage_query->condition('entity_id', $parent_entity_id);
      $usage_query->condition('field_name', $table_info['field_name']);
      $usage_query->accessCheck(FALSE);
      $usage_ids = $usage_query->execute();

      if (!$usage_ids) {
        $url_embed_method = ($table_info['type'] === 'link') ? 'link_field' : 'text_url';
        $usage_storage->create([
          'asset_id' => $asset_id,
          'entity_type' => $parent_entity_type,
          'entity_id' => $parent_entity_id,
          'field_name' => $table_info['field_name'],
          'count' => 1,
          'embed_method' => $url_embed_method,
        ])->save();
      }

      // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).
    }
  }

  /**
   * Gets all field tables that should be scanned for external URLs.
   *
   * @return array
   *   Array of table info with keys: table, column, entity_type, field_name.
   */
  protected function getFieldTablesToScan() {
    $tables = [];

    // Get all tables in the database.
    $db_schema = $this->database->schema();

    // Scan for text/long text field tables (node__, paragraph__, etc.).
    $prefixes = ['node__', 'taxonomy_term__', 'block_content__'];
    if ($this->moduleHandler->moduleExists('paragraphs')) {
      $prefixes[] = 'paragraph__';
    }

    foreach ($prefixes as $prefix) {
      // Find all tables with this prefix (cross-database compatible).
      $all_tables = $this->database->schema()->findTables($prefix . '%');

      foreach ($all_tables as $table) {
        // Check if table has a _value column (text field).
        $field_name = str_replace($prefix, '', $table);
        $value_column = $field_name . '_value';

        if ($db_schema->fieldExists($table, $value_column)) {
          // Extract entity type properly - remove the trailing "__".
          $entity_type = str_replace('__', '', $prefix);
          $tables[] = [
            'table' => $table,
            'column' => $value_column,
            'entity_type' => $entity_type,
            'field_name' => $field_name,
            'type' => 'text',
          ];
        }

        // Check if table has a _uri column (link field).
        $uri_column = $field_name . '_uri';
        if ($db_schema->fieldExists($table, $uri_column)) {
          // Extract entity type properly - remove the trailing "__".
          $entity_type = str_replace('__', '', $prefix);
          $tables[] = [
            'table' => $table,
            'column' => $uri_column,
            'entity_type' => $entity_type,
            'field_name' => $field_name,
            'type' => 'link',
          ];
        }
      }
    }

    return $tables;
  }

  /**
   * Gets count of content entities to scan for external URLs.
   *
   * @return int
   *   The number of content entities.
   */
  public function getContentEntitiesCount() {
    // Get all field tables to scan.
    $tables = $this->getFieldTablesToScan();

    if (empty($tables)) {
      return 0;
    }

    // Count unique entity IDs across all tables.
    $entity_ids = [];
    foreach ($tables as $table_info) {
      $results = $this->database->select($table_info['table'], 't')
        ->fields('t', ['entity_id'])
        ->execute();

      foreach ($results as $row) {
        $entity_ids[$row->entity_id] = TRUE;
      }
    }

    return count($entity_ids);
  }

  /**
   * Scans a chunk of content entities for external URLs.
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of entities to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Number of items processed.
   */
  public function scanContentChunk($offset, $limit, $is_temp = FALSE) {
    $count = 0;
    $asset_storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    // Get all field tables to scan.
    $tables = $this->getFieldTablesToScan();

    if (empty($tables)) {
      return 0;
    }

    // Scan each table for URLs.
    foreach ($tables as $table_info) {
      $query = $this->database->select($table_info['table'], 't');
      $query->fields('t', ['entity_id', $table_info['column']]);
      $query->range($offset, $limit);
      $results = $query->execute();

      foreach ($results as $row) {
        $entity_id = $row->entity_id;
        $field_value = $row->{$table_info['column']};

        // NOTE: We no longer scan for embedded media (<drupal-media>) here
        // because entity_usage module already tracks media references.
        // Scanning here would create duplicate usage records.
        // Extract or get URLs based on field type.
        $urls = [];
        // Track URLs found in iframes to exclude from general text_url processing.
        $iframe_urls = [];
        if ($table_info['type'] === 'text') {
          // Extract iframe URLs first - these get 'inline_iframe' embed method.
          $iframe_urls = $this->extractIframeUrls($field_value);

          // Text field - extract URLs from HTML/text.
          $urls = $this->extractUrls($field_value);

          // Remove iframe URLs from general URL list to avoid duplicates.
          // Iframe URLs will be processed separately with inline_iframe embed method.
          if (!empty($iframe_urls)) {
            $urls = array_filter($urls, function ($url) use ($iframe_urls) {
              // Normalize URLs for comparison (handle HTML entity encoding differences).
              $normalized_url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
              foreach ($iframe_urls as $iframe_url) {
                // Check if the URL matches (with or without query params).
                $normalized_iframe = html_entity_decode($iframe_url, ENT_QUOTES, 'UTF-8');
                // Strip query params for comparison since extractUrls may get different variations.
                $url_base = preg_replace('/[?#].*$/', '', $normalized_url);
                $iframe_base = preg_replace('/[?#].*$/', '', $normalized_iframe);
                if ($url_base === $iframe_base || $normalized_url === $normalized_iframe) {
                  return FALSE;
                }
              }
              return TRUE;
            });
          }

          // Also scan for HTML5 video/audio embeds.
          $html5_embeds = $this->extractHtml5MediaEmbeds($field_value);
          foreach ($html5_embeds as $embed) {
            $count += $this->processHtml5MediaEmbed(
              $embed,
              $table_info,
              $entity_id,
              $is_temp,
              $asset_storage,
              $usage_storage
            );
          }

          // Also scan for local file links (<a href="/sites/default/files/...">, etc.)
          $local_uris = $this->extractLocalFileUrls($field_value);
          foreach ($local_uris as $uri) {
            $count += $this->processLocalFileLink(
              $uri,
              $table_info,
              $entity_id,
              $is_temp,
              $asset_storage,
              $usage_storage
            );
          }

          // Scan for inline images (<img src="/sites/default/files/...">, etc.)
          $inline_image_uris = $this->extractLocalFileUrls($field_value, 'img');
          foreach ($inline_image_uris as $uri) {
            $count += $this->processLocalFileLink(
              $uri,
              $table_info,
              $entity_id,
              $is_temp,
              $asset_storage,
              $usage_storage,
              'inline_image'
            );
          }

          // Scan for legacy embeds (<object data="...">, <embed src="...">, etc.)
          $legacy_embed_tags = [
            'object' => 'inline_object',
            'embed' => 'inline_embed',
          ];
          foreach ($legacy_embed_tags as $legacy_tag => $legacy_method) {
            $legacy_uris = $this->extractLocalFileUrls($field_value, $legacy_tag);
            foreach ($legacy_uris as $uri) {
              $count += $this->processLocalFileLink(
                $uri,
                $table_info,
                $entity_id,
                $is_temp,
                $asset_storage,
                $usage_storage,
                $legacy_method
              );
            }
          }

          // Process iframe URLs with inline_iframe embed method.
          // These were extracted earlier and excluded from the general $urls array.
          if (!empty($iframe_urls)) {
            foreach ($iframe_urls as $iframe_url) {
              $count += $this->processExternalUrl(
                $iframe_url,
                $table_info,
                $entity_id,
                $is_temp,
                $asset_storage,
                $usage_storage,
                'inline_iframe'
              );
            }
          }
        }
        elseif ($table_info['type'] === 'link') {
          // Link field - the value IS the URL.
          if (!empty($field_value) && (strpos($field_value, 'http://') === 0 || strpos($field_value, 'https://') === 0)) {
            $urls = [$field_value];
          }
        }

        // Check for video IDs based on field naming conventions.
        // This catches fields like field_youtube_id that store just the video ID.
        $video_id_info = $this->detectVideoIdFromFieldName(
          $field_value,
          $table_info['field_name'],
          $table_info['table']
        );
        if ($video_id_info) {
          // Add the constructed URL to the list for processing.
          // The asset_type is already known, so we'll handle it specially.
          $urls[] = $video_id_info['url'];
        }

        foreach ($urls as $url) {
          // Match URL to asset type.
          $asset_type = $this->matchUrlToAssetType($url);

          // Only process URLs that match known patterns (not 'other').
          if ($asset_type === 'other') {
            continue;
          }

          // Normalize video URLs for consistent tracking.
          // This ensures the same video is tracked as one asset regardless of URL format.
          $display_url = $url;
          $normalized = $this->normalizeVideoUrl($url);
          if ($normalized) {
            // Use canonical URL for hashing and storage.
            $url = $normalized['url'];
            // Update asset type based on detected platform.
            $asset_type = $normalized['platform'];
          }

          // Determine category and sort order.
          $category = $this->mapAssetTypeToCategory($asset_type);
          $sort_order = $this->getCategorySortOrder($category);

          // Create URL hash for uniqueness using normalized URL.
          $url_hash = md5($url);

          // Check if TEMP asset already exists by url_hash.
          // Only update temp items - never modify permanent items during scan.
          $existing_query = $asset_storage->getQuery();
          $existing_query->condition('url_hash', $url_hash);
          $existing_query->condition('source_type', 'external');
          $existing_query->condition('is_temp', TRUE);
          $existing_query->accessCheck(FALSE);
          $existing_ids = $existing_query->execute();

          if ($existing_ids) {
            // Temp asset exists - reuse it.
            $asset_id = reset($existing_ids);
          }
          else {
            // Create new external asset.
            $config = $this->configFactory->get('digital_asset_inventory.settings');
            $asset_types_config = $config->get('asset_types');
            $label = $asset_types_config[$asset_type]['label'] ?? $asset_type;

            $asset = $asset_storage->create([
              'source_type' => 'external',
              'url_hash' => $url_hash,
              'asset_type' => $asset_type,
              'category' => $category,
              'sort_order' => $sort_order,
              'file_path' => $url,
              'file_name' => $label,
              'mime_type' => $label,
              'filesize' => 0,
              'is_temp' => $is_temp,
            ]);
            $asset->save();
            $asset_id = $asset->id();
          }

          // Determine parent entity for paragraphs.
          $parent_entity_type = $table_info['entity_type'];
          $parent_entity_id = $entity_id;

          if ($parent_entity_type === 'paragraph') {
            // Get parent node from paragraph.
            if (!array_key_exists($entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$entity_id] = $this->getParentFromParagraph($entity_id);
        }
        $parent_info = $this->paragraphParentCache[$entity_id];
            if ($parent_info && empty($parent_info['orphan'])) {
              $parent_entity_type = $parent_info['type'];
              $parent_entity_id = $parent_info['id'];
            }
            elseif ($parent_info && !empty($parent_info['orphan'])) {
              // Orphan detected — create orphan reference record.
              $orphan_embed = ($table_info['type'] === 'link') ? 'link_field' : 'text_url';
              $this->createOrphanReference($asset_id, 'paragraph', $entity_id, $table_info['field_name'], $orphan_embed, $parent_info['context']);
              continue;
            }
            else {
              // Paragraph not found (NULL) — skip.
              continue;
            }
          }

          // Track usage - check if usage record exists.
          $usage_query = $usage_storage->getQuery();
          $usage_query->condition('asset_id', $asset_id);
          $usage_query->condition('entity_type', $parent_entity_type);
          $usage_query->condition('entity_id', $parent_entity_id);
          $usage_query->condition('field_name', $table_info['field_name']);
          $usage_query->accessCheck(FALSE);
          $usage_ids = $usage_query->execute();

          if (!$usage_ids) {
            // Determine embed method based on how the URL was found.
            $url_embed_method = ($table_info['type'] === 'link') ? 'link_field' : 'text_url';

            // Create usage tracking record.
            $usage_storage->create([
              'asset_id' => $asset_id,
              'entity_type' => $parent_entity_type,
              'entity_id' => $parent_entity_id,
              'field_name' => $table_info['field_name'],
              'count' => 1,
              'embed_method' => $url_embed_method,
            ])->save();
          }

          // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

          $count++;
        }
      }
    }

    // Flush buffered records before cache reset.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();

    // Reset entity caches to prevent memory exhaustion in long batch runs.
    $this->resetEntityCaches([
      'digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference',
      'node', 'paragraph', 'block_content', 'taxonomy_term',
    ]);

    return $count;
  }

  /**
   * Scans content entities for external URLs with time-budgeted processing.
   *
   * Uses a compound cursor (table_index + last_entity_id) to process
   * multiple field tables within the configured time budget.
   *
   * @param array &$context
   *   Batch API context array.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */
  public function scanContentChunkNew(array &$context, bool $is_temp): void {
    $budget = $this->getBatchTimeBudget();
    $startTime = microtime(true);
    $itemsThisCallback = 0;

    // Initialize on first call.
    if (!isset($context['sandbox']['table_index'])) {
      // Build table list and store minimal info only.
      $tables = $this->getFieldTablesToScan();
      $context['sandbox']['tables'] = array_map(fn($t) => [
        'table' => $t['table'],
        'column' => $t['column'],
        'entity_type' => $t['entity_type'],
        'field_name' => $t['field_name'],
        'type' => $t['type'],
      ], $tables);
      $context['sandbox']['table_index'] = 0;
      $context['sandbox']['last_entity_id'] = 0;
      $context['sandbox']['tables_completed'] = 0;
      $context['sandbox']['total_tables'] = count($context['sandbox']['tables']);
      $context['sandbox']['current_table_total'] = 0;
      $context['sandbox']['current_table_processed'] = 0;
    }

    $tables = $context['sandbox']['tables'];
    $tableIndex = $context['sandbox']['table_index'];

    // Exhaustion guard: all tables done.
    if ($tableIndex >= count($tables)) {
      $context['finished'] = 1;
      $context['results']['last_chunk_items'] = 0;
      return;
    }

    // If entering a new table, count its rows for progress.
    if ($context['sandbox']['current_table_processed'] === 0 && $context['sandbox']['current_table_total'] === 0) {
      $context['sandbox']['current_table_total'] = $this->countEntitiesInFieldTable(
        $tables[$tableIndex]['table']
      );
    }

    $lastEntityId = $context['sandbox']['last_entity_id'];

    // Process entities from current table.
    while ((microtime(true) - $startTime) < $budget) {
      $rows = $this->getFieldTableRows(
        $tables[$tableIndex]['table'],
        $tables[$tableIndex]['column'],
        $lastEntityId,
        50
      );

      // Current table exhausted — advance to next.
      if (empty($rows)) {
        $context['sandbox']['tables_completed']++;
        $context['sandbox']['table_index']++;
        $context['sandbox']['last_entity_id'] = 0;
        $context['sandbox']['current_table_total'] = 0;
        $context['sandbox']['current_table_processed'] = 0;
        $tableIndex = $context['sandbox']['table_index'];

        // All tables done.
        if ($tableIndex >= count($tables)) {
          $context['finished'] = 1;
          break;
        }

        // Count next table's rows.
        $context['sandbox']['current_table_total'] = $this->countEntitiesInFieldTable(
          $tables[$tableIndex]['table']
        );
        $lastEntityId = 0;
        continue;
      }

      foreach ($rows as $row) {
        if ((microtime(true) - $startTime) >= $budget) {
          break 2;
        }

        $this->processContentRow($row, $tables[$tableIndex], $is_temp);
        $this->maybeUpdateHeartbeat();

        $lastEntityId = $row->entity_id;
        $context['sandbox']['last_entity_id'] = $lastEntityId;
        $context['sandbox']['current_table_processed']++;
        $itemsThisCallback++;
      }
    }

    // Progress calculation.
    $totalTables = $context['sandbox']['total_tables'];
    if ($totalTables > 0) {
      $tableProgress = 0;
      $currentTotal = $context['sandbox']['current_table_total'];
      if ($currentTotal > 0) {
        $tableProgress = $context['sandbox']['current_table_processed'] / $currentTotal;
      }
      $context['finished'] = ($context['sandbox']['tables_completed'] + $tableProgress) / $totalTables;
    }
    if ($context['finished'] >= 1) {
      $context['finished'] = 1;
    }

    // FR-6: Cache resets.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();
    $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'node', 'paragraph', 'block_content', 'taxonomy_term']);
    if ($itemsThisCallback >= 50) {
      drupal_static_reset();
    }

    $context['results']['last_chunk_items'] = $itemsThisCallback;
  }

  /**
   * Processes a single HTML5 media embed, creating assets and usage records.
   *
   * @param array $embed
   *   The parsed embed data from extractHtml5MediaEmbeds().
   * @param array $table_info
   *   Table information (entity_type, field_name, etc.).
   * @param int $entity_id
   *   The entity ID where the embed was found.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   * @param object $usage_storage
   *   The usage entity storage.
   *
   * @return int
   *   Number of assets processed.
   */
  protected function processHtml5MediaEmbed(array $embed, array $table_info, $entity_id, $is_temp, $asset_storage, $usage_storage) {
    $count = 0;
    $embed_method = $embed['type'] === 'video' ? 'html5_video' : 'html5_audio';

    // Resolve all assets FIRST (sources + tracks) so $asset_ids are available
    // for orphan reference creation if the paragraph turns out to be orphan.
    $resolved_assets = [];

    // Process each source URL in the embed.
    foreach ($embed['sources'] as $source_url) {
      $absolute_url = $this->resolveMediaUrl($source_url);
      $stream_uri = $this->urlToStreamUri($absolute_url);

      if ($stream_uri) {
        $asset_id = $this->findOrCreateLocalAssetForHtml5($stream_uri, $absolute_url, $embed, $is_temp, $asset_storage);
      }
      else {
        $asset_id = $this->findOrCreateExternalAssetForHtml5($absolute_url, $embed, $is_temp, $asset_storage);
      }

      if ($asset_id) {
        $resolved_assets[] = [
          'asset_id' => $asset_id,
          'signals' => $embed['signals'],
          'tracks' => $embed['tracks'],
        ];
      }
    }

    // Process track/caption files as separate assets.
    foreach ($embed['tracks'] as $track) {
      if (!$track['url']) {
        continue;
      }

      $track_url = $this->resolveMediaUrl($track['url']);
      $stream_uri = $this->urlToStreamUri($track_url);

      if ($stream_uri) {
        $asset_id = $this->findOrCreateCaptionAsset($stream_uri, $track_url, $track, $is_temp, $asset_storage);
      }
      else {
        $asset_id = $this->findOrCreateExternalCaptionAsset($track_url, $track, $is_temp, $asset_storage);
      }

      if ($asset_id) {
        $resolved_assets[] = [
          'asset_id' => $asset_id,
          'signals' => [],
          'tracks' => [],
        ];
      }
    }

    // No assets resolved — nothing to record.
    if (empty($resolved_assets)) {
      return 0;
    }

    // Now determine parent entity for paragraphs (after asset resolution).
    $parent_entity_type = $table_info['entity_type'];
    $parent_entity_id = $entity_id;

    if ($parent_entity_type === 'paragraph') {
      if (!array_key_exists($entity_id, $this->paragraphParentCache)) {
        $this->paragraphParentCache[$entity_id] = $this->getParentFromParagraph($entity_id);
      }
      $parent_info = $this->paragraphParentCache[$entity_id];
      if ($parent_info && empty($parent_info['orphan'])) {
        $parent_entity_type = $parent_info['type'];
        $parent_entity_id = $parent_info['id'];
      }
      elseif ($parent_info && !empty($parent_info['orphan'])) {
        // Orphan detected — create orphan reference for each resolved asset.
        foreach ($resolved_assets as $asset_data) {
          $this->createOrphanReference($asset_data['asset_id'], 'paragraph', $entity_id, $table_info['field_name'], $embed_method, $parent_info['context']);
        }
        return 0;
      }
      else {
        // Paragraph not found (NULL) — skip.
        return 0;
      }
    }

    // Create usage records for each resolved asset.
    foreach ($resolved_assets as $asset_data) {
      $this->createHtml5UsageRecord(
        $asset_data['asset_id'],
        $parent_entity_type,
        $parent_entity_id,
        $table_info['field_name'],
        $embed_method,
        $asset_data['signals'],
        $asset_data['tracks'],
        $usage_storage
      );
      $count++;
    }

    return $count;
  }

  /**
   * Processes a local file link found in text content.
   *
   * Handles <a href="/sites/default/files/..."> and similar patterns.
   * Links the text link to existing assets and creates usage records.
   *
   * @param string $uri
   *   The Drupal stream URI (public:// or private://).
   * @param array $table_info
   *   Information about the source table/field.
   * @param int $entity_id
   *   The entity ID where the link was found.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   * @param object $usage_storage
   *   The usage entity storage.
   * @param string $embed_method
   *   The embed method (text_link, inline_image, etc.). Defaults to text_link.
   *
   * @return int
   *   1 if a usage was created/updated, 0 otherwise.
   */
  protected function processLocalFileLink($uri, array $table_info, $entity_id, $is_temp, $asset_storage, $usage_storage, $embed_method = 'text_link') {
    // Resolve/create the asset FIRST so $asset_id is available for orphan refs.
    // Check if file exists in file_managed.
    $file = NULL;
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
      $file = reset($files);
    }
    catch (\Exception $e) {
      // File not in file_managed.
    }

    // Find the asset - first try by fid if file exists.
    $asset_id = NULL;
    if ($file) {
      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('fid', $file->id());
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        $asset_id = reset($existing_ids);
      }
    }

    // If not found by fid, try by file_path.
    if (!$asset_id) {
      $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
      $url_hash = md5($absolute_url);

      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('url_hash', $url_hash);
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        $asset_id = reset($existing_ids);
      }
    }

    // If no asset found, the file might be on filesystem but not scanned yet.
    // Create a filesystem_only asset.
    if (!$asset_id) {
      $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
      $real_path = $this->fileSystem->realpath($uri);

      if (!$real_path || !file_exists($real_path)) {
        // File doesn't exist - skip.
        return 0;
      }

      // Get file info.
      $filesize = filesize($real_path);
      $filename = basename($uri);
      $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

      // Determine MIME type.
      $mime_type = $this->getMimeTypeFromExtension($extension);
      $asset_type = $this->mapMimeToAssetType($mime_type);
      $category = $this->mapAssetTypeToCategory($asset_type);
      $sort_order = $this->getCategorySortOrder($category);

      // Check if private.
      $is_private = strpos($uri, 'private://') === 0;

      // Create the asset.
      $asset = $asset_storage->create([
        'fid' => $file ? $file->id() : NULL,
        'source_type' => $file ? 'file_managed' : 'filesystem_only',
        'url_hash' => md5($absolute_url),
        'asset_type' => $asset_type,
        'category' => $category,
        'sort_order' => $sort_order,
        'file_path' => $absolute_url,
        'file_name' => $filename,
        'mime_type' => $mime_type,
        'filesize' => $filesize,
        'is_temp' => $is_temp,
        'is_private' => $is_private,
      ]);
      $asset->save();

      // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

      $asset_id = $asset->id();
    }

    if (!$asset_id) {
      return 0;
    }

    // Now determine parent entity for paragraphs (after asset resolution).
    $parent_entity_type = $table_info['entity_type'];
    $parent_entity_id = $entity_id;

    if ($parent_entity_type === 'paragraph') {
      if (!array_key_exists($entity_id, $this->paragraphParentCache)) {
        $this->paragraphParentCache[$entity_id] = $this->getParentFromParagraph($entity_id);
      }
      $parent_info = $this->paragraphParentCache[$entity_id];
      if ($parent_info && empty($parent_info['orphan'])) {
        $parent_entity_type = $parent_info['type'];
        $parent_entity_id = $parent_info['id'];
      }
      elseif ($parent_info && !empty($parent_info['orphan'])) {
        // Orphan detected — create orphan reference record.
        $this->createOrphanReference($asset_id, 'paragraph', $entity_id, $table_info['field_name'], $embed_method, $parent_info['context']);
        return 0;
      }
      else {
        // Paragraph not found (NULL) — skip.
        return 0;
      }
    }

    // Create usage record with appropriate embed_method.
    $this->createHtml5UsageRecord(
      $asset_id,
      $parent_entity_type,
      $parent_entity_id,
      $table_info['field_name'],
      $embed_method,
      [],
      [],
      $usage_storage
    );

    return 1;
  }

  /**
   * Processes an external URL, creating asset and usage records.
   *
   * @param string $url
   *   The external URL to process.
   * @param array $table_info
   *   Table information (entity_type, field_name, etc.).
   * @param int $entity_id
   *   The entity ID where the URL was found.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   * @param object $usage_storage
   *   The usage entity storage.
   * @param string $embed_method
   *   The embed method (e.g., 'inline_iframe', 'text_url', 'link_field').
   *
   * @return int
   *   1 if asset/usage was created, 0 otherwise.
   */
  protected function processExternalUrl($url, array $table_info, $entity_id, $is_temp, $asset_storage, $usage_storage, $embed_method = 'text_url') {
    // Match URL to asset type.
    $asset_type = $this->matchUrlToAssetType($url);

    // Only process URLs that match known patterns (not 'other').
    if ($asset_type === 'other') {
      return 0;
    }

    // Normalize video URLs for consistent tracking.
    $normalized = $this->normalizeVideoUrl($url);
    if ($normalized) {
      $url = $normalized['url'];
      $asset_type = $normalized['platform'];
    }

    // Determine category and sort order.
    $category = $this->mapAssetTypeToCategory($asset_type);
    $sort_order = $this->getCategorySortOrder($category);

    // Create URL hash for uniqueness using normalized URL.
    $url_hash = md5($url);

    // Check if TEMP asset already exists by url_hash.
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('source_type', 'external');
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      $asset_id = reset($existing_ids);
    }
    else {
      // Create new external asset.
      $config = $this->configFactory->get('digital_asset_inventory.settings');
      $asset_types_config = $config->get('asset_types');
      $label = $asset_types_config[$asset_type]['label'] ?? $asset_type;

      $asset = $asset_storage->create([
        'source_type' => 'external',
        'url_hash' => $url_hash,
        'asset_type' => $asset_type,
        'category' => $category,
        'sort_order' => $sort_order,
        'file_path' => $url,
        'file_name' => $label,
        'mime_type' => $label,
        'filesize' => 0,
        'is_temp' => $is_temp,
      ]);
      $asset->save();
      $asset_id = $asset->id();
    }

    // Determine parent entity for paragraphs.
    $parent_entity_type = $table_info['entity_type'];
    $parent_entity_id = $entity_id;

    if ($parent_entity_type === 'paragraph') {
      if (!array_key_exists($entity_id, $this->paragraphParentCache)) {
        $this->paragraphParentCache[$entity_id] = $this->getParentFromParagraph($entity_id);
      }
      $parent_info = $this->paragraphParentCache[$entity_id];
      if ($parent_info && empty($parent_info['orphan'])) {
        $parent_entity_type = $parent_info['type'];
        $parent_entity_id = $parent_info['id'];
      }
      elseif ($parent_info && !empty($parent_info['orphan'])) {
        // Orphan detected — create orphan reference record.
        $this->createOrphanReference($asset_id, 'paragraph', $entity_id, $table_info['field_name'], $embed_method, $parent_info['context']);
        return 0;
      }
      else {
        // Paragraph not found (NULL) — skip.
        return 0;
      }
    }

    // Track usage - check if usage record exists.
    $usage_query = $usage_storage->getQuery();
    $usage_query->condition('asset_id', $asset_id);
    $usage_query->condition('entity_type', $parent_entity_type);
    $usage_query->condition('entity_id', $parent_entity_id);
    $usage_query->condition('field_name', $table_info['field_name']);
    $usage_query->accessCheck(FALSE);
    $usage_ids = $usage_query->execute();

    if (!$usage_ids) {
      // Create usage tracking record.
      $usage_storage->create([
        'asset_id' => $asset_id,
        'entity_type' => $parent_entity_type,
        'entity_id' => $parent_entity_id,
        'field_name' => $table_info['field_name'],
        'count' => 1,
        'embed_method' => $embed_method,
      ])->save();
    }

    // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

    return 1;
  }

  /**
   * Gets MIME type from file extension.
   *
   * @param string $extension
   *   The file extension (without dot).
   *
   * @return string
   *   The MIME type.
   */
  protected function getMimeTypeFromExtension($extension) {
    $mime_map = [
      // Documents.
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'txt' => 'text/plain',
      'csv' => 'text/csv',
      'vtt' => 'text/vtt',
      'srt' => 'text/plain',
      // Images.
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'svg' => 'image/svg+xml',
      'webp' => 'image/webp',
      // Videos.
      'mp4' => 'video/mp4',
      'webm' => 'video/webm',
      'mov' => 'video/quicktime',
      'avi' => 'video/x-msvideo',
      // Audio.
      'mp3' => 'audio/mpeg',
      'wav' => 'audio/wav',
      'm4a' => 'audio/mp4',
      'ogg' => 'audio/ogg',
      // Archives.
      'zip' => 'application/zip',
      'tar' => 'application/x-tar',
      'gz' => 'application/gzip',
      '7z' => 'application/x-7z-compressed',
      'rar' => 'application/x-rar-compressed',
    ];

    return $mime_map[$extension] ?? 'application/octet-stream';
  }

  /**
   * Finds or creates a local asset for HTML5 media embed.
   *
   * @param string $stream_uri
   *   The Drupal stream URI (public:// or private://).
   * @param string $absolute_url
   *   The absolute URL of the file.
   * @param array $embed
   *   The parsed embed data.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   *
   * @return int|null
   *   The asset ID, or NULL if not found/created.
   */
  protected function findOrCreateLocalAssetForHtml5($stream_uri, $absolute_url, array $embed, $is_temp, $asset_storage) {
    $url_hash = md5($absolute_url);

    // Check if file exists in file_managed first.
    $file = NULL;
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $stream_uri]);
      $file = reset($files);
    }
    catch (\Exception $e) {
      // File not in file_managed.
    }

    // If file exists in file_managed, check if asset already exists by fid.
    // This links HTML5 embeds to existing inventory items from managed file scan.
    if ($file) {
      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('fid', $file->id());
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        return reset($existing_ids);
      }
    }

    // Check if asset already exists by url_hash (for filesystem_only files).
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      return reset($existing_ids);
    }

    // No existing asset found - create a new one.
    // This should only happen for filesystem_only files not yet in inventory.

    // Determine asset type from file extension.
    $extension = pathinfo($stream_uri, PATHINFO_EXTENSION);
    $asset_type = $this->mapExtensionToAssetType(strtolower($extension));
    $category = $embed['type'] === 'video' ? 'Videos' : 'Audio';
    $sort_order = $this->getCategorySortOrder($category);

    // Get filename.
    $filename = basename($stream_uri);

    // Determine source type.
    $source_type = $file ? 'file_managed' : 'filesystem_only';

    // Get file size and MIME type.
    $filesize = 0;
    $mime_type = '';
    if ($file) {
      $filesize = $file->getSize() ?: 0;
      $mime_type = $file->getMimeType() ?: '';
    }
    else {
      // Try to get from filesystem.
      $real_path = $this->fileSystem->realpath($stream_uri);
      if ($real_path && file_exists($real_path)) {
        $filesize = filesize($real_path);
        $mime_type = mime_content_type($real_path) ?: '';
      }
    }

    // Check if file is private.
    $is_private = strpos($stream_uri, 'private://') === 0;

    // Create the asset.
    $asset = $asset_storage->create([
      'fid' => $file ? $file->id() : NULL,
      'source_type' => $source_type,
      'url_hash' => $url_hash,
      'asset_type' => $asset_type,
      'category' => $category,
      'sort_order' => $sort_order,
      'file_path' => $absolute_url,
      'file_name' => $filename,
      'mime_type' => $mime_type,
      'filesize' => $filesize,
      'is_temp' => $is_temp,
      'is_private' => $is_private,
    ]);
    $asset->save();

    // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

    return $asset->id();
  }

  /**
   * Finds or creates an external asset for HTML5 media embed.
   *
   * @param string $absolute_url
   *   The absolute URL of the external media.
   * @param array $embed
   *   The parsed embed data.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   *
   * @return int|null
   *   The asset ID, or NULL if not created.
   */
  protected function findOrCreateExternalAssetForHtml5($absolute_url, array $embed, $is_temp, $asset_storage) {
    $url_hash = md5($absolute_url);

    // Check if temp asset already exists.
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('source_type', 'external');
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      return reset($existing_ids);
    }

    // Determine category based on embed type.
    $category = $embed['type'] === 'video' ? 'Embedded Media' : 'Audio';
    $asset_type = $embed['type'] === 'video' ? 'external_video' : 'external_audio';
    $sort_order = $this->getCategorySortOrder($category);

    // Extract filename from URL.
    $parsed = parse_url($absolute_url);
    $filename = basename($parsed['path'] ?? $absolute_url);
    if (empty($filename) || $filename === '/') {
      $filename = $embed['type'] === 'video' ? 'External Video' : 'External Audio';
    }

    // Create the asset.
    $asset = $asset_storage->create([
      'source_type' => 'external',
      'url_hash' => $url_hash,
      'asset_type' => $asset_type,
      'category' => $category,
      'sort_order' => $sort_order,
      'file_path' => $absolute_url,
      'file_name' => $filename,
      'mime_type' => $embed['type'] . '/*',
      'filesize' => 0,
      'is_temp' => $is_temp,
      'is_private' => FALSE,
    ]);
    $asset->save();

    // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

    return $asset->id();
  }

  /**
   * Finds or creates a caption/subtitle file asset.
   *
   * @param string $stream_uri
   *   The Drupal stream URI.
   * @param string $absolute_url
   *   The absolute URL.
   * @param array $track
   *   Track info (kind, srclang, label).
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   *
   * @return int|null
   *   The asset ID, or NULL if not created.
   */
  protected function findOrCreateCaptionAsset($stream_uri, $absolute_url, array $track, $is_temp, $asset_storage) {
    $url_hash = md5($absolute_url);

    // Check if file exists in file_managed first.
    $file = NULL;
    try {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $stream_uri]);
      $file = reset($files);
    }
    catch (\Exception $e) {
      // File not in file_managed.
    }

    // If file exists in file_managed, check if asset already exists by fid.
    if ($file) {
      $existing_query = $asset_storage->getQuery();
      $existing_query->condition('fid', $file->id());
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        return reset($existing_ids);
      }
    }

    // Check if temp asset already exists by url_hash.
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      return reset($existing_ids);
    }

    // No existing asset found - create a new one.

    // Determine asset type from extension.
    $extension = strtolower(pathinfo($stream_uri, PATHINFO_EXTENSION));
    $asset_type = in_array($extension, ['vtt', 'srt']) ? $extension : 'text';
    $category = 'Documents';
    $sort_order = $this->getCategorySortOrder($category);

    // Get filename with language context.
    $filename = basename($stream_uri);
    if ($track['label']) {
      $filename .= ' (' . $track['label'] . ')';
    }
    elseif ($track['srclang']) {
      $filename .= ' (' . strtoupper($track['srclang']) . ')';
    }

    $source_type = $file ? 'file_managed' : 'filesystem_only';

    // Get file info.
    $filesize = 0;
    $mime_type = 'text/plain';
    if ($file) {
      $filesize = $file->getSize() ?: 0;
      $mime_type = $file->getMimeType() ?: 'text/plain';
    }
    else {
      $real_path = $this->fileSystem->realpath($stream_uri);
      if ($real_path && file_exists($real_path)) {
        $filesize = filesize($real_path);
      }
    }

    // Check if private.
    $is_private = strpos($stream_uri, 'private://') === 0;

    // Create the asset.
    $asset = $asset_storage->create([
      'fid' => $file ? $file->id() : NULL,
      'source_type' => $source_type,
      'url_hash' => $url_hash,
      'asset_type' => $asset_type,
      'category' => $category,
      'sort_order' => $sort_order,
      'file_path' => $absolute_url,
      'file_name' => $filename,
      'mime_type' => $mime_type,
      'filesize' => $filesize,
      'is_temp' => $is_temp,
      'is_private' => $is_private,
    ]);
    $asset->save();

    // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

    return $asset->id();
  }

  /**
   * Finds or creates an external caption file asset.
   *
   * @param string $absolute_url
   *   The absolute URL.
   * @param array $track
   *   Track info.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   * @param object $asset_storage
   *   The asset entity storage.
   *
   * @return int|null
   *   The asset ID.
   */
  protected function findOrCreateExternalCaptionAsset($absolute_url, array $track, $is_temp, $asset_storage) {
    $url_hash = md5($absolute_url);

    // Check if temp asset already exists.
    $existing_query = $asset_storage->getQuery();
    $existing_query->condition('url_hash', $url_hash);
    $existing_query->condition('source_type', 'external');
    $existing_query->condition('is_temp', TRUE);
    $existing_query->accessCheck(FALSE);
    $existing_ids = $existing_query->execute();

    if ($existing_ids) {
      return reset($existing_ids);
    }

    // Determine asset type.
    $extension = strtolower(pathinfo(parse_url($absolute_url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $asset_type = in_array($extension, ['vtt', 'srt']) ? $extension : 'text';

    // Get filename.
    $filename = basename(parse_url($absolute_url, PHP_URL_PATH));
    if ($track['label']) {
      $filename .= ' (' . $track['label'] . ')';
    }

    // Create the asset.
    $asset = $asset_storage->create([
      'source_type' => 'external',
      'url_hash' => $url_hash,
      'asset_type' => $asset_type,
      'category' => 'Documents',
      'sort_order' => $this->getCategorySortOrder('Documents'),
      'file_path' => $absolute_url,
      'file_name' => $filename,
      'mime_type' => 'text/plain',
      'filesize' => 0,
      'is_temp' => $is_temp,
      'is_private' => FALSE,
    ]);
    $asset->save();

    // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

    return $asset->id();
  }

  /**
   * Creates a usage record for HTML5 media embed with signals.
   *
   * @param int $asset_id
   *   The asset ID.
   * @param string $entity_type
   *   The entity type.
   * @param int $entity_id
   *   The entity ID.
   * @param string $field_name
   *   The field name.
   * @param string $embed_method
   *   The embed method (html5_video, html5_audio).
   * @param array $signals
   *   Accessibility signals (controls, autoplay, muted, loop).
   * @param array $tracks
   *   Track elements found (for captions signal).
   * @param object $usage_storage
   *   The usage entity storage.
   */
  protected function createHtml5UsageRecord($asset_id, $entity_type, $entity_id, $field_name, $embed_method, array $signals, array $tracks, $usage_storage) {
    // Always create a new usage record for each embed.
    // Each usage is tracked separately, even if same asset appears multiple times on same page.

    // Build accessibility signals.
    $accessibility_signals = $this->buildAccessibilitySignals($signals, $tracks);

    // Determine presentation type.
    $presentation_type = '';
    if ($embed_method === 'html5_video') {
      $presentation_type = 'VIDEO_HTML5';
    }
    elseif ($embed_method === 'html5_audio') {
      $presentation_type = 'AUDIO_HTML5';
    }

    // Create new usage record.
    $usage = $usage_storage->create([
      'asset_id' => $asset_id,
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'field_name' => $field_name,
      'count' => 1,
      'embed_method' => $embed_method,
      'presentation_type' => $presentation_type,
      'accessibility_signals' => json_encode($accessibility_signals),
      'signals_evaluated' => TRUE,
    ]);
    $usage->save();
  }

  /**
   * Builds accessibility signals array from HTML5 embed data.
   *
   * @param array $signals
   *   Raw signals from HTML parsing (controls, autoplay, muted, loop).
   * @param array $tracks
   *   Track elements found.
   *
   * @return array
   *   Formatted signals array for storage.
   */
  protected function buildAccessibilitySignals(array $signals, array $tracks) {
    $result = [
      'controls' => !empty($signals['controls']) ? 'detected' : 'not_detected',
      'autoplay' => !empty($signals['autoplay']) ? 'detected' : 'not_detected',
      'muted' => !empty($signals['muted']) ? 'detected' : 'not_detected',
      'loop' => !empty($signals['loop']) ? 'detected' : 'not_detected',
    ];

    // Check for captions in tracks.
    $has_captions = FALSE;
    foreach ($tracks as $track) {
      if (in_array($track['kind'], ['captions', 'subtitles'])) {
        $has_captions = TRUE;
        break;
      }
    }
    $result['captions'] = $has_captions ? 'detected' : 'not_detected';

    return $result;
  }

  /**
   * Maps file extension to asset type.
   *
   * @param string $extension
   *   The file extension (lowercase).
   *
   * @return string
   *   The asset type.
   */
  protected function mapExtensionToAssetType($extension) {
    $map = [
      // Video.
      'mp4' => 'mp4',
      'webm' => 'webm',
      'mov' => 'mov',
      'avi' => 'avi',
      'mkv' => 'mkv',
      'ogv' => 'ogv',
      // Audio.
      'mp3' => 'mp3',
      'wav' => 'wav',
      'ogg' => 'ogg',
      'oga' => 'ogg',
      'm4a' => 'm4a',
      'flac' => 'flac',
      'aac' => 'aac',
      // Captions.
      'vtt' => 'vtt',
      'srt' => 'srt',
    ];

    return $map[$extension] ?? $extension;
  }

  /**
   * Finds media usage using Drupal's Entity Query API.
   *
   * This method bypasses entity_usage entirely and uses Entity Query to find
   * where media is used. Entity Query automatically queries only current
   * revisions and excludes deleted entities.
   *
   * @param int $media_id
   *   The media entity ID.
   *
   * @return array
   *   Array of references, each with keys: entity_type, entity_id.
   */
  protected function findMediaUsageViaEntityQuery($media_id) {
    $references = [];

    try {
      // Load the media entity to get its UUID for text field searching.
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if (!$media) {
        return $references;
      }

      $media_uuid = $media->uuid();

      // Get the field manager to find media reference fields.
      $field_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');

      // 1. Check entity reference fields that target media.
      foreach ($field_map as $entity_type_id => $fields) {
        // Skip the media entity type itself.
        if ($entity_type_id === 'media') {
          continue;
        }

        // Check if storage exists for this entity type.
        try {
          $storage = $this->entityTypeManager->getStorage($entity_type_id);
        }
        catch (\Exception $e) {
          continue;
        }

        // Collect media reference fields for this entity type.
        $media_fields = [];
        foreach ($fields as $field_name => $field_info) {
          // Load the field storage definition to check target type.
          try {
            $field_storage = $this->entityTypeManager
              ->getStorage('field_storage_config')
              ->load($entity_type_id . '.' . $field_name);

            if ($field_storage && $field_storage->getSetting('target_type') === 'media') {
              $media_fields[] = $field_name;
            }
          }
          catch (\Exception $e) {
            continue;
          }
        }

        if (empty($media_fields)) {
          continue;
        }

        // Query for entities that reference this media.
        // Query each field separately to capture which field contains the reference.
        foreach ($media_fields as $field_name) {
          try {
            $query = $storage->getQuery()
              ->accessCheck(FALSE)
              ->condition($field_name, $media_id);

            $entity_ids = $query->execute();

            foreach ($entity_ids as $entity_id) {
              $references[] = [
                'entity_type' => $entity_type_id,
                'entity_id' => $entity_id,
                'field_name' => $field_name,
                'method' => 'entity_reference',
              ];
            }
          }
          catch (\Exception $e) {
            // Skip fields that can't be queried.
            continue;
          }
        }
      }

      // 2. Check text fields for CKEditor embeds (<drupal-media> tags).
      $text_field_map = $this->entityFieldManager->getFieldMapByFieldType('text_long');
      $text_with_summary_map = $this->entityFieldManager->getFieldMapByFieldType('text_with_summary');

      // Merge text field maps.
      foreach ($text_with_summary_map as $entity_type_id => $fields) {
        if (!isset($text_field_map[$entity_type_id])) {
          $text_field_map[$entity_type_id] = [];
        }
        $text_field_map[$entity_type_id] = array_merge($text_field_map[$entity_type_id], $fields);
      }

      foreach ($text_field_map as $entity_type_id => $fields) {
        // Skip the media entity type.
        if ($entity_type_id === 'media') {
          continue;
        }

        try {
          $storage = $this->entityTypeManager->getStorage($entity_type_id);
        }
        catch (\Exception $e) {
          continue;
        }

        foreach ($fields as $field_name => $field_info) {
          // Query for entities where text field contains the media UUID.
          try {
            $query = $storage->getQuery()
              ->accessCheck(FALSE)
              ->condition($field_name, '%' . $media_uuid . '%', 'LIKE');

            $entity_ids = $query->execute();

            foreach ($entity_ids as $entity_id) {
              // Verify entity has media embed in current content.
              $entity = $storage->load($entity_id);
              if (!$entity || !$entity->hasField($field_name)) {
                continue;
              }

              $field_value = $entity->get($field_name)->value ?? '';

              // Check if the UUID is actually in the current field value.
              if (strpos($field_value, $media_uuid) !== FALSE) {
                $references[] = [
                  'entity_type' => $entity_type_id,
                  'entity_id' => $entity_id,
                  'field_name' => $field_name,
                  'method' => 'media_embed',
                ];
              }
            }
          }
          catch (\Exception $e) {
            // Skip fields that can't be queried.
            continue;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error finding media usage via entity query: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    // Remove duplicates (same entity might be found via multiple fields).
    $unique_refs = [];
    foreach ($references as $ref) {
      $key = $ref['entity_type'] . ':' . $ref['entity_id'];
      if (!isset($unique_refs[$key])) {
        $unique_refs[$key] = $ref;
      }
    }

    return array_values($unique_refs);
  }

  /**
   * Finds all media references by directly scanning content field tables.
   *
   * This method replaces entity_usage dependency with direct database queries
   * to find where media is actually used in current content.
   *
   * @param int $media_id
   *   The media entity ID.
   *
   * @return array
   *   Array of refs with keys: entity_type, entity_id, field_name, method.
   */
  protected function findMediaReferencesDirectly($media_id) {
    $references = [];

    // Get the media entity's UUID for searching in text fields.
    $media_uuid = NULL;
    try {
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if ($media) {
        $media_uuid = $media->uuid();
      }
    }
    catch (\Exception $e) {
      // Media doesn't exist.
    }

    // 1. Scan entity reference fields that point to media.
    $entity_ref_results = $this->scanEntityReferenceFields($media_id);
    foreach ($entity_ref_results as $ref) {
      $references[] = $ref;
    }

    // 2. Scan text fields for embedded media (<drupal-media> tags).
    if ($media_uuid) {
      $embed_results = $this->scanTextFieldsForMediaEmbed($media_uuid);
      foreach ($embed_results as $ref) {
        $references[] = $ref;
      }
    }

    return $references;
  }

  /**
   * Scans entity reference fields for media references.
   *
   * @param int $media_id
   *   The media entity ID.
   *
   * @return array
   *   Array of references found.
   */
  protected function scanEntityReferenceFields($media_id) {
    $references = [];

    // Get list of fields that actually reference media entities.
    $media_reference_fields = $this->getMediaReferenceFields();

    // Only scan tables for fields that are configured to reference media.
    foreach ($media_reference_fields as $field_info) {
      $table = $field_info['table'];
      $field_name = $field_info['field_name'];
      $entity_type = $field_info['entity_type'];
      $target_id_column = $field_name . '_target_id';

      try {
        $results = $this->database->select($table, 't')
          ->fields('t', ['entity_id'])
          ->condition($target_id_column, $media_id)
          ->execute()
          ->fetchAll();

        foreach ($results as $row) {
          $references[] = [
            'entity_type' => $entity_type,
            'entity_id' => $row->entity_id,
            'field_name' => $field_name,
            'method' => 'entity_reference',
          ];
        }
      }
      catch (\Exception $e) {
        // Skip tables that can't be queried.
      }
    }

    return $references;
  }

  /**
   * Gets all entity reference fields that target media entities.
   *
   * @return array
   *   Array of field info with keys: table, field_name, entity_type.
   */
  protected function getMediaReferenceFields() {
    $media_fields = [];

    try {
      // Load all field storage config entities.
      $field_storage_storage = $this->entityTypeManager->getStorage('field_storage_config');
      $field_storages = $field_storage_storage->loadMultiple();

      foreach ($field_storages as $field_storage) {
        // Check if this field is an entity_reference type.
        if ($field_storage->getType() !== 'entity_reference') {
          continue;
        }

        // Check if this field targets media entities.
        $target_type = $field_storage->getSetting('target_type');
        if ($target_type !== 'media') {
          continue;
        }

        // Get the field name and entity type.
        $field_name = $field_storage->getName();
        $entity_type_id = $field_storage->getTargetEntityTypeId();

        // Build the table name (current revision tables only).
        $table = $entity_type_id . '__' . $field_name;

        // Check if the table exists.
        if ($this->database->schema()->tableExists($table)) {
          $media_fields[] = [
            'table' => $table,
            'field_name' => $field_name,
            'entity_type' => $entity_type_id,
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Fallback: scan common media field patterns if config loading fails.
      $this->logger->warning('Could not load field config, using fallback media field detection');
    }

    return $media_fields;
  }

  /**
   * Returns cached list of entity text-field tables for LIKE-based link detection.
   *
   * Built once per PHP process from SHOW TABLES + schema inspection.
   * Replaces 4 SHOW TABLES + ~235 fieldExists() calls per orphan file
   * with a single discovery pass cached for the lifetime of the scanner.
   *
   * @return array
   *   Array of ['table', 'entity_type', 'field_name', 'value_column'].
   */
  protected function getTextFieldTables(): array {
    if ($this->textFieldTableCache !== NULL) {
      return $this->textFieldTableCache;
    }

    $this->textFieldTableCache = [];
    $db_schema = $this->database->schema();
    $prefixes = [
      'node__' => 'node',
      'taxonomy_term__' => 'taxonomy_term',
      'block_content__' => 'block_content',
    ];
    // Only scan paragraph tables if the Paragraphs module is installed.
    if ($this->moduleHandler->moduleExists('paragraphs')) {
      $prefixes['paragraph__'] = 'paragraph';
    }

    foreach ($prefixes as $prefix => $entity_type) {
      // Use Schema::findTables() for cross-database compatibility (D10/D11).
      $tables = $db_schema->findTables($prefix . '%');

      foreach ($tables as $table) {
        $field_name = substr($table, strlen($prefix));
        $value_column = $field_name . '_value';

        if ($db_schema->fieldExists($table, $value_column)) {
          $this->textFieldTableCache[] = [
            'table' => $table,
            'entity_type' => $entity_type,
            'field_name' => $field_name,
            'value_column' => $value_column,
          ];
        }
      }
    }

    return $this->textFieldTableCache;
  }

  /**
   * Builds search needles for a file URI (public:// or private://).
   *
   * Covers multisite, Site Factory, and non-standard public file paths.
   * Extracted from findLocalFileLinkUsage() for reuse in batch methods.
   *
   * @param string $file_uri
   *   Drupal stream URI (e.g. 'public://documents/report.pdf').
   *
   * @return array
   *   Unique search needle strings for LIKE queries.
   */
  protected function buildFileSearchNeedles(string $file_uri): array {
    $needles = [];

    if (strpos($file_uri, 'public://') === 0) {
      $relative = substr($file_uri, 9);
      $needles[] = '/files/' . $relative;
      $needles[] = $this->getPublicFilesBasePath() . '/' . $relative;
    }
    elseif (strpos($file_uri, 'private://') === 0) {
      $relative = substr($file_uri, 10);
      $needles[] = '/system/files/' . $relative;
      $needles[] = '/files/private/' . $relative;
      $needles[] = $this->getPublicFilesBasePath() . '/private/' . $relative;
    }

    return array_values(array_unique($needles));
  }

  /**
   * Batch-finds local file link usage for multiple orphan files at once.
   *
   * Replaces N × findLocalFileLinkUsage() calls with a single pass over the
   * text-field table list. For each table, ONE query with OR LIKE covers all
   * orphan needles, reducing ~5,400 queries to ~113.
   *
   * @param array $orphan_files
   *   Array of orphan file info arrays, each with 'uri' and 'url_hash' keys.
   *
   * @return array
   *   Keyed by url_hash => array of references (entity_type, entity_id,
   *   field_name, method). Already deduplicated per hash.
   */
  protected function findLocalFileLinkUsageBatch(array $orphan_files): array {
    $tables = $this->getTextFieldTables();

    // Build needle → url_hash mapping.
    $needle_to_hashes = [];
    foreach ($orphan_files as $file) {
      $needles = $this->buildFileSearchNeedles($file['uri']);
      foreach ($needles as $needle) {
        $needle_to_hashes[$needle][] = $file['url_hash'];
      }
    }

    $all_needles = array_keys($needle_to_hashes);
    if (empty($all_needles)) {
      return [];
    }

    $usage_by_hash = [];

    foreach ($tables as $t) {
      try {
        $query = $this->database->select($t['table'], 'tbl')
          ->fields('tbl', ['entity_id', $t['value_column']]);

        $or = $query->orConditionGroup();
        foreach ($all_needles as $needle) {
          $or->condition($t['value_column'], '%' . $this->database->escapeLike($needle) . '%', 'LIKE');
        }
        $query->condition($or);

        $rows = $query->execute()->fetchAll();
      }
      catch (\Exception $e) {
        continue;
      }

      if (empty($rows)) {
        continue;
      }

      // Map matching rows back to orphan files via PHP-side needle check.
      $value_col = $t['value_column'];
      foreach ($rows as $row) {
        $text = $row->$value_col;
        foreach ($needle_to_hashes as $needle => $hashes) {
          if (stripos($text, $needle) !== FALSE) {
            foreach ($hashes as $hash) {
              $usage_by_hash[$hash][] = [
                'entity_type' => $t['entity_type'],
                'entity_id' => $row->entity_id,
                'field_name' => $t['field_name'],
                'method' => 'file_link',
              ];
            }
          }
        }
      }
    }

    // Dedup per hash.
    foreach ($usage_by_hash as $hash => &$refs) {
      $unique = [];
      foreach ($refs as $ref) {
        $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . $ref['field_name'];
        $unique[$key] = $ref;
      }
      $refs = array_values($unique);
    }
    unset($refs);

    return $usage_by_hash;
  }

  /**
   * Finds local file link usage by scanning text fields for file URLs.
   *
   * @param string $file_uri
   *   The file URI (e.g., public://files/document.pdf).
   *
   * @return array
   *   Array of references found, each with entity_type, entity_id, field_name.
   */
  protected function findLocalFileLinkUsage(string $file_uri): array {
    $references = [];
    $search_needles = $this->buildFileSearchNeedles($file_uri);
    if (empty($search_needles)) {
      return [];
    }

    // Use cached text-field table list (avoids SHOW TABLES + fieldExists
    // per call — significant when called from Phase 3 content scanning).
    $tables = $this->getTextFieldTables();

    foreach ($tables as $t) {
      foreach ($search_needles as $needle) {
        try {
          $results = $this->database->select($t['table'], 'tbl')
            ->fields('tbl', ['entity_id'])
            ->condition($t['value_column'], '%' . $this->database->escapeLike($needle) . '%', 'LIKE')
            ->execute()
            ->fetchAll();

          foreach ($results as $row) {
            $references[] = [
              'entity_type' => $t['entity_type'],
              'entity_id' => $row->entity_id,
              'field_name' => $t['field_name'],
              'method' => 'file_link',
            ];
          }
        }
        catch (\Exception $e) {
          // Skip tables that can't be queried.
        }
      }
    }

    // De-dupe by entity_type:entity_id:field_name.
    $unique_refs = [];
    foreach ($references as $ref) {
      $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . $ref['field_name'];
      $unique_refs[$key] = $ref;
    }

    return array_values($unique_refs);
  }

  /**
   * Finds direct file/image field usage for a file.
   *
   * This detects files used in direct 'image' or 'file' field types,
   * NOT via media entities.
   *
   * @param int $file_id
   *   The file ID from file_managed.
   *
   * @return array
   *   Array of references found, each with entity_type, entity_id, field_name.
   */
  protected function findDirectFileUsage($file_id) {
    $references = [];

    try {
      // Scan for direct file/image field usage using file_usage table.
      // file_usage tracks where files are used, regardless of media.
      $file_usages = $this->database->select('file_usage', 'fu')
        ->fields('fu', ['type', 'id', 'module'])
        ->condition('fid', $file_id)
        // Exclude media type as those are handled separately.
        ->condition('type', 'media', '!=')
        ->execute()
        ->fetchAll();

      foreach ($file_usages as $usage) {
        // Only track usage in content entities (node, paragraph, etc.).
        if (in_array($usage->type, ['node', 'paragraph', 'taxonomy_term', 'block_content'])) {
          // Try to find the actual field name that contains this file.
          // This checks the entity's CURRENT/DEFAULT revision.
          $field_name = $this->findFileFieldName($usage->type, $usage->id, $file_id);

          // Skip if file is not in the entity's current revision.
          // 'direct_file' means the file wasn't found - likely from a previous revision.
          if ($field_name === 'direct_file') {
            continue;
          }

          $references[] = [
            'entity_type' => $usage->type,
            'entity_id' => $usage->id,
            'field_name' => $field_name,
            'method' => 'file_usage',
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error finding direct file usage: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $references;
  }

  /**
   * Finds the field name that contains a specific file in an entity.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   * @param int $file_id
   *   The file ID to find.
   *
   * @return string
   *   The field name, or 'direct_file' if not found.
   */
  protected function findFileFieldName($entity_type, $entity_id, $file_id) {
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        return 'direct_file';
      }

      $bundle = $entity->bundle();

      // Get all field definitions for this entity type/bundle.
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

      foreach ($field_definitions as $field_name => $field_definition) {
        $field_type = $field_definition->getType();

        // Check file and image fields.
        if (in_array($field_type, ['file', 'image'])) {
          if ($entity->hasField($field_name)) {
            $field_values = $entity->get($field_name)->getValue();
            foreach ($field_values as $value) {
              if (isset($value['target_id']) && (int) $value['target_id'] === (int) $file_id) {
                return $field_name;
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // Fall back to generic name on error.
    }

    return 'direct_file';
  }

  /**
   * Scans text fields for embedded media tags.
   *
   * @param string $media_uuid
   *   The media entity UUID.
   *
   * @return array
   *   Array of references found.
   */
  protected function scanTextFieldsForMediaEmbed($media_uuid) {
    $references = [];
    $db_schema = $this->database->schema();

    // Entity type prefixes to scan (current revision tables only).
    // Includes taxonomy_term for text fields on taxonomy terms.
    // Includes block_content for custom blocks like sidebar navigation.
    $prefixes = ['node__', 'taxonomy_term__', 'block_content__'];
    if ($this->moduleHandler->moduleExists('paragraphs')) {
      $prefixes[] = 'paragraph__';
    }

    foreach ($prefixes as $prefix) {
      $entity_type = str_replace('__', '', $prefix);

      // Find all tables with this prefix (cross-database compatible).
      $all_tables = $this->database->schema()->findTables($prefix . '%');

      foreach ($all_tables as $table) {
        $field_name = str_replace($prefix, '', $table);
        $value_column = $field_name . '_value';

        // Check if this table has a _value column (text field).
        if (!$db_schema->fieldExists($table, $value_column)) {
          continue;
        }

        // Search for the media UUID in the text field.
        try {
          $results = $this->database->select($table, 't')
            ->fields('t', ['entity_id'])
            ->condition($value_column, '%' . $this->database->escapeLike($media_uuid) . '%', 'LIKE')
            ->execute()
            ->fetchAll();

          foreach ($results as $row) {
            $references[] = [
              'entity_type' => $entity_type,
              'entity_id' => $row->entity_id,
              'field_name' => $field_name,
              'method' => 'media_embed',
            ];
          }
        }
        catch (\Exception $e) {
          // Skip tables that can't be queried.
        }
      }
    }

    return $references;
  }

  /**
   * Gets root parent entity from a paragraph (handles nested paragraphs).
   *
   * Verifies that paragraph chain is actually attached and not orphaned.
   * Handles nested structures like: Node > slideshow > slide > content.
   *
   * @param int $paragraph_id
   *   The paragraph ID.
   *
   * @return array|null
   *   One of three return types:
   *   - Valid parent: ['type' => string, 'id' => int]
   *   - Orphan detected: ['orphan' => TRUE, 'context' => string, 'paragraph_id' => int]
   *   - Not found (paragraph doesn't exist): NULL
   */
  protected function getParentFromParagraph($paragraph_id) {
    // Guard: Paragraphs module must be installed.
    if (!$this->moduleHandler->moduleExists('paragraphs')) {
      return NULL;
    }

    try {
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($paragraph_id);

      if (!$paragraph) {
        // Paragraph entity no longer exists (already deleted). This is a stale
        // reference (e.g., in file_usage table), not an orphan paragraph.
        // Do NOT increment orphan count — only actual existing-but-detached
        // paragraphs should be counted as orphans.
        return NULL;
      }

      // Build the complete paragraph chain from child to root.
      $paragraph_chain = [$paragraph];
      $current = $paragraph;

      // Use getParentEntity() to trace through nested paragraphs.
      if (method_exists($current, 'getParentEntity')) {
        while ($current) {
          $parent = $current->getParentEntity();

          // If parent is NULL at any point, the chain is orphaned.
          if (!$parent) {
            $this->currentOrphanCount++;
            return ['orphan' => TRUE, 'context' => 'missing_parent_entity', 'paragraph_id' => $paragraph_id];
          }

          // If parent is another paragraph, continue tracing.
          if ($parent->getEntityTypeId() === 'paragraph') {
            $paragraph_chain[] = $parent;
            $current = $parent;
            continue;
          }

          // Found a non-paragraph parent (node, block_content, etc.).
          // Now verify the entire chain is properly attached.
          // No entity-type guard: always verify attachment via
          // isParagraphInEntityField(). The method works for any entity type.
          $root_parent = $parent;
          $root_paragraph = end($paragraph_chain);

          // Verify root paragraph is in parent's current paragraph fields.
          if (!$this->isParagraphInEntityField($root_paragraph->id(), $root_parent)) {
            $this->currentOrphanCount++;
            return ['orphan' => TRUE, 'context' => 'detached_component', 'paragraph_id' => $paragraph_id];
          }

          // Also verify each nested paragraph is in its parent's fields.
          for ($i = 0; $i < count($paragraph_chain) - 1; $i++) {
            $child_paragraph = $paragraph_chain[$i];
            $parent_paragraph = $paragraph_chain[$i + 1];

            if (!$this->isParagraphInEntityField($child_paragraph->id(), $parent_paragraph)) {
              $this->currentOrphanCount++;
              return ['orphan' => TRUE, 'context' => 'detached_component', 'paragraph_id' => $paragraph_id];
            }
          }

          return [
            'type' => $root_parent->getEntityTypeId(),
            'id' => $root_parent->id(),
          ];
        }
      }
      else {
        // Fallback for older Drupal versions: Use parent_type/parent_id fields.
        $parent_type = $paragraph->get('parent_type')->value;
        $parent_id = $paragraph->get('parent_id')->value;

        if ($parent_type && $parent_id) {
          if ($parent_type === 'paragraph') {
            // Recursively trace nested paragraphs.
            return $this->getParentFromParagraph($parent_id);
          }

          // Found non-paragraph parent - verify attachment.
          if (!$this->isParaGraphAttachedToNode($paragraph_id, $parent_id)) {
            $this->currentOrphanCount++;
            return ['orphan' => TRUE, 'context' => 'detached_component', 'paragraph_id' => $paragraph_id];
          }

          return [
            'type' => $parent_type,
            'id' => $parent_id,
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error tracing paragraph @id: @error', [
        '@id' => $paragraph_id,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Checks if a paragraph is in any paragraph reference field of an entity.
   *
   * @param int $paragraph_id
   *   The paragraph ID to look for.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The parent entity to check.
   *
   * @return bool
   *   TRUE if the paragraph is found in the entity's fields.
   */
  protected function isParagraphInEntityField($paragraph_id, $entity) {
    try {
      // Get all fields from the entity.
      $fields = $entity->getFields();

      foreach ($fields as $field) {
        // Check if this field is an entity reference to paragraphs.
        $definition = $field->getFieldDefinition();

        if ($definition->getType() === 'entity_reference_revisions' ||
            $definition->getType() === 'entity_reference') {
          // Check if target type is paragraph.
          $settings = $definition->getSettings();
          $target_type = $settings['target_type'] ?? NULL;

          if ($target_type === 'paragraph') {
            // Check if this field contains our paragraph.
            foreach ($field as $item) {
              if ($item->target_id == $paragraph_id) {
                return TRUE;
              }
            }
          }
        }
      }

      return FALSE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if a paragraph is actually attached to a node's current revision.
   *
   * @param int $paragraph_id
   *   The paragraph ID.
   * @param int $node_id
   *   The node ID.
   *
   * @return bool
   *   TRUE if the paragraph is attached, FALSE if orphaned.
   */
  protected function isParaGraphAttachedToNode($paragraph_id, $node_id) {
    try {
      // Dynamically find paragraph reference tables (cross-database compatible).
      $all_tables = $this->database->schema()->findTables('node__field_%');
      foreach ($all_tables as $table) {
        $field_name = str_replace('node__', '', $table);
        $target_id_column = $field_name . '_target_id';

        if ($this->database->schema()->fieldExists($table, $target_id_column)) {
          // Check if this table references the paragraph.
          try {
            $count = $this->database->select($table, 't')
              ->condition('entity_id', $node_id)
              ->condition($target_id_column, $paragraph_id)
              ->countQuery()
              ->execute()
              ->fetchField();

            if ($count > 0) {
              return TRUE;
            }
          }
          catch (\Exception $e) {
            continue;
          }
        }
      }

      return FALSE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if paragraph revision is in current revision of its parent node.
   *
   * @param int $paragraph_id
   *   The paragraph entity ID.
   * @param int $paragraph_vid
   *   The paragraph revision ID from entity_usage.
   *
   * @return bool
   *   TRUE if the paragraph is in the current revision, FALSE otherwise.
   */
  protected function isParagraphInCurrentRevision($paragraph_id, $paragraph_vid) {
    try {
      // If source_vid is 0, we regenerated from current content scan.
      // Treat as "always current" since we scanned actual field tables.
      if ($paragraph_vid == 0) {
        return TRUE;
      }

      // Load the current paragraph entity (default revision).
      $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($paragraph_id);

      if (!$paragraph) {
        // Paragraph doesn't exist anymore - skip it.
        return FALSE;
      }

      // Check if the revision ID matches the current paragraph's revision.
      $current_vid = $paragraph->getRevisionId();

      if ($paragraph_vid != $current_vid) {
        // This is an old revision reference.
        return FALSE;
      }

      // Now check if the parent node is using the current revision.
      if (!array_key_exists($paragraph_id, $this->paragraphParentCache)) {
        $this->paragraphParentCache[$paragraph_id] = $this->getParentFromParagraph($paragraph_id);
      }
      $parent_info = $this->paragraphParentCache[$paragraph_id];

      if (!$parent_info || !empty($parent_info['orphan']) || ($parent_info['type'] ?? '') !== 'node') {
        // No parent, orphan, or not a node - include it.
        return TRUE;
      }

      // Load the parent node's current revision.
      $node = $this->entityTypeManager->getStorage('node')->load($parent_info['id']);

      if (!$node) {
        return FALSE;
      }

      // The paragraph is valid if it exists in the current node revision.
      return TRUE;
    }
    catch (\Exception $e) {
      // On error, skip this reference.
      return FALSE;
    }
  }

  /**
   * Gets list of known file extensions for asset types.
   *
   * @return array
   *   Array of file extensions (without dots).
   */
  protected function getKnownExtensions() {
    return [
      // Documents.
      'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
      // Images.
      'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp',
      // Videos.
      'mp4', 'webm', 'mov', 'avi',
      // Audio.
      'mp3', 'wav', 'm4a', 'ogg',
      // Archives.
      'zip', 'tar', 'gz', '7z', 'rar',
    ];
  }

  /**
   * Maps file extension to MIME type.
   *
   * @param string $extension
   *   The file extension (without dot).
   *
   * @return string
   *   The MIME type.
   */
  protected function extensionToMime($extension) {
    $map = [
      // Documents.
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'txt' => 'text/plain',
      'csv' => 'text/csv',
      // Images.
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'svg' => 'image/svg+xml',
      'webp' => 'image/webp',
      // Videos.
      'mp4' => 'video/mp4',
      'webm' => 'video/webm',
      'mov' => 'video/quicktime',
      'avi' => 'video/x-msvideo',
      // Audio.
      'mp3' => 'audio/mpeg',
      'wav' => 'audio/wav',
      'm4a' => 'audio/mp4',
      'ogg' => 'audio/ogg',
      // Archives.
      'zip' => 'application/zip',
      'tar' => 'application/x-tar',
      'gz' => 'application/gzip',
      '7z' => 'application/x-7z-compressed',
      'rar' => 'application/x-rar-compressed',
    ];

    return $map[strtolower($extension)] ?? 'application/octet-stream';
  }

  /**
   * Recursively scans a directory for files with known extensions.
   *
   * @param string $directory
   *   The directory path to scan.
   * @param array $known_extensions
   *   Array of extensions to look for.
   * @param bool $is_private_scan
   *   Whether this is scanning the private directory.
   *
   * @return array
   *   Array of file paths relative to public directory.
   */
  protected function scanDirectoryRecursive($directory, array $known_extensions, $is_private_scan = FALSE) {
    $files = [];

    if (!is_dir($directory)) {
      return $files;
    }

    // Excluded system directories (image derivatives, aggregated files, etc.).
    $excluded_dirs = [
    // Image style derivatives.
      'styles',
    // Media thumbnails (contrib/custom thumbnail directory configurations).
      'thumbnails',
    // Media type placeholder icons.
      'media-icons',
    // oEmbed thumbnails (YouTube, Vimeo, etc.).
      'oembed_thumbnails',
    // Video poster images.
      'video_thumbnails',
    // Aggregated CSS.
      'css',
    // Aggregated JavaScript.
      'js',
    // Temporary PHP files.
      'php',
    // CTools generated content.
      'ctools',
    // Generated sitemaps.
      'xmlsitemap',
    // Config sync directories.
      'config_',
    ];

    // Merge user-configured excluded directories.
    $custom_dirs = $this->configFactory->get('digital_asset_inventory.settings')->get('scan_excluded_directories') ?? [];
    if (!empty($custom_dirs)) {
      $excluded_dirs = array_unique(array_merge($excluded_dirs, $custom_dirs));
    }

    // Only exclude 'private' subdirectory when NOT doing a private scan.
    if (!$is_private_scan) {
      $excluded_dirs[] = 'private';
    }

    // Check if path contains excluded directories (skip subdirs too).
    foreach ($excluded_dirs as $excluded) {
      if (strpos($directory, '/' . $excluded . '/') !== FALSE ||
          strpos($directory, '/' . $excluded) === strlen($directory) - strlen('/' . $excluded)) {
        return $files;
      }
    }

    $items = scandir($directory);

    foreach ($items as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }

      $path = $directory . '/' . $item;

      if (is_dir($path)) {
        // Recursively scan subdirectory.
        $files = array_merge($files, $this->scanDirectoryRecursive($path, $known_extensions, $is_private_scan));
      }
      elseif (is_file($path)) {
        // Check extension.
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, $known_extensions)) {
          $files[] = $path;
        }
      }
    }

    return $files;
  }

  /**
   * Gets count of orphan files on filesystem.
   *
   * @return int
   *   The number of orphan files.
   */
  public function getOrphanFilesCount() {
    $known_extensions = $this->getKnownExtensions();
    $orphan_count = 0;

    // Scan both public and private directories.
    $streams = ['public://', 'private://'];

    foreach ($streams as $stream) {
      $base_path = $this->fileSystem->realpath($stream);

      if (!$base_path || !is_dir($base_path)) {
        continue;
      }

      // Determine if this is a private scan.
      $is_private_scan = ($stream === 'private://');

      // Get all files with known extensions.
      $all_files = $this->scanDirectoryRecursive($base_path, $known_extensions, $is_private_scan);

      foreach ($all_files as $file_path) {
        // Convert to Drupal URI.
        $relative_path = str_replace($base_path . '/', '', $file_path);
        $uri = $stream . $relative_path;

        // Check if file exists in file_managed.
        $exists = $this->database->select('file_managed', 'f')
          ->condition('uri', $uri)
          ->countQuery()
          ->execute()
          ->fetchField();

        if (!$exists) {
          $orphan_count++;
        }
      }
    }

    return $orphan_count;
  }

  /**
   * Builds a sorted list of orphan files (not in file_managed).
   *
   * @return array
   *   Array of ['path', 'uri', 'relative', 'url_hash'] per orphan file.
   *   url_hash is pre-computed md5($uri) for bulk lookup efficiency.
   */
  protected function buildOrphanFileList(): array {
    $known_extensions = $this->getKnownExtensions();
    $orphan_files = [];
    $streams = ['public://', 'private://'];

    // Single query: load ALL managed file URIs into a hash set.
    // Replaces per-file COUNT(*) queries (e.g., 5,473 queries → 1).
    $managed_uris = $this->database->select('file_managed', 'f')
      ->fields('f', ['uri'])
      ->execute()
      ->fetchCol();
    $managed_set = array_flip($managed_uris);

    foreach ($streams as $stream) {
      $base_path = $this->fileSystem->realpath($stream);
      if (!$base_path || !is_dir($base_path)) {
        continue;
      }

      $is_private_scan = ($stream === 'private://');
      $all_files = $this->scanDirectoryRecursive($base_path, $known_extensions, $is_private_scan);

      foreach ($all_files as $file_path) {
        $relative_path = str_replace($base_path, '', $file_path);
        $relative_path = ltrim($relative_path, '/');
        $uri = $stream . $relative_path;

        // In-memory check instead of DB query.
        if (!isset($managed_set[$uri])) {
          $orphan_files[] = [
            'path' => $file_path,
            'uri' => $uri,
            'relative' => $relative_path,
            'url_hash' => md5($uri),
          ];
        }
      }
    }

    // Sort for deterministic cursor behavior.
    usort($orphan_files, fn($a, $b) => strcmp($a['uri'], $b['uri']));

    return $orphan_files;
  }

  /**
   * Processes a batch of orphan files using two-pass bulk writes.
   *
   * Pass 1 (Collect): Iterates orphan files, resolving metadata and
   * paragraph parent chains. No DB writes — pure CPU. Accumulates item
   * fields, usage records, and orphan reference records in memory, all
   * keyed by url_hash.
   *
   * Pass 2 (Flush): Bulk-deletes existing items + their usage/orphan-refs,
   * batch-inserts new items via Drupal's Insert builder (shared transaction),
   * resolves auto-increment IDs via one SELECT, then remaps and flushes
   * usage + orphan-ref buffers.
   *
   * This replaces per-item processOrphanFile() calls in
   * scanOrphanFilesChunkNew(), reducing SQL round-trips from ~310 to ~7
   * per callback (plus N in-transaction prepared statement executions).
   *
   * @param array $orphan_batch
   *   Slice of orphan file info arrays from the orphan file list.
   * @param bool $is_temp
   *   Whether to create items as temporary.
   * @param array $orphan_usage_map
   *   Pre-built usage map from Phase 2 (url_hash => usage refs).
   */
  protected function processOrphanFileBatch(array $orphan_batch, bool $is_temp, array $orphan_usage_map): void {
    if (empty($orphan_batch)) {
      return;
    }

    // ── Pass 1: Collect (CPU only, no DB writes) ────────────────────

    $item_fields_by_hash = [];   // url_hash => [column => value]
    $usage_by_hash = [];         // url_hash => [[usage record], ...]
    $orphan_refs_by_hash = [];   // url_hash => [[orphan ref record], ...]
    $all_hashes = [];

    foreach ($orphan_batch as $file_info) {
      $file_path = $file_info['path'];
      $uri = $file_info['uri'];
      $filename = basename($file_path);
      $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
      $url_hash = $file_info['url_hash'];
      $all_hashes[] = $url_hash;

      $filesize = file_exists($file_path) ? filesize($file_path) : 0;
      $mime = $this->extensionToMime($extension);
      $asset_type = $this->mapMimeToAssetType($mime);
      $category = $this->mapAssetTypeToCategory($asset_type);
      $sort_order = $this->getCategorySortOrder($category);

      try {
        $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
      }
      catch (\Exception $e) {
        $absolute_url = $uri;
      }

      $is_private = strpos($uri, 'private://') === 0;

      $item_fields_by_hash[$url_hash] = [
        'source_type' => 'filesystem_only',
        'url_hash' => $url_hash,
        'asset_type' => $asset_type,
        'category' => $category,
        'sort_order' => $sort_order,
        'file_path' => $absolute_url,
        'file_name' => $filename,
        'mime_type' => $mime,
        'filesize' => $filesize,
        'filesize_formatted' => $this->formatFileSize($filesize),
        'is_temp' => $is_temp ? 1 : 0,
        'is_private' => $is_private ? 1 : 0,
      ];

      // Resolve usage from pre-built Phase 2 index.
      $file_link_usage = $orphan_usage_map[$url_hash] ?? [];
      $seen_usage = [];

      foreach ($file_link_usage as $ref) {
        $parent_entity_type = $ref['entity_type'];
        $parent_entity_id = $ref['entity_id'];

        if ($parent_entity_type === 'paragraph') {
          if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
            $this->paragraphParentCache[$parent_entity_id] =
              $this->getParentFromParagraph($parent_entity_id);
          }
          $parent_info = $this->paragraphParentCache[$parent_entity_id];

          if ($parent_info && empty($parent_info['orphan'])) {
            $parent_entity_type = $parent_info['type'];
            $parent_entity_id = $parent_info['id'];
          }
          elseif ($parent_info && !empty($parent_info['orphan'])) {
            // Collect orphan ref — asset_id resolved in Pass 2.
            $orphan_refs_by_hash[$url_hash][] = [
              'source_entity_type' => 'paragraph',
              'source_entity_id' => $parent_entity_id,
              'field_name' => $ref['field_name'],
              'embed_method' => 'text_link',
              'reference_context' => $parent_info['context'],
            ];
            continue;
          }
          else {
            continue;
          }
        }

        $usage_key = $parent_entity_type . ':' . $parent_entity_id
          . ':' . $ref['field_name'];
        if (!isset($seen_usage[$usage_key])) {
          $seen_usage[$usage_key] = TRUE;
          $usage_by_hash[$url_hash][] = [
            'entity_type' => $parent_entity_type,
            'entity_id' => $parent_entity_id,
            'field_name' => $ref['field_name'],
            'embed_method' => 'text_link',
          ];
        }
      }
    }

    // Keep heartbeat alive between passes for large sub-batches.
    $this->maybeUpdateHeartbeat();

    // ── Pass 2: Flush (bulk DB writes) ──────────────────────────────

    // Wrap DELETE + INSERT + ID-resolution in a transaction so that a
    // failure mid-way rolls back the DELETEs (no data loss). If the
    // batch INSERT fails, fall back to per-item rawInsertAssetItem().
    try {
      $transaction = $this->database->startTransaction('orphan_batch');

      // Step 1: Bulk DELETE existing items + their usage + orphan refs.
      // Uses url_hash to find existing IDs without needing Entity API.
      $existing_ids = $this->database->select('digital_asset_item', 'dai')
        ->fields('dai', ['id'])
        ->condition('url_hash', $all_hashes, 'IN')
        ->condition('source_type', 'filesystem_only')
        ->condition('is_temp', 1)
        ->execute()
        ->fetchCol();

      if (!empty($existing_ids)) {
        $this->database->delete('digital_asset_usage')
          ->condition('asset_id', $existing_ids, 'IN')
          ->execute();
        $this->database->delete('dai_orphan_reference')
          ->condition('asset_id', $existing_ids, 'IN')
          ->execute();
        $this->database->delete('digital_asset_item')
          ->condition('id', $existing_ids, 'IN')
          ->execute();
      }

      // Step 2: Batch INSERT all items via Drupal Insert builder.
      // The builder wraps N ->values() calls in a single transaction with
      // a shared prepared statement. Per-row cost: ~0.02s vs ~0.15s standalone.
      $columns = [
        'uuid', 'created', 'changed', 'active_use_csv', 'used_in_csv',
        'location', 'source_type', 'url_hash', 'asset_type', 'category',
        'sort_order', 'file_path', 'file_name', 'mime_type', 'filesize',
        'filesize_formatted', 'is_temp', 'is_private',
      ];

      $now = \Drupal::time()->getRequestTime();
      $insert = $this->database->insert('digital_asset_item')->fields($columns);

      foreach ($item_fields_by_hash as $hash => $fields) {
        $insert->values([
          'uuid' => \Drupal::service('uuid')->generate(),
          'created' => $now,
          'changed' => $now,
          'active_use_csv' => '',
          'used_in_csv' => '',
          'location' => '',
          'source_type' => $fields['source_type'],
          'url_hash' => $fields['url_hash'],
          'asset_type' => $fields['asset_type'],
          'category' => $fields['category'],
          'sort_order' => $fields['sort_order'],
          'file_path' => $fields['file_path'],
          'file_name' => $fields['file_name'],
          'mime_type' => $fields['mime_type'],
          'filesize' => $fields['filesize'],
          'filesize_formatted' => $fields['filesize_formatted'],
          'is_temp' => $fields['is_temp'],
          'is_private' => $fields['is_private'],
        ]);
      }

      $insert->execute();

      // Step 3: Resolve auto-increment IDs via one SELECT.
      $id_map = $this->database->select('digital_asset_item', 'dai')
        ->fields('dai', ['url_hash', 'id'])
        ->condition('url_hash', $all_hashes, 'IN')
        ->condition('is_temp', 1)
        ->execute()
        ->fetchAllKeyed();  // url_hash => id

      // Transaction commits here when $transaction goes out of scope
      // (no explicit commit needed — Drupal handles it).
    }
    catch (\Exception $e) {
      // Transaction rolls back automatically on exception (Drupal's
      // Transaction destructor calls rollBack if not committed).
      $this->logger->error('Phase 3 batch write failed, falling back to per-item: @error', [
        '@error' => $e->getMessage(),
      ]);

      // Fall back to per-item inserts for this sub-batch.
      $id_map = [];
      foreach ($item_fields_by_hash as $hash => $fields) {
        try {
          // Delete existing item if present.
          $existing_id = $this->database->select('digital_asset_item', 'dai')
            ->fields('dai', ['id'])
            ->condition('url_hash', $hash)
            ->condition('source_type', 'filesystem_only')
            ->condition('is_temp', 1)
            ->range(0, 1)
            ->execute()
            ->fetchField();

          if ($existing_id) {
            $this->rawDeleteUsageByAssetId((int) $existing_id);
            $this->rawDeleteOrphanRefsByAssetId((int) $existing_id);
            $this->database->delete('digital_asset_item')
              ->condition('id', $existing_id)
              ->execute();
          }

          $asset_id = $this->rawInsertAssetItem($fields);
          $id_map[$hash] = $asset_id;
        }
        catch (\Exception $inner) {
          $this->logger->error('Phase 3 per-item fallback failed for @hash: @error', [
            '@hash' => $hash,
            '@error' => $inner->getMessage(),
          ]);
        }
      }
    }

    if (count($id_map) !== count($item_fields_by_hash)) {
      $this->logger->warning('Phase 3 batch: ID resolution mismatch — expected @expected, got @got', [
        '@expected' => count($item_fields_by_hash),
        '@got' => count($id_map),
      ]);
    }

    // Step 4: Remap and buffer usage records with resolved asset_ids.
    foreach ($usage_by_hash as $hash => $records) {
      $asset_id = (int) ($id_map[$hash] ?? 0);
      if (!$asset_id) {
        continue;
      }
      foreach ($records as $record) {
        $this->bufferUsageRecord([
          'asset_id' => $asset_id,
          'entity_type' => $record['entity_type'],
          'entity_id' => $record['entity_id'],
          'field_name' => $record['field_name'],
          'embed_method' => $record['embed_method'],
        ]);
      }
    }

    // Step 5: Remap and create orphan ref records with resolved asset_ids.
    // Uses createOrphanReference() which handles bundle lookup + buffering.
    foreach ($orphan_refs_by_hash as $hash => $records) {
      $asset_id = (int) ($id_map[$hash] ?? 0);
      if (!$asset_id) {
        continue;
      }
      foreach ($records as $record) {
        $this->createOrphanReference(
          $asset_id,
          $record['source_entity_type'],
          $record['source_entity_id'],
          $record['field_name'],
          $record['embed_method'],
          $record['reference_context'],
        );
      }
    }

    // Step 6: Flush all buffered writes.
    // Usage and orphan-ref buffers may also contain records from
    // createOrphanReference() bundle lookups. Flush everything now.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();
  }

  /**
   * Processes a single orphan file.
   *
   * @param array $file_info
   *   Array with 'path', 'uri', and 'relative' keys.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */
  /**
   * Processes a single orphan file.
   *
   * @param array $file_info
   *   Orphan file info with 'path', 'uri', 'relative', 'url_hash' keys.
   * @param bool $is_temp
   *   Whether to create temp items.
   * @param array $existing_temp_map
   *   Pre-queried map of url_hash => loaded entity for existing temp items.
   *   The map covers all upcoming orphans for this callback, so absence
   *   means the item genuinely doesn't exist — no fallback query needed.
   */
  protected function processOrphanFile(array $file_info, bool $is_temp, array $existing_temp_map = [], array $pre_fetched_usage = []): void {
    $file_path = $file_info['path'];
    $uri = $file_info['uri'];
    $filename = basename($file_path);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $filesize = file_exists($file_path) ? filesize($file_path) : 0;
    $mime = $this->extensionToMime($extension);
    $asset_type = $this->mapMimeToAssetType($mime);
    $category = $this->mapAssetTypeToCategory($asset_type);
    $sort_order = $this->getCategorySortOrder($category);
    $url_hash = $file_info['url_hash'];

    try {
      $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
    }
    catch (\Exception $e) {
      $absolute_url = $uri;
    }

    $is_private = strpos($uri, 'private://') === 0;

    // --- Raw SQL item insert/update (bypasses Entity API) ---
    if (isset($existing_temp_map[$url_hash])) {
      $asset_id = (int) $existing_temp_map[$url_hash]->id();
      $this->rawUpdateAssetItem($asset_id, [
        'filesize' => $filesize,
        'filesize_formatted' => $this->formatFileSize($filesize),
        'file_path' => $absolute_url,
        'is_private' => $is_private ? 1 : 0,
      ]);
    }
    else {
      $asset_id = $this->rawInsertAssetItem([
        'source_type' => 'filesystem_only',
        'url_hash' => $url_hash,
        'asset_type' => $asset_type,
        'category' => $category,
        'sort_order' => $sort_order,
        'file_path' => $absolute_url,
        'file_name' => $filename,
        'mime_type' => $mime,
        'filesize' => $filesize,
        'is_temp' => $is_temp ? 1 : 0,
        'is_private' => $is_private ? 1 : 0,
      ]);
    }

    // Clear existing usage and orphan records — raw SQL DELETEs.
    $this->rawDeleteUsageByAssetId($asset_id);
    $this->rawDeleteOrphanRefsByAssetId($asset_id);

    // Use pre-fetched batch usage data if available; fall back to per-item query.
    $file_link_usage = !empty($pre_fetched_usage) ? $pre_fetched_usage : $this->findLocalFileLinkUsage($uri);
    $seen_usage = [];

    foreach ($file_link_usage as $ref) {
      $parent_entity_type = $ref['entity_type'];
      $parent_entity_id = $ref['entity_id'];

      if ($parent_entity_type === 'paragraph') {
        if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
        }
        $parent_info = $this->paragraphParentCache[$parent_entity_id];
        if ($parent_info && empty($parent_info['orphan'])) {
          $parent_entity_type = $parent_info['type'];
          $parent_entity_id = $parent_info['id'];
        }
        elseif ($parent_info && !empty($parent_info['orphan'])) {
          $this->createOrphanReference($asset_id, 'paragraph', $parent_entity_id, $ref['field_name'], 'text_link', $parent_info['context']);
          continue;
        }
        else {
          continue;
        }
      }

      $usage_key = $parent_entity_type . ':' . $parent_entity_id . ':' . $ref['field_name'];
      if (!isset($seen_usage[$usage_key])) {
        $seen_usage[$usage_key] = TRUE;
        $this->bufferUsageRecord([
          'asset_id' => $asset_id,
          'entity_type' => $parent_entity_type,
          'entity_id' => $parent_entity_id,
          'field_name' => $ref['field_name'],
          'embed_method' => 'text_link',
        ]);
      }
    }

    // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).
  }

  /**
   * Scans orphan files using time-budgeted index-based processing.
   *
   * @param array &$context
   *   Batch API context array.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */

  /**
   * Builds the orphan file usage index by scanning text-field tables.
   *
   * Batch API operation (Phase 2). Each callback scans a batch of
   * text-field tables for rows containing '/files/', matches results
   * against known orphan paths, and accumulates a usage map.
   *
   * The completed map is stored via State API for Phase 3 (orphan processing).
   * This replaces findLocalFileLinkUsageBatch() which ran O(tables × batches)
   * queries; this runs O(tables) — one LIKE per table, regardless of orphan count.
   *
   * @param array &$context
   *   Batch API context array.
   */
  public function buildOrphanUsageIndex(array &$context): void {
    $budget = $this->getBatchTimeBudget();
    $startTime = microtime(true);

    // Initialize on first callback.
    if (!isset($context['sandbox']['table_index'])) {
      // Build orphan file list and needle map.
      $orphan_files = $this->buildOrphanFileList();
      $needle_to_hashes = [];
      foreach ($orphan_files as $file) {
        $needles = $this->buildFileSearchNeedles($file['uri']);
        foreach ($needles as $needle) {
          $needle_to_hashes[$needle][] = $file['url_hash'];
        }
      }

      $context['sandbox']['table_index'] = 0;
      $context['sandbox']['total_tables'] = count($this->getTextFieldTables());
      $context['sandbox']['needle_to_hashes'] = $needle_to_hashes;
      $context['sandbox']['usage_map'] = [];

      // Store orphan file list in State for Phase 3 (orphan processing).
      $this->state->set('dai.scan.orphan_files', $orphan_files);

      if (count($orphan_files) > 5000) {
        $this->logger->warning('Large orphan file count: @count. Consider cleaning up unused files.', [
          '@count' => count($orphan_files),
        ]);
      }
    }

    $tables = $this->getTextFieldTables();
    $total_tables = $context['sandbox']['total_tables'];
    $table_index = $context['sandbox']['table_index'];
    $needle_to_hashes = $context['sandbox']['needle_to_hashes'];
    $all_needles = array_keys($needle_to_hashes);
    $tablesThisCallback = 0;

    // Scan tables within time budget — Batch API handles the rest.
    while ($table_index < $total_tables && (microtime(true) - $startTime) < $budget) {
      $t = $tables[$table_index];
      try {
        $rows = $this->database->select($t['table'], 'tbl')
          ->fields('tbl', ['entity_id', $t['value_column']])
          ->condition($t['value_column'], '%/files/%', 'LIKE')
          ->execute()
          ->fetchAll();

        if (!empty($rows)) {
          $value_col = $t['value_column'];
          foreach ($rows as $row) {
            $text = $row->$value_col;
            foreach ($all_needles as $needle) {
              if (stripos($text, $needle) !== FALSE) {
                foreach ($needle_to_hashes[$needle] as $hash) {
                  $key = $t['entity_type'] . ':' . $row->entity_id . ':' . $t['field_name'];
                  $context['sandbox']['usage_map'][$hash][$key] = [
                    'entity_type' => $t['entity_type'],
                    'entity_id' => (int) $row->entity_id,
                    'field_name' => $t['field_name'],
                    'method' => 'file_link',
                  ];
                }
              }
            }
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Failed to scan table @table for orphan usage: @error', [
          '@table' => $t['table'],
          '@error' => $e->getMessage(),
        ]);
      }

      $table_index++;
      $tablesThisCallback++;
      $this->maybeUpdateHeartbeat();
    }

    $context['sandbox']['table_index'] = $table_index;

    // Progress: fraction of tables scanned.
    $context['finished'] = $total_tables > 0 ? $table_index / $total_tables : 1;

    if ($context['finished'] >= 1) {
      // Flatten dedup keys to simple arrays, store in State for Phase 3.
      $usage_map = $context['sandbox']['usage_map'];
      foreach ($usage_map as $hash => &$refs) {
        $refs = array_values($refs);
      }
      unset($refs);

      $this->state->set('dai.scan.orphan_usage_map', $usage_map);
      $context['finished'] = 1;
    }

    $context['results']['last_chunk_items'] = $tablesThisCallback;
  }

  public function scanOrphanFilesChunkNew(array &$context, bool $is_temp): void {
    $budget = $this->getBatchTimeBudget();
    $startTime = microtime(true);
    $itemsThisCallback = 0;

    // Read pre-built data from State API (built by Phase 2 buildOrphanUsageIndex).
    if (!isset($context['sandbox']['orphan_files'])) {
      $context['sandbox']['orphan_files'] = $this->state->get('dai.scan.orphan_files', []);
      $context['sandbox']['orphan_usage_map'] = $this->state->get('dai.scan.orphan_usage_map', []);
      $context['sandbox']['orphan_index'] = 0;
      $context['sandbox']['orphan_total'] = count($context['sandbox']['orphan_files']);
    }

    $orphanFiles = $context['sandbox']['orphan_files'];
    $index = $context['sandbox']['orphan_index'];
    $total = $context['sandbox']['orphan_total'];
    $orphan_usage_map = $context['sandbox']['orphan_usage_map'];

    // Exhaustion guard.
    if ($index >= $total || empty($orphanFiles)) {
      $context['finished'] = 1;
      $context['results']['last_chunk_items'] = 0;
      return;
    }

    // Process in sub-batches using two-pass bulk writes.
    // Each sub-batch collects metadata in Pass 1, then bulk-writes in Pass 2.
    // Sub-batch sizing accounts for ~0.03s/item (Pass 1) + ~1s fixed flush.
    while ($index < $total) {
      $elapsed = microtime(true) - $startTime;
      if ($elapsed >= $budget) {
        break;
      }

      // Size the sub-batch to fit within remaining budget.
      // Reserve 2s for the Pass 2 flush operations.
      $remaining_budget = $budget - $elapsed;
      $sub_batch_size = min(
        max((int) (($remaining_budget - 2.0) / 0.03), 1),
        100,
        $total - $index,
      );

      // If less than 1s remains, don't start another sub-batch.
      if ($remaining_budget < 1.0 && $itemsThisCallback > 0) {
        break;
      }

      $sub_batch = array_slice($orphanFiles, $index, $sub_batch_size);
      $this->processOrphanFileBatch($sub_batch, $is_temp, $orphan_usage_map);
      $this->maybeUpdateHeartbeat();

      $index += count($sub_batch);
      $itemsThisCallback += count($sub_batch);
    }

    $context['sandbox']['orphan_index'] = $index;

    // Progress.
    if ($total > 0) {
      $context['finished'] = $index / $total;
    }
    if ($index >= $total) {
      $context['finished'] = 1;
    }

    // FR-6: Cache resets.
    $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'file']);
    if ($itemsThisCallback >= 50) {
      drupal_static_reset();
    }

    $context['results']['last_chunk_items'] = $itemsThisCallback;
  }

  /**
   * Scans a chunk of orphan files (legacy offset/limit signature).
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of files to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Number of items processed.
   *
   * @deprecated Use scanOrphanFilesChunkNew(array &$context, bool $is_temp) instead.
   */
  public function scanOrphanFilesChunk($offset, $limit, $is_temp = FALSE) {
    $count = 0;
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');
    $known_extensions = $this->getKnownExtensions();

    // Scan both public and private directories.
    $streams = ['public://', 'private://'];
    $orphan_files = [];

    // Single query: load ALL managed file URIs into a hash set.
    $managed_uris = $this->database->select('file_managed', 'f')
      ->fields('f', ['uri'])
      ->execute()
      ->fetchCol();
    $managed_set = array_flip($managed_uris);

    foreach ($streams as $stream) {
      $base_path = $this->fileSystem->realpath($stream);

      if (!$base_path || !is_dir($base_path)) {
        continue;
      }

      // Determine if this is a private scan.
      $is_private_scan = ($stream === 'private://');

      // Get all files with known extensions.
      $all_files = $this->scanDirectoryRecursive($base_path, $known_extensions, $is_private_scan);

      // Filter to only orphan files.
      foreach ($all_files as $file_path) {
        // Construct URI - ensure no double slashes.
        $relative_path = str_replace($base_path, '', $file_path);
        $relative_path = ltrim($relative_path, '/');
        $uri = $stream . $relative_path;

        // In-memory check instead of DB query.
        if (!isset($managed_set[$uri])) {
          $orphan_files[] = [
            'path' => $file_path,
            'uri' => $uri,
            'relative' => $relative_path,
          ];
        }
      }
    }

    // Process chunk.
    $chunk = array_slice($orphan_files, $offset, $limit);

    foreach ($chunk as $file_info) {
      $file_path = $file_info['path'];
      $uri = $file_info['uri'];
      $filename = basename($file_path);
      $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

      // Get file size.
      $filesize = file_exists($file_path) ? filesize($file_path) : 0;

      // Map extension to MIME type.
      $mime = $this->extensionToMime($extension);

      // Map MIME to asset type.
      $asset_type = $this->mapMimeToAssetType($mime);

      // Determine category and sort order.
      $category = $this->mapAssetTypeToCategory($asset_type);
      $sort_order = $this->getCategorySortOrder($category);

      // Create hash for uniqueness (based on URI).
      $uri_hash = md5($uri);

      // Convert URI to absolute URL for storage.
      try {
        $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($uri);
      }
      catch (\Exception $e) {
        // Fallback to URI if conversion fails.
        $absolute_url = $uri;
      }

      // Check if file is in the private file system.
      $is_private = strpos($uri, 'private://') === 0;

      // Check if TEMP asset already exists.
      // Only update temp items - never modify permanent items during scan.
      $existing_query = $storage->getQuery();
      $existing_query->condition('url_hash', $uri_hash);
      $existing_query->condition('source_type', 'filesystem_only');
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        // Update existing temp item.
        $asset_id = reset($existing_ids);
        $asset = $storage->load($asset_id);
        $asset->set('filesize', $filesize);
        $asset->set('file_path', $absolute_url);
        $asset->set('is_private', $is_private);
        $asset->save();
      }
      else {
        // Create new orphan asset.
        $asset = $storage->create([
          'source_type' => 'filesystem_only',
          'url_hash' => $uri_hash,
          'asset_type' => $asset_type,
          'category' => $category,
          'sort_order' => $sort_order,
          'file_path' => $absolute_url,
          'file_name' => $filename,
          'mime_type' => $mime,
          'filesize' => $filesize,
          'is_temp' => $is_temp,
          'is_private' => $is_private,
        ]);
        $asset->save();
        $asset_id = $asset->id();
      }

      // Clear existing usage records for this asset before re-scanning.
      $old_usage_query = $usage_storage->getQuery();
      $old_usage_query->condition('asset_id', $asset_id);
      $old_usage_query->accessCheck(FALSE);
      $old_usage_ids = $old_usage_query->execute();

      if ($old_usage_ids) {
        $old_usages = $usage_storage->loadMultiple($old_usage_ids);
        $usage_storage->delete($old_usages);
      }

      // Scan text fields for links to this orphan file (CKEditor links).
      $file_link_usage = $this->findLocalFileLinkUsage($uri);

      foreach ($file_link_usage as $ref) {
        // Trace paragraphs to their parent nodes.
        $parent_entity_type = $ref['entity_type'];
        $parent_entity_id = $ref['entity_id'];

        if ($parent_entity_type === 'paragraph') {
          if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
        }
        $parent_info = $this->paragraphParentCache[$parent_entity_id];
          if ($parent_info && empty($parent_info['orphan'])) {
            $parent_entity_type = $parent_info['type'];
            $parent_entity_id = $parent_info['id'];
          }
          elseif ($parent_info && !empty($parent_info['orphan'])) {
            // Orphan detected — create orphan reference record.
            $this->createOrphanReference($asset_id, 'paragraph', $parent_entity_id, $ref['field_name'], 'text_link', $parent_info['context']);
            continue;
          }
          else {
            // Paragraph not found (NULL) — skip.
            continue;
          }
        }

        // Check if usage record already exists for this entity.
        $existing_usage_query = $usage_storage->getQuery();
        $existing_usage_query->condition('asset_id', $asset_id);
        $existing_usage_query->condition('entity_type', $parent_entity_type);
        $existing_usage_query->condition('entity_id', $parent_entity_id);
        $existing_usage_query->accessCheck(FALSE);
        $existing_usage_ids = $existing_usage_query->execute();

        if (!$existing_usage_ids) {
          // Create usage record showing where file is linked.
          // These are text links found via findLocalFileLinkUsage().
          $usage_storage->create([
            'asset_id' => $asset_id,
            'entity_type' => $parent_entity_type,
            'entity_id' => $parent_entity_id,
            'field_name' => $ref['field_name'],
            'count' => 1,
            'embed_method' => 'text_link',
          ])->save();
        }
      }

      // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

      $count++;
    }

    // Flush buffered records before cache reset.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();

    // Reset entity caches to prevent memory exhaustion in long batch runs.
    $this->resetEntityCaches([
      'digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference',
      'file',
    ]);

    return $count;
  }

  /**
   * Gets count of media entities (not used anymore for file-based media).
   *
   * @return int
   *   Always returns 0 as file-based media is handled via file_managed.
   */
  public function getMediaEntitiesCount() {
    return 0;
  }

  /**
   * Scans media entities (not used anymore for file-based media).
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of entities to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Always returns 0 as file-based media is handled via file_managed.
   */
  public function scanMediaEntitiesChunk($offset, $limit, $is_temp = FALSE) {
    return 0;
  }

  /**
   * Gets count of remote media entities (oEmbed videos like YouTube, Vimeo).
   *
   * Remote media entities don't have file_managed entries - they store
   * URLs directly in their source field.
   *
   * @return int
   *   The number of remote media entities.
   */
  public function getRemoteMediaCount() {
    try {
      // Get media types that use remote video/oEmbed sources.
      $remote_media_types = $this->getRemoteMediaTypes();

      if (empty($remote_media_types)) {
        return 0;
      }

      // Count media entities of these types.
      $query = $this->entityTypeManager->getStorage('media')->getQuery();
      $query->condition('bundle', $remote_media_types, 'IN');
      $query->accessCheck(FALSE);

      return (int) $query->count()->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Error counting remote media: @error', [
        '@error' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Gets media type IDs that use remote/oEmbed sources.
   *
   * @return array
   *   Array of media type machine names.
   */
  protected function getRemoteMediaTypes() {
    $remote_types = [];

    try {
      // Load all media type configurations.
      $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();

      foreach ($media_types as $type_id => $media_type) {
        // Get the source plugin ID.
        $source_plugin = $media_type->getSource();
        $source_id = $source_plugin->getPluginId();

        // Remote video sources: oembed:video, video_file (remote), etc.
        // The standard Drupal core remote video uses 'oembed:video'.
        if (in_array($source_id, ['oembed:video', 'video_embed_field'])) {
          $remote_types[] = $type_id;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading media types: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $remote_types;
  }

  /**
   * Scans a chunk of remote media entities.
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of entities to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   *
   * @return int
   *   Number of items processed.
   */
  public function scanRemoteMediaChunk($offset, $limit, $is_temp = FALSE) {
    $count = 0;
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    try {
      // Get media types that use remote video/oEmbed sources.
      $remote_media_types = $this->getRemoteMediaTypes();

      if (empty($remote_media_types)) {
        return 0;
      }

      // Query remote media entities.
      $query = $this->entityTypeManager->getStorage('media')->getQuery();
      $query->condition('bundle', $remote_media_types, 'IN');
      $query->accessCheck(FALSE);
      $query->range($offset, $limit);
      $media_ids = $query->execute();

      if (empty($media_ids)) {
        return 0;
      }

      $media_entities = $this->entityTypeManager->getStorage('media')->loadMultiple($media_ids);

      foreach ($media_entities as $media) {
        $media_id = $media->id();
        $media_name = $media->label();

        // Get the source URL from the media entity.
        $source = $media->getSource();
        $source_field_name = $source->getSourceFieldDefinition($media->bundle->entity)->getName();

        // Get the URL value from the source field.
        $source_url = NULL;
        if ($media->hasField($source_field_name) && !$media->get($source_field_name)->isEmpty()) {
          $source_url = $media->get($source_field_name)->value;
        }

        if (empty($source_url)) {
          // Skip media without a source URL.
          continue;
        }

        // Normalize and determine asset type from URL.
        $normalized = $this->normalizeVideoUrl($source_url);
        if ($normalized) {
          // Use canonical URL for storage.
          $source_url = $normalized['url'];
          $asset_type = $normalized['platform'];
        }
        else {
          // Fallback: match URL to asset type from config.
          $asset_type = $this->matchUrlToAssetType($source_url);

          // If URL doesn't match our known patterns, try to detect type.
          if ($asset_type === 'other') {
            if (stripos($source_url, 'youtube.com') !== FALSE || stripos($source_url, 'youtu.be') !== FALSE) {
              $asset_type = 'youtube';
            }
            elseif (stripos($source_url, 'vimeo.com') !== FALSE) {
              $asset_type = 'vimeo';
            }
            else {
              $asset_type = 'youtube';
            }
          }
        }

        // Determine category and sort order.
        $category = $this->mapAssetTypeToCategory($asset_type);
        $sort_order = $this->getCategorySortOrder($category);

        // Create URL hash for uniqueness (based on media ID to avoid duplicates).
        $url_hash = md5('media:' . $media_id);

        // Check if TEMP asset already exists.
        $existing_query = $storage->getQuery();
        $existing_query->condition('url_hash', $url_hash);
        $existing_query->condition('source_type', 'media_managed');
        $existing_query->condition('is_temp', TRUE);
        $existing_query->accessCheck(FALSE);
        $existing_ids = $existing_query->execute();

        if ($existing_ids) {
          // Update existing temp item.
          $asset_id = reset($existing_ids);
          $asset = $storage->load($asset_id);
          $asset->set('file_path', $source_url);
          $asset->set('file_name', $media_name);
          $asset->save();
        }
        else {
          // Create new remote media asset.
          $config = $this->configFactory->get('digital_asset_inventory.settings');
          $asset_types_config = $config->get('asset_types');
          $label = $asset_types_config[$asset_type]['label'] ?? ucfirst($asset_type);

          $asset = $storage->create([
            'source_type' => 'media_managed',
            'media_id' => $media_id,
            'url_hash' => $url_hash,
            'asset_type' => $asset_type,
            'category' => $category,
            'sort_order' => $sort_order,
            'file_path' => $source_url,
            'file_name' => $media_name,
            'mime_type' => $label,
            'filesize' => NULL,
            'is_temp' => $is_temp,
            'is_private' => FALSE,
          ]);
          $asset->save();
          $asset_id = $asset->id();
        }

        // Clear existing usage records for this asset.
        $old_usage_query = $usage_storage->getQuery();
        $old_usage_query->condition('asset_id', $asset_id);
        $old_usage_query->accessCheck(FALSE);
        $old_usage_ids = $old_usage_query->execute();

        if ($old_usage_ids) {
          $old_usages = $usage_storage->loadMultiple($old_usage_ids);
          $usage_storage->delete($old_usages);
        }

        // Find usage via entity query (entity reference fields).
        $media_references = $this->findMediaUsageViaEntityQuery($media_id);

        // Also scan text fields directly (including paragraphs) for drupal-media embeds.
        // This catches embeds in paragraph text fields that entity queries may miss.
        $media_uuid = $media->uuid();
        $text_field_references = $this->scanTextFieldsForMediaEmbed($media_uuid);

        // Merge and deduplicate references.
        $all_references = array_merge($media_references, $text_field_references);
        $media_references = [];
        $seen = [];
        foreach ($all_references as $ref) {
          $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . ($ref['field_name'] ?? '');
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $media_references[] = $ref;
          }
        }

        foreach ($media_references as $ref) {
          // Trace paragraphs to their parent nodes.
          $parent_entity_type = $ref['entity_type'];
          $parent_entity_id = $ref['entity_id'];
          $field_name = $ref['field_name'] ?? 'media';

          if ($parent_entity_type === 'paragraph') {
            if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
        }
        $parent_info = $this->paragraphParentCache[$parent_entity_id];
            if ($parent_info && empty($parent_info['orphan'])) {
              $parent_entity_type = $parent_info['type'];
              $parent_entity_id = $parent_info['id'];
            }
            elseif ($parent_info && !empty($parent_info['orphan'])) {
              // Orphan detected — create orphan reference record.
              $ref_embed = (isset($ref['method']) && $ref['method'] === 'media_embed') ? 'drupal_media' : 'field_reference';
              $this->createOrphanReference($asset_id, 'paragraph', $parent_entity_id, $field_name, $ref_embed, $parent_info['context']);
              continue;
            }
            else {
              // Paragraph not found (NULL) — skip.
              continue;
            }
          }

          // Check if usage record already exists.
          $existing_usage_query = $usage_storage->getQuery();
          $existing_usage_query->condition('asset_id', $asset_id);
          $existing_usage_query->condition('entity_type', $parent_entity_type);
          $existing_usage_query->condition('entity_id', $parent_entity_id);
          $existing_usage_query->condition('field_name', $field_name);
          $existing_usage_query->accessCheck(FALSE);
          $existing_usage_ids = $existing_usage_query->execute();

          if (!$existing_usage_ids) {
            // Map reference method to embed_method field value.
            $embed_method = 'field_reference';
            if (isset($ref['method']) && $ref['method'] === 'media_embed') {
              $embed_method = 'drupal_media';
            }

            // Create usage record.
            $usage_storage->create([
              'asset_id' => $asset_id,
              'entity_type' => $parent_entity_type,
              'entity_id' => $parent_entity_id,
              'field_name' => $field_name,
              'count' => 1,
              'embed_method' => $embed_method,
            ])->save();
          }
        }

        // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).

        $count++;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error scanning remote media: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    // Flush buffered records before cache reset.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();

    // Reset entity caches to prevent memory exhaustion in long batch runs.
    $this->resetEntityCaches([
      'digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference',
      'media',
    ]);

    return $count;
  }

  /**
   * Gets remote media IDs after a given mid, for cursor-based pagination.
   *
   * @param int $lastMid
   *   Last processed media ID (exclusive lower bound).
   * @param int $limit
   *   Maximum number of IDs to return.
   *
   * @return array
   *   Array of media IDs.
   */
  protected function getRemoteMediaIdsAfter(int $lastMid, int $limit): array {
    $remote_media_types = $this->getRemoteMediaTypes();
    if (empty($remote_media_types)) {
      return [];
    }

    return $this->entityTypeManager->getStorage('media')->getQuery()
      ->condition('bundle', $remote_media_types, 'IN')
      ->condition('mid', $lastMid, '>')
      ->sort('mid', 'ASC')
      ->range(0, $limit)
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Processes a single remote media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity, bulk-loaded by the caller.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */
  protected function processRemoteMedia($media, bool $is_temp): void {
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    try {
      $mid = $media->id();
      $media_id = $media->id();
      $media_name = $media->label();

      // Get the source URL from the media entity.
      $source = $media->getSource();
      $source_field_name = $source->getSourceFieldDefinition($media->bundle->entity)->getName();

      $source_url = NULL;
      if ($media->hasField($source_field_name) && !$media->get($source_field_name)->isEmpty()) {
        $source_url = $media->get($source_field_name)->value;
      }

      if (empty($source_url)) {
        return;
      }

      // Normalize and determine asset type from URL.
      $normalized = $this->normalizeVideoUrl($source_url);
      if ($normalized) {
        $source_url = $normalized['url'];
        $asset_type = $normalized['platform'];
      }
      else {
        $asset_type = $this->matchUrlToAssetType($source_url);
        if ($asset_type === 'other') {
          if (stripos($source_url, 'youtube.com') !== FALSE || stripos($source_url, 'youtu.be') !== FALSE) {
            $asset_type = 'youtube';
          }
          elseif (stripos($source_url, 'vimeo.com') !== FALSE) {
            $asset_type = 'vimeo';
          }
          else {
            $asset_type = 'youtube';
          }
        }
      }

      $category = $this->mapAssetTypeToCategory($asset_type);
      $sort_order = $this->getCategorySortOrder($category);
      $url_hash = md5('media:' . $media_id);

      // Check if TEMP asset already exists.
      $existing_query = $storage->getQuery();
      $existing_query->condition('url_hash', $url_hash);
      $existing_query->condition('source_type', 'media_managed');
      $existing_query->condition('is_temp', TRUE);
      $existing_query->accessCheck(FALSE);
      $existing_ids = $existing_query->execute();

      if ($existing_ids) {
        $asset_id = reset($existing_ids);
        $asset = $storage->load($asset_id);
        $asset->set('file_path', $source_url);
        $asset->set('file_name', $media_name);
        $asset->save();
      }
      else {
        $config = $this->configFactory->get('digital_asset_inventory.settings');
        $asset_types_config = $config->get('asset_types');
        $label = $asset_types_config[$asset_type]['label'] ?? ucfirst($asset_type);

        $asset = $storage->create([
          'source_type' => 'media_managed',
          'media_id' => $media_id,
          'url_hash' => $url_hash,
          'asset_type' => $asset_type,
          'category' => $category,
          'sort_order' => $sort_order,
          'file_path' => $source_url,
          'file_name' => $media_name,
          'mime_type' => $label,
          'filesize' => NULL,
          'is_temp' => $is_temp,
          'is_private' => FALSE,
        ]);
        $asset->save();
        $asset_id = $asset->id();
      }

      // Clear existing usage records for this asset.
      $old_usage_query = $usage_storage->getQuery();
      $old_usage_query->condition('asset_id', $asset_id);
      $old_usage_query->accessCheck(FALSE);
      $old_usage_ids = $old_usage_query->execute();

      if ($old_usage_ids) {
        $old_usages = $usage_storage->loadMultiple($old_usage_ids);
        $usage_storage->delete($old_usages);
      }

      // Find usage via entity query (entity reference fields).
      $media_references = $this->findMediaUsageViaEntityQuery($media_id);

      // Also scan text fields for drupal-media embeds.
      $media_uuid = $media->uuid();
      $text_field_references = $this->scanTextFieldsForMediaEmbed($media_uuid);

      // Merge and deduplicate references.
      $all_references = array_merge($media_references, $text_field_references);
      $media_references = [];
      $seen = [];
      foreach ($all_references as $ref) {
        $key = $ref['entity_type'] . ':' . $ref['entity_id'] . ':' . ($ref['field_name'] ?? '');
        if (!isset($seen[$key])) {
          $seen[$key] = TRUE;
          $media_references[] = $ref;
        }
      }

      foreach ($media_references as $ref) {
        $parent_entity_type = $ref['entity_type'];
        $parent_entity_id = $ref['entity_id'];
        $field_name = $ref['field_name'] ?? 'media';

        if ($parent_entity_type === 'paragraph') {
          if (!array_key_exists($parent_entity_id, $this->paragraphParentCache)) {
          $this->paragraphParentCache[$parent_entity_id] = $this->getParentFromParagraph($parent_entity_id);
        }
        $parent_info = $this->paragraphParentCache[$parent_entity_id];
          if ($parent_info && empty($parent_info['orphan'])) {
            $parent_entity_type = $parent_info['type'];
            $parent_entity_id = $parent_info['id'];
          }
          elseif ($parent_info && !empty($parent_info['orphan'])) {
            $ref_embed = (isset($ref['method']) && $ref['method'] === 'media_embed') ? 'drupal_media' : 'field_reference';
            $this->createOrphanReference($asset_id, 'paragraph', $parent_entity_id, $field_name, $ref_embed, $parent_info['context']);
            continue;
          }
          else {
            continue;
          }
        }

        // Check if usage record already exists.
        $existing_usage_query = $usage_storage->getQuery();
        $existing_usage_query->condition('asset_id', $asset_id);
        $existing_usage_query->condition('entity_type', $parent_entity_type);
        $existing_usage_query->condition('entity_id', $parent_entity_id);
        $existing_usage_query->condition('field_name', $field_name);
        $existing_usage_query->accessCheck(FALSE);
        $existing_usage_ids = $existing_usage_query->execute();

        if (!$existing_usage_ids) {
          $embed_method = 'field_reference';
          if (isset($ref['method']) && $ref['method'] === 'media_embed') {
            $embed_method = 'drupal_media';
          }

          $usage_storage->create([
            'asset_id' => $asset_id,
            'entity_type' => $parent_entity_type,
            'entity_id' => $parent_entity_id,
            'field_name' => $field_name,
            'count' => 1,
            'embed_method' => $embed_method,
          ])->save();
        }
      }

      // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing remote media @mid: @error', [
        '@mid' => $mid,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Scans remote media entities with time-budgeted processing.
   *
   * @param array &$context
   *   Batch API context array.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */
  public function scanRemoteMediaChunkNew(array &$context, bool $is_temp): void {
    $itemsThisCallback = $this->processWithTimeBudget(
      $context,
      'last_mid',
      'total_media',
      fn() => $this->getRemoteMediaCount(),
      fn(int $lastMid, int $limit) => $this->getRemoteMediaIdsAfter($lastMid, $limit),
      fn(array $ids) => $this->entityTypeManager->getStorage('media')->loadMultiple($ids),
      fn($media) => $this->processRemoteMedia($media, $is_temp),
    );

    // FR-6: Cache resets.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();
    $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'dai_orphan_reference', 'media']);
    if ($itemsThisCallback >= 50) {
      drupal_static_reset();
    }

    $context['results']['last_chunk_items'] = $itemsThisCallback;
  }

  /**
   * Promotes temporary items to permanent (atomic swap).
   */
  public function promoteTemporaryItems() {
    // Delete old items and their dependent records — raw SQL in FK-safe order.
    // Uses subqueries for portability across MySQL/MariaDB/PostgreSQL/SQLite.
    $old_ids_subquery = $this->database->select('digital_asset_item', 'dai')
      ->fields('dai', ['id'])
      ->condition('is_temp', 0);

    // Step 1: Delete orphan references for old items.
    $this->database->delete('dai_orphan_reference')
      ->condition('asset_id', $old_ids_subquery, 'IN')
      ->execute();

    // Step 2: Delete usage records for old items.
    $this->database->delete('digital_asset_usage')
      ->condition('asset_id', $old_ids_subquery, 'IN')
      ->execute();

    // Step 3: Delete old non-temporary items.
    $this->database->delete('digital_asset_item')
      ->condition('is_temp', 0)
      ->execute();

    // Step 4: Mark all temporary items as permanent — single raw SQL UPDATE.
    $this->database->update('digital_asset_item')
      ->fields(['is_temp' => 0, 'changed' => \Drupal::time()->getRequestTime()])
      ->condition('is_temp', 1)
      ->execute();

    // After promoting items, validate archived files to update warning flags.
    // This ensures that if files were deleted during scanning, archive records
    // are updated with appropriate warnings (File Missing, etc.).
    try {
      $archive_service = $this->container->get('digital_asset_inventory.archive');
      $archive_service->validateArchivedFiles();
    }
    catch (\Exception $e) {
      $this->logger->error('Error validating archived files after scan: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    // Clean up State API data from Phase 2 (orphan usage index).
    $this->state->delete('dai.scan.orphan_files');
    $this->state->delete('dai.scan.orphan_usage_map');
  }

  /**
   * Clears temporary items (on cancel or error).
   *
   * Per Entity Integrity rules: Always delete usage records BEFORE items
   * to maintain foreign key integrity and prevent orphaned data.
   */
  public function clearTemporaryItems() {
    // Deletion order: orphan references → usage records → asset items.
    // Uses raw SQL with subqueries for performance (same pattern as promoteTemporaryItems).
    $temp_ids_subquery = $this->database->select('digital_asset_item', 'dai')
      ->fields('dai', ['id'])
      ->condition('is_temp', 1);

    // Step 1: Delete orphan references for temp items.
    $this->database->delete('dai_orphan_reference')
      ->condition('asset_id', $temp_ids_subquery, 'IN')
      ->execute();

    // Step 2: Delete usage records for temp items.
    $this->database->delete('digital_asset_usage')
      ->condition('asset_id', $temp_ids_subquery, 'IN')
      ->execute();

    // Step 3: Delete the temp items.
    $this->database->delete('digital_asset_item')
      ->condition('is_temp', 1)
      ->execute();
  }

  /**
   * Regenerates entity_usage tracking for a specific media entity.
   *
   * This forces entity_usage to recalculate all references to this media,
   * ensuring we have fresh data instead of stale cache.
   *
   * @param int $media_id
   *   The media entity ID.
   */
  protected function regenerateMediaEntityUsage($media_id) {
    try {
      // Check if entity_usage service exists.
      if (!$this->container->has('entity_usage.usage')) {
        return;
      }

      $entity_usage = $this->container->get('entity_usage.usage');

      // Load the media entity.
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);

      if (!$media) {
        return;
      }

      // First, delete all existing entity_usage records for this media target.
      // This ensures we start fresh and don't have stale data.
      $this->database->delete('entity_usage')
        ->condition('target_id', $media_id)
        ->condition('target_type', 'media')
        ->execute();

      // Now let entity_usage track this media's usage fresh.
      // We need to scan all entities that might reference this media.
      // The entity_usage module provides methods to register sources.
      // Get media UUID for searching in text fields.
      $media_uuid = $media->uuid();

      // Scan for entity reference fields pointing to this media.
      $entity_ref_results = $this->scanEntityReferenceFields($media_id);
      foreach ($entity_ref_results as $ref) {
        // Register this source in entity_usage.
        $entity_usage->registerUsage(
          $media_id,
          'media',
          $ref['entity_id'],
          $ref['entity_type'],
          'en',
        // revision_id (use 0 for default)
          0,
          $ref['method'],
          $ref['field_name'],
        // Count.
          1
        );
      }

      // Scan for embedded media in text fields.
      if ($media_uuid) {
        $embed_results = $this->scanTextFieldsForMediaEmbed($media_uuid);
        foreach ($embed_results as $ref) {
          // Register this source in entity_usage.
          $entity_usage->registerUsage(
            $media_id,
            'media',
            $ref['entity_id'],
            $ref['entity_type'],
            'en',
          // revision_id (use 0 for default)
            0,
            $ref['method'],
            $ref['field_name'],
          // Count.
            1
          );
        }
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Error regenerating entity_usage for media @id: @error', [
        '@id' => $media_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Clears all usage records (called at start of new scan).
   */
  public function clearUsageRecords() {
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    // Delete all usage records.
    $query = $usage_storage->getQuery();
    $query->accessCheck(FALSE);
    $ids = $query->execute();

    if ($ids) {
      $entities = $usage_storage->loadMultiple($ids);
      $usage_storage->delete($entities);
    }
  }

  /**
   * Updates CSV export fields for a digital asset.
   *
   * @param int $asset_id
   *   The digital asset item ID.
   * @param int $filesize
   *   The file size in bytes.
   */
  /**
   * @internal Only used by legacy deprecated scan methods and non-scan
   *   code paths. The primary scan pipeline uses updateCsvExportFieldsBulk()
   *   (Phase 6) instead.
   */
  protected function updateCsvExportFields($asset_id, $filesize) {
    $storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');

    $asset = $storage->load($asset_id);
    if (!$asset) {
      $this->logger->error('updateCsvExportFields: Asset @id not found', ['@id' => $asset_id]);
      return;
    }

    // Format file size as human-readable (e.g., "2.5 MB", "156 KB").
    $filesize_formatted = $this->formatFileSize($filesize);

    // Check if field exists before setting.
    if (!$asset->hasField('filesize_formatted')) {
      $this->logger->error('Field filesize_formatted does not exist on entity!');
      return;
    }

    $asset->set('filesize_formatted', $filesize_formatted);

    // Build "used in" CSV field - list of "Page Name (URL)" entries.
    $used_in_parts = [];

    // Query usage records for this asset.
    $usage_query = $usage_storage->getQuery();
    $usage_query->condition('asset_id', $asset_id);
    $usage_query->accessCheck(FALSE);
    $usage_ids = $usage_query->execute();

    // Set Active Use Detected for CSV export.
    $asset->set('active_use_csv', !empty($usage_ids) ? 'Yes' : 'No');

    if ($usage_ids) {
      $usages = $usage_storage->loadMultiple($usage_ids);

      foreach ($usages as $usage) {
        $entity_type = $usage->get('entity_type')->value;
        $entity_id = $usage->get('entity_id')->value;

        // Load the referenced entity to get its title and URL.
        try {
          $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);

          if ($entity) {
            $label = $entity->label();

            // Get absolute URL if entity has a canonical link.
            if ($entity->hasLinkTemplate('canonical')) {
              $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
              $used_in_parts[] = $label . ' (' . $url . ')';
            }
            else {
              // No canonical URL available.
              $used_in_parts[] = $label;
            }
          }
        }
        catch (\Exception $e) {
          // Skip entities that can't be loaded.
        }
      }
    }

    // Build final string - semicolon-separated or "No active use detected".
    if (!empty($used_in_parts)) {
      // Remove duplicates (same page might be referenced multiple times).
      $used_in_parts = array_unique($used_in_parts);
      $used_in_csv = implode('; ', $used_in_parts);
    }
    else {
      $used_in_csv = 'No active use detected';
    }

    $asset->set('used_in_csv', $used_in_csv);
    $asset->save();
  }

  /**
   * Bulk-updates CSV export fields for all temp asset items.
   *
   * Processes items in cursor-based batches within the time budget.
   * Called as Phase 6 after all scanning phases complete.
   *
   * For each batch of items:
   * 1. Bulk-loads usage records for the batch
   * 2. Determines active_use_csv (Yes/No) from usage existence
   * 3. Loads parent entities to build used_in_csv ("Title (URL); ...")
   * 4. Updates items via raw SQL
   *
   * @param array &$context
   *   Batch API context array.
   */
  public function updateCsvExportFieldsBulk(array &$context): void {
    $budget = $this->getBatchTimeBudget();
    $startTime = microtime(true);
    $itemsThisCallback = 0;

    // Initialize on first call.
    if (!isset($context['sandbox']['csv_last_id'])) {
      $context['sandbox']['csv_last_id'] = 0;
      $context['sandbox']['csv_total'] = (int) $this->database
        ->select('digital_asset_item', 'dai')
        ->condition('is_temp', 1)
        ->countQuery()
        ->execute()
        ->fetchField();
      $context['sandbox']['csv_processed'] = 0;
    }

    $lastId = $context['sandbox']['csv_last_id'];
    $total = $context['sandbox']['csv_total'];

    while ((microtime(true) - $startTime) < $budget) {
      // Fetch a batch of item IDs + filesize.
      $items = $this->database->select('digital_asset_item', 'dai')
        ->fields('dai', ['id', 'filesize'])
        ->condition('id', $lastId, '>')
        ->condition('is_temp', 1)
        ->orderBy('id', 'ASC')
        ->range(0, 50)
        ->execute()
        ->fetchAllAssoc('id');

      if (empty($items)) {
        $context['finished'] = 1;
        break;
      }

      $item_ids = array_keys($items);

      // Bulk-load ALL usage records for this batch of items.
      $usage_rows = $this->database->select('digital_asset_usage', 'dau')
        ->fields('dau', ['asset_id', 'entity_type', 'entity_id'])
        ->condition('asset_id', $item_ids, 'IN')
        ->execute()
        ->fetchAll();

      // Group usage by asset_id.
      $usage_by_asset = [];
      foreach ($usage_rows as $row) {
        $usage_by_asset[$row->asset_id][] = $row;
      }

      // Collect unique entity references for bulk loading.
      $entity_refs = [];
      foreach ($usage_by_asset as $usages) {
        foreach ($usages as $usage) {
          $key = $usage->entity_type . ':' . $usage->entity_id;
          if (!isset($entity_refs[$key])) {
            $entity_refs[$key] = [
              'type' => $usage->entity_type,
              'id' => $usage->entity_id,
            ];
          }
        }
      }

      // Bulk-load parent entities grouped by type.
      $entity_labels = [];
      $by_type = [];
      foreach ($entity_refs as $key => $ref) {
        $by_type[$ref['type']][] = $ref['id'];
      }
      foreach ($by_type as $entity_type => $ids) {
        try {
          if (!$this->entityTypeManager->hasDefinition($entity_type)) {
            continue;
          }
          $entities = $this->entityTypeManager->getStorage($entity_type)
            ->loadMultiple($ids);
          foreach ($entities as $entity) {
            $label = $entity->label();
            $url = '';
            try {
              if ($entity->hasLinkTemplate('canonical')) {
                $url = $entity->toUrl('canonical', ['absolute' => TRUE])
                  ->toString();
              }
            }
            catch (\Exception $e) {
              // No canonical URL.
            }
            $entity_labels[$entity_type . ':' . $entity->id()] = [
              'label' => $label,
              'url' => $url,
            ];
          }
        }
        catch (\Exception $e) {
          // Skip entity types that fail to load.
        }
      }

      // Build CSV fields and update each item.
      foreach ($items as $item_id => $item) {
        $usages = $usage_by_asset[$item_id] ?? [];
        $active_use = !empty($usages) ? 'Yes' : 'No';

        $used_in_parts = [];
        foreach ($usages as $usage) {
          $key = $usage->entity_type . ':' . $usage->entity_id;
          if (isset($entity_labels[$key])) {
            $info = $entity_labels[$key];
            $used_in_parts[] = $info['url']
              ? $info['label'] . ' (' . $info['url'] . ')'
              : $info['label'];
          }
        }
        $used_in_parts = array_unique($used_in_parts);
        $used_in_csv = !empty($used_in_parts)
          ? implode('; ', $used_in_parts)
          : 'No active use detected';

        $this->database->update('digital_asset_item')
          ->fields([
            'active_use_csv' => $active_use,
            'used_in_csv' => $used_in_csv,
          ])
          ->condition('id', $item_id)
          ->execute();

        $lastId = $item_id;
        $context['sandbox']['csv_last_id'] = $lastId;
        $context['sandbox']['csv_processed']++;
        $itemsThisCallback++;
      }

      $this->maybeUpdateHeartbeat();
    }

    // Progress calculation.
    if ($total > 0) {
      $context['finished'] = $context['sandbox']['csv_processed'] / $total;
    }
    if ($context['finished'] >= 1) {
      $context['finished'] = 1;
    }

    // Cache resets.
    $this->resetPhaseEntityCaches(['node', 'paragraph', 'block_content',
      'taxonomy_term', 'media', 'menu_link_content']);

    $context['results']['last_chunk_items'] = $itemsThisCallback;
  }

  /**
   * Formats file size in human-readable format.
   *
   * @param int $bytes
   *   The file size in bytes.
   *
   * @return string
   *   Human-readable size (e.g., "2.5 MB", "156 KB").
   */
  protected function formatFileSize($bytes) {
    if ($bytes == 0) {
      return '-';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    // Format with appropriate decimal places.
    if ($pow == 0) {
      // Bytes - no decimals.
      return round($bytes) . ' ' . $units[$pow];
    }
    else {
      // KB, MB, etc. - 2 decimal places.
      return number_format($bytes, 2) . ' ' . $units[$pow];
    }
  }

  /**
   * Excludes system-generated files from the managed files query.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query object to modify.
   */
  protected function excludeSystemGeneratedFiles($query) {
    // System directories to exclude from managed file scanning.
    $excluded_paths = [
      'public://styles/%',
      'public://thumbnails/%',
      'public://media-icons/%',
      'public://oembed_thumbnails/%',
      'public://video_thumbnails/%',
      'public://css/%',
      'public://js/%',
      'public://php/%',
      'public://ctools/%',
      'public://xmlsitemap/%',
      'public://config_%',
    // Site logos — site-specific branding directory (not a Drupal core path).
      'public://wordmark/%',
      'private://styles/%',
      'private://thumbnails/%',
      'private://media-icons/%',
      'private://oembed_thumbnails/%',
      'private://video_thumbnails/%',
      'private://css/%',
      'private://js/%',
      'private://php/%',
      'private://ctools/%',
      'private://xmlsitemap/%',
      'private://config_%',
    ];

    // Merge user-configured excluded directories (generate both public:// and
    // private:// patterns from each directory name).
    $custom_dirs = $this->configFactory->get('digital_asset_inventory.settings')->get('scan_excluded_directories') ?? [];
    foreach ($custom_dirs as $dir) {
      $excluded_paths[] = 'public://' . $dir . '/%';
      $excluded_paths[] = 'private://' . $dir . '/%';
    }

    // Add NOT LIKE conditions for each excluded path.
    foreach ($excluded_paths as $excluded_path) {
      $query->condition('uri', $excluded_path, 'NOT LIKE');
    }
  }

  /**
   * Check if a file URI points to a system icon directory.
   *
   * System icons are generic placeholders provided by Drupal core or contrib
   * modules (e.g., media-icons/generic/generic.png). These should not be
   * tracked as derived thumbnails because hundreds of media entities share the
   * same generic icon, creating massive duplicate usage with no audit value.
   *
   * @param string $uri
   *   The file URI (e.g., 'public://media-icons/generic/generic.png').
   *
   * @return bool
   *   TRUE if the URI is a system icon that should be skipped.
   */
  protected function isSystemIconUri(string $uri): bool {
    $icon_directories = [
      'media-icons/',
    ];
    // Strip scheme (public://, private://) to get relative path.
    $relative = preg_replace('#^[a-zA-Z]+://#', '', $uri);
    foreach ($icon_directories as $dir) {
      if (strpos($relative, $dir) === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Registers a derived file as an asset item and records its usage.
   *
   * Used for relationship-driven inclusion: files discovered through entity
   * relationships (e.g., Media thumbnails) that reside in directories excluded
   * from filesystem scanning.
   *
   * @param int $thumbnail_fid
   *   The file ID of the derived file.
   * @param int $media_id
   *   The media entity ID that references this file.
   * @param string $field_name
   *   The field name on the media entity (e.g., 'thumbnail').
   * @param string $embed_method
   *   The embed method value (e.g., 'derived_thumbnail').
   * @param bool $is_temp
   *   Whether to create items as temporary (atomic swap pattern).
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The digital_asset_item entity storage.
   * @param \Drupal\Core\Entity\EntityStorageInterface $usage_storage
   *   The digital_asset_usage entity storage.
   */
  protected function registerDerivedFileUsage(
    int $thumbnail_fid,
    int $media_id,
    string $field_name,
    string $embed_method,
    $is_temp,
    $storage,
    $usage_storage
  ): void {
    // Skip generic placeholder icons (e.g., media-icons/generic/generic.png).
    // These are Drupal's default thumbnails for media types without a real
    // preview image — tracking them as derived assets creates hundreds of
    // duplicate usage records with no audit value.
    $file_storage = $this->entityTypeManager->getStorage('file');
    $file_entity = $file_storage->load($thumbnail_fid);
    if (!$file_entity) {
      $this->logger->warning('Thumbnail file @fid referenced by media @mid not found in file_managed.', [
        '@fid' => $thumbnail_fid,
        '@mid' => $media_id,
      ]);
      return;
    }
    $uri = $file_entity->getFileUri();
    if ($this->isSystemIconUri($uri)) {
      return;
    }

    // Check if a temp asset item already exists for this fid.
    $existing_query = $storage->getQuery()
      ->condition('fid', $thumbnail_fid)
      ->condition('is_temp', $is_temp ? 1 : 0)
      ->accessCheck(FALSE)
      ->execute();

    if ($existing_ids = $existing_query) {
      $thumbnail_asset = $storage->load(reset($existing_ids));
    }
    else {
      // Determine asset type and category from MIME type.
      $asset_type = $this->mapMimeToAssetType($file_entity->getMimeType());
      $category = $this->mapAssetTypeToCategory($asset_type);
      $sort_order = $this->getCategorySortOrder($category);

      // Convert URI to absolute URL for storage.
      try {
        $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($file_entity->getFileUri());
      }
      catch (\Exception $e) {
        $absolute_url = $file_entity->getFileUri();
      }

      $is_private = strpos($file_entity->getFileUri(), 'private://') === 0;

      // Create asset item with source_type = 'media_managed'.
      $thumbnail_asset = $storage->create([
        'fid' => $thumbnail_fid,
        'source_type' => 'media_managed',
        'media_id' => $media_id,
        'asset_type' => $asset_type,
        'category' => $category,
        'sort_order' => $sort_order,
        'file_path' => $absolute_url,
        'file_name' => $file_entity->getFilename(),
        'mime_type' => $file_entity->getMimeType(),
        'filesize' => $file_entity->getSize(),
        'is_temp' => $is_temp,
        'is_private' => $is_private,
      ]);
      $thumbnail_asset->save();
    }

    $thumbnail_asset_id = $thumbnail_asset->id();

    // Dedup: check all 5 persisted fields to avoid duplicate usage rows.
    $existing_usage_query = $usage_storage->getQuery();
    $existing_usage_query->condition('asset_id', $thumbnail_asset_id);
    $existing_usage_query->condition('entity_type', 'media');
    $existing_usage_query->condition('entity_id', $media_id);
    $existing_usage_query->condition('field_name', $field_name);
    $existing_usage_query->condition('embed_method', $embed_method);
    $existing_usage_query->accessCheck(FALSE);
    $existing_usage_ids = $existing_usage_query->execute();

    if (!$existing_usage_ids) {
      $usage_storage->create([
        'asset_id' => $thumbnail_asset_id,
        'entity_type' => 'media',
        'entity_id' => $media_id,
        'field_name' => $field_name,
        'count' => 1,
        'embed_method' => $embed_method,
      ])->save();
    }

    // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).
  }

  /**
   * Creates an orphan reference record for a detected orphan.
   *
   * @param int $asset_id
   *   The digital asset item ID.
   * @param string $source_entity_type
   *   The entity type of the orphan source (e.g., 'paragraph').
   * @param int $source_entity_id
   *   The entity ID of the orphan source.
   * @param string $field_name
   *   The field containing the reference.
   * @param string $embed_method
   *   How the asset is referenced.
   * @param string $reference_context
   *   Why this reference is orphaned.
   */
  protected function createOrphanReference(
    int $asset_id,
    string $source_entity_type,
    int $source_entity_id,
    string $field_name = '',
    string $embed_method = 'field_reference',
    string $reference_context = 'detached_component'
  ): void {
    try {
      // Look up source entity bundle via raw SQL — replaces full entity load
      // that was only used to call ->bundle(). A single SELECT on the data
      // table is ~0.01s vs ~0.15s for Entity API load on managed hosting.
      $source_bundle = '';
      try {
        $entity_type_def = $this->entityTypeManager->getDefinition($source_entity_type);
        $bundle_key = $entity_type_def->getKey('bundle');
        if ($bundle_key) {
          $data_table = $entity_type_def->getDataTable()
            ?: $entity_type_def->getBaseTable();
          $id_key = $entity_type_def->getKey('id');
          if ($data_table && $id_key) {
            $source_bundle = (string) $this->database
              ->select($data_table, 'e')
              ->fields('e', [$bundle_key])
              ->condition($id_key, $source_entity_id)
              ->range(0, 1)
              ->execute()
              ->fetchField();
          }
        }
        else {
          // Entity type has no bundle key (e.g., 'user') — bundle = entity type.
          $source_bundle = $source_entity_type;
        }
      }
      catch (\Exception $e) {
        // Entity type definition not available or table missing.
      }

      $this->bufferOrphanReference(
        $asset_id,
        $source_entity_type,
        $source_entity_id,
        $source_bundle,
        $field_name,
        $embed_method,
        $reference_context,
      );
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to buffer orphan reference for asset @id: @error', [
        '@id' => $asset_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Increments the orphaned paragraph count for scan statistics.
   *
   * Uses Drupal State API to track counts across batch chunks.
   *
   * @deprecated Use $this->currentOrphanCount++ instead. Orphan count is
   *   now accumulated via scanner property and persisted once per callback
   *   via persistOrphanCount() to reduce DB writes.
   */
  protected function incrementOrphanCount() {
    $state = \Drupal::state();
    $current = $state->get('digital_asset_inventory.scan_orphan_count', 0);
    $state->set('digital_asset_inventory.scan_orphan_count', $current + 1);
  }

  /**
   * Gets the current orphaned paragraph count.
   *
   * @return int
   *   The number of orphaned paragraphs skipped during scan.
   */
  public function getOrphanCount() {
    return \Drupal::state()->get('digital_asset_inventory.scan_orphan_count', 0);
  }

  /**
   * Records an untracked orphan paragraph ID for scan reporting.
   *
   * Called when an orphan paragraph is detected but no dai_orphan_reference
   * can be created (e.g., $asset_id not yet resolved at that scan stage).
   *
   * @param int $paragraph_id
   *   The paragraph entity ID.
   * @param string $context
   *   The orphan context (e.g., 'missing_parent_entity', 'detached_component').
   * @param string $field_name
   *   The field name where the orphan was found.
   */
  protected function recordUntrackedOrphan(int $paragraph_id, string $context, string $field_name = '') {
    $state = \Drupal::state();
    $untracked = $state->get('digital_asset_inventory.scan_untracked_orphans', []);
    $untracked[] = [
      'paragraph_id' => $paragraph_id,
      'context' => $context,
      'field_name' => $field_name,
    ];
    $state->set('digital_asset_inventory.scan_untracked_orphans', $untracked);
  }

  /**
   * Gets the list of untracked orphan paragraphs from the current scan.
   *
   * @return array
   *   Array of ['paragraph_id' => int, 'context' => string, 'field_name' => string].
   */
  public function getUntrackedOrphans() {
    return \Drupal::state()->get('digital_asset_inventory.scan_untracked_orphans', []);
  }

  /**
   * Resets scan statistics (call at start of new scan).
   */
  public function resetScanStats() {
    $state = \Drupal::state();
    $state->set('digital_asset_inventory.scan_orphan_count', 0);
    $state->set('digital_asset_inventory.scan_untracked_orphans', []);
  }

  /**
   * Gets count of menu links to scan for file references.
   *
   * @return int
   *   The number of menu link content entities.
   */
  public function getMenuLinksCount() {
    // Check if menu_link_content module is enabled.
    if (!$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return 0;
    }

    try {
      $count = $this->entityTypeManager
        ->getStorage('menu_link_content')
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      return (int) $count;
    }
    catch (\Exception $e) {
      $this->logger->warning('Could not count menu links: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Scans a chunk of menu links for file references.
   *
   * Detects menu links that point to files (PDFs, documents, etc.) and creates
   * usage tracking records for them.
   *
   * @param int $offset
   *   Starting offset.
   * @param int $limit
   *   Number of menu links to process.
   * @param bool $is_temp
   *   Whether to mark items as temporary (unused for menu link scanning).
   *
   * @return int
   *   Number of menu links processed.
   */
  public function scanMenuLinksChunk($offset, $limit, $is_temp = FALSE) {
    // Check if menu_link_content module is enabled.
    if (!$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return 0;
    }

    $count = 0;

    try {
      // Load menu link entities.
      $ids = $this->entityTypeManager
        ->getStorage('menu_link_content')
        ->getQuery()
        ->accessCheck(FALSE)
        ->range($offset, $limit)
        ->execute();

      if (empty($ids)) {
        return 0;
      }

      $menu_links = $this->entityTypeManager
        ->getStorage('menu_link_content')
        ->loadMultiple($ids);

      $asset_storage = $this->entityTypeManager->getStorage('digital_asset_item');
      $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');
      $file_storage = $this->entityTypeManager->getStorage('file');

      foreach ($menu_links as $menu_link) {
        $count++;

        // Get the link field value.
        if (!$menu_link->hasField('link') || $menu_link->get('link')->isEmpty()) {
          continue;
        }

        $link_uri = $menu_link->get('link')->uri;
        if (empty($link_uri)) {
          continue;
        }

        // Convert URI to file path for matching.
        $file_info = $this->parseMenuLinkUri($link_uri);
        if (!$file_info) {
          continue;
        }

        // Find the DigitalAssetItem for this file.
        $asset_id = $this->findAssetIdByFileInfo($file_info, $asset_storage, $file_storage);
        if (!$asset_id) {
          continue;
        }

        // Get menu name for context.
        $menu_name = $menu_link->getMenuName();

        // Check if usage record already exists.
        $usage_query = $usage_storage->getQuery();
        $usage_query->condition('asset_id', $asset_id);
        $usage_query->condition('entity_type', 'menu_link_content');
        $usage_query->condition('entity_id', $menu_link->id());
        $usage_query->accessCheck(FALSE);
        $existing_usage = $usage_query->execute();

        if (!$existing_usage) {
          // Create usage tracking record.
          $usage_storage->create([
            'asset_id' => $asset_id,
            'entity_type' => 'menu_link_content',
            'entity_id' => $menu_link->id(),
            'field_name' => 'link (' . $menu_name . ')',
            'count' => 1,
            'embed_method' => 'menu_link',
          ])->save();

          // Update CSV export fields for the asset.
          // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Error scanning menu links: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    // Reset entity caches to prevent memory exhaustion in long batch runs.
    $this->resetEntityCaches([
      'digital_asset_item', 'digital_asset_usage', 'menu_link_content',
    ]);

    return $count;
  }

  /**
   * Gets menu link IDs after a given ID, for cursor-based pagination.
   *
   * @param int $lastId
   *   Last processed menu link ID (exclusive lower bound).
   * @param int $limit
   *   Maximum number of IDs to return.
   *
   * @return array
   *   Array of menu link content IDs.
   */
  protected function getMenuLinkIdsAfter(int $lastId, int $limit): array {
    if (!$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return [];
    }

    return $this->entityTypeManager->getStorage('menu_link_content')->getQuery()
      ->condition('id', $lastId, '>')
      ->sort('id', 'ASC')
      ->range(0, $limit)
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Processes a single menu link entity for file references.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $menu_link
   *   The menu link entity, bulk-loaded by the caller.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */
  protected function processMenuLink($menu_link, bool $is_temp): void {
    $asset_storage = $this->entityTypeManager->getStorage('digital_asset_item');
    $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');
    $file_storage = $this->entityTypeManager->getStorage('file');
    $id = $menu_link->id();

    try {

      // Get the link field value.
      if (!$menu_link->hasField('link') || $menu_link->get('link')->isEmpty()) {
        return;
      }

      $link_uri = $menu_link->get('link')->uri;
      if (empty($link_uri)) {
        return;
      }

      // Convert URI to file path for matching.
      $file_info = $this->parseMenuLinkUri($link_uri);
      if (!$file_info) {
        return;
      }

      // Find the DigitalAssetItem for this file.
      $asset_id = $this->findAssetIdByFileInfo($file_info, $asset_storage, $file_storage);
      if (!$asset_id) {
        return;
      }

      // Get menu name for context.
      $menu_name = $menu_link->getMenuName();

      // Check if usage record already exists.
      $usage_query = $usage_storage->getQuery();
      $usage_query->condition('asset_id', $asset_id);
      $usage_query->condition('entity_type', 'menu_link_content');
      $usage_query->condition('entity_id', $menu_link->id());
      $usage_query->accessCheck(FALSE);
      $existing_usage = $usage_query->execute();

      if (!$existing_usage) {
        $usage_storage->create([
          'asset_id' => $asset_id,
          'entity_type' => 'menu_link_content',
          'entity_id' => $menu_link->id(),
          'field_name' => 'link (' . $menu_name . ')',
          'count' => 1,
          'embed_method' => 'menu_link',
        ])->save();

        // CSV export fields deferred to Phase 6 (updateCsvExportFieldsBulk).
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Error processing menu link @id: @message', [
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Scans menu links for file references with time-budgeted processing.
   *
   * @param array &$context
   *   Batch API context array.
   * @param bool $is_temp
   *   Whether to mark items as temporary.
   */
  public function scanMenuLinksChunkNew(array &$context, bool $is_temp): void {
    $itemsThisCallback = $this->processWithTimeBudget(
      $context,
      'last_id',
      'total_menu_links',
      fn() => $this->getMenuLinksCount(),
      fn(int $lastId, int $limit) => $this->getMenuLinkIdsAfter($lastId, $limit),
      fn(array $ids) => $this->entityTypeManager->getStorage('menu_link_content')->loadMultiple($ids),
      fn($link) => $this->processMenuLink($link, $is_temp),
    );

    // FR-6: Cache resets.
    $this->flushUsageBuffer();
    $this->flushOrphanRefBuffer();
    $this->resetPhaseEntityCaches(['digital_asset_item', 'digital_asset_usage', 'menu_link_content']);
    if ($itemsThisCallback >= 50) {
      drupal_static_reset();
    }

    $context['results']['last_chunk_items'] = $itemsThisCallback;
  }

  /**
   * Parses a menu link URI to extract file information.
   *
   * @param string $uri
   *   The menu link URI (e.g., 'internal:/sites/default/files/doc.pdf').
   *
   * @return array|null
   *   Array with 'type' (stream or url) and 'path' keys, or NULL if not a file.
   */
  protected function parseMenuLinkUri($uri) {
    // Handle internal URIs (internal:/path).
    if (strpos($uri, 'internal:/') === 0) {
      $path = substr($uri, 9); // Remove 'internal:'
      return $this->extractFileInfoFromPath($path);
    }

    // Handle base URIs (base:path).
    if (strpos($uri, 'base:') === 0) {
      $path = '/' . substr($uri, 5); // Remove 'base:' and add leading slash
      return $this->extractFileInfoFromPath($path);
    }

    // Handle entity URIs (entity:node/123) - not file links.
    if (strpos($uri, 'entity:') === 0) {
      return NULL;
    }

    // Handle route URIs (route:<name>) - not file links.
    if (strpos($uri, 'route:') === 0) {
      return NULL;
    }

    // Handle full URLs (https://...).
    if (strpos($uri, 'http://') === 0 || strpos($uri, 'https://') === 0) {
      // Check if it's a local file URL.
      $parsed = parse_url($uri);
      if (isset($parsed['path'])) {
        return $this->extractFileInfoFromPath($parsed['path']);
      }
    }

    return NULL;
  }

  /**
   * Extracts file info from a URL path.
   *
   * @param string $path
   *   The URL path (e.g., '/sites/default/files/doc.pdf').
   *
   * @return array|null
   *   Array with file info or NULL if not a file path.
   */
  protected function extractFileInfoFromPath(string $path): ?array {
    $path = trim($path, " \t\n\r\0\x0B\"'");
    $path = preg_replace('/[?#].*$/', '', $path);

    // Delegate to trait: handles universal public, dynamic fallback,
    // legacy /private/ under public path, and /system/files/.
    if ($uri = $this->urlPathToStreamUri($path)) {
      return [
        'type' => 'stream',
        'stream_uri' => $uri,
        'path' => $path,
      ];
    }

    return NULL;
  }

  /**
   * Finds a DigitalAssetItem ID by file information.
   *
   * @param array $file_info
   *   File info from parseMenuLinkUri().
   * @param \Drupal\Core\Entity\EntityStorageInterface $asset_storage
   *   The asset storage.
   * @param \Drupal\Core\Entity\EntityStorageInterface $file_storage
   *   The file storage.
   *
   * @return int|null
   *   The asset ID or NULL if not found.
   */
  protected function findAssetIdByFileInfo(array $file_info, $asset_storage, $file_storage) {
    if ($file_info['type'] !== 'stream' || empty($file_info['stream_uri'])) {
      return NULL;
    }

    $stream_uri = $file_info['stream_uri'];

    // First, try to find the file entity by URI.
    $file_ids = $file_storage->getQuery()
      ->condition('uri', $stream_uri)
      ->accessCheck(FALSE)
      ->execute();

    if ($file_ids) {
      $fid = reset($file_ids);

      // Find asset by fid (file_managed source).
      $asset_ids = $asset_storage->getQuery()
        ->condition('fid', $fid)
        ->condition('is_temp', TRUE)
        ->accessCheck(FALSE)
        ->execute();

      if ($asset_ids) {
        return reset($asset_ids);
      }

      // Also check media_id if it's a media file.
      // Media files might be tracked via media_id rather than fid.
      $media_ids = $this->entityTypeManager
        ->getStorage('media')
        ->getQuery()
        ->condition('field_media_file.target_id', $fid)
        ->accessCheck(FALSE)
        ->execute();

      if ($media_ids) {
        $media_id = reset($media_ids);
        $asset_ids = $asset_storage->getQuery()
          ->condition('media_id', $media_id)
          ->condition('is_temp', TRUE)
          ->accessCheck(FALSE)
          ->execute();

        if ($asset_ids) {
          return reset($asset_ids);
        }
      }
    }

    // If file entity not found, try matching by path.
    // This handles orphan files that might be in the inventory.
    $absolute_url = $this->fileUrlGenerator->generateAbsoluteString($stream_uri);
    $asset_ids = $asset_storage->getQuery()
      ->condition('file_path', $absolute_url)
      ->condition('is_temp', TRUE)
      ->accessCheck(FALSE)
      ->execute();

    if ($asset_ids) {
      return reset($asset_ids);
    }

    return NULL;
  }

  /**
   * Lock name for scan concurrency protection.
   */
  const SCAN_LOCK_NAME = 'digital_asset_inventory_scan';

  /**
   * Lock timeout in seconds (2 hours).
   */
  const SCAN_LOCK_TIMEOUT = 7200;

  /**
   * Fallback stale lock threshold. Runtime value comes from config via getStaleLockThreshold().
   */
  const SCAN_LOCK_STALE_THRESHOLD = 900;

  /**
   * Fallback time budget. Runtime value comes from config via getBatchTimeBudget().
   */
  const BATCH_TIME_BUDGET_SECONDS = 10;

  /**
   * Acquires the scan lock and sets the heartbeat.
   *
   * @return bool
   *   TRUE if lock acquired, FALSE if already held.
   */
  public function acquireScanLock(): bool {
    $acquired = $this->lock->acquire(self::SCAN_LOCK_NAME, self::SCAN_LOCK_TIMEOUT);
    if ($acquired) {
      // Session-scoped heartbeat.
      $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
      if ($sessionId) {
        $this->state->set("dai.scan.{$sessionId}.heartbeat", time());
      }
      // Legacy global key for backward compatibility during rollout.
      $this->state->set('dai.scan.lock.heartbeat', time());
    }
    return $acquired;
  }

  /**
   * Releases the scan lock and clears the heartbeat.
   */
  public function releaseScanLock(): void {
    $this->lock->release(self::SCAN_LOCK_NAME);
    // Clean up session-scoped keys.
    $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
    if ($sessionId) {
      $this->cleanupSessionKeys($sessionId);
    }
    // Legacy global key cleanup.
    $this->state->delete('dai.scan.lock.heartbeat');
  }

  /**
   * Checks if a scan lock is currently held.
   *
   * @return bool
   *   TRUE if the scan lock is held.
   */
  public function isScanLocked(): bool {
    return !$this->lock->lockMayBeAvailable(self::SCAN_LOCK_NAME);
  }

  /**
   * Updates the scan heartbeat timestamp.
   *
   * Called by batch callbacks after each chunk to signal the scan is alive.
   */
  public function updateScanHeartbeat(): void {
    $now = time();
    $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
    if ($sessionId) {
      $this->state->set("dai.scan.{$sessionId}.heartbeat", $now);
    }
    // Legacy global key — maintain during transition.
    $this->state->set('dai.scan.lock.heartbeat', $now);
  }

  /**
   * Returns the scan heartbeat timestamp.
   *
   * @return int|null
   *   The heartbeat timestamp, or NULL if not set.
   */
  public function getScanHeartbeat(): ?int {
    $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
    if ($sessionId) {
      $heartbeat = $this->state->get("dai.scan.{$sessionId}.heartbeat");
      if ($heartbeat !== NULL) {
        return (int) $heartbeat;
      }
    }
    // Legacy fallback — handles scans started before this update.
    $legacy = $this->state->get('dai.scan.lock.heartbeat');
    return $legacy !== NULL ? (int) $legacy : NULL;
  }

  /**
   * Checks if the scan lock is stale (no heartbeat for 2+ minutes).
   *
   * A stale lock indicates the scan was abandoned (user navigated away
   * without clicking Cancel, so batchFinished() never released the lock).
   *
   * Uses a 3-tier check:
   * 1. If heartbeat exists, compare against threshold.
   * 2. If heartbeat is missing (startup window), fall back to
   *    checkpoint.started timestamp for grace period.
   * 3. If both are missing, treat as orphan lock (stale).
   *
   * @return bool
   *   TRUE if the lock heartbeat is stale or missing with no grace.
   */
  public function isScanLockStale(): bool {
    if (!$this->isScanLocked()) {
      return FALSE;
    }

    $threshold = $this->getStaleLockThreshold();
    $now = time();

    // Tier 1: Session-scoped heartbeat.
    $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
    if ($sessionId) {
      $heartbeat = $this->state->get("dai.scan.{$sessionId}.heartbeat");
      if ($heartbeat !== NULL) {
        return ($now - (int) $heartbeat) > $threshold;
      }
    }

    // Tier 2: Legacy global heartbeat (handles scans started before session-scoped keys).
    $legacyHeartbeat = $this->state->get('dai.scan.lock.heartbeat');
    if ($legacyHeartbeat !== NULL) {
      return ($now - (int) $legacyHeartbeat) > $threshold;
    }

    // Tier 3: checkpoint.started (grace window for startup or missing heartbeat).
    $started = $this->state->get('dai.scan.checkpoint.started');
    if ($started !== NULL) {
      return ($now - (int) $started) > $threshold;
    }

    // Tier 4: No heartbeat, no started — orphan lock. Stale.
    return TRUE;
  }

  /**
   * Force-breaks a stale scan lock.
   *
   * Releases the persistent lock and clears the heartbeat.
   * Includes guardrails (lock must be held and stale) and forensic
   * logging for post-incident analysis.
   */
  public function breakStaleLock(): void {
    if (!$this->isScanLocked() || !$this->isScanLockStale()) {
      $this->logger->warning('breakStaleLock() called without meeting preconditions.');
      return;
    }

    $checkpoint = $this->getCheckpoint();
    $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
    $heartbeat = $sessionId
      ? $this->state->get("dai.scan.{$sessionId}.heartbeat")
      : $this->state->get('dai.scan.lock.heartbeat');
    $started = $checkpoint['started'] ?? NULL;

    $this->logger->warning('Breaking stale scan lock. Session: @session, Heartbeat: @hb, Started: @started, Now: @now, Phase: @phase, Saved item count: @items, Saved usage count: @usage', [
      '@session' => $sessionId ?? 'unknown',
      '@hb' => $heartbeat ?? 'none',
      '@started' => $started ?? 'none',
      '@now' => time(),
      '@phase' => $checkpoint['phase'] ?? 'none',
      '@items' => $checkpoint['temp_item_count'] ?? 'unknown',
      '@usage' => $checkpoint['temp_usage_count'] ?? 'unknown',
    ]);

    // Release lock via service API (works on any backend: MySQL, Redis, etc.).
    $this->persistentLock->release(self::SCAN_LOCK_NAME);

    // Clean up session-scoped keys.
    if ($sessionId) {
      $this->cleanupSessionKeys($sessionId);
    }
    // Also clean legacy global key if present.
    $this->state->delete('dai.scan.lock.heartbeat');

    // Restore cron if it was suspended by an interrupted scan.
    $this->restoreCron();
  }

  /**
   * Suspends automated cron for the duration of the scan.
   *
   * Saves the current cron interval to State so it can be restored
   * after scan completion, even if the scan is interrupted and resumed.
   */
  public function suspendCron(): void {
    if (!$this->moduleHandler || !$this->moduleHandler->moduleExists('automated_cron')) {
      return;
    }

    $config = $this->configFactory->getEditable('automated_cron.settings');
    $currentInterval = $config->get('interval');

    // Don't suspend if already suspended (interval = 0) or if we already saved.
    if ($currentInterval > 0) {
      $this->state->set('dai.scan.cron_interval_backup', $currentInterval);
      $config->set('interval', 0)->save();
      $this->logger->notice('Automated cron suspended during scan (was @interval seconds).', [
        '@interval' => $currentInterval,
      ]);
    }
  }

  /**
   * Restores automated cron after scan completion.
   */
  public function restoreCron(): void {
    if (!$this->moduleHandler || !$this->moduleHandler->moduleExists('automated_cron')) {
      $this->state->delete('dai.scan.cron_interval_backup');
      return;
    }

    $savedInterval = $this->state->get('dai.scan.cron_interval_backup');
    if ($savedInterval) {
      $config = $this->configFactory->getEditable('automated_cron.settings');
      $config->set('interval', $savedInterval)->save();
      $this->state->delete('dai.scan.cron_interval_backup');
      $this->logger->notice('Automated cron restored (@interval seconds).', [
        '@interval' => $savedInterval,
      ]);
    }
  }

  /**
   * Saves a phase checkpoint after successful phase completion.
   *
   * Enforces INV-4 (monotonicity) internally:
   * - $phase > stored: update phase and counts
   * - $phase == stored: update counts only; phase5_complete sticky TRUE
   * - $phase < stored or out of range: ignore and log warning
   * - session_id mismatch or missing: ignore and log warning
   *
   * @param int $phase
   *   The phase number (1-7).
   * @param bool $final_phase_complete
   *   TRUE only when the final phase has fully completed.
   */
  public function saveCheckpoint(int $phase, bool $final_phase_complete = FALSE): void {
    // Validate phase range.
    if ($phase < 1 || $phase > 7) {
      $this->logger->warning('saveCheckpoint: invalid phase @phase (must be 1-7). Ignoring.', [
        '@phase' => $phase,
      ]);
      return;
    }

    // Final phase (7) must always pass TRUE for final_phase_complete.
    if ($phase === 7 && !$final_phase_complete) {
      $this->logger->warning('saveCheckpoint: Phase 7 called with final_phase_complete=FALSE. Ignoring.');
      return;
    }

    // Verify session ID exists in State.
    $stored_session_id = $this->state->get('dai.scan.checkpoint.session_id');
    if (empty($stored_session_id)) {
      $this->logger->warning('saveCheckpoint: No session_id in State. Ignoring.');
      return;
    }

    $stored_phase = (int) $this->state->get('dai.scan.checkpoint.phase', 0);

    // Monotonicity: reject decreasing phase.
    if ($phase < $stored_phase) {
      $this->logger->warning('saveCheckpoint: phase @new < stored phase @stored. Ignoring.', [
        '@new' => $phase,
        '@stored' => $stored_phase,
      ]);
      return;
    }

    // Count temp items and usage for checkpoint.
    $temp_item_count = (int) $this->entityTypeManager
      ->getStorage('digital_asset_item')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('is_temp', 1)
      ->count()
      ->execute();

    // Count temp usage rows (usage whose asset_id references a temp item).
    $temp_usage_count = (int) $this->database->select('digital_asset_usage', 'dau')
      ->condition('dau.asset_id',
        $this->database->select('digital_asset_item', 'dai')
          ->fields('dai', ['id'])
          ->condition('dai.is_temp', 1), 'IN')
      ->countQuery()->execute()->fetchField();

    if ($phase > $stored_phase) {
      // Advancing: update phase and counts.
      $this->state->set('dai.scan.checkpoint.phase', $phase);
      $this->state->set('dai.scan.checkpoint.temp_item_count', $temp_item_count);
      $this->state->set('dai.scan.checkpoint.temp_usage_count', $temp_usage_count);
      // final_phase_complete: only TRUE when the last phase (7) finishes.
      // Stored under phase5_complete (legacy) and phase6_complete keys.
      // The phase5_complete key name is legacy; it means "all scan
      // phases complete" regardless of how many phases exist.
      $this->state->set('dai.scan.checkpoint.phase5_complete', $final_phase_complete);
      $this->state->set('dai.scan.checkpoint.phase6_complete', $final_phase_complete);
    }
    elseif ($phase === $stored_phase) {
      // Same phase: update counts only. final_phase_complete is sticky TRUE.
      $this->state->set('dai.scan.checkpoint.temp_item_count', $temp_item_count);
      $this->state->set('dai.scan.checkpoint.temp_usage_count', $temp_usage_count);
      $stored_complete = $this->state->get('dai.scan.checkpoint.phase5_complete', FALSE);
      if (!$stored_complete && $final_phase_complete) {
        $this->state->set('dai.scan.checkpoint.phase5_complete', TRUE);
        $this->state->set('dai.scan.checkpoint.phase6_complete', TRUE);
      }
    }
  }

  /**
   * Gets current checkpoint state, or NULL if no checkpoint exists.
   *
   * @return array|null
   *   Structured checkpoint array or NULL.
   */
  public function getCheckpoint(): ?array {
    $session_id = $this->state->get('dai.scan.checkpoint.session_id');
    $phase = $this->state->get('dai.scan.checkpoint.phase');

    if (empty($session_id) || $phase === NULL) {
      return NULL;
    }

    return [
      'session_id' => $session_id,
      'phase' => (int) $phase,
      'started' => (int) $this->state->get('dai.scan.checkpoint.started', 0),
      'temp_item_count' => (int) $this->state->get('dai.scan.checkpoint.temp_item_count', 0),
      'temp_usage_count' => (int) $this->state->get('dai.scan.checkpoint.temp_usage_count', 0),
      'phase5_complete' => (bool) $this->state->get('dai.scan.checkpoint.phase5_complete', FALSE),
      'phase6_complete' => (bool) $this->state->get('dai.scan.checkpoint.phase6_complete', FALSE),
    ];
  }

  /**
   * Cleans up all session-scoped State keys for a given session.
   *
   * Call on scan completion, fresh start, or stale-break.
   */
  protected function cleanupSessionKeys(string $sessionId): void {
    $this->state->delete("dai.scan.{$sessionId}.heartbeat");
    $this->state->delete("dai.scan.{$sessionId}.stats.orphan_count");
  }

  /**
   * Persists the orphan count for the current session.
   *
   * Called once per batch callback with the cumulative total from sandbox.
   * Replaces the per-item incrementOrphanCount() to reduce DB writes.
   *
   * @param string $sessionId
   *   Active scan session ID.
   * @param int $count
   *   Cumulative orphan count (from $context['sandbox']).
   */
  public function persistOrphanCount(string $sessionId, int $count): void {
    $this->state->set("dai.scan.{$sessionId}.stats.orphan_count", $count);
  }

  /**
   * Clears all checkpoint state.
   */
  public function clearCheckpoint(): void {
    // Clean up session-scoped keys before clearing the session ID.
    $sessionId = $this->state->get('dai.scan.checkpoint.session_id');
    if ($sessionId) {
      $this->cleanupSessionKeys($sessionId);
    }

    $keys = [
      'dai.scan.checkpoint.session_id',
      'dai.scan.checkpoint.phase',
      'dai.scan.checkpoint.started',
      'dai.scan.checkpoint.temp_item_count',
      'dai.scan.checkpoint.temp_usage_count',
      'dai.scan.checkpoint.phase5_complete',
      'dai.scan.checkpoint.phase6_complete',
      'dai.scan.orphan_files',
      'dai.scan.orphan_usage_map',
    ];
    foreach ($keys as $key) {
      $this->state->delete($key);
    }
  }

  /**
   * Validates that temp scan data is intact for checkpoint resume or finalize.
   *
   * @param string $mode
   *   Either 'resume' or 'finalize'.
   *
   * @return array
   *   Structured validation result.
   */
  public function validateCheckpointIntegrity(string $mode): array {
    $checkpoint = $this->getCheckpoint();
    $result = [
      'ok' => TRUE,
      'reason' => 'none',
      'warnings' => [],
      'current_item_count' => 0,
      'saved_item_count' => 0,
      'current_usage_count' => 0,
      'saved_usage_count' => 0,
    ];

    if (!$checkpoint) {
      $result['ok'] = FALSE;
      $result['reason'] = 'missing_checkpoint';
      return $result;
    }

    $saved_item_count = $checkpoint['temp_item_count'];
    $saved_usage_count = $checkpoint['temp_usage_count'];

    // Count current temp items via COUNT(*) query.
    $current_item_count = (int) $this->database->select('digital_asset_item', 'dai')
      ->condition('dai.is_temp', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    // Count current temp usage via JOIN to temp items.
    $current_usage_count = (int) $this->database->select('digital_asset_usage', 'dau')
      ->condition('dau.asset_id',
        $this->database->select('digital_asset_item', 'dai')
          ->fields('dai', ['id'])
          ->condition('dai.is_temp', 1), 'IN')
      ->countQuery()->execute()->fetchField();

    $result['current_item_count'] = $current_item_count;
    $result['saved_item_count'] = $saved_item_count;
    $result['current_usage_count'] = $current_usage_count;
    $result['saved_usage_count'] = $saved_usage_count;

    // Item count checks (both modes).
    if ($current_item_count === 0 && $saved_item_count > 0) {
      $result['ok'] = FALSE;
      $result['reason'] = 'missing_temp_items';
      return $result;
    }

    if ($current_item_count < $saved_item_count) {
      $result['ok'] = FALSE;
      $result['reason'] = 'partial_item_loss';
      return $result;
    }

    // Usage count checks (mode-dependent).
    if ($mode === 'finalize') {
      if ($current_usage_count === 0 && $saved_usage_count > 0) {
        $result['ok'] = FALSE;
        $result['reason'] = 'usage_loss';
        return $result;
      }
      if ($current_usage_count < $saved_usage_count) {
        $result['ok'] = FALSE;
        $result['reason'] = 'usage_loss';
        return $result;
      }
    }
    elseif ($mode === 'resume') {
      // Usage mismatch is warning-only in resume mode.
      if ($current_usage_count < $saved_usage_count) {
        $result['warnings'][] = 'Usage count is lower than expected (' . $current_usage_count . ' vs ' . $saved_usage_count . '). Remaining phases will rebuild usage data.';
      }
    }

    return $result;
  }

  /**
   * Resets entity static caches for the given entity types.
   *
   * Used for memory management during long batch runs.
   *
   * @param array $entity_types
   *   Entity type IDs to reset.
   */
  public function resetEntityCaches(array $entity_types): void {
    foreach ($entity_types as $entity_type) {
      if ($this->entityTypeManager->hasDefinition($entity_type)) {
        $this->entityTypeManager->getStorage($entity_type)->resetCache();
      }
    }
    drupal_static_reset();
  }

}
