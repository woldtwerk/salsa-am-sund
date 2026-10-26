<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\ConfigRecordLoader;
use Drupal\workspace_events\CourseDifficultyMigrator;
use Drupal\workspace_events\Difficulty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the migration of the legacy course level.
 */
#[CoversClass(CourseDifficultyMigrator::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class CourseDifficultyMigratorTest extends EventKernelTestBase {

  /**
   * The migrator.
   */
  private CourseDifficultyMigrator $migrator;

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The extension path resolver.
   */
  private ExtensionPathResolver $extensionPathResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entity_type_manager = $this->container->get('entity_type.manager');
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $this->entityTypeManager = $entity_type_manager;
    $extension_path_resolver = $this->container->get(ExtensionPathResolver::class);
    \assert($extension_path_resolver instanceof ExtensionPathResolver);
    $this->extensionPathResolver = $extension_path_resolver;

    FieldStorageConfig::create([
      'field_name' => CourseDifficultyMigrator::LEGACY_FIELD_NAME,
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => 1,
      'settings' => ['max_length' => 255, 'is_ascii' => FALSE, 'case_sensitive' => FALSE],
    ])->save();
    $this->createFieldConfig(CourseDifficultyMigrator::LEGACY_FIELD_NAME, 'course', 'Level');

    $this->migrator = $this->createMigrator(new MemoryStorage());
  }

  /**
   * Tests the value migration and the removal of the legacy field.
   */
  public function testMigratesLegacyValues(): void {
    $course = $this->createCourse([
      'title' => 'Tanzfeeling u. mehr',
      'field_level' => 'Level 1–3',
    ]);
    $revision_id = $course->getRevisionId();

    $this->migrator->migrate();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$course->id()]);
    $course = $storage->load($course->id());
    self::assertInstanceOf(NodeInterface::class, $course);
    self::assertSame([1, 2, 3], $this->difficultyValues($course));
    self::assertSame($revision_id, $course->getRevisionId());
    self::assertNull(FieldConfig::loadByName('node', 'course', 'field_level'));
    self::assertNull(FieldStorageConfig::loadByName('node', 'field_level'));
  }

  /**
   * Tests that the migration creates the difficulty field.
   */
  public function testCreatesDifficultyField(): void {
    $field = FieldConfig::loadByName('node', 'course', 'field_difficulty');
    self::assertInstanceOf(FieldConfig::class, $field);
    $this->deleteDifficultyField();
    self::assertNull(FieldStorageConfig::loadByName('node', 'field_difficulty'));

    $course = $this->createCourse([
      'title' => 'Leicht Fortgeschrittene',
      'field_level' => 'Level 1 + 2',
    ]);

    $this->migrator->migrate();

    $storage = FieldStorageConfig::loadByName('node', 'field_difficulty');
    self::assertInstanceOf(FieldStorageConfig::class, $storage);
    self::assertSame('list_integer', $storage->getType());
    self::assertSame(FieldStorageConfig::CARDINALITY_UNLIMITED, $storage->getCardinality());
    self::assertSame(
      Difficulty::class . '::allowedValues',
      $storage->getSetting('allowed_values_function'),
    );

    $field = FieldConfig::loadByName('node', 'course', 'field_difficulty');
    self::assertInstanceOf(FieldConfig::class, $field);
    self::assertSame('Level', $field->label());
    self::assertFalse($field->isRequired());

    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $node_storage->resetCache([$course->id()]);
    $course = $node_storage->load($course->id());
    self::assertInstanceOf(NodeInterface::class, $course);
    self::assertSame([1, 2], $this->difficultyValues($course));
  }

  /**
   * Tests that the field is created with the UUIDs from the sync storage.
   */
  public function testUsesSyncConfigUuids(): void {
    $field = FieldConfig::loadByName('node', 'course', 'field_difficulty');
    self::assertInstanceOf(FieldConfig::class, $field);
    $this->deleteDifficultyField();
    self::assertNull(FieldStorageConfig::loadByName('node', 'field_difficulty'));

    $module_path = $this->extensionPathResolver->getPath('module', 'workspace_events');
    $install_storage = new FileStorage($module_path . '/config/install');
    $storage_data = $install_storage->read('field.storage.node.field_difficulty');
    $field_data = $install_storage->read('field.field.node.course.field_difficulty');
    self::assertIsArray($storage_data);
    self::assertIsArray($field_data);

    $storage_uuid = '11111111-1111-4111-8111-111111111111';
    $field_uuid = '22222222-2222-4222-8222-222222222222';
    $storage_data['uuid'] = $storage_uuid;
    $field_data['uuid'] = $field_uuid;

    $sync_storage = new MemoryStorage();
    $sync_storage->write('field.storage.node.field_difficulty', $storage_data);
    $sync_storage->write('field.field.node.course.field_difficulty', $field_data);

    $course = $this->createCourse([
      'title' => 'Figuren + Elemente',
      'field_level' => 'Level 1',
    ]);

    $this->createMigrator($sync_storage)->migrate();

    $storage_config = FieldStorageConfig::loadByName('node', 'field_difficulty');
    self::assertInstanceOf(FieldStorageConfig::class, $storage_config);
    self::assertSame($storage_uuid, $storage_config->uuid());

    $field_config = FieldConfig::loadByName('node', 'course', 'field_difficulty');
    self::assertInstanceOf(FieldConfig::class, $field_config);
    self::assertSame($field_uuid, $field_config->uuid());

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node_storage->resetCache([$course->id()]);
    $course = $node_storage->load($course->id());
    self::assertInstanceOf(NodeInterface::class, $course);
    self::assertSame([1], $this->difficultyValues($course));
  }

  /**
   * Tests that every course translation is migrated.
   */
  public function testMigratesTranslations(): void {
    $this->enableModules(['language', 'content_translation']);
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['language', 'content_translation']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    ContentLanguageSettings::create([
      'target_entity_type_id' => 'node',
      'target_bundle' => 'course',
      'default_langcode' => 'site_default',
      'language_alterable' => TRUE,
    ])->save();

    $course = $this->createCourse([
      'title' => 'Tanzfeeling',
      'field_level' => 'Level 1',
    ]);
    $translation = $course->addTranslation('de', [
      'title' => 'Tanzfeeling',
      'field_level' => 'Level 1–3',
    ]);
    $translation->save();

    $this->migrator->migrate();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$course->id()]);
    $course = $storage->load($course->id());
    self::assertInstanceOf(NodeInterface::class, $course);
    self::assertSame([1], $this->difficultyValues($course));
    self::assertSame([1, 2, 3], $this->difficultyValues($course->getTranslation('de')));
  }

  /**
   * Creates a migrator with the given sync storage.
   *
   * @param \Drupal\Core\Config\StorageInterface $syncStorage
   *   The sync storage used to read the field configuration.
   *
   * @return \Drupal\workspace_events\CourseDifficultyMigrator
   *   The migrator.
   */
  private function createMigrator(StorageInterface $syncStorage): CourseDifficultyMigrator {
    return new CourseDifficultyMigrator(
      $this->entityTypeManager,
      new ConfigRecordLoader($syncStorage, $this->extensionPathResolver),
    );
  }

  /**
   * Deletes the difficulty field and its storage.
   */
  private function deleteDifficultyField(): void {
    $field = FieldConfig::loadByName('node', 'course', 'field_difficulty');
    if ($field !== NULL) {
      $field->delete();
    }
    $storage = FieldStorageConfig::loadByName('node', 'field_difficulty');
    if ($storage !== NULL) {
      $storage->delete();
    }
  }

  /**
   * Returns the difficulty values of a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return int[]
   *   The difficulty values.
   */
  private function difficultyValues(NodeInterface $course): array {
    return array_map(
      static fn (array $item): int => (int) $item['value'],
      $course->get('field_difficulty')->getValue(),
    );
  }

}
