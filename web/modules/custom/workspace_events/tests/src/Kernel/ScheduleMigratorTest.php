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
final class ScheduleMigratorTest extends LessonKernelTestBase {

  /**
   * The modules the legacy schedule test needs.
   *
   * @var string[]
   */
  protected static $modules = [
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

    $this->installEntitySchema('smart_date_rule');
    $this->installEntitySchema('smart_date_override');
    $this->installConfig(['smart_date', 'smart_date_recur']);

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

    self::assertSame('', $this->migrator->migrate());

    $course_id = (int) $course->id();
    $utc = new \DateTimeZone('UTC');
    self::assertSame($start->setTimezone($utc)->format('Y-m-d\TH:i:s'), $this->fieldColumn('field_course_first', $course_id));
    self::assertSame($end->setTimezone($utc)->format('Y-m-d\TH:i:s'), $this->fieldColumn('field_course_first', $course_id, 'end_value'));
    self::assertSame($start->modify('+6 weeks')->format('Y-m-d'), $this->fieldColumn('field_course_last', $course_id));
    self::assertSame('2', $this->fieldColumn('field_course_interval', $course_id));

    $lessons = $this->loadLessons($course_id);
    self::assertCount(4, $lessons);
    self::assertSame([
      $start->format('Y-m-d'),
      $start->modify('+2 weeks')->format('Y-m-d'),
      $start->modify('+4 weeks')->format('Y-m-d'),
      $start->modify('+6 weeks')->format('Y-m-d'),
    ], array_map(
      function (NodeInterface $lesson): string {
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
   * Tests the upcoming and the last past date of event series.
   */
  public function testMigratesEvents(): void {
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $upcoming_start = (new \DateTimeImmutable('now', $timezone))
      ->modify('first day of -2 months')
      ->setTime(19, 0);
    $upcoming_deltas = $this->recurringDeltas(
      $upcoming_start,
      $upcoming_start->modify('+3 hours'),
      'MONTHLY',
      'COUNT=4',
    );
    $upcoming = $this->createEvent([
      'title' => 'Monatliche Party',
      'field_when' => $upcoming_deltas,
    ]);

    $past_start = (new \DateTimeImmutable('now', $timezone))
      ->modify('first day of -4 months')
      ->setTime(19, 0);
    $past_deltas = $this->recurringDeltas(
      $past_start,
      $past_start->modify('+3 hours'),
      'MONTHLY',
      'COUNT=3',
    );
    $past = $this->createEvent([
      'title' => 'Vergangene Party',
      'field_when' => $past_deltas,
    ]);

    $message = $this->migrator->migrate();
    self::assertStringContainsString('Monatliche Party', $message);
    self::assertStringContainsString('4 dates collapsed', $message);
    self::assertStringContainsString('Vergangene Party', $message);

    $expected = $this->firstUpcomingDelta($upcoming_deltas);
    self::assertNotNull($expected);
    self::assertSame($expected['value'], $this->fieldTimestamp('field_event_date', (int) $upcoming->id()));
    self::assertSame($expected['end_value'], $this->fieldTimestamp('field_event_date', (int) $upcoming->id(), 'end_value'));

    $expected = $past_deltas[count($past_deltas) - 1];
    self::assertSame($expected['value'], $this->fieldTimestamp('field_event_date', (int) $past->id()));
    self::assertSame($expected['end_value'], $this->fieldTimestamp('field_event_date', (int) $past->id(), 'end_value'));
  }

  /**
   * Creates the legacy Smart Date field on courses and events.
   */
  private function createLegacyScheduleField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'node',
      'type' => 'smartdate',
      'module' => 'smart_date',
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
   * Loads the lessons of a course from their stored references.
   *
   * @param int $course_id
   *   The course node ID.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The lessons, in the stored order.
   */
  private function loadLessons(int $course_id): array {
    $ids = $this->container->get('database')->select('node__field_lessons', 't')
      ->fields('t', ['field_lessons_target_id'])
      ->condition('entity_id', $course_id)
      ->orderBy('delta')
      ->execute()
      ->fetchCol();
    $ids = array_map(static fn (mixed $id): int => (int) $id, $ids);
    $this->nodeStorage->resetCache($ids);
    $lessons = [];
    foreach ($ids as $id) {
      $lesson = $this->nodeStorage->load($id);
      self::assertInstanceOf(NodeInterface::class, $lesson);
      $lessons[] = $lesson;
    }
    return $lessons;
  }

  /**
   * Returns a stored date field as a timestamp.
   *
   * @param string $field_name
   *   The field name.
   * @param int $entity_id
   *   The node ID.
   * @param string $property
   *   The field property.
   *
   * @return int
   *   The stored timestamp.
   */
  private function fieldTimestamp(string $field_name, int $entity_id, string $property = 'value'): int {
    $value = $this->fieldColumn($field_name, $entity_id, $property);
    self::assertNotNull($value);
    return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
  }

  /**
   * Returns a stored field property of a node.
   *
   * @param string $field_name
   *   The field name.
   * @param int $entity_id
   *   The node ID.
   * @param string $property
   *   The field property.
   *
   * @return string|null
   *   The stored value.
   */
  private function fieldColumn(string $field_name, int $entity_id, string $property = 'value'): ?string {
    $value = $this->container->get('database')->select('node__' . $field_name, 't')
      ->fields('t', [$field_name . '_' . $property])
      ->condition('entity_id', $entity_id)
      ->condition('delta', 0)
      ->execute()
      ->fetchField();
    return $value === FALSE ? NULL : (string) $value;
  }

}
