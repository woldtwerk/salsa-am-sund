<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\workspace_events\DetailFieldResolver;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\workspace_events\Plugin\Block\CourseScheduleBlock;
use Drupal\workspace_events\Plugin\Block\EventBlockBase;
use Drupal\workspace_events\Plugin\Block\UpcomingEventsBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the data selection of the event blocks.
 */
#[CoversClass(UpcomingEventsBlock::class)]
#[CoversClass(CourseScheduleBlock::class)]
#[CoversClass(EventBlockBase::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventBlocksTest extends EventKernelTestBase {

  /**
   * The block plugin manager.
   */
  private BlockManagerInterface $blockManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installLessonConfig();
    $this->blockManager = $this->container->get('plugin.manager.block');
  }

  /**
   * Tests the upcoming events block.
   */
  public function testUpcomingEventsBlock(): void {
    $location = $this->createLocation('Bootshaus Stralsund', "Friedrich-Naumann-Straße 5\n18435 Stralsund");
    $this->createEvent([
      'title' => 'Salsa im ballhaus goldfisch',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $location->id(),
      'field_event_date' => $this->dateRange(
        $this->date('now +1 day', '20:00'),
        $this->date('now +1 day', '23:00'),
      ),
    ]);
    $start = $this->date('now +2 days', '18:15');
    $course = $this->createCourse([
      'title' => 'Anfänger',
      'field_location' => $location->id(),
      ...$this->courseSchedule($start, $start),
    ]);
    $party = $this->createEvent([
      'title' => 'Tanzabend',
      'field_location' => $location->id(),
      'body' => [
        'value' => '<p>Tanzabschluss-Abend für alle.</p>',
        'format' => 'plain_text',
      ],
      'field_event_date' => $this->dateRange(
        $this->date('now +3 days', '18:00'),
        $this->date('now +3 days', '22:00'),
      ),
    ]);
    $this->createEvent([
      'title' => 'Später Termin',
      'field_event_date' => $this->dateRange(
        $this->date('now +40 days', '18:00'),
        $this->date('now +40 days', '20:00'),
      ),
    ]);

    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [$this->salsaAmSund, $this->externalOrganizer],
      'courses' => FALSE,
      'locations' => [],
      'limit' => 2,
      'show_ical' => TRUE,
    ]);
    $build = $block->build();

    self::assertArrayHasKey('events', $build);
    $items = $build['events']['#slots']['items'];
    self::assertCount(2, $items);
    self::assertSame('Salsa im ballhaus goldfisch', $items[0]['#props']['title']);
    self::assertSame('20:00 – 23:00 Uhr', $items[0]['#props']['time']);
    self::assertSame('Tanzabend', $items[1]['#props']['title']);
    self::assertSame('18:00 – 22:00 Uhr', $items[1]['#props']['time']);
    self::assertSame('Bootshaus Stralsund', $items[1]['#props']['location']);
    self::assertStringContainsString('Tanzabschluss-Abend', (string) $items[1]['#props']['text']);
    self::assertStringNotContainsString('plain_text', (string) $items[1]['#props']['text']);
    self::assertStringNotContainsString('content_format', (string) $items[1]['#props']['text']);
    self::assertSame($party->toUrl()->toString(), $items[1]['#props']['href']);
    self::assertSame(
      '/termine/' . $party->id() . '/ical',
      $items[1]['#props']['ical_href'],
    );

    // The calendar subscription popover offers one checkbox per organizer and
    // the courses.
    $component = $build['ical']['subscribe'];
    self::assertSame('salsa:calendar-subscribe', $component['#component']);
    $props = $component['#props'];
    self::assertSame('/termine.ics', parse_url($props['action'], PHP_URL_PATH));
    self::assertSame([], $props['hidden']);

    $options = array_values($props['options']);
    self::assertCount(3, $options);
    self::assertSame('organizer[]', $options[0]['name']);
    self::assertSame((string) $this->salsaAmSund, $options[0]['value']);
    self::assertSame('Salsa am Sund', $options[0]['label']);
    self::assertSame('organizers', $options[0]['group']);
    self::assertTrue($options[0]['checked']);
    self::assertSame('organizer[]', $options[1]['name']);
    self::assertSame((string) $this->externalOrganizer, $options[1]['value']);
    self::assertSame('Sonstige', $options[1]['label']);
    self::assertSame(2, count(array_filter(
      $options,
      static fn (array $option): bool => $option['group'] === 'organizers',
    )));
    self::assertSame('course[]', $options[2]['name']);
    self::assertSame((string) $course->id(), $options[2]['value']);
    self::assertSame('Anfänger', $options[2]['label']);
    self::assertSame('courses', $options[2]['group']);
    self::assertFalse($options[2]['checked']);
    self::assertStringContainsString(' · ', $options[2]['meta']);
    self::assertStringContainsString('Bootshaus Stralsund', $options[2]['meta']);

    self::assertContains('node_list:event', $build['#cache']['tags']);
    self::assertContains('node_list:course', $build['#cache']['tags']);
    self::assertContains('node_list:lesson', $build['#cache']['tags']);
    self::assertContains('taxonomy_term_list:location', $build['#cache']['tags']);
    self::assertContains('taxonomy_term_list:organizer', $build['#cache']['tags']);
    self::assertLessThanOrEqual(86400, $build['#cache']['max-age']);
  }

  /**
   * Tests the options of the calendar subscription popover.
   */
  public function testUpcomingEventsCalendarSubscription(): void {
    $location = $this->createLocation('Bootshaus Stralsund');
    foreach (['Anfänger', 'Fortgeschrittene'] as $index => $title) {
      $start = $this->date('now +' . ($index + 2) . ' days', '18:15');
      $this->createCourse([
        'title' => $title,
        'field_location' => $location->id(),
        ...$this->courseSchedule($start, $start),
      ]);
    }

    // A block without an organizer restriction offers every organizer term.
    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [],
      'courses' => FALSE,
      'locations' => [(int) $location->id()],
      'limit' => 3,
      'show_ical' => TRUE,
    ]);
    $props = $block->build()['ical']['subscribe']['#props'];
    $groups = array_column($props['options'], 'group');
    self::assertSame(['organizers', 'organizers', 'courses', 'courses'], $groups);
    self::assertSame([
      ['name' => 'location', 'value' => (string) $location->id()],
    ], $props['hidden']);

    // Only the organizers allowed by the block configuration are offered.
    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [$this->externalOrganizer],
      'courses' => TRUE,
      'locations' => [],
      'limit' => 3,
      'show_ical' => TRUE,
    ]);
    $props = $block->build()['ical']['subscribe']['#props'];
    self::assertSame(['organizers', 'courses', 'courses'], array_column($props['options'], 'group'));
    self::assertSame('Sonstige', $props['options'][0]['label']);
    self::assertSame([], $props['hidden']);
  }

  /**
   * Tests filtering and the empty state of the upcoming events block.
   */
  public function testUpcomingEventsFiltering(): void {
    $location = $this->createLocation('Bootshaus Stralsund');
    $start = $this->date('now +2 days', '18:15');
    $this->createCourse([
      'title' => 'Anfänger',
      'field_location' => $location->id(),
      ...$this->courseSchedule($start, $start),
    ]);
    $this->createEvent([
      'title' => 'Salsa am Sund Party',
      'field_event_date' => $this->dateRange(
        $this->date('now +3 days', '18:00'),
        $this->date('now +3 days', '22:00'),
      ),
    ]);
    $this->createEvent([
      'title' => 'Externe Party',
      'field_organizer' => $this->externalOrganizer,
      'field_event_date' => $this->dateRange(
        $this->date('now +4 days', '18:00'),
        $this->date('now +4 days', '22:00'),
      ),
    ]);

    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [$this->salsaAmSund],
      'courses' => FALSE,
      'locations' => [],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    $build = $block->build();
    self::assertCount(1, $build['events']['#slots']['items']);
    self::assertSame('Salsa am Sund Party', $build['events']['#slots']['items'][0]['#props']['title']);
    self::assertArrayNotHasKey('ical', $build);

    // An empty organizer selection shows the events of every organizer.
    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [],
      'courses' => FALSE,
      'locations' => [],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    self::assertCount(2, $block->build()['events']['#slots']['items']);

    // With courses enabled the course is part of the list too.
    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [$this->salsaAmSund],
      'courses' => TRUE,
      'locations' => [],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    $build = $block->build();
    self::assertCount(2, $build['events']['#slots']['items']);
    self::assertSame('Anfänger', $build['events']['#slots']['items'][0]['#props']['title']);

    // An unknown organizer filters everything out.
    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [9999],
      'courses' => FALSE,
      'locations' => [],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    $build = $block->build();
    self::assertArrayNotHasKey('events', $build);
    self::assertArrayHasKey('empty', $build);
    self::assertSame('No upcoming events.', (string) $build['empty']['#markup']);

    // An unknown location filters everything out.
    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [],
      'courses' => TRUE,
      'locations' => [9999],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    self::assertArrayHasKey('empty', $block->build());
  }

  /**
   * Tests the course schedule block.
   */
  public function testCourseScheduleBlock(): void {
    $location = $this->createLocation(
      'Bootshaus Stralsund',
      'Friedrich-Naumann-Straße 5',
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
    );
    $this->createCourse([
      'title' => 'Anfänger',
      'field_difficulty' => [0],
      'field_location' => $location->id(),
      ...$this->courseSchedule(
        $this->date('next monday', '18:15'),
        $this->date('next monday', '18:15')->modify('+3 weeks'),
      ),
    ]);
    $this->createCourse([
      'title' => 'Tanzfeeling u. mehr',
      'field_difficulty' => [1],
      'field_location' => $location->id(),
      ...$this->courseSchedule(
        $this->date('next tuesday', '19:30'),
        $this->date('next tuesday', '19:30'),
      ),
    ]);
    $this->createCourse([
      'title' => 'Zu weit in der Zukunft',
      ...$this->courseSchedule(
        $this->date('now +200 days', '18:00'),
        $this->date('now +200 days', '18:00'),
      ),
    ]);

    $block = $this->blockManager->createInstance('salsa_course_schedule', [
      'locations' => [],
    ]);
    $build = $block->build();

    self::assertArrayHasKey('schedule', $build);
    $courses = $build['schedule']['#slots']['courses'];
    self::assertCount(2, $courses);
    self::assertSame('montag', $courses[0]['#props']['day']);
    self::assertSame('18:15 – 19:15 Uhr', $courses[0]['#props']['time']);
    self::assertSame('Anfänger', $courses[0]['#props']['title']);
    self::assertSame('Level 0', $courses[0]['#props']['level']);
    self::assertSame('Bootshaus Stralsund', $courses[0]['#props']['location']);
    self::assertSame('https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205', $courses[0]['#props']['location_href']);
    self::assertSame('dienstag', $courses[1]['#props']['day']);
    self::assertSame('Tanzfeeling u. mehr', $courses[1]['#props']['title']);
    self::assertStringNotContainsString('plain_text', json_encode($courses, JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('content_format', json_encode($courses, JSON_THROW_ON_ERROR));

    self::assertContains('node_list:event', $build['#cache']['tags']);
    self::assertContains('node_list:course', $build['#cache']['tags']);
    self::assertContains('node_list:lesson', $build['#cache']['tags']);
    self::assertContains('taxonomy_term_list:location', $build['#cache']['tags']);
    self::assertContains('taxonomy_term_list:organizer', $build['#cache']['tags']);

    // The calendar subscription offers every organizer unchecked and the
    // courses of the schedule checked.
    $component = $build['ical']['subscribe'];
    self::assertSame('salsa:calendar-subscribe', $component['#component']);
    $props = $component['#props'];
    self::assertSame([], $props['hidden']);

    $options = array_values($props['options']);
    self::assertCount(4, $options);
    $organizers = array_values(array_filter(
      $options,
      static fn (array $option): bool => $option['group'] === 'organizers',
    ));
    self::assertCount(2, $organizers);
    foreach ($organizers as $option) {
      self::assertFalse($option['checked'], $option['label']);
    }
    $course_options = array_values(array_filter(
      $options,
      static fn (array $option): bool => $option['group'] === 'courses',
    ));
    self::assertCount(2, $course_options);
    foreach ($course_options as $option) {
      self::assertTrue($option['checked'], $option['label']);
    }
  }

  /**
   * Tests that the summary is preferred over the body value.
   */
  public function testDescriptionPrefersSummary(): void {
    $this->createEvent([
      'title' => 'Tanzabend',
      'body' => [
        'value' => '<p>Der lange Beschreibungstext des Termins.</p>',
        'summary' => 'Kurzbeschreibung für die Karte.',
        'format' => 'plain_text',
      ],
      'field_event_date' => $this->dateRange(
        $this->date('now +1 day', '18:00'),
        $this->date('now +1 day', '22:00'),
      ),
    ]);

    $block = $this->blockManager->createInstance('salsa_events', [
      'organizers' => [$this->salsaAmSund],
      'courses' => FALSE,
      'locations' => [],
      'limit' => 3,
      'show_ical' => FALSE,
    ]);
    $text = (string) $block->build()['events']['#slots']['items'][0]['#props']['text'];

    self::assertStringContainsString('Kurzbeschreibung für die Karte.', $text);
    self::assertStringNotContainsString('Der lange Beschreibungstext', $text);
    self::assertStringNotContainsString('plain_text', $text);
  }

  /**
   * Tests the location filter of the course schedule block.
   */
  public function testCourseScheduleLocationFilter(): void {
    $location_a = $this->createLocation('Bootshaus Stralsund');
    $location_b = $this->createLocation('Treff ZuverSICht');
    foreach (['A' => $location_a->id(), 'B' => $location_b->id()] as $suffix => $tid) {
      $start = $this->date('next monday', '18:00');
      $this->createCourse([
        'title' => 'Kurs ' . $suffix,
        'field_location' => $tid,
        ...$this->courseSchedule($start, $start),
      ]);
    }

    $block = $this->blockManager->createInstance('salsa_course_schedule', [
      'locations' => [(int) $location_b->id()],
    ]);
    $build = $block->build();
    $courses = $build['schedule']['#slots']['courses'];
    self::assertCount(1, $courses);
    self::assertSame('Kurs B', $courses[0]['#props']['title']);
    self::assertSame([
      ['name' => 'location', 'value' => (string) $location_b->id()],
    ], $build['ical']['subscribe']['#props']['hidden']);
  }

  /**
   * Tests that the cache expires with the earliest occurrence end.
   */
  public function testOccurrenceCacheExpiresWithEarliestEnd(): void {
    $node = $this->createEvent([
      'title' => 'Cache test',
      'field_event_date' => $this->dateRange(
        $this->date('now +1 day', '18:00'),
        $this->date('now +1 day', '19:00'),
      ),
    ]);
    $now = time();
    $start = new \DateTimeImmutable('@' . ($now + 3600));
    $occurrences = [
      new EventOccurrence($node, NULL, $start, new \DateTimeImmutable('@' . ($now + 7200)), FALSE, NULL, NULL),
      new EventOccurrence($node, NULL, $start, new \DateTimeImmutable('@' . ($now + 14400)), FALSE, NULL, NULL),
    ];

    $block = $this->cacheBlock();
    $build = [];
    $before = time();
    $block->applyCache($build, $occurrences);
    $after = time();

    // The earliest end is two hours away; the later occurrence must not
    // extend the lifetime.
    $end = $now + 7200;
    self::assertGreaterThanOrEqual(min(86400, max(0, $end - $after)), $build['#cache']['max-age']);
    self::assertLessThanOrEqual(min(86400, max(0, $end - $before)), $build['#cache']['max-age']);
  }

  /**
   * Tests that an occurrence without an end expires at the end of its day.
   */
  public function testOccurrenceCacheOpenEndFallsBackToEndOfDay(): void {
    $node = $this->createEvent([
      'title' => 'Cache test',
      'field_event_date' => $this->dateRange(
        $this->date('now +1 day', '18:00'),
        $this->date('now +1 day', '19:00'),
      ),
    ]);
    $start = new \DateTimeImmutable('today 10:00');
    $occurrences = [
      new EventOccurrence($node, NULL, $start, NULL, FALSE, NULL, NULL),
    ];

    $block = $this->cacheBlock();
    $build = [];
    $before = time();
    $block->applyCache($build, $occurrences);
    $after = time();

    $end = $start->setTime(23, 59)->getTimestamp();
    self::assertGreaterThanOrEqual(min(86400, max(0, $end - $after)), $build['#cache']['max-age']);
    self::assertLessThanOrEqual(min(86400, max(0, $end - $before)), $build['#cache']['max-age']);
  }

  /**
   * Builds a block that exposes the occurrence cache metadata.
   *
   * @return \Drupal\workspace_events\Plugin\Block\EventBlockBase
   *   The test block.
   */
  private function cacheBlock(): EventBlockBase {
    $repository = $this->container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $formatter = $this->container->get(EventDescriptionFormatter::class);
    \assert($formatter instanceof EventDescriptionFormatter);

    return new class([], 'test', ['provider' => 'workspace_events'], $repository, $entity_type_manager, $formatter, new DetailFieldResolver()) extends EventBlockBase {

      /**
       * {@inheritdoc}
       */
      public function build(): array {
        return [];
      }

      /**
       * Exposes the occurrence cache metadata.
       *
       * @param array<mixed> $build
       *   The render array.
       * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
       *   The rendered occurrences.
       */
      public function applyCache(array &$build, array $occurrences): void {
        $this->applyOccurrenceCache($build, $occurrences);
      }

    };
  }

}
