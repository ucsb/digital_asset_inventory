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

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\digital_asset_inventory\Service\AltTextEvaluator;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views field handler to display alt text status per usage row.
 *
 * In Drupal, Media image alt text is shared across all Media references,
 * but images embedded directly in content can define alt text per usage.
 *
 * This field evaluates and displays alt text for each specific usage context.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("usage_alt_text_field")
 */
class UsageAltTextField extends FieldPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The alt text evaluator service.
   *
   * @var \Drupal\digital_asset_inventory\Service\AltTextEvaluator
   */
  protected $altTextEvaluator;

  /**
   * The asset being viewed (cached).
   *
   * @var object|null
   */
  protected $asset = NULL;

  /**
   * Whether the asset is an image.
   *
   * @var bool|null
   */
  protected $isImage = NULL;

  /**
   * Constructs a UsageAltTextField object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\digital_asset_inventory\Service\AltTextEvaluator $alt_text_evaluator
   *   The alt text evaluator service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AltTextEvaluator $alt_text_evaluator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->altTextEvaluator = $alt_text_evaluator;
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
      $container->get('digital_asset_inventory.alt_text_evaluator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Ensure we have the fields we need.
    $this->ensureMyTable();
    $this->aliases['asset_id'] = $this->query->addField($this->tableAlias, 'asset_id');
    $this->aliases['entity_type'] = $this->query->addField($this->tableAlias, 'entity_type');
    $this->aliases['entity_id'] = $this->query->addField($this->tableAlias, 'entity_id');
    $this->aliases['field_name'] = $this->query->addField($this->tableAlias, 'field_name');
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Check if asset is an image - if not, don't render.
    if (!$this->isImageAsset()) {
      return [];
    }

    $asset = $this->getAsset();
    if (!$asset) {
      return [];
    }

    // Get usage context.
    $entity_type = $values->{$this->aliases['entity_type']} ?? NULL;
    $entity_id = $values->{$this->aliases['entity_id']} ?? NULL;
    $field_name = $values->{$this->aliases['field_name']} ?? NULL;

    if (!$entity_type || !$entity_id || !$field_name) {
      return $this->renderStatus(AltTextEvaluator::STATUS_NOT_EVALUATED);
    }

    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        return $this->renderStatus(AltTextEvaluator::STATUS_NOT_EVALUATED);
      }

      $result = $this->altTextEvaluator->evaluateForUsage($asset, $entity, $field_name);
      return $this->renderResult($result);
    }
    catch (\Exception $e) {
      return $this->renderStatus(AltTextEvaluator::STATUS_NOT_EVALUATED);
    }
  }

  /**
   * Renders an alt text evaluation result.
   *
   * UI Specification:
   * - Value first, source label second
   * - Detected: show alt text + "(source)" on secondary line
   * - Decorative: "Decorative image" (italic, muted)
   * - Template: "Managed by template" (italic, muted)
   * - Not evaluated: "Not evaluated" (muted, not italic)
   *
   * @param array $result
   *   The evaluation result from AltTextEvaluator.
   *
   * @return array
   *   A render array.
   */
  protected function renderResult(array $result): array {
    $status = $result['status'] ?? AltTextEvaluator::STATUS_NOT_EVALUATED;
    $text = $result['truncated_text'] ?? $result['text'] ?? NULL;
    $full_text = $result['text'] ?? NULL;
    $source = $result['source'] ?? NULL;

    $output = '<div class="alt-cell">';

    switch ($status) {
      case AltTextEvaluator::STATUS_DETECTED:
        // Value first: show the alt text.
        if ($text) {
          $output .= '<div class="alt-cell__value">';
          // Add tooltip for full text if truncated.
          if ($full_text && $full_text !== $text) {
            $output .= '<span title="' . htmlspecialchars($full_text) . '">' . htmlspecialchars($text) . '</span>';
          }
          else {
            $output .= htmlspecialchars($text);
          }
          $output .= '</div>';

          // Source label second: "(content override)", "(inline image)", "(from media)".
          if ($source) {
            $source_label = $this->altTextEvaluator->getSourceLabel($source);
            $source_tooltip = $this->altTextEvaluator->getSourceTooltip($source);
            if ($source_label) {
              $output .= '<div class="alt-cell__source">';
              $output .= '<span title="' . htmlspecialchars($source_tooltip) . '">(' . htmlspecialchars($source_label) . ')</span>';
              $output .= '</div>';
            }
          }
        }
        else {
          // Edge case: detected but no text (shouldn't happen).
          $output .= '<div class="alt-cell__value">' . $this->t('Alt text detected') . '</div>';
        }
        break;

      case AltTextEvaluator::STATUS_DECORATIVE:
        // Decorative image: italic, muted.
        $output .= '<div class="alt-cell__decorative" title="' . htmlspecialchars($this->t('Image is marked as decorative (empty alt attribute).')) . '">';
        $output .= $this->t('Decorative image');
        $output .= '</div>';
        break;

      case AltTextEvaluator::STATUS_NOT_EVALUATED:
        if ($source === AltTextEvaluator::SOURCE_TEMPLATE) {
          // Template-controlled: italic, muted.
          $output .= '<div class="alt-cell__template" title="' . htmlspecialchars($this->t('Alt text is generated by site templates and cannot be evaluated here.')) . '">';
          $output .= $this->t('Managed by template');
          $output .= '</div>';
        }
        else {
          // Unknown: muted, not italic.
          $output .= '<div class="alt-cell__unknown" title="' . htmlspecialchars($this->t('Alt text could not be reliably detected for this usage.')) . '">';
          $output .= $this->t('Not evaluated');
          $output .= '</div>';
        }
        break;

      case AltTextEvaluator::STATUS_NOT_DETECTED:
        // Not detected: muted, not italic.
        $output .= '<div class="alt-cell__missing" title="' . htmlspecialchars($this->t('No alt text was found for this image usage.')) . '">';
        $output .= $this->t('Not detected');
        $output .= '</div>';
        break;

      case AltTextEvaluator::STATUS_NOT_APPLICABLE:
        // Not applicable: linked file, not displayed image. Show dash.
        $output .= '<div class="alt-cell__na" title="' . htmlspecialchars($this->t('This file is linked, not displayed as an image. Alt text does not apply.')) . '">';
        $output .= '–';
        $output .= '</div>';
        break;

      default:
        // Unknown status: show dash.
        $output .= '<div class="alt-cell__na">';
        $output .= '–';
        $output .= '</div>';
        break;
    }

    $output .= '</div>';

    return [
      '#markup' => $output,
    ];
  }

  /**
   * Renders a simple status.
   *
   * @param string $status
   *   The status constant.
   *
   * @return array
   *   A render array.
   */
  protected function renderStatus(string $status): array {
    return $this->renderResult(['status' => $status]);
  }

  /**
   * Checks if the current asset is an image.
   *
   * @return bool
   *   TRUE if the asset is an image.
   */
  protected function isImageAsset(): bool {
    if ($this->isImage !== NULL) {
      return $this->isImage;
    }

    $asset = $this->getAsset();
    if (!$asset) {
      $this->isImage = FALSE;
      return FALSE;
    }

    $mime_type = $asset->get('mime_type')->value ?? '';
    $this->isImage = str_starts_with($mime_type, 'image/');

    return $this->isImage;
  }

  /**
   * Gets the asset entity being viewed.
   *
   * @return object|null
   *   The asset entity, or NULL if not found.
   */
  protected function getAsset() {
    if ($this->asset !== NULL) {
      return $this->asset;
    }

    // Get asset_id from view argument.
    $asset_id = $this->view->args[0] ?? NULL;
    if (!$asset_id) {
      return NULL;
    }

    try {
      $this->asset = $this->entityTypeManager
        ->getStorage('digital_asset_item')
        ->load($asset_id);
    }
    catch (\Exception $e) {
      $this->asset = NULL;
    }

    return $this->asset;
  }

}
