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

namespace Drupal\digital_asset_inventory\Plugin\views\field;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A handler to display a "Notes" link with count for archive entries.
 *
 * Shows "Notes" (when count=0 and user can add) or "Notes (N)" (when count>0).
 * View-only users see the link only when notes exist.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("dai_archive_notes_link")
 */
final class ArchiveNotesLink extends FieldPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an ArchiveNotesLink object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // This is a computed field - do not add to the query.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Only show notes link if archive feature is enabled.
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    if (!$config->get('enable_archive')) {
      return [];
    }

    $can_add = $this->currentUser->hasPermission('add archive internal notes');
    $can_view = $this->currentUser->hasPermission('view archive internal notes');

    // Must have at least view permission.
    if (!$can_view && !$can_add) {
      return [];
    }

    // Get the archive entity.
    $archive = $values->_entity;
    if (!$archive) {
      return [];
    }

    // Count notes: initial note (if not empty) + notes log entries.
    $initial_note = trim((string) $archive->get('internal_notes')->value);
    $has_initial_note = $initial_note !== '';
    $log_count = $this->countNotes($archive->id());
    $total_count = ($has_initial_note ? 1 : 0) + $log_count;

    // View-only users: hide when count = 0.
    // Users with add permission: always show (to allow adding first note).
    if ($total_count === 0 && !$can_add) {
      return [];
    }

    // Build link title: "Notes" when count=0, "Notes (N)" when count>0.
    $title = $total_count > 0
      ? $this->t('Notes (@count)', ['@count' => $total_count])
      : $this->t('Notes');

    $aria_label = $total_count > 0
      ? $this->t('Notes, @count entries', ['@count' => $total_count])
      : $this->t('Notes, no entries yet');

    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromRoute('digital_asset_inventory.archive_notes', [
        'digital_asset_archive' => $archive->id(),
      ]),
      '#attributes' => [
        'class' => ['dai-notes-link'],
        'aria-label' => $aria_label,
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $archive->getCacheTags(),
      ],
    ];
  }

  /**
   * Counts notes log entries for an archive.
   *
   * @param int $archive_id
   *   The archive entity ID.
   *
   * @return int
   *   The number of notes.
   */
  protected function countNotes($archive_id) {
    try {
      $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
      return (int) $note_storage->getQuery()
        ->condition('archive_id', $archive_id)
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }
    catch (\Exception $e) {
      // Entity type may not exist yet (during update).
      return 0;
    }
  }

}
