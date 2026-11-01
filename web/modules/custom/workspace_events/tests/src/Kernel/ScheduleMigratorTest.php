<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\workspace_events\Entity\LessonInterface;
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

    $this->createLegacyScheduleField();
    if (FieldConfig::loadByName('node', 'course', 'field_location') === NULL) {
      FieldConfig::create([
        'field_name' => 'field_location',
        'entity_type' => 'node',
        'bundle' => 'course',
        'label' => 'Ort',
        'field_storage' => FieldStorageConfig::loadByName('node', 'field_location'),
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => ['location' => 'location'],
            'sort' => ['field' => 'name', 'direction' => 'ASC'],
            'auto_create' => FALSE,
          ],
        ],
      ])->save();
    }

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
    $location = $this->createLocation('Bootshaus Stralsund');

    $course = $this->createCourse([
      'title' => 'Zweiwöchentlich',
      'field_when' => $deltas,
    ]);
    $course->set('field_location', $location->id());
    $course->save();

    self::assertSame('', $this->migrator->migrate());

    $season = $this->loadSeason($course);
    $schedule = $season->get('field_schedule')->first();
    self::assertNotNull($schedule);
    $utc = new \DateTimeZone('UTC');
    self::assertSame($start->setTimezone($utc)->format('Y-m-d\TH:i:s'), $schedule->get('value')->getString());
    self::assertSame($end->setTimezone($utc)->format('Y-m-d\TH:i:s'), $schedule->get('end_value')->getString());
    self::assertSame($start->modify('+6 weeks')->format('Y-m-d'), $season->get('last')->getString());
    self::assertSame('2', $season->get('interval')->getString());
    self::assertSame($location->id(), $season->get('location')->target_id);
    self::assertSame(1, (int) $season->getOwnerId());

    $lessons = $this->loadCourseLessons($course);
    self::assertCount(4, $lessons);
    self::assertSame([
      $start->format('Y-m-d'),
      $start->modify('+2 weeks')->format('Y-m-d'),
      $start->modify('+4 weeks')->format('Y-m-d'),
      $start->modify('+6 weeks')->format('Y-m-d'),
    ], array_map(
      function (LessonInterface $lesson): string {
        return $this->lessonDate($lesson)->format('Y-m-d');
      },
      $lessons,
    ));

    // A second run creates neither a season nor lessons.
    $lesson_ids = array_map(static fn (LessonInterface $lesson): int => (int) $lesson->id(), $lessons);
    self::assertSame('', $this->migrator->migrate());
    self::assertSame((int) $season->id(), (int) $this->loadSeason($course)->id());
    self::assertSame($lesson_ids, array_map(
      static fn (LessonInterface $lesson): int => (int) $lesson->id(),
      $this->loadCourseLessons($course),
    ));
  }

  /**
   * Tests that a recurring event keeps every delta and its rules.
   */
  public function testMigratesEvents(): void {
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $second_friday = new \DateTimeImmutable('second friday of january 2027 20:00', $timezone);
    $fourth_friday = new \DateTimeImmutable('fourth friday of january 2027 20:00', $timezone);
    $deltas = array_merge(
      $this->recurringDeltas($second_friday, $second_friday->modify('+2 hours'), 'MONTHLY', 'BYDAY=2FR', 'COUNT=3'),
      $this->recurringDeltas($fourth_friday, $fourth_friday->modify('+2 hours'), 'MONTHLY', 'BYDAY=4FR', 'COUNT=3'),
    );
    $event = $this->createEvent([
      'title' => 'Salsa im Ballhaus Goldfisch',
      'field_when' => $deltas,
    ]);

    $past_start = (new \DateTimeImmutable('first day of -4 months', $timezone))->setTime(19, 0);
    $past_deltas = [
      $this->smartDateRange($past_start, $past_start->modify('+3 hours')),
      $this->smartDateRange($past_start->modify('+1 month'), $past_start->modify('+1 month')->modify('+3 hours')),
    ];
    $past = $this->createEvent([
      'title' => 'Vergangene Party',
      'field_when' => $past_deltas,
    ]);

    self::assertSame('', $this->migrator->migrate());

    // Every delta is copied one to one, including rule and rule index.
    $event_id = (int) $event->id();
    $copied = $this->fieldRows('field_event_date', $event_id);
    self::assertCount(count($deltas), $copied);
    foreach (array_values($deltas) as $delta => $expected) {
      self::assertSame((string) $expected['value'], $copied[$delta]['value']);
      self::assertSame((string) $expected['end_value'], $copied[$delta]['end_value']);
      self::assertSame((string) $expected['duration'], $copied[$delta]['duration']);
      self::assertSame((string) $expected['rrule'], $copied[$delta]['rrule']);
      self::assertSame((string) $expected['rrule_index'], $copied[$delta]['rrule_index']);
      self::assertSame($expected['timezone'], $copied[$delta]['timezone']);
    }

    // Both rules of the series now belong to the recurring event date field.
    $rules = $this->container->get('entity_type.manager')->getStorage('smart_date_rule')->loadMultiple();
    self::assertCount(2, $rules);
    foreach ($rules as $rule) {
      self::assertInstanceOf(SmartDateRule::class, $rule);
      self::assertSame('field_event_date', $rule->get('field_name')->getString());
    }

    // A series that already ended keeps its dates too.
    self::assertCount(count($past_deltas), $this->fieldRows('field_event_date', (int) $past->id()));
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
   * Returns the start of a lesson in the site timezone.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \DateTimeImmutable
   *   The start.
   */
  private function lessonDate(LessonInterface $lesson): \DateTimeImmutable {
    $item = $lesson->get('field_lesson_date')->first();
    self::assertNotNull($item);
    return (new \DateTimeImmutable(
      $item->get('value')->getString(),
      new \DateTimeZone('UTC'),
    ))->setTimezone(new \DateTimeZone(self::TIMEZONE));
  }

  /**
   * Returns all stored deltas of a Smart Date field as plain arrays.
   *
   * @param string $field_name
   *   The field name.
   * @param int $entity_id
   *   The node ID.
   *
   * @return array<int, array{value: string, end_value: string, duration: string, rrule: string, rrule_index: string, timezone: string}>
   *   The rows keyed by delta.
   */
  private function fieldRows(string $field_name, int $entity_id): array {
    $prefix = $field_name . '_';
    $rows = $this->container->get('database')->select('node__' . $field_name, 't')
      ->fields('t', [
        'delta',
        $prefix . 'value',
        $prefix . 'end_value',
        $prefix . 'duration',
        $prefix . 'rrule',
        $prefix . 'rrule_index',
        $prefix . 'timezone',
      ])
      ->condition('entity_id', $entity_id)
      ->orderBy('delta')
      ->execute()
      ->fetchAll();

    $result = [];
    foreach ($rows as $row) {
      $result[(int) $row->delta] = [
        'value' => (string) $row->{$prefix . 'value'},
        'end_value' => (string) $row->{$prefix . 'end_value'},
        'duration' => (string) $row->{$prefix . 'duration'},
        'rrule' => (string) $row->{$prefix . 'rrule'},
        'rrule_index' => (string) $row->{$prefix . 'rrule_index'},
        'timezone' => (string) $row->{$prefix . 'timezone'},
      ];
    }
    return $result;
  }

}
