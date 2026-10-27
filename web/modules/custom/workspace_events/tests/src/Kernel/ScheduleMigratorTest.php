<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\NodeInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
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
final class ScheduleMigratorTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...EventKernelTestBase::MODULES,
    'smart_date',
    'smart_date_recur',
  ];

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

    $this->createLegacyScheduleField();

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
    $utc = new \DateTimeZone('UTC');
    self::assertSame($start->setTimezone($utc)->format('Y-m-d\TH:i:s'), $first->get('value')->getString());
    self::assertSame($end->setTimezone($utc)->format('Y-m-d\TH:i:s'), $first->get('end_value')->getString());
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
        return (new \DateTimeImmutable(
          $item->get('value')->getString(),
          new \DateTimeZone('UTC'),
        ))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
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
   * Tests that the migrator does nothing without the legacy field.
   */
  public function testDoesNothingWithoutLegacyField(): void {
    FieldConfig::load('node.course.field_when')?->delete();
    FieldConfig::load('node.event.field_when')?->delete();
    FieldStorageConfig::loadByName('node', 'field_when')?->delete();

    self::assertSame('', $this->migrator->migrate());
  }

  /**
   * Creates the legacy Smart Date field on courses and events.
   */
  private function createLegacyScheduleField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'node',
      'type' => 'smartdate',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    ])->save();
    foreach (['event' => 'Datum & Uhrzeit', 'course' => 'Wann'] as $bundle => $label) {
      $when = FieldConfig::create([
        'field_name' => 'field_when',
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $label,
        'required' => TRUE,
        'field_storage' => FieldStorageConfig::loadByName('node', 'field_when'),
      ]);
      $when->setThirdPartySetting('smart_date_recur', 'allow_recurring', TRUE);
      $when->setThirdPartySetting('smart_date_recur', 'month_limit', 12);
      $when->save();
    }
  }

  /**
   * Creates a recurrence rule and returns its materialized date deltas.
   *
   * @param \DateTimeImmutable $start
   *   The first occurrence start.
   * @param \DateTimeImmutable $end
   *   The first occurrence end.
   * @param string $frequency
   *   The recurrence frequency.
   * @param string $parameters
   *   The recurrence parameters.
   * @param string $limit
   *   The recurrence limit, for example COUNT=4. Empty for unlimited.
   * @param string $bundle
   *   The node bundle the rule belongs to.
   *
   * @return array<int, array<string, mixed>>
   *   The date deltas.
   */
  private function recurringDeltas(\DateTimeImmutable $start, \DateTimeImmutable $end, string $frequency, string $parameters, string $limit = '', string $bundle = 'event'): array {
    $rule = SmartDateRule::create([
      'freq' => $frequency,
      'limit' => $limit,
      'unlimited' => $limit === '',
      'entity_type' => 'node',
      'bundle' => $bundle,
      'field_name' => 'field_when',
      'start' => $start->getTimestamp(),
      'end' => $end->getTimestamp(),
      'parameters' => $parameters,
    ]);
    $rule->setTimezone(self::TIMEZONE);
    $instances = $rule->getRuleInstances(strtotime('+12 months'));
    self::assertIsArray($instances);
    $rule->set('instances', ['data' => $instances]);
    $rule->save();

    $deltas = [];
    foreach ($rule->getStoredInstances() as $index => $instance) {
      $deltas[] = [
        'value' => $instance['value'],
        'end_value' => $instance['end_value'],
        'duration' => (int) (($instance['end_value'] - $instance['value']) / 60),
        'timezone' => self::TIMEZONE,
        'rrule' => $rule->id(),
        'rrule_index' => $index,
      ];
    }
    return $deltas;
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
    return (new \DateTimeImmutable($item->get('value')->getString(), new \DateTimeZone('UTC')))->getTimestamp();
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
    return (new \DateTimeImmutable($item->get('end_value')->getString(), new \DateTimeZone('UTC')))->getTimestamp();
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
    return $this->loadCourseLessons($course);
  }

}
