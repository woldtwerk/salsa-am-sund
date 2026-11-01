<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SiteTimeZone $siteTimeZone,
    private readonly TimeInterface $time,
    private readonly SeasonRepository $seasonRepository,
  ) {}

  /**
   * Generates the lessons of a season when it has none yet.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return int
   *   The number of lessons created.
   */
  public function generate(SeasonInterface $season): int {
    if ($season->isNew()) {
      return 0;
    }
    if ($this->seasonRepository->countLessonsBySeason([(int) $season->id()]) !== []) {
      return 0;
    }

    $course = $season->getCourse();
    $schedule = $this->readSchedule($season);
    if (!$course instanceof NodeInterface || $schedule === NULL) {
      return 0;
    }

    return $this->createLessons($season, $course, $schedule);
  }

  /**
   * Replaces the lessons of a season with lessons from its current schedule.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return int
   *   The number of lessons created.
   */
  public function regenerate(SeasonInterface $season): int {
    $lessons = $this->seasonRepository->loadLessons($season);
    if ($lessons !== []) {
      $this->entityTypeManager->getStorage('lesson')->delete($lessons);
    }

    return $this->generate($season);
  }

  /**
   * Reads the schedule fields of a season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, last: \DateTimeImmutable, interval: int, timezone: \DateTimeZone}|null
   *   The schedule, or NULL when the season has no complete schedule.
   */
  private function readSchedule(SeasonInterface $season): ?array {
    $first = $season->get('field_schedule')->first();
    $last = $season->get('last')->first();
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
    $start = $this->siteTimeZone->fromStorageValue($start_value);
    $end = $end_value === '' ? $start : $this->siteTimeZone->fromStorageValue($end_value);
    $last_day = \DateTimeImmutable::createFromFormat('!Y-m-d', $last_value, $timezone);
    if ($last_day === FALSE) {
      return NULL;
    }

    $interval = (int) $season->get('interval')->getString();
    if ($interval < 1) {
      $interval = 1;
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
   * Creates the lessons of a season schedule.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   * @param \Drupal\node\NodeInterface $course
   *   The course of the season.
   * @param array{start: \DateTimeImmutable, end: \DateTimeImmutable, last: \DateTimeImmutable, interval: int, timezone: \DateTimeZone} $schedule
   *   The schedule of the season.
   *
   * @return int
   *   The number of lessons created.
   */
  private function createLessons(SeasonInterface $season, NodeInterface $course, array $schedule): int {
    $storage = $this->entityTypeManager->getStorage('lesson');
    $now = $this->time->getRequestTime();

    $count = 0;
    $occurrences = LessonSchedule::occurrences(
      $schedule['start'],
      $schedule['end'],
      $schedule['last'],
      $schedule['interval'],
      $schedule['timezone'],
    );
    foreach ($occurrences as $occurrence) {
      $end_timestamp = $occurrence['end']->getTimestamp();
      $published = $end_timestamp <= $now;

      $lesson = $storage->create([
        'title' => (string) $course->label() . ' – ' . $occurrence['start']->format('d.m.Y'),
        'season' => $season->id(),
        'uid' => $course->getOwnerId(),
        'langcode' => $course->language()->getId(),
        'field_lesson_date' => [
          'value' => $this->toStorageValue($occurrence['start']),
          'end_value' => $this->toStorageValue($occurrence['end']),
        ],
        'field_cancelled' => FALSE,
      ]);
      \assert($lesson instanceof LessonInterface);

      if ($published) {
        $lesson->setPublished();
      }
      else {
        $lesson->setUnpublished();
        if ($lesson->hasField('publish_on')) {
          $lesson->set('publish_on', $end_timestamp);
        }
      }

      // The lessons are created by the season save, not by an editor.
      $lesson->setSyncing(TRUE);
      $lesson->save();
      $count++;
    }

    return $count;
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
