<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Calculates the dates of the lessons of a course schedule.
 *
 * The calculation is pure: it only depends on the first date, the last date
 * and the interval, so it can be tested without a database.
 */
final class LessonSchedule {

  /**
   * Returns the start and end dates of every lesson of a schedule.
   *
   * The last date is inclusive. The local time of day is kept across daylight
   * saving changes, because every date is built from its calendar day.
   *
   * @param \DateTimeImmutable $firstStart
   *   The start of the first lesson.
   * @param \DateTimeImmutable $firstEnd
   *   The end of the first lesson.
   * @param \DateTimeImmutable $lastDate
   *   The date of the last lesson.
   * @param int $interval
   *   The interval in weeks.
   * @param \DateTimeZone $timezone
   *   The site timezone.
   *
   * @return array<int, array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
   *   The start and end of every lesson, in date order.
   */
  public static function occurrences(
    \DateTimeImmutable $firstStart,
    \DateTimeImmutable $firstEnd,
    \DateTimeImmutable $lastDate,
    int $interval,
    \DateTimeZone $timezone,
  ): array {
    $interval = max(1, $interval);
    $first_day = $firstStart->setTime(0, 0);
    $last_day = $lastDate->format('Y-m-d');
    $start_time = $firstStart->format('H:i:s');
    $end_time = $firstEnd->format('H:i:s');

    $occurrences = [];
    $offset = 0;
    while (TRUE) {
      $day = $first_day->modify(sprintf('+%d days', $offset));
      if ($day->format('Y-m-d') > $last_day) {
        break;
      }

      $occurrences[] = [
        'start' => new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $start_time, $timezone),
        'end' => new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $end_time, $timezone),
      ];
      $offset += $interval * 7;
    }

    return $occurrences;
  }

}
