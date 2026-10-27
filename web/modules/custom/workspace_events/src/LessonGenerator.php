<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;

/**
 * Generates the lessons of a course from its schedule.
 *
 * The lessons are created once, when a course is saved without lessons. They
 * are not synchronized afterwards: changing the schedule only takes effect
 * after the lessons have been deleted.
 */
final class LessonGenerator {

  /**
   * The node bundle of the courses.
   */
  public const string COURSE_BUNDLE = 'course';

  /**
   * The node bundle of the lessons.
   */
  public const string LESSON_BUNDLE = 'lesson';

  /**
   * Constructs a LessonGenerator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SiteTimeZone $siteTimeZone,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Generates the lessons of a course when it has none yet.
   *
   * References to lessons that no longer exist are removed in every case, so
   * a bulk delete in the content overview lets the course forget the deleted
   * lessons and create new ones on the next save.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   *
   * @return int
   *   The number of lessons created.
   */
  public function generate(NodeInterface $course): int {
    if ($course->bundle() !== self::COURSE_BUNDLE || !$course->hasField('field_lessons')) {
      return 0;
    }

    $existing = $this->getExistingLessonIds($course);
    $course->set('field_lessons', $this->toFieldValues($existing));
    if ($existing !== []) {
      return 0;
    }

    $schedule = $this->readSchedule($course);
    if ($schedule === NULL) {
      return 0;
    }

    $lesson_ids = $this->createLessons($course, $schedule);
    $course->set('field_lessons', $this->toFieldValues($lesson_ids));

    return count($lesson_ids);
  }

  /**
   * Returns the referenced lessons that still exist, in their stored order.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   *
   * @return int[]
   *   The IDs of the existing lessons.
   */
  private function getExistingLessonIds(NodeInterface $course): array {
    $ids = [];
    foreach ($course->get('field_lessons') as $item) {
      $target_id = $item->get('target_id')->getString();
      if ($target_id === '' || !ctype_digit($target_id)) {
        continue;
      }
      $id = (int) $target_id;
      if (!in_array($id, $ids, TRUE)) {
        $ids[] = $id;
      }
    }
    if ($ids === []) {
      return [];
    }

    $existing = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $ids, 'IN')
      ->condition('type', self::LESSON_BUNDLE)
      ->execute();
    $existing_ids = array_map(static fn (mixed $id): int => (int) $id, array_values($existing));

    return array_values(array_filter(
      $ids,
      static fn (int $id): bool => in_array($id, $existing_ids, TRUE),
    ));
  }

  /**
   * Reads the schedule fields of a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   *
   * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, last: \DateTimeImmutable, interval: int, timezone: \DateTimeZone}|null
   *   The schedule, or NULL when the course has no complete schedule.
   */
  private function readSchedule(NodeInterface $course): ?array {
    if (
      !$course->hasField('field_course_first')
      || !$course->hasField('field_course_last')
      || !$course->hasField('field_course_interval')
    ) {
      return NULL;
    }

    $first = $course->get('field_course_first')->first();
    $last = $course->get('field_course_last')->first();
    if ($first === NULL || $last === NULL) {
      return NULL;
    }

    $start_value = $first->get('value')->getString();
    $end_value = $first->get('end_value')->getString();
    $last_value = $last->get('value')->getString();
    if ($start_value === '' || $last_value === '') {
      return NULL;
    }

    $timezone = $this->siteTimeZone->get();
    $start = $this->fromStorageValue($start_value, $timezone);
    $end = $end_value === '' ? $start : $this->fromStorageValue($end_value, $timezone);
    $last_day = \DateTimeImmutable::createFromFormat('!Y-m-d', $last_value, $timezone);
    if ($last_day === FALSE) {
      return NULL;
    }

    $interval = 1;
    $interval_item = $course->get('field_course_interval')->first();
    if ($interval_item !== NULL) {
      $interval = (int) $interval_item->get('value')->getString();
      if ($interval < 1) {
        $interval = 1;
      }
    }

    return [
      'start' => $start,
      'end' => $end,
      'last' => $last_day,
      'interval' => $interval,
      'timezone' => $timezone,
    ];
  }

  /**
   * Creates the lessons of a schedule.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   * @param array{start: \DateTimeImmutable, end: \DateTimeImmutable, last: \DateTimeImmutable, interval: int, timezone: \DateTimeZone} $schedule
   *   The schedule of the course.
   *
   * @return int[]
   *   The IDs of the created lessons, in date order.
   */
  private function createLessons(NodeInterface $course, array $schedule): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $now = $this->time->getRequestTime();
    $interval_days = $schedule['interval'] * 7;
    $first_day = $schedule['start']->setTime(0, 0);
    $last_day = $schedule['last']->format('Y-m-d');
    $start_time = $schedule['start']->format('H:i:s');
    $end_time = $schedule['end']->format('H:i:s');
    $timezone = $schedule['timezone'];

    $lesson_ids = [];
    $offset = 0;
    while (TRUE) {
      $day = $first_day->modify(sprintf('+%d days', $offset));
      if ($day->format('Y-m-d') > $last_day) {
        break;
      }

      $start = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $start_time, $timezone);
      $end = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $end_time, $timezone);
      $end_timestamp = $end->getTimestamp();
      $published = $end_timestamp <= $now;

      $lesson = $storage->create([
        'type' => self::LESSON_BUNDLE,
        'title' => (string) $course->label() . ' – ' . $day->format('d.m.Y'),
        'uid' => $course->getOwnerId(),
        'langcode' => $course->language()->getId(),
        'status' => $published ? NodeInterface::PUBLISHED : NodeInterface::NOT_PUBLISHED,
        'field_lesson_date' => [
          'value' => $this->toStorageValue($start),
          'end_value' => $this->toStorageValue($end),
        ],
        'field_cancelled' => FALSE,
      ]);
      \assert($lesson instanceof NodeInterface);

      if (!$published && $lesson->hasField('publish_on')) {
        $lesson->set('publish_on', $end_timestamp);
      }

      // The lessons are created by the course save, not by an editor.
      $lesson->setSyncing(TRUE);
      $lesson->save();
      $lesson_ids[] = (int) $lesson->id();
      $offset += $interval_days;
    }

    return $lesson_ids;
  }

  /**
   * Builds entity reference field values from lesson IDs.
   *
   * @param int[] $ids
   *   The lesson IDs.
   *
   * @return array<int, array{target_id: int}>
   *   The field values.
   */
  private function toFieldValues(array $ids): array {
    return array_values(array_map(static fn (int $id): array => ['target_id' => $id], $ids));
  }

  /**
   * Converts a stored date to the site timezone.
   *
   * Date fields store their values in UTC.
   *
   * @param string $value
   *   The stored value.
   * @param \DateTimeZone $timezone
   *   The site timezone.
   *
   * @return \DateTimeImmutable
   *   The date in the site timezone.
   */
  private function fromStorageValue(string $value, \DateTimeZone $timezone): \DateTimeImmutable {
    return (new \DateTimeImmutable($value, new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE)))
      ->setTimezone($timezone);
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

}
