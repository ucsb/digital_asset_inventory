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

namespace Drupal\digital_asset_inventory\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;

/**
 * Service for evaluating alt text status per usage context.
 *
 * In Drupal, Media image alt text is shared across all Media references,
 * but images embedded directly in content can define alt text per usage.
 *
 * This service evaluates alt text for different usage scenarios:
 * - Media reference fields: Alt text from media.field_media_image.alt
 * - Inline <img> tags in text fields: Alt text from HTML alt attribute
 * - Template-rendered: Cannot be evaluated statically
 *
 * IMPORTANT: Fail-safe behavior - if alt text source cannot be confidently
 * determined, return 'not_evaluated'. Never guess or infer compliance.
 */
class AltTextEvaluator {

  /**
   * Alt text status: Alt text was detected.
   */
  const STATUS_DETECTED = 'detected';

  /**
   * Alt text status: Alt text was not detected (empty or missing).
   */
  const STATUS_NOT_DETECTED = 'not_detected';

  /**
   * Alt text status: Decorative image (alt="").
   */
  const STATUS_DECORATIVE = 'decorative';

  /**
   * Alt text status: Could not evaluate (template-controlled or unknown).
   */
  const STATUS_NOT_EVALUATED = 'not_evaluated';

  /**
   * Alt text status: Not applicable (linked file, not displayed image).
   */
  const STATUS_NOT_APPLICABLE = 'not_applicable';

  /**
   * Alt text source: Content override (media embed with alt defined on content).
   */
  const SOURCE_CONTENT_OVERRIDE = 'content_override';

  /**
   * Alt text source: Inline image (<img> tag in text field).
   */
  const SOURCE_INLINE_IMAGE = 'inline_image';

  /**
   * Alt text source: Media field (shared from Media entity).
   */
  const SOURCE_MEDIA_FIELD = 'media_field';

  /**
   * Alt text source: Template-controlled (cannot be evaluated).
   */
  const SOURCE_TEMPLATE = 'template';

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an AltTextEvaluator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Evaluates alt text for a specific usage context.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity where the asset is used.
   * @param string $field_name
   *   The field name where the asset is used.
   *
   * @return array
   *   An array with keys:
   *   - 'status': One of the STATUS_* constants.
   *   - 'text': The alt text if detected, NULL otherwise.
   *   - 'source': 'media_field', 'inline_img', 'template', or NULL.
   *   - 'truncated_text': Truncated alt text for display (max 120 chars).
   */
  public function evaluateForUsage($asset, EntityInterface $entity, string $field_name): array {
    $result = [
      'status' => self::STATUS_NOT_EVALUATED,
      'text' => NULL,
      'source' => NULL,
      'truncated_text' => NULL,
    ];

    // Check if the field is a media reference field.
    if ($this->isMediaReferenceField($entity, $field_name)) {
      return $this->evaluateMediaReferenceField($asset, $entity, $field_name);
    }

    // Check if this is an image field (direct file upload with alt text).
    if ($this->isImageField($entity, $field_name)) {
      return $this->evaluateImageField($asset, $entity, $field_name);
    }

    // Check if this is a text field where we can parse inline images.
    if ($this->isTextField($entity, $field_name)) {
      return $this->evaluateTextFieldUsage($asset, $entity, $field_name);
    }

    // Check if this is a paragraph reference field.
    // Usage tracking often stores the parent entity with the paragraph reference field,
    // not the actual media/text field inside the paragraph.
    if ($this->isParagraphReferenceField($entity, $field_name)) {
      return $this->evaluateParagraphField($asset, $entity, $field_name);
    }

    // Field not found on the entity - it might be on a paragraph.
    // Usage tracking sometimes stores the parent node with a field name
    // that actually exists on a paragraph within the node.
    if (!$entity->hasField($field_name)) {
      $paragraphResult = $this->findFieldInParagraphs($asset, $entity, $field_name);
      if ($paragraphResult !== NULL) {
        return $paragraphResult;
      }
    }

    // Field not found or not a recognized type.
    // Try searching all fields on the entity for images.
    $imageResult = $this->searchAllFieldsForImage($asset, $entity);
    if ($imageResult !== NULL) {
      return $imageResult;
    }

    // Check if this is an image asset that wasn't found as a displayed image.
    // This means it's likely linked (via <a> tag) rather than displayed (via <img>).
    $mime_type = $asset->get('mime_type')->value ?? '';
    if (str_starts_with($mime_type, 'image/')) {
      return [
        'status' => self::STATUS_NOT_APPLICABLE,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    // For non-image assets or unknown field types, return not_evaluated.
    $result['source'] = self::SOURCE_TEMPLATE;
    return $result;
  }

  /**
   * Checks if a field is a paragraph reference field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if the field is a paragraph reference field.
   */
  protected function isParagraphReferenceField(EntityInterface $entity, string $field_name): bool {
    if (!$entity->hasField($field_name)) {
      return FALSE;
    }

    $field_definition = $entity->getFieldDefinition($field_name);
    if (!$field_definition) {
      return FALSE;
    }

    $field_type = $field_definition->getType();
    if ($field_type !== 'entity_reference_revisions' && $field_type !== 'entity_reference') {
      return FALSE;
    }

    $settings = $field_definition->getSettings();
    return ($settings['target_type'] ?? '') === 'paragraph';
  }

  /**
   * Evaluates alt text for media within a paragraph reference field.
   *
   * Searches through referenced paragraphs to find the media field
   * that matches our asset and evaluates its alt text.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the paragraph reference field.
   * @param string $field_name
   *   The paragraph reference field name.
   *
   * @return array
   *   Result array with status, text, and source.
   */
  protected function evaluateParagraphField($asset, EntityInterface $entity, string $field_name): array {
    $media_id = $asset->get('media_id')->value ?? NULL;
    $file_id = $asset->get('fid')->value ?? NULL;

    if (!$entity->hasField($field_name)) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    // Collect all paragraphs first.
    $paragraphs = [];
    foreach ($field as $item) {
      $paragraph = $item->entity ?? NULL;
      if ($paragraph) {
        $paragraphs[] = $paragraph;
      }
    }

    // PASS 1: Check ALL paragraphs for media reference fields first.
    // This ensures media fields (shared alt text) are found before
    // text fields which might have content overrides.
    if ($media_id) {
      foreach ($paragraphs as $paragraph) {
        $mediaResult = $this->searchParagraphMediaFields($asset, $paragraph);
        if ($mediaResult !== NULL) {
          return $mediaResult;
        }
      }
    }

    // PASS 2: Check ALL paragraphs for image fields.
    if ($file_id) {
      foreach ($paragraphs as $paragraph) {
        $imageResult = $this->searchParagraphImageFields($asset, $paragraph);
        if ($imageResult !== NULL) {
          return $imageResult;
        }
      }
    }

    // PASS 3: Check ALL paragraphs for text fields (drupal-media embeds, img tags).
    foreach ($paragraphs as $paragraph) {
      $textResult = $this->searchEntityTextFields($asset, $paragraph);
      if ($textResult !== NULL) {
        return $textResult;
      }
    }

    // PASS 4: Check nested paragraphs.
    foreach ($paragraphs as $paragraph) {
      $nestedResult = $this->searchParagraphTextFields($asset, $paragraph);
      if ($nestedResult !== NULL) {
        return $nestedResult;
      }
    }

    return [
      'status' => self::STATUS_NOT_EVALUATED,
      'text' => NULL,
      'source' => self::SOURCE_TEMPLATE,
      'truncated_text' => NULL,
    ];
  }

  /**
   * Finds a specific field in paragraphs attached to an entity.
   *
   * Usage tracking sometimes stores the parent node with a field name
   * that actually exists on a paragraph. This method searches through
   * all paragraphs to find the specific field.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The parent entity (e.g., node).
   * @param string $field_name
   *   The field name to find.
   *
   * @return array|null
   *   Result array if found, NULL otherwise.
   */
  protected function findFieldInParagraphs($asset, EntityInterface $entity, string $field_name): ?array {
    $field_definitions = $entity->getFieldDefinitions();
    $media_id = $asset->get('media_id')->value ?? NULL;
    $file_id = $asset->get('fid')->value ?? NULL;

    foreach ($field_definitions as $para_field_name => $definition) {
      // Check if this is a paragraph reference field.
      if ($definition->getType() !== 'entity_reference_revisions' &&
          $definition->getType() !== 'entity_reference') {
        continue;
      }

      $settings = $definition->getSettings();
      if (($settings['target_type'] ?? '') !== 'paragraph') {
        continue;
      }

      if (!$entity->hasField($para_field_name)) {
        continue;
      }

      $field = $entity->get($para_field_name);
      if ($field->isEmpty()) {
        continue;
      }

      // Search through paragraphs for the specific field.
      foreach ($field as $item) {
        $paragraph = $item->entity ?? NULL;
        if (!$paragraph) {
          continue;
        }

        // Check if this paragraph has the field we're looking for.
        if ($paragraph->hasField($field_name)) {
          // Found the field - evaluate it based on its type.
          // Only return if the evaluation succeeds (asset is actually in this field).
          if ($this->isMediaReferenceField($paragraph, $field_name)) {
            // Verify this field contains our media.
            if ($this->fieldContainsMedia($paragraph, $field_name, $media_id)) {
              return $this->evaluateMediaReferenceField($asset, $paragraph, $field_name);
            }
          }
          elseif ($this->isImageField($paragraph, $field_name)) {
            // Verify this field contains our file.
            if ($this->fieldContainsFile($paragraph, $field_name, $file_id)) {
              return $this->evaluateImageField($asset, $paragraph, $field_name);
            }
          }
          elseif ($this->isTextField($paragraph, $field_name)) {
            // For text fields, try to evaluate and check if we get a result.
            $result = $this->evaluateTextFieldUsage($asset, $paragraph, $field_name);
            if ($result['status'] !== self::STATUS_NOT_EVALUATED) {
              return $result;
            }
          }
        }

        // Also check nested paragraphs.
        $nestedResult = $this->findFieldInParagraphs($asset, $paragraph, $field_name);
        if ($nestedResult !== NULL) {
          return $nestedResult;
        }
      }
    }

    return NULL;
  }

  /**
   * Checks if a media reference field contains a specific media ID.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param string $field_name
   *   The field name.
   * @param int|string|null $media_id
   *   The media ID to look for.
   *
   * @return bool
   *   TRUE if the field contains the media.
   */
  protected function fieldContainsMedia(EntityInterface $entity, string $field_name, $media_id): bool {
    if (!$media_id || !$entity->hasField($field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      return FALSE;
    }

    foreach ($field as $item) {
      try {
        $target_id = $item->get('target_id')->getValue();
        if ($target_id == $media_id) {
          return TRUE;
        }
      }
      catch (\Exception $e) {
        continue;
      }
    }

    return FALSE;
  }

  /**
   * Checks if an image field contains a specific file ID.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param string $field_name
   *   The field name.
   * @param int|string|null $file_id
   *   The file ID to look for.
   *
   * @return bool
   *   TRUE if the field contains the file.
   */
  protected function fieldContainsFile(EntityInterface $entity, string $field_name, $file_id): bool {
    if (!$file_id || !$entity->hasField($field_name)) {
      return FALSE;
    }

    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      return FALSE;
    }

    foreach ($field as $item) {
      try {
        $target_id = $item->get('target_id')->getValue();
        if ($target_id == $file_id) {
          return TRUE;
        }
      }
      catch (\Exception $e) {
        continue;
      }
    }

    return FALSE;
  }

  /**
   * Searches media reference fields in a paragraph for the asset.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $paragraph
   *   The paragraph entity to search.
   *
   * @return array|null
   *   Result array if found, NULL otherwise.
   */
  protected function searchParagraphMediaFields($asset, EntityInterface $paragraph): ?array {
    $media_id = $asset->get('media_id')->value ?? NULL;
    if (!$media_id) {
      return NULL;
    }

    $field_definitions = $paragraph->getFieldDefinitions();

    foreach ($field_definitions as $field_name => $definition) {
      // Check if this is a media reference field.
      if (!$this->isMediaReferenceField($paragraph, $field_name)) {
        continue;
      }

      if (!$paragraph->hasField($field_name)) {
        continue;
      }

      $field = $paragraph->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      // Check if this field references our media.
      foreach ($field as $item) {
        $target_id = $item->get('target_id')->getValue();
        if ($target_id == $media_id) {
          // Found the media reference - evaluate it.
          return $this->evaluateMediaReferenceField($asset, $paragraph, $field_name);
        }
      }
    }

    return NULL;
  }

  /**
   * Searches image fields in a paragraph for the asset.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $paragraph
   *   The paragraph entity to search.
   *
   * @return array|null
   *   Result array if found, NULL otherwise.
   */
  protected function searchParagraphImageFields($asset, EntityInterface $paragraph): ?array {
    $file_id = $asset->get('fid')->value ?? NULL;
    if (!$file_id) {
      return NULL;
    }

    $field_definitions = $paragraph->getFieldDefinitions();

    foreach ($field_definitions as $field_name => $definition) {
      // Check if this is an image field.
      if ($definition->getType() !== 'image') {
        continue;
      }

      if (!$paragraph->hasField($field_name)) {
        continue;
      }

      $field = $paragraph->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      // Check if this field references our file.
      foreach ($field as $item) {
        $target_id = NULL;
        try {
          $target_id = $item->get('target_id')->getValue();
        }
        catch (\Exception $e) {
          continue;
        }

        if ($target_id == $file_id) {
          // Found the image field - evaluate it.
          return $this->evaluateImageField($asset, $paragraph, $field_name);
        }
      }
    }

    return NULL;
  }

  /**
   * Searches all text fields on an entity for image references.
   *
   * This is a fallback for when the specific field_name doesn't match
   * or isn't recognized, which can happen with paragraph-based embeds.
   *
   * Handles both:
   * - drupal-media embeds (for Media images)
   * - img tags (for file-managed images)
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to search.
   *
   * @return array|null
   *   Result array if a matching image is found, NULL otherwise.
   */
  protected function searchAllTextFieldsForMedia($asset, EntityInterface $entity): ?array {
    // First, search text fields directly on this entity.
    $result = $this->searchEntityTextFields($asset, $entity);
    if ($result !== NULL) {
      return $result;
    }

    // If not found, check if this entity has paragraph reference fields
    // and search within those paragraphs.
    $result = $this->searchParagraphTextFields($asset, $entity);
    if ($result !== NULL) {
      return $result;
    }

    return NULL;
  }

  /**
   * Searches text fields directly on an entity for drupal-media embeds.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to search.
   *
   * @return array|null
   *   Result array if a matching drupal-media embed is found, NULL otherwise.
   */
  protected function searchEntityTextFields($asset, EntityInterface $entity): ?array {
    $field_definitions = $entity->getFieldDefinitions();
    $media_id = $asset->get('media_id')->value ?? NULL;
    $file_path = $asset->get('file_path')->value ?? '';
    $file_name = $asset->get('file_name')->value ?? '';

    foreach ($field_definitions as $field_name => $definition) {
      $field_type = $definition->getType();

      // Check if this is a text field type.
      $text_types = [
        'text',
        'text_long',
        'text_with_summary',
        'string_long',
      ];

      if (!in_array($field_type, $text_types)) {
        continue;
      }

      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      // Check all field values (for multi-value fields).
      foreach ($field as $item) {
        $value = $item->getValue();
        $html = $value['value'] ?? '';

        if (empty($html)) {
          continue;
        }

        // Check if HTML contains potential image references.
        $hasDrupalMedia = strpos($html, '<drupal-media') !== FALSE;
        $hasImg = strpos($html, '<img') !== FALSE;

        if (!$hasDrupalMedia && !$hasImg) {
          continue;
        }

        // Parse the HTML.
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // For media assets, check drupal-media embeds first.
        if ($media_id && $hasDrupalMedia) {
          $result = $this->parseDrupalMediaEmbed($dom, $asset);
          if ($result !== NULL) {
            return $result;
          }
        }

        // For all image assets (media or file), check img tags.
        if ($hasImg) {
          $result = $this->parseImgTags($dom, $file_path, $file_name);
          if ($result !== NULL) {
            return $result;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Parses img tags in HTML to find alt text for a specific file.
   *
   * @param \DOMDocument $dom
   *   The parsed DOM document.
   * @param string $file_path
   *   The file path/URL to match.
   * @param string $file_name
   *   The file name to match.
   *
   * @return array|null
   *   Result array if a matching img tag is found, NULL otherwise.
   */
  protected function parseImgTags(\DOMDocument $dom, string $file_path, string $file_name): ?array {
    if (empty($file_path) && empty($file_name)) {
      return NULL;
    }

    $images = $dom->getElementsByTagName('img');

    foreach ($images as $img) {
      $src = $img->getAttribute('src');

      // Check if this img references our file.
      if ($this->srcMatchesFile($src, $file_path, $file_name)) {
        // Found the image - check its alt attribute.
        if (!$img->hasAttribute('alt')) {
          // No alt attribute at all.
          return [
            'status' => self::STATUS_NOT_DETECTED,
            'text' => NULL,
            'source' => self::SOURCE_INLINE_IMAGE,
            'truncated_text' => NULL,
          ];
        }

        $alt = $img->getAttribute('alt');
        return $this->buildAltTextResult($alt, self::SOURCE_INLINE_IMAGE);
      }
    }

    return NULL;
  }

  /**
   * Searches paragraph reference fields for drupal-media embeds.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to search.
   *
   * @return array|null
   *   Result array if a matching drupal-media embed is found, NULL otherwise.
   */
  protected function searchParagraphTextFields($asset, EntityInterface $entity): ?array {
    $field_definitions = $entity->getFieldDefinitions();

    foreach ($field_definitions as $field_name => $definition) {
      // Check if this is an entity reference field targeting paragraphs.
      if ($definition->getType() !== 'entity_reference_revisions' &&
          $definition->getType() !== 'entity_reference') {
        continue;
      }

      $settings = $definition->getSettings();
      $target_type = $settings['target_type'] ?? '';

      if ($target_type !== 'paragraph') {
        continue;
      }

      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      // Iterate through referenced paragraphs.
      foreach ($field as $item) {
        $paragraph = $item->entity ?? NULL;
        if (!$paragraph) {
          continue;
        }

        // Recursively search this paragraph's text fields.
        $result = $this->searchEntityTextFields($asset, $paragraph);
        if ($result !== NULL) {
          return $result;
        }

        // Also check nested paragraph references.
        $nestedResult = $this->searchParagraphTextFields($asset, $paragraph);
        if ($nestedResult !== NULL) {
          return $nestedResult;
        }
      }
    }

    return NULL;
  }

  /**
   * Gets alt text from a Media entity's image field.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return array
   *   Result array with status, text, and source.
   */
  public function getMediaAltText(MediaInterface $media): array {
    $result = [
      'status' => self::STATUS_NOT_EVALUATED,
      'text' => NULL,
      'source' => 'media_field',
      'truncated_text' => NULL,
    ];

    // Get the source field for the media type.
    $source = $media->getSource();
    $source_field = $source->getConfiguration()['source_field'] ?? NULL;

    if (!$source_field || !$media->hasField($source_field)) {
      return $result;
    }

    $field = $media->get($source_field);
    if ($field->isEmpty()) {
      return $result;
    }

    // Check if this is an image field with alt text.
    $first_item = $field->first();
    if (!$first_item) {
      return $result;
    }

    // Only image fields have alt text — file fields (PDF, video, etc.) do not.
    $field_type = $field->getFieldDefinition()->getType();
    if ($field_type !== 'image') {
      return $result;
    }

    // Get alt text from the image field.
    $alt = $first_item->get('alt')->getValue();

    return $this->buildAltTextResult($alt, self::SOURCE_MEDIA_FIELD);
  }

  /**
   * Evaluates alt text for a media reference field usage.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity where the asset is used.
   * @param string $field_name
   *   The field name where the asset is used.
   *
   * @return array
   *   Result array with status, text, and source.
   */
  protected function evaluateMediaReferenceField($asset, EntityInterface $entity, string $field_name): array {
    $media_id = $asset->get('media_id')->value ?? NULL;

    if (!$media_id) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    // Load the Media entity first to get its default alt text.
    $media = NULL;
    $media_alt = NULL;
    try {
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if ($media instanceof MediaInterface) {
        $media_result = $this->getMediaAltText($media);
        $media_alt = $media_result['text'] ?? NULL;
      }
    }
    catch (\Exception $e) {
      // Continue without media alt text.
    }

    // Check for alt text on the field item.
    // Media Library can store alt text on the reference field, but we need to
    // compare it with the Media's alt to determine if it's truly an override.
    if ($entity->hasField($field_name)) {
      $field = $entity->get($field_name);
      if (!$field->isEmpty()) {
        foreach ($field as $item) {
          // Check if this item references our media.
          $target_id = NULL;
          try {
            $target_id = $item->get('target_id')->getValue();
          }
          catch (\Exception $e) {
            continue;
          }

          if ($target_id == $media_id) {
            // Check for alt on the field item.
            $field_alt = NULL;
            try {
              $field_alt = $item->get('alt')->getValue();
            }
            catch (\Exception $e) {
              // Field may not have alt property.
            }

            if (!empty($field_alt)) {
              // Compare with Media's alt text.
              // If they're different, it's a content override.
              // If they're the same, report as "from media".
              if ($media_alt !== NULL && $field_alt === $media_alt) {
                // Same as Media's alt - not an override.
                return $this->buildAltTextResult($field_alt, self::SOURCE_MEDIA_FIELD);
              }
              else {
                // Different from Media's alt - content override.
                return $this->buildAltTextResult($field_alt, self::SOURCE_CONTENT_OVERRIDE);
              }
            }
          }
        }
      }
    }

    // Fall back to Media entity's alt text.
    if ($media instanceof MediaInterface) {
      return $this->getMediaAltText($media);
    }

    return [
      'status' => self::STATUS_NOT_EVALUATED,
      'text' => NULL,
      'source' => self::SOURCE_MEDIA_FIELD,
      'truncated_text' => NULL,
    ];
  }

  /**
   * Evaluates alt text for inline images in a text field.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity where the asset is used.
   * @param string $field_name
   *   The field name where the asset is used.
   *
   * @return array
   *   Result array with status, text, and source.
   */
  protected function evaluateTextFieldUsage($asset, EntityInterface $entity, string $field_name): array {
    if (!$entity->hasField($field_name)) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    // Get the file path/URI to search for in the HTML.
    $file_path = $asset->get('file_path')->value ?? '';
    $file_name = $asset->get('file_name')->value ?? '';

    // Get the text content.
    $first_item = $field->first();
    if (!$first_item) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    $value = $first_item->getValue();
    $html = $value['value'] ?? '';

    if (empty($html)) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    // Parse HTML and find img tags or drupal-media embeds referencing this file.
    return $this->parseAltFromHtml($html, $file_path, $file_name, $asset);
  }

  /**
   * Parses alt text from HTML content for a specific file.
   *
   * @param string $html
   *   The HTML content to parse.
   * @param string $file_path
   *   The file path/URL to search for.
   * @param string $file_name
   *   The file name to search for.
   * @param object|null $asset
   *   The digital asset item entity (optional, for media UUID matching).
   *
   * @return array
   *   Result array with status, text, and source.
   */
  protected function parseAltFromHtml(string $html, string $file_path, string $file_name, $asset = NULL): array {
    $result = [
      'status' => self::STATUS_NOT_EVALUATED,
      'text' => NULL,
      'source' => self::SOURCE_INLINE_IMAGE,
      'truncated_text' => NULL,
    ];

    if (empty($html) || (empty($file_path) && empty($file_name))) {
      return $result;
    }

    // Use DOMDocument to parse HTML.
    $dom = new \DOMDocument();
    // Suppress warnings for malformed HTML.
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    // First, check for drupal-media embeds (CKEditor media).
    $drupalMediaResult = $this->parseDrupalMediaEmbed($dom, $asset);
    if ($drupalMediaResult !== NULL) {
      return $drupalMediaResult;
    }

    // Check for regular <img> tags.
    $images = $dom->getElementsByTagName('img');

    foreach ($images as $img) {
      $src = $img->getAttribute('src');

      // Check if this img references our file.
      if ($this->srcMatchesFile($src, $file_path, $file_name)) {
        // Found the image - check its alt attribute.
        if (!$img->hasAttribute('alt')) {
          // No alt attribute at all.
          $result['status'] = self::STATUS_NOT_DETECTED;
          return $result;
        }

        $alt = $img->getAttribute('alt');
        return $this->buildAltTextResult($alt, self::SOURCE_INLINE_IMAGE);
      }
    }

    return $result;
  }

  /**
   * Parses alt text from drupal-media embeds in CKEditor content.
   *
   * In CKEditor, when you override alt text on a media embed, it's stored
   * as the `alt` attribute on the `<drupal-media>` element.
   *
   * @param \DOMDocument $dom
   *   The parsed DOM document.
   * @param object|null $asset
   *   The digital asset item entity.
   *
   * @return array|null
   *   Result array if a matching drupal-media embed is found, NULL otherwise.
   */
  protected function parseDrupalMediaEmbed(\DOMDocument $dom, $asset = NULL): ?array {
    if (!$asset) {
      return NULL;
    }

    $media_id = $asset->get('media_id')->value ?? NULL;
    if (!$media_id) {
      return NULL;
    }

    // Get the Media entity to find its UUID.
    try {
      $media = $this->entityTypeManager->getStorage('media')->load($media_id);
      if (!$media) {
        return NULL;
      }
      $media_uuid = $media->uuid();
    }
    catch (\Exception $e) {
      return NULL;
    }

    // Find drupal-media elements using XPath.
    $xpath = new \DOMXPath($dom);
    // Note: drupal-media is a custom element, so we search by tag name.
    $mediaElements = $xpath->query('//drupal-media');

    foreach ($mediaElements as $element) {
      $uuid = $element->getAttribute('data-entity-uuid');

      // Check if this element references our media.
      if ($uuid === $media_uuid) {
        // Check for alt override on the drupal-media element.
        // CKEditor stores the override in the `alt` attribute.
        if ($element->hasAttribute('alt')) {
          $alt = $element->getAttribute('alt');
          return $this->buildAltTextResult($alt, self::SOURCE_CONTENT_OVERRIDE);
        }

        // No override - fall back to Media entity's alt text.
        if ($media instanceof MediaInterface) {
          return $this->getMediaAltText($media);
        }
      }
    }

    return NULL;
  }

  /**
   * Checks if an img src matches the given file.
   *
   * @param string $src
   *   The img src attribute value.
   * @param string $file_path
   *   The file path/URL to match.
   * @param string $file_name
   *   The file name to match.
   *
   * @return bool
   *   TRUE if the src references the file.
   */
  protected function srcMatchesFile(string $src, string $file_path, string $file_name): bool {
    if (empty($src)) {
      return FALSE;
    }

    // Direct path match.
    if (!empty($file_path) && strpos($src, $file_path) !== FALSE) {
      return TRUE;
    }

    // File name match (for relative paths).
    if (!empty($file_name) && basename($src) === $file_name) {
      return TRUE;
    }

    // URL-decoded match.
    $decoded_src = urldecode($src);
    if (!empty($file_path) && strpos($decoded_src, $file_path) !== FALSE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Builds a standardized alt text result array.
   *
   * @param string|null $alt
   *   The alt text value.
   * @param string $source
   *   The source of the alt text.
   *
   * @return array
   *   Result array with status, text, source, and truncated_text.
   */
  protected function buildAltTextResult(?string $alt, string $source): array {
    $result = [
      'text' => $alt,
      'source' => $source,
      'truncated_text' => NULL,
    ];

    if ($alt === NULL) {
      // No alt attribute at all.
      $result['status'] = self::STATUS_NOT_DETECTED;
    }
    elseif ($alt === '') {
      // Empty alt - decorative image.
      $result['status'] = self::STATUS_DECORATIVE;
    }
    else {
      // Has alt text.
      $result['status'] = self::STATUS_DETECTED;
      // Truncate for display (max 120 chars).
      if (mb_strlen($alt) > 120) {
        $result['truncated_text'] = mb_substr($alt, 0, 117) . '...';
      }
      else {
        $result['truncated_text'] = $alt;
      }
    }

    return $result;
  }

  /**
   * Checks if a field is a media reference field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if the field is a media reference field.
   */
  protected function isMediaReferenceField(EntityInterface $entity, string $field_name): bool {
    // Handle the 'media' placeholder used by the scanner.
    if ($field_name === 'media') {
      return TRUE;
    }

    if (!$entity->hasField($field_name)) {
      return FALSE;
    }

    $field_definition = $entity->getFieldDefinition($field_name);
    if (!$field_definition) {
      return FALSE;
    }

    $field_type = $field_definition->getType();
    // Media reference fields can be entity_reference or entity_reference_revisions.
    if ($field_type !== 'entity_reference' && $field_type !== 'entity_reference_revisions') {
      return FALSE;
    }

    $settings = $field_definition->getSettings();
    return ($settings['target_type'] ?? '') === 'media';
  }

  /**
   * Checks if a field is an image field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if the field is an image field.
   */
  protected function isImageField(EntityInterface $entity, string $field_name): bool {
    if (!$entity->hasField($field_name)) {
      return FALSE;
    }

    $field_definition = $entity->getFieldDefinition($field_name);
    if (!$field_definition) {
      return FALSE;
    }

    return $field_definition->getType() === 'image';
  }

  /**
   * Evaluates alt text for an image field.
   *
   * Image fields store alt text directly on the field item.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the image field.
   * @param string $field_name
   *   The image field name.
   *
   * @return array
   *   Result array with status, text, and source.
   */
  protected function evaluateImageField($asset, EntityInterface $entity, string $field_name): array {
    $file_id = $asset->get('fid')->value ?? NULL;

    if (!$entity->hasField($field_name)) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    $field = $entity->get($field_name);
    if ($field->isEmpty()) {
      return [
        'status' => self::STATUS_NOT_EVALUATED,
        'text' => NULL,
        'source' => NULL,
        'truncated_text' => NULL,
      ];
    }

    // Check each item in the field (for multi-value fields).
    foreach ($field as $item) {
      // Check if this item references our file.
      $target_id = NULL;
      try {
        $target_id = $item->get('target_id')->getValue();
      }
      catch (\Exception $e) {
        continue;
      }

      if ($file_id && $target_id == $file_id) {
        // Found our file - get the alt text.
        $alt = NULL;
        try {
          $alt = $item->get('alt')->getValue();
        }
        catch (\Exception $e) {
          // Field may not have alt property.
        }

        return $this->buildAltTextResult($alt, self::SOURCE_INLINE_IMAGE);
      }
    }

    // File not found in this field.
    return [
      'status' => self::STATUS_NOT_EVALUATED,
      'text' => NULL,
      'source' => NULL,
      'truncated_text' => NULL,
    ];
  }

  /**
   * Searches all fields on an entity for image references.
   *
   * Checks both image fields and text fields.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to search.
   *
   * @return array|null
   *   Result array if a matching image is found, NULL otherwise.
   */
  protected function searchAllFieldsForImage($asset, EntityInterface $entity): ?array {
    $file_id = $asset->get('fid')->value ?? NULL;
    $field_definitions = $entity->getFieldDefinitions();

    // First, search image fields directly on this entity.
    foreach ($field_definitions as $field_name => $definition) {
      if ($definition->getType() === 'image') {
        $result = $this->evaluateImageField($asset, $entity, $field_name);
        if ($result['status'] !== self::STATUS_NOT_EVALUATED) {
          return $result;
        }
      }
    }

    // Then, search text fields for inline images / media embeds.
    $result = $this->searchEntityTextFields($asset, $entity);
    if ($result !== NULL) {
      return $result;
    }

    // Check paragraph reference fields.
    $result = $this->searchParagraphsForImage($asset, $entity);
    if ($result !== NULL) {
      return $result;
    }

    return NULL;
  }

  /**
   * Searches paragraph reference fields for image fields.
   *
   * @param object $asset
   *   The digital asset item entity.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to search.
   *
   * @return array|null
   *   Result array if a matching image is found, NULL otherwise.
   */
  protected function searchParagraphsForImage($asset, EntityInterface $entity): ?array {
    $field_definitions = $entity->getFieldDefinitions();

    foreach ($field_definitions as $field_name => $definition) {
      // Check if this is an entity reference field targeting paragraphs.
      if ($definition->getType() !== 'entity_reference_revisions' &&
          $definition->getType() !== 'entity_reference') {
        continue;
      }

      $settings = $definition->getSettings();
      $target_type = $settings['target_type'] ?? '';

      if ($target_type !== 'paragraph') {
        continue;
      }

      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      // Iterate through referenced paragraphs.
      foreach ($field as $item) {
        $paragraph = $item->entity ?? NULL;
        if (!$paragraph) {
          continue;
        }

        // Recursively search this paragraph.
        $result = $this->searchAllFieldsForImage($asset, $paragraph);
        if ($result !== NULL) {
          return $result;
        }
      }
    }

    return NULL;
  }

  /**
   * Checks if a field is a text field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if the field is a text field.
   */
  protected function isTextField(EntityInterface $entity, string $field_name): bool {
    if (!$entity->hasField($field_name)) {
      return FALSE;
    }

    $field_definition = $entity->getFieldDefinition($field_name);
    if (!$field_definition) {
      return FALSE;
    }

    $field_type = $field_definition->getType();
    $text_types = [
      'text',
      'text_long',
      'text_with_summary',
      'string',
      'string_long',
    ];

    return in_array($field_type, $text_types);
  }

  /**
   * Gets the source label for display in the Alt Text column.
   *
   * These are neutral, factual labels per the UI specification:
   * - content_override: "(content override)"
   * - inline_image: "(inline image)"
   * - media_field: "(from media)"
   * - template: "Managed by template"
   *
   * @param string|null $source
   *   The source constant.
   *
   * @return string
   *   A human-readable source label.
   */
  public function getSourceLabel(?string $source): string {
    $labels = [
      self::SOURCE_CONTENT_OVERRIDE => t('content override'),
      self::SOURCE_INLINE_IMAGE => t('inline image'),
      self::SOURCE_MEDIA_FIELD => t('from media'),
      self::SOURCE_TEMPLATE => t('managed by template'),
    ];

    return $labels[$source] ?? '';
  }

  /**
   * Gets the tooltip text for a source type.
   *
   * @param string|null $source
   *   The source constant.
   *
   * @return string
   *   Tooltip text explaining the source.
   */
  public function getSourceTooltip(?string $source): string {
    $tooltips = [
      self::SOURCE_CONTENT_OVERRIDE => t('Alt text defined on this content item and overrides the Media default.'),
      self::SOURCE_INLINE_IMAGE => t('Alt text provided directly in this content field.'),
      self::SOURCE_MEDIA_FIELD => t('Alt text from the Media entity, shared across all usages.'),
      self::SOURCE_TEMPLATE => t('Alt text is generated by site templates and cannot be evaluated here.'),
    ];

    return $tooltips[$source] ?? '';
  }

  /**
   * Gets a human-readable status label.
   *
   * Uses compliance-safe language (detected/not detected, not pass/fail).
   *
   * @param string $status
   *   The status constant.
   *
   * @return string
   *   A human-readable label.
   */
  public function getStatusLabel(string $status): string {
    $labels = [
      self::STATUS_DETECTED => t('Alt text detected'),
      self::STATUS_NOT_DETECTED => t('Alt text not detected'),
      self::STATUS_DECORATIVE => t('Decorative image'),
      self::STATUS_NOT_EVALUATED => t('Not evaluated'),
    ];

    return $labels[$status] ?? t('Unknown');
  }

}
