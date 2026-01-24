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
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for unarchiving an archived asset.
 *
 * This form sets the status to 'unarchived' but keeps the record for history.
 * The file remains in the /archive directory.
 */
final class UnarchiveForm extends ConfirmFormBase {

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
   * Constructs an UnarchiveForm object.
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
    return 'digital_asset_inventory_unarchive_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Remove %filename from public view', [
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

    $description = '<div class="messages messages--warning">';
    $description .= '<h3>' . $this->t('Remove Archived Document from Public View') . '</h3>';
    $description .= '<p>' . $this->t('This action hides the archived document from the public archive. It will:') . '</p>';
    $description .= '<ul>';
    $description .= '<li>' . $this->t('Remove the document from the public Archive Registry') . '</li>';
    $description .= '<li>' . $this->t('Mark the document as <strong>Archived (Deleted)</strong>') . '</li>';
    $description .= '<li>' . $this->t('Keep a record of this document for compliance and audit purposes') . '</li>';
    $description .= '</ul>';
    $description .= '<p>' . $this->t('The file remains at its current location. The document will no longer appear to the public, but staff can still see it in <a href="@url"><strong>Archive Management</strong></a> for recordkeeping.', ['@url' => $archive_management_url]) . '</p>';
    $description .= '<p><strong>' . $this->t('Note:') . '</strong> ' . $this->t('If this document needs to be made public again, it must be archived again as a new entry.') . '</p>';
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
    return $this->t('Remove from Public View');
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

    // Validate status allows unarchiving.
    if (!$this->archivedAsset->canUnarchive()) {
      if ($this->archivedAsset->isQueued() || $this->archivedAsset->isBlocked()) {
        $this->messenger->addError($this->t('This asset has not been archived yet. Use "Remove from Queue" instead.'));
      }
      elseif ($this->archivedAsset->isArchivedDeleted()) {
        $this->messenger->addWarning($this->t('This asset has already been unarchived or deleted.'));
      }
      else {
        $this->messenger->addError($this->t('This asset cannot be unarchived (current status: @status).', [
          '@status' => $this->archivedAsset->getStatusLabel(),
        ]));
      }
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Display archive information.
    $file_name = $this->archivedAsset->getFileName();
    $archive_path = $this->archivedAsset->getArchivePath();
    $asset_type = $this->archivedAsset->getAssetType();
    $archive_reason_label = $this->archivedAsset->getArchiveReasonLabel();
    $public_description = $this->archivedAsset->getPublicDescription();
    $archived_date = $this->archivedAsset->getArchiveClassificationDate();
    $status_label = $this->archivedAsset->getStatusLabel();
    $checksum = $this->archivedAsset->getFileChecksum();

    $form['file_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Information'),
      '#open' => TRUE,
      '#weight' => -90,
      '#attributes' => ['role' => 'group'],
    ];

    $info_content = '<ul>';
    $info_content .= '<li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
    if (!empty($archive_path)) {
      $info_content .= '<li><strong>' . $this->t('Archive URL:') . '</strong> <a href="' . $archive_path . '">' . htmlspecialchars($archive_path) . '</a></li>';
    }
    $info_content .= '<li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>';
    $info_content .= '<li><strong>' . $this->t('Current status:') . '</strong> ' . $status_label . '</li>';
    if ($archived_date) {
      $info_content .= '<li><strong>' . $this->t('Archive Classification Date:') . '</strong> ' . \Drupal::service('date.formatter')->format($archived_date, 'custom', 'c') . '</li>';
    }
    if (!empty($checksum)) {
      $info_content .= '<li><strong>' . $this->t('SHA256 Checksum:') . '</strong> <code>' . htmlspecialchars(substr($checksum, 0, 16)) . '...</code></li>';
    }
    $info_content .= '</ul>';

    $form['file_info']['content'] = [
      '#markup' => $info_content,
    ];

    $form['archive_reason_display'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Details'),
      '#open' => TRUE,
      '#weight' => -80,
      '#attributes' => ['role' => 'group'],
    ];

    $reason_content = '<p><strong>' . $this->t('Archive Purpose:') . '</strong> ' . htmlspecialchars($archive_reason_label) . '</p>';

    if (!empty($public_description)) {
      $reason_content .= '<p><strong>' . $this->t('Public Description:') . '</strong></p>';
      $reason_content .= '<blockquote class="archive-description-block">' .
        nl2br(htmlspecialchars($public_description)) .
        '</blockquote>';
    }

    $form['archive_reason_display']['content'] = [
      '#markup' => $reason_content,
    ];

    // Show problem status warning if applicable.
    if ($this->archivedAsset->hasArchiveProblems()) {
      $form['problem_warning'] = [
        '#type' => 'item',
        '#markup' => '<div class="messages messages--error">
          <h3>' . $this->t('Archive Problem Detected') . '</h3>
          <p>' . $this->t('This archive has a problem status: <strong>@status</strong>', [
            '@status' => $status_label,
          ]) . '</p>
          <p>' . $this->t('Unarchiving will remove this document from the public Archive Registry but will not resolve the underlying issue.') . '</p>
        </div>',
        '#weight' => -95,
      ];
    }

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
      $this->archiveService->unarchive($this->archivedAsset);

      $this->messenger->addStatus($this->t('"%filename" has been unarchived and removed from the public Archive Registry. The archive record is preserved for audit trail.', [
        '%filename' => $file_name,
      ]));
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error unarchiving: @error', [
        '@error' => $e->getMessage(),
      ]));

      \Drupal::logger('digital_asset_inventory')->error('Unarchive failed for @filename: @error', [
        '@filename' => $file_name,
        '@error' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
  }

}
