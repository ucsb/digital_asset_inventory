<?php

namespace Drupal\Tests\digital_asset_inventory\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

/**
 * Kernel tests for derived media thumbnail usage detection.
 *
 * Tests that Media entity thumbnail files are discovered through entity
 * relationships and correctly registered as asset items with
 * derived_thumbnail usage records.
 *
 * @group digital_asset_inventory
 * @group digital_asset_inventory_kernel
 */
class ThumbnailUsageKernelTest extends DigitalAssetKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'text',
    'options',
    'filter',
    'serialization',
    'rest',
    'image',
    'media',
    'media_test_source',
    'views',
    'better_exposed_filters',
    'views_data_export',
    'csv_serialization',
    'digital_asset_inventory',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install media entity schema (not installed by base class).
    $this->installEntitySchema('media');
    $this->installConfig(['media']);
  }

  /**
   * Creates a source file, thumbnail file, and media entity for testing.
   *
   * @param string $source_filename
   *   The source file name.
   * @param string $thumbnail_uri
   *   The thumbnail file URI.
   *
   * @return array
   *   Associative array with keys: source_file, thumbnail_file, media,
   *   media_type, source_field_name.
   */
  protected function createMediaWithThumbnail(
    string $source_filename = 'test-doc.pdf',
    string $thumbnail_uri = 'public://thumbnails/thumb.jpg',
  ): array {
    // Create source file (will be in the scanner query).
    $source_uri = $this->createTestFile($source_filename, 'pdf content');
    $source_file = $this->createFileEntity($source_uri, 'application/pdf');

    // Create physical thumbnail file + file entity.
    $fs = $this->container->get('file_system');
    $thumb_dir = dirname($thumbnail_uri);
    $fs->prepareDirectory($thumb_dir, FileSystemInterface::CREATE_DIRECTORY);
    $fs->saveData('fake thumbnail data', $thumbnail_uri, FileExists::Replace);
    $this->testFileUris[] = $thumbnail_uri;
    $thumbnail_file = $this->createFileEntity($thumbnail_uri, 'image/jpeg');

    // Create media type (idempotent — uses the same ID).
    $media_type = $this->createMediaType('test', [
      'id' => 'document',
      'label' => 'Document',
    ]);
    $source_field = $media_type->getSource()
      ->getSourceFieldDefinition($media_type);

    // Create media entity with default thumbnail.
    $media = Media::create([
      'bundle' => 'document',
      'name' => 'Test Document',
      $source_field->getName() => 'test_value',
    ]);
    $media->save();

    // Override thumbnail to our specific file entity.
    $media->set('thumbnail', ['target_id' => $thumbnail_file->id()]);
    $media->save();

    // Create file_usage entry to associate source file with media.
    \Drupal::service('file.usage')
      ->add($source_file, 'file', 'media', $media->id());

    return [
      'source_file' => $source_file,
      'thumbnail_file' => $thumbnail_file,
      'media' => $media,
      'media_type' => $media_type,
      'source_field_name' => $source_field->getName(),
    ];
  }

  /**
   * Tests that thumbnail files referenced by Media are detected.
   *
   * Given a Media entity with a thumbnail field referencing a file, the
   * scanner creates an asset item with source_type = 'media_managed' and
   * a usage record with embed_method = 'derived_thumbnail'.
   */
  public function testThumbnailFileDetection(): void {
    $fixture = $this->createMediaWithThumbnail();

    $scanner = $this->container->get('digital_asset_inventory.scanner');
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Verify thumbnail asset item was created.
    $item_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item');
    $items = $item_storage->getQuery()
      ->condition('fid', $fixture['thumbnail_file']->id())
      ->condition('is_temp', 1)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $items, 'Thumbnail asset item was created.');

    $thumbnail_item = $item_storage->load(reset($items));
    $this->assertEquals('media_managed', $thumbnail_item->get('source_type')->value);
    $this->assertEquals('jpg', $thumbnail_item->get('asset_type')->value);
    $this->assertEquals('Images', $thumbnail_item->get('category')->value);
    $this->assertEquals('thumb.jpg', $thumbnail_item->get('file_name')->value);

    // Verify usage record.
    $usage_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage');
    $usages = $usage_storage->getQuery()
      ->condition('asset_id', $thumbnail_item->id())
      ->condition('embed_method', 'derived_thumbnail')
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $usages, 'Thumbnail usage record was created.');

    $usage = $usage_storage->load(reset($usages));
    $this->assertEquals('thumbnail', $usage->get('field_name')->value);
  }

  /**
   * Tests that thumbnail usage is attributed to the Media entity.
   *
   * Usage record must point to the Media entity (entity_type = 'media',
   * entity_id = media ID), NOT to any parent content entity.
   */
  public function testThumbnailUsageAttributedToMedia(): void {
    $fixture = $this->createMediaWithThumbnail();

    $scanner = $this->container->get('digital_asset_inventory.scanner');
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Find the thumbnail asset item.
    $item_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item');
    $items = $item_storage->getQuery()
      ->condition('fid', $fixture['thumbnail_file']->id())
      ->condition('is_temp', 1)
      ->accessCheck(FALSE)
      ->execute();
    $thumbnail_item = $item_storage->load(reset($items));

    // Verify usage attribution.
    $usage_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage');
    $usages = $usage_storage->getQuery()
      ->condition('asset_id', $thumbnail_item->id())
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $usages);

    $usage = $usage_storage->load(reset($usages));
    $this->assertEquals('media', $usage->get('entity_type')->value,
      'Usage attributed to media entity type.');
    $this->assertEquals($fixture['media']->id(), $usage->get('entity_id')->value,
      'Usage attributed to the correct media entity ID.');
  }

  /**
   * Tests that a file with only derived_thumbnail usage is "in use".
   *
   * The thumbnail asset should have active_use_csv = 'Yes' since it has
   * a valid usage record.
   */
  public function testThumbnailCountsAsActiveUsage(): void {
    $fixture = $this->createMediaWithThumbnail();

    $scanner = $this->container->get('digital_asset_inventory.scanner');
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Find the thumbnail asset item.
    $item_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item');
    $items = $item_storage->getQuery()
      ->condition('fid', $fixture['thumbnail_file']->id())
      ->condition('is_temp', 1)
      ->accessCheck(FALSE)
      ->execute();
    $thumbnail_item = $item_storage->load(reset($items));

    // Verify active use CSV field indicates "in use".
    $this->assertEquals('Yes', $thumbnail_item->get('active_use_csv')->value,
      'Thumbnail with derived_thumbnail usage is marked as active.');

    // Verify usage count > 0.
    $usage_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage');
    $usage_count = $usage_storage->getQuery()
      ->condition('asset_id', $thumbnail_item->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertGreaterThan(0, $usage_count,
      'Thumbnail asset has at least one usage record.');
  }

  /**
   * Tests that a missing thumbnail file is handled gracefully.
   *
   * If a Media entity's thumbnail reference points to a file that does not
   * exist in file_managed, the scanner logs a warning and skips.
   */
  public function testMissingThumbnailFile(): void {
    $fixture = $this->createMediaWithThumbnail();

    // Delete the thumbnail file entity to simulate a broken reference.
    $thumbnail_fid = $fixture['thumbnail_file']->id();
    $fixture['thumbnail_file']->delete();

    // Verify file entity no longer exists.
    $this->assertNull(File::load($thumbnail_fid),
      'Thumbnail file entity was deleted.');

    // Run scanner — should not crash.
    $scanner = $this->container->get('digital_asset_inventory.scanner');
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Verify no asset item was created for the missing thumbnail.
    $item_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item');
    $items = $item_storage->getQuery()
      ->condition('fid', $thumbnail_fid)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertEmpty($items, 'No asset item created for missing thumbnail file.');
  }

  /**
   * Tests that multiple media sharing a thumbnail produce correct records.
   *
   * Two media entities referencing the same thumbnail file should result in
   * one asset item and two usage records.
   */
  public function testMultipleMediaSameThumbnail(): void {
    // Create shared thumbnail file.
    $thumbnail_uri = 'public://thumbnails/shared-thumb.jpg';
    $fs = $this->container->get('file_system');
    $thumb_dir = 'public://thumbnails';
    $fs->prepareDirectory($thumb_dir, FileSystemInterface::CREATE_DIRECTORY);
    $fs->saveData('shared thumbnail data', $thumbnail_uri, FileExists::Replace);
    $this->testFileUris[] = $thumbnail_uri;
    $thumbnail_file = $this->createFileEntity($thumbnail_uri, 'image/jpeg');

    // Create media type.
    $media_type = $this->createMediaType('test', [
      'id' => 'document',
      'label' => 'Document',
    ]);
    $source_field = $media_type->getSource()
      ->getSourceFieldDefinition($media_type);
    $sf_name = $source_field->getName();

    // Create first source file + media.
    $source_uri1 = $this->createTestFile('doc1.pdf', 'pdf content 1');
    $source_file1 = $this->createFileEntity($source_uri1, 'application/pdf');

    $media1 = Media::create([
      'bundle' => 'document',
      'name' => 'Document 1',
      $sf_name => 'test_value_1',
    ]);
    $media1->save();
    $media1->set('thumbnail', ['target_id' => $thumbnail_file->id()]);
    $media1->save();
    \Drupal::service('file.usage')
      ->add($source_file1, 'file', 'media', $media1->id());

    // Create second source file + media (same thumbnail).
    $source_uri2 = $this->createTestFile('doc2.pdf', 'pdf content 2');
    $source_file2 = $this->createFileEntity($source_uri2, 'application/pdf');

    $media2 = Media::create([
      'bundle' => 'document',
      'name' => 'Document 2',
      $sf_name => 'test_value_2',
    ]);
    $media2->save();
    $media2->set('thumbnail', ['target_id' => $thumbnail_file->id()]);
    $media2->save();
    \Drupal::service('file.usage')
      ->add($source_file2, 'file', 'media', $media2->id());

    // Run scanner.
    $scanner = $this->container->get('digital_asset_inventory.scanner');
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Verify ONE asset item for the shared thumbnail.
    $item_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item');
    $items = $item_storage->getQuery()
      ->condition('fid', $thumbnail_file->id())
      ->condition('is_temp', 1)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $items,
      'One asset item created for shared thumbnail.');

    // Verify TWO usage records (one per media entity).
    $thumbnail_item = $item_storage->load(reset($items));
    $usage_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage');
    $usages = $usage_storage->getQuery()
      ->condition('asset_id', $thumbnail_item->id())
      ->condition('embed_method', 'derived_thumbnail')
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(2, $usages,
      'Two usage records created (one per media entity).');

    // Verify each usage points to a different media entity.
    $media_ids = [];
    foreach ($usage_storage->loadMultiple($usages) as $usage) {
      $this->assertEquals('media', $usage->get('entity_type')->value);
      $media_ids[] = $usage->get('entity_id')->value;
    }
    $this->assertContains((string) $media1->id(), $media_ids);
    $this->assertContains((string) $media2->id(), $media_ids);
  }

  /**
   * Tests that self-referencing thumbnails are skipped.
   *
   * When a Media entity's thumbnail points to the same file currently being
   * processed, the scanner MUST skip to avoid self-referential usage rows.
   */
  public function testSelfReferenceThumbnailSkipped(): void {
    // Create source file.
    $source_uri = $this->createTestFile('image.jpg', 'image content');
    $source_file = $this->createFileEntity($source_uri, 'image/jpeg');

    // Create media type.
    $media_type = $this->createMediaType('test', [
      'id' => 'image_media',
      'label' => 'Image',
    ]);
    $source_field = $media_type->getSource()
      ->getSourceFieldDefinition($media_type);

    // Create media entity whose thumbnail IS the source file.
    $media = Media::create([
      'bundle' => 'image_media',
      'name' => 'Test Image',
      $source_field->getName() => 'test_value',
    ]);
    $media->save();

    // Set thumbnail to the SAME file as the source.
    $media->set('thumbnail', ['target_id' => $source_file->id()]);
    $media->save();

    // Create file_usage entry.
    \Drupal::service('file.usage')
      ->add($source_file, 'file', 'media', $media->id());

    // Run scanner.
    $scanner = $this->container->get('digital_asset_inventory.scanner');
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Verify no derived_thumbnail usage was created.
    $usage_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage');
    $derived_usages = $usage_storage->getQuery()
      ->condition('embed_method', 'derived_thumbnail')
      ->accessCheck(FALSE)
      ->execute();
    $this->assertEmpty($derived_usages,
      'No derived_thumbnail usage created for self-referencing thumbnail.');
  }

  /**
   * Tests that repeated scans do not create duplicate records.
   *
   * Running scanManagedFilesChunk twice should yield the same result:
   * one asset item and one usage record per thumbnail.
   */
  public function testNoDuplicationOnRepeatedScan(): void {
    $fixture = $this->createMediaWithThumbnail();

    $scanner = $this->container->get('digital_asset_inventory.scanner');

    // First scan.
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Second scan (same temp flag).
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Verify still one asset item.
    $item_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item');
    $items = $item_storage->getQuery()
      ->condition('fid', $fixture['thumbnail_file']->id())
      ->condition('is_temp', 1)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $items,
      'One asset item after repeated scans.');

    // Verify still one usage record.
    $thumbnail_item = $item_storage->load(reset($items));
    $usage_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage');
    $usages = $usage_storage->getQuery()
      ->condition('asset_id', $thumbnail_item->id())
      ->condition('embed_method', 'derived_thumbnail')
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $usages,
      'One usage record after repeated scans.');
  }

  /**
   * Tests reverse detection: thumbnail in non-excluded directory, no file_usage.
   *
   * When a contrib module generates a PDF preview image in a regular directory
   * (not thumbnails/), the file has no file_usage entry linking it to the
   * media entity. The scanner must still detect the thumbnail relationship
   * via reverse media entity query and mark it as in use.
   *
   * Without this, users could accidentally delete the thumbnail through the
   * scanner, breaking pages that display the PDF preview.
   */
  public function testReverseThumbnailDetectionWithoutFileUsage(): void {
    // Create thumbnail file in a NON-excluded directory (like public://2026-02/).
    $thumbnail_uri = 'public://2026-02/preview.pdf-p1.jpeg';
    $fs = $this->container->get('file_system');
    $thumb_dir = 'public://2026-02';
    $fs->prepareDirectory($thumb_dir, FileSystemInterface::CREATE_DIRECTORY);
    $fs->saveData('fake preview image', $thumbnail_uri, FileExists::Replace);
    $this->testFileUris[] = $thumbnail_uri;
    $thumbnail_file = $this->createFileEntity($thumbnail_uri, 'image/jpeg');

    // Create a source PDF file.
    $source_uri = $this->createTestFile('document.pdf', 'pdf content');
    $source_file = $this->createFileEntity($source_uri, 'application/pdf');

    // Create media type and entity.
    $media_type = $this->createMediaType('test', [
      'id' => 'document',
      'label' => 'Document',
    ]);
    $source_field = $media_type->getSource()
      ->getSourceFieldDefinition($media_type);

    $media = Media::create([
      'bundle' => 'document',
      'name' => 'Test PDF',
      $source_field->getName() => 'test_value',
    ]);
    $media->save();

    // Set thumbnail to our preview image (simulates contrib module behavior).
    $media->set('thumbnail', ['target_id' => $thumbnail_file->id()]);
    $media->save();

    // Create file_usage ONLY for the source PDF, NOT for the thumbnail.
    // This simulates the real-world case where the thumbnail has no
    // file_usage entry pointing to the media entity.
    \Drupal::service('file.usage')
      ->add($source_file, 'file', 'media', $media->id());

    // Critically: do NOT add file_usage for the thumbnail file.
    // Also remove any file_usage that Drupal auto-created for the thumbnail
    // base field (to match the real-world scenario).
    $this->container->get('database')
      ->delete('file_usage')
      ->condition('fid', $thumbnail_file->id())
      ->execute();

    // Run scanner.
    $scanner = $this->container->get('digital_asset_inventory.scanner');
    $scanner->scanManagedFilesChunk(0, 50, TRUE);

    // Verify thumbnail asset item was created with correct source_type.
    $item_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_item');
    $items = $item_storage->getQuery()
      ->condition('fid', $thumbnail_file->id())
      ->condition('is_temp', 1)
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $items,
      'Thumbnail asset item exists.');

    $thumbnail_item = $item_storage->load(reset($items));
    $this->assertEquals('media_managed', $thumbnail_item->get('source_type')->value,
      'Thumbnail source_type updated to media_managed via reverse detection.');
    $this->assertEquals($media->id(), $thumbnail_item->get('media_id')->value,
      'Thumbnail media_id set to the media entity.');

    // Verify derived_thumbnail usage record exists.
    $usage_storage = $this->container->get('entity_type.manager')
      ->getStorage('digital_asset_usage');
    $usages = $usage_storage->getQuery()
      ->condition('asset_id', $thumbnail_item->id())
      ->condition('embed_method', 'derived_thumbnail')
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(1, $usages,
      'Derived thumbnail usage record created via reverse detection.');

    $usage = $usage_storage->load(reset($usages));
    $this->assertEquals('media', $usage->get('entity_type')->value);
    $this->assertEquals($media->id(), $usage->get('entity_id')->value);

    // Verify the file is marked as active (not "Not In Use").
    $this->assertEquals('Yes', $thumbnail_item->get('active_use_csv')->value,
      'Thumbnail is marked as active via reverse thumbnail detection.');
  }

}
