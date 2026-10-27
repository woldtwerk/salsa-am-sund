<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\NodeInterface;
use Drupal\workspace_events\ScheduleMigrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the migration of the Smart Date series.
 */
#[CoversClass(ScheduleMigrator::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class ScheduleMigratorTest extends LessonKernelTestBase {

  /**
   * The migrator.
   */
  private ScheduleMigrator $migrator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installLessonConfig();

    $migrator = $this->container->get(ScheduleMigrator::class);
    \assert($migrator instanceof ScheduleMigrator);
    $this->migrator = $migrator;
  }

  /**
   * Tests the migration of a course series and the generated lessons.
   */
  public function testMigratesCourseWithRule(): void {
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $start = new \DateTimeImmutable('2026-11-02 18:00', $timezone);
    $end = $start->modify('+1 hour');
    $deltas = $this->recurringDeltas($start, $end, 'WEEKLY', 'INTERVAL=2;BYDAY=MO', 'COUNT=4', 'course');

    $course = $this->createCourse([
      'title' => 'Zweiwöchentlich',
      'field_when' => $deltas,
    ]);

    $message = $this->migrator->migrate();
    self::assertSame('', $message);

    $course = $this->nodeStorage->load((int) $course->id());
    self::assertInstanceOf(NodeInterface::class, $course);

    $first = $course->get('field_course_first')->first();
    self::assertNotNull($first);
    self::assertSame($start->format('Y-m-d\TH:i:s'), $first->get('value')->getString());
    self::assertSame($end->format('Y-m-d\TH:i:s'), $first->get('end_value')->getString());
    self::assertSame($start->modify('+6 weeks')->format('Y-m-d'), $course->get('field_course_last')->getString());
    self::assertSame('2', $course->get('field_course_interval')->getString());

    $lessons = $this->loadReferencedLessons($course);
    self::assertCount(4, $lessons);
    $expected_dates = [
      $start->format('Y-m-d'),
      $start->modify('+2 weeks')->format('Y-m-d'),
      $start->modify('+4 weeks')->format('Y-m-d'),
      $start->modify('+6 weeks')->format('Y-m-d'),
    ];
    self::assertSame($expected_dates, array_map(
      static function (NodeInterface $lesson): string {
        $item = $lesson->get('field_lesson_date')->first();
        self::assertNotNull($item);
        return substr($item->get('value')->getString(), 0, 10);
      },
      $lessons,
    ));
  }

  /**
   * Tests that an event keeps its next upcoming date.
   */
  public function testMigratesEventToNextUpcomingDate(): void {
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $start = (new \DateTimeImmutable('now', $timezone))
      ->modify('first day of -2 months')
      ->setTime(19, 0);
    $deltas = $this->recurringDeltas($start, $start->modify('+3 hours'), 'MONTHLY', 'COUNT=4');

    $event = $this->createEvent([
      'title' => 'Monatliche Party',
      'field_when' => $deltas,
    ]);

    $message = $this->migrator->migrate();

    self::assertStringContainsString('Monatliche Party', $message);
    self::assertStringContainsString('4 dates collapsed', $message);

    $event = $this->nodeStorage->load((int) $event->id());
    self::assertInstanceOf(NodeInterface::class, $event);
    $expected = $this->firstUpcomingDelta($deltas);
    self::assertNotNull($expected);
    self::assertSame($expected['value'], $this->fieldTimestamp($event, 'field_event_date'));
    self::assertSame($expected['end_value'], $this->fieldEndTimestamp($event, 'field_event_date'));
    self::assertFalse($event->get('field_when')->isEmpty());
  }

  /**
   * Tests that an event without a future date keeps its last past date.
   */
  public function testMigratesPastEventToLastDate(): void {
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $start = (new \DateTimeImmutable('now', $timezone))
      ->modify('first day of -4 months')
      ->setTime(19, 0);
    $deltas = $this->recurringDeltas($start, $start->modify('+3 hours'), 'MONTHLY', 'COUNT=3');

    $event = $this->createEvent([
      'title' => 'Vergangene Party',
      'field_when' => $deltas,
    ]);

    $message = $this->migrator->migrate();

    self::assertStringContainsString('Vergangene Party', $message);

    $event = $this->nodeStorage->load((int) $event->id());
    self::assertInstanceOf(NodeInterface::class, $event);
    $expected = $deltas[count($deltas) - 1];
    self::assertSame($expected['value'], $this->fieldTimestamp($event, 'field_event_date'));
    self::assertSame($expected['end_value'], $this->fieldEndTimestamp($event, 'field_event_date'));
  }

  /**
   * Returns the first date of a series that is not in the past.
   *
   * @param array<int, array<string, mixed>> $deltas
   *   The date deltas.
   *
   * @return array<string, mixed>|null
   *   The first upcoming delta, or NULL when every date is in the past.
   */
  private function firstUpcomingDelta(array $deltas): ?array {
    $now = time();
    foreach ($deltas as $delta) {
      if ((int) $delta['value'] >= $now) {
        return $delta;
      }
    }
    return NULL;
  }

  /**
   * Returns the start timestamp of a date range field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The timestamp.
   */
  private function fieldTimestamp(NodeInterface $node, string $field_name): int {
    $item = $node->get($field_name)->first();
    self::assertNotNull($item);
    return (new \DateTimeImmutable($item->get('value')->getString(), new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
  }

  /**
   * Returns the end timestamp of a date range field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The timestamp.
   */
  private function fieldEndTimestamp(NodeInterface $node, string $field_name): int {
    $item = $node->get($field_name)->first();
    self::assertNotNull($item);
    return (new \DateTimeImmutable($item->get('end_value')->getString(), new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
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
    foreach ($course->get('field_lessons') as $item) {
      $lesson = $this->nodeStorage->load((int) $item->get('target_id')->getString());
      self::assertInstanceOf(NodeInterface::class, $lesson);
      $lessons[] = $lesson;
    }
    return $lessons;
  }

}
