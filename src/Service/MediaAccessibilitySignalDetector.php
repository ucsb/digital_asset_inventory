<?php

/**
 * @file
 * Digital Asset Inventory & Archive Management module.
 *
 * Provides digital asset scanning, usage tracking, and
 * ADA Title II-compliant archiving tools for Drupal sites.
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

namespace Drupal\digital_asset_inventory\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\digital_asset_inventory\Entity\DigitalAssetUsage;

/**
 * Service for detecting accessibility signals in audio/video usage contexts.
 *
 * Signals are evaluated per-usage, not per-asset, mirroring the alt-text
 * pattern used for images. Uses compliance-safe language ("detected",
 * "not detected", "unknown") rather than pass/fail terminology.
 *
 * Key signals detected:
 * - controls: Player controls are present
 * - captions: Captions/subtitles are available
 * - transcript_link: Link to transcript is nearby
 */
class MediaAccessibilitySignalDetector {

  /**
   * Presentation type: HTML5 audio element.
   */
  const AUDIO_HTML5 = 'AUDIO_HTML5';

  /**
   * Presentation type: HTML5 video element (local file).
   */
  const VIDEO_HTML5 = 'VIDEO_HTML5';

  /**
   * Presentation type: Remote video via iframe (YouTube, Vimeo).
   */
  const VIDEO_IFRAME_REMOTE = 'VIDEO_IFRAME_REMOTE';

  /**
   * Presentation type: Hosted video via Media Library.
   */
  const VIDEO_EMBED_HOSTED = 'VIDEO_EMBED_HOSTED';

  /**
   * Signal value: Signal is present in the embedding context.
   */
  const SIGNAL_DETECTED = 'detected';

  /**
   * Signal value: Signal is not present (checked and absent).
   */
  const SIGNAL_NOT_DETECTED = 'not_detected';

  /**
   * Signal value: Cannot determine (e.g., iframe content, external platform).
   */
  const SIGNAL_UNKNOWN = 'unknown';

  /**
   * Signal value: Signal doesn't apply to this presentation type.
   */
  const SIGNAL_NOT_APPLICABLE = 'not_applicable';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a MediaAccessibilitySignalDetector object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
  }

  /**
   * Evaluate accessibility signals for a usage context.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetUsage $usage
   *   The usage record to evaluate.
   * @param \Drupal\Core\Entity\EntityInterface|null $parent_entity
   *   The parent entity containing the usage (optional, for context).
   *
   * @return array
   *   Array with keys:
   *   - 'presentation_type': string (AUDIO_HTML5, VIDEO_HTML5, etc.)
   *   - 'signals': array of signal_name => signal_value
   */
  public function evaluateUsageSignals(DigitalAssetUsage $usage, ?EntityInterface $parent_entity = NULL): array {
    $result = [
      'presentation_type' => '',
      'signals' => [
        'controls' => self::SIGNAL_UNKNOWN,
        'captions' => self::SIGNAL_UNKNOWN,
        'transcript_link' => self::SIGNAL_UNKNOWN,
      ],
    ];

    try {
      // Load the asset to determine category.
      $asset_id = $usage->get('asset_id')->target_id ?? NULL;
      if (!$asset_id) {
        return $result;
      }

      $asset = $this->entityTypeManager->getStorage('digital_asset_item')->load($asset_id);
      if (!$asset) {
        return $result;
      }

      $category = $asset->get('category')->value ?? '';
      if (!in_array($category, ['Audio', 'Videos'])) {
        // Not an audio/video asset, signals don't apply.
        return $result;
      }

      // Load parent entity if not provided.
      if (!$parent_entity) {
        $entity_type = $usage->get('entity_type')->value ?? NULL;
        $entity_id = $usage->get('entity_id')->value ?? NULL;
        if ($entity_type && $entity_id) {
          try {
            $parent_entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
          }
          catch (\Exception $e) {
            // Entity type storage may not exist.
          }
        }
      }

      $field_name = $usage->get('field_name')->value ?? '';

      // Determine presentation type.
      $presentation_type = $this->determinePresentationType($field_name, $parent_entity, $asset);
      $result['presentation_type'] = $presentation_type;

      // Build context for signal detection.
      $context = $this->buildDetectionContext($parent_entity, $field_name, $asset);

      // Detect signals based on presentation type.
      $result['signals']['controls'] = $this->detectControlsSignal($presentation_type, $context);
      $result['signals']['captions'] = $this->detectCaptionsSignal($presentation_type, $context, $category);
      $result['signals']['transcript_link'] = $this->detectTranscriptLinkSignal($presentation_type, $context);
    }
    catch (\Exception $e) {
      // Fail-safe: return unknown for all signals.
      \Drupal::logger('digital_asset_inventory')->warning('Signal detection error: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $result;
  }

  /**
   * Determine presentation type from field and media context.
   *
   * @param string $field_name
   *   The field name containing the media reference.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity containing the field.
   * @param object $asset
   *   The asset being referenced.
   *
   * @return string
   *   Presentation type constant (AUDIO_HTML5, VIDEO_HTML5, etc.)
   */
  public function determinePresentationType(string $field_name, ?EntityInterface $entity, $asset): string {
    $category = $asset->get('category')->value ?? '';
    $source_type = $asset->get('source_type')->value ?? '';

    // Check if external (YouTube, Vimeo, etc.).
    if ($source_type === 'external') {
      return self::VIDEO_IFRAME_REMOTE;
    }

    // Check asset category.
    if ($category === 'Audio') {
      return self::AUDIO_HTML5;
    }

    if ($category === 'Videos') {
      // Check if this is a Media entity with oEmbed source.
      $media_id = $asset->get('media_id')->value ?? NULL;
      if ($media_id) {
        try {
          $media = $this->entityTypeManager->getStorage('media')->load($media_id);
          if ($media) {
            $source = $media->getSource();
            $source_plugin_id = $source ? $source->getPluginId() : '';
            // oEmbed sources indicate remote embeds.
            if (strpos($source_plugin_id, 'oembed') !== FALSE) {
              return self::VIDEO_IFRAME_REMOTE;
            }
            // Video file in Media indicates hosted.
            return self::VIDEO_EMBED_HOSTED;
          }
        }
        catch (\Exception $e) {
          // Fall through to default.
        }
      }

      // Default for local video files.
      return self::VIDEO_HTML5;
    }

    // Fallback.
    return self::VIDEO_HTML5;
  }

  /**
   * Builds detection context with rendered markup and field info.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The parent entity.
   * @param string $field_name
   *   The field name.
   * @param object $asset
   *   The asset.
   *
   * @return array
   *   Context array with 'markup', 'text_fields', 'field_config'.
   */
  protected function buildDetectionContext(?EntityInterface $entity, string $field_name, $asset): array {
    $context = [
      'markup' => '',
      'text_fields' => [],
      'field_config' => [],
    ];

    if (!$entity) {
      return $context;
    }

    // Try to get rendered markup of the specific field.
    if ($entity->hasField($field_name)) {
      try {
        $field = $entity->get($field_name);
        $field_definition = $entity->getFieldDefinition($field_name);
        $context['field_config'] = $field_definition ? $field_definition->getSettings() : [];

        // Render the field to get HTML output.
        $render_array = $field->view(['label' => 'hidden']);
        if (!empty($render_array)) {
          $context['markup'] = (string) $this->renderer->renderPlain($render_array);
        }
      }
      catch (\Exception $e) {
        // Continue without rendered markup.
      }
    }

    // Collect text fields for transcript link scanning.
    $context['text_fields'] = $this->collectTextFieldValues($entity);

    return $context;
  }

  /**
   * Collects text field values from an entity for transcript scanning.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to scan.
   *
   * @return array
   *   Array of text field values.
   */
  protected function collectTextFieldValues(EntityInterface $entity): array {
    $text_values = [];

    $field_definitions = $entity->getFieldDefinitions();
    $text_types = ['text', 'text_long', 'text_with_summary', 'string_long'];

    foreach ($field_definitions as $field_name => $definition) {
      if (!in_array($definition->getType(), $text_types)) {
        continue;
      }

      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      foreach ($field as $item) {
        $value = $item->getValue();
        if (!empty($value['value'])) {
          $text_values[] = $value['value'];
        }
      }
    }

    // Also check paragraph fields.
    foreach ($field_definitions as $field_name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions' &&
          $definition->getType() !== 'entity_reference') {
        continue;
      }

      $settings = $definition->getSettings();
      if (($settings['target_type'] ?? '') !== 'paragraph') {
        continue;
      }

      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      foreach ($field as $item) {
        $paragraph = $item->entity ?? NULL;
        if ($paragraph) {
          $paragraph_texts = $this->collectTextFieldValues($paragraph);
          $text_values = array_merge($text_values, $paragraph_texts);
        }
      }
    }

    return $text_values;
  }

  /**
   * Detect controls signal.
   *
   * @param string $presentation_type
   *   The presentation type.
   * @param array $context
   *   Additional context (rendered markup, field config, etc.)
   *
   * @return string
   *   Signal value: detected, not_detected, unknown, or not_applicable.
   */
  public function detectControlsSignal(string $presentation_type, array $context): string {
    $markup = $context['markup'] ?? '';

    switch ($presentation_type) {
      case self::AUDIO_HTML5:
        // Check for controls attribute on audio element.
        if (preg_match('/<audio[^>]*\bcontrols\b/i', $markup)) {
          return self::SIGNAL_DETECTED;
        }
        // If we have markup but no controls, it's not detected.
        if (!empty($markup) && preg_match('/<audio/i', $markup)) {
          return self::SIGNAL_NOT_DETECTED;
        }
        return self::SIGNAL_UNKNOWN;

      case self::VIDEO_HTML5:
      case self::VIDEO_EMBED_HOSTED:
        // Check for controls attribute on video element.
        if (preg_match('/<video[^>]*\bcontrols\b/i', $markup)) {
          return self::SIGNAL_DETECTED;
        }
        // If we have markup but no controls, it's not detected.
        if (!empty($markup) && preg_match('/<video/i', $markup)) {
          return self::SIGNAL_NOT_DETECTED;
        }
        return self::SIGNAL_UNKNOWN;

      case self::VIDEO_IFRAME_REMOTE:
        // Cannot inspect iframe content.
        return self::SIGNAL_UNKNOWN;
    }

    return self::SIGNAL_UNKNOWN;
  }

  /**
   * Detect captions signal.
   *
   * @param string $presentation_type
   *   The presentation type.
   * @param array $context
   *   Additional context (rendered markup, track elements, etc.)
   * @param string $category
   *   The asset category (Audio or Videos).
   *
   * @return string
   *   Signal value: detected, not_detected, unknown, or not_applicable.
   */
  public function detectCaptionsSignal(string $presentation_type, array $context, string $category = 'Videos'): string {
    // Captions don't apply to audio.
    if ($category === 'Audio' || $presentation_type === self::AUDIO_HTML5) {
      return self::SIGNAL_NOT_APPLICABLE;
    }

    $markup = $context['markup'] ?? '';

    switch ($presentation_type) {
      case self::VIDEO_HTML5:
      case self::VIDEO_EMBED_HOSTED:
        // Check for <track> elements with captions or subtitles.
        if (preg_match('/<track[^>]*kind=["\']?(captions|subtitles)/i', $markup)) {
          return self::SIGNAL_DETECTED;
        }
        // If we have video markup but no track, it's not detected.
        if (!empty($markup) && preg_match('/<video/i', $markup)) {
          return self::SIGNAL_NOT_DETECTED;
        }
        return self::SIGNAL_UNKNOWN;

      case self::VIDEO_IFRAME_REMOTE:
        // Cannot inspect external platform captions.
        return self::SIGNAL_UNKNOWN;
    }

    return self::SIGNAL_UNKNOWN;
  }

  /**
   * Detect transcript link signal.
   *
   * Uses heuristics to find nearby transcript links.
   *
   * @param string $presentation_type
   *   The presentation type.
   * @param array $context
   *   Additional context (surrounding content, field siblings, etc.)
   *
   * @return string
   *   Signal value: detected, not_detected, unknown.
   */
  public function detectTranscriptLinkSignal(string $presentation_type, array $context): string {
    $text_fields = $context['text_fields'] ?? [];

    // Keywords to match in link text or URL.
    $transcript_patterns = [
      'transcript',
      'transcription',
      'text version',
      'full text',
      'read along',
    ];

    $pattern = implode('|', array_map('preg_quote', $transcript_patterns));

    // Search surrounding text fields for transcript links.
    foreach ($text_fields as $field_value) {
      // Check for href containing transcript keywords.
      if (preg_match('/href=["\'][^"\']*(' . $pattern . ')/i', $field_value)) {
        return self::SIGNAL_DETECTED;
      }
      // Check for link text containing transcript keywords.
      if (preg_match('/>([^<]*(' . $pattern . ')[^<]*)</i', $field_value)) {
        return self::SIGNAL_DETECTED;
      }
    }

    // If we scanned text fields but found nothing.
    if (!empty($text_fields)) {
      return self::SIGNAL_NOT_DETECTED;
    }

    return self::SIGNAL_UNKNOWN;
  }

  /**
   * Get aggregated signal summary for an asset across all usages.
   *
   * @param object $asset
   *   The asset to summarize.
   *
   * @return array
   *   Array with keys:
   *   - 'total_usages': int
   *   - 'evaluated_usages': int
   *   - 'signals': array of signal_name => [
   *       'detected' => count,
   *       'not_detected' => count,
   *       'unknown' => count,
   *     ]
   */
  public function getAssetSignalSummary($asset): array {
    $summary = [
      'total_usages' => 0,
      'evaluated_usages' => 0,
      'signals' => [
        'controls' => ['detected' => 0, 'not_detected' => 0, 'unknown' => 0, 'not_applicable' => 0],
        'captions' => ['detected' => 0, 'not_detected' => 0, 'unknown' => 0, 'not_applicable' => 0],
        'transcript_link' => ['detected' => 0, 'not_detected' => 0, 'unknown' => 0, 'not_applicable' => 0],
      ],
    ];

    $asset_id = $asset->id();
    if (!$asset_id) {
      return $summary;
    }

    try {
      $usage_storage = $this->entityTypeManager->getStorage('digital_asset_usage');
      $usage_ids = $usage_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('asset_id', $asset_id)
        ->execute();

      if (empty($usage_ids)) {
        return $summary;
      }

      $usages = $usage_storage->loadMultiple($usage_ids);
      $summary['total_usages'] = count($usages);

      foreach ($usages as $usage) {
        $signals = $usage->getAccessibilitySignals();
        if (empty($signals)) {
          continue;
        }

        $summary['evaluated_usages']++;

        foreach (['controls', 'captions', 'transcript_link'] as $signal_name) {
          $value = $signals[$signal_name] ?? self::SIGNAL_UNKNOWN;
          if (isset($summary['signals'][$signal_name][$value])) {
            $summary['signals'][$signal_name][$value]++;
          }
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('digital_asset_inventory')->warning('Error getting signal summary: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $summary;
  }

  /**
   * Gets a human-readable label for a presentation type.
   *
   * @param string $type
   *   The presentation type constant.
   *
   * @return string
   *   A human-readable label.
   */
  public function getPresentationTypeLabel(string $type): string {
    $labels = [
      self::AUDIO_HTML5 => t('HTML5 Audio'),
      self::VIDEO_HTML5 => t('HTML5 Video'),
      self::VIDEO_IFRAME_REMOTE => t('Remote Iframe'),
      self::VIDEO_EMBED_HOSTED => t('Hosted Video'),
    ];

    return $labels[$type] ?? t('Unknown');
  }

  /**
   * Gets a human-readable label for a signal value.
   *
   * @param string $value
   *   The signal value constant.
   *
   * @return string
   *   A human-readable label.
   */
  public function getSignalValueLabel(string $value): string {
    $labels = [
      self::SIGNAL_DETECTED => t('Yes'),
      self::SIGNAL_NOT_DETECTED => t('No'),
      self::SIGNAL_UNKNOWN => t('Unknown'),
      self::SIGNAL_NOT_APPLICABLE => '-',
    ];

    return $labels[$value] ?? t('Unknown');
  }

  /**
   * Gets a tooltip for a signal value.
   *
   * @param string $value
   *   The signal value constant.
   * @param string $signal_name
   *   The signal name (controls, captions, transcript_link).
   *
   * @return string
   *   Tooltip text.
   */
  public function getSignalValueTooltip(string $value, string $signal_name): string {
    $tooltips = [
      self::SIGNAL_DETECTED => t('@signal detected in this usage context.', ['@signal' => ucfirst(str_replace('_', ' ', $signal_name))]),
      self::SIGNAL_NOT_DETECTED => t('@signal not found in this usage context.', ['@signal' => ucfirst(str_replace('_', ' ', $signal_name))]),
      self::SIGNAL_UNKNOWN => t('Cannot determine (external platform or iframe).'),
      self::SIGNAL_NOT_APPLICABLE => t('Signal does not apply to this media type.'),
    ];

    return $tooltips[$value] ?? '';
  }

  /**
   * Checks if an asset category supports accessibility signals.
   *
   * @param string $category
   *   The asset category.
   *
   * @return bool
   *   TRUE if the category supports signals.
   */
  public function categorySupportsSignals(string $category): bool {
    return in_array($category, ['Audio', 'Videos']);
  }

}
