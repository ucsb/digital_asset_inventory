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

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field handler to display detailed entity info for usage records.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_usage_entity_info")
 */
class UsageEntityInfoField extends FieldPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a UsageEntityInfoField object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
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
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['display_mode'] = ['default' => 'title'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['display_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Display mode'),
      '#options' => [
        'title' => $this->t('Content Title (with link)'),
        'entity_type' => $this->t('Entity Type'),
        'bundle' => $this->t('Content Type / Bundle'),
        'field_name' => $this->t('Field Name'),
        'field_required' => $this->t('Required Field'),
      ],
      '#default_value' => $this->options['display_mode'],
      '#description' => $this->t('Select what information to display.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Ensure entity_type, entity_id, and field_name are available.
    $this->ensureMyTable();

    // Add the required fields to the query.
    $this->aliases['entity_type'] = $this->query->addField($this->tableAlias, 'entity_type', NULL, ['function' => '']);
    $this->aliases['entity_id'] = $this->query->addField($this->tableAlias, 'entity_id', NULL, ['function' => '']);
    $this->aliases['field_name'] = $this->query->addField($this->tableAlias, 'field_name', NULL, ['function' => '']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity_type = $values->{$this->aliases['entity_type']} ?? NULL;
    $entity_id = $values->{$this->aliases['entity_id']} ?? NULL;
    $field_name = $values->{$this->aliases['field_name']} ?? NULL;

    if (!$entity_type || !$entity_id) {
      return '';
    }

    $display_mode = $this->options['display_mode'] ?? 'title';

    switch ($display_mode) {
      case 'title':
        return $this->renderTitle($entity_type, $entity_id);

      case 'entity_type':
        return $this->renderEntityType($entity_type);

      case 'bundle':
        return $this->renderBundle($entity_type, $entity_id);

      case 'field_name':
        return $this->renderFieldName($entity_type, $entity_id, $field_name);

      case 'field_required':
        return $this->renderFieldRequired($entity_type, $entity_id, $field_name);

      default:
        return '';
    }
  }

  /**
   * Renders the entity title with link.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return array|string
   *   Render array or string.
   */
  protected function renderTitle($entity_type, $entity_id) {
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        return $this->t('(Deleted: @type #@id)', [
          '@type' => $entity_type,
          '@id' => $entity_id,
        ]);
      }

      $label = $entity->label() ?: $this->t('(No title)');

      // Try to generate a link to the entity.
      if ($entity->hasLinkTemplate('canonical')) {
        $url = $entity->toUrl('canonical')->toString();
        return [
          '#markup' => '<a href="' . $url . '" target="_blank">' . htmlspecialchars($label) . '</a>',
        ];
      }
      elseif ($entity->hasLinkTemplate('edit-form')) {
        $url = $entity->toUrl('edit-form')->toString();
        return [
          '#markup' => '<a href="' . $url . '" target="_blank">' . htmlspecialchars($label) . '</a>',
        ];
      }

      return $label;
    }
    catch (\Exception $e) {
      return $this->t('(Error loading @type #@id)', [
        '@type' => $entity_type,
        '@id' => $entity_id,
      ]);
    }
  }

  /**
   * Renders the entity type label.
   *
   * @param string $entity_type
   *   The entity type ID.
   *
   * @return string
   *   The entity type label.
   */
  protected function renderEntityType($entity_type) {
    try {
      $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
      return $entity_type_definition->getLabel();
    }
    catch (\Exception $e) {
      return $entity_type;
    }
  }

  /**
   * Renders the bundle label.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return string
   *   The bundle label.
   */
  protected function renderBundle($entity_type, $entity_id) {
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        return $this->t('(Unknown)');
      }

      $bundle = $entity->bundle();
      $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($entity_type);

      if (isset($bundle_info[$bundle]['label'])) {
        return $bundle_info[$bundle]['label'];
      }

      return $bundle;
    }
    catch (\Exception $e) {
      return $this->t('(Unknown)');
    }
  }

  /**
   * Renders the field name/label.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   * @param string|null $field_name
   *   The field name.
   *
   * @return string
   *   The field label.
   */
  protected function renderFieldName($entity_type, $entity_id, $field_name) {
    if (!$field_name) {
      return $this->t('(Unknown)');
    }

    // Handle special field names.
    if ($field_name === 'direct_file') {
      return $this->t('Direct File Field');
    }
    if ($field_name === 'file_link') {
      return $this->t('File Link (in text)');
    }

    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        return $field_name;
      }

      $bundle = $entity->bundle();

      // For 'media' placeholder, find the actual media reference field(s).
      if ($field_name === 'media') {
        $media_fields = $this->findMediaReferenceFields($entity_type, $bundle);
        if (count($media_fields) === 1) {
          // Single media field - show its label.
          $field_config = $this->entityTypeManager
            ->getStorage('field_config')
            ->load($entity_type . '.' . $bundle . '.' . $media_fields[0]);
          if ($field_config) {
            return $field_config->getLabel();
          }
          return ucwords(str_replace(['field_', '_'], ['', ' '], $media_fields[0]));
        }
        elseif (count($media_fields) > 1) {
          // Multiple media fields - show generic label.
          return $this->t('Media Reference');
        }
        return $this->t('Media Reference');
      }

      // Try to get the field config for a human-readable label.
      $field_config = $this->entityTypeManager
        ->getStorage('field_config')
        ->load($entity_type . '.' . $bundle . '.' . $field_name);

      if ($field_config) {
        return $field_config->getLabel();
      }

      // Fallback: prettify the field name.
      return ucwords(str_replace(['field_', '_'], ['', ' '], $field_name));
    }
    catch (\Exception $e) {
      return $field_name;
    }
  }

  /**
   * Renders whether the field is required.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   * @param string|null $field_name
   *   The field name.
   *
   * @return array|string
   *   Render array or string indicating required status.
   */
  protected function renderFieldRequired($entity_type, $entity_id, $field_name) {
    if (!$field_name) {
      return '-';
    }

    // These special field names cannot be checked for required status.
    if (in_array($field_name, ['direct_file', 'file_link'])) {
      return '-';
    }

    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        return '-';
      }

      $bundle = $entity->bundle();

      // For 'media' placeholder, find the actual media reference field(s).
      if ($field_name === 'media') {
        $media_fields = $this->findMediaReferenceFields($entity_type, $bundle);
        foreach ($media_fields as $media_field_name) {
          $field_config = $this->entityTypeManager
            ->getStorage('field_config')
            ->load($entity_type . '.' . $bundle . '.' . $media_field_name);
          if ($field_config && $field_config->isRequired()) {
            return [
              '#markup' => '<span class="field-required" style="color: #d32f2f; font-weight: bold;">' . $this->t('Yes') . '</span>',
            ];
          }
        }
        return empty($media_fields) ? '-' : $this->t('No');
      }

      // Try to get the field config for regular fields.
      $field_config = $this->entityTypeManager
        ->getStorage('field_config')
        ->load($entity_type . '.' . $bundle . '.' . $field_name);

      if ($field_config && $field_config->isRequired()) {
        return [
          '#markup' => '<span class="field-required" style="color: #d32f2f; font-weight: bold;">' . $this->t('Yes') . '</span>',
        ];
      }

      return $this->t('No');
    }
    catch (\Exception $e) {
      return '-';
    }
  }

  /**
   * Finds media reference fields for an entity type/bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle name.
   *
   * @return array
   *   Array of field names that reference media entities.
   */
  protected function findMediaReferenceFields($entity_type, $bundle) {
    $media_fields = [];

    try {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

      foreach ($field_definitions as $field_name => $field_definition) {
        // Check if this is an entity reference field targeting media.
        if ($field_definition->getType() === 'entity_reference') {
          $settings = $field_definition->getSettings();
          if (isset($settings['target_type']) && $settings['target_type'] === 'media') {
            $media_fields[] = $field_name;
          }
        }
      }
    }
    catch (\Exception $e) {
      // Ignore errors.
    }

    return $media_fields;
  }

}
