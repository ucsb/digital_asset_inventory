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

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\digital_asset_inventory\Entity\DigitalAssetItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for deleting a digital asset.
 */
final class DeleteAssetForm extends ConfirmFormBase {

  /**
   * The digital asset item entity.
   *
   * @var \Drupal\digital_asset_inventory\Entity\DigitalAssetItem
   */
  protected $entity;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a DeleteAssetForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    MessengerInterface $messenger,
    Connection $database,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->messenger = $messenger;
    $this->database = $database;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('messenger'),
      $container->get('database'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Complex HTML description for this confirmation form.
   */
  public function getDescription() {
    $description = '<h2>' . $this->t('Permanent Deletion Warning') . '</h2>';
    $description .= '<p>' . $this->t('You are about to permanently delete this file from the website.') . '</p>';
    $description .= '<ul>';
    $description .= '<li>' . $this->t('This action cannot be undone.') . '</li>';
    $description .= '<li>' . $this->t('The file will no longer be available at its URL.') . '</li>';
    $description .= '<li>' . $this->t('Any external links to this file will stop working.') . '</li>';
    $description .= '<li>' . $this->t('Older content revisions may display') . ' <q>' . $this->t('File not found') . '</q> ' . $this->t('where the file was previously used.') . '</li>';
    $description .= '<li>' . $this->t('Even if the file does not appear on current pages, it may still be used elsewhere.') . '</li>';
    $description .= '</ul>';
    $description .= '<p><strong>' . $this->t('Are you sure you want to permanently delete this file?') . '</strong></p>';

    return Markup::create($description);
  }

  /**
   * {@inheritdoc}
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The form array or redirect response for access control.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DigitalAssetItem $digital_asset_item = NULL) {
    $this->entity = $digital_asset_item;

    // Validate that this is an orphaned file, managed file, or media file.
    $source_type = $this->entity->get('source_type')->value;
    if ($source_type !== 'filesystem_only' && $source_type !== 'file_managed' && $source_type !== 'media_managed') {
      $this->messenger->addError($this->t('Only orphaned, unused managed files, or unused media files can be deleted using this method.'));
      return $this->redirect('view.digital_assets.page_inventory');
    }

    // Check usage count in custom table.
    $asset_id = $this->entity->id();
    $usage_count = $this->database->select('digital_asset_usage', 'dau')
      ->condition('asset_id', $asset_id)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($usage_count > 0) {
      $this->messenger->addError($this->t('This asset cannot be deleted because it is currently in use in @count location(s).', ['@count' => $usage_count]));
      return $this->redirect('view.digital_assets.page_inventory');
    }

    // For managed files, also check Drupal's core file_usage table.
    if ($source_type === 'file_managed' || $source_type === 'media_managed') {
      $fid = $this->entity->get('fid')->value;
      if ($fid) {
        $file_usage = \Drupal::service('file.usage');
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        if ($file) {
          $usage_list = $file_usage->listUsage($file);
          $core_usage_count = 0;

          // Count total usage across all modules.
          foreach ($usage_list as $module => $module_usage) {
            foreach ($module_usage as $type => $type_usage) {
              foreach ($type_usage as $id => $count) {
                $core_usage_count += $count;
              }
            }
          }

          // Check if physical file still exists.
          $file_uri = $this->entity->get('file_path')->value;
          $file_path = $this->fileSystem->realpath($file_uri);
          $file_exists = $file_path && file_exists($file_path);

          // For regular managed files: allow deletion if our custom usage
          // tracking says "Not used", even if file_usage has references.
          // Only block if file exists AND is in live content (custom tracking).
          if ($source_type === 'file_managed' && $usage_count > 0 && $file_exists) {
            $this->messenger->addError($this->t('This file cannot be deleted because it is currently used in live content.'));
            return $this->redirect('view.digital_assets.page_inventory');
          }

          // For media files, check if usage is ONLY from media entities.
          if ($source_type === 'media_managed') {
            $has_non_media_usage = FALSE;
            foreach ($usage_list as $module => $module_usage) {
              foreach ($module_usage as $type => $type_usage) {
                if ($type !== 'media') {
                  $has_non_media_usage = TRUE;
                  break 2;
                }
              }
            }

            if ($has_non_media_usage) {
              $this->messenger->addError($this->t('This media file cannot be deleted because it has non-media usage references (@count total).', ['@count' => $core_usage_count]));
              return $this->redirect('view.digital_assets.page_inventory');
            }

            // Note: Media content refs are validated via custom usage table
            // (usage_count=0 means not in content). No additional scanning.
          }
        }
      }
    }

    // Check if file/media is used in required fields (block deletion if so).
    if ($source_type === 'media_managed' || $source_type === 'file_managed') {
      $required_field_usage = $this->checkRequiredFieldUsage();
      if (!empty($required_field_usage)) {
        $items = [];
        foreach ($required_field_usage as $usage) {
          $items[] = $this->t('@entity_type "@label" (field: @field)', [
            '@entity_type' => $usage['entity_type'],
            '@label' => $usage['label'],
            '@field' => $usage['field_name'],
          ]);
        }
        $list = '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
        $file_or_media = $source_type === 'media_managed' ? $this->t('media') : $this->t('file');
        $this->messenger->addError($this->t('This @type cannot be deleted because it is used in required fields on the following content:', ['@type' => $file_or_media]) . $list . $this->t('Please remove or replace the reference before deleting.'));
        return $this->redirect('view.digital_assets.page_inventory');
      }
    }

    // Check for other file_managed records sharing the same URI.
    $shared_uri_info = $this->checkSharedUri();

    // Display file information.
    $file_path = $this->entity->get('file_path')->value;

    // Generate full URL from file path.
    if (strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0) {
      $file_url = $file_path;
    }
    else {
      $file_url_generator = \Drupal::service('file_url_generator');
      $file_url = $file_url_generator->generateAbsoluteString($file_path);
    }

    // Important warning message about potential external links.
    $form['important_warning'] = [
      '#type' => 'item',
      '#markup' => '<div data-drupal-message-type="error" class="messages messages--error" role="alert" style="font-size: 1.25em;">
        <h2 class="visually-hidden">' . $this->t('Important message') . '</h2>
        <h2>' . $this->t('Important - Review Before Deleting') . '</h2>
        <p>' . $this->t('This scan helps identify where files are used on your site, but it may not detect every reference. Some files may still be used outside the website (for example, in emails, documents, bookmarks, or external links).') . '</p>
        <p><strong>' . $this->t('Before deleting a file, confirm it is no longer needed. If you are unsure, check with your site administrator.') . '</strong></p>
      </div>',
      '#weight' => -100,
    ];

    // Determine upload method and additional info based on source type.
    $source_type = $this->entity->get('source_type')->value;
    $upload_method = '';
    $additional_info = '';
    if ($source_type === 'media_managed') {
      $upload_method = $this->t('Uploaded through Media Library');
      $additional_info = $this->t('Both the Media entity and file will be permanently deleted.');
    }
    elseif ($source_type === 'file_managed') {
      $upload_method = $this->t('Uploaded through Drupal');
      $additional_info = $this->t('A Drupal file record exists for this file.');
    }
    else {
      $upload_method = $this->t('Manually uploaded (FTP / SFTP / other)');
      $additional_info = $this->t('No Drupal file record exists for this file.');
    }

    $form['file_info'] = [
      '#type' => 'details',
      '#title' => $this->t('File Information'),
      '#open' => TRUE,
      '#weight' => -90,
      '#attributes' => ['role' => 'group'],
    ];

    $form['file_info']['content'] = [
      '#markup' => '<ul>
        <li><strong>' . $this->t('File name:') . '</strong> ' . htmlspecialchars($this->entity->get('file_name')->value) . '</li>
        <li><strong>' . $this->t('File URL:') . '</strong> <a href="' . $file_url . '">' . htmlspecialchars($file_url) . '</a></li>
        <li><strong>' . $this->t('File size:') . '</strong> ' . ByteSizeMarkup::create($this->entity->get('filesize')->value) . '</li>
        <li><strong>' . $this->t('Upload method:') . '</strong> ' . $upload_method . '</li>
        <li>' . $additional_info . '</li>
      </ul>',
    ];

    // Display shared URI warning if applicable.
    if (!empty($shared_uri_info)) {
      $other_fids = $shared_uri_info['other_fids'];
      $other_media_ids = $shared_uri_info['other_media_ids'];

      $warning_message = '<h2>' . $this->t('Shared File Warning') . '</h2>';
      $warning_message .= '<p>' . $this->t('This physical file is referenced by @count other file record(s) in the database:', ['@count' => count($other_fids)]) . '</p>';
      $warning_message .= '<ul>';
      foreach ($other_fids as $other_fid) {
        $media_info = isset($other_media_ids[$other_fid]) ? $this->t(' (Media ID: @mid)', ['@mid' => $other_media_ids[$other_fid]]) : '';
        $warning_message .= '<li>' . $this->t('File ID: @fid', ['@fid' => $other_fid]) . $media_info . '</li>';
      }
      $warning_message .= '</ul>';
      $warning_message .= '<p><strong>' . $this->t('If you delete this file, the physical file will be removed and all other references will become broken.') . '</strong></p>';
      $warning_message .= '<p>' . $this->t('This typically occurs on multilingual sites where translations create separate file records for the same physical file.') . '</p>';

      $form['shared_uri_warning'] = [
        '#type' => 'item',
        '#markup' => '<div class="messages messages--error">' . $warning_message . '</div>',
        '#weight' => -85,
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
  public function getFormId() {
    return 'digital_asset_inventory_delete_asset_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Permanently delete %filename', [
      '%filename' => $this->entity->get('file_name')->value,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('view.digital_assets.page_inventory');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Permanently Delete File');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Return to Inventory');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_name = $this->entity->get('file_name')->value;
    $file_path = $this->entity->get('file_path')->value;
    $asset_id = $this->entity->id();
    $source_type = $this->entity->get('source_type')->value;

    // Get current user for logging.
    $current_user = \Drupal::currentUser();
    $user_name = $current_user->getAccountName();
    $user_id = $current_user->id();

    $file_deleted = FALSE;
    $managed_file_deleted = FALSE;

    // Handle deletion based on source type.
    if ($source_type === 'media_managed') {
      // For media files: delete Media entity first, then explicitly delete
      // file entity. This ensures complete removal with derivatives.
      $media_id = $this->entity->get('media_id')->value;
      $fid = $this->entity->get('fid')->value;

      // Get file URI and real path before any deletions.
      $file_uri = NULL;
      $real_path_before = NULL;
      if ($fid) {
        /** @var \Drupal\file\FileInterface|null $file */
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        if ($file) {
          $file_uri = $file->getFileUri();
          $real_path_before = $this->fileSystem->realpath($file_uri);
        }
      }

      if ($media_id) {
        try {
          // Delete Media entity first - removes Media→File reference and
          // decrements the file usage count.
          $media = $this->entityTypeManager->getStorage('media')->load($media_id);
          if ($media) {
            $media->delete();

            // Delete file entity for complete removal.
            if ($fid) {
              $file = $this->entityTypeManager->getStorage('file')->load($fid);
              if ($file) {
                $file->delete();
              }
            }

            $managed_file_deleted = TRUE;

            // Log successful media + file deletion.
            \Drupal::logger('digital_asset_inventory')->notice('User @user (UID: @uid) deleted unused Media entity and file: @filename (Media ID: @mid, FID: @fid, Asset ID: @id)', [
              '@user' => $user_name,
              '@uid' => $user_id,
              '@filename' => $file_name,
              '@mid' => $media_id,
              '@fid' => $fid,
              '@id' => $asset_id,
            ]);
          }
          else {
            // Media entity doesn't exist - still try to delete file entity.
            \Drupal::logger('digital_asset_inventory')->warning('User @user (UID: @uid) attempted to delete Media entity @mid for file @filename but Media entity was not found (Asset ID: @id)', [
              '@user' => $user_name,
              '@uid' => $user_id,
              '@mid' => $media_id,
              '@filename' => $file_name,
              '@id' => $asset_id,
            ]);

            // Try to delete file entity directly.
            if ($fid) {
              $file = $this->entityTypeManager->getStorage('file')->load($fid);
              if ($file) {
                $file->delete();
                $managed_file_deleted = TRUE;
              }
            }

            $this->messenger->addWarning($this->t('The Media entity was not found, but the inventory record will be removed.'));
          }
        }
        catch (\Exception $e) {
          // Log error deleting media.
          \Drupal::logger('digital_asset_inventory')->error('User @user (UID: @uid) attempted to delete Media entity @mid but deletion failed: @error (Asset ID: @id)', [
            '@user' => $user_name,
            '@uid' => $user_id,
            '@mid' => $media_id,
            '@error' => $e->getMessage(),
            '@id' => $asset_id,
          ]);

          $this->messenger->addError($this->t('Unable to delete the Media entity: @error', ['@error' => $e->getMessage()]));
        }
      }
      else {
        // No media ID - still try to delete file entity.
        \Drupal::logger('digital_asset_inventory')->warning('User @user (UID: @uid) attempted to delete media file @filename but no Media ID found - trying file entity (Asset ID: @id)', [
          '@user' => $user_name,
          '@uid' => $user_id,
          '@filename' => $file_name,
          '@id' => $asset_id,
        ]);

        if ($fid) {
          $file = $this->entityTypeManager->getStorage('file')->load($fid);
          if ($file) {
            $file->delete();
            $managed_file_deleted = TRUE;
          }
        }
      }

      // Ensure physical file is deleted (Drupal may not delete it if file_usage exists).
      if ($real_path_before && file_exists($real_path_before)) {
        try {
          $this->fileSystem->delete($file_uri);
          \Drupal::logger('digital_asset_inventory')->notice('Explicitly deleted physical file after entity deletion: @path', [
            '@path' => $real_path_before,
          ]);
        }
        catch (\Exception $e) {
          \Drupal::logger('digital_asset_inventory')->error('Failed to delete physical file: @error', [
            '@error' => $e->getMessage(),
          ]);
        }
      }
      elseif (!$real_path_before) {
        // No file URI from entity - try stored file_path.
        $real_path = $this->resolveFilePathForDeletion($file_path);
        if ($real_path && file_exists($real_path)) {
          try {
            $this->fileSystem->delete($real_path);
            $file_deleted = TRUE;
            \Drupal::logger('digital_asset_inventory')->notice('Deleted physical file using stored path: @path', [
              '@path' => $real_path,
            ]);
          }
          catch (\Exception $e) {
            \Drupal::logger('digital_asset_inventory')->error('Failed to delete physical file: @error', [
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }
    }
    elseif ($source_type === 'file_managed') {
      // For managed files: delete Drupal file entity (physical file + DB).
      $fid = $this->entity->get('fid')->value;
      if ($fid) {
        try {
          /** @var \Drupal\file\FileInterface|null $file */
          $file = $this->entityTypeManager->getStorage('file')->load($fid);
          if ($file) {
            // Get the file URI before deletion so we can verify physical file removal.
            $file_uri = $file->getFileUri();
            $real_path_before = $this->fileSystem->realpath($file_uri);

            // Delete the file entity.
            $file->delete();
            $managed_file_deleted = TRUE;

            // Ensure physical file is deleted (Drupal may not delete it if file_usage exists).
            if ($real_path_before && file_exists($real_path_before)) {
              $this->fileSystem->delete($file_uri);
              \Drupal::logger('digital_asset_inventory')->notice('Explicitly deleted physical file after entity deletion: @path', [
                '@path' => $real_path_before,
              ]);
            }

            // Log successful managed file deletion.
            \Drupal::logger('digital_asset_inventory')->notice('User @user (UID: @uid) deleted unused managed file: @filename (FID: @fid, Path: @path, Asset ID: @id)', [
              '@user' => $user_name,
              '@uid' => $user_id,
              '@filename' => $file_name,
              '@fid' => $fid,
              '@path' => $file_path,
              '@id' => $asset_id,
            ]);
          }
          else {
            // File entity doesn't exist - try to delete physical file directly.
            \Drupal::logger('digital_asset_inventory')->warning('User @user (UID: @uid) attempted to delete managed file @filename but file entity (FID: @fid) was not found (Asset ID: @id)', [
              '@user' => $user_name,
              '@uid' => $user_id,
              '@filename' => $file_name,
              '@fid' => $fid,
              '@id' => $asset_id,
            ]);

            // Try to delete physical file using the stored path.
            $real_path = $this->resolveFilePathForDeletion($file_path);
            if ($real_path && file_exists($real_path)) {
              $this->fileSystem->delete($real_path);
              $file_deleted = TRUE;
            }

            $this->messenger->addWarning($this->t('The Drupal file record was not found, but the inventory record will be removed.'));
          }
        }
        catch (\Exception $e) {
          // Log error deleting managed file.
          \Drupal::logger('digital_asset_inventory')->error('User @user (UID: @uid) attempted to delete managed file @filename but deletion failed: @error (FID: @fid, Asset ID: @id)', [
            '@user' => $user_name,
            '@uid' => $user_id,
            '@filename' => $file_name,
            '@fid' => $fid,
            '@error' => $e->getMessage(),
            '@id' => $asset_id,
          ]);

          // Even if entity deletion failed, try to delete physical file.
          $real_path = $this->resolveFilePathForDeletion($file_path);
          if ($real_path && file_exists($real_path)) {
            try {
              $this->fileSystem->delete($real_path);
              $file_deleted = TRUE;
              \Drupal::logger('digital_asset_inventory')->notice('Deleted physical file despite entity deletion error: @path', [
                '@path' => $real_path,
              ]);
            }
            catch (\Exception $e2) {
              \Drupal::logger('digital_asset_inventory')->error('Failed to delete physical file: @error', [
                '@error' => $e2->getMessage(),
              ]);
            }
          }

          $this->messenger->addError($this->t('Unable to delete the managed file: @error', ['@error' => $e->getMessage()]));
        }
      }
      else {
        // No fid - try to delete physical file directly using stored path.
        $real_path = $this->resolveFilePathForDeletion($file_path);
        if ($real_path && file_exists($real_path)) {
          try {
            $this->fileSystem->delete($real_path);
            $file_deleted = TRUE;
            \Drupal::logger('digital_asset_inventory')->notice('Deleted physical file (no fid): @path', [
              '@path' => $real_path,
            ]);
          }
          catch (\Exception $e) {
            \Drupal::logger('digital_asset_inventory')->error('Failed to delete physical file: @error', [
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }
    }
    else {
      // For orphaned files: delete physical file directly.
      // Convert file_path (may be URL) to real filesystem path.
      $real_path = $this->resolveFilePathForDeletion($file_path);

      if ($real_path && file_exists($real_path)) {
        try {
          $file_deleted = $this->fileSystem->delete($real_path);

          // Log successful orphaned file deletion.
          \Drupal::logger('digital_asset_inventory')->notice('User @user (UID: @uid) deleted orphaned file: @filename (Path: @path, RealPath: @real, Asset ID: @id)', [
            '@user' => $user_name,
            '@uid' => $user_id,
            '@filename' => $file_name,
            '@path' => $file_path,
            '@real' => $real_path,
            '@id' => $asset_id,
          ]);
        }
        catch (\Exception $e) {
          // Log error deleting physical file.
          \Drupal::logger('digital_asset_inventory')->error('User @user (UID: @uid) attempted to delete orphaned file @filename but physical deletion failed: @error (Path: @path, RealPath: @real, Asset ID: @id)', [
            '@user' => $user_name,
            '@uid' => $user_id,
            '@filename' => $file_name,
            '@error' => $e->getMessage(),
            '@path' => $file_path,
            '@real' => $real_path,
            '@id' => $asset_id,
          ]);

          $this->messenger->addWarning($this->t('Unable to delete the physical file, but the inventory record will be removed. Error: @error', ['@error' => $e->getMessage()]));
        }
      }
      else {
        // Log missing physical file.
        \Drupal::logger('digital_asset_inventory')->warning('User @user (UID: @uid) attempted to delete orphaned file @filename but physical file was not found on filesystem (Path: @path, RealPath: @real, Asset ID: @id)', [
          '@user' => $user_name,
          '@uid' => $user_id,
          '@filename' => $file_name,
          '@path' => $file_path,
          '@real' => $real_path ?: 'NULL',
          '@id' => $asset_id,
        ]);

        $this->messenger->addWarning($this->t('The physical file was not found on the filesystem, but the inventory record will be removed.'));
      }
    }

    // Delete the digital asset item entity.
    try {
      $this->entity->delete();

      // Log inventory record deletion with file type context.
      $file_type = $source_type === 'media_managed' ? 'media' : ($source_type === 'file_managed' ? 'managed' : 'orphaned');
      \Drupal::logger('digital_asset_inventory')->notice('User @user (UID: @uid) removed inventory record for @type file: @filename (Asset ID: @id)', [
        '@user' => $user_name,
        '@uid' => $user_id,
        '@type' => $file_type,
        '@filename' => $file_name,
        '@id' => $asset_id,
      ]);

      // Update any archive records for this file.
      // Check by fid if available, otherwise by original_path (for orphan files).
      $fid = $this->entity->get('fid')->value;
      $archive_storage = $this->entityTypeManager->getStorage('digital_asset_archive');
      $archive_query = $archive_storage->getQuery()->accessCheck(FALSE);

      if ($fid) {
        // Managed files: lookup by fid.
        $archive_query->condition('original_fid', $fid);
      }
      else {
        // Orphan files: lookup by original_path.
        $archive_query->condition('original_path', $file_path);
      }

      $archive_ids = $archive_query->execute();

      if (!empty($archive_ids)) {
        /** @var \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive[] $archives */
        $archives = $archive_storage->loadMultiple($archive_ids);
        foreach ($archives as $archive) {
          $status = $archive->getStatus();
          if ($status === 'queued') {
            // Remove queued items - nothing to archive anymore.
            $archive->delete();
            \Drupal::logger('digital_asset_inventory')->notice('Removed @filename from archive queue (source file deleted from inventory)', [
              '@filename' => $file_name,
            ]);
          }
          elseif (in_array($status, ['archived_public', 'archived_admin'])) {
            // Set status to archived_deleted and flag as missing.
            $archive->setStatus('archived_deleted');
            $archive->setFlagMissing(TRUE);
            $archive->setDeletedDate(time());
            $archive->setDeletedBy(\Drupal::currentUser()->id());
            $archive->save();
            \Drupal::logger('digital_asset_inventory')->notice('Set archived file @filename to archived_deleted (source file deleted from inventory)', [
              '@filename' => $file_name,
            ]);
          }
        }
      }

      // Success message based on what was deleted.
      if ($source_type === 'media_managed' && $managed_file_deleted) {
        $this->messenger->addStatus($this->t('The Media entity and file %filename have been permanently deleted from Drupal.', ['%filename' => $file_name]));
      }
      elseif ($source_type === 'file_managed' && $managed_file_deleted) {
        $this->messenger->addStatus($this->t('The managed file %filename has been permanently deleted from Drupal.', ['%filename' => $file_name]));
      }
      elseif ($file_deleted) {
        $this->messenger->addStatus($this->t('The orphaned file %filename has been permanently deleted.', ['%filename' => $file_name]));
      }
      else {
        $this->messenger->addStatus($this->t('The inventory record for %filename has been removed.', ['%filename' => $file_name]));
      }
    }
    catch (\Exception $e) {
      // Log error deleting inventory record.
      \Drupal::logger('digital_asset_inventory')->error('User @user (UID: @uid) failed to delete inventory record for @filename: @error (Asset ID: @id)', [
        '@user' => $user_name,
        '@uid' => $user_id,
        '@filename' => $file_name,
        '@error' => $e->getMessage(),
        '@id' => $asset_id,
      ]);

      $this->messenger->addError($this->t('An error occurred while deleting the asset: @error', ['@error' => $e->getMessage()]));
    }

    // Redirect back to inventory.
    $form_state->setRedirect('view.digital_assets.page_inventory');
  }

  /**
   * Resolves a file path (URL or URI) to a real filesystem path for deletion.
   *
   * @param string $file_path
   *   The file path, which may be a URL, stream URI, or filesystem path.
   *
   * @return string|null
   *   The real filesystem path, or NULL if it cannot be resolved.
   */
  protected function resolveFilePathForDeletion($file_path) {
    // If it's already a valid filesystem path, return it.
    if (strpos($file_path, '/') === 0 && strpos($file_path, '://') === FALSE) {
      return $file_path;
    }

    // If it's a stream URI (public://, private://), convert to real path.
    if (strpos($file_path, 'public://') === 0 || strpos($file_path, 'private://') === 0) {
      return $this->fileSystem->realpath($file_path);
    }

    // If it's an absolute URL, try to extract the relative path and convert.
    if (strpos($file_path, 'http://') === 0 || strpos($file_path, 'https://') === 0) {
      // Extract the path portion after /sites/default/files/
      // Public files: https://site.com/sites/default/files/path/to/file.jpg
      // Private files: https://site.com/system/files/path/to/file.jpg
      // Check for private files path first (more specific patterns).
      // Private via system/files route.
      if (preg_match('#/system/files/(.+)$#', $file_path, $matches)) {
        // URL decode to handle special characters like %20, %E2%80%AF.
        $relative_path = urldecode($matches[1]);
        $uri = 'private://' . $relative_path;
        return $this->fileSystem->realpath($uri);
      }

      // Private files at /sites/default/files/private/ (check before public).
      if (preg_match('#/sites/default/files/private/(.+)$#', $file_path, $matches)) {
        // URL decode to handle special characters.
        $relative_path = urldecode($matches[1]);
        $uri = 'private://' . $relative_path;
        return $this->fileSystem->realpath($uri);
      }

      // Check for public files path (most general - must be checked last).
      if (preg_match('#/sites/default/files/(.+)$#', $file_path, $matches)) {
        // URL decode to handle special characters like %20 (space).
        $relative_path = urldecode($matches[1]);
        $uri = 'public://' . $relative_path;
        return $this->fileSystem->realpath($uri);
      }
    }

    // Could not resolve the path.
    return NULL;
  }

  /**
   * Checks if the file/media is used in required fields.
   *
   * Checks both:
   * - Media reference fields (entity_reference targeting media)
   * - Direct file/image fields (file, image field types)
   *
   * @return array
   *   Array of usage info for required fields, each with keys:
   *   - entity_type: The entity type (e.g., 'node')
   *   - entity_id: The entity ID
   *   - label: The entity label
   *   - field_name: The field name/label
   */
  protected function checkRequiredFieldUsage() {
    $required_usage = [];
    $source_type = $this->entity->get('source_type')->value;
    $media_id = $this->entity->get('media_id')->value;
    $fid = $this->entity->get('fid')->value;

    try {
      // For media_managed: Check media reference fields.
      if ($source_type === 'media_managed' && $media_id) {
        $media_usage = $this->checkRequiredMediaReferenceFields($media_id);
        $required_usage = array_merge($required_usage, $media_usage);
      }

      // For both media_managed and file_managed: Check direct file/image fields.
      // (Media files also have an underlying fid that could be used directly.)
      if ($fid) {
        $file_usage = $this->checkRequiredFileFields($fid);
        $required_usage = array_merge($required_usage, $file_usage);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('digital_asset_inventory')->error('Error checking required field usage: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    // Deduplicate by entity_type:entity_id.
    $unique_usage = [];
    foreach ($required_usage as $usage) {
      $key = $usage['entity_type'] . ':' . $usage['entity_id'];
      if (!isset($unique_usage[$key])) {
        $unique_usage[$key] = $usage;
      }
    }

    return array_values($unique_usage);
  }

  /**
   * Checks if media is used in required entity reference fields.
   *
   * @param int $media_id
   *   The media entity ID.
   *
   * @return array
   *   Array of usage info.
   */
  protected function checkRequiredMediaReferenceFields($media_id) {
    $required_usage = [];

    // Get all entity reference fields that target media.
    $field_map = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');

    foreach ($field_map as $entity_type_id => $fields) {
      // Skip media entity type itself.
      if ($entity_type_id === 'media') {
        continue;
      }

      foreach ($fields as $field_name => $field_info) {
        try {
          $field_storage = $this->entityTypeManager
            ->getStorage('field_storage_config')
            ->load($entity_type_id . '.' . $field_name);

          if (!$field_storage || $field_storage->getSetting('target_type') !== 'media') {
            continue;
          }

          // Check if any bundle has this field as required.
          foreach ($field_info['bundles'] as $bundle) {
            $field_config = $this->entityTypeManager
              ->getStorage('field_config')
              ->load($entity_type_id . '.' . $bundle . '.' . $field_name);

            if ($field_config && $field_config->isRequired()) {
              // This field is required - check if our media is used here.
              $storage = $this->entityTypeManager->getStorage($entity_type_id);
              $query = $storage->getQuery()
                ->accessCheck(FALSE)
                ->condition($field_name, $media_id);

              $entity_ids = $query->execute();

              foreach ($entity_ids as $entity_id) {
                $entity = $storage->load($entity_id);
                if ($entity) {
                  $required_usage[] = [
                    'entity_type' => $entity_type_id,
                    'entity_id' => $entity_id,
                    'label' => $entity->label(),
                    'field_name' => $field_config->getLabel() ?: $field_name,
                  ];
                }
              }
            }
          }
        }
        catch (\Exception $e) {
          // Skip fields that can't be checked.
          continue;
        }
      }
    }

    return $required_usage;
  }

  /**
   * Checks if file is used in required file/image fields.
   *
   * @param int $fid
   *   The file ID.
   *
   * @return array
   *   Array of usage info.
   */
  protected function checkRequiredFileFields($fid) {
    $required_usage = [];

    // Check both 'file' and 'image' field types.
    $field_types = ['file', 'image'];

    foreach ($field_types as $field_type) {
      $field_map = $this->entityFieldManager->getFieldMapByFieldType($field_type);

      foreach ($field_map as $entity_type_id => $fields) {
        // Skip file and media entity types - we're looking for content that
        // references the file, not the file/media entities themselves.
        if ($entity_type_id === 'file' || $entity_type_id === 'media') {
          continue;
        }

        foreach ($fields as $field_name => $field_info) {
          try {
            // Check if any bundle has this field as required.
            foreach ($field_info['bundles'] as $bundle) {
              $field_config = $this->entityTypeManager
                ->getStorage('field_config')
                ->load($entity_type_id . '.' . $bundle . '.' . $field_name);

              if ($field_config && $field_config->isRequired()) {
                // This field is required - check if our file is used here.
                // File/image fields store fid in {field_name}_target_id column.
                $storage = $this->entityTypeManager->getStorage($entity_type_id);
                $query = $storage->getQuery()
                  ->accessCheck(FALSE)
                  ->condition($field_name . '.target_id', $fid);

                $entity_ids = $query->execute();

                foreach ($entity_ids as $entity_id) {
                  $entity = $storage->load($entity_id);
                  if ($entity) {
                    $required_usage[] = [
                      'entity_type' => $entity_type_id,
                      'entity_id' => $entity_id,
                      'label' => $entity->label(),
                      'field_name' => $field_config->getLabel() ?: $field_name,
                    ];
                  }
                }
              }
            }
          }
          catch (\Exception $e) {
            // Skip fields that can't be checked.
            continue;
          }
        }
      }
    }

    return $required_usage;
  }

  /**
   * Checks if other file_managed records share the same URI as this file.
   *
   * @return array|null
   *   Array with keys 'other_fids' and 'other_media_ids', or NULL if no
   *   shared URIs found.
   */
  protected function checkSharedUri() {
    $fid = $this->entity->get('fid')->value;
    if (!$fid) {
      return NULL;
    }

    try {
      // Get the URI for this file.
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if (!$file) {
        return NULL;
      }

      $uri = $file->getFileUri();

      // Find other fids with the same URI.
      $other_fids = $this->database->select('file_managed', 'fm')
        ->fields('fm', ['fid'])
        ->condition('uri', $uri)
        ->condition('fid', $fid, '!=')
        ->execute()
        ->fetchCol();

      if (empty($other_fids)) {
        return NULL;
      }

      // Get media associations for the other fids.
      $other_media_ids = [];
      foreach ($other_fids as $other_fid) {
        $media_id = $this->database->select('file_usage', 'fu')
          ->fields('fu', ['id'])
          ->condition('fid', $other_fid)
          ->condition('type', 'media')
          ->execute()
          ->fetchField();

        if ($media_id) {
          $other_media_ids[$other_fid] = $media_id;
        }
      }

      return [
        'other_fids' => $other_fids,
        'other_media_ids' => $other_media_ids,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('digital_asset_inventory')->error('Error checking shared URI: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
