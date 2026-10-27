<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;

/**
 * Migrates the Smart Date series of courses and events to the new date fields.
 */
final class ScheduleMigrator {

  /**
   * The legacy Smart Date field on courses and events.
   */
  public const string LEGACY_FIELD_NAME = 'field_when';

  /**
   * The node bundle of the courses.
   */
  private const string COURSE_BUNDLE = 'course';

  /**
   * The node bundle of the events.
   */
  private const string EVENT_BUNDLE = 'event';

  /**
   * Constructs a ScheduleMigrator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager, used to detect the legacy field.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SiteTimeZone $siteTimeZone,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Migrates every course and event.
   *
   * @return string
   *   The update message for the collapsed event series, or an empty string
   *   when no event had more than one date.
   */
  public function migrate(): string {
    // The migration only runs while the legacy field still exists. After the
    // smart_date removal update it does nothing.
    if (!array_key_exists(self::LEGACY_FIELD_NAME, $this->entityFieldManager->getFieldStorageDefinitions('node'))) {
      return '';
    }

    $this->migrateCourses();
    return $this->migrateEvents();
  }

  /**
   * Migrates the course series and generates their lessons.
   */
  private function migrateCourses(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::COURSE_BUNDLE)
      ->execute();

    foreach ($storage->loadMultiple($ids) as $course) {
      if (!$course instanceof NodeInterface) {
        continue;
      }

      $first_missing = !$course->hasField('field_course_first')
        || $course->get('field_course_first')->isEmpty();
      $lessons_present = $course->hasField('field_lessons')
        && !$course->get('field_lessons')->isEmpty();
      if (!$first_missing && $lessons_present) {
        continue;
      }

      if ($first_missing) {
        $this->applyCourseSchedule($course);
      }

      // Saving the course runs the generator through the presave hook.
      $course->setSyncing(TRUE);
      $course->setNewRevision(FALSE);
      $course->save();
    }
  }

  /**
   * Copies the first and last date and the interval to the course fields.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   *
   * @return bool
   *   TRUE when the course had a legacy series, FALSE otherwise.
   */
  private function applyCourseSchedule(NodeInterface $course): bool {
    if (!$course->hasField(self::LEGACY_FIELD_NAME)) {
      return FALSE;
    }
    $items = $course->get(self::LEGACY_FIELD_NAME);
    if ($items->isEmpty()) {
      return FALSE;
    }

    $first = $items->first();
    if ($first === NULL) {
      return FALSE;
    }
    $first_timestamp = (int) $first->get('value')->getString();
    if ($first_timestamp <= 0) {
      return FALSE;
    }

    $last_timestamp = 0;
    $rule_id = 0;
    foreach ($items as $item) {
      $value = (int) $item->get('value')->getString();
      if ($value > $last_timestamp) {
        $last_timestamp = $value;
      }
      $rrule = (int) $item->get('rrule')->getString();
      if ($rule_id === 0 && $rrule > 0) {
        $rule_id = $rrule;
      }
    }
    if ($last_timestamp <= 0) {
      return FALSE;
    }

    $timezone = $this->siteTimeZone->get();
    $start = $this->toDate($first_timestamp, $timezone);
    $end = $this->readEnd($first, $timezone) ?? $start;
    $last_day = $this->toDate($last_timestamp, $timezone);

    $course->set('field_course_first', [
      'value' => $this->toStorageValue($start),
      'end_value' => $this->toStorageValue($end),
    ]);
    $course->set('field_course_last', ['value' => $last_day->format('Y-m-d')]);
    $course->set('field_course_interval', ['value' => $this->readInterval($rule_id)]);

    return TRUE;
  }

  /**
   * Migrates the event series to a single date.
   *
   * @return string
   *   One line per event that had more than one date.
   */
  private function migrateEvents(): string {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::EVENT_BUNDLE)
      ->execute();

    $messages = [];
    foreach ($storage->loadMultiple($ids) as $event) {
      if (!$event instanceof NodeInterface) {
        continue;
      }
      if (!$event->hasField(self::LEGACY_FIELD_NAME) || !$event->hasField('field_event_date')) {
        continue;
      }
      if (!$event->get('field_event_date')->isEmpty()) {
        continue;
      }

      $items = $event->get(self::LEGACY_FIELD_NAME);
      if ($items->isEmpty()) {
        continue;
      }

      $now = $this->time->getRequestTime();
      $next = NULL;
      $next_timestamp = 0;
      $last = NULL;
      $last_timestamp = 0;
      $timestamps = [];
      foreach ($items as $item) {
        $value = (int) $item->get('value')->getString();
        if ($value <= 0) {
          continue;
        }
        $timestamps[$value] = TRUE;
        if ($value >= $now && ($next === NULL || $value < $next_timestamp)) {
          $next = $item;
          $next_timestamp = $value;
        }
        if ($last === NULL || $value > $last_timestamp) {
          $last = $item;
          $last_timestamp = $value;
        }
      }

      $selected = $next ?? $last;
      if ($selected === NULL) {
        continue;
      }

      $timezone = $this->siteTimeZone->get();
      $start_timestamp = (int) $selected->get('value')->getString();
      $start = $this->toDate($start_timestamp, $timezone);
      $end = $this->readEnd($selected, $timezone) ?? $start;

      $event->set('field_event_date', [
        'value' => $this->toStorageValue($start),
        'end_value' => $this->toStorageValue($end),
      ]);
      $event->setSyncing(TRUE);
      $event->setNewRevision(FALSE);
      $event->save();

      if (count($timestamps) > 1) {
        $messages[] = $this->logCollapsedSeries($event, count($timestamps), $start);
      }
    }

    return implode("\n", $messages);
  }

  /**
   * Logs an event whose series was collapsed to a single date.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param int $date_count
   *   The number of dates the event had.
   * @param \DateTimeImmutable $date
   *   The date that was kept.
   *
   * @return string
   *   The update message.
   */
  private function logCollapsedSeries(NodeInterface $event, int $date_count, \DateTimeImmutable $date): string {
    $message = sprintf(
      'Event "%s" (%d): %d dates collapsed to %s.',
      (string) $event->label(),
      (int) $event->id(),
      $date_count,
      $date->format('Y-m-d'),
    );
    $this->loggerFactory->get('workspace_events')->notice($message);

    return $message;
  }

  /**
   * Returns the interval of a Smart Date rule in weeks.
   *
   * @param int $rule_id
   *   The Smart Date rule ID, or 0 when the series has no rule.
   *
   * @return int
   *   The interval in weeks. Only one and two weeks are supported.
   */
  private function readInterval(int $rule_id): int {
    if ($rule_id <= 0 || !class_exists(SmartDateRule::class) || !$this->entityTypeManager->hasDefinition('smart_date_rule')) {
      return 1;
    }

    $rule = $this->entityTypeManager->getStorage('smart_date_rule')->load($rule_id);
    if (!$rule instanceof SmartDateRule) {
      return 1;
    }

    $parameters = $rule->getParametersArray();
    if (!is_array($parameters)) {
      return 1;
    }
    $interval = $parameters['interval'] ?? 0;
    $interval = is_numeric($interval) ? (int) $interval : 0;

    return in_array($interval, [1, 2], TRUE) ? $interval : 1;
  }

  /**
   * Converts a timestamp to a date in the site timezone.
   *
   * @param int $timestamp
   *   The timestamp.
   * @param \DateTimeZone $timezone
   *   The site timezone.
   *
   * @return \DateTimeImmutable
   *   The date.
   */
  private function toDate(int $timestamp, \DateTimeZone $timezone): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
  }

  /**
   * Converts a date in the site timezone to the storage format.
   *
   * Date fields store their values in UTC.
   *
   * @param \DateTimeImmutable $date
   *   The date in the site timezone.
   *
   * @return string
   *   The value in the storage timezone.
   */
  private function toStorageValue(\DateTimeImmutable $date): string {
    return $date
      ->setTimezone(new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE))
      ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
  }

  /**
   * Reads the end of a Smart Date value.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   The Smart Date value.
   * @param \DateTimeZone $timezone
   *   The site timezone.
   *
   * @return \DateTimeImmutable|null
   *   The end, or NULL when the value has none.
   */
  private function readEnd(FieldItemInterface $item, \DateTimeZone $timezone): ?\DateTimeImmutable {
    $end_value = (int) $item->get('end_value')->getString();

    return $end_value > 0 ? $this->toDate($end_value, $timezone) : NULL;
  }

}
