<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\NodeInterface;

/**
 * Migrates the legacy free-text course level to the difficulty scale.
 */
final class CourseDifficultyMigrator {

  /**
   * The machine name of the difficulty field.
   */
  public const string FIELD_NAME = 'field_difficulty';

  /**
   * The machine name of the legacy course level field.
   */
  public const string LEGACY_FIELD_NAME = 'field_level';

  /**
   * The config name of the difficulty field storage.
   */
  private const string STORAGE_CONFIG_NAME = 'field.storage.node.field_difficulty';

  /**
   * The config name of the difficulty field instance.
   */
  private const string FIELD_CONFIG_NAME = 'field.field.node.course.field_difficulty';

  /**
   * Constructs a CourseDifficultyMigrator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\ConfigRecordLoader $configRecordLoader
   *   The loader of the field configuration.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigRecordLoader $configRecordLoader,
  ) {}

  /**
   * Runs the complete difficulty migration.
   *
   * Creates the difficulty field, migrates the courses and removes the old
   * field.
   */
  public function migrate(): void {
    $this->createField();
    $this->migrateCourses();
    $this->deleteLegacyField();
  }

  /**
   * Migrates the legacy level of a single course translation.
   *
   * The node is saved in sync mode and without a new revision, so the
   * migration does not trigger content moderation transitions or revision
   * history entries.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course translation.
   */
  public function migrateNode(NodeInterface $node): void {
    if (!$node->hasField(self::FIELD_NAME) || !$node->hasField(self::LEGACY_FIELD_NAME)) {
      return;
    }
    $legacy = $node->get(self::LEGACY_FIELD_NAME);
    if ($legacy->isEmpty()) {
      return;
    }

    $values = Difficulty::parse($legacy->getString());
    $node->set(self::FIELD_NAME, array_map(
      static fn (int $value): array => ['value' => $value],
      $values,
    ));
    $node->setSyncing(TRUE);
    $node->setNewRevision(FALSE);
    $node->save();
  }

  /**
   * Creates the field storage and instance when they do not exist yet.
   *
   * The configuration is taken from the sync storage when available, so the
   * UUIDs match the exported configuration and a later config import does not
   * recreate the field. Otherwise the module's install configuration is used.
   */
  private function createField(): void {
    if (FieldStorageConfig::loadByName('node', self::FIELD_NAME) === NULL) {
      FieldStorageConfig::create($this->configRecordLoader->load(self::STORAGE_CONFIG_NAME))->save();
    }

    if (FieldConfig::loadByName('node', 'course', self::FIELD_NAME) === NULL) {
      FieldConfig::create($this->configRecordLoader->load(self::FIELD_CONFIG_NAME))->save();
    }
  }

  /**
   * Migrates every course and course translation.
   */
  private function migrateCourses(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
        $translation = $node->getTranslation($langcode);
        if ($translation instanceof NodeInterface) {
          $this->migrateNode($translation);
        }
      }
    }
  }

  /**
   * Deletes the legacy course level field and its storage.
   */
  private function deleteLegacyField(): void {
    $field = FieldConfig::loadByName('node', 'course', self::LEGACY_FIELD_NAME);
    if ($field !== NULL) {
      $field->delete();
    }

    $storage = FieldStorageConfig::loadByName('node', self::LEGACY_FIELD_NAME);
    if ($storage !== NULL) {
      $storage->delete();
    }
  }

}
