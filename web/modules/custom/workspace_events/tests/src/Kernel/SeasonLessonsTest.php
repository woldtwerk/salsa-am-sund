<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\Season;
use Drupal\workspace_events\Entity\SeasonInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the lessons of a course season and the protection of recorded data.
 */
#[CoversClass(Season::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class SeasonLessonsTest extends LessonKernelTestBase {

  /**
   * Tests that the lessons follow the dates of the schedule.
   */
  public function testSyncsLessonsWithTheFieldDates(): void {
    $location = $this->createLocation('Bootshaus');
    $first = $this->date('-1 week', '18:00');
    $course = $this->createCourse([
      'title' => 'Salsa',
      'season_location' => $location->id(),
      ...$this->courseSchedule($first, $first->modify('+2 weeks')),
    ]);
    $season = $this->loadSeason($course);
    $lessons = $this->loadSeasonLessons($season);
    self::assertCount(3, $lessons);

    // The past lesson has recorded data and must keep its date.
    $past = $lessons[0];
    $past->set('field_participants', [['target_id' => $this->createUser(['access content'])->id()]]);
    $past->save();
    $past_id = (int) $past->id();

    // Removing a future date deletes its lesson.
    $this->setSchedule($season, [
      [$first, 60],
      [$first->modify('+1 week'), 60],
    ]);
    $season->save();
    $lessons = $this->loadSeasonLessons($season);
    self::assertCount(2, $lessons);
    self::assertInstanceOf(LessonInterface::class, $this->lessonStorage->load($past_id));
    self::assertNull($this->lessonStartingAt($lessons, $first->modify('+2 weeks')));

    // Adding a date creates its lesson.
    $this->setSchedule($season, [
      [$first, 60],
      [$first->modify('+1 week'), 60],
      [$first->modify('+3 weeks'), 60],
    ]);
    $season->save();
    $lessons = $this->loadSeasonLessons($season);
    self::assertCount(3, $lessons);
    self::assertNotNull($this->lessonStartingAt($lessons, $first->modify('+3 weeks')));

    // Changing the end time of a date updates the lesson end.
    $this->setSchedule($season, [
      [$first, 60],
      [$first->modify('+1 week'), 120],
      [$first->modify('+3 weeks'), 60],
    ]);
    $season->save();
    $lessons = $this->loadSeasonLessons($season);
    $changed = $this->lessonStartingAt($lessons, $first->modify('+1 week'));
    self::assertNotNull($changed);
    self::assertSame($first->modify('+1 week')->modify('+2 hours')->getTimestamp(), $this->lessonEnd($changed));

    // The date of the recorded lesson cannot be removed.
    $this->setSchedule($season, [
      [$first->modify('+1 week'), 120],
      [$first->modify('+3 weeks'), 60],
    ]);
    $violations = $season->validate();
    self::assertGreaterThan(0, $violations->count());
    $message = (string) $violations->get(0)->getMessage();
    self::assertStringContainsString('cannot be removed', $message);
    self::assertStringContainsString($first->format('d.m.Y'), $message);

    // An API save bypasses validation and keeps the recorded lesson.
    $season->save();
    self::assertInstanceOf(LessonInterface::class, $this->lessonStorage->load($past_id));
    self::assertCount(3, $this->loadSeasonLessons($season));
  }

  /**
   * Replaces the dates of a season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   * @param array<int, array{0: \DateTimeImmutable, 1: int}> $dates
   *   The start and the duration in minutes of every date.
   */
  private function setSchedule(SeasonInterface $season, array $dates): void {
    $deltas = [];
    foreach ($dates as [$start, $duration]) {
      $deltas[] = $this->smartDateRange($start, $start->modify('+' . $duration . ' minutes'));
    }
    $season->set('field_schedule', $deltas);
  }

  /**
   * Finds the lesson that starts at a date.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface[] $lessons
   *   The lessons.
   * @param \DateTimeImmutable $date
   *   The start of the lesson.
   *
   * @return \Drupal\workspace_events\Entity\LessonInterface|null
   *   The lesson, or NULL when no lesson starts at the date.
   */
  private function lessonStartingAt(array $lessons, \DateTimeImmutable $date): ?LessonInterface {
    foreach ($lessons as $lesson) {
      if ($this->lessonStart($lesson)->getTimestamp() === $date->getTimestamp()) {
        return $lesson;
      }
    }
    return NULL;
  }

  /**
   * Returns the start of a lesson in the site timezone.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \DateTimeImmutable
   *   The start.
   */
  private function lessonStart(LessonInterface $lesson): \DateTimeImmutable {
    $item = $lesson->get('field_lesson_date')->first();
    self::assertNotNull($item);
    return (new \DateTimeImmutable(
      $item->get('value')->getString(),
      new \DateTimeZone('UTC'),
    ))->setTimezone(new \DateTimeZone(self::TIMEZONE));
  }

  /**
   * Returns the end of a lesson in the site timezone.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return int
   *   The end timestamp.
   */
  private function lessonEnd(LessonInterface $lesson): int {
    $item = $lesson->get('field_lesson_date')->first();
    self::assertNotNull($item);
    return (new \DateTimeImmutable(
      $item->get('end_value')->getString(),
      new \DateTimeZone('UTC'),
    ))->getTimestamp();
  }

}
