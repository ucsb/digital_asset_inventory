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
use Drupal\Core\Form\FormStateInterface;
use Drupal\digital_asset_inventory\Service\MediaAccessibilitySignalDetector;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Views field handler to display accessibility signals for audio/video usages.
 *
 * Displays per-usage accessibility signals (controls, captions, transcript)
 * for audio and video assets, mirroring the alt text pattern for images.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("media_signals_field")
 */
class MediaSignalsField extends FieldPluginBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The media signal detector service.
   *
   * @var \Drupal\digital_asset_inventory\Service\MediaAccessibilitySignalDetector
   */
  protected $signalDetector;

  /**
   * The asset being viewed (cached).
   *
   * @var object|null
   */
  protected $asset = NULL;

  /**
   * Whether the asset is audio or video.
   *
   * @var bool|null
   */
  protected $isAudioVideo = NULL;

  /**
   * Constructs a MediaSignalsField object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\digital_asset_inventory\Service\MediaAccessibilitySignalDetector $signal_detector
   *   The media signal detector service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    MediaAccessibilitySignalDetector $signal_detector,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->signalDetector = $signal_detector;
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
      $container->get('digital_asset_inventory.media_signal_detector')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['signal_type'] = ['default' => 'controls'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['signal_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Signal Type'),
      '#description' => $this->t('Which accessibility signal to display.'),
      '#options' => [
        'embed_type' => $this->t('Embed Type (presentation type)'),
        'controls' => $this->t('Controls'),
        'captions' => $this->t('Captions'),
        'transcript_link' => $this->t('Transcript Link'),
      ],
      '#default_value' => $this->options['signal_type'],
    ];
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
    $this->aliases['presentation_type'] = $this->query->addField($this->tableAlias, 'presentation_type');
    $this->aliases['embed_method'] = $this->query->addField($this->tableAlias, 'embed_method');
    $this->aliases['accessibility_signals'] = $this->query->addField($this->tableAlias, 'accessibility_signals');
    $this->aliases['signals_evaluated'] = $this->query->addField($this->tableAlias, 'signals_evaluated');
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    // Get signal type from options.
    $signal_type = $this->options['signal_type'] ?? 'controls';

    // Embed type applies to all asset types (documents, images, etc.).
    // Other signals (controls, captions, transcript) only apply to audio/video.
    if ($signal_type === 'embed_type') {
      $embed_method = $values->{$this->aliases['embed_method']} ?? 'field_reference';
      $presentation_type = $values->{$this->aliases['presentation_type']} ?? NULL;
      return $this->renderPresentationType($presentation_type, $embed_method);
    }

    // Check if asset is audio or video - if not, don't render signals.
    if (!$this->isAudioVideoAsset()) {
      return [];
    }

    $asset = $this->getAsset();
    if (!$asset) {
      return [];
    }

    // Get cached values from the row if available.
    $presentation_type = $values->{$this->aliases['presentation_type']} ?? NULL;
    $signals_json = $values->{$this->aliases['accessibility_signals']} ?? NULL;
    $signals_evaluated = $values->{$this->aliases['signals_evaluated']} ?? FALSE;

    // If signals haven't been evaluated, evaluate them now.
    if (!$signals_evaluated) {
      $entity_type = $values->{$this->aliases['entity_type']} ?? NULL;
      $entity_id = $values->{$this->aliases['entity_id']} ?? NULL;
      $field_name = $values->{$this->aliases['field_name']} ?? NULL;

      if ($entity_type && $entity_id) {
        try {
          $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
          if ($entity) {
            // Load the usage entity to evaluate and store signals.
            $usage_id = $values->id ?? NULL;
            if ($usage_id) {
              $usage = $this->entityTypeManager
                ->getStorage('digital_asset_usage')
                ->load($usage_id);
              if ($usage) {
                $result = $this->signalDetector->evaluateUsageSignals($usage, $entity);
                $presentation_type = $result['presentation_type'] ?? NULL;
                $signals = $result['signals'] ?? [];
                $signals_json = json_encode($signals);
              }
            }
          }
        }
        catch (\Exception $e) {
          // Fall through to render unknown state.
        }
      }
    }

    // Decode signals if we have them.
    $signals = [];
    if (!empty($signals_json)) {
      $decoded = json_decode($signals_json, TRUE);
      if (is_array($decoded)) {
        $signals = $decoded;
      }
    }

    $signal_value = $signals[$signal_type] ?? MediaAccessibilitySignalDetector::SIGNAL_UNKNOWN;
    return $this->renderSignalValue($signal_value, $signal_type);
  }

  /**
   * Renders a presentation type value.
   *
   * @param string|null $presentation_type
   *   The presentation type constant.
   * @param string $embed_method
   *   The embed method (field_reference, drupal_media, html5_video, etc.).
   *
   * @return array
   *   A render array.
   */
  protected function renderPresentationType(?string $presentation_type, string $embed_method = 'field_reference'): array {
    // Labels for presentation_type (HTML5 embeds).
    $presentation_labels = [
      MediaAccessibilitySignalDetector::AUDIO_HTML5 => $this->t('HTML5 Audio'),
      MediaAccessibilitySignalDetector::VIDEO_HTML5 => $this->t('HTML5 Video'),
      MediaAccessibilitySignalDetector::VIDEO_IFRAME_REMOTE => $this->t('Remote Iframe'),
      MediaAccessibilitySignalDetector::VIDEO_EMBED_HOSTED => $this->t('Hosted Video'),
    ];

    // Labels for embed_method (how the asset is embedded).
    $embed_labels = [
      'field_reference' => $this->t('Field Reference'),
      'drupal_media' => $this->t('Media Embed'),
      'html5_video' => $this->t('HTML5 Video'),
      'html5_audio' => $this->t('HTML5 Audio'),
      'text_link' => $this->t('Text Link'),
      'inline_image' => $this->t('Inline Image'),
      'inline_object' => $this->t('Object Embed'),
      'inline_embed' => $this->t('Embed Element'),
      'inline_iframe' => $this->t('Iframe Embed'),
      'text_url' => $this->t('Text URL'),
      'link_field' => $this->t('Link Field'),
      'menu_link' => $this->t('Menu Link'),
    ];

    // Prioritize embed_method to show HOW the asset is embedded.
    // This provides consistent labeling: "Media Embed", "Text Link", "HTML5 Video", etc.
    if (isset($embed_labels[$embed_method])) {
      $label = $embed_labels[$embed_method];
    }
    elseif ($presentation_type && isset($presentation_labels[$presentation_type])) {
      // Fall back to presentation_type if embed_method is not set.
      $label = $presentation_labels[$presentation_type];
    }
    else {
      $label = $this->t('Unknown');
    }

    $output = '<div class="signal-cell">';
    $output .= '<div class="signal-cell__embed-type">';
    $output .= htmlspecialchars($label);
    $output .= '</div>';
    $output .= '</div>';

    return [
      '#markup' => $output,
    ];
  }

  /**
   * Renders a signal value with appropriate styling.
   *
   * @param string $signal_value
   *   The signal value (detected, not_detected, unknown, not_applicable).
   * @param string $signal_type
   *   The type of signal (controls, captions, transcript_link).
   *
   * @return array
   *   A render array.
   */
  protected function renderSignalValue(string $signal_value, string $signal_type): array {
    $display_config = $this->getSignalDisplayConfig($signal_value, $signal_type);

    $output = '<div class="signal-cell">';
    $output .= '<div class="signal-cell__value signal-cell__value--' . htmlspecialchars($signal_value) . '" title="' . htmlspecialchars($display_config['tooltip']) . '">';
    $output .= htmlspecialchars($display_config['label']);
    $output .= '</div>';
    $output .= '</div>';

    return [
      '#markup' => $output,
    ];
  }

  /**
   * Gets display configuration for a signal value.
   *
   * @param string $signal_value
   *   The signal value.
   * @param string $signal_type
   *   The signal type.
   *
   * @return array
   *   Array with 'label' and 'tooltip' keys.
   */
  protected function getSignalDisplayConfig(string $signal_value, string $signal_type): array {
    $signal_labels = [
      'controls' => $this->t('Controls'),
      'captions' => $this->t('Captions'),
      'transcript_link' => $this->t('Transcript'),
    ];

    $signal_label = $signal_labels[$signal_type] ?? $signal_type;

    switch ($signal_value) {
      case MediaAccessibilitySignalDetector::SIGNAL_DETECTED:
        return [
          'label' => $this->t('Yes'),
          'tooltip' => $this->t('@signal detected in this usage context.', ['@signal' => $signal_label]),
        ];

      case MediaAccessibilitySignalDetector::SIGNAL_NOT_DETECTED:
        return [
          'label' => $this->t('No'),
          'tooltip' => $this->t('@signal not found in this usage context.', ['@signal' => $signal_label]),
        ];

      case MediaAccessibilitySignalDetector::SIGNAL_UNKNOWN:
        return [
          'label' => $this->t('Unknown'),
          'tooltip' => $this->t('Cannot determine @signal status (external platform or iframe).', ['@signal' => strtolower($signal_label)]),
        ];

      case MediaAccessibilitySignalDetector::SIGNAL_NOT_APPLICABLE:
        return [
          'label' => '—',
          'tooltip' => $this->t('@signal does not apply to this media type.', ['@signal' => $signal_label]),
        ];

      default:
        return [
          'label' => '—',
          'tooltip' => $this->t('Unknown status.'),
        ];
    }
  }

  /**
   * Checks if the current asset is audio or video.
   *
   * @return bool
   *   TRUE if the asset is audio or video.
   */
  protected function isAudioVideoAsset(): bool {
    if ($this->isAudioVideo !== NULL) {
      return $this->isAudioVideo;
    }

    $asset = $this->getAsset();
    if (!$asset) {
      $this->isAudioVideo = FALSE;
      return FALSE;
    }

    $category = $asset->get('category')->value ?? '';
    $this->isAudioVideo = in_array($category, ['Videos', 'Audio'], TRUE);

    return $this->isAudioVideo;
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
