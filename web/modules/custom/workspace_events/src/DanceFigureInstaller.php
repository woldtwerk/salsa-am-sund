<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Creates the dance figure vocabulary and its configuration.
 */
final class DanceFigureInstaller {

  /**
   * The configuration entities of the dance figure vocabulary.
   *
   * The order is the order the entities have to be created in: the vocabulary
   * first, then its fields, then the displays and finally the role.
   */
  public const array CONFIG_NAMES = [
    'taxonomy.vocabulary.dance_figure',
    'field.storage.taxonomy_term.field_difficulty',
    'field.field.taxonomy_term.dance_figure.field_difficulty',
    'field.storage.taxonomy_term.field_video',
    'field.field.taxonomy_term.dance_figure.field_video',
    'core.entity_form_display.taxonomy_term.dance_figure.default',
    'core.entity_view_display.taxonomy_term.dance_figure.default',
    'pathauto.pattern.dance_figure_path',
    'user.role.member',
  ];

  /**
   * Constructs a DanceFigureInstaller.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\ConfigRecordLoader $configRecordLoader
   *   The loader of the configuration records.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigRecordLoader $configRecordLoader,
  ) {}

  /**
   * Creates the missing configuration entities.
   *
   * Existing entities are left alone, so an update never overwrites data an
   * editor or the site configuration already changed. The configuration is
   * read from the sync storage when present, so the UUIDs match the exported
   * configuration and a later configuration import updates the entities
   * instead of recreating them.
   */
  public function install(): void {
    foreach (self::CONFIG_NAMES as $name) {
      $data = $this->configRecordLoader->load($name);
      $storage = $this->getStorage($name);
      $id = $this->getId($data, $name);
      if ($storage->load($id) !== NULL) {
        continue;
      }

      $storage->createFromStorageRecord($data)->save();
    }
  }

  /**
   * Returns the config entity storage of a configuration name.
   *
   * @param string $name
   *   The configuration name.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityStorageInterface
   *   The config entity storage.
   */
  private function getStorage(string $name): ConfigEntityStorageInterface {
    $entity_type_id = match (TRUE) {
      str_starts_with($name, 'taxonomy.vocabulary.') => 'taxonomy_vocabulary',
      str_starts_with($name, 'field.storage.') => 'field_storage_config',
      str_starts_with($name, 'field.field.') => 'field_config',
      str_starts_with($name, 'core.entity_form_display.') => 'entity_form_display',
      str_starts_with($name, 'core.entity_view_display.') => 'entity_view_display',
      str_starts_with($name, 'pathauto.pattern.') => 'pathauto_pattern',
      str_starts_with($name, 'user.role.') => 'user_role',
      default => throw new \InvalidArgumentException(sprintf('Unsupported configuration %s.', $name)),
    };

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if (!$storage instanceof ConfigEntityStorageInterface) {
      throw new \InvalidArgumentException(sprintf('Configuration %s is not stored as a config entity.', $name));
    }
    return $storage;
  }

  /**
   * Returns the ID of a configuration record.
   *
   * @param array<mixed> $data
   *   The configuration data.
   * @param string $name
   *   The configuration name.
   *
   * @return string
   *   The entity ID.
   */
  private function getId(array $data, string $name): string {
    $id = $data['id'] ?? $data['vid'] ?? NULL;
    if (!is_string($id)) {
      throw new \RuntimeException(sprintf('Missing ID in configuration %s.', $name));
    }
    return $id;
  }

}
