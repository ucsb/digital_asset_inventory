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
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for removing an asset from the archive queue.
 *
 * This form removes a queued/blocked archive request without moving the file.
 * Only queued or blocked archives can be removed from queue.
 */
class CancelArchiveForm extends ConfirmFormBase {

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
   * Constructs a CancelArchiveForm object.
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
    return 'digital_asset_inventory_cancel_archive_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Remove %filename from Archive Queue?', [
      '%filename' => $this->archivedAsset->getFileName(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $description = '<div class="messages messages--warning">';
    $description .= '<h3>' . $this->t('Remove from Archive Queue') . '</h3>';
    $description .= '<p>' . $this->t('This will:') . '</p>';
    $description .= '<ul>';
    $description .= '<li>' . $this->t('Remove this file from the archive queue') . '</li>';
    $description .= '<li>' . $this->t('Keep the file in its current location (no changes to the file)') . '</li>';
    $description .= '<li>' . $this->t('Delete the archive reason and metadata') . '</li>';
    $description .= '</ul>';
    $description .= '<p>' . $this->t('You can queue this file for archive again later if needed.') . '</p>';
    $description .= '</div>';

    return $description;
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
    return $this->t('Remove from Queue');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Keep in Queue');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DigitalAssetArchive $digital_asset_archive = NULL) {
    $this->archivedAsset = $digital_asset_archive;

    // Validate entity exists.
    if (!$this->archivedAsset) {
      $this->messenger->addError($this->t('Archive record not found.'));
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Validate status allows removal from queue.
    if (!$this->archivedAsset->canRemoveFromQueue()) {
      if ($this->archivedAsset->isArchived()) {
        $this->messenger->addError($this->t('This asset has already been archived. Use "Unarchive" to remove it from the archive.'));
      }
      else {
        $this->messenger->addError($this->t('This asset cannot be removed from queue (current status: @status).', [
          '@status' => $this->archivedAsset->getStatusLabel(),
        ]));
      }
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Display file information.
    $file_name = $this->archivedAsset->getFileName();
    $original_path = $this->archivedAsset->getOriginalPath();
    $asset_type = $this->archivedAsset->getAssetType();
    $archive_reason_label = $this->archivedAsset->getArchiveReasonLabel();
    $created = $this->archivedAsset->get('created')->value;
    $status_label = $this->archivedAsset->getStatusLabel();

    // Generate URL for the file.
    if (strpos($original_path, 'http://') === 0 || strpos($original_path, 'https://') === 0) {
      $file_url = $original_path;
    }
    else {
      $file_url_generator = \Drupal::service('file_url_generator');
      $file_url = $file_url_generator->generateAbsoluteString($original_path);
    }

    $form['file_info'] = [
      '#type' => 'details',
      '#title' => $this->t('File Information'),
      '#open' => TRUE,
      '#weight' => -90,
      '#attributes' => ['role' => 'group'],
    ];

    $form['file_info']['content'] = [
      '#markup' => '<ul>
        <li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>
        <li><strong>' . $this->t('Current URL:') . '</strong> <a href="' . $file_url . '" target="_blank" rel="noopener">' . htmlspecialchars($file_url) . '</a></li>
        <li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>
        <li><strong>' . $this->t('Current status:') . '</strong> ' . $status_label . '</li>
        <li><strong>' . $this->t('Queued for archive:') . '</strong> ' . \Drupal::service('date.formatter')->format($created, 'custom', 'c') . '</li>
      </ul>',
    ];

    $form['archive_reason_display'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Details (will be deleted)'),
      '#open' => TRUE,
      '#weight' => -80,
      '#attributes' => ['role' => 'group'],
    ];

    $form['archive_reason_display']['content'] = [
      '#markup' => '<p><strong>' . $this->t('Archive Purpose:') . '</strong> ' . htmlspecialchars($archive_reason_label) . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_name = $this->archivedAsset->getFileName();

    try {
      $this->archiveService->removeFromQueue($this->archivedAsset);

      $this->messenger->addStatus($this->t('"%filename" has been removed from the archive queue. The file remains in its current location.', [
        '%filename' => $file_name,
      ]));
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error removing from queue: @error', [
        '@error' => $e->getMessage(),
      ]));

      \Drupal::logger('digital_asset_inventory')->error('Remove from queue failed for @filename: @error', [
        '@filename' => $file_name,
        '@error' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
  }

}
