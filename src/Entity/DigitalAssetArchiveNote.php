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

namespace Drupal\digital_asset_inventory\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Archive Note entity.
 *
 * Stores append-only internal notes for archived items. Notes are
 * administrative metadata used to document compliance decisions and
 * operational context. They are not part of the archived material,
 * are not publicly visible, and do not affect archive classification
 * or exemption status.
 *
 * @ContentEntityType(
 *   id = "dai_archive_note",
 *   label = @Translation("Archive Note"),
 *   label_collection = @Translation("Archive Notes"),
 *   label_singular = @Translation("archive note"),
 *   label_plural = @Translation("archive notes"),
 *   label_count = @PluralTranslation(
 *     singular = "@count archive note",
 *     plural = "@count archive notes"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\digital_asset_inventory\Access\ArchiveNoteAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData"
 *   },
 *   base_table = "dai_archive_note",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "uid" = "author"
 *   }
 * )
 */
class DigitalAssetArchiveNote extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Auto-increment ID - primary key.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The note ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    // Reference to the parent archive entity.
    $fields['archive_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Archive'))
      ->setDescription(t('The archive this note belongs to.'))
      ->setSetting('target_type', 'digital_asset_archive')
      ->setRequired(TRUE);

    // The note text content.
    $fields['note_text'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Note'))
      ->setDescription(t('The note content.'))
      ->setSettings([
        'max_length' => 500,
        'text_processing' => 0,
      ])
      ->setRequired(TRUE);

    // Timestamp when the note was created.
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the note was created.'));

    // User who created the note.
    $fields['author'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setDescription(t('The user who created the note.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    return $fields;
  }

  /**
   * Gets the archive entity this note belongs to.
   *
   * @return \Drupal\digital_asset_inventory\Entity\DigitalAssetArchive|null
   *   The parent archive entity, or NULL if not found.
   */
  public function getArchive() {
    return $this->get('archive_id')->entity;
  }

  /**
   * Gets the archive ID.
   *
   * @return int|null
   *   The archive ID.
   */
  public function getArchiveId() {
    return $this->get('archive_id')->target_id;
  }

  /**
   * Gets the note text.
   *
   * @return string
   *   The note text.
   */
  public function getNoteText() {
    return $this->get('note_text')->value ?? '';
  }

  /**
   * Gets the created timestamp.
   *
   * @return int
   *   The created timestamp.
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * Gets the author user entity.
   *
   * @return \Drupal\user\UserInterface|null
   *   The author user entity, or NULL if not found.
   */
  public function getAuthor() {
    return $this->get('author')->entity;
  }

  /**
   * Gets the author user ID.
   *
   * @return int|null
   *   The author user ID.
   */
  public function getAuthorId() {
    return $this->get('author')->target_id;
  }

}
