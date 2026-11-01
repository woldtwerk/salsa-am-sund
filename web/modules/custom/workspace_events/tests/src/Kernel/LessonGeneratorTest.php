<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\NodeInterface;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\LessonGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the lesson generator.
 */
#[CoversClass(LessonGenerator::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LessonGeneratorTest extends LessonKernelTestBase {

  /**
   * Tests generating and regenerating the lessons of a season.
   */
  public function testGenerateLessonsOnSeasonSave(): void {
    $first = new \DateTimeImmutable('now -2 weeks 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourseSchedule($first, $first->modify('+4 weeks'));
    $season = $this->loadSeason($course);
    $lessons = $this->loadCourseLessons($course);

    self::assertCount(5, $lessons);
    self::assertSame('Salsa Kurs – ' . $first->format('d.m.Y'), $lessons[0]->label());
    self::assertSame('Salsa Kurs – ' . $first->modify('+4 weeks')->format('d.m.Y'), $lessons[4]->label());
    self::assertSame((int) $season->id(), (int) $lessons[0]->getSeason()?->id());

    $starts = array_map($this->lessonStart(...), $lessons);
    self::assertSame(
      [
        $first->format('Y-m-d'),
        $first->modify('+1 week')->format('Y-m-d'),
        $first->modify('+2 weeks')->format('Y-m-d'),
        $first->modify('+3 weeks')->format('Y-m-d'),
        $first->modify('+4 weeks')->format('Y-m-d'),
      ],
      array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $starts),
    );

    $now = $this->container->get('datetime.time')->getRequestTime();
    foreach ($lessons as $lesson) {
      $end = $this->lessonEnd($lesson);
      self::assertSame($end <= $now, $lesson->isPublished());
      if ($end <= $now) {
        self::assertTrue($lesson->get('publish_on')->isEmpty());
      }
      else {
        self::assertSame($end, (int) $lesson->get('publish_on')->getString());
      }
    }

    // Saving the season without a schedule change keeps the lessons.
    $original_ids = $this->lessonIds($lessons);
    $season->save();
    self::assertSame($original_ids, $this->lessonIds($this->loadCourseLessons($course)));

    // Changing the dates replaces the lessons of the season.
    $season->set('field_schedule', $this->dateRange($first->modify('+1 day'), $first->modify('+1 day')->modify('+1 hour')));
    $season->set('last', ['value' => $first->modify('+4 weeks')->modify('+1 day')->format('Y-m-d')]);
    $season->save();

    $new_lessons = $this->loadCourseLessons($course);
    self::assertCount(5, $new_lessons);
    self::assertSame([], array_intersect($original_ids, $this->lessonIds($new_lessons)));
    self::assertSame($first->modify('+1 day')->format('Y-m-d'), $this->lessonStart($new_lessons[0])->format('Y-m-d'));
  }

  /**
   * Tests that deleting a course keeps lessons with recorded data.
   */
  public function testCourseDeletionKeepsRecordedLessons(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourseSchedule($first, $first->modify('+1 week'));
    $lessons = $this->loadCourseLessons($course);
    self::assertCount(2, $lessons);

    $lessons[0]->set('field_cancelled', TRUE);
    $lessons[0]->save();
    $recorded_id = (int) $lessons[0]->id();

    $course->delete();

    self::assertNull($this->lessonStorage->load((int) $lessons[1]->id()));
    self::assertInstanceOf(LessonInterface::class, $this->lessonStorage->load($recorded_id));
  }

  /**
   * Tests that Scheduler publishes a lesson on cron.
   *
   * This fails when the Scheduler plugin, its event class or the no-bundle
   * settings are missing.
   */
  public function testSchedulerPublishesFutureLesson(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourseSchedule($first, $first);
    $lessons = $this->loadCourseLessons($course);
    self::assertCount(1, $lessons);

    $lesson = $lessons[0];
    self::assertFalse($lesson->isPublished());
    self::assertNotSame('', $lesson->get('publish_on')->getString());

    // Move the publication date into the past, as the next cron run would.
    $lesson->set('publish_on', $this->container->get('datetime.time')->getRequestTime() - 60);
    $lesson->save();

    \Drupal::service('scheduler.manager')->publish();

    $this->lessonStorage->resetCache([(int) $lesson->id()]);
    $published = $this->lessonStorage->load((int) $lesson->id());
    self::assertInstanceOf(LessonInterface::class, $published);
    self::assertTrue($published->isPublished());
  }

  /**
   * Creates a course with a weekly schedule.
   *
   * @param \DateTimeImmutable $first
   *   The start of the first lesson.
   * @param \DateTimeImmutable $last
   *   The date of the last lesson.
   *
   * @return \Drupal\node\NodeInterface
   *   The course.
   */
  private function createCourseSchedule(\DateTimeImmutable $first, \DateTimeImmutable $last): NodeInterface {
    return $this->createCourse([
      'title' => 'Salsa Kurs',
      ...$this->courseSchedule($first, $last),
    ]);
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
   * Returns the end timestamp of a lesson.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return int
   *   The timestamp at which the lesson ends.
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
