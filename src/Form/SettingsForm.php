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

namespace Drupal\digital_asset_inventory\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Digital Asset Inventory settings.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager (required for Drupal 11, optional for D10.2+).
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    RouteProviderInterface $route_provider,
    ModuleHandlerInterface $module_handler,
  ) {
    // Pass both parameters - works in D10.2+ (optional) and D11 (required).
    parent::__construct($config_factory, $typedConfigManager);
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->routeProvider = $route_provider;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('router.route_provider'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['digital_asset_inventory.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('digital_asset_inventory.settings');

    // Archive Settings - first section.
    $form['archive'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Settings'),
      '#open' => TRUE,
      '#attributes' => ['role' => 'group'],
    ];

    $form['archive']['enable_archive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Archive functionality'),
      '#description' => $this->t('When enabled, documents and pages can be archived. Archives created before the compliance deadline are classified as "Legacy Archives" (eligible for ADA Title II exemption). Archives created after the deadline are classified as "General Archives" (retained for reference purposes without claiming accessibility exemption). Disabling hides archive features but preserves existing records.'),
      '#default_value' => $config->get('enable_archive') ?? FALSE,
    ];

    $form['archive']['enable_manual_archive'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Manual Archive entries'),
      '#description' => $this->t('When enabled, administrators can manually add archive entries for web pages and external resources without going through the scanner.'),
      '#default_value' => $config->get('enable_manual_archive') ?? FALSE,
      '#states' => [
        'visible' => [
          ':input[name="enable_archive"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Archive Classification Settings - second section (only relevant when archive enabled).
    $form['compliance'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Classification Settings'),
      '#open' => TRUE,
      '#attributes' => ['role' => 'group'],
      '#states' => [
        'visible' => [
          ':input[name="enable_archive"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Get current deadline value and format for display.
    // Use gmdate() to ensure UTC consistency with how we store the timestamp.
    $deadline_timestamp = $config->get('ada_compliance_deadline');
    $default_date = $deadline_timestamp ? gmdate('Y-m-d', $deadline_timestamp) : '2026-04-24';

    $form['compliance']['ada_compliance_deadline'] = [
      '#type' => 'date',
      '#title' => $this->t('ADA Compliance Deadline'),
      '#description' => $this->t('This date determines how archives are classified: archives created before this date are "Legacy Archives" (ADA Title II exempt), while archives created on or after this date are "General Archives" (no exemption claimed). Default: April 24, 2026.'),
      '#default_value' => $default_date,
    ];

    $form['compliance']['deadline_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning"><p><strong>' . $this->t('Important:') . '</strong> ' . $this->t('Changing this date only affects <em>new</em> archives. Existing archives retain their original classification (Legacy or General) based on the deadline that was configured when they were archived. This preserves the audit trail and compliance integrity.') . '</p></div>',
    ];

    $form['compliance']['exemption_info'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('<strong>Legacy Archives (ADA Title II):</strong> Archived content created before the deadline is exempt from WCAG 2.1 AA requirements, provided it has not been modified since being archived. If a legacy archive is modified after the compliance deadline, the exemption is automatically voided.') . '</p><p>' . $this->t('<strong>General Archives:</strong> Archived content created after the deadline is retained for reference, research, or recordkeeping purposes. These archives do not claim ADA accessibility exemption. If a general archive is modified after being archived, it is removed from the public Archive Registry and flagged as "Modified" for audit tracking purposes.') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $config = $this->config('digital_asset_inventory.settings');
    $was_enabled = (bool) $config->get('enable_archive');
    $will_enable = (bool) $form_state->getValue('enable_archive');

    // Only check conflicts when enabling (not when already enabled or disabling).
    if (!$was_enabled && $will_enable) {
      $conflicts = $this->checkArchivePathConflict();
      if (!empty($conflicts)) {
        $conflict_list = '<ul><li>' . implode('</li><li>', $conflicts) . '</li></ul>';
        $future_note = '<p><em>' . $this->t('Note: A future release may allow the Archive Registry to use an alternate path (e.g., /digital-archive) when /archive-registry is already in use, but this version requires exclusivity for /archive-registry.') . '</em></p>';
        $form_state->setErrorByName('enable_archive', $this->t('This site already uses the path <a href="@archive_url">/archive-registry</a>, so the Archive Registry cannot be enabled. Remove the following conflicts, then try again: @conflicts @future_note', [
          '@archive_url' => '/archive-registry',
          '@conflicts' => new \Drupal\Component\Render\FormattableMarkup($conflict_list, []),
          '@future_note' => new \Drupal\Component\Render\FormattableMarkup($future_note, []),
        ]));
      }
    }
  }

  /**
   * Checks if /archive-registry path is already in use by another part of the site.
   *
   * @return array
   *   Array of conflict descriptions, empty if no conflicts.
   */
  protected function checkArchivePathConflict() {
    $conflicts = [];

    // 1. Check path aliases for /archive-registry.
    try {
      $aliases = $this->database->select('path_alias', 'pa')
        ->fields('pa', ['path', 'alias'])
        ->condition('alias', '/archive-registry')
        ->execute()
        ->fetchAll();
      foreach ($aliases as $alias) {
        $conflicts[] = $this->t('Path alias: @source → @alias', [
          '@source' => $alias->path,
          '@alias' => $alias->alias,
        ]);
      }
    }
    catch (\Exception $e) {
      // Table may not exist, continue checking.
    }

    // 2. Check Views for /archive-registry path (excluding our own public_archive view).
    if (class_exists('\Drupal\views\Views')) {
      $views = Views::getAllViews();
      foreach ($views as $view) {
        // Skip our own view.
        if ($view->id() === 'public_archive') {
          continue;
        }
        $displays = $view->get('display');
        foreach ($displays as $display_id => $display) {
          if (isset($display['display_options']['path']) && $display['display_options']['path'] === 'archive-registry') {
            $conflicts[] = $this->t('View: @label (@id) - <a href="@url">Edit view</a>', [
              '@label' => $view->label(),
              '@id' => $view->id(),
              '@url' => '/admin/structure/views/view/' . $view->id(),
            ]);
            break;
          }
        }
      }
    }

    // 3. Check menu links pointing to /archive-registry.
    try {
      $menu_links = $this->entityTypeManager->getStorage('menu_link_content')
        ->loadByProperties(['link__uri' => 'internal:/archive-registry']);
      foreach ($menu_links as $link) {
        $conflicts[] = $this->t('Menu link: @title in @menu menu', [
          '@title' => $link->getTitle(),
          '@menu' => $link->getMenuName(),
        ]);
      }
    }
    catch (\Exception $e) {
      // Entity type may not exist, continue checking.
    }

    // 4. Check Redirect module entries (if installed).
    if ($this->moduleHandler->moduleExists('redirect')) {
      try {
        $redirects = $this->database->select('redirect', 'r')
          ->fields('r', ['rid', 'redirect_source__path', 'redirect_redirect__uri'])
          ->condition('redirect_source__path', 'archive-registry')
          ->execute()
          ->fetchAll();
        foreach ($redirects as $redirect) {
          $conflicts[] = $this->t('URL Redirect: /archive-registry → @target - <a href="@url">Edit redirect</a>', [
            '@target' => $redirect->redirect_redirect__uri,
            '@url' => '/admin/config/search/redirect/edit/' . $redirect->rid,
          ]);
        }
      }
      catch (\Exception $e) {
        // Table may not exist, continue checking.
      }
    }

    // 5. Check for custom routes at /archive-registry (excluding our module's routes).
    try {
      $routes = $this->routeProvider->getRoutesByPattern('/archive-registry');
      foreach ($routes as $route_name => $route) {
        // Skip our module's routes.
        if (strpos($route_name, 'digital_asset_inventory.') === 0 ||
            strpos($route_name, 'view.public_archive.') === 0) {
          continue;
        }
        $conflicts[] = $this->t('Custom route: @name', ['@name' => $route_name]);
      }
    }
    catch (\Exception $e) {
      // Route provider error, continue.
    }

    // 6. Check Pathauto patterns (if installed) - basic check for literal /archive-registry.
    if ($this->moduleHandler->moduleExists('pathauto')) {
      try {
        $pattern_storage = $this->entityTypeManager->getStorage('pathauto_pattern');
        $patterns = $pattern_storage->loadMultiple();
        foreach ($patterns as $pattern) {
          $pattern_value = $pattern->getPattern();
          // Check if pattern is exactly "archive-registry" or starts with "archive-registry/".
          if ($pattern_value === 'archive-registry' || strpos($pattern_value, 'archive-registry/') === 0) {
            $conflicts[] = $this->t('Pathauto pattern: @label (@pattern)', [
              '@label' => $pattern->label(),
              '@pattern' => $pattern_value,
            ]);
          }
        }
      }
      catch (\Exception $e) {
        // Entity type may not exist, continue.
      }
    }

    return $conflicts;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('digital_asset_inventory.settings');

    // Track if archive settings changed to trigger cache rebuild.
    $old_archive_value = $config->get('enable_archive');
    $new_archive_value = (bool) $form_state->getValue('enable_archive');
    $old_manual_archive_value = $config->get('enable_manual_archive');
    $new_manual_archive_value = (bool) $form_state->getValue('enable_manual_archive');

    // Convert date string to timestamp for storage.
    $deadline_date = $form_state->getValue('ada_compliance_deadline');
    $deadline_timestamp = $deadline_date ? strtotime($deadline_date . ' 00:00:00 UTC') : NULL;

    $config
      ->set('enable_archive', $new_archive_value)
      ->set('enable_manual_archive', $new_manual_archive_value)
      ->set('ada_compliance_deadline', $deadline_timestamp)
      ->save();

    // Enable/disable the public_archive View based on archive setting.
    if ($old_archive_value !== $new_archive_value) {
      $view = View::load('public_archive');
      if ($view) {
        if ($new_archive_value) {
          // Double-check no conflicts exist before enabling the /archive-registry route.
          $conflicts = $this->checkArchivePathConflict();
          if (!empty($conflicts)) {
            // Conflict still exists - revert config and warn user.
            $this->config('digital_asset_inventory.settings')
              ->set('enable_archive', FALSE)
              ->save();
            $conflict_list = '<ul><li>' . implode('</li><li>', $conflicts) . '</li></ul>';
            $future_note = '<p><em>' . $this->t('Note: A future release may allow the Archive Registry to use an alternate path (e.g., /digital-archive) when /archive-registry is already in use, but this version requires exclusivity for /archive-registry.') . '</em></p>';
            $this->messenger()->addError(new \Drupal\Component\Render\FormattableMarkup(
              $this->t('The /archive-registry path is still in use. Please remove the following conflicts, then try again:') . $conflict_list . $future_note,
              []
            ));
          }
          else {
            $view->enable();
            $view->save();
          }
        }
        else {
          $view->disable();
          $view->save();
        }
      }
    }

    // Clear caches if archive settings changed so menu links and routes update.
    if ($old_archive_value !== $new_archive_value || $old_manual_archive_value !== $new_manual_archive_value) {
      drupal_flush_all_caches();
      $this->messenger()->addStatus($this->t('All caches have been cleared.'));
    }

    parent::submitForm($form, $form_state);
  }

}
