<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\ConfigRecordLoader;
use Drupal\workspace_events\CourseDifficultyMigrator;
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
   * The modules the translation test needs.
   *
   * @var string[]
   */
  protected static $modules = [
    'language',
    'content_translation',
  ];

  /**
   * The migrator.
   */
  private CourseDifficultyMigrator $migrator;

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entity_type_manager = $this->container->get('entity_type.manager');
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $this->entityTypeManager = $entity_type_manager;
    $resolver = $this->container->get(ExtensionPathResolver::class);
    \assert($resolver instanceof ExtensionPathResolver);

    FieldStorageConfig::create([
      'field_name' => CourseDifficultyMigrator::LEGACY_FIELD_NAME,
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => 1,
      'settings' => ['max_length' => 255, 'is_ascii' => FALSE, 'case_sensitive' => FALSE],
    ])->save();
    $this->createFieldConfig(CourseDifficultyMigrator::LEGACY_FIELD_NAME, 'course', 'Level');

    $this->migrator = new CourseDifficultyMigrator(
      $this->entityTypeManager,
      new ConfigRecordLoader(new MemoryStorage(), $resolver),
    );
  }

  /**
   * Tests the migration of the legacy values, including a translation.
   */
  public function testMigratesLegacyValues(): void {
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
    $course->addTranslation('de', [
      'title' => 'Tanzfeeling',
      'field_level' => 'Level 1–3',
    ])->save();

    $this->migrator->migrate();

    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache([$course->id()]);
    $course = $storage->load($course->id());
    self::assertInstanceOf(NodeInterface::class, $course);
    self::assertSame([1], $this->difficultyValues($course));
    self::assertSame([1, 2, 3], $this->difficultyValues($course->getTranslation('de')));

    self::assertNull(FieldConfig::loadByName('node', 'course', 'field_level'));
    self::assertNull(FieldStorageConfig::loadByName('node', 'field_level'));
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
