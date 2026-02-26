<?php

namespace Drupal\digital_asset_inventory\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\digital_asset_inventory\Service\DashboardDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Digital Asset Inventory dashboard page.
 */
class DashboardController extends ControllerBase {

  /**
   * The dashboard data service.
   *
   * @var \Drupal\digital_asset_inventory\Service\DashboardDataService
   */
  protected DashboardDataService $dashboardData;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a DashboardController.
   *
   * @param \Drupal\digital_asset_inventory\Service\DashboardDataService $dashboard_data
   *   The dashboard data service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(DashboardDataService $dashboard_data, ConfigFactoryInterface $config_factory) {
    $this->dashboardData = $dashboard_data;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('digital_asset_inventory.dashboard_data'),
      $container->get('config.factory')
    );
  }

  /**
   * Renders the dashboard page.
   *
   * All metrics are computed once via DashboardDataService. The same dataset
   * is passed to both Twig (for tables/stat cards) and drupalSettings (for
   * Chart.js) — no redundant aggregation.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $archive_enabled = (bool) $config->get('enable_archive');

    // Gather all metrics once.
    $category_breakdown = $this->dashboardData->getCategoryBreakdown();
    $usage_breakdown = $this->dashboardData->getUsageBreakdown();
    $top_assets = $this->dashboardData->getTopAssetsByUsage(10);
    $location_breakdown = $this->dashboardData->getLocationBreakdown();

    // Prepare drupalSettings chart data.
    $chart_data = [
      'category' => [
        'labels' => array_column($category_breakdown, 'category'),
        'values' => array_column($category_breakdown, 'count'),
      ],
      'usage' => [
        'labels' => ['In Use', 'Unused'],
        'values' => [
          $usage_breakdown['in_use'],
          $usage_breakdown['unused'] + $usage_breakdown['orphan_only'],
        ],
      ],
      'location' => [
        'labels' => array_column($location_breakdown, 'source_type'),
        'values' => array_column($location_breakdown, 'count'),
      ],
    ];

    // Archive data (conditional).
    $archive_status = [];
    $archive_type = [];
    $archive_reason = [];

    if ($archive_enabled) {
      $archive_status = $this->dashboardData->getArchiveStatusBreakdown();
      $archive_type = $this->dashboardData->getArchiveTypeBreakdown();
      $archive_reason = $this->dashboardData->getArchiveReasonBreakdown();

      $chart_data['archiveStatus'] = [
        'labels' => array_column($archive_status, 'status'),
        'values' => array_column($archive_status, 'count'),
      ];
      $chart_data['archiveType'] = [
        'labels' => ['Legacy', 'General'],
        'values' => [$archive_type['legacy'], $archive_type['general']],
      ];
      $chart_data['archiveReason'] = [
        'labels' => array_column($archive_reason, 'reason'),
        'values' => array_column($archive_reason, 'count'),
      ];
    }

    $build = [
      '#theme' => 'dai_dashboard',
      '#total_assets' => $this->dashboardData->getTotalAssetCount(),
      '#total_archived' => $archive_enabled ? $this->dashboardData->getTotalArchivedCount() : 0,
      '#category_breakdown' => $category_breakdown,
      '#usage_breakdown' => $usage_breakdown,
      '#top_assets' => $top_assets,
      '#location_breakdown' => $location_breakdown,
      '#archive_enabled' => $archive_enabled,
      '#archive_status' => $archive_status,
      '#archive_type' => $archive_type,
      '#archive_reason' => $archive_reason,
      '#attached' => [
        'library' => ['digital_asset_inventory/dashboard_charts'],
        'drupalSettings' => [
          'digitalAssetInventory' => [
            'dashboard' => $chart_data,
          ],
        ],
      ],
      '#cache' => [
        'tags' => [
          'digital_asset_item_list',
          'digital_asset_archive_list',
          'config:digital_asset_inventory.settings',
        ],
        'max-age' => 3600,
      ],
    ];

    return $build;
  }

}
