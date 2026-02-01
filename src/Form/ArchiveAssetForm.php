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
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;
use Drupal\digital_asset_inventory\Service\ArchiveService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for marking a digital asset for archive.
 *
 * Collects the archive reason and creates a pending ArchivedAsset entity.
 */
final class ArchiveAssetForm extends FormBase {

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
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The form array or redirect response for access control.
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

    // Block queuing if file is in use and allow_archive_in_use is disabled.
    // Per test case AIU-3: queuing is blocked when policy disallows in-use archiving.
    if ($usage_count > 0 && !$this->archiveService->isArchiveInUseAllowed()) {
      $usage_url = Url::fromRoute('view.digital_asset_usage.page_1', ['arg_0' => $this->asset->id()])->toString();
      $this->messenger->addError($this->t('This asset cannot be queued for archive because it is currently in use (<a href="@usage_url">@count reference(s)</a>). To queue this asset, either remove all content references or ask an administrator to enable "Allow archiving documents and videos while in use" in settings.', [
        '@usage_url' => $usage_url,
        '@count' => $usage_count,
      ]));
      return $this->redirect('view.digital_assets.page_inventory');
    }

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

    // Attach admin CSS library for button styling.
    $form['#attached']['library'][] = 'digital_asset_inventory/admin';

    // 1. Page intro (subtext only - title comes from route).
    $form['page_intro'] = [
      '#type' => 'item',
      '#markup' => '<p class="dai-page-subtitle">' . $this->t('This step records archive intent. Archiving is completed in a later step.') . '</p>',
      '#weight' => -120,
    ];

    // 2. Reference Context - Archive Information (collapsed).
    $form['archive_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Requirements'),
      '#description' => $this->t('Expand to review archiving requirements'),
      '#open' => FALSE,
      '#weight' => -110,
      '#attributes' => ['role' => 'group'],
    ];

    $form['archive_info']['content'] = [
      '#markup' => '<p><strong>' . $this->t('Legacy Archives (ADA Title II)') . '</strong></p>
        <p>' . $this->t('Under ADA Title II (updated April 2024), archived content is exempt from WCAG 2.1 AA requirements if ALL conditions are met:') . '</p>
        <ol>
          <li>' . $this->t('Content was archived before @deadline', ['@deadline' => $deadline_formatted]) . '</li>
          <li>' . $this->t('Content is kept only for <strong>Reference</strong>, <strong>Research</strong>, or <strong>Recordkeeping</strong>') . '</li>
          <li>' . $this->t('Content is kept in a special archive area (<code>/archive-registry</code> subdirectory)') . '</li>
          <li>' . $this->t('Content has not been changed since archived') . '</li>
        </ol>
        <p>' . $this->t('If a Legacy Archive is modified after the deadline, the ADA exemption is automatically voided.') . '</p>

        <p><strong>' . $this->t('General Archives') . '</strong></p>
        <p>' . $this->t('Content archived after @deadline is classified as a General Archive:', ['@deadline' => $deadline_formatted]) . '</p>
        <ul>
          <li>' . $this->t('Retained for reference, research, or recordkeeping purposes') . '</li>
          <li>' . $this->t('Does not claim ADA Title II accessibility exemption') . '</li>
          <li>' . $this->t('Available in the public Archive Registry for reference') . '</li>
          <li>' . $this->t('If modified after archiving, removed from public view and flagged for audit') . '</li>
        </ul>

        <p><strong>' . $this->t('Important:') . '</strong> ' . $this->t('If someone requests that an archived document be made accessible, it must be remediated promptly.') . '</p>',
    ];

    // Two-step workflow explanation (collapsed by default to save space).
    $form['workflow_info'] = [
      '#type' => 'details',
      '#title' => $this->t('About the Two-Step Archive Process'),
      '#description' => $this->t('Expand to review the archive process'),
      '#open' => FALSE,
      '#weight' => -100,
      '#attributes' => ['role' => 'group'],
    ];

    $form['workflow_info']['content'] = [
      '#markup' => '<p>' . $this->t('This is <strong>Step 1</strong> – queuing the document for archive. The file will NOT be moved yet.') . '</p>
        <ol>
          <li><strong>' . $this->t('Step 1 (this form):') . '</strong> ' . $this->t('Queue the document and provide archive details.') . '</li>
          <li><strong>' . $this->t('Step 2 (Archive Management):') . '</strong> ' . $this->t('Review and execute the archive to finalize visibility and classification.') . '</li>
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

    // Check if file is in use. Note: If allow_archive_in_use = FALSE and file
    // is in use, we already redirected above. So at this point, if usage > 0
    // we know that in-use archiving is allowed.
    $is_in_use = $usage_count > 0;
    $is_private = $this->asset->isPrivate();

    // Usage warning if file is in use (only shown when allow_archive_in_use = TRUE).
    // Note: If allow_archive_in_use = FALSE and file is in use, we redirect
    // early (see check above), so this code path only executes when in-use
    // archiving is allowed.
    if ($usage_count > 0) {
      $usage_url = Url::fromRoute('view.digital_asset_usage.page_1', ['arg_0' => $this->asset->id()])->toString();

      // 3. Archive Validation (Green info box).
      $form['validation_complete'] = [
        '#type' => 'item',
        '#markup' => '<div class="archive-validation-panel">
          <h2>' . $this->t('Archive validation complete') . '</h2>
          <ul>
            <li>' . $this->t('✓ File exists at its original location') . '</li>
            <li>' . $this->t('✓ In-use archiving is enabled for this asset type') . '</li>
          </ul>
          <p>' . $this->t('This item meets the system requirements to be queued for archiving.') . '</p>
          <p class="archive-validation-note">' . $this->t('Queueing does not remove the file. The file remains in its current location until archiving is completed.') . '</p>
        </div>',
        '#weight' => -95,
      ];

      // 4. Impact Summary (Yellow warning box - future-state).
      $public_note = '';
      if (!$is_private) {
        $public_note = '<p class="dai-in-use-public-note">' . $this->t('Note: This file is stored in a public directory. The direct file URL may still be accessible to users who already have it.') . '</p>';
      }

      $form['usage_warning'] = [
        '#type' => 'item',
        '#markup' => '<div class="dai-in-use-warning" role="status">
          <h2>' . $this->t('This item is currently in use') . '</h2>
          <p class="dai-in-use-location">' . $this->formatPlural($usage_count, 'Referenced in 1 location.', 'Referenced in @count locations.') . ' <a href="' . $usage_url . '">' . $this->t('View usage locations') . '</a></p>
          <p>' . $this->t('When archived, site links will route to the Archive Detail Page instead of serving the file directly. Existing references will continue to work and will display archive context before access.') . '</p>
          ' . $public_note . '
        </div>',
        '#weight' => -90,
      ];

      // 5. Explicit Acknowledgment (gates everything below).
      $form['confirm_archive_in_use'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I understand that this item is currently in use and that, when archived, site links will route to the Archive Detail Page.'),
        '#required' => TRUE,
        '#weight' => -85,
      ];
    }

    // 6. Archive Details section with intro text.
    if ($is_in_use) {
      $form['archive_details_container'] = [
        '#type' => 'container',
        '#weight' => -10,
        '#tree' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="confirm_archive_in_use"]' => ['checked' => TRUE],
          ],
        ],
      ];
      $form['archive_details_container']['label'] = [
        '#markup' => '<h3 class="archive-details-label">' . $this->t('Archive Details') . '</h3>
          <p>' . $this->t('Provide information that will appear on the Archive Registry and explain why this item should be archived.') . '</p>',
      ];
    }
    else {
      $form['archive_details_label'] = [
        '#type' => 'item',
        '#markup' => '<h3 class="archive-details-label">' . $this->t('Archive Details') . '</h3>
          <p>' . $this->t('Provide information that will appear on the Archive Registry and explain why this item should be archived.') . '</p>',
        '#weight' => -10,
      ];
    }

    // Archive reason field (required).
    // Per ADA Title II, archived content must be retained for one of these.
    $archive_reason_base = [
      '#type' => 'select',
      '#title' => $this->t('Archive Reason'),
      '#description' => $this->t('Select the primary purpose for retaining this document. This will be displayed on the public Archive Registry.'),
      '#required' => TRUE,
      '#empty_option' => $this->t('– Select archive purpose –'),
      '#empty_value' => '',
      '#options' => [
        'reference' => $this->t('Reference - Content retained for informational purposes'),
        'research' => $this->t('Research - Material retained for research or study'),
        'recordkeeping' => $this->t('Recordkeeping - Content retained for compliance or official records'),
        'other' => $this->t('Other - Specify a custom reason'),
      ],
      '#weight' => 0,
    ];

    // Custom reason field (shown when "Other" is selected).
    $archive_reason_other_base = [
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
    $public_description_base = [
      '#type' => 'textarea',
      '#title' => $this->t('Public Description'),
      '#description' => $this->t('This description will be displayed on the public Archive Registry. Explain why this document is archived and its relevance to users who may need it.'),
      '#default_value' => $this->t('This material has been archived for reference purposes only. It is no longer maintained and may not reflect current information.'),
      '#required' => TRUE,
      '#rows' => 4,
      '#weight' => 2,
    ];

    // Internal notes (optional, admin only).
    $internal_notes_base = [
      '#type' => 'textarea',
      '#title' => $this->t('Internal Notes'),
      '#description' => $this->t('Optional notes visible only to administrators. Not shown on the public Archive Registry.'),
      '#rows' => 3,
      '#weight' => 3,
    ];

    // When in use, nest fields inside the gated container.
    if ($is_in_use) {
      $form['archive_details_container']['archive_reason'] = $archive_reason_base;
      $form['archive_details_container']['archive_reason_other'] = $archive_reason_other_base;
      $form['archive_details_container']['public_description'] = $public_description_base;
      $form['archive_details_container']['internal_notes'] = $internal_notes_base;
    }
    else {
      $form['archive_reason'] = $archive_reason_base;
      $form['archive_reason_other'] = $archive_reason_other_base;
      $form['public_description'] = $public_description_base;
      $form['internal_notes'] = $internal_notes_base;
    }

    // 7. File Information (collapsible, collapsed by default, below Archive Details).
    $file_info_content = '<ul>
      <li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($file_name) . '</li>
      <li><strong>' . $this->t('File URL:') . '</strong> <a href="' . $file_url . '">' . $file_url . '</a></li>
      <li><strong>' . $this->t('File type:') . '</strong> ' . strtoupper($asset_type) . '</li>
      <li><strong>' . $this->t('File size:') . '</strong> ' . ByteSizeMarkup::create($filesize) . '</li>
    </ul>';

    if ($is_in_use) {
      $form['archive_details_container']['file_info'] = [
        '#type' => 'details',
        '#title' => $this->t('File Information'),
        '#open' => FALSE,
        '#weight' => 10,
        '#attributes' => ['role' => 'group'],
      ];
      $form['archive_details_container']['file_info']['content'] = [
        '#markup' => $file_info_content,
      ];
    }
    else {
      $form['file_info'] = [
        '#type' => 'details',
        '#title' => $this->t('File Information'),
        '#open' => FALSE,
        '#weight' => 10,
        '#attributes' => ['role' => 'group'],
      ];
      $form['file_info']['content'] = [
        '#markup' => $file_info_content,
      ];
    }

    // Hidden asset ID.
    $form['asset_id'] = [
      '#type' => 'hidden',
      '#value' => $this->asset->id(),
    ];

    // Helper text above actions.
    $form['actions_helper'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('This action adds the asset to the archive queue. Archiving is completed in a later step.') . '</p>',
      '#weight' => 99,
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

    // Disable submit until confirmation checkbox is checked (when in use).
    if ($is_in_use) {
      $form['actions']['submit']['#states'] = [
        'disabled' => [
          ':input[name="confirm_archive_in_use"]' => ['checked' => FALSE],
        ],
      ];
    }

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Return to Inventory'),
      '#url' => Url::fromRoute('view.digital_assets.page_inventory'),
      '#attributes' => [
        'class' => ['button', 'button--secondary'],
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
