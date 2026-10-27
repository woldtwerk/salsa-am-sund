<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\LessonSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the date calculation of the course schedule.
 */
#[CoversClass(LessonSchedule::class)]
#[Group('workspace_events')]
final class LessonScheduleTest extends UnitTestCase {

  /**
   * Tests the lesson dates of a schedule.
   *
   * @param string $firstStart
   *   The start of the first lesson.
   * @param string $firstEnd
   *   The end of the first lesson.
   * @param string $lastDate
   *   The date of the last lesson.
   * @param int $interval
   *   The interval in weeks.
   * @param string[] $expected
   *   The expected local start dates.
   * @param int|null $firstDistance
   *   The expected seconds between the first two starts, or NULL to skip.
   */
  #[DataProvider('scheduleProvider')]
  public function testOccurrences(string $firstStart, string $firstEnd, string $lastDate, int $interval, array $expected, ?int $firstDistance = NULL): void {
    $timezone = new \DateTimeZone('Europe/Berlin');
    $occurrences = LessonSchedule::occurrences(
      new \DateTimeImmutable($firstStart, $timezone),
      new \DateTimeImmutable($firstEnd, $timezone),
      new \DateTimeImmutable($lastDate, $timezone),
      $interval,
      $timezone,
    );

    self::assertSame($expected, array_map(
      static fn (array $occurrence): string => $occurrence['start']->format('Y-m-d H:i'),
      $occurrences,
    ));
    if ($firstDistance !== NULL) {
      self::assertSame($firstDistance, $occurrences[1]['start']->getTimestamp() - $occurrences[0]['start']->getTimestamp());
    }
  }

  /**
   * Provides schedules and their expected lesson starts.
   *
   * @return iterable<string, array{0: string, 1: string, 2: string, 3: int, 4: string[], 5?: int}>
   *   The test cases.
   */
  public static function scheduleProvider(): iterable {
    yield 'weekly' => [
      '2027-03-01 18:00',
      '2027-03-01 19:30',
      '2027-03-15',
      1,
      ['2027-03-01 18:00', '2027-03-08 18:00', '2027-03-15 18:00'],
      604800,
    ];
    yield 'every two weeks' => [
      '2027-03-01 18:00',
      '2027-03-01 19:30',
      '2027-03-29',
      2,
      ['2027-03-01 18:00', '2027-03-15 18:00', '2027-03-29 18:00'],
      1209600,
    ];
    yield 'last date inclusive' => [
      '2027-03-01 18:00',
      '2027-03-01 19:30',
      '2027-03-08',
      1,
      ['2027-03-01 18:00', '2027-03-08 18:00'],
    ];
    yield 'last date before first date' => [
      '2027-03-08 18:00',
      '2027-03-08 19:30',
      '2027-03-01',
      1,
      [],
    ];
    yield 'daylight saving keeps the local time' => [
      '2027-03-22 18:00',
      '2027-03-22 19:30',
      '2027-04-05',
      1,
      ['2027-03-22 18:00', '2027-03-29 18:00', '2027-04-05 18:00'],
      // The week of the DST change is one hour shorter in UTC.
      601200,
    ];
  }

}
