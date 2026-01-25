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

namespace Drupal\digital_asset_inventory\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for deleting an archived file.
 *
 * This form deletes the physical file and sets status to archived_deleted.
 * The archive record is preserved as an audit trail.
 */
final class DeleteArchivedFileForm extends ConfirmFormBase {

  /**
   * The archive service.
   *
   * @var \Drupal\digital_asset_inventory\Service\ArchiveService
   */
  protected $archiveService;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The DigitalAssetArchive entity.
   *
   * @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive
   */
  protected $archivedAsset;

  /**
   * Constructs a DeleteArchivedFileForm object.
   *
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(ArchiveService $archive_service, MessengerInterface $messenger) {
    $this->archiveService = $archive_service;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('digital_asset_inventory.archive'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_delete_archived_file_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Permanently delete %filename', [
      '%filename' => $this->archivedAsset->getFileName(),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Complex HTML description for this confirmation form.
   */
  public function getDescription() {
    $archive_management_url = Url::fromRoute('view.digital_asset_archive.page_archive_management')->toString();

    $description = '<div class="messages messages--error">';
    $description .= '<h3>' . $this->t('Permanently Delete Archived File') . '</h3>';
    $description .= '<p>' . $this->t('This action permanently removes the file from the server. It will:') . '</p>';
    $description .= '<ul>';
    $description .= '<li>' . $this->t('Permanently delete the physical file from the server') . '</li>';
    $description .= '<li>' . $this->t('Mark the archive record as <strong>Archived (Deleted)</strong>') . '</li>';
    $description .= '<li>' . $this->t('Record deletion metadata (date, user) for compliance purposes') . '</li>';
    $description .= '</ul>';
    $description .= '<p>' . $this->t('The archive record will be preserved in <a href="@url"><strong>Archive Management</strong></a> for audit purposes, but the file itself will no longer be accessible.', ['@url' => $archive_management_url]) . '</p>';
    $description .= '<p><strong>' . $this->t('Warning:') . '</strong> ' . $this->t('This action cannot be undone. The file will be permanently removed from the server.') . '</p>';
    $description .= '</div>';

    return Markup::create($description);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('view.digital_asset_archive.page_archive_management');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Permanently Delete File');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Return to Archive Management');
  }

  /**
   * {@inheritdoc}
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The form array or redirect response for access control.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DigitalAssetArchive $digital_asset_archive = NULL) {
    $this->archivedAsset = $digital_asset_archive;

    // Validate entity exists.
    if (!$this->archivedAsset) {
      $this->messenger->addError($this->t('Archive record not found.'));
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Validate file can be deleted (only active archived items).
    if (!$this->archivedAsset->canDeleteFile()) {
      if ($this->archivedAsset->isArchivedDeleted()) {
        $this->messenger->addWarning($this->t('This file has already been deleted.'));
      }
      elseif ($this->archivedAsset->isManualEntry()) {
        $this->messenger->addError($this->t('Cannot delete file: This is a manual entry (URL/page), not a file-based archive.'));
      }
      else {
        $this->messenger->addError($this->t('This file cannot be deleted (current status: @status).', [
          '@status' => $this->archivedAsset->getStatusLabel(),
        ]));
      }
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Display archive information.
    $file_name = $this->archivedAsset->getFileName();
    $archive_path = $this->archivedAsset->getArchivePath();
    $asset_type = $this->archivedAsset->getAssetType();
    $filesize = $this->archivedAsset->getFilesize();
    $archive_reason_label = $this->archivedAsset->getArchiveReasonLabel();
    $archived_date = $this->archivedAsset->getArchiveClassificationDate();
    $status_label = $this->archivedAsset->getStatusLabel();
    $checksum = $this->archivedAsset->getFileChecksum();

    $form['file_info'] = [
      '#type' => 'details',
      '#title' => $this->t('File to be Deleted'),
      '#open' => TRUE,
      '#weight' => -90,
      '#attributes' => ['role' => 'group'],
    ];

    $info_content = '<ul>';
    $info_content .= '<li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
    if (!empty($archive_path)) {
      $info_content .= '<li><strong>' . $this->t('File URL:') . '</strong> <a href="' . $archive_path . '">' . htmlspecialchars($archive_path) . '</a></li>';
    }
    $info_content .= '<li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>';
    if ($filesize) {
      $info_content .= '<li><strong>' . $this->t('File size:') . '</strong> ' . ByteSizeMarkup::create($filesize) . '</li>';
    }
    $info_content .= '<li><strong>' . $this->t('Current status:') . '</strong> ' . $status_label . '</li>';
    if ($archived_date) {
      $info_content .= '<li><strong>' . $this->t('Archive Classification Date:') . '</strong> ' . \Drupal::service('date.formatter')->format($archived_date, 'custom', 'c') . '</li>';
    }
    $info_content .= '<li><strong>' . $this->t('Archive Purpose:') . '</strong> ' . htmlspecialchars($archive_reason_label) . '</li>';
    if (!empty($checksum)) {
      $info_content .= '<li><strong>' . $this->t('SHA256 Checksum:') . '</strong> <code>' . htmlspecialchars(substr($checksum, 0, 32)) . '...</code></li>';
    }
    $info_content .= '</ul>';

    $form['file_info']['content'] = [
      '#markup' => $info_content,
    ];

    $form = parent::buildForm($form, $form_state);

    // Attach admin CSS library for button styling.
    $form['#attached']['library'][] = 'digital_asset_inventory/admin';

    // Style the submit button with primary styling.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#button_type'] = 'primary';
    }

    // Style cancel as a secondary button.
    if (isset($form['actions']['cancel'])) {
      $form['actions']['cancel']['#attributes']['class'][] = 'button';
      $form['actions']['cancel']['#attributes']['class'][] = 'button--secondary';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_name = $this->archivedAsset->getFileName();

    try {
      $this->archiveService->deleteFile($this->archivedAsset);

      $this->messenger->addStatus($this->t('The file "%filename" has been permanently deleted.', [
        '%filename' => $file_name,
      ]));

      $this->messenger->addStatus($this->t('The archive record has been preserved with status "Archived (Deleted)" and deletion metadata recorded.'));
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error deleting file: @error', [
        '@error' => $e->getMessage(),
      ]));

      \Drupal::logger('digital_asset_inventory')->error('File deletion failed for @filename: @error', [
        '@filename' => $file_name,
        '@error' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
  }

}
