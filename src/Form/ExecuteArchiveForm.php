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

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for executing a queued archive.
 *
 * Step 2 of the two-step archive workflow. This form validates all
 * execution gates (file exists, no active usage), then updates the
 * DigitalAssetArchive entity status to 'archived'. Files remain at
 * their original location - archiving is a compliance classification.
 */
final class ExecuteArchiveForm extends ConfirmFormBase {

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The DigitalAssetArchive entity.
   *
   * @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive
   */
  protected $archivedAsset;

  /**
   * Constructs an ExecuteArchiveForm object.
   *
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    ArchiveService $archive_service,
    MessengerInterface $messenger,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->archiveService = $archive_service;
    $this->messenger = $messenger;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('digital_asset_inventory.archive'),
      $container->get('messenger'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_execute_archive_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Archive %filename', [
      '%filename' => $this->archivedAsset->getFileName(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    // Description is now provided in the gates_passed form element.
    return $this->t('');
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
    return $this->t('Archive Asset');
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

    // Handle already archived items.
    if ($this->archivedAsset->isArchived()) {
      $this->messenger->addWarning($this->t('This asset has already been archived (status: @status).', [
        '@status' => $this->archivedAsset->getStatusLabel(),
      ]));
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Validate status allows execution.
    if (!$this->archivedAsset->isQueued() && !$this->archivedAsset->isBlocked()) {
      $this->messenger->addError($this->t('This asset cannot be archived (current status: @status).', [
        '@status' => $this->archivedAsset->getStatusLabel(),
      ]));
      return $this->redirect('view.digital_asset_archive.page_archive_management');
    }

    // Validate execution gates.
    $blocking_issues = $this->archiveService->validateExecutionGates($this->archivedAsset);

    if (!empty($blocking_issues)) {
      $issues_html = '<ul>';
      foreach ($blocking_issues as $key => $issue) {
        $issue_text = htmlspecialchars($issue);

        // If this is a usage issue, add a link to the usage detail page.
        if ($key === 'usage_detected') {
          $asset_id = $this->getAssetIdForArchive();
          if ($asset_id) {
            $usage_url = Url::fromRoute('view.digital_asset_usage.page_1', ['arg_0' => $asset_id])->toString();
            $issue_text .= ' <a href="' . $usage_url . '">' . $this->t('View usage locations') . '</a>';
          }
        }

        $issues_html .= '<li><strong>' . ucfirst(str_replace('_', ' ', $key)) . ':</strong> ' . $issue_text . '</li>';
      }
      $issues_html .= '</ul>';

      $form['gate_failure'] = [
        '#type' => 'item',
        '#markup' => '<div class="messages messages--error">
          <h3>' . $this->t('Action Required Before Archiving') . '</h3>
          <p>' . $this->t('The following issues must be resolved before archiving:') . '</p>
          ' . $issues_html . '
          <p>' . $this->t('After resolving these issues:') . '</p>
          <ol>
            <li>' . $this->t('Re-run the <a href="/admin/digital-asset-inventory">Digital Asset Inventory</a> scanner if you made content changes') . '</li>
            <li>' . $this->t('Return to <a href="/admin/digital-asset-inventory/archive">Archive Management</a> and click "Archive Asset" to try again') . '</li>
          </ol>
        </div>',
        '#weight' => -95,
      ];

      // Show file info but disable submit.
      $this->buildFileInfoSection($form);

      $form['actions'] = [
        '#type' => 'actions',
        '#weight' => 100,
      ];

      $form['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Back to Archive Management'),
        '#url' => $this->getCancelUrl(),
        '#attributes' => [
          'class' => ['button'],
          'role' => 'button',
        ],
      ];

      return $form;
    }

    // Gates passed - show validation complete panel.
    $is_ada_compliance_mode = $this->archiveService->isAdaComplianceMode();

    $form['gates_passed'] = [
      '#type' => 'item',
      '#markup' => '<div class="archive-validation-panel">
        <p><strong>' . $this->t('Archive validation complete') . '</strong></p>
        <ul>
          <li>' . $this->t('✓ File exists at its original location') . '</li>
          <li>' . $this->t('✓ No active content references detected') . '</li>
        </ul>
        <p>' . $this->t('This asset is ready to be archived.') . '</p>
        <p>' . $this->t('When archived, this asset will be registered in the archive system, assigned the selected visibility, and protected with an integrity checksum.') . '</p>
        <p class="archive-validation-note"><strong>' . $this->t('Note:') . '</strong> ' . $this->t('Archiving does not remove the file. The file remains in its current location and is classified for ADA Title II compliance purposes.') . '</p>
      </div>',
      '#weight' => -100,
    ];

    // Build file information section.
    $this->buildFileInfoSection($form);

    // Visibility selection.
    $form['visibility'] = [
      '#type' => 'radios',
      '#title' => $this->t('Archive Visibility'),
      '#description' => $this->t('Choose whether this archived document should be visible on the public Archive Registry or only in admin archive management.'),
      '#options' => [
        'public' => $this->t('Public - Visible on the public Archive Registry at /archive-registry'),
        'admin' => $this->t('Admin-only - Visible only in Archive Management'),
      ],
      '#default_value' => 'public',
      '#weight' => -70,
    ];

    // Classification helper text above actions.
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $deadline_timestamp = $config->get('ada_compliance_deadline') ?: strtotime('2026-04-24 00:00:00 UTC');
    $deadline_formatted = gmdate('F j, Y', $deadline_timestamp);

    if ($is_ada_compliance_mode) {
      $helper_text = '<strong>' . $this->t('Classification:') . '</strong> ' . $this->t('This document will be classified as a Legacy Archive (archived before @deadline) and may be eligible for ADA Title II accessibility exemption.', ['@deadline' => $deadline_formatted]);
    }
    else {
      $helper_text = '<strong>' . $this->t('Classification:') . '</strong> ' . $this->t('This document will be classified as a General Archive (archived after @deadline), retained for reference purposes without claiming ADA exemption.', ['@deadline' => $deadline_formatted]);
    }

    $form['actions_helper'] = [
      '#type' => 'item',
      '#markup' => '<p class="form-actions-helper">' . $helper_text . '</p>',
      '#weight' => 99,
    ];

    // Build the confirmation form.
    $form = parent::buildForm($form, $form_state);

    // Attach admin CSS library for button styling.
    $form['#attached']['library'][] = 'digital_asset_inventory/admin';

    // Style cancel as a secondary button.
    if (isset($form['actions']['cancel'])) {
      $form['actions']['cancel']['#attributes']['class'][] = 'button';
      $form['actions']['cancel']['#attributes']['class'][] = 'button--secondary';
    }

    return $form;
  }

  /**
   * Builds the file information section of the form.
   *
   * @param array $form
   *   The form array.
   */
  protected function buildFileInfoSection(array &$form) {
    $file_name = $this->archivedAsset->getFileName();
    $original_path = $this->archivedAsset->getOriginalPath();
    $asset_type = $this->archivedAsset->getAssetType();
    $filesize = $this->archivedAsset->getFilesize();
    $archive_reason_label = $this->archivedAsset->getArchiveReasonLabel();
    $public_description = $this->archivedAsset->getPublicDescription();
    $internal_notes = $this->archivedAsset->getInternalNotes();
    $created = $this->archivedAsset->get('created')->value;

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
      '#open' => FALSE,
      '#weight' => -90,
      '#attributes' => ['role' => 'group'],
    ];

    $form['file_info']['content'] = [
      '#markup' => '<ul>
        <li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>
        <li><strong>' . $this->t('File URL:') . '</strong> <a href="' . $file_url . '">' . htmlspecialchars($file_url) . '</a></li>
        <li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>
        <li><strong>' . $this->t('File size:') . '</strong> ' . ByteSizeMarkup::create($filesize) . '</li>
        <li><strong>' . $this->t('Queued for archive:') . '</strong> ' . \Drupal::service('date.formatter')->format($created, 'custom', 'c') . '</li>
      </ul>',
    ];

    $form['archive_reason_display'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Details'),
      '#open' => FALSE,
      '#weight' => -80,
      '#attributes' => ['role' => 'group'],
    ];

    $reason_content = '<ul>';
    $reason_content .= '<li><strong>' . $this->t('Archive Purpose:') . '</strong> ' . htmlspecialchars($archive_reason_label) . '</li>';
    $reason_content .= '</ul>';

    if (!empty($public_description)) {
      $reason_content .= '<p><strong>' . $this->t('Public Description:') . '</strong></p>';
      $reason_content .= '<blockquote class="archive-description-block">' .
        nl2br(htmlspecialchars($public_description)) .
        '</blockquote>';
    }

    if (!empty($internal_notes)) {
      $reason_content .= '<p><strong>' . $this->t('Internal Notes (admin only):') . '</strong></p>';
      $reason_content .= '<blockquote class="archive-notes-block">' .
        nl2br(htmlspecialchars($internal_notes)) .
        '</blockquote>';
    }

    $form['archive_reason_display']['content'] = [
      '#markup' => $reason_content,
    ];
  }

  /**
   * Gets the usage count for a file.
   *
   * @param int|null $fid
   *   The file ID.
   * @param string $original_path
   *   The original file path.
   *
   * @return int
   *   The usage count.
   */
  protected function getUsageCount($fid, $original_path) {
    $database = \Drupal::database();

    // Look up the DigitalAssetItem by fid or path.
    $query = $database->select('digital_asset_item', 'dai')
      ->fields('dai', ['id']);

    if ($fid) {
      $query->condition('fid', $fid);
    }
    else {
      $query->condition('file_path', $original_path);
    }

    $asset_id = $query->execute()->fetchField();

    if (!$asset_id) {
      return 0;
    }

    // Count usage records.
    return (int) $database->select('digital_asset_usage', 'dau')
      ->condition('asset_id', $asset_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Gets the digital_asset_item ID for the current archived asset.
   *
   * @return int|null
   *   The asset ID, or NULL if not found.
   */
  protected function getAssetIdForArchive() {
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

    $asset_id = $query->execute()->fetchField();
    return $asset_id ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // If blocked, reset to queued first so executeArchive can process it.
    if ($this->archivedAsset->isBlocked()) {
      $this->archivedAsset->setStatus('queued');
      $this->archivedAsset->save();
    }

    // Get visibility selection (required field, defaults to 'public').
    $visibility = $form_state->getValue('visibility') ?: 'public';

    try {
      $this->archiveService->executeArchive($this->archivedAsset, $visibility);

      $visibility_label = ($visibility === 'public') ? $this->t('public Archive Registry') : $this->t('admin archive management only');
      $this->messenger->addStatus($this->t('Document "%filename" has been successfully archived.', [
        '%filename' => $this->archivedAsset->getFileName(),
      ]));

      $this->messenger->addStatus($this->t('SHA256 checksum recorded for integrity verification. The document is now visible in @visibility.', [
        '@visibility' => $visibility_label,
      ]));
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error archiving "@filename": @error', [
        '@filename' => $this->archivedAsset->getFileName(),
        '@error' => $e->getMessage(),
      ]));

      \Drupal::logger('digital_asset_inventory')->error('Archive execution failed for @filename: @error', [
        '@filename' => $this->archivedAsset->getFileName(),
        '@error' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
  }

}
