<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Attendance\PreviousAttendance;
use Drupal\workspace_events\LessonCourseResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the recommendation by the earlier lessons of a course.
 */
#[CoversClass(PreviousAttendance::class)]
#[Group('workspace_events')]
final class PreviousAttendanceTest extends LessonKernelTestBase {

  /**
   * Tests the recommendation of the candidates from the earlier lessons.
   */
  public function testRecommendationFollowsTheEarlierLessons(): void {
    $alice = $this->createUser([], 'Alice Mitglied');
    $bob = $this->createUser([], 'Bob Mitglied');
    $alice_id = (int) $alice->id();
    $bob_id = (int) $bob->id();
    $candidates = [$alice_id, $bob_id];

    $timezone = new \DateTimeZone(self::TIMEZONE);
    $first = new \DateTimeImmutable('2026-09-07 18:00', $timezone);
    $lessons = [];
    for ($week = 0; $week < 5; $week++) {
      $start = $first->modify('+' . $week . ' weeks');
      $lessons[] = $this->createLesson([
        'title' => 'Stunde ' . ($week + 1),
        'field_lesson_date' => $this->dateRange($start, $start->modify('+1 hour')),
      ]);
    }
    $current = $this->createLesson([
      'title' => 'Aktuelle Stunde',
      'field_lesson_date' => $this->dateRange(
        $first->modify('+5 weeks'),
        $first->modify('+5 weeks')->modify('+1 hour'),
      ),
    ]);
    $this->createCourse([
      'title' => 'Salsa Kurs',
      'field_lessons' => array_map(
        static fn (NodeInterface $lesson): array => ['target_id' => $lesson->id()],
        [...$lessons, $current],
      ),
    ]);

    // Only Bob attended the two most recent earlier lessons: neither candidate
    // attends regularly, and the most recent lesson singles out one of them.
    $this->setParticipants($lessons[3], [$bob_id]);
    $this->setParticipants($lessons[4], [$bob_id]);
    self::assertSame([$bob_id], $this->recommender()->recommendAll($current, $candidates));

    // Both candidates attended the middle lesson, so no lesson singles one of
    // them out, and neither attends half of the last five lessons.
    $this->setParticipants($lessons[3], $candidates);
    $this->setParticipants($lessons[4], []);
    self::assertSame([], $this->recommender()->recommendAll($current, $candidates));

    // Both candidates attended four of the last five lessons: both are
    // recommended, so the editor can confirm both "Lena"s at once.
    $this->setParticipants($lessons[0], $candidates);
    $this->setParticipants($lessons[1], $candidates);
    $this->setParticipants($lessons[2], $candidates);
    self::assertSame([$alice_id, $bob_id], $this->recommender()->recommendAll($current, $candidates));

    // A lesson without a course and an unsaved lesson have no earlier lessons.
    $without_course = $this->createLesson([
      'title' => 'Ohne Kurs',
      'field_lesson_date' => $this->dateRange($first, $first->modify('+1 hour')),
    ]);
    self::assertSame([], $this->recommender()->recommendAll($without_course, $candidates));

    $unsaved = Node::create([
      'type' => 'lesson',
      'title' => 'Noch nicht gespeichert',
      'field_lesson_date' => $this->dateRange($first, $first->modify('+1 hour')),
    ]);
    self::assertSame([], $this->recommender()->recommendAll($unsaved, $candidates));
  }

  /**
   * Sets the participants of a lesson.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   * @param int[] $participants
   *   The participant user IDs.
   */
  private function setParticipants(NodeInterface $lesson, array $participants): void {
    $lesson->set('field_participants', $participants);
    $lesson->save();
  }

  /**
   * Returns a fresh recommender, because it memoizes per request.
   *
   * @return \Drupal\workspace_events\Attendance\PreviousAttendance
   *   The recommender.
   */
  private function recommender(): PreviousAttendance {
    return new PreviousAttendance(
      $this->container->get('entity_type.manager'),
      $this->container->get(LessonCourseResolver::class),
    );
  }

}
