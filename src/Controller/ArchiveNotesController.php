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
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Form\AddArchiveNoteForm;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for archive notes pages.
 *
 * Provides a dedicated page for viewing and adding internal notes
 * to archived items. Notes are append-only and admin-only.
 */
final class ArchiveNotesController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The archive service.
   *
   * @var \Drupal\digital_asset_inventory\Service\ArchiveService
   */
  protected $archiveService;

  /**
   * Constructs an ArchiveNotesController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
    AccountProxyInterface $current_user,
    FormBuilderInterface $form_builder,
    ArchiveService $archive_service,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->currentUser = $current_user;
    $this->formBuilder = $form_builder;
    $this->archiveService = $archive_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('current_user'),
      $container->get('form_builder'),
      $container->get('digital_asset_inventory.archive')
    );
  }

  /**
   * Returns the notes page title.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $digital_asset_archive
   *   The archive entity.
   *
   * @return string
   *   The page title.
   */
  public function title(DigitalAssetArchive $digital_asset_archive) {
    return $this->t('Internal Notes (Admin Only)');
  }

  /**
   * Returns the notes page.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $digital_asset_archive
   *   The archive entity.
   *
   * @return array
   *   A render array for the notes page.
   */
  public function page(DigitalAssetArchive $digital_asset_archive) {
    // Defensive access check - allow either full archive access or view-only.
    $has_full_access = $this->currentUser->hasPermission('archive digital assets');
    $has_view_access = $this->currentUser->hasPermission('view digital asset archives');
    if (!$has_full_access && !$has_view_access) {
      throw new AccessDeniedHttpException();
    }

    // Build archive info section (combines file/entry info with archive details).
    $archive_info = $this->buildFileInfoSection($digital_asset_archive);

    // Get initial note from the archive entity.
    $initial_note = trim((string) $digital_asset_archive->get('internal_notes')->value);

    // Load notes log entries with pagination.
    $notes = $this->loadNotes($digital_asset_archive);

    // Build add note form if user has full archive permission.
    // Users with view-only access can see notes but not add them.
    $add_form = NULL;
    if ($this->currentUser->hasPermission('archive digital assets')) {
      $add_form = $this->formBuilder->getForm(AddArchiveNoteForm::class, $digital_asset_archive);
    }

    return [
      '#theme' => 'archive_notes_page',
      '#archive_info' => $archive_info,
      '#initial_note' => $initial_note,
      '#notes' => $notes,
      '#add_form' => $add_form,
      '#attached' => [
        'library' => [
          'digital_asset_inventory/admin',
        ],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $digital_asset_archive->getCacheTags(),
      ],
    ];
  }

  /**
   * Builds the archive information section.
   *
   * Combines file/entry information with archive details in a single section.
   * Displays different fields based on whether this is a file-based archive
   * or a manual entry (page/external URL).
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive
   *   The archive entity.
   *
   * @return array
   *   A render array for the archive info details element.
   */
  protected function buildFileInfoSection(DigitalAssetArchive $archive) {
    $is_manual_entry = $archive->isManualEntry();
    $file_name = $archive->get('file_name')->value;
    $original_path = $archive->get('archive_path')->value ?: $archive->get('original_path')->value;
    $asset_type = $archive->get('asset_type')->value;
    $classification_date = $archive->getArchiveClassificationDate();
    $status = $archive->getStatus();

    $items = [];

    if ($is_manual_entry) {
      // Manual entry: Title, URL, Content type.
      $items[] = '<li><strong>' . $this->t('Title:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
      if ($original_path) {
        $items[] = '<li><strong>' . $this->t('URL:') . '</strong> <a href="' . htmlspecialchars($original_path) . '">' . htmlspecialchars($original_path) . '</a></li>';
      }
      $content_type = $asset_type === 'page' ? $this->t('Web Page') : $this->t('External Resource');
      $items[] = '<li><strong>' . $this->t('Content type:') . '</strong> ' . $content_type . '</li>';
    }
    else {
      // File-based archive: File name, File URL, File type, File size.
      $filesize = $archive->get('filesize')->value;

      // Generate URL for the file.
      $file_url = $original_path;
      if ($original_path && strpos($original_path, 'http') !== 0) {
        $file_url_generator = \Drupal::service('file_url_generator');
        try {
          $file_url = $file_url_generator->generateAbsoluteString($original_path);
        }
        catch (\Exception $e) {
          $file_url = $original_path;
        }
      }

      $items[] = '<li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
      if ($file_url) {
        $items[] = '<li><strong>' . $this->t('File URL:') . '</strong> <a href="' . htmlspecialchars($file_url) . '">' . htmlspecialchars($file_url) . '</a></li>';
      }
      $items[] = '<li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>';
      if ($filesize) {
        $items[] = '<li><strong>' . $this->t('File size:') . '</strong> ' . \Drupal\Core\StringTranslation\ByteSizeMarkup::create($filesize) . '</li>';
      }
    }

    // Show archive classification date if archived, otherwise show queued date.
    if ($classification_date) {
      $items[] = '<li><strong>' . $this->t('Archived:') . '</strong> ' . $this->dateFormatter->format($classification_date, 'custom', 'Y-m-d H:i') . '</li>';
    }
    else {
      $created = $archive->get('created')->value;
      $items[] = '<li><strong>' . $this->t('Queued for archive:') . '</strong> ' . $this->dateFormatter->format($created, 'custom', 'Y-m-d H:i') . '</li>';
    }

    // Archive details: Archive Type, Purpose, Status, Warnings.

    // Show archive type for archived items.
    if (in_array($status, ['archived_public', 'archived_admin', 'exemption_void', 'archived_deleted'])) {
      $archive_type = !$archive->hasFlagLateArchive() ? $this->t('Legacy Archive') : $this->t('General Archive');
      $items[] = '<li><strong>' . $this->t('Archive Type:') . '</strong> ' . $archive_type . '</li>';
    }

    // Get archive reason label with optional public description.
    $reason = $archive->get('archive_reason')->value;
    $reason_labels = [
      'reference' => $this->t('Reference'),
      'research' => $this->t('Research'),
      'recordkeeping' => $this->t('Recordkeeping'),
      'other' => $this->t('Other'),
    ];
    $archive_reason_label = $reason_labels[$reason] ?? $reason;
    $public_description = trim((string) $archive->get('public_description')->value);
    $purpose_display = htmlspecialchars($archive_reason_label);
    if (!empty($public_description)) {
      $purpose_display .= ' - ' . htmlspecialchars($public_description);
    }
    $items[] = '<li><strong>' . $this->t('Archive Purpose:') . '</strong> ' . $purpose_display . '</li>';

    // Status.
    $status_labels = [
      'queued' => $this->t('Queued'),
      'archived_public' => $this->t('Archived (Public)'),
      'archived_admin' => $this->t('Archived (Admin)'),
      'archived_deleted' => $this->t('Archived (Deleted)'),
      'exemption_void' => $this->t('Exemption Void'),
    ];
    $status_label = $status_labels[$status] ?? $status;
    $items[] = '<li><strong>' . $this->t('Status:') . '</strong> ' . $status_label . '</li>';

    // Build warnings list.
    $warnings = $this->buildWarningsList($archive);
    if (!empty($warnings)) {
      $items[] = '<li><strong>' . $this->t('Warnings:') . '</strong> ' . implode(', ', $warnings) . '</li>';
    }

    $content = '<ul>' . implode('', $items) . '</ul>';

    return [
      '#type' => 'details',
      '#title' => $this->t('Archive Information'),
      '#open' => FALSE,
      '#attributes' => ['role' => 'group'],
      'content' => [
        '#markup' => $content,
      ],
    ];
  }

  /**
   * Builds a list of warning labels for the archive.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive
   *   The archive entity.
   *
   * @return array
   *   An array of warning label strings.
   */
  protected function buildWarningsList(DigitalAssetArchive $archive) {
    $warnings = [];

    if ($archive->hasFlagIntegrity()) {
      $warnings[] = $this->t('Integrity Issue');
    }
    if ($archive->hasFlagUsage()) {
      $warnings[] = $this->t('Active Usage');
    }
    if ($archive->hasFlagMissing()) {
      $warnings[] = $this->t('File Missing');
    }
    if ($archive->hasFlagModified()) {
      $warnings[] = $this->t('Modified');
    }
    if ($archive->hasFlagPriorVoid()) {
      $warnings[] = $this->t('Prior Exemption Voided');
    }

    return $warnings;
  }

  /**
   * Loads notes for an archive with pagination.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive
   *   The archive entity.
   *
   * @return array
   *   An array of note data for display.
   */
  protected function loadNotes(DigitalAssetArchive $archive) {
    $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');

    // Query notes with pagination (25 per page).
    $query = $note_storage->getQuery()
      ->condition('archive_id', $archive->id())
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->accessCheck(TRUE)
      ->pager(25);

    $ids = $query->execute();

    if (empty($ids)) {
      return [];
    }

    $notes = $note_storage->loadMultiple($ids);
    $result = [];

    foreach ($notes as $note) {
      $author = $note->getAuthor();
      $result[] = [
        'text' => $note->getNoteText(),
        'created' => $note->getCreatedTime(),
        'created_formatted' => $this->dateFormatter->format($note->getCreatedTime(), 'custom', 'Y-m-d H:i'),
        'created_iso' => $this->dateFormatter->format($note->getCreatedTime(), 'custom', 'c'),
        'author' => $author ? $author->getDisplayName() : $this->t('Unknown'),
      ];
    }

    return $result;
  }

}
