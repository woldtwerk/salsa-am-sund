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
use Drupal\node\Entity\NodeType;
use Drupal\workspace_events\ConfigRecordLoader;
use Drupal\workspace_events\LessonInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the creation of the lesson configuration.
 */
#[CoversClass(LessonInstaller::class)]
#[CoversClass(ConfigRecordLoader::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LessonInstallerTest extends LessonKernelTestBase {

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
  }

  /**
   * Tests that the lesson configuration is created with the sync UUIDs.
   */
  public function testInstallCreatesConfiguration(): void {
    [$sync_storage, $uuids] = $this->createSyncStorage();

    $this->createInstaller($sync_storage)->install($this->configNames());

    $node_type = NodeType::load('lesson');
    self::assertInstanceOf(NodeType::class, $node_type);
    self::assertSame($uuids['node.type.lesson'], $node_type->uuid());
    self::assertFalse($node_type->shouldCreateNewRevision());
    self::assertTrue($node_type->getThirdPartySetting('scheduler', 'publish_enable', FALSE));

    $first = FieldStorageConfig::loadByName('node', 'field_course_first');
    self::assertInstanceOf(FieldStorageConfig::class, $first);
    self::assertSame('daterange', $first->getType());
    self::assertSame(1, $first->getCardinality());
    $first_field = FieldConfig::loadByName('node', 'course', 'field_course_first');
    self::assertInstanceOf(FieldConfig::class, $first_field);
    self::assertTrue($first_field->isRequired());

    $last = FieldStorageConfig::loadByName('node', 'field_course_last');
    self::assertInstanceOf(FieldStorageConfig::class, $last);
    self::assertSame('datetime', $last->getType());
    self::assertSame('date', $last->getSetting('datetime_type'));

    $interval = FieldStorageConfig::loadByName('node', 'field_course_interval');
    self::assertInstanceOf(FieldStorageConfig::class, $interval);
    self::assertSame('list_integer', $interval->getType());
    self::assertSame([1, 2], array_keys($interval->getSetting('allowed_values')));

    $lessons = FieldConfig::loadByName('node', 'course', 'field_lessons');
    self::assertInstanceOf(FieldConfig::class, $lessons);
    self::assertSame(FieldStorageConfig::CARDINALITY_UNLIMITED, $lessons->getFieldStorageDefinition()->getCardinality());
    $handler_settings = $lessons->getSetting('handler_settings');
    self::assertSame(['lesson' => 'lesson'], $handler_settings['target_bundles']);

    $event_date = FieldConfig::loadByName('node', 'event', 'field_event_date');
    self::assertInstanceOf(FieldConfig::class, $event_date);
    self::assertSame('daterange', $event_date->getType());
    self::assertTrue($event_date->isRequired());

    $lesson_date = FieldConfig::loadByName('node', 'lesson', 'field_lesson_date');
    self::assertInstanceOf(FieldConfig::class, $lesson_date);
    self::assertTrue($lesson_date->isRequired());

    $photo_storage = FieldStorageConfig::loadByName('node', 'field_attendance_photo');
    self::assertInstanceOf(FieldStorageConfig::class, $photo_storage);
    self::assertSame('private', $photo_storage->getSetting('uri_scheme'));
    $photo = FieldConfig::loadByName('node', 'lesson', 'field_attendance_photo');
    self::assertInstanceOf(FieldConfig::class, $photo);
    self::assertSame('lessons/[date:custom:Y]-[date:custom:m]', $photo->getSetting('file_directory'));

    $participants = FieldConfig::loadByName('node', 'lesson', 'field_participants');
    self::assertInstanceOf(FieldConfig::class, $participants);
    $participant_settings = $participants->getSetting('handler_settings');
    self::assertSame('role', $participant_settings['filter']['type']);
    self::assertSame(['member' => 'member'], $participant_settings['filter']['role']);

    $form_display = $this->entityTypeManager->getStorage('entity_form_display')->load('node.lesson.default');
    self::assertNotNull($form_display);
    self::assertSame($uuids['core.entity_form_display.node.lesson.default'], $form_display->uuid());
    self::assertNull($form_display->getComponent('title'));

    $view_display = $this->entityTypeManager->getStorage('entity_view_display')->load('node.lesson.default');
    self::assertNotNull($view_display);
    self::assertSame($uuids['core.entity_view_display.node.lesson.default'], $view_display->uuid());
  }

  /**
   * Tests that existing configuration is not overwritten.
   */
  public function testDoesNotOverwriteExistingConfiguration(): void {
    [$sync_storage, $uuids] = $this->createSyncStorage();
    NodeType::create([
      'type' => 'lesson',
      'name' => 'Bestehende Stunden',
      'new_revision' => TRUE,
    ])->save();

    $this->createInstaller($sync_storage)->install($this->configNames());

    $node_type = NodeType::load('lesson');
    self::assertInstanceOf(NodeType::class, $node_type);
    self::assertSame('Bestehende Stunden', $node_type->label());
    self::assertNotSame($uuids['node.type.lesson'], $node_type->uuid());
  }

  /**
   * Tests that the module install configuration is used without a sync record.
   */
  public function testFallsBackToInstallConfig(): void {
    $this->createInstaller(new MemoryStorage())->install($this->configNames());

    self::assertInstanceOf(NodeType::class, NodeType::load('lesson'));
    self::assertInstanceOf(FieldStorageConfig::class, FieldStorageConfig::loadByName('node', 'field_lesson_date'));
  }

  /**
   * Returns the configuration names of the lesson bundle itself.
   *
   * The course and event displays already exist on the sites that run the
   * update hook, so they are not part of this test.
   *
   * @return string[]
   *   The configuration names.
   */
  private function configNames(): array {
    return array_values(array_filter(
      LessonInstaller::CONFIG_NAMES,
      static fn (string $name): bool => !str_starts_with($name, 'core.entity_')
        || str_contains($name, '.node.lesson.'),
    ));
  }

  /**
   * Creates the sync storage from the module install configuration.
   *
   * @return array{\Drupal\Core\Config\StorageInterface, array<string, string>}
   *   The sync storage and the UUIDs keyed by configuration name.
   */
  private function createSyncStorage(): array {
    $module_path = $this->extensionPathResolver->getPath('module', 'workspace_events');
    $install_storage = new FileStorage($module_path . '/config/install');
    $sync_storage = new MemoryStorage();
    $uuids = [];
    foreach ($this->configNames() as $index => $name) {
      $data = $install_storage->read($name);
      self::assertIsArray($data);
      $uuid = sprintf('00000000-0000-4000-8000-%012d', $index + 1);
      $data['uuid'] = $uuid;
      $sync_storage->write($name, $data);
      $uuids[$name] = $uuid;
    }
    return [$sync_storage, $uuids];
  }

  /**
   * Creates the installer.
   *
   * @param \Drupal\Core\Config\StorageInterface $sync_storage
   *   The sync storage used to read the configuration.
   *
   * @return \Drupal\workspace_events\LessonInstaller
   *   The installer.
   */
  private function createInstaller(StorageInterface $sync_storage): LessonInstaller {
    return new LessonInstaller(
      $this->entityTypeManager,
      new ConfigRecordLoader($sync_storage, $this->extensionPathResolver),
    );
  }

}
