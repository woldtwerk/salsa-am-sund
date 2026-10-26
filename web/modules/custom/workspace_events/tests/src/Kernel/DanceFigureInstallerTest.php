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
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\workspace_events\ConfigRecordLoader;
use Drupal\workspace_events\DanceFigureInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the creation of the dance figure configuration.
 */
#[CoversClass(DanceFigureInstaller::class)]
#[CoversClass(ConfigRecordLoader::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class DanceFigureInstallerTest extends EventKernelTestBase {

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
   * Tests that the configuration uses the UUIDs from the sync storage.
   */
  public function testUsesSyncConfigUuids(): void {
    [$syncStorage, $uuids] = $this->createSyncStorage();

    $this->createInstaller($syncStorage)->install();

    $vocabulary = Vocabulary::load('dance_figure');
    self::assertInstanceOf(Vocabulary::class, $vocabulary);
    self::assertSame($uuids['taxonomy.vocabulary.dance_figure'], $vocabulary->uuid());

    $difficulty_storage = FieldStorageConfig::loadByName('taxonomy_term', 'field_difficulty');
    self::assertInstanceOf(FieldStorageConfig::class, $difficulty_storage);
    self::assertSame($uuids['field.storage.taxonomy_term.field_difficulty'], $difficulty_storage->uuid());
    self::assertSame(1, $difficulty_storage->getCardinality());

    $difficulty_field = FieldConfig::loadByName('taxonomy_term', 'dance_figure', 'field_difficulty');
    self::assertInstanceOf(FieldConfig::class, $difficulty_field);
    self::assertSame($uuids['field.field.taxonomy_term.dance_figure.field_difficulty'], $difficulty_field->uuid());
    self::assertTrue($difficulty_field->isRequired());

    $video_storage = FieldStorageConfig::loadByName('taxonomy_term', 'field_video');
    self::assertInstanceOf(FieldStorageConfig::class, $video_storage);
    self::assertSame($uuids['field.storage.taxonomy_term.field_video'], $video_storage->uuid());

    $video_field = FieldConfig::loadByName('taxonomy_term', 'dance_figure', 'field_video');
    self::assertInstanceOf(FieldConfig::class, $video_field);
    self::assertSame($uuids['field.field.taxonomy_term.dance_figure.field_video'], $video_field->uuid());
    self::assertTrue($video_field->isRequired());

    $form_display = $this->entityTypeManager->getStorage('entity_form_display')->load('taxonomy_term.dance_figure.default');
    self::assertNotNull($form_display);
    self::assertSame($uuids['core.entity_form_display.taxonomy_term.dance_figure.default'], $form_display->uuid());

    $view_display = $this->entityTypeManager->getStorage('entity_view_display')->load('taxonomy_term.dance_figure.default');
    self::assertNotNull($view_display);
    self::assertSame($uuids['core.entity_view_display.taxonomy_term.dance_figure.default'], $view_display->uuid());

    $pattern = PathautoPattern::load('dance_figure_path');
    self::assertInstanceOf(PathautoPattern::class, $pattern);
    self::assertSame($uuids['pathauto.pattern.dance_figure_path'], $pattern->uuid());
    self::assertSame('/tanzfiguren/[term:name]', $pattern->getPattern());

    $role = Role::load('member');
    self::assertInstanceOf(Role::class, $role);
    self::assertSame($uuids['user.role.member'], $role->uuid());
    self::assertTrue($role->hasPermission('view dance figures'));
  }

  /**
   * Tests that existing configuration is not overwritten.
   */
  public function testDoesNotOverwriteExistingConfiguration(): void {
    [$syncStorage, $uuids] = $this->createSyncStorage();
    Vocabulary::create([
      'vid' => 'dance_figure',
      'name' => 'Bestehende Figuren',
    ])->save();

    $this->createInstaller($syncStorage)->install();

    $storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    $storage->resetCache(['dance_figure']);
    $vocabulary = $storage->load('dance_figure');
    self::assertInstanceOf(Vocabulary::class, $vocabulary);
    self::assertSame('Bestehende Figuren', $vocabulary->label());
    self::assertNotSame($uuids['taxonomy.vocabulary.dance_figure'], $vocabulary->uuid());
  }

  /**
   * Tests that the module install configuration is used without a sync record.
   */
  public function testFallsBackToInstallConfig(): void {
    $this->createInstaller(new MemoryStorage())->install();

    self::assertInstanceOf(Vocabulary::class, Vocabulary::load('dance_figure'));
    self::assertInstanceOf(Role::class, Role::load('member'));
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
    foreach (DanceFigureInstaller::CONFIG_NAMES as $index => $name) {
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
   * @param \Drupal\Core\Config\StorageInterface $syncStorage
   *   The sync storage used to read the configuration.
   *
   * @return \Drupal\workspace_events\DanceFigureInstaller
   *   The installer.
   */
  private function createInstaller(StorageInterface $syncStorage): DanceFigureInstaller {
    return new DanceFigureInstaller(
      $this->entityTypeManager,
      new ConfigRecordLoader($syncStorage, $this->extensionPathResolver),
    );
  }

}
