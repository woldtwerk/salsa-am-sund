<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\SeasonInterface;

/**
 * Generates the lessons of a season from its schedule.
 */
final class LessonGenerator {

  /**
   * The node bundle of the courses.
   */
  public const string COURSE_BUNDLE = 'course';

  /**
   * Constructs a LessonGenerator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\workspace_events\SeasonRepository $seasonRepository
   *   The season repository.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SiteTimeZone $siteTimeZone,
    private readonly TimeInterface $time,
    private readonly SeasonRepository $seasonRepository,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Generates the lessons of a new season from its schedule.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   */
  public function generate(SeasonInterface $season): void {
    $this->sync($season);
  }

  /**
   * Synchronizes the lessons of a season with the dates of its schedule.
   *
   * Every date of the schedule becomes one lesson. Lessons whose date is no
   * longer in the schedule are deleted, unless they have recorded data: those
   * keep their date and are only reported.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   */
  public function sync(SeasonInterface $season): void {
    if ($season->isNew()) {
      return;
    }

    $course = $season->getCourse();
    if (!$course instanceof NodeInterface) {
      return;
    }

    $diff = $this->diff($season);
    $storage = $this->entityTypeManager->getStorage('lesson');

    if ($diff['remove'] !== []) {
      $storage->delete($diff['remove']);
    }

    $now = $this->time->getRequestTime();
    foreach ($diff['create'] as $start => $end) {
      $this->createLesson($storage, $season, $course, $start, $end, $now);
    }

    foreach ($diff['update'] as $start => $end) {
      $lesson = $diff['lessons'][$start];
      $lesson->set('field_lesson_date', [
        'value' => $this->toStorageValue($start),
        'end_value' => $this->toStorageValue($end),
      ]);
      $lesson->setSyncing(TRUE);
      $lesson->save();
    }

    foreach ($diff['blocked'] as $lesson) {
      $this->loggerFactory->get('workspace_events')->warning(
        'Kept lesson %id of season %season because it has recorded data; its date is no longer in the schedule.',
        ['%id' => $lesson->id(), '%season' => $season->id()],
      );
    }
  }

  /**
   * Returns the lessons with recorded data whose date left the schedule.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return \Drupal\workspace_events\Entity\LessonInterface[]
   *   The lessons that keep their date.
   */
  public function blockedLessons(SeasonInterface $season): array {
    if ($season->isNew()) {
      return [];
    }

    return $this->diff($season)['blocked'];
  }

  /**
   * Compares the dates of the schedule with the lessons of the season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return array{create: array<int, int>, update: array<int, int>, remove: \Drupal\workspace_events\Entity\LessonInterface[], blocked: \Drupal\workspace_events\Entity\LessonInterface[], lessons: array<int, \Drupal\workspace_events\Entity\LessonInterface>}
   *   The end timestamp keyed by start timestamp for new and changed lessons,
   *   the lessons to delete, the recorded lessons to keep and the existing
   *   lessons keyed by start timestamp.
   */
  private function diff(SeasonInterface $season): array {
    $dates = $this->scheduleDates($season);
    $create = [];
    $update = [];
    $remove = [];
    $blocked = [];
    $lessons = [];

    $by_start = [];
    foreach ($this->seasonRepository->loadLessons($season) as $lesson) {
      $start = $this->lessonTimestamp($lesson, 'value');
      if ($start === NULL) {
        // A lesson without a start can never match a date of the schedule.
        if ($lesson->hasRecordedData()) {
          $blocked[] = $lesson;
        }
        else {
          $remove[] = $lesson;
        }
        continue;
      }
      $by_start[$start] = $lesson;
    }

    foreach ($dates as $start => $end) {
      if (!isset($by_start[$start])) {
        $create[$start] = $end;
        continue;
      }

      $lesson = $by_start[$start];
      $lessons[$start] = $lesson;
      if ($this->lessonTimestamp($lesson, 'end_value') !== $end) {
        $update[$start] = $end;
      }
    }

    foreach ($by_start as $start => $lesson) {
      if (isset($dates[$start])) {
        continue;
      }
      if ($lesson->hasRecordedData()) {
        $blocked[] = $lesson;
      }
      else {
        $remove[] = $lesson;
      }
    }

    return [
      'create' => $create,
      'update' => $update,
      'remove' => $remove,
      'blocked' => $blocked,
      'lessons' => $lessons,
    ];
  }

  /**
   * Reads the dates of the schedule as start and end timestamps.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return array<int, int>
   *   The end timestamp keyed by start timestamp, in date order.
   */
  private function scheduleDates(SeasonInterface $season): array {
    if (!$season->hasField('field_schedule')) {
      return [];
    }

    $dates = [];
    foreach ($season->get('field_schedule') as $item) {
      $start = (int) $item->get('value')->getString();
      if ($start <= 0) {
        continue;
      }
      $end = (int) $item->get('end_value')->getString();
      $dates[$start] = $end > 0 ? $end : $start;
    }
    ksort($dates);

    return $dates;
  }

  /**
   * Reads a timestamp of a lesson date.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   * @param string $property
   *   The date property, "value" or "end_value".
   *
   * @return int|null
   *   The timestamp, or NULL when the lesson or the property has no date.
   */
  private function lessonTimestamp(LessonInterface $lesson, string $property): ?int {
    if (!$lesson->hasField('field_lesson_date')) {
      return NULL;
    }
    $item = $lesson->get('field_lesson_date')->first();
    if ($item === NULL) {
      return NULL;
    }

    $value = (string) $item->get($property)->getString();
    return $value === '' ? NULL : $this->siteTimeZone->fromStorageValue($value)->getTimestamp();
  }

  /**
   * Creates the lesson of one date.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The lesson storage.
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   * @param \Drupal\node\NodeInterface $course
   *   The course of the season.
   * @param int $start
   *   The start timestamp.
   * @param int $end
   *   The end timestamp.
   * @param int $now
   *   The request time.
   */
  private function createLesson(EntityStorageInterface $storage, SeasonInterface $season, NodeInterface $course, int $start, int $end, int $now): void {
    $lesson = $storage->create([
      'title' => (string) $course->label() . ' – ' . $this->siteTimeZone->fromTimestamp($start)->format('d.m.Y'),
      'season' => $season->id(),
      'uid' => $course->getOwnerId(),
      'langcode' => $course->language()->getId(),
      'field_lesson_date' => [
        'value' => $this->toStorageValue($start),
        'end_value' => $this->toStorageValue($end),
      ],
      'field_cancelled' => FALSE,
    ]);
    \assert($lesson instanceof LessonInterface);

    if ($end <= $now) {
      $lesson->setPublished();
    }
    else {
      $lesson->setUnpublished();
      if ($lesson->hasField('publish_on')) {
        $lesson->set('publish_on', $end);
      }
    }

    // The lessons are created by the season save, not by an editor.
    $lesson->setSyncing(TRUE);
    $lesson->save();
  }

  /**
   * Converts a timestamp to the date storage format.
   *
   * Date fields store their values in UTC.
   *
   * @param int $timestamp
   *   The timestamp.
   *
   * @return string
   *   The value in the storage timezone.
   */
  private function toStorageValue(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE))
      ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
  }

}
