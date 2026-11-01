<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Scheduler;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\scheduler\SchedulerPluginBase;

/**
 * Plugin for the lesson entity type.
 *
 * Scheduler only supports annotations for its plugins, so this one cannot use
 * a PHP attribute.
 *
 * @SchedulerPlugin(
 *  id = "lesson_scheduler",
 *  label = @Translation("Lesson Scheduler Plugin"),
 *  description = @Translation("Provides support for scheduling lessons"),
 *  entityType = "lesson",
 *  dependency = "workspace_events",
 *  schedulerEventClass = "\Drupal\workspace_events\Event\SchedulerLessonEvents",
 *  collectionRoute = "entity.lesson.collection",
 * )
 */
final class LessonScheduler extends SchedulerPluginBase implements ContainerFactoryPluginInterface {}
