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

namespace Drupal\digital_asset_inventory\Plugin\views\area;

use Drupal\Core\Url;
use Drupal\views\Plugin\views\area\AreaPluginBase;

/**
 * Views area handler to display a back button in the footer.
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("digital_asset_info_footer")
 */
final class AssetInfoFooter extends AreaPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    $url = Url::fromRoute('view.digital_assets.page_inventory')->toString();

    return [
      '#markup' => '<div class="asset-info-footer"><a href="' . $url . '" class="button button--secondary" role="button">' . $this->t('Return to Inventory') . '</a></div>',
      '#attached' => [
        'library' => ['digital_asset_inventory/admin'],
      ],
    ];
  }

}
