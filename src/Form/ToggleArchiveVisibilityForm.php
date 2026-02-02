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
 * Provides a confirmation form for toggling archive visibility.
 *
 * Toggles between archived_public and archived_admin with audit trail.
 * The archive_classification_date remains unchanged.
 */
final class ToggleArchiveVisibilityForm extends ConfirmFormBase {

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

    if ($current_status === 'archived_public') {
      return $this->t('Make %filename Admin-only?', [
        '%filename' => $this->archivedAsset->getFileName(),
      ]);
    }
    return $this->t('Make %filename public?', [
      '%filename' => $this->archivedAsset->getFileName(),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   HTML description for this confirmation form.
   */
  public function getDescription() {
    $current_status = $this->archivedAsset->getStatus();

    if ($current_status === 'archived_public') {
      // Public → Admin-only: Check for active usage and show appropriate warning.
      $description = '<p>' . $this->t('This item will no longer appear in the public Archive Registry.') . '</p>';

      // Check for active usage - warn that visitors will see access notice.
      $usage_count = $this->archiveService->getUsageCountByArchive($this->archivedAsset);
      if ($usage_count > 0) {
        $description .= '<div class="messages messages--warning">';

        // Find the digital_asset_item for this archive to link to usage page.
        // Use same lookup logic as ArchiveService::getUsageCountByArchive().
        $item_storage = \Drupal::entityTypeManager()->getStorage('digital_asset_item');
        $original_fid = $this->archivedAsset->getOriginalFid();
        $original_path = $this->archivedAsset->getOriginalPath();

        $query = $item_storage->getQuery()->accessCheck(FALSE);
        if ($original_fid) {
          $query->condition('fid', $original_fid);
        }
        else {
          $query->condition('file_path', $original_path);
        }

        $ids = $query->execute();
        $item_id = !empty($ids) ? reset($ids) : NULL;

        // Build the usage message with linked count.
        if ($item_id) {
          $location_link = '<a href="/admin/digital-asset-inventory/usage/' . $item_id . '">' .
            $this->formatPlural($usage_count, '1 location', '@count locations') . '</a>';
          $description .= '<p><strong>' . $this->t('This item is referenced in @locations.', [
            '@locations' => Markup::create($location_link),
          ]) . '</strong></p>';
        }
        else {
          // Fallback if item not found in inventory.
          $description .= '<p><strong>' . $this->formatPlural($usage_count,
            'This item is referenced in 1 location.',
            'This item is referenced in @count locations.'
          ) . '</strong></p>';
        }

        $description .= '<p>' . $this->t('After making it Admin-only, visitors clicking these links will see an access notice instead of the file.') . '</p>';
        $description .= '</div>';
      }

      return Markup::create($description);
    }
    else {
      // Admin-only → Public: More detailed explanation.
      $is_manual = $this->archivedAsset->isManualEntry();
      $item_type = $is_manual ? $this->t('entry') : $this->t('document');

      $description = '<div class="messages messages--warning">';
      $description .= '<h3>' . $this->t('Make Archive Public') . '</h3>';
      $description .= '<p>' . $this->t('This action will:') . '</p>';
      $description .= '<ul>';
      $description .= '<li>' . $this->t('Add the @item_type to the public Archive Registry', ['@item_type' => $item_type]) . '</li>';
      $description .= '<li>' . $this->t('Make the @item_type publicly accessible', ['@item_type' => $item_type]) . '</li>';
      $description .= '<li>' . $this->t('Log this visibility change for audit trail') . '</li>';
      $description .= '</ul>';
      $description .= '</div>';

      return Markup::create($description);
    }
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
    if ($this->archivedAsset && $this->archivedAsset->getStatus() === 'archived_public') {
      return $this->t('Make Admin-only');
    }
    return $this->t('Make Public');
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

    // Check if toggling to public is blocked due to in-use + config disabled.
    $visibility_blocked = $this->archiveService->isVisibilityToggleBlocked($this->archivedAsset);
    if ($visibility_blocked) {
      // Show blocking notice instead of the normal form.
      $form['visibility_blocked'] = [
        '#type' => 'item',
        '#markup' => '<div class="dai-policy-notice dai-policy-notice--error">
          <h3>' . $this->t('Visibility change blocked') . '</h3>
          <p>' . $this->t('This item is currently in use. Making it public would expose archived content while it is still referenced, which is not allowed under current settings.') . '</p>
          <p>' . $this->t('To make this item public, either:') . '</p>
          <ul>
            <li>' . $this->t('Remove all references from site content, <strong>or</strong>') . '</li>
            <li>' . $this->t('Contact an administrator to review archive policy settings.') . '</li>
          </ul>
        </div>',
        '#weight' => -100,
      ];

      // Show file info for context.
      $this->buildFileInfoSection($form);

      // Only show cancel button.
      $form['actions'] = [
        '#type' => 'actions',
        '#weight' => 100,
      ];

      $form['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Return to Archive Management'),
        '#url' => $this->getCancelUrl(),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
          'role' => 'button',
        ],
      ];

      // Attach admin CSS library.
      $form['#attached']['library'][] = 'digital_asset_inventory/admin';

      return $form;
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
        $info_content .= '<li><strong>' . $this->t('Archive URL:') . '</strong> <a href="' . $archive_path . '">' . htmlspecialchars($archive_path) . '</a></li>';
      }
      $asset_type = $this->archivedAsset->getAssetType();
      $info_content .= '<li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>';
    }
    
    $info_content .= '<li><strong>' . $this->t('Status:') . '</strong> ' . $status_label . '</li>';
    if ($archived_date) {
      $info_content .= '<li><strong>' . $this->t('Archive Classification Date:') . '</strong> ' . \Drupal::service('date.formatter')->format($archived_date, 'custom', 'c') . ' <em>(will remain unchanged)</em></li>';
    }

    // Archive Purpose with public description inline.
    $purpose_text = htmlspecialchars($archive_reason_label);
    if (!empty($public_description)) {
      $purpose_text .= ' &ndash; ' . nl2br(htmlspecialchars($public_description));
    }
    $info_content .= '<li><strong>' . $this->t('Archive Purpose:') . '</strong> ' . $purpose_text . '</li>';
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
   * Builds the file/entry info section for the form.
   *
   * @param array &$form
   *   The form array to add the section to.
   */
  protected function buildFileInfoSection(array &$form): void {
    $file_name = $this->archivedAsset->getFileName();
    $status_label = $this->archivedAsset->getStatusLabel();
    $is_manual = $this->archivedAsset->isManualEntry();

    // Determine appropriate title based on entry type.
    $details_title = $is_manual ? $this->t('Entry Information') : $this->t('Archive Information');

    $form['file_info'] = [
      '#type' => 'details',
      '#title' => $details_title,
      '#open' => FALSE,
      '#weight' => -90,
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
      $info_content .= '<li><strong>' . $this->t('Content Type:') . '</strong> ' . htmlspecialchars($asset_type_label) . '</li>';
    }
    else {
      $info_content .= '<li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>';
      $archive_path = $this->archivedAsset->getArchivePath();
      if (!empty($archive_path)) {
        $info_content .= '<li><strong>' . $this->t('Archive URL:') . '</strong> <a href="' . $archive_path . '">' . htmlspecialchars($archive_path) . '</a></li>';
      }
      $asset_type = $this->archivedAsset->getAssetType();
      $info_content .= '<li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>';
    }

    $info_content .= '<li><strong>' . $this->t('Status:') . '</strong> ' . $status_label . '</li>';
    $info_content .= '</ul>';

    $form['file_info']['content'] = [
      '#markup' => $info_content,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_name = $this->archivedAsset->getFileName();
    $previous_status = $this->archivedAsset->getStatus();
    $usage_count = $this->archiveService->getUsageCountByArchive($this->archivedAsset);

    try {
      $this->archiveService->toggleVisibility($this->archivedAsset);

      $new_status = $this->archivedAsset->getStatus();

      // Log visibility change with full context for audit trail.
      \Drupal::logger('digital_asset_inventory')->notice('Visibility changed for @filename: @previous → @new (usage count: @usage, actor: @actor)', [
        '@filename' => $file_name,
        '@previous' => $previous_status,
        '@new' => $new_status,
        '@usage' => $usage_count,
        '@actor' => \Drupal::currentUser()->getAccountName(),
      ]);

      if ($new_status === 'archived_public') {
        // Made public.
        $this->messenger->addStatus($this->t('"%filename" is now visible on the public Archive Registry.', [
          '%filename' => $file_name,
        ]));
      }
      else {
        // Made admin-only.
        $this->messenger->addStatus($this->t('"%filename" has been removed from the public Archive Registry.', [
          '%filename' => $file_name,
        ]));

        // Additional warning if item is still in use.
        if ($usage_count > 0) {
          $this->messenger->addWarning($this->t('Note: This item is still referenced in @count location(s). Visitors clicking those links will see an access notice instead of the file.', [
            '@count' => $usage_count,
          ]));
        }
      }
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
