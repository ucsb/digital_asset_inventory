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

namespace Drupal\digital_asset_inventory;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Handles pre-uninstall cleanup so the module can be removed in one step.
 *
 * Drupal core blocks module uninstall when:
 * - A filter plugin provided by the module is enabled in a text format
 *   (FilterUninstallValidator).
 * - Content entities defined by the module still have data
 *   (ContentUninstallValidator).
 *
 * This validator runs at priority 100 (before core validators) and:
 * 1. Disables the deprecated archive link filter in all text formats.
 * 2. Truncates all module entity tables so no content remains.
 *
 * Entity table definitions and the tables themselves are dropped by Drupal's
 * entity system during the actual uninstall phase that follows validation.
 */
class DaiUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a DaiUninstallValidator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $database) {
    $this->configFactory = $config_factory;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    if ($module !== 'digital_asset_inventory') {
      return [];
    }

    // --- 1. Filter cleanup ------------------------------------------------
    // Disable the deprecated filter in all text formats so the core
    // FilterUninstallValidator does not block uninstall.
    $filter_id = 'digital_asset_archive_link_filter';
    foreach ($this->configFactory->listAll('filter.format.') as $config_name) {
      $config = $this->configFactory->getEditable($config_name);
      $status = $config->get('filters.' . $filter_id . '.status');
      if ($status === TRUE) {
        $config->set('filters.' . $filter_id . '.status', FALSE);
        $config->save();
      }
    }

    // --- 2. Entity data cleanup -------------------------------------------
    // Truncate all module entity tables so core's ContentUninstallValidator
    // finds zero rows and allows uninstall. Order: children before parents.
    // These entities use base fields only (no revision/data/field tables).
    $entity_tables = [
      'dai_archive_note',
      'dai_orphan_reference',
      'digital_asset_archive',
      'digital_asset_usage',
      'digital_asset_item',
    ];

    foreach ($entity_tables as $table) {
      try {
        if ($this->database->schema()->tableExists($table)) {
          $this->database->truncate($table)->execute();
        }
      }
      catch (\Exception $e) {
        // Log but don't block — if truncate fails, core validator will
        // report the specific entity type so the admin can fix it.
        \Drupal::logger('digital_asset_inventory')->warning(
          'Could not truncate @table during uninstall: @error',
          ['@table' => $table, '@error' => $e->getMessage()]
        );
      }
    }

    return [];
  }

}
