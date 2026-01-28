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

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Form for adding a manual entry to the Archive Registry.
 *
 * Manual entries allow archiving web pages and external resources
 * that are not part of the file-based asset inventory.
 */
final class ManualArchiveForm extends FormBase {

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
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The router service.
   *
   * @var \Symfony\Component\Routing\Matcher\UrlMatcherInterface
   */
  protected $router;

  /**
   * Constructs a ManualArchiveForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Symfony\Component\Routing\Matcher\UrlMatcherInterface $router
   *   The router service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    TimeInterface $time,
    ConfigFactoryInterface $config_factory,
    RequestStack $request_stack,
    LoggerChannelFactoryInterface $logger_factory,
    UrlMatcherInterface $router,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->time = $time;
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
    $this->router = $router;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('request_stack'),
      $container->get('logger.factory'),
      $container->get('router.no_access_checks')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'digital_asset_inventory_manual_archive_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#prefix'] = '<div class="manual-archive-form">';
    $form['#suffix'] = '</div>';

    // Attach admin CSS library for button styling.
    $form['#attached']['library'][] = 'digital_asset_inventory/admin';

    // Determine if we're in ADA compliance mode (before deadline) or general archive mode.
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $deadline_timestamp = $config->get('ada_compliance_deadline') ?: strtotime('2026-04-24 00:00:00 UTC');
    $current_time = $this->time->getRequestTime();
    $is_ada_compliance_mode = ($current_time < $deadline_timestamp);
    $deadline_formatted = gmdate('F j, Y', $deadline_timestamp);

    // Archive Requirements collapsible section.
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

        <p><strong>' . $this->t('Important:') . '</strong> ' . $this->t('If someone requests that archived content be made accessible, it must be remediated promptly.') . '</p>',
    ];

    // About the Archive Process collapsible section.
    $form['process_info'] = [
      '#type' => 'details',
      '#title' => $this->t('About the Archive Process'),
      '#description' => $this->t('Expand to review the archive process'),
      '#open' => FALSE,
      '#weight' => -100,
      '#attributes' => ['role' => 'group'],
    ];

    $form['process_info']['content'] = [
      '#markup' => '<p>' . $this->t('Manual entries will appear in the public <a href="@registry_url">Archive Registry</a> and may be updated later through <a href="@management_url">Archive Management</a>.', [
        '@registry_url' => '/archive-registry',
        '@management_url' => '/admin/digital-asset-inventory/archive',
      ]) . '</p>
        <ul>
          <li><strong>' . $this->t('For internal pages,') . '</strong> ' . $this->t('you are responsible for removing this content from menus, active navigation, and main site search before recording it here as archived.') . '</li>
          <li><strong>' . $this->t('For external resources,') . '</strong> ' . $this->t('ensure that the linked material is no longer actively updated or relied upon. This system cannot verify or monitor external content.') . '</li>
          <li><strong>' . $this->t('To archive documents or videos,') . '</strong> ' . $this->t('use the <a href="@inventory_url">Digital Asset Inventory</a> and select "Queue for Archive." Images, audio files, and compressed files are not supported for archiving.', ['@inventory_url' => '/admin/digital-asset-inventory']) . '</li>
        </ul>',
    ];

    // Archive details section label.
    $form['archive_details_label'] = [
      '#type' => 'item',
      '#markup' => '<h3 class="archive-details-label">' . $this->t('Archive details') . '</h3>
        <p>' . $this->t('Use this form to add web pages or external resources to the Archive Registry.') . '</p>',
      '#weight' => -10,
    ];

    // Title field.
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#description' => $this->t('A descriptive title for this archived item (e.g., "2023 Annual Report Page", "External Policy Document").'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#weight' => 0,
    ];

    // Asset type field - controls which URL field is shown.
    $form['asset_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#description' => $this->t('Select the type of content being archived.'),
      '#required' => TRUE,
      '#options' => [
        'page' => $this->t('Web Page - An internal page on this website'),
        'external' => $this->t('External Resource - A document or page hosted elsewhere'),
      ],
      '#default_value' => 'page',
      '#weight' => 1,
    ];

    // Internal page field - accepts URL paths and aliases with autocomplete.
    $form['internal_page'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page URL'),
      '#description' => $this->t('Start typing a page title or path alias to search. You can also enter a direct path (e.g., /about-us, node/123).'),
      '#maxlength' => 2048,
      '#weight' => 2,
      '#placeholder' => $this->t('Search by title or enter path...'),
      '#autocomplete_route_name' => 'digital_asset_inventory.page_autocomplete',
      '#states' => [
        'visible' => [
          ':input[name="asset_type"]' => ['value' => 'page'],
        ],
        'required' => [
          ':input[name="asset_type"]' => ['value' => 'page'],
        ],
      ],
    ];

    // External URL field - plain textfield for external resources.
    $form['external_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('External URL'),
      '#description' => $this->t('Enter the full URL of the external resource (e.g., https://example.com/document).'),
      '#maxlength' => 2048,
      '#weight' => 2,
      '#placeholder' => $this->t('https://example.com/page'),
      '#states' => [
        'visible' => [
          ':input[name="asset_type"]' => ['value' => 'external'],
        ],
        'required' => [
          ':input[name="asset_type"]' => ['value' => 'external'],
        ],
      ],
    ];

    // Archive reason field.
    $form['archive_reason'] = [
      '#type' => 'select',
      '#title' => $this->t('Archive Reason'),
      '#description' => $this->t('Select the primary purpose for retaining this content. This will be displayed on the public Archive Registry.'),
      '#required' => TRUE,
      '#empty_option' => $this->t('– Select archive purpose –'),
      '#empty_value' => '',
      '#options' => [
        'reference' => $this->t('Reference - Content retained for informational purposes'),
        'research' => $this->t('Research - Material retained for research or study'),
        'recordkeeping' => $this->t('Recordkeeping - Content retained for compliance or official records'),
        'other' => $this->t('Other - Specify a custom reason'),
      ],
      '#weight' => 3,
    ];

    // Custom reason field (shown when "Other" is selected).
    $form['archive_reason_other'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Specify Reason'),
      '#description' => $this->t('Enter the reason for archiving this content.'),
      '#rows' => 3,
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
      '#description' => $this->t('This description will be displayed on the public Archive Registry. Explain why this content is archived and its relevance to users who may need it.'),
      '#default_value' => $this->t('This material has been archived for reference purposes only. It is no longer maintained and may not reflect current information.'),
      '#required' => TRUE,
      '#rows' => 4,
      '#weight' => 5,
    ];

    // Internal notes (optional, admin only).
    $form['internal_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Internal Notes'),
      '#description' => $this->t('Optional notes visible only to administrators. Not shown on the public Archive Registry.'),
      '#rows' => 3,
      '#weight' => 6,
    ];

    // Visibility selection - required for archive.
    $form['visibility'] = [
      '#type' => 'radios',
      '#title' => $this->t('Archive Visibility'),
      '#description' => $this->t('Choose whether this archived entry should be visible on the public Archive Registry or only in admin archive management.'),
      '#options' => [
        'public' => $this->t('Public - Visible on the public Archive Registry at /archive-registry'),
        'admin' => $this->t('Admin-only - Visible only in Archive Management'),
      ],
      '#default_value' => 'public',
      '#weight' => 7,
    ];

    // Helper text above actions with archive type info.
    if ($is_ada_compliance_mode) {
      $helper_text = '<strong>' . $this->t('Classification:') . '</strong> ' . $this->t('This entry will be classified as a Legacy Archive (archived before @deadline) and may be eligible for ADA Title II accessibility exemption.', ['@deadline' => $deadline_formatted]);
    }
    else {
      $helper_text = '<strong>' . $this->t('Classification:') . '</strong> ' . $this->t('This entry will be classified as a General Archive (archived after @deadline), retained for reference purposes without claiming ADA exemption.', ['@deadline' => $deadline_formatted]);
    }

    $form['actions_helper'] = [
      '#type' => 'item',
      '#markup' => '<p class="form-actions-helper">' . $helper_text . '</p>',
      '#weight' => 99,
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add to Archive Registry'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Return to Archive Management'),
      '#url' => Url::fromRoute('view.digital_asset_archive.page_archive_management'),
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

    // Get the URL based on content type.
    $asset_type = $form_state->getValue('asset_type');
    $resolved_url = NULL;

    if ($asset_type === 'page') {
      // Internal page - URL path or alias.
      $internal_page = trim($form_state->getValue('internal_page'));

      if (empty($internal_page)) {
        $form_state->setErrorByName('internal_page', $this->t('Please enter a page URL or path.'));
        return;
      }

      $url = $internal_page;

      // Block incomplete paths.
      if (preg_match('#^/?node/?$#i', $url)) {
        $form_state->setErrorByName('internal_page', $this->t('Please enter a complete path with an ID, such as <strong>node/123</strong>.'));
        return;
      }

      // Block media paths.
      if (preg_match('#^/?media/(\d+)$#', $url) || $this->isMediaEntityPath($url)) {
        $form_state->setErrorByName('internal_page', $this->t('Media entities cannot be archived using this form. To archive files, use the <a href="@inventory_url">Digital Asset Inventory</a>.', [
          '@inventory_url' => '/admin/digital-asset-inventory',
        ]));
        return;
      }

      // Block user paths.
      if (preg_match('#^/?user/(\d+)$#', $url)) {
        $form_state->setErrorByName('internal_page', $this->t('User pages cannot be archived.'));
        return;
      }

      $resolved_url = $this->resolveUrl($url);
      if ($resolved_url === FALSE) {
        $form_state->setErrorByName('internal_page', $this->t('Please enter a valid internal path (e.g., node/123, taxonomy/term/123, or /about-us).'));
        return;
      }

      // Block external URLs in internal page field.
      if ($this->isExternalUrl($resolved_url)) {
        $form_state->setErrorByName('internal_page', $this->t('External URLs should use the "External Resource" content type.'));
        return;
      }

      // Block file storage paths.
      if ($this->isFileStoragePath($resolved_url)) {
        $form_state->setErrorByName('internal_page', $this->t('File URLs cannot be archived using this form. To archive documents or videos, use the <a href="@inventory_url">Digital Asset Inventory</a>.', [
          '@inventory_url' => '/admin/digital-asset-inventory',
        ]));
        return;
      }
    }
    else {
      // External resource - get from URL field.
      $external_url = trim($form_state->getValue('external_url'));

      if (empty($external_url)) {
        $form_state->setErrorByName('external_url', $this->t('Please enter an external URL.'));
        return;
      }

      // Validate it's a proper URL.
      if (!preg_match('#^https?://#i', $external_url)) {
        $form_state->setErrorByName('external_url', $this->t('Please enter a full URL starting with http:// or https://.'));
        return;
      }

      if (!filter_var($external_url, FILTER_VALIDATE_URL)) {
        $form_state->setErrorByName('external_url', $this->t('Please enter a valid URL.'));
        return;
      }

      // Block file URLs in external field too.
      if ($this->isFileStoragePath($external_url)) {
        $form_state->setErrorByName('external_url', $this->t('File URLs cannot be archived using this form. This form is for web pages only.'));
        return;
      }

      $resolved_url = $external_url;
    }

    // Check for duplicate URLs in the archive.
    $existing_archive = $this->findExistingArchive($resolved_url);
    if ($existing_archive) {
      $error_field = ($asset_type === 'page') ? 'internal_page' : 'external_url';
      $detail_url = Url::fromRoute('digital_asset_inventory.archive_detail', [
        'digital_asset_archive' => $existing_archive->id(),
      ])->toString();
      $form_state->setErrorByName($error_field, $this->t('This URL is already in the Archive Registry. <a href="@detail_url">View the existing entry</a>.', [
        '@detail_url' => $detail_url,
      ]));
      return;
    }

    // Check if this URL has a voided exemption - store for submit handler.
    $has_voided_exemption = $this->hasVoidedExemption($resolved_url);
    $form_state->set('has_voided_exemption', $has_voided_exemption);

    // Store the resolved URL for use in submit handler.
    $form_state->set('resolved_url', $resolved_url);
  }

  /**
   * Checks if the input is a media entity path.
   *
   * @param string $input
   *   The user input (path or URL).
   *
   * @return bool
   *   TRUE if this is a media entity path, FALSE otherwise.
   */
  protected function isMediaEntityPath($input) {
    $path = ltrim($input, '/');

    // Check for media/ID pattern.
    if (preg_match('#^media/\d+$#i', $path)) {
      return TRUE;
    }

    // Check for entity:media/ID pattern.
    if (preg_match('#^entity:media/\d+$#i', $input)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks if a URL points to a file that must use DAI workflow.
   *
   * Blocks all file types (documents, videos, images, audio) and folder URLs.
   * Only web pages (HTML content) should use this manual archive form.
   *
   * @param string $url
   *   The resolved URL.
   *
   * @return bool
   *   TRUE if this URL must use DAI workflow, FALSE otherwise.
   */
  protected function isFileStoragePath($url) {
    // Block file storage directories entirely - these are not web pages.
    // Matches /sites/default/files/, /sites/*/files/, and /system/files/.
    if (preg_match('#/(sites/[^/]+/files|system/files)(/|$)#i', $url)) {
      return TRUE;
    }

    // Block all file types - this form is for web pages only.
    // - Documents & Videos: Must use Digital Asset Inventory workflow
    // - Images, Audio & Compressed: Cannot be archived at all
    $blocked_extensions = '#\.(pdf|doc|docx|xls|xlsx|ppt|pptx|txt|csv|mp4|webm|mov|avi|jpg|jpeg|png|gif|svg|webp|ico|bmp|tiff|avif|mp3|wav|m4a|ogg|flac|aac|wma|zip|tar|gz|7z|rar)$#i';

    if (preg_match($blocked_extensions, $url)) {
      return TRUE;
    }

    // Block media entity URLs (canonical media pages).
    if (preg_match('#/media/\d+#i', $url)) {
      return TRUE;
    }

    // Block folder/directory URLs (URLs ending with /).
    if (preg_match('#/$#', $url)) {
      return TRUE;
    }

    // Allow only HTML pages and external resources.
    return FALSE;
  }

  /**
   * Resolves a URL or internal path to a full absolute URL.
   *
   * @param string $input
   *   The user input - can be a full URL or internal path.
   *
   * @return string|false
   *   The resolved absolute URL, or FALSE if invalid.
   */
  protected function resolveUrl($input) {
    if (empty($input)) {
      return FALSE;
    }

    // If it's already a full URL, validate and return it.
    if (preg_match('#^https?://#i', $input)) {
      return filter_var($input, FILTER_VALIDATE_URL) ? $input : FALSE;
    }

    // Handle entity: URI scheme (e.g., entity:node/123).
    if (preg_match('#^entity:(\w+)/(\d+)$#', $input, $matches)) {
      $entity_type = $matches[1];
      $entity_id = $matches[2];
      return $this->resolveEntityUrl($entity_type, $entity_id);
    }

    // Handle internal paths like node/123 or /node/123.
    $path = ltrim($input, '/');

    // Check for node/ID pattern.
    if (preg_match('#^node/(\d+)$#', $path, $matches)) {
      return $this->resolveEntityUrl('node', $matches[1]);
    }

    // Check for taxonomy/term/ID pattern.
    if (preg_match('#^taxonomy/term/(\d+)$#', $path, $matches)) {
      return $this->resolveEntityUrl('taxonomy_term', $matches[1]);
    }

    // Try to resolve as a Drupal path alias or route.
    try {
      $url = Url::fromUserInput('/' . $path);
      if ($url->isRouted()) {
        return $url->setAbsolute()->toString();
      }
    }
    catch (\Exception $e) {
      // Not a valid internal path.
    }

    // If nothing matched, it's invalid.
    return FALSE;
  }

  /**
   * Resolves an entity to its canonical URL.
   *
   * @param string $entity_type
   *   The entity type (e.g., 'node', 'taxonomy_term').
   * @param int|string $entity_id
   *   The entity ID.
   *
   * @return string|false
   *   The absolute URL, or FALSE if entity doesn't exist or has no URL.
   */
  protected function resolveEntityUrl($entity_type, $entity_id) {
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if ($entity && $entity->hasLinkTemplate('canonical')) {
        return $entity->toUrl('canonical')->setAbsolute()->toString();
      }
    }
    catch (\Exception $e) {
      // Entity doesn't exist or can't be loaded.
    }
    return FALSE;
  }

  /**
   * Checks if a URL is external (not on this site).
   *
   * @param string $url
   *   The resolved URL to check.
   *
   * @return bool
   *   TRUE if the URL is external, FALSE if internal.
   */
  protected function isExternalUrl($url) {
    // Get the site's base URL.
    $request = $this->requestStack->getCurrentRequest();
    $base_url = $request ? $request->getSchemeAndHttpHost() : '';

    // Check if the URL starts with the site's base URL.
    if ($base_url && strpos($url, $base_url) === 0) {
      return FALSE;
    }

    // Check for localhost variations (for development).
    if (preg_match('#^https?://(localhost|127\.0\.0\.1)#i', $url)) {
      return FALSE;
    }

    // All other URLs are external.
    return TRUE;
  }

  /**
   * Finds an existing archive entry for the given URL.
   *
   * @param string $url
   *   The resolved URL to check.
   * @param int|null $exclude_id
   *   Optional archive ID to exclude from the check (for edit forms).
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The existing archive entity if found, NULL otherwise.
   */
  protected function findExistingArchive($url, $exclude_id = NULL) {
    try {
      $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
      // Exclude terminal states (archived_deleted, exemption_void) from blocking
      // new entries. This allows creating new entries when the previous entry
      // for this URL was deleted or had its exemption voided - the old record
      // is preserved for audit trail but should not block new entries.
      $query = $storage->getQuery()
        ->condition('original_path', $url)
        ->condition('status', ['removed', 'archived_deleted', 'exemption_void'], 'NOT IN')
        ->accessCheck(FALSE);

      if ($exclude_id) {
        $query->condition('id', $exclude_id, '<>');
      }

      $ids = $query->execute();

      if (!empty($ids)) {
        $id = reset($ids);
        return $storage->load($id);
      }
    }
    catch (\Exception $e) {
      // Log error but don't block the form.
    }

    return NULL;
  }

  /**
   * Checks if a URL has an existing exemption_void archive record.
   *
   * URLs with voided exemptions permanently lose eligibility for Legacy Archive
   * status. Any new archive entry must be classified as General Archive.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL has a voided exemption record, FALSE otherwise.
   */
  protected function hasVoidedExemption($url) {
    try {
      $storage = $this->entityTypeManager->getStorage('digital_asset_archive');
      $count = $storage->getQuery()
        ->condition('original_path', $url)
        ->condition('status', 'exemption_void')
        ->accessCheck(FALSE)
        ->count()
        ->execute();

      return $count > 0;
    }
    catch (\Exception $e) {
      // Log error but don't block - default to allowing Legacy Archive.
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $title = trim($form_state->getValue('title'));
    // Use resolved URL from validation.
    $url = $form_state->get('resolved_url');
    $asset_type = $form_state->getValue('asset_type');
    $reason = $form_state->getValue('archive_reason');
    $reason_other = trim($form_state->getValue('archive_reason_other') ?? '');
    $public_description = trim($form_state->getValue('public_description'));
    $internal_notes = trim($form_state->getValue('internal_notes') ?? '');
    $visibility = $form_state->getValue('visibility') ?: 'public';

    // Determine status based on visibility selection.
    $status = ($visibility === 'public') ? 'archived_public' : 'archived_admin';

    // Check if we're archiving after the ADA compliance deadline.
    $classification_time = $this->time->getRequestTime();
    $config = $this->configFactory->get('digital_asset_inventory.settings');
    $compliance_deadline = $config->get('ada_compliance_deadline');
    if (!$compliance_deadline) {
      // Default: April 24, 2026 00:00:00 UTC.
      $compliance_deadline = strtotime('2026-04-24 00:00:00 UTC');
    }
    $is_late_archive = ($classification_time > $compliance_deadline);

    // If this URL has an existing exemption_void record, force General Archive.
    // Once an exemption has been voided for a URL, that URL permanently loses
    // eligibility for Legacy Archive status. The voided record remains as
    // immutable audit trail documenting the original exemption violation.
    $has_voided_exemption = $form_state->get('has_voided_exemption');
    $forced_general_archive = FALSE;
    if (!$is_late_archive && $has_voided_exemption) {
      $is_late_archive = TRUE;
      $forced_general_archive = TRUE;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('digital_asset_archive');

      // Create archive entity directly with archived status.
      // Manual entries skip the queue/execute workflow.
      $archive = $storage->create([
        'file_name' => $title,
        'original_path' => $url,
        'archive_path' => $url,
        'asset_type' => $asset_type,
        'archive_reason' => $reason,
        'archive_reason_other' => $reason_other,
        'public_description' => $public_description,
        'internal_notes' => $internal_notes,
        'status' => $status,
        'archive_classification_date' => $classification_time,
        'flag_late_archive' => $is_late_archive,
        'flag_prior_void' => $forced_general_archive,
        // No file-specific fields for manual entries.
        'original_fid' => NULL,
        'file_checksum' => NULL,
        'mime_type' => NULL,
        'filesize' => NULL,
      ]);

      $archive->save();

      // Log the manual archive creation for audit trail.
      $visibility_label = ($visibility === 'public') ? $this->t('public Archive Registry') : $this->t('admin archive management only');
      $this->loggerFactory->get('digital_asset_inventory')->notice('User @user created manual archive entry "@title" (@asset_type) with visibility @visibility.', [
        '@user' => $this->currentUser()->getDisplayName(),
        '@title' => $title,
        '@asset_type' => $asset_type,
        '@visibility' => $visibility_label,
      ]);

      // Invalidate cache so the archived content banner appears immediately.
      // Method checks for internal URLs and invalidates entity cache tags.
      $this->invalidateArchivedPageCache($url);

      $this->messenger->addStatus($this->t('The entry "@title" has been added to the Archive Registry (@visibility).', [
        '@title' => $title,
        '@visibility' => $visibility_label,
      ]));

      // Notify user if entry was forced to General Archive due to voided exemption.
      if ($forced_general_archive) {
        $this->loggerFactory->get('digital_asset_inventory')->warning('Manual archive entry "@title" forced to General Archive due to prior exemption_void record.', [
          '@title' => $title,
        ]);
        $this->messenger->addWarning($this->t('This entry has been classified as a General Archive because this URL has a previous exemption violation on record. URLs with voided exemptions are permanently ineligible for Legacy Archive status.'));
      }

      // Redirect to archive management page.
      $form_state->setRedirect('view.digital_asset_archive.page_archive_management');
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('Error adding entry to archive: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Invalidates cache for an archived page so the banner appears immediately.
   *
   * Works for nodes, custom entities, and any internal page.
   *
   * @param string $url
   *   The resolved URL of the archived page.
   */
  protected function invalidateArchivedPageCache($url) {
    $request = $this->requestStack->getCurrentRequest();
    $base_url = $request ? $request->getSchemeAndHttpHost() : '';

    // Only process internal URLs.
    if (!$base_url || strpos($url, $base_url) !== 0) {
      return;
    }

    // Extract the internal path from the URL.
    $internal_path = str_replace($base_url, '', $url);

    // Try to find the entity from the route.
    try {
      // Use the router to match the URL to a route.
      $result = $this->router->match($internal_path);

      // Check for entity parameters in the route match.
      $cache_tags = [];

      // Look for common entity types in route parameters.
      $entity_types = ['node', 'taxonomy_term', 'user', 'media'];
      foreach ($entity_types as $entity_type) {
        if (isset($result[$entity_type]) && is_object($result[$entity_type])) {
          $entity = $result[$entity_type];
          if ($entity instanceof \Drupal\Core\Entity\EntityInterface) {
            $cache_tags = array_merge($cache_tags, $entity->getCacheTags());
          }
        }
      }

      // Also check for generic _entity parameter (custom entities).
      if (isset($result['_entity']) && $result['_entity'] instanceof \Drupal\Core\Entity\EntityInterface) {
        $cache_tags = array_merge($cache_tags, $result['_entity']->getCacheTags());
      }

      // Check for any parameter that's an entity.
      foreach ($result as $param_value) {
        if ($param_value instanceof \Drupal\Core\Entity\EntityInterface) {
          $cache_tags = array_merge($cache_tags, $param_value->getCacheTags());
        }
      }

      if (!empty($cache_tags)) {
        Cache::invalidateTags(array_unique($cache_tags));
      }
      else {
        // Fallback for non-entity pages: invalidate render cache tags.
        Cache::invalidateTags(['rendered']);
      }
    }
    catch (\Exception $e) {
      // Log the error before fallback.
      $this->loggerFactory->get('digital_asset_inventory')->warning('Failed to invalidate cache for archived page @url: @error', [
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
      // Fallback: invalidate render cache tags if routing fails.
      Cache::invalidateTags(['rendered']);
    }
  }

}
