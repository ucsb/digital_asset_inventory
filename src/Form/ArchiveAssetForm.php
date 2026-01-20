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
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for marking a digital asset for archive.
 *
 * Collects the archive reason and creates a pending ArchivedAsset entity.
 */
class ArchiveAssetForm extends FormBase {

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
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The digital asset item entity.
   *
   * @var \Drupal\digital_asset_inventory\Entity\DigitalAssetItem
   */
  protected $asset;

  /**
   * Constructs an ArchiveAssetForm object.
   *
   * @param \Drupal\digital_asset_inventory\Service\ArchiveService $archive_service
   *   The archive service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    ArchiveService $archive_service,
    MessengerInterface $messenger,
    FileUrlGeneratorInterface $file_url_generator,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->archiveService = $archive_service;
    $this->messenger = $messenger;
    $this->fileUrlGenerator = $file_url_generator;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('digital_asset_inventory.archive'),
      $container->get('messenger'),
      $container->get('file_url_generator'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_archive_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DigitalAssetItem $digital_asset_item = NULL) {
    $this->asset = $digital_asset_item;

    // Validate asset exists.
    if (!$this->asset) {
      $this->messenger->addError($this->t('Asset not found.'));
      return $this->redirect('view.digital_assets.page_inventory');
    }

    // Validate asset can be archived (is a document or video).
    if (!$this->archiveService->canArchive($this->asset)) {
      $this->messenger->addError($this->t('Only document assets (PDFs, Word, Excel, PowerPoint) and video files can be archived.'));
      return $this->redirect('view.digital_assets.page_inventory');
    }

    // Check if already has an active archive record (queued, archived, or voided).
    // This excludes archived_deleted status, allowing files to be re-archived as new entries.
    $active_archive = $this->archiveService->getActiveArchiveRecord($this->asset);
    if ($active_archive) {
      $status = $active_archive->getStatus();
      if ($status === 'queued') {
        $this->messenger->addWarning($this->t('This asset is already queued for archive. <a href="@url">View archive management</a>.', [
          '@url' => Url::fromRoute('view.digital_asset_archive.page_archive_management')->toString(),
        ]));
      }
      else {
        $this->messenger->addWarning($this->t('This asset has an active archive record (status: @status). <a href="@url">View archive management</a>.', [
          '@status' => $active_archive->getStatusLabel(),
          '@url' => Url::fromRoute('view.digital_asset_archive.page_archive_management')->toString(),
        ]));
      }
      return $this->redirect('view.digital_assets.page_inventory');
    }

    $file_name = $this->asset->get('file_name')->value;
    $file_path = $this->asset->get('file_path')->value;
    $asset_type = $this->asset->get('asset_type')->value;
    $filesize = $this->asset->get('filesize')->value;
    $usage_count = $this->archiveService->getUsageCount($this->asset);

    // Generate full URL from file path.
    if (strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0) {
      $file_url = $file_path;
    }
    else {
      $file_url = $this->fileUrlGenerator->generateAbsoluteString($file_path);
    }

    // Get the compliance deadline from config for display.
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $deadline_timestamp = $config->get('ada_compliance_deadline') ?: strtotime('2026-04-24 00:00:00 UTC');
    // Use gmdate() since timestamp is stored in UTC to avoid timezone shift.
    $deadline_formatted = gmdate('F j, Y', $deadline_timestamp);

    // Determine if we're in ADA compliance mode (before deadline) or general archive mode.
    $is_ada_compliance_mode = $this->archiveService->isAdaComplianceMode();

    // Archive Information - unified section explaining both archive types.
    $form['archive_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Requirements'),
      '#open' => FALSE,
      '#weight' => -110,
      '#attributes' => ['role' => 'group'],
    ];

    $form['archive_info']['content'] = [
      '#markup' => '<div class="archive-requirements-info">
        <h3>' . $this->t('Legacy Archives (ADA Title II)') . '</h3>
        <p>' . $this->t('Under ADA Title II (updated April 2024), archived content is exempt from WCAG 2.1 AA requirements if ALL conditions are met:') . '</p>
        <ol>
          <li>' . $this->t('Content was archived before @deadline', ['@deadline' => $deadline_formatted]) . '</li>
          <li>' . $this->t('Content is kept only for <strong>Reference</strong>, <strong>Research</strong>, or <strong>Recordkeeping</strong>') . '</li>
          <li>' . $this->t('Content is kept in a special archive area (<code>/archive-registry</code> subdirectory)') . '</li>
          <li>' . $this->t('Content has not been changed since archived') . '</li>
        </ol>
        <p>' . $this->t('If a Legacy Archive is modified after the deadline, the ADA exemption is automatically voided.') . '</p>

        <h3>' . $this->t('General Archives') . '</h3>
        <p>' . $this->t('Content archived after @deadline is classified as a General Archive:', ['@deadline' => $deadline_formatted]) . '</p>
        <ul>
          <li>' . $this->t('Retained for reference, research, or recordkeeping purposes') . '</li>
          <li>' . $this->t('Does not claim ADA Title II accessibility exemption') . '</li>
          <li>' . $this->t('Available in the public Archive Registry for reference') . '</li>
          <li>' . $this->t('If modified after archiving, removed from public view and flagged for audit') . '</li>
        </ul>

        <p><strong>' . $this->t('Important:') . '</strong> ' . $this->t('If someone requests that an archived document be made accessible, it must be remediated promptly.') . '</p>
      </div>',
    ];

    // Two-step workflow explanation (collapsed by default to save space).
    $form['workflow_info'] = [
      '#type' => 'details',
      '#title' => $this->t('About the Two-Step Archive Process'),
      '#open' => FALSE,
      '#weight' => -100,
      '#attributes' => ['role' => 'group'],
    ];

    $form['workflow_info']['content'] = [
      '#markup' => '<p>' . $this->t('This is <strong>Step 1</strong> – queuing the document for archive. The file will NOT be moved yet.') . '</p>
        <ol>
          <li><strong>' . $this->t('Step 1 (this form):') . '</strong> ' . $this->t('Queue the document and provide archive details.') . '</li>
          <li><strong>' . $this->t('Step 2 (Archive Management):') . '</strong> ' . $this->t('Execute the archive after removing any active references.') . '</li>
        </ol>
        <p>' . $this->t('After queuing, the document will appear in Archive Management where you can execute or cancel the archive.') . '</p>',
    ];

    // Determine archive type text based on current date.
    if ($is_ada_compliance_mode) {
      $archive_type_text = $this->t('Legacy Archive (archived before @deadline)', ['@deadline' => $deadline_formatted]);
    }
    else {
      $archive_type_text = $this->t('General Archive (archived after @deadline)', ['@deadline' => $deadline_formatted]);
    }

    // File information (below ADA Archive Requirements).
    $form['file_info'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages messages--status">
        <h3>' . $this->t('File Information') . '</h3>
        <ul>
          <li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>
          <li><strong>' . $this->t('File URL:') . '</strong> <a href="' . $file_url . '" target="_blank" rel="noopener">' . $file_url . '</a></li>
          <li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>
          <li><strong>' . $this->t('File size:') . '</strong> ' . \format_size($filesize) . '</li>
          <li><strong>' . $this->t('Currently used in:') . '</strong> ' . $this->formatPlural($usage_count, '1 location', '@count locations') . '</li>
          <li><strong>' . $this->t('Archive type:') . '</strong> ' . $archive_type_text . '</li>
        </ul>
      </div>',
      '#weight' => -95,
    ];

    // Usage warning if file is in use.
    if ($usage_count > 0) {
      $usage_url = Url::fromRoute('view.digital_asset_usage.page_1', ['arg_0' => $this->asset->id()])->toString();

      $form['usage_warning'] = [
        '#type' => 'item',
        '#markup' => '<div class="messages messages--error">
          <h3>' . $this->t('Action Required Before Archiving') . '</h3>
          <p>' . $this->t('This file is currently used in @count location(s). Before the archive can be completed, you must:', [
            '@count' => $usage_count,
          ]) . '</p>
          <ol>
            <li>' . $this->t('Review the content using this file: <a href="@url">View usage locations</a>', ['@url' => $usage_url]) . '</li>
            <li>' . $this->t('Edit each content item to either remove or update the file reference') . '</li>
            <li>' . $this->t('Add a disclaimer noting the document has been archived (suggested text below)') . '</li>
            <li>' . $this->t('Re-run the Digital Asset Inventory scanner') . '</li>
            <li>' . $this->t('Return to the Archive Management page to complete the archive') . '</li>
          </ol>
        </div>',
        '#weight' => -80,
      ];

      $form['disclaimer_suggestion'] = [
        '#type' => 'details',
        '#title' => $this->t('Suggested Disclaimer Text'),
        '#open' => FALSE,
        '#weight' => -70,
        '#attributes' => ['role' => 'group'],
      ];

      $form['disclaimer_suggestion']['text'] = [
        '#markup' => '<div class="form-item">
          <h4>' . $this->t('Where to add the disclaimer') . '</h4>
          <p>' . $this->t('Add the disclaimer text on any page that currently links to this document. Place it above or in place of the existing document link.') . '</p>

          <h4>' . $this->t('Suggested text') . '</h4>
          <blockquote style="background: #f5f5f5; padding: 15px; border-left: 4px solid #0073aa; margin: 10px 0; line-height: 1.5;">
            <em>' . $this->t('This document has been archived and is available for reference purposes only. If you need an accessible version of this document, please contact [department/email].') . '</em><br><br>
            <a href="/archive-registry">' . $this->t('View archived document') . '</a>
          </blockquote>

          <h4>' . $this->t('Important: Use the Archive URL') . '</h4>
          <p>' . $this->t('When linking to archived documents, <strong>always use the Archive Registry URL</strong> (e.g., <code>/archive-registry/[id]</code>), not the direct file URL. The archive page provides important context about the document\'s archived status and accessibility options.') . '</p>
          <p>' . $this->t('After archiving is complete, you can find the specific archive URL for this document in the Archive Management page.') . '</p>
        </div>',
      ];
    }

    // Archive reason field (required).
    // Per ADA Title II, archived content must be retained for one of these.
    $form['archive_reason'] = [
      '#type' => 'select',
      '#title' => $this->t('Archive Reason'),
      '#description' => $this->t('Select the primary purpose for retaining this document. This will be displayed on the public Archive Registry.'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select a reason -'),
        'reference' => $this->t('Reference - Content retained for informational purposes'),
        'research' => $this->t('Research - Material retained for research or study'),
        'recordkeeping' => $this->t('Recordkeeping - Content retained for compliance or official records'),
        'other' => $this->t('Other - Specify a custom reason'),
      ],
      '#weight' => 0,
    ];

    // Custom reason field (shown when "Other" is selected).
    $form['archive_reason_other'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Specify Reason'),
      '#description' => $this->t('Enter the reason for archiving this document.'),
      '#rows' => 3,
      '#weight' => 1,
      '#states' => [
        'visible' => [
          ':input[name="archive_reason"]' => ['value' => 'other'],
        ],
        'required' => [
          ':input[name="archive_reason"]' => ['value' => 'other'],
        ],
      ],
    ];

    // Public description for Archive Registry (required).
    $form['public_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Public Description'),
      '#description' => $this->t('This description will be displayed on the public Archive Registry. Explain why this document is archived and its relevance to users who may need it.'),
      '#default_value' => $this->t('This material has been archived and is available for reference purposes only. It is no longer updated and may not reflect current information.'),
      '#required' => TRUE,
      '#rows' => 4,
      '#weight' => 2,
    ];

    // Internal notes (optional, admin only).
    $form['internal_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Internal Notes'),
      '#description' => $this->t('Optional notes visible only to administrators. Not shown on the public Archive Registry.'),
      '#rows' => 3,
      '#weight' => 3,
    ];

    // Hidden asset ID.
    $form['asset_id'] = [
      '#type' => 'hidden',
      '#value' => $this->asset->id(),
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue for Archive'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('view.digital_assets.page_inventory'),
      '#attributes' => [
        'class' => ['button'],
        'role' => 'button',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $reason = $form_state->getValue('archive_reason');
    $valid_reasons = ['reference', 'research', 'recordkeeping', 'other'];

    if (empty($reason) || !in_array($reason, $valid_reasons)) {
      $form_state->setErrorByName('archive_reason', $this->t('Please select a valid archive reason.'));
    }

    // If "Other" is selected, require the custom reason.
    if ($reason === 'other') {
      $custom_reason = trim($form_state->getValue('archive_reason_other'));
      if (empty($custom_reason)) {
        $form_state->setErrorByName('archive_reason_other', $this->t('Please specify the reason for archiving.'));
      }
      elseif (strlen($custom_reason) < 10) {
        $form_state->setErrorByName('archive_reason_other', $this->t('Please provide a more detailed reason (at least 10 characters).'));
      }
    }

    // Validate public description (required).
    $public_description = trim($form_state->getValue('public_description') ?? '');
    if (empty($public_description)) {
      $form_state->setErrorByName('public_description', $this->t('Please provide a public description for the Archive Registry.'));
    }
    elseif (strlen($public_description) < 20) {
      $form_state->setErrorByName('public_description', $this->t('Please provide a more detailed public description (at least 20 characters).'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $reason = $form_state->getValue('archive_reason');
    $reason_other = trim($form_state->getValue('archive_reason_other') ?? '');
    $public_description = trim($form_state->getValue('public_description') ?? '');
    $internal_notes = trim($form_state->getValue('internal_notes') ?? '');

    try {
      $this->archiveService->markForArchive(
        $this->asset,
        $reason,
        $reason_other,
        $public_description,
        $internal_notes
      );

      $usage_count = $this->archiveService->getUsageCount($this->asset);

      if ($usage_count > 0) {
        $this->messenger->addStatus($this->t('Asset "@filename" has been queued for archive. Please remove file references from content before executing the archiving of this digital asset.', [
          '@filename' => $this->asset->get('file_name')->value,
        ]));
      }
      else {
        $this->messenger->addStatus($this->t('Asset "@filename" has been queued for archive. You can now execute the archive from the Archive Management page.', [
          '@filename' => $this->asset->get('file_name')->value,
        ]));
      }

      // Redirect to archive management page.
      $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error queuing asset for archive: @error', [
        '@error' => $e->getMessage(),
      ]));
      $form_state->setRedirect('view.digital_assets.page_inventory');
    }
  }

}
