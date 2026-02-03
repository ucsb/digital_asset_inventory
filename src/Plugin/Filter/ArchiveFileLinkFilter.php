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

namespace Drupal\digital_asset_inventory\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a filter to route file links to Archive Detail Pages.
 *
 * @deprecated in digital_asset_inventory:1.25.3 and is removed from
 *   digital_asset_inventory:2.0.0. Archive link routing is now handled
 *   automatically by ArchiveLinkResponseSubscriber which processes all
 *   HTML output. This filter is no longer needed and can be safely
 *   removed from text format configurations.
 * @see \Drupal\digital_asset_inventory\EventSubscriber\ArchiveLinkResponseSubscriber
 *
 * This filter was originally designed to process CKEditor content and replace
 * links to archived files with links to their Archive Detail Pages. However,
 * the Response Subscriber now handles this comprehensively for all content,
 * making this filter redundant.
 *
 * @Filter(
 *   id = "digital_asset_archive_link_filter",
 *   title = @Translation("Route archived file links to Archive Detail Page (Deprecated)"),
 *   description = @Translation("DEPRECATED: This filter is no longer needed. Archive link routing is now handled automatically by the Response Subscriber. You can safely remove this filter from your text format."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
 *   weight = 100
 * )
 */
final class ArchiveFileLinkFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The archive service.
   *
   * @var \Drupal\digital_asset_inventory\Service\ArchiveService
   */
  protected $archiveService;

  /**
   * Constructs an ArchiveFileLinkFilter object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ArchiveService $archive_service,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->archiveService = $archive_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('digital_asset_inventory.archive')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @deprecated in digital_asset_inventory:1.25.3 and is removed from
   *   digital_asset_inventory:2.0.0. Archive link routing is now handled
   *   by ArchiveLinkResponseSubscriber.
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    // Trigger deprecation notice.
    @trigger_error('The "digital_asset_archive_link_filter" text format filter is deprecated in digital_asset_inventory:1.25.3 and will be removed in digital_asset_inventory:2.0.0. Archive link routing is now handled automatically by ArchiveLinkResponseSubscriber. Remove this filter from your text format configuration. See https://github.com/ucsb/digital_asset_inventory', E_USER_DEPRECATED);

    // This filter no longer processes content. The ArchiveLinkResponseSubscriber
    // handles all archive link routing in the final HTML output.
    // We still add cache tags/contexts for proper cache invalidation during
    // the transition period while sites remove this filter.
    $result->addCacheTags(['digital_asset_archive_list', 'config:digital_asset_inventory.settings']);
    $result->addCacheContexts(['url.site']);

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    if ($long) {
      return $this->t('<strong>DEPRECATED:</strong> This filter is no longer needed. Archive link routing is now handled automatically by the Response Subscriber. You can safely remove this filter from your text format configuration.');
    }
    return $this->t('DEPRECATED: Remove this filter. Archive link routing is now automatic.');
  }

}
