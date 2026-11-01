<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Creates the lesson and season config, actions and Scheduler settings.
 *
 * The lesson and season entity types themselves are installed by the update
 * hook, because they are code, not configuration.
 */
final class LessonInstaller extends ConfigRecordInstaller {

  /**
   * The configuration records of the lessons.
   *
   * Field storages come before the field instances, and the displays come
   * last.
   */
  public const array CONFIG_NAMES = [
    'field.storage.node.field_event_date',
    'field.field.node.event.field_event_date',
    'field.storage.lesson.field_lesson_date',
    'field.field.lesson.lesson.field_lesson_date',
    'field.storage.lesson.field_cancelled',
    'field.field.lesson.lesson.field_cancelled',
    'field.storage.lesson.field_dance_figures',
    'field.field.lesson.lesson.field_dance_figures',
    'field.storage.lesson.field_participants',
    'field.field.lesson.lesson.field_participants',
    'field.storage.course_season.field_schedule',
    'field.field.course_season.course_season.field_schedule',
    'field.storage.course_season.field_prices',
    'field.field.course_season.course_season.field_prices',
    'scheduler.no_bundle_entity_type_settings.lesson',
    'system.action.lesson_delete_action',
    'core.entity_form_display.node.course.default',
    'core.entity_view_display.node.course.default',
    'core.entity_form_display.node.event.default',
    'core.entity_view_display.node.event.default',
    'core.entity_form_display.lesson.lesson.default',
    'core.entity_form_display.course_season.course_season.default',
    'core.entity_view_display.course_season.course_season.default',
  ];

  /**
   * Constructs a LessonInstaller.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\ConfigRecordLoader $configRecordLoader
   *   The loader of the configuration records.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory, used for the plain scheduler config.
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
    $names = $config_names ?? static::CONFIG_NAMES;
    $scheduler_names = array_values(array_filter(
      $names,
      static fn (string $name): bool => str_starts_with($name, 'scheduler.no_bundle_entity_type_settings.'),
    ));
    $entity_names = array_values(array_diff($names, $scheduler_names));

    parent::install($entity_names);

    // Scheduler's no-bundle settings are plain configuration, not a config
    // entity, so ConfigRecordInstaller cannot create them.
    foreach ($scheduler_names as $name) {
      $config = $this->configFactory->getEditable($name);
      if (!$config->isNew()) {
        continue;
      }
      $config->setData($this->configRecordLoader->load($name))->save();
    }
  }

}
