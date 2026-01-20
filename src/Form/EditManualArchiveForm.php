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

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing a manual archive entry.
 *
 * Only manual entries (those with no original_fid) can be edited.
 * File-based archives are locked for integrity compliance.
 */
class EditManualArchiveForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The archive entity being edited.
   *
   * @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive
   */
  protected $archive;

  /**
   * Constructs an EditManualArchiveForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    DateFormatterInterface $date_formatter,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_edit_manual_archive_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DigitalAssetArchive $digital_asset_archive = NULL) {
    $this->archive = $digital_asset_archive;

    // Validate archive exists.
    if (!$this->archive) {
      $this->messenger->addError($this->t('Archive entry not found.'));
      $url = Url::fromRoute('view.digital_asset_archive.page_archive_management')->toString();
      return new TrustedRedirectResponse($url);
    }

    // Validate this is a manual entry (can be edited).
    if (!$this->archive->canEdit()) {
      $this->messenger->addError($this->t('Only manual archive entries can be edited. File-based archives are locked for integrity compliance.'));
      $url = Url::fromRoute('view.digital_asset_archive.page_archive_management')->toString();
      return new TrustedRedirectResponse($url);
    }

    $form['#prefix'] = '<div class="edit-manual-archive-form">';
    $form['#suffix'] = '</div>';

    // Introduction.
    $form['intro'] = [
      '#markup' => '<div class="messages messages--status">
        <h3>' . $this->t('Edit Archive Entry') . '</h3>
        <p>' . $this->t('Update the details for this manual archive entry. Changes will be reflected immediately on the public Archive Registry.') . '</p>
      </div>',
      '#weight' => -100,
    ];

    // Title field.
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#description' => $this->t('A descriptive title for this archived item.'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $this->archive->getFileName(),
      '#weight' => 0,
    ];

    // URL field - read-only after creation.
    // The URL is immutable because:
    // 1. The archive classification date was set for this specific URL
    // 2. For internal pages, the "Archived Material" banner depends on this URL
    // 3. Changing would break the audit trail
    // If the URL is wrong, remove this entry and create a new one.
    $form['url'] = [
      '#type' => 'item',
      '#title' => $this->t('URL'),
      '#markup' => '<a href="' . htmlspecialchars($this->archive->getOriginalPath()) . '" target="_blank" rel="noopener">' . htmlspecialchars($this->archive->getOriginalPath()) . '</a>',
      '#description' => $this->t('The URL cannot be changed after the entry is created. If the URL is incorrect, remove this entry and create a new one.'),
      '#weight' => 1,
    ];

    // Asset type field - read-only (tied to the URL).
    $asset_type_labels = [
      'page' => $this->t('Web Page - An internal page on this website'),
      'external' => $this->t('External Resource - A document or page hosted elsewhere'),
    ];
    $current_asset_type = $this->archive->getAssetType();
    $form['asset_type'] = [
      '#type' => 'item',
      '#title' => $this->t('Content Type'),
      '#markup' => $asset_type_labels[$current_asset_type] ?? $current_asset_type,
      '#description' => $this->t('The content type is determined by the URL and cannot be changed.'),
      '#weight' => 2,
    ];

    // Archive reason field.
    $form['archive_reason'] = [
      '#type' => 'select',
      '#title' => $this->t('Archive Reason'),
      '#description' => $this->t('Select the primary purpose for retaining this content.'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select a reason -'),
        'reference' => $this->t('Reference - Content retained for informational purposes'),
        'research' => $this->t('Research - Content retained for research or study'),
        'recordkeeping' => $this->t('Recordkeeping - Content retained for compliance or official records'),
        'other' => $this->t('Other - Specify a custom reason'),
      ],
      '#default_value' => $this->archive->getArchiveReason(),
      '#weight' => 3,
    ];

    // Custom reason field (shown when "Other" is selected).
    $form['archive_reason_other'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Specify Reason'),
      '#description' => $this->t('Enter the reason for archiving this content.'),
      '#rows' => 3,
      '#default_value' => $this->archive->getArchiveReasonOther(),
      '#weight' => 4,
      '#states' => [
        'visible' => [
          ':input[name="archive_reason"]' => ['value' => 'other'],
        ],
        'required' => [
          ':input[name="archive_reason"]' => ['value' => 'other'],
        ],
      ],
    ];

    // Public description for Archive Registry.
    $form['public_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Public Description'),
      '#description' => $this->t('This description will be displayed on the public Archive Registry.'),
      '#required' => TRUE,
      '#rows' => 4,
      '#default_value' => $this->archive->getPublicDescription(),
      '#weight' => 5,
    ];

    // Internal notes (optional, admin only).
    $form['internal_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Internal Notes'),
      '#description' => $this->t('Optional notes visible only to administrators.'),
      '#rows' => 3,
      '#default_value' => $this->archive->getInternalNotes(),
      '#weight' => 6,
    ];

    // Archive metadata (read-only).
    $form['metadata'] = [
      '#type' => 'details',
      '#title' => $this->t('Archive Metadata'),
      '#open' => FALSE,
      '#weight' => 7,
      '#attributes' => ['role' => 'group'],
    ];

    $created = $this->dateFormatter->format(
      $this->archive->get('created')->value,
      'custom',
      'c'
    );
    $classification_date = $this->archive->getArchiveClassificationDate();
    $classified = $classification_date
      ? $this->dateFormatter->format($classification_date, 'custom', 'c')
      : $this->t('Not yet');

    $form['metadata']['info'] = [
      '#markup' => '<ul>
        <li><strong>' . $this->t('Created:') . '</strong> ' . $created . '</li>
        <li><strong>' . $this->t('Archive Classification Date:') . '</strong> ' . $classified . '</li>
        <li><strong>' . $this->t('Status:') . '</strong> ' . $this->archive->getStatusLabel() . '</li>
        <li><strong>' . $this->t('Archive ID:') . '</strong> ' . $this->archive->id() . '</li>
      </ul>',
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Changes'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('view.digital_asset_archive.page_archive_management'),
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
    // Validate archive reason.
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

    // Validate public description.
    $public_description = trim($form_state->getValue('public_description') ?? '');
    if (empty($public_description)) {
      $form_state->setErrorByName('public_description', $this->t('Please provide a public description for the Archive Registry.'));
    }
    elseif (strlen($public_description) < 20) {
      $form_state->setErrorByName('public_description', $this->t('Please provide a more detailed public description (at least 20 characters).'));
    }

    // Note: URL and asset_type are read-only and not validated here.
    // They are immutable after creation to preserve audit trail integrity.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $title = trim($form_state->getValue('title'));
    $reason = $form_state->getValue('archive_reason');
    $reason_other = trim($form_state->getValue('archive_reason_other') ?? '');
    $public_description = trim($form_state->getValue('public_description'));
    $internal_notes = trim($form_state->getValue('internal_notes') ?? '');

    try {
      // Update the archive entity.
      // Note: URL and asset_type are immutable and not updated here.
      $this->archive->setFileName($title);
      $this->archive->setArchiveReason($reason);
      $this->archive->setArchiveReasonOther($reason_other);
      $this->archive->setPublicDescription($public_description);
      $this->archive->setInternalNotes($internal_notes);

      $this->archive->save();

      $this->messenger->addStatus($this->t('The archive entry "@title" has been updated.', [
        '@title' => $title,
      ]));

      // Redirect to archive management page.
      $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error updating archive entry: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

}
