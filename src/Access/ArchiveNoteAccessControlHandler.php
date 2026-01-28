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

namespace Drupal\digital_asset_inventory\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for the Archive Note entity.
 *
 * Enforces append-only policy: notes can be viewed and created,
 * but never updated or deleted. This ensures the audit trail
 * remains immutable for compliance purposes.
 */
class ArchiveNoteAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    switch ($operation) {
      case 'view':
        // Viewing notes requires either full archive access or view-only access.
        $has_full_access = $account->hasPermission('archive digital assets');
        $has_view_access = $account->hasPermission('view digital asset archives');
        return AccessResult::allowedIf($has_full_access || $has_view_access)
          ->cachePerPermissions();

      case 'update':
      case 'delete':
        // Notes are append-only: updates and deletions are always denied.
        // This preserves the audit trail for compliance purposes.
        return AccessResult::forbidden('Archive notes are append-only and cannot be modified or deleted.');

      default:
        return AccessResult::neutral();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Creating notes requires full archive permission.
    return AccessResult::allowedIfHasPermission($account, 'archive digital assets');
  }

}
