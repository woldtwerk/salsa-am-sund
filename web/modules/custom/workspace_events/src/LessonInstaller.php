<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Creates the lesson bundle, its fields and the course and event date fields.
 */
final class LessonInstaller extends ConfigRecordInstaller {

  /**
   * The configuration entities of the lesson bundle.
   *
   * Node types and field storages come before the field instances, and the
   * displays come last.
   */
  public const array CONFIG_NAMES = [
    'node.type.lesson',
    'field.storage.node.field_course_first',
    'field.field.node.course.field_course_first',
    'field.storage.node.field_course_last',
    'field.field.node.course.field_course_last',
    'field.storage.node.field_course_interval',
    'field.field.node.course.field_course_interval',
    'field.storage.node.field_lessons',
    'field.field.node.course.field_lessons',
    'field.storage.node.field_event_date',
    'field.field.node.event.field_event_date',
    'field.storage.node.field_lesson_date',
    'field.field.node.lesson.field_lesson_date',
    'field.storage.node.field_cancelled',
    'field.field.node.lesson.field_cancelled',
    'field.storage.node.field_dance_figures',
    'field.field.node.lesson.field_dance_figures',
    'field.storage.node.field_participants',
    'field.field.node.lesson.field_participants',
    'core.entity_form_display.node.course.default',
    'core.entity_view_display.node.course.default',
    'core.entity_form_display.node.event.default',
    'core.entity_view_display.node.event.default',
    'core.entity_form_display.node.lesson.default',
    'core.entity_view_display.node.lesson.default',
  ];

}
