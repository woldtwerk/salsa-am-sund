<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\NodeInterface;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installLessonConfig();
  }

  /**
   * Tests the weekly schedule and the last date being inclusive.
   */
  public function testWeeklyLessons(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $last = $first->modify('+2 weeks');

    $course = $this->createCourseSchedule($first, $last);

    $lessons = $this->loadReferencedLessons($course);
    self::assertCount(3, $lessons);
    self::assertSame('Salsa Kurs – ' . $first->format('d.m.Y'), $lessons[0]->label());
    self::assertSame('Salsa Kurs – ' . $last->format('d.m.Y'), $lessons[2]->label());

    foreach ($lessons as $lesson) {
      $start = $this->lessonStart($lesson);
      self::assertSame('18:00', $start->format('H:i'));
      self::assertFalse($lesson->isPublished());
      self::assertSame($this->lessonEnd($lesson), (int) $lesson->get('publish_on')->getString());
    }
  }

  /**
   * Tests a course that takes place every two weeks.
   */
  public function testTwoWeekInterval(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));

    $course = $this->createCourseSchedule($first, $first->modify('+4 weeks'), 2);

    $lessons = $this->loadReferencedLessons($course);
    self::assertCount(3, $lessons);
    self::assertSame($first->format('Y-m-d'), $this->lessonStart($lessons[0])->format('Y-m-d'));
    self::assertSame($first->modify('+2 weeks')->format('Y-m-d'), $this->lessonStart($lessons[1])->format('Y-m-d'));
    self::assertSame($first->modify('+4 weeks')->format('Y-m-d'), $this->lessonStart($lessons[2])->format('Y-m-d'));
  }

  /**
   * Tests that past lessons are published immediately.
   */
  public function testPastLessonsArePublished(): void {
    $first = new \DateTimeImmutable('now -3 weeks 18:00', new \DateTimeZone(self::TIMEZONE));

    $course = $this->createCourseSchedule($first, $first->modify('+2 weeks'));

    $lessons = $this->loadReferencedLessons($course);
    self::assertCount(3, $lessons);
    foreach ($lessons as $lesson) {
      self::assertTrue($lesson->isPublished());
      self::assertTrue($lesson->get('publish_on')->isEmpty());
    }
  }

  /**
   * Tests that future lessons are scheduled to be published at their end.
   */
  public function testFutureLessonsGetPublishOn(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));

    $course = $this->createCourseSchedule($first, $first->modify('+1 week'));

    $lessons = $this->loadReferencedLessons($course);
    self::assertCount(2, $lessons);
    foreach ($lessons as $lesson) {
      self::assertFalse($lesson->isPublished());
      self::assertSame($this->lessonEnd($lesson), (int) $lesson->get('publish_on')->getString());
    }
  }

  /**
   * Tests that the local time stays the same across a daylight saving change.
   */
  public function testDaylightSavingChange(): void {
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $first = new \DateTimeImmutable('2027-03-22 18:00', $timezone);
    $last = new \DateTimeImmutable('2027-04-05', $timezone);

    $course = $this->createCourseSchedule($first, $last);

    $lessons = $this->loadReferencedLessons($course);
    self::assertCount(3, $lessons);

    $starts = array_map($this->lessonStart(...), $lessons);
    self::assertSame('18:00', $starts[0]->format('H:i'));
    self::assertSame('18:00', $starts[1]->format('H:i'));
    self::assertSame('18:00', $starts[2]->format('H:i'));
    // The week of the DST change is one hour shorter in UTC.
    self::assertSame(601200, $starts[1]->getTimestamp() - $starts[0]->getTimestamp());
  }

  /**
   * Tests that a second save does not create more lessons.
   */
  public function testSecondSaveDoesNotChangeTheLessons(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourseSchedule($first, $first->modify('+2 weeks'));
    $original_ids = $this->referencedLessonIds($course);

    $course->save();

    self::assertSame($original_ids, $this->referencedLessonIds($course));
    self::assertCount(3, $this->loadReferencedLessons($course));
  }

  /**
   * Tests that the lessons are recreated after all of them were deleted.
   */
  public function testGeneratesAgainAfterAllLessonsWereDeleted(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourseSchedule($first, $first->modify('+2 weeks'));
    $original_ids = $this->referencedLessonIds($course);

    $this->nodeStorage->delete($this->loadReferencedLessons($course));
    $course->save();

    $new_ids = $this->referencedLessonIds($course);
    self::assertCount(3, $new_ids);
    self::assertSame([], array_intersect($original_ids, $new_ids));
  }

  /**
   * Tests that references to deleted lessons are removed on save.
   */
  public function testRemovesReferencesToDeletedLessons(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourseSchedule($first, $first->modify('+2 weeks'));
    $lessons = $this->loadReferencedLessons($course);
    $deleted_id = (int) $lessons[1]->id();

    $this->nodeStorage->delete([$lessons[1]]);
    $course->save();

    $ids = $this->referencedLessonIds($course);
    self::assertCount(2, $ids);
    self::assertNotContains($deleted_id, $ids);
  }

  /**
   * Tests that deleting a course keeps lessons with recorded data.
   */
  public function testCourseDeletionKeepsRecordedLessons(): void {
    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourseSchedule($first, $first->modify('+1 week'));
    $lessons = $this->loadReferencedLessons($course);
    self::assertCount(2, $lessons);

    $lessons[0]->set('field_cancelled', TRUE);
    $lessons[0]->save();
    $recorded_id = (int) $lessons[0]->id();

    $course->delete();

    self::assertNull($this->nodeStorage->load((int) $lessons[1]->id()));
    $kept = $this->nodeStorage->load($recorded_id);
    self::assertInstanceOf(NodeInterface::class, $kept);
  }

  /**
   * Tests that the scheduler publishes an overdue lesson on cron.
   */
  public function testSchedulerPublishesOverdueLessons(): void {
    $date = new \DateTimeImmutable('now -2 hours', new \DateTimeZone(self::TIMEZONE));
    $lesson = $this->createLesson([
      'title' => 'Nachzügler',
      'status' => NodeInterface::NOT_PUBLISHED,
      'publish_on' => $this->container->get('datetime.time')->getRequestTime() - 60,
      'field_lesson_date' => [
        'value' => $date->format('Y-m-d\TH:i:s'),
        'end_value' => $date->modify('+1 hour')->format('Y-m-d\TH:i:s'),
      ],
    ]);

    $this->container->get('scheduler.manager')->publish();

    $lesson = $this->nodeStorage->load((int) $lesson->id());
    self::assertInstanceOf(NodeInterface::class, $lesson);
    self::assertTrue($lesson->isPublished());
    self::assertTrue($lesson->get('publish_on')->isEmpty());
  }

  /**
   * Creates a course with a schedule.
   *
   * @param \DateTimeImmutable $first
   *   The start of the first lesson.
   * @param \DateTimeImmutable $last
   *   The date of the last lesson.
   * @param int $interval
   *   The interval in weeks.
   *
   * @return \Drupal\node\NodeInterface
   *   The course.
   */
  private function createCourseSchedule(\DateTimeImmutable $first, \DateTimeImmutable $last, int $interval = 1): NodeInterface {
    return $this->createCourse([
      'title' => 'Salsa Kurs',
      'field_course_first' => [
        'value' => $first->format('Y-m-d\TH:i:s'),
        'end_value' => $first->modify('+1 hour')->format('Y-m-d\TH:i:s'),
      ],
      'field_course_last' => ['value' => $last->format('Y-m-d')],
      'field_course_interval' => ['value' => $interval],
    ]);
  }

  /**
   * Returns the referenced lesson IDs of a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return int[]
   *   The lesson IDs, in the stored order.
   */
  private function referencedLessonIds(NodeInterface $course): array {
    $ids = [];
    foreach ($course->get('field_lessons') as $item) {
      $ids[] = (int) $item->get('target_id')->getString();
    }
    return $ids;
  }

  /**
   * Loads the referenced lessons of a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The lessons, in the stored order.
   */
  private function loadReferencedLessons(NodeInterface $course): array {
    $lessons = [];
    $ids = $this->referencedLessonIds($course);
    $this->nodeStorage->resetCache($ids);
    foreach ($ids as $id) {
      $lesson = $this->nodeStorage->load($id);
      self::assertInstanceOf(NodeInterface::class, $lesson);
      $lessons[] = $lesson;
    }
    return $lessons;
  }

  /**
   * Returns the start of a lesson in the site timezone.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   *
   * @return \DateTimeImmutable
   *   The start.
   */
  private function lessonStart(NodeInterface $lesson): \DateTimeImmutable {
    $item = $lesson->get('field_lesson_date')->first();
    self::assertNotNull($item);
    return new \DateTimeImmutable($item->get('value')->getString(), new \DateTimeZone(self::TIMEZONE));
  }

  /**
   * Returns the end timestamp of a lesson.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   *
   * @return int
   *   The timestamp at which the lesson ends.
   */
  private function lessonEnd(NodeInterface $lesson): int {
    $item = $lesson->get('field_lesson_date')->first();
    self::assertNotNull($item);
    return (new \DateTimeImmutable($item->get('end_value')->getString(), new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
  }

}
