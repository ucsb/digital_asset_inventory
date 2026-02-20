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
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\digital_asset_inventory\FilePathResolver;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A handler to dynamically generate the file URL for a digital asset.
 *
 * Generates correct URLs for the current environment at render time,
 * so that file URLs are always correct even when the database was copied
 * from a different environment (e.g., live to dev).
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("digital_asset_file_url")
 */
final class FileUrlField extends FieldPluginBase {

  use FilePathResolver;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Pre-loaded File entities keyed by fid.
   *
   * @var \Drupal\file\FileInterface[]
   */
  protected array $fileEntities = [];

  /**
   * Constructs a FileUrlField object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
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
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do not add to the query - this is a computed field.
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values) {
    // Batch-load all File entities for rows that have a fid,
    // avoiding N+1 queries during render().
    $fids = [];
    foreach ($values as $row) {
      if ($row->_entity) {
        $fid = $row->_entity->get('fid')->value;
        if (!empty($fid)) {
          $fids[$fid] = $fid;
        }
      }
    }

    if (!empty($fids)) {
      $this->fileEntities = $this->entityTypeManager
        ->getStorage('file')
        ->loadMultiple($fids);
    }
    else {
      $this->fileEntities = [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $values->_entity;
    if (!$entity) {
      return '';
    }

    $source_type = $entity->get('source_type')->value ?? '';
    $fid = $entity->get('fid')->value;
    $stored_path = $entity->get('file_path')->value ?? '';

    // For external and remote media assets, return the stored URL as-is.
    // External URLs (Google Docs, YouTube, etc.) don't change per environment.
    if ($source_type === 'external') {
      return $stored_path;
    }

    // For file_managed and media_managed assets with a fid, generate the URL
    // dynamically from the File entity's stream URI.
    if (!empty($fid) && isset($this->fileEntities[$fid])) {
      try {
        $file = $this->fileEntities[$fid];
        return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
      catch (\Exception $e) {
        // Fall through to stored path.
      }
    }

    // For filesystem_only assets (no fid), convert the stored path to a
    // stream URI, then regenerate the absolute URL.
    if ($source_type === 'filesystem_only' && !empty($stored_path)) {
      try {
        $stream_uri = $this->urlPathToStreamUri($stored_path);
        if ($stream_uri) {
          return $this->fileUrlGenerator->generateAbsoluteString($stream_uri);
        }
      }
      catch (\Exception $e) {
        // Fall through to stored path.
      }
    }

    // Fallback: return the stored file_path as-is.
    return $stored_path;
  }

}
