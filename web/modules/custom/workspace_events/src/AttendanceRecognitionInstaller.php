<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Creates the configuration of the AI attendance recognition.
 */
final class AttendanceRecognitionInstaller extends ConfigRecordInstaller {

  /**
   * The configuration entities of the attendance recognition.
   */
  public const array CONFIG_NAMES = [
    'key.key.mistral_api_key',
    'ai_automators.ai_automator.node.lesson.field_participants.default',
  ];

  /**
   * The name of the lesson form display.
   */
  private const string FORM_DISPLAY = 'core.entity_form_display.node.lesson.default';

  /**
   * The ID of the lesson form display.
   */
  private const string FORM_DISPLAY_ID = 'node.lesson.default';

  /**
   * The name of the Mistral provider settings.
   */
  private const string PROVIDER_SETTINGS = 'ai_provider_mistral.settings';

  /**
   * Constructs an AttendanceRecognitionInstaller.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\ConfigRecordLoader $configRecordLoader
   *   The loader of the configuration records.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    ConfigRecordLoader $configRecordLoader,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($entityTypeManager, $configRecordLoader);
  }

  /**
   * {@inheritdoc}
   */
  public function install(?array $config_names = NULL): void {
    parent::install($config_names);
    $this->installProviderSettings();
    $this->installFormDisplay();
  }

  /**
   * Points the Mistral provider at the Key entity.
   *
   * An API key that is already configured is kept, so an update never
   * overwrites a key an administrator chose.
   */
  private function installProviderSettings(): void {
    $config = $this->configFactory->getEditable(self::PROVIDER_SETTINGS);
    if ($config->get('api_key')) {
      return;
    }

    $record = $this->configRecordLoader->load(self::PROVIDER_SETTINGS);
    $config
      ->set('api_key', $record['api_key'] ?? AttendanceRecognition::KEY_ID)
      ->set('host', $record['host'] ?? '')
      ->save();
  }

  /**
   * Adds the attendance action to the existing lesson form display.
   */
  private function installFormDisplay(): void {
    $record = $this->configRecordLoader->load(self::FORM_DISPLAY);
    $content = $record['content'] ?? [];
    $component = is_array($content) ? ($content['field_participants'] ?? NULL) : NULL;
    if (!is_array($component)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('entity_form_display');
    if (!$storage instanceof ConfigEntityStorageInterface) {
      return;
    }

    $display = $storage->load(self::FORM_DISPLAY_ID);
    if ($display === NULL) {
      $storage->createFromStorageRecord($record)->save();
      return;
    }
    if (!$display instanceof EntityFormDisplayInterface) {
      return;
    }

    $current = $display->getComponent('field_participants') ?? [];

    $componentSettings = $component['third_party_settings'] ?? [];
    $actions = is_array($componentSettings) ? ($componentSettings['field_widget_actions'] ?? []) : [];
    if (!is_array($actions)) {
      $actions = [];
    }

    if ($current === []) {
      $display->setComponent('field_participants', $component);
    }
    elseif ($actions !== []) {
      $currentSettings = $current['third_party_settings'] ?? [];
      if (!is_array($currentSettings)) {
        $currentSettings = [];
      }
      $currentActions = $currentSettings['field_widget_actions'] ?? [];
      if (!is_array($currentActions)) {
        $currentActions = [];
      }
      $currentSettings['field_widget_actions'] = array_replace($currentActions, $actions);
      $current['third_party_settings'] = $currentSettings;
      $display->setComponent('field_participants', $current);
    }
    else {
      return;
    }
    $display->save();
  }

}
