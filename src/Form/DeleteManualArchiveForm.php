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
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for removing a manual archive entry.
 *
 * Manual entries are removed from public view but the archive record is
 * preserved with status 'archived_deleted' for audit trail purposes.
 * This maintains consistency with file-based archives.
 */
final class DeleteManualArchiveForm extends ConfirmFormBase {

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
   * Constructs a DeleteManualArchiveForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_delete_manual_archive_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Remove manual archive entry for %title from public view?', [
      '%title' => $this->archivedAsset->getFileName(),
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
    $description .= '<h3>' . $this->t('Remove Manual Archive Entry from Public View') . '</h3>';
    $description .= '<p>' . $this->t('This action hides the archived item from the public archive. It will:') . '</p>';
    $description .= '<ul>';
    $description .= '<li>' . $this->t('Remove the item from the public Archive Registry') . '</li>';
    $description .= '<li>' . $this->t('Mark the item as <strong>Archived (Deleted)</strong>') . '</li>';
    $description .= '<li>' . $this->t('Keep a record of this item for compliance and audit purposes') . '</li>';
    $description .= '</ul>';
    $description .= '<p>' . $this->t('The item will no longer appear to the public, but staff can still see it in <a href="@url"><strong>Archive Management</strong></a> for recordkeeping.', ['@url' => $archive_management_url]) . '</p>';
    $description .= '<p><strong>' . $this->t('Note:') . '</strong> ' . $this->t('If this item needs to be made public again, it must be archived again as a new entry.') . '</p>';
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
      $this->messenger->addError($this->t('Archive entry not found.'));
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Validate this is a manual entry.
    if (!$this->archivedAsset->isManualEntry()) {
      $this->messenger->addError($this->t('Only manual archive entries can be removed using this form. File-based archives must use "Unarchive" or "Remove Record".'));
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Validate entry hasn't already been removed.
    if ($this->archivedAsset->isArchivedDeleted()) {
      $this->messenger->addWarning($this->t('This manual archive entry has already been removed from public view.'));
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Display entry information.
    $title = $this->archivedAsset->getFileName();
    $url = $this->archivedAsset->getOriginalPath();
    $asset_type = $this->archivedAsset->getAssetTypeLabel();
    $status = $this->archivedAsset->getStatusLabel();

    $form['entry_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Entry Information'),
      '#open' => TRUE,
      '#weight' => -90,
      '#attributes' => ['role' => 'group'],
    ];

    $info_content = '<ul>';
    $info_content .= '<li><strong>' . $this->t('Title:') . '</strong> ' . htmlspecialchars($title) . '</li>';
    if (!empty($url)) {
      $info_content .= '<li><strong>' . $this->t('URL:') . '</strong> ' . htmlspecialchars($url) . '</li>';
    }
    $info_content .= '<li><strong>' . $this->t('Content Type:') . '</strong> ' . htmlspecialchars($asset_type) . '</li>';
    $info_content .= '<li><strong>' . $this->t('Status:') . '</strong> ' . htmlspecialchars($status) . '</li>';
    $info_content .= '</ul>';

    $form['entry_info']['content'] = [
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
    $title = $this->archivedAsset->getFileName();

    try {
      $current_user = \Drupal::currentUser();

      // Set status to archived_deleted (preserves record for audit trail).
      $this->archivedAsset->setStatus('archived_deleted');
      $this->archivedAsset->setDeletedDate(\Drupal::time()->getRequestTime());
      $this->archivedAsset->setDeletedBy($current_user->id());
      $this->archivedAsset->save();

      // Create note in the archive review log.
      $note_storage = \Drupal::entityTypeManager()->getStorage('dai_archive_note');
      $note = $note_storage->create([
        'archive_id' => $this->archivedAsset->id(),
        'note_text' => 'Manual entry removed from archive registry.',
        'author' => $current_user->id(),
      ]);
      $note->save();

      $this->messenger->addStatus($this->t('Manual archive entry "%title" has been removed from public view. The record is preserved for audit purposes.', [
        '%title' => $title,
      ]));

      \Drupal::logger('digital_asset_inventory')->notice('Manual archive entry "@title" was removed from public view (status set to archived_deleted).', [
        '@title' => $title,
      ]);
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error removing entry: @error', [
        '@error' => $e->getMessage(),
      ]));

      \Drupal::logger('digital_asset_inventory')->error('Remove manual archive entry failed for @title: @error', [
        '@title' => $title,
        '@error' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
  }

}