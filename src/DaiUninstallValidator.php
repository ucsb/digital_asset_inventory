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
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Disables the deprecated filter plugin before uninstall validation.
 *
 * Drupal's FilterUninstallValidator blocks module uninstall when the module
 * provides a filter plugin that is enabled in any text format. This validator
 * runs first (weight -100) and disables the deprecated filter so the core
 * validator passes without manual intervention.
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
   * Constructs a DaiUninstallValidator.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    if ($module !== 'digital_asset_inventory') {
      return [];
    }

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

    return [];
  }

}
