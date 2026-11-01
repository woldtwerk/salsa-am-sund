<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Event;

/**
 * Lists the six events dispatched by Scheduler relating to lessons.
 */
final class SchedulerLessonEvents {

  /**
   * The event triggered after a lesson is published immediately.
   *
   * @var string
   */
  public const string PUBLISH_IMMEDIATELY = 'scheduler.lesson_publish_immediately';

  /**
   * The event triggered after a lesson is published by cron.
   *
   * @var string
   */
  public const string PUBLISH = 'scheduler.lesson_publish';

  /**
   * The event triggered before a lesson is published immediately.
   *
   * @var string
   */
  public const string PRE_PUBLISH_IMMEDIATELY = 'scheduler.lesson_pre_publish_immediately';

  /**
   * The event triggered before a lesson is published by cron.
   *
   * @var string
   */
  public const string PRE_PUBLISH = 'scheduler.lesson_pre_publish';

  /**
   * The event triggered before a lesson is unpublished by cron.
   *
   * @var string
   */
  public const string PRE_UNPUBLISH = 'scheduler.lesson_pre_unpublish';

  /**
   * The event triggered after a lesson is unpublished by cron.
   *
   * @var string
   */
  public const string UNPUBLISH = 'scheduler.lesson_unpublish';

}
