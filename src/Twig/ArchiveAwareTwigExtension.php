<?php

namespace Drupal\digital_asset_inventory\Twig;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig extension for archive-aware URL handling.
 *
 * Provides filters and functions for templates that render file/media URLs
 * directly, bypassing Drupal's field rendering system.
 */
class ArchiveAwareTwigExtension extends AbstractExtension {

  /**
   * The archive service.
   *
   * @var \Drupal\digital_asset_inventory\Service\ArchiveService
   */
  protected $archiveService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ArchiveAwareTwigExtension.
   *
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ArchiveService $archive_service, EntityTypeManagerInterface $entity_type_manager) {
    $this->archiveService = $archive_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      new TwigFilter('archive_aware_url', [$this, 'getArchiveAwareUrl']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('archive_aware_url', [$this, 'getArchiveAwareUrl']),
      new TwigFunction('is_archived', [$this, 'isArchived']),
    ];
  }

  /**
   * Returns the archive detail URL if archived, otherwise the original URL.
   *
   * Usage in templates:
   *   {{ node.field_cv|archive_aware_url }}
   *   {{ node.field_document.entity|archive_aware_url }}
   *   {{ media|archive_aware_url }}
   *
   * @param mixed $input
   *   A file entity, media entity, field item list, or file URI string.
   *
   * @return string
   *   The archive detail URL if the file is archived, otherwise the file URL.
   */
  public function getArchiveAwareUrl($input) {
    if (empty($input)) {
      return '';
    }

    // Skip if routing is disabled.
    if (!$this->archiveService->isLinkRoutingEnabled()) {
      return $this->getOriginalUrl($input);
    }

    // Extract file entity from various input types.
    $file = $this->extractFile($input);

    if (!$file) {
      return $this->getOriginalUrl($input);
    }

    // Skip images.
    $mime_type = $file->getMimeType();
    if ($mime_type && strpos($mime_type, 'image/') === 0) {
      return $this->getOriginalUrl($input);
    }

    // Check if file is archived.
    $archive = $this->archiveService->getActiveArchiveByFid($file->id());

    if ($archive) {
      return '/archive-registry/' . $archive->id();
    }

    return $this->getOriginalUrl($input);
  }

  /**
   * Checks if a file or media entity is archived.
   *
   * Usage in templates:
   *   {% if is_archived(node.field_cv) %}(Archived){% endif %}
   *
   * @param mixed $input
   *   A file entity, media entity, field item list, or file URI string.
   *
   * @return bool
   *   TRUE if the file is archived, FALSE otherwise.
   */
  public function isArchived($input) {
    if (empty($input)) {
      return FALSE;
    }

    // Skip if routing is disabled.
    if (!$this->archiveService->isLinkRoutingEnabled()) {
      return FALSE;
    }

    // Extract file entity from various input types.
    $file = $this->extractFile($input);

    if (!$file) {
      return FALSE;
    }

    // Check if file is archived.
    $archive = $this->archiveService->getActiveArchiveByFid($file->id());

    return $archive !== NULL;
  }

  /**
   * Extracts a file entity from various input types.
   *
   * @param mixed $input
   *   A file entity, media entity, field item list, or file URI string.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity, or NULL if not found.
   */
  protected function extractFile($input) {
    // Direct file entity.
    if ($input instanceof FileInterface) {
      return $input;
    }

    // Media entity - get its source file.
    if ($input instanceof MediaInterface) {
      return $this->getFileFromMedia($input);
    }

    // Field item list (e.g., node.field_cv).
    if ($input instanceof \Drupal\Core\Field\FieldItemListInterface) {
      $item = $input->first();
      if ($item) {
        // File field.
        if (isset($item->entity) && $item->entity instanceof FileInterface) {
          return $item->entity;
        }
        // Media reference field.
        if (isset($item->entity) && $item->entity instanceof MediaInterface) {
          return $this->getFileFromMedia($item->entity);
        }
        // Try target_id for lazy-loaded references.
        if (isset($item->target_id)) {
          $target_type = $input->getFieldDefinition()->getSetting('target_type');
          if ($target_type === 'file') {
            return $this->entityTypeManager->getStorage('file')->load($item->target_id);
          }
          if ($target_type === 'media') {
            $media = $this->entityTypeManager->getStorage('media')->load($item->target_id);
            if ($media) {
              return $this->getFileFromMedia($media);
            }
          }
        }
      }
    }

    // Array with entity key.
    if (is_array($input) && isset($input['entity'])) {
      return $this->extractFile($input['entity']);
    }

    return NULL;
  }

  /**
   * Gets the source file from a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The source file, or NULL if not found.
   */
  protected function getFileFromMedia(MediaInterface $media) {
    $source = $media->getSource();
    $source_field = $source->getSourceFieldDefinition($media->bundle->entity);

    if (!$source_field) {
      return NULL;
    }

    $source_field_name = $source_field->getName();

    if (!$media->hasField($source_field_name)) {
      return NULL;
    }

    $field_value = $media->get($source_field_name)->getValue();
    if (empty($field_value[0]['target_id'])) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage('file')->load($field_value[0]['target_id']);
  }

  /**
   * Gets the original URL for the input.
   *
   * @param mixed $input
   *   The input value.
   *
   * @return string
   *   The original file URL.
   */
  protected function getOriginalUrl($input) {
    // File entity.
    if ($input instanceof FileInterface) {
      return \Drupal::service('file_url_generator')->generateAbsoluteString($input->getFileUri());
    }

    // Media entity.
    if ($input instanceof MediaInterface) {
      $file = $this->getFileFromMedia($input);
      if ($file) {
        return \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      }
      return '';
    }

    // Field item list.
    if ($input instanceof \Drupal\Core\Field\FieldItemListInterface) {
      $item = $input->first();
      if ($item && isset($item->entity) && $item->entity instanceof FileInterface) {
        return \Drupal::service('file_url_generator')->generateAbsoluteString($item->entity->getFileUri());
      }
      if ($item && isset($item->entity) && $item->entity instanceof MediaInterface) {
        $file = $this->getFileFromMedia($item->entity);
        if ($file) {
          return \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        }
      }
    }

    // String (assume it's already a URL or URI).
    if (is_string($input)) {
      if (strpos($input, '://') !== FALSE && strpos($input, 'http') !== 0) {
        // Stream wrapper URI.
        return \Drupal::service('file_url_generator')->generateAbsoluteString($input);
      }
      return $input;
    }

    return '';
  }

}
