<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\Season;
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
   * Tests that a season generates and regenerates its lessons.
   */
  public function testSeasonGeneratesAndRegeneratesLessons(): void {
    $location = $this->createLocation('Bootshaus');
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourse([
      'title' => 'Salsa',
      'season_location' => $location->id(),
      ...$this->courseSchedule($first, $first->modify('+2 weeks')),
    ]);
    $season = $this->loadSeason($course);

    $lessons = $this->loadSeasonLessons($season);
    self::assertCount(3, $lessons);
    self::assertSame($first->format('Y-m-d'), $this->lessonStart($lessons[0])->format('Y-m-d'));
    self::assertSame((int) $season->id(), (int) $lessons[0]->getSeason()?->id());

    $original_ids = $this->lessonIds($lessons);
    $season->set('field_schedule', $this->dateRange($first->modify('+1 week'), $first->modify('+1 week')->modify('+1 hour')));
    $season->set('last', ['value' => $first->modify('+3 weeks')->format('Y-m-d')]);
    $season->save();

    $regenerated = $this->loadSeasonLessons($season);
    self::assertCount(3, $regenerated);
    self::assertSame([], array_intersect($original_ids, $this->lessonIds($regenerated)));
    self::assertSame($first->modify('+1 week')->format('Y-m-d'), $this->lessonStart($regenerated[0])->format('Y-m-d'));
  }

  /**
   * Tests that recorded lesson data locks the schedule and the deletions.
   */
  public function testRecordedLessonsLockScheduleAndDeletion(): void {
    $location = $this->createLocation('Bootshaus');
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourse([
      'title' => 'Salsa',
      'season_location' => $location->id(),
      ...$this->courseSchedule($first, $first->modify('+1 week')),
    ]);
    $season = $this->loadSeason($course);
    $lessons = $this->loadSeasonLessons($season);
    self::assertCount(2, $lessons);

    $participant = $this->createUser(['access content']);
    $lessons[0]->set('field_participants', [['target_id' => $participant->id()]]);
    $lessons[0]->save();
    $recorded_ids = $this->lessonIds($this->loadSeasonLessons($season));

    $season->set('field_schedule', $this->dateRange($first->modify('+1 week'), $first->modify('+1 week')->modify('+1 hour')));
    $season->set('last', ['value' => $first->modify('+2 weeks')->format('Y-m-d')]);
    $violations = $season->validate();
    self::assertGreaterThan(0, $violations->count());
    self::assertStringContainsString("can't be changed", (string) $violations->get(0)->getMessage());

    // The failed save leaves the lessons untouched.
    self::assertSame($recorded_ids, $this->lessonIds($this->loadSeasonLessons($season)));

    $editor = $this->createUser([
      'access content',
      'delete course seasons',
      'delete any course content',
    ]);
    self::assertFalse($season->access('delete', $editor));
    self::assertFalse($course->access('delete', $editor));

    // A season without recorded lessons deletes its lessons.
    $second = $this->createSeason($course, [
      'field_schedule' => $this->dateRange($first->modify('+3 weeks'), $first->modify('+3 weeks')->modify('+1 hour')),
      'last' => ['value' => $first->modify('+4 weeks')->format('Y-m-d')],
      'location' => $location->id(),
    ]);
    $second_lessons = $this->loadSeasonLessons($second);
    self::assertCount(2, $second_lessons);
    $second_ids = $this->lessonIds($second_lessons);

    $second->delete();

    foreach ($second_ids as $lesson_id) {
      self::assertNull($this->lessonStorage->load($lesson_id));
    }
    // The recorded lesson of the first season survives.
    self::assertSame($recorded_ids[0], (int) $this->lessonStorage->load($recorded_ids[0])?->id());
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
   * Returns the IDs of lessons.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface[] $lessons
   *   The lessons.
   *
   * @return int[]
   *   The lesson IDs.
   */
  private function lessonIds(array $lessons): array {
    return array_map(static fn (LessonInterface $lesson): int => (int) $lesson->id(), $lessons);
  }

}
