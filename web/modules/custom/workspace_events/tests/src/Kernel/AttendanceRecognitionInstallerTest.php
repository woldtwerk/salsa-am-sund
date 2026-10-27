<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\ai_automators\Entity\AiAutomator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\key\Entity\Key;
use Drupal\user\UserInterface;
use Drupal\workspace_events\AttendanceRecognition;
use Drupal\workspace_events\AttendanceRecognitionInstaller;
use Drupal\workspace_events\ConfigRecordLoader;
use Drupal\workspace_events\Hook\LessonAccessHooks;
use Drupal\workspace_events\LessonInstaller;
use Drupal\workspace_events\Plugin\AiAutomatorType\AttendanceFromPhoto;
use Drupal\workspace_events\Plugin\FieldWidgetAction\AttendanceFromPhotoParticipants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the configuration of the AI attendance recognition.
 */
#[CoversClass(AttendanceRecognitionInstaller::class)]
#[CoversClass(ConfigRecordLoader::class)]
#[CoversClass(AttendanceFromPhoto::class)]
#[CoversClass(AttendanceFromPhotoParticipants::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class AttendanceRecognitionInstallerTest extends LessonKernelTestBase {

  /**
   * The action instance ID in the lesson form display.
   */
  private const string ACTION_ID = '5b2a6c1e-8f4d-4d2b-9a7e-3c1f0d9e8b6a';

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The extension path resolver.
   */
  private ExtensionPathResolver $extensionPathResolver;

  /**
   * The config factory.
   */
  private ConfigFactoryInterface $configFactory;

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

    $config_factory = $this->container->get('config.factory');
    \assert($config_factory instanceof ConfigFactoryInterface);
    $this->configFactory = $config_factory;
  }

  /**
   * Tests that the key, provider setting and automator are created.
   */
  public function testInstallCreatesConfiguration(): void {
    $this->installLessonConfiguration();
    [$syncStorage, $uuids] = $this->createSyncStorage();

    $this->createInstaller($syncStorage)->install();

    $key = Key::load('mistral_api_key');
    self::assertInstanceOf(Key::class, $key);
    self::assertSame($uuids['key.key.mistral_api_key'], $key->uuid());
    self::assertSame('env', $key->getKeyProvider()->getPluginId());
    self::assertSame('MISTRAL_API_KEY', $key->getKeyProvider()->getConfiguration()['env_variable']);

    self::assertSame('mistral_api_key', $this->configFactory->get('ai_provider_mistral.settings')->get('api_key'));

    $automator = AiAutomator::load('node.lesson.field_participants.default');
    self::assertInstanceOf(AiAutomator::class, $automator);
    self::assertSame($uuids['ai_automators.ai_automator.node.lesson.field_participants.default'], $automator->uuid());
    self::assertSame('attendance_from_photo', $automator->get('rule'));
    self::assertSame('field_widget_actions', $automator->get('worker_type'));
    self::assertSame('node', $automator->get('entity_type'));
    self::assertSame('lesson', $automator->get('bundle'));
    self::assertSame('field_participants', $automator->get('field_name'));
    self::assertSame('field_attendance_photo', $automator->get('base_field'));
  }

  /**
   * Tests that the action is added to an existing lesson form display.
   */
  public function testInstallUpdatesFormDisplay(): void {
    $this->installLessonConfiguration();

    $display = EntityFormDisplay::load('node.lesson.default');
    self::assertInstanceOf(EntityFormDisplay::class, $display);
    $component = $display->getComponent('field_participants');
    unset($component['third_party_settings']['field_widget_actions']);
    $display->setComponent('field_participants', $component);
    $display->save();

    $this->createInstaller(new MemoryStorage())->install();

    $display = EntityFormDisplay::load('node.lesson.default');
    self::assertInstanceOf(EntityFormDisplay::class, $display);
    $component = $display->getComponent('field_participants');
    $actions = $component['third_party_settings']['field_widget_actions'] ?? [];
    self::assertArrayHasKey(self::ACTION_ID, $actions);
    self::assertSame('attendance_from_photo_participants', $actions[self::ACTION_ID]['plugin_id']);
    self::assertSame('node.lesson.field_participants.default', $actions[self::ACTION_ID]['settings']['automator_id']);
  }

  /**
   * Tests that the automator rule plugin is discovered and instantiated.
   */
  public function testAutomatorRuleIsAvailable(): void {
    $manager = $this->container->get('plugin.manager.ai_automator');
    self::assertTrue($manager->hasDefinition('attendance_from_photo'));

    $plugin = $manager->createInstance('attendance_from_photo');
    self::assertInstanceOf(AttendanceFromPhoto::class, $plugin);
    self::assertSame(['image'], $plugin->allowedInputs());
  }

  /**
   * Tests that an already configured provider key is not overwritten.
   */
  public function testKeepsConfiguredProviderKey(): void {
    $this->installLessonConfiguration();
    $this->configFactory->getEditable('ai_provider_mistral.settings')
      ->set('api_key', 'custom_key')
      ->save();

    $this->createInstaller(new MemoryStorage())->install();

    self::assertSame('custom_key', $this->configFactory->get('ai_provider_mistral.settings')->get('api_key'));
  }

  /**
   * Tests that the action is only available with the recognition permission.
   */
  public function testActionRequiresTheRecognitionPermission(): void {
    $this->installLessonConfiguration();

    $without_permission = $this->createUser(['access content', LessonAccessHooks::EDIT_PERMISSION]);
    $editor = $this->createUser([
      'access content',
      LessonAccessHooks::EDIT_PERMISSION,
      AttendanceRecognition::PERMISSION,
    ]);
    self::assertInstanceOf(UserInterface::class, $editor);

    $manager = $this->container->get('plugin.manager.field_widget_actions');
    $plugin = $manager->createInstance('attendance_from_photo_participants', []);
    self::assertInstanceOf(AttendanceFromPhotoParticipants::class, $plugin);

    $this->setCurrentUser($without_permission);
    self::assertFalse($plugin->isAvailable());

    $this->setCurrentUser($editor);
    self::assertTrue($plugin->isAvailable());
  }

  /**
   * Installs the lesson fields and form display.
   */
  private function installLessonConfiguration(): void {
    $installer = $this->container->get(LessonInstaller::class);
    \assert($installer instanceof LessonInstaller);
    $installer->install();
  }

  /**
   * Creates a sync storage with the records of the attendance installer.
   *
   * @return array{\Drupal\Core\Config\StorageInterface, array<string, string>}
   *   The sync storage and the UUIDs keyed by configuration name.
   */
  private function createSyncStorage(): array {
    $module_path = $this->extensionPathResolver->getPath('module', 'workspace_events');
    $install_storage = new FileStorage($module_path . '/config/install');
    $sync_storage = new MemoryStorage();
    $uuids = [];
    foreach (AttendanceRecognitionInstaller::CONFIG_NAMES as $index => $name) {
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
   * @return \Drupal\workspace_events\AttendanceRecognitionInstaller
   *   The installer.
   */
  private function createInstaller(StorageInterface $sync_storage): AttendanceRecognitionInstaller {
    return new AttendanceRecognitionInstaller(
      $this->entityTypeManager,
      new ConfigRecordLoader($sync_storage, $this->extensionPathResolver),
      $this->configFactory,
    );
  }

}
