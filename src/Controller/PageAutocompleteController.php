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

namespace Drupal\digital_asset_inventory\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for page autocomplete in manual archive form.
 *
 * Provides autocomplete suggestions by searching:
 * - Node titles
 * - Path aliases
 * - Taxonomy term names
 */
final class PageAutocompleteController extends ControllerBase {

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
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * Constructs a PageAutocompleteController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The path alias manager.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AliasManagerInterface $alias_manager,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->aliasManager = $alias_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('path_alias.manager')
    );
  }

  /**
   * Handles autocomplete requests for internal pages.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with autocomplete suggestions.
   */
  public function handleAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');

    if (!$input || strlen($input) < 2) {
      return new JsonResponse($results);
    }

    // Normalize input - remove leading slash for consistency.
    $search_term = ltrim($input, '/');

    // Search nodes by title.
    $node_results = $this->searchNodesByTitle($search_term);
    $results = array_merge($results, $node_results);

    // Search path aliases.
    $alias_results = $this->searchPathAliases($search_term);
    $results = array_merge($results, $alias_results);

    // Search taxonomy terms by name.
    $term_results = $this->searchTaxonomyTerms($search_term);
    $results = array_merge($results, $term_results);

    // Remove duplicates (same path from different searches).
    $results = $this->deduplicateResults($results);

    // Limit results.
    $results = array_slice($results, 0, 15);

    return new JsonResponse($results);
  }

  /**
   * Searches nodes by title.
   *
   * @param string $search_term
   *   The search term.
   *
   * @return array
   *   Array of autocomplete result items.
   */
  protected function searchNodesByTitle($search_term) {
    $results = [];

    try {
      $node_storage = $this->entityTypeManager->getStorage('node');
      $query = $node_storage->getQuery()
        ->condition('title', '%' . $this->database->escapeLike($search_term) . '%', 'LIKE')
        ->condition('status', 1)
        ->accessCheck(TRUE)
        ->range(0, 10);

      $nids = $query->execute();

      if (!empty($nids)) {
        $nodes = $node_storage->loadMultiple($nids);
        foreach ($nodes as $node) {
          $path = '/node/' . $node->id();
          $alias = $this->aliasManager->getAliasByPath($path);

          // Use alias if available, otherwise use system path.
          $display_path = ($alias !== $path) ? $alias : $path;

          $results[] = [
            'value' => $display_path,
            'label' => $node->getTitle() . ' (' . $display_path . ')',
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Log error but continue with other searches.
      \Drupal::logger('digital_asset_inventory')->warning('Node search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Searches path aliases.
   *
   * @param string $search_term
   *   The search term.
   *
   * @return array
   *   Array of autocomplete result items.
   */
  protected function searchPathAliases($search_term) {
    $results = [];

    try {
      // Search path_alias table directly for better alias matching.
      $query = $this->database->select('path_alias', 'pa')
        ->fields('pa', ['path', 'alias'])
        ->condition('pa.alias', '%' . $this->database->escapeLike($search_term) . '%', 'LIKE')
        ->condition('pa.status', 1)
        ->range(0, 10);

      $aliases = $query->execute()->fetchAll();

      foreach ($aliases as $alias_record) {
        $alias = $alias_record->alias;
        $path = $alias_record->path;

        // Try to get a title for the path.
        $title = $this->getTitleForPath($path);

        if ($title) {
          $results[] = [
            'value' => $alias,
            'label' => $title . ' (' . $alias . ')',
          ];
        }
        else {
          $results[] = [
            'value' => $alias,
            'label' => $alias,
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Log error but continue.
      \Drupal::logger('digital_asset_inventory')->warning('Path alias search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Searches taxonomy terms by name.
   *
   * @param string $search_term
   *   The search term.
   *
   * @return array
   *   Array of autocomplete result items.
   */
  protected function searchTaxonomyTerms($search_term) {
    $results = [];

    try {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $query = $term_storage->getQuery()
        ->condition('name', '%' . $this->database->escapeLike($search_term) . '%', 'LIKE')
        ->condition('status', 1)
        ->accessCheck(TRUE)
        ->range(0, 10);

      $tids = $query->execute();

      if (!empty($tids)) {
        $terms = $term_storage->loadMultiple($tids);
        foreach ($terms as $term) {
          $path = '/taxonomy/term/' . $term->id();
          $alias = $this->aliasManager->getAliasByPath($path);

          // Use alias if available, otherwise use system path.
          $display_path = ($alias !== $path) ? $alias : $path;

          $results[] = [
            'value' => $display_path,
            'label' => $term->getName() . ' (' . $display_path . ')',
          ];
        }
      }
    }
    catch (\Exception $e) {
      // Log error but continue.
      \Drupal::logger('digital_asset_inventory')->warning('Taxonomy term search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Gets the title for a system path.
   *
   * @param string $path
   *   The system path (e.g., /node/123).
   *
   * @return string|null
   *   The title, or NULL if not found.
   */
  protected function getTitleForPath($path) {
    // Extract entity type and ID from path.
    if (preg_match('#^/node/(\d+)$#', $path, $matches)) {
      try {
        $node = $this->entityTypeManager->getStorage('node')->load($matches[1]);
        if ($node) {
          return $node->getTitle();
        }
      }
      catch (\Exception $e) {
        // Entity not found.
      }
    }
    elseif (preg_match('#^/taxonomy/term/(\d+)$#', $path, $matches)) {
      try {
        $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($matches[1]);
        if ($term) {
          return $term->getName();
        }
      }
      catch (\Exception $e) {
        // Entity not found.
      }
    }

    return NULL;
  }

  /**
   * Removes duplicate results based on value (path).
   *
   * @param array $results
   *   Array of autocomplete result items.
   *
   * @return array
   *   Deduplicated array of results.
   */
  protected function deduplicateResults(array $results) {
    $seen = [];
    $deduplicated = [];

    foreach ($results as $result) {
      $value = $result['value'];
      if (!isset($seen[$value])) {
        $seen[$value] = TRUE;
        $deduplicated[] = $result;
      }
    }

    return $deduplicated;
  }

}
