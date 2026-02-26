<?php

namespace Drupal\digital_asset_inventory\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides database-level aggregation queries for the dashboard.
 *
 * Entity objects are never loaded or instantiated — all metrics use SQL.
 * This is the single source of truth for all dashboard data.
 */
class DashboardDataService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a DashboardDataService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(Connection $database, ConfigFactoryInterface $config_factory) {
    $this->database = $database;
    $this->configFactory = $config_factory;
  }

  /**
   * Gets the total count of non-temporary asset items.
   *
   * @return int
   *   The total asset count.
   */
  public function getTotalAssetCount(): int {
    return (int) $this->database->query(
      "SELECT COUNT(*) FROM {digital_asset_item} WHERE is_temp = 0"
    )->fetchField();
  }

  /**
   * Gets the total count of active archived assets.
   *
   * Counts archives with status 'archived_public' or 'archived_admin'.
   *
   * @return int
   *   The total active archived count.
   */
  public function getTotalArchivedCount(): int {
    return (int) $this->database->query(
      "SELECT COUNT(*) FROM {digital_asset_archive}
       WHERE status IN ('archived_public', 'archived_admin')"
    )->fetchField();
  }

  /**
   * Gets asset counts grouped by category, ordered by sort_order.
   *
   * @return array
   *   Array of ['category' => string, 'count' => int].
   */
  public function getCategoryBreakdown(): array {
    $results = $this->database->query(
      "SELECT category, COUNT(*) AS count
       FROM {digital_asset_item}
       WHERE is_temp = 0
       GROUP BY category
       ORDER BY MIN(sort_order), category"
    )->fetchAll();

    $breakdown = [];
    foreach ($results as $row) {
      $breakdown[] = [
        'category' => $row->category,
        'count' => (int) $row->count,
      ];
    }
    return $breakdown;
  }

  /**
   * Gets usage breakdown: in_use, orphan_only, unused.
   *
   * Uses LEFT JOINs to digital_asset_usage and dai_orphan_reference
   * with subqueries.
   *
   * NOTE: Raw SQL used intentionally for performance and clarity.
   * Rewriting via query builder degrades readability and performance.
   *
   * @return array
   *   ['in_use' => int, 'orphan_only' => int, 'unused' => int]
   */
  public function getUsageBreakdown(): array {
    $result = $this->database->query(
      "SELECT
        COALESCE(SUM(CASE WHEN u.usage_count > 0 THEN 1 ELSE 0 END), 0) AS in_use,
        COALESCE(SUM(CASE WHEN COALESCE(u.usage_count, 0) = 0
          AND COALESCE(o.orphan_count, 0) > 0 THEN 1 ELSE 0 END), 0) AS orphan_only,
        COALESCE(SUM(CASE WHEN COALESCE(u.usage_count, 0) = 0
          AND COALESCE(o.orphan_count, 0) = 0 THEN 1 ELSE 0 END), 0) AS unused
       FROM {digital_asset_item} dai
       LEFT JOIN (
         SELECT asset_id, COUNT(*) AS usage_count
         FROM {digital_asset_usage} GROUP BY asset_id
       ) u ON dai.id = u.asset_id
       LEFT JOIN (
         SELECT asset_id, COUNT(*) AS orphan_count
         FROM {dai_orphan_reference} GROUP BY asset_id
       ) o ON dai.id = o.asset_id
       WHERE dai.is_temp = 0"
    )->fetchAssoc();

    return [
      'in_use' => (int) ($result['in_use'] ?? 0),
      'orphan_only' => (int) ($result['orphan_only'] ?? 0),
      'unused' => (int) ($result['unused'] ?? 0),
    ];
  }

  /**
   * Gets total storage (filesize) grouped by category.
   *
   * @return array
   *   Array of ['category' => string, 'total_bytes' => int].
   */
  public function getStorageByCategory(): array {
    $results = $this->database->query(
      "SELECT category, SUM(filesize) AS total_bytes
       FROM {digital_asset_item}
       WHERE is_temp = 0 AND filesize IS NOT NULL AND filesize > 0
       GROUP BY category
       ORDER BY SUM(filesize) DESC"
    )->fetchAll();

    $breakdown = [];
    foreach ($results as $row) {
      $breakdown[] = [
        'category' => $row->category,
        'total_bytes' => (int) $row->total_bytes,
      ];
    }
    return $breakdown;
  }

  /**
   * Gets top assets by usage count.
   *
   * @param int $limit
   *   Maximum number of assets to return.
   *
   * @return array
   *   Array of ['id' => int, 'file_name' => string, 'category' => string,
   *   'usage_count' => int, 'file_path' => string].
   */
  public function getTopAssetsByUsage(int $limit = 10): array {
    $results = $this->database->query(
      "SELECT dai.id, dai.file_name, dai.category, dai.file_path, COUNT(u.id) AS usage_count
       FROM {digital_asset_item} dai
       INNER JOIN {digital_asset_usage} u ON dai.id = u.asset_id
       WHERE dai.is_temp = 0
       GROUP BY dai.id, dai.file_name, dai.category, dai.file_path
       ORDER BY usage_count DESC
       LIMIT " . (int) $limit,
      []
    )->fetchAll();

    $assets = [];
    foreach ($results as $row) {
      $assets[] = [
        'id' => (int) $row->id,
        'file_name' => $row->file_name,
        'category' => $row->category,
        'usage_count' => (int) $row->usage_count,
        'file_path' => $row->file_path,
      ];
    }
    return $assets;
  }

  /**
   * Gets asset counts grouped by source_type (location).
   *
   * @return array
   *   Array of ['source_type' => string, 'count' => int].
   */
  public function getLocationBreakdown(): array {
    $results = $this->database->query(
      "SELECT source_type, COUNT(*) AS count
       FROM {digital_asset_item}
       WHERE is_temp = 0
       GROUP BY source_type
       ORDER BY count DESC"
    )->fetchAll();

    $labels = [
      'file_managed' => 'Upload',
      'media_managed' => 'Media',
      'filesystem_only' => 'Server',
      'external' => 'External',
    ];

    $breakdown = [];
    foreach ($results as $row) {
      $breakdown[] = [
        'source_type' => $labels[$row->source_type] ?? $row->source_type,
        'count' => (int) $row->count,
      ];
    }
    return $breakdown;
  }

  /**
   * Gets archive counts grouped by status.
   *
   * @return array
   *   Array of ['status' => string, 'count' => int].
   */
  public function getArchiveStatusBreakdown(): array {
    $results = $this->database->query(
      "SELECT status, COUNT(*) AS count
       FROM {digital_asset_archive}
       GROUP BY status
       ORDER BY count DESC"
    )->fetchAll();

    $labels = [
      'queued' => 'Queued',
      'archived_public' => 'Archived (Public)',
      'archived_admin' => 'Archived (Admin-Only)',
      'archived_deleted' => 'Archived (Deleted)',
      'exemption_void' => 'Exemption Void',
    ];

    $breakdown = [];
    foreach ($results as $row) {
      $breakdown[] = [
        'status' => $labels[$row->status] ?? $row->status,
        'count' => (int) $row->count,
      ];
    }
    return $breakdown;
  }

  /**
   * Gets archive type breakdown (Legacy vs General) for active archives.
   *
   * @return array
   *   ['legacy' => int, 'general' => int]
   */
  public function getArchiveTypeBreakdown(): array {
    $result = $this->database->query(
      "SELECT
        COALESCE(SUM(CASE WHEN flag_late_archive = 0 THEN 1 ELSE 0 END), 0) AS legacy,
        COALESCE(SUM(CASE WHEN flag_late_archive = 1 THEN 1 ELSE 0 END), 0) AS general
       FROM {digital_asset_archive}
       WHERE status IN ('archived_public', 'archived_admin')"
    )->fetchAssoc();

    return [
      'legacy' => (int) ($result['legacy'] ?? 0),
      'general' => (int) ($result['general'] ?? 0),
    ];
  }

  /**
   * Gets archive counts grouped by archive_reason for active archives.
   *
   * @return array
   *   Array of ['reason' => string, 'count' => int].
   */
  public function getArchiveReasonBreakdown(): array {
    $results = $this->database->query(
      "SELECT archive_reason, COUNT(*) AS count
       FROM {digital_asset_archive}
       WHERE status IN ('archived_public', 'archived_admin')
       GROUP BY archive_reason
       ORDER BY count DESC"
    )->fetchAll();

    $labels = [
      'reference' => 'Reference',
      'research' => 'Research',
      'recordkeeping' => 'Recordkeeping',
      'other' => 'Other',
    ];

    $breakdown = [];
    foreach ($results as $row) {
      $breakdown[] = [
        'reason' => $labels[$row->archive_reason] ?? $row->archive_reason,
        'count' => (int) $row->count,
      ];
    }
    return $breakdown;
  }

}
