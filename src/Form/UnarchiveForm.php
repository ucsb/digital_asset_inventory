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
    return $this->t('Unarchive Digital Asset');
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Complex HTML description for this confirmation form.
   */
  public function getDescription() {
    // Description is now built in buildForm() for context-awareness.
    return Markup::create('');
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
    return $this->t('Unarchive Asset');
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

    // Get asset information.
    $file_name = $this->archivedAsset->getFileName();
    $archive_path = $this->archivedAsset->getArchivePath();
    $asset_type = $this->archivedAsset->getAssetType();
    $archive_reason_label = $this->archivedAsset->getArchiveReasonLabel();
    $public_description = $this->archivedAsset->getPublicDescription();
    $archived_date = $this->archivedAsset->getArchiveClassificationDate();
    $status_label = $this->archivedAsset->getStatusLabel();
    $checksum = $this->archivedAsset->getFileChecksum();
    $is_public = $this->archivedAsset->getStatus() === 'archived_public';
    $is_manual = $this->archivedAsset->isManualEntry();

    // Section 1: Archive Information (read-only, closed by default).
    $details_title = $is_manual ? $this->t('Entry Information') : $this->t('Archive Information');
    $form['file_info'] = [
      '#type' => 'details',
      '#title' => $details_title,
      '#open' => FALSE,
      '#weight' => -100,
      '#attributes' => ['role' => 'group'],
    ];

    $info_content = '<ul>';
    if ($is_manual) {
      $info_content .= '<li><strong>' . $this->t('Title:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
      $url = $this->archivedAsset->getOriginalPath();
      if (!empty($url)) {
        $info_content .= '<li><strong>' . $this->t('URL:') . '</strong> ' . htmlspecialchars($url) . '</li>';
      }
      $asset_type_label = $this->archivedAsset->getAssetTypeLabel();
      $info_content .= '<li><strong>' . $this->t('Content type:') . '</strong> ' . htmlspecialchars($asset_type_label) . '</li>';
    }
    else {
      $info_content .= '<li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
      if (!empty($archive_path)) {
        $info_content .= '<li><strong>' . $this->t('Archive URL:') . '</strong> <a href="' . $archive_path . '">' . htmlspecialchars($archive_path) . '</a></li>';
      }
      $info_content .= '<li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>';
    }
    $info_content .= '<li><strong>' . $this->t('Current status:') . '</strong> ' . $status_label . '</li>';
    if ($archived_date) {
      $info_content .= '<li><strong>' . $this->t('Archive classification date:') . '</strong> ' . \Drupal::service('date.formatter')->format($archived_date, 'custom', 'Y-m-d H:i:s (T)') . '</li>';
    }
    if (!empty($checksum)) {
      $info_content .= '<li><strong>' . $this->t('SHA-256 checksum:') . '</strong> <code>' . htmlspecialchars(substr($checksum, 0, 16)) . '...</code></li>';
    }
    $info_content .= '</ul>';

    $form['file_info']['content'] = [
      '#markup' => $info_content,
    ];

    // Section 2: Archive Details (read-only, closed by default).
    $form['archive_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Details'),
      '#open' => FALSE,
      '#weight' => -90,
      '#attributes' => ['role' => 'group'],
    ];

    $details_content = '<p><strong>' . $this->t('Archive purpose') . '</strong></p>';
    $details_content .= '<p>' . htmlspecialchars($archive_reason_label) . '</p>';

    if (!empty($public_description)) {
      $details_content .= '<p><strong>' . $this->t('Public description') . '</strong></p>';
      $details_content .= '<blockquote class="archive-description-block">' .
        nl2br(htmlspecialchars($public_description)) .
        '</blockquote>';
    }

    $form['archive_details']['content'] = [
      '#markup' => $details_content,
    ];

    // Section 3: Action Limitation Warning (only when re-archive is blocked).
    $rearchive_blocked = $this->archiveService->isReArchiveBlocked($this->archivedAsset);
    if ($rearchive_blocked) {
      // Build usage link if asset ID is available.
      $asset_id = $this->getAssetIdForArchive();
      if ($asset_id) {
        $usage_url = Url::fromRoute('view.digital_asset_usage.page_1', ['arg_0' => $asset_id])->toString();
        $usage_text = $this->formatPlural($rearchive_blocked['usage_count'],
          'This archived item is currently in use (<a href="@url">1 reference</a>).',
          'This archived item is currently in use (<a href="@url">@count references</a>).',
          ['@url' => $usage_url]
        );
      }
      else {
        $usage_text = $this->formatPlural($rearchive_blocked['usage_count'],
          'This archived item is currently in use (1 reference).',
          'This archived item is currently in use (@count references).'
        );
      }

      $warning_content = '<div class="dai-policy-notice">';
      $warning_content .= '<p class="dai-notice-title">' . $this->t('Action limited by current settings') . '</p>';
      $warning_content .= '<p>' . $usage_text . '</p>';
      $warning_content .= '<p>' . $this->t('Unarchiving will remove this item from archived status.') . '<br>';
      $warning_content .= $this->t('Because archiving assets that are in use is disabled, you will not be able to archive this item again while it is still referenced.') . '</p>';

      // Conditional line for Public archives only.
      if ($is_public) {
        $warning_content .= '<p><em>' . $this->t('This item is currently visible in the public Archive Registry. Unarchiving will remove it from public view.') . '</em></p>';
      }

      $warning_content .= '<p><strong>' . $this->t('What you can do') . '</strong></p>';
      $warning_content .= '<ul>';
      $warning_content .= '<li><strong>' . $this->t('Unarchive') . '</strong> ' . $this->t('(allowed)') . ' — ' . $this->t('returns the item to active use.') . '</li>';
      $warning_content .= '<li><strong>' . $this->t('Re-archive') . '</strong> ' . $this->t('(blocked)') . ' — ' . $this->t('not permitted while this item is in use.') . '</li>';
      $warning_content .= '</ul>';

      $warning_content .= '<p><strong>' . $this->t('To archive this item again later') . '</strong></p>';
      $warning_content .= '<p>' . $this->t('One of the following must occur:') . '</p>';
      $warning_content .= '<ul>';
      $warning_content .= '<li>' . $this->t('All references to this item are removed from site content, <strong>or</strong>') . '</li>';
      $warning_content .= '<li>' . $this->t('An administrator updates system settings to allow archiving items that are in use.') . '</li>';
      $warning_content .= '</ul>';

      $warning_content .= '<p><strong>' . $this->t('Why unarchive is allowed') . '</strong></p>';
      $warning_content .= '<p>' . $this->t('Unarchiving is permitted as a corrective action, even when archiving items that are in use is restricted.') . '</p>';
      $warning_content .= '</div>';

      $form['action_warning'] = [
        '#type' => 'item',
        '#markup' => $warning_content,
        '#weight' => -80,
      ];

      // Section 4: What Happens After Unarchiving (when re-archive is blocked).
      $consequences_content = '<div class="dai-consequences-notice">';
      $consequences_content .= '<p class="dai-notice-title">' . $this->t('What happens after unarchiving') . '</p>';
      $consequences_content .= '<ul>';
      $consequences_content .= '<li>' . $this->t('The asset will return to active use.') . '</li>';
      $consequences_content .= '<li>' . $this->t('Existing references will continue to function normally.') . '</li>';
      $consequences_content .= '<li>' . $this->t('This asset cannot be archived again unless:') . '<ul>';
      $consequences_content .= '<li>' . $this->t('All references are removed, <strong>or</strong>') . '</li>';
      $consequences_content .= '<li>' . $this->t('An administrator updates system settings.') . '</li>';
      $consequences_content .= '</ul></li>';
      $consequences_content .= '</ul>';
      $consequences_content .= '</div>';

      $form['consequences'] = [
        '#type' => 'item',
        '#markup' => $consequences_content,
        '#weight' => -70,
      ];

      // Helper text above actions (only when re-archive is blocked).
      $form['helper_text'] = [
        '#type' => 'item',
        '#markup' => '<p class="dai-action-helper-text">' . $this->t('Unarchiving is allowed as a corrective action and does not resolve the underlying usage condition.') . '</p>',
        '#weight' => -60,
      ];
    }
    else {
      // Non-blocked case: Simple explanation.
      $simple_notice = '<div class="dai-consequences-notice">';
      $simple_notice .= '<p class="dai-notice-title">' . $this->t('What happens after unarchiving') . '</p>';
      $simple_notice .= '<p><strong>' . $this->t('Unarchiving will remove this asset from archived status.') . '</strong><br>';
      $simple_notice .= $this->t('The archive record will be retained for audit purposes.') . '</p>';
      if ($is_public) {
        $simple_notice .= '<p><strong>' . $this->t('This item is currently visible in the public Archive Registry.') . '</strong><br>';
        $simple_notice .= $this->t('Unarchiving will remove it from public view.') . '</p>';
      }
      $simple_notice .= '</div>';

      $form['simple_notice'] = [
        '#type' => 'item',
        '#markup' => $simple_notice,
        '#weight' => -70,
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
    $was_public = $this->archivedAsset->getStatus() === 'archived_public';

    try {
      $this->archiveService->unarchive($this->archivedAsset);

      if ($was_public) {
        $this->messenger->addStatus($this->t('"%filename" has been unarchived and removed from the public Archive Registry.', [
          '%filename' => $file_name,
        ]));
      }
      else {
        $this->messenger->addStatus($this->t('"%filename" has been unarchived.', [
          '%filename' => $file_name,
        ]));
      }
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

  /**
   * Gets the DigitalAssetItem ID for the current archive.
   *
   * @return int|null
   *   The asset item ID, or NULL if not found.
   */
  protected function getAssetIdForArchive(): ?int {
    $database = \Drupal::database();
    $fid = $this->archivedAsset->getOriginalFid();
    $original_path = $this->archivedAsset->getOriginalPath();

    // Look up the DigitalAssetItem by fid or path.
    $query = $database->select('digital_asset_item', 'dai')
      ->fields('dai', ['id']);

    if ($fid) {
      $query->condition('fid', $fid);
    }
    else {
      $query->condition('file_path', $original_path);
    }

    $result = $query->execute()->fetchField();
    return $result ? (int) $result : NULL;
  }

}
