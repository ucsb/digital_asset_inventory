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

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\digital_asset_inventory\Entity\DigitalAssetArchive;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding notes to an archive entry.
 *
 * This form is embedded on the notes page and submits back to the same page.
 * Notes are append-only; this form only creates new notes.
 */
final class AddArchiveNoteForm extends FormBase {

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
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs an AddArchiveNoteForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    AccountProxyInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_add_archive_note_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, DigitalAssetArchive $archive = NULL) {
    if (!$archive) {
      return $form;
    }

    // Store archive in form state for use in submit handler.
    $form_state->set('archive', $archive);

    $form['note_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Add a note'),
      '#description' => $this->t('Record decisions, status updates, or compliance-related context. Maximum 500 characters.'),
      '#maxlength' => 500,
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Record a review update, decision, or compliance-related context...'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Note'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $text = trim($form_state->getValue('note_text'));

    if ($text === '') {
      $form_state->setErrorByName('note_text', $this->t('Note cannot be empty.'));
      return;
    }

    if (mb_strlen($text) > 500) {
      $form_state->setErrorByName('note_text', $this->t('Note cannot exceed 500 characters.'));
      return;
    }

    // Store trimmed value for submit handler.
    $form_state->setValue('note_text', $text);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $archive = $form_state->get('archive');
    $note_text = $form_state->getValue('note_text');

    // Create the note entity.
    $note_storage = $this->entityTypeManager->getStorage('dai_archive_note');
    $note = $note_storage->create([
      'archive_id' => $archive->id(),
      'note_text' => $note_text,
      'author' => $this->currentUser->id(),
    ]);
    $note->save();

    // Invalidate cache tags so the note count updates in Views.
    Cache::invalidateTags($archive->getCacheTags());

    // Log the note creation.
    \Drupal::logger('digital_asset_inventory')->notice(
      'Note added to archive @archive_id by user @uid.',
      ['@archive_id' => $archive->id(), '@uid' => $this->currentUser->id()]
    );

    $this->messenger->addStatus($this->t('Note added.'));

    // Redirect back to the notes page.
    $form_state->setRedirect('digital_asset_inventory.archive_notes', [
      'digital_asset_archive' => $archive->id(),
    ]);
  }

}
