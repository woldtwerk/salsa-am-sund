<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_gallery\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\workspace_gallery\GalleryImages;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the gallery image builder.
 */
#[CoversNothing]
#[Group('workspace_gallery')]
#[RunTestsInSeparateProcesses]
final class GalleryImagesTest extends CanvasKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'field',
    'node',
    'pathauto',
    'token',
    'workspace_gallery',
  ];

  /**
   * The gallery image builder.
   */
  private GalleryImages $galleryImages;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['field', 'file', 'image', 'media', 'node', 'text']);

    $this->createContentModel();

    $service = $this->container->get(GalleryImages::class);
    self::assertInstanceOf(GalleryImages::class, $service);
    $this->galleryImages = $service;
  }

  /**
   * Tests that the image order and metadata are preserved.
   */
  public function testBuildPreservesOrderAndMetadata(): void {
    $first = $this->createImage('public://gallery-first.jpg', 'Erstes Bild', 400, 300);
    $second = $this->createImage('public://gallery-second.jpg', 'Zweites Bild', 800, 600);
    $third = $this->createImage('public://gallery-third.jpg', 'Drittes Bild', 1200, 900);

    $node = $this->createGallery([
      $second->id(),
      $first->id(),
      $third->id(),
    ]);

    $result = $this->galleryImages->build($node);

    self::assertSame([
      [
        'src' => 'public://gallery-second.jpg',
        'alt' => 'Zweites Bild',
        'width' => 800,
        'height' => 600,
      ],
      [
        'src' => 'public://gallery-first.jpg',
        'alt' => 'Erstes Bild',
        'width' => 400,
        'height' => 300,
      ],
      [
        'src' => 'public://gallery-third.jpg',
        'alt' => 'Drittes Bild',
        'width' => 1200,
        'height' => 900,
      ],
    ], $result['images']);

    $tags = $result['cache']->getCacheTags();
    self::assertContains('media:' . $second->id(), $tags);
    self::assertContains('media:' . $first->id(), $tags);
    self::assertContains('file:' . $second->get('field_media_image')->target_id, $tags);
  }

  /**
   * Tests that a limited build only returns the first image.
   */
  public function testBuildLimitReturnsFirstImage(): void {
    $first = $this->createImage('public://gallery-first.jpg', 'Erstes Bild', 400, 300);
    $second = $this->createImage('public://gallery-second.jpg', 'Zweites Bild', 800, 600);
    $node = $this->createGallery([$first->id(), $second->id()]);

    $result = $this->galleryImages->build($node, 1);

    self::assertCount(1, $result['images']);
    self::assertSame('public://gallery-first.jpg', $result['images'][0]['src']);
  }

  /**
   * Tests that an empty gallery field returns no images.
   */
  public function testBuildEmptyFieldReturnsEmptyList(): void {
    $node = $this->createGallery([]);

    $result = $this->galleryImages->build($node);

    self::assertSame([], $result['images']);
    self::assertSame([], $result['cache']->getCacheTags());
  }

  /**
   * Creates the gallery content model.
   */
  private function createContentModel(): void {
    $this->createMediaType('image', [
      'id' => 'image',
      'label' => 'Bild',
    ]);

    NodeType::create([
      'type' => 'gallery',
      'name' => 'Fotogalerie',
      'new_revision' => TRUE,
      'display_submitted' => FALSE,
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_gallery_images',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_gallery_images',
      'entity_type' => 'node',
      'bundle' => 'gallery',
      'label' => 'Bilder',
      'field_storage' => FieldStorageConfig::loadByName('node', 'field_gallery_images'),
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => ['image' => 'image'],
        ],
      ],
    ])->save();
  }

  /**
   * Creates an image media entity.
   *
   * @param string $uri
   *   The file URI.
   * @param string $alt
   *   The alternative text.
   * @param int $width
   *   The image width.
   * @param int $height
   *   The image height.
   *
   * @return \Drupal\media\MediaInterface
   *   The media entity.
   */
  private function createImage(string $uri, string $alt, int $width, int $height): MediaInterface {
    $file = File::create([
      'uri' => $uri,
      'filename' => basename($uri),
    ]);
    $file->save();

    $media = Media::create([
      'bundle' => 'image',
      'name' => $alt,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt,
        'width' => $width,
        'height' => $height,
      ],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Creates a gallery node.
   *
   * @param int[] $media_ids
   *   The media IDs in the order they should appear in the gallery.
   *
   * @return \Drupal\node\NodeInterface
   *   The gallery node.
   */
  private function createGallery(array $media_ids): NodeInterface {
    $values = [];
    foreach ($media_ids as $media_id) {
      $values[] = ['target_id' => $media_id];
    }
    $node = Node::create([
      'type' => 'gallery',
      'title' => 'Galerie',
      'field_gallery_images' => $values,
    ]);
    $node->save();
    return $node;
  }

}
