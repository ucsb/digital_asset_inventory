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
    // Defensive access check.
    if (!$digital_asset_archive->access('view', $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }

    // Build file info and archive details sections (matching form pattern).
    $file_info = $this->buildFileInfoSection($digital_asset_archive);
    $archive_details = $this->buildArchiveDetailsSection($digital_asset_archive);

    // Get initial note from the archive entity.
    $initial_note = trim((string) $digital_asset_archive->get('internal_notes')->value);

    // Load notes log entries with pagination.
    $notes = $this->loadNotes($digital_asset_archive);

    // Build add note form if user has permission.
    $add_form = NULL;
    if ($this->currentUser->hasPermission('add archive internal notes')) {
      $add_form = $this->formBuilder->getForm(AddArchiveNoteForm::class, $digital_asset_archive);
    }

    return [
      '#theme' => 'archive_notes_page',
      '#file_info' => $file_info,
      '#archive_details' => $archive_details,
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
   * Builds the file information section.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive
   *   The archive entity.
   *
   * @return array
   *   A render array for the file info details element.
   */
  protected function buildFileInfoSection(DigitalAssetArchive $archive) {
    $file_name = $archive->get('file_name')->value;
    $original_path = $archive->get('archive_path')->value ?: $archive->get('original_path')->value;
    $asset_type = $archive->get('asset_type')->value;
    $filesize = $archive->get('filesize')->value;
    $created = $archive->get('created')->value;

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

    $items = [];
    $items[] = '<li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
    if ($file_url) {
      $items[] = '<li><strong>' . $this->t('File URL:') . '</strong> <a href="' . htmlspecialchars($file_url) . '">' . htmlspecialchars($file_url) . '</a></li>';
    }
    $items[] = '<li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>';
    if ($filesize) {
      $items[] = '<li><strong>' . $this->t('File size:') . '</strong> ' . \Drupal\Core\StringTranslation\ByteSizeMarkup::create($filesize) . '</li>';
    }
    $items[] = '<li><strong>' . $this->t('Queued for archive:') . '</strong> ' . $this->dateFormatter->format($created, 'custom', 'Y-m-d H:i') . '</li>';

    return [
      '#type' => 'details',
      '#title' => $this->t('File Information'),
      '#open' => FALSE,
      '#attributes' => ['role' => 'group'],
      'content' => [
        '#markup' => '<ul>' . implode('', $items) . '</ul>',
      ],
    ];
  }

  /**
   * Builds the archive details section.
   *
   * @param \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive $archive
   *   The archive entity.
   *
   * @return array
   *   A render array for the archive details element.
   */
  protected function buildArchiveDetailsSection(DigitalAssetArchive $archive) {
    $status = $archive->getStatus();
    $status_labels = [
      'queued' => $this->t('Queued'),
      'archived_public' => $this->t('Archived (Public)'),
      'archived_admin' => $this->t('Archived (Admin)'),
      'archived_deleted' => $this->t('Archived (Deleted)'),
      'exemption_void' => $this->t('Exemption Void'),
    ];
    $status_label = $status_labels[$status] ?? $status;

    // Get archive reason label.
    $reason = $archive->get('archive_reason')->value;
    $reason_labels = [
      'reference' => $this->t('Reference'),
      'research' => $this->t('Research'),
      'recordkeeping' => $this->t('Recordkeeping'),
      'other' => $this->t('Other'),
    ];
    $archive_reason_label = $reason_labels[$reason] ?? $reason;

    $public_description = trim((string) $archive->get('public_description')->value);

    $items = [];
    $items[] = '<li><strong>' . $this->t('Status:') . '</strong> ' . $status_label . '</li>';

    // Show archive type for archived items.
    if (in_array($status, ['archived_public', 'archived_admin', 'exemption_void'])) {
      $archive_type = !$archive->hasFlagLateArchive() ? $this->t('Legacy Archive') : $this->t('General Archive');
      $items[] = '<li><strong>' . $this->t('Archive Type:') . '</strong> ' . $archive_type . '</li>';
    }

    $items[] = '<li><strong>' . $this->t('Archive Purpose:') . '</strong> ' . htmlspecialchars($archive_reason_label) . '</li>';

    $content = '<ul>' . implode('', $items) . '</ul>';

    if (!empty($public_description)) {
      $content .= '<p><strong>' . $this->t('Public Description:') . '</strong></p>';
      $content .= '<blockquote class="archive-description-block">' . nl2br(htmlspecialchars($public_description)) . '</blockquote>';
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Archive Details'),
      '#open' => FALSE,
      '#attributes' => ['role' => 'group'],
      'content' => [
        '#markup' => $content,
      ],
    ];
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
