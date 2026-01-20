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
 * Provides a confirmation form for toggling archive visibility.
 *
 * Toggles between archived_public and archived_admin with audit trail.
 * The archive_classification_date remains unchanged.
 */
class ToggleArchiveVisibilityForm extends ConfirmFormBase {

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
   * Constructs a ToggleArchiveVisibilityForm object.
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
    return 'digital_asset_inventory_toggle_archive_visibility_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $current_status = $this->archivedAsset->getStatus();
    $new_visibility = ($current_status === 'archived_public') ? 'Admin-only' : 'Public';

    return $this->t('Change visibility of %filename to @visibility?', [
      '%filename' => $this->archivedAsset->getFileName(),
      '@visibility' => $new_visibility,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $current_status = $this->archivedAsset->getStatus();
    $current_label = ($current_status === 'archived_public') ? $this->t('Public') : $this->t('Admin-only');
    $new_label = ($current_status === 'archived_public') ? $this->t('Admin-only') : $this->t('Public');
    $is_manual = $this->archivedAsset->isManualEntry();
    
    // Use "entry" for manual entries, "document" for file-based archives.
    $item_type = $is_manual ? 'entry' : 'document';

    $description = '<div class="messages messages--warning">';
    $description .= '<h3>' . $this->t('Toggle Archive Visibility') . '</h3>';
    $description .= '<p><strong>' . $this->t('Current visibility:') . '</strong> ' . $current_label . '</p>';
    $description .= '<p><strong>' . $this->t('New visibility:') . '</strong> ' . $new_label . '</p>';
    $description .= '<p>' . $this->t('This action will:') . '</p>';
    $description .= '<ul>';

    if ($current_status === 'archived_public') {
      $description .= '<li>' . $this->t('Remove the @item_type from the public Archive Registry at /archive-registry', ['@item_type' => $item_type]) . '</li>';
      $description .= '<li>' . $this->t('Keep the @item_type visible in admin Archive Management only', ['@item_type' => $item_type]) . '</li>';
    }
    else {
      $description .= '<li>' . $this->t('Add the @item_type to the public Archive Registry at /archive-registry', ['@item_type' => $item_type]) . '</li>';
      $description .= '<li>' . $this->t('Make the @item_type publicly accessible', ['@item_type' => $item_type]) . '</li>';
    }

    $description .= '<li>' . $this->t('Log this visibility change with timestamp and user for audit trail') . '</li>';
    $description .= '<li><strong>' . $this->t('Preserve the archive classification date unchanged') . '</strong></li>';
    $description .= '</ul>';
    $description .= '<p><strong>' . $this->t('Note:') . '</strong> ' . $this->t('The archive classification date is immutable and will not be affected by this visibility change.') . '</p>';
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
    return $this->t('Change Visibility');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Cancel');
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

    // Validate visibility can be toggled (only active archived items).
    if (!$this->archivedAsset->canToggleVisibility()) {
      if ($this->archivedAsset->isArchivedDeleted()) {
        $this->messenger->addError($this->t('Cannot change visibility: Asset has been deleted or unarchived.'));
      }
      elseif ($this->archivedAsset->isQueued() || $this->archivedAsset->isBlocked()) {
        $this->messenger->addError($this->t('Cannot change visibility: Asset has not been archived yet.'));
      }
      else {
        $this->messenger->addError($this->t('Cannot change visibility for this asset (current status: @status).', [
          '@status' => $this->archivedAsset->getStatusLabel(),
        ]));
      }
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Display archive information.
    $file_name = $this->archivedAsset->getFileName();
    $archive_reason_label = $this->archivedAsset->getArchiveReasonLabel();
    $public_description = $this->archivedAsset->getPublicDescription();
    $archived_date = $this->archivedAsset->getArchiveClassificationDate();
    $status_label = $this->archivedAsset->getStatusLabel();
    $is_manual = $this->archivedAsset->isManualEntry();

    // Determine appropriate title based on entry type.
    $details_title = $is_manual ? $this->t('Entry Information') : $this->t('Archive Information');

    $form['file_info'] = [
      '#type' => 'details',
      '#title' => $details_title,
      '#open' => TRUE,
      '#weight' => -90,
      '#attributes' => ['role' => 'group'],
    ];

    $info_content = '<ul>';
    
    // Manual entries show: Title, URL, Content Type.
    // File-based archives show: File name, Archive URL, File type.
    if ($is_manual) {
      $info_content .= '<li><strong>' . $this->t('Title:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
      $url = $this->archivedAsset->getOriginalPath();
      if (!empty($url)) {
        $info_content .= '<li><strong>' . $this->t('URL:') . '</strong> ' . htmlspecialchars($url) . '</li>';
      }
      $asset_type_label = $this->archivedAsset->getAssetTypeLabel();
      $info_content .= '<li><strong>' . $this->t('Content Type:') . '</strong> ' . htmlspecialchars($asset_type_label) . '</li>';
    }
    else {
      $info_content .= '<li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
      $archive_path = $this->archivedAsset->getArchivePath();
      if (!empty($archive_path)) {
        $info_content .= '<li><strong>' . $this->t('Archive URL:') . '</strong> <a href="' . $archive_path . '" target="_blank" rel="noopener">' . htmlspecialchars($archive_path) . '</a></li>';
      }
      $asset_type = $this->archivedAsset->getAssetType();
      $info_content .= '<li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>';
    }
    
    $info_content .= '<li><strong>' . $this->t('Status:') . '</strong> ' . $status_label . '</li>';
    if ($archived_date) {
      $info_content .= '<li><strong>' . $this->t('Archive Classification Date:') . '</strong> ' . \Drupal::service('date.formatter')->format($archived_date, 'custom', 'c') . ' <em>(will remain unchanged)</em></li>';
    }
    $info_content .= '<li><strong>' . $this->t('Archive Purpose:') . '</strong> ' . htmlspecialchars($archive_reason_label) . '</li>';
    $info_content .= '</ul>';

    $form['file_info']['content'] = [
      '#markup' => $info_content,
    ];

    // Show public description if changing to/from public.
    if (!empty($public_description)) {
      $form['public_description_display'] = [
        '#type' => 'details',
        '#title' => $this->t('Public Description'),
        '#description' => $this->t('This description will be shown on the public Archive Registry if visibility is set to Public.'),
        '#open' => TRUE,
        '#weight' => -80,
        '#attributes' => ['role' => 'group'],
      ];

      $form['public_description_display']['content'] = [
        '#markup' => '<blockquote style="background: #f5f5f5; padding: 15px; border-left: 4px solid #0073aa; margin: 10px 0; line-height: 1.5;">' .
        nl2br(htmlspecialchars($public_description)) .
        '</blockquote>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_name = $this->archivedAsset->getFileName();
    $old_status = $this->archivedAsset->getStatus();
    $old_visibility = ($old_status === 'archived_public') ? 'Public' : 'Admin-only';

    try {
      $this->archiveService->toggleVisibility($this->archivedAsset);

      $new_status = $this->archivedAsset->getStatus();
      $new_visibility = ($new_status === 'archived_public') ? 'Public' : 'Admin-only';

      $this->messenger->addStatus($this->t('Visibility of "%filename" has been changed from @old to @new.', [
        '%filename' => $file_name,
        '@old' => $old_visibility,
        '@new' => $new_visibility,
      ]));

      if ($new_status === 'archived_public') {
        $this->messenger->addStatus($this->t('The document is now visible on the public Archive Registry at /archive-registry.'));
      }
      else {
        $this->messenger->addStatus($this->t('The document has been removed from the public Archive Registry and is now admin-only.'));
      }

      $this->messenger->addStatus($this->t('Archive classification date remains unchanged. Visibility change has been logged for audit trail.'));
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error changing visibility: @error', [
        '@error' => $e->getMessage(),
      ]));

      \Drupal::logger('digital_asset_inventory')->error('Visibility toggle failed for @filename: @error', [
        '@filename' => $file_name,
        '@error' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
  }

}
