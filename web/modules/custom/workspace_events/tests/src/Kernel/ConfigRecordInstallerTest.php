<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\workspace_events\ConfigRecordInstaller;
use Drupal\workspace_events\ConfigRecordLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the creation of configuration entities from configuration records.
 */
#[CoversClass(ConfigRecordInstaller::class)]
#[CoversClass(ConfigRecordLoader::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class ConfigRecordInstallerTest extends EventKernelTestBase {

  /**
   * Tests the sync UUIDs, existing entities and the install fallback.
   */
  public function testInstall(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $resolver = $this->container->get(ExtensionPathResolver::class);
    \assert($resolver instanceof ExtensionPathResolver);
    $loader = static function (StorageInterface $storage) use ($resolver): ConfigRecordLoader {
      return new ConfigRecordLoader($storage, $resolver);
    };

    $names = ['taxonomy.vocabulary.dance_figure', 'user.role.member'];
    [$sync_storage, $uuids] = $this->createSyncStorage($names, $resolver);

    // An existing entity must keep its data and its UUID.
    Vocabulary::create([
      'vid' => 'dance_figure',
      'name' => 'Bestehende Figuren',
    ])->save();

    $this->installer($entity_type_manager, $loader($sync_storage), $names)->install();

    $vocabulary = Vocabulary::load('dance_figure');
    self::assertInstanceOf(Vocabulary::class, $vocabulary);
    self::assertSame('Bestehende Figuren', $vocabulary->label());
    self::assertNotSame($uuids['taxonomy.vocabulary.dance_figure'], $vocabulary->uuid());

    $role = Role::load('member');
    self::assertInstanceOf(Role::class, $role);
    self::assertSame($uuids['user.role.member'], $role->uuid());

    // Without a sync record the module install configuration is used.
    $role->delete();
    $this->installer($entity_type_manager, $loader(new MemoryStorage()), ['user.role.member'])->install();

    $role = Role::load('member');
    self::assertInstanceOf(Role::class, $role);
    self::assertSame('Mitglied', $role->label());
    self::assertNotSame($uuids['user.role.member'], $role->uuid());
  }

  /**
   * Creates a sync storage with test UUIDs for some configuration records.
   *
   * @param string[] $names
   *   The configuration names.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $resolver
   *   The extension path resolver.
   *
   * @return array{\Drupal\Core\Config\StorageInterface, array<string, string>}
   *   The sync storage and the UUIDs keyed by configuration name.
   */
  private function createSyncStorage(array $names, ExtensionPathResolver $resolver): array {
    $storage = new FileStorage($resolver->getPath('module', 'workspace_events') . '/config/install');
    $sync_storage = new MemoryStorage();
    $uuids = [];
    foreach ($names as $index => $name) {
      $data = $storage->read($name);
      self::assertIsArray($data);
      $uuid = sprintf('00000000-0000-4000-8000-%012d', $index + 1);
      $data['uuid'] = $uuid;
      $sync_storage->write($name, $data);
      $uuids[$name] = $uuid;
    }
    return [$sync_storage, $uuids];
  }

  /**
   * Creates an installer for the given configuration records.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\workspace_events\ConfigRecordLoader $loader
   *   The configuration record loader.
   * @param string[] $names
   *   The configuration names.
   *
   * @return \Drupal\workspace_events\ConfigRecordInstaller
   *   The installer.
   */
  private function installer(EntityTypeManagerInterface $entity_type_manager, ConfigRecordLoader $loader, array $names): ConfigRecordInstaller {
    return new class($entity_type_manager, $loader, $names) extends ConfigRecordInstaller {

      /**
       * {@inheritdoc}
       */
      public const array CONFIG_NAMES = [];

      /**
       * Constructs the test installer.
       *
       * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
       *   The entity type manager.
       * @param \Drupal\workspace_events\ConfigRecordLoader $loader
       *   The configuration record loader.
       * @param string[] $names
       *   The configuration names.
       */
      public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigRecordLoader $loader, private readonly array $names) {
        parent::__construct($entity_type_manager, $loader);
      }

      /**
       * {@inheritdoc}
       */
      public function install(?array $config_names = NULL): void {
        parent::install($this->names);
      }

    };
  }

}
