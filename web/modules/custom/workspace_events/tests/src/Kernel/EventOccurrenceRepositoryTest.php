<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\NodeInterface;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the event occurrence repository.
 */
#[CoversClass(EventOccurrenceRepository::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventOccurrenceRepositoryTest extends EventKernelTestBase {

  /**
   * The occurrence repository.
   */
  private EventOccurrenceRepository $repository;

  /**
   * The filter factory.
   */
  private EventFilterFactory $filterFactory;

  /**
   * The location A.
   */
  private int $locationA;

  /**
   * The location B.
   */
  private int $locationB;

  /**
   * The weekly course node ID.
   */
  private int $courseId;

  /**
   * The party node ID.
   */
  private int $partyId;

  /**
   * The all-day event node ID.
   */
  private int $allDayId;

  /**
   * The event without a known end node ID.
   */
  private int $openEndId;

  /**
   * The unpublished event node ID.
   */
  private int $unpublishedId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->repository = $this->container->get(EventOccurrenceRepository::class);
    $filter_factory = $this->container->get(EventFilterFactory::class);
    \assert($filter_factory instanceof EventFilterFactory);
    $this->filterFactory = $filter_factory;

    $location_a = $this->createLocation('Bootshaus Stralsund', "Friedrich-Naumann-Straße 5\n18435 Stralsund");
    $location_b = $this->createLocation('Treff ZuverSICht');
    $this->locationA = (int) $location_a->id();
    $this->locationB = (int) $location_b->id();

    $course = $this->createCourse([
      'title' => 'Anfänger',
      'field_location' => $this->locationA,
      'field_difficulty' => [0],
      'field_when' => $this->recurringDeltas(
        $this->date('2026-10-05', '18:15'),
        $this->date('2026-10-05', '19:15'),
        'WEEKLY',
        'INTERVAL=1;BYDAY=MO',
        '',
        'course',
      ),
    ]);
    $this->courseId = (int) $course->id();

    $party = $this->createEvent([
      'title' => 'Tanzabend',
      'field_location' => $this->locationA,
      'field_when' => [
        $this->dateDelta($this->date('2026-10-26', '18:00'), $this->date('2026-10-26', '22:00')),
      ],
    ]);
    $this->partyId = (int) $party->id();

    $all_day = $this->createEvent([
      'title' => 'Salsa-Party',
      'field_location' => $this->locationB,
      'field_when' => [
        $this->allDayDelta($this->date('2026-11-20')),
      ],
    ]);
    $this->allDayId = (int) $all_day->id();

    $open_end = $this->createEvent([
      'title' => 'Salsa im ballhaus goldfisch',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $this->locationB,
      'field_when' => [
        $this->dateDelta($this->date('2026-10-09', '20:00'), NULL),
      ],
    ]);
    $this->openEndId = (int) $open_end->id();

    $unpublished = $this->createEvent([
      'title' => 'Entwurf',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_location' => $this->locationA,
      'field_when' => [
        $this->dateDelta($this->date('2026-10-10', '20:00'), $this->date('2026-10-10', '22:00')),
      ],
    ]);
    $this->unpublishedId = (int) $unpublished->id();
  }

  /**
   * Tests finding occurrences in a range.
   */
  public function testFindOccurrences(): void {
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );

    self::assertCount(6, $occurrences);
    $starts = array_map(static fn ($occurrence): int => $occurrence->start->getTimestamp(), $occurrences);
    $sorted = $starts;
    sort($sorted);
    self::assertSame($sorted, $starts);

    $node_ids = array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $occurrences);
    self::assertNotContains($this->unpublishedId, $node_ids);
    self::assertNotContains($this->allDayId, $node_ids);

    // The recurring course yields four occurrences in October.
    self::assertCount(4, array_filter($node_ids, fn (int $id): bool => $id === $this->courseId));

    // The 18:00 party is sorted before the 18:15 course on the same day.
    $last_day = array_values(array_filter($occurrences, static fn ($occurrence): bool => $occurrence->start->format('Y-m-d') === '2026-10-26'));
    self::assertCount(2, $last_day);
    self::assertSame('Salsa am Sund', $last_day[0]->organizer?->label());
    self::assertFalse($last_day[0]->isCourse());
    self::assertSame('Anfänger', (string) $last_day[1]->node->label());
    self::assertTrue($last_day[1]->isCourse());
    self::assertNull($last_day[1]->organizer);

    // An event without an end has no end value.
    $open_end = array_values(array_filter($occurrences, fn ($occurrence): bool => (int) $occurrence->node->id() === $this->openEndId));
    self::assertCount(1, $open_end);
    self::assertNull($open_end[0]->end);
    self::assertFalse($open_end[0]->allDay);
    self::assertSame('Sonstige', $open_end[0]->organizer?->label());
  }

  /**
   * Tests the legacy type query parameters.
   */
  public function testLegacyTypeMapping(): void {
    $from = $this->date('2026-10-01');
    $to = $this->date('2026-12-01');

    $filter = $this->filterFactory->fromQuery(['type' => 'party']);
    self::assertTrue($filter->includeEvents);
    self::assertFalse($filter->includeCourses);
    self::assertSame([$this->salsaAmSund], $filter->organizerIds);

    $occurrences = $this->repository->findOccurrences($filter, $from, $to);
    self::assertCount(2, $occurrences);
    self::assertSame('Salsa am Sund', $occurrences[0]->organizer?->label());
    self::assertTrue($occurrences[1]->allDay);
    self::assertSame($this->allDayId, (int) $occurrences[1]->node->id());

    $filter = $this->filterFactory->fromQuery(['type' => 'external_party']);
    self::assertSame([$this->externalOrganizer], $filter->organizerIds);
    $occurrences = $this->repository->findOccurrences($filter, $from, $to);
    self::assertCount(1, $occurrences);
    self::assertSame($this->openEndId, (int) $occurrences[0]->node->id());

    $filter = $this->filterFactory->fromQuery(['type' => 'course']);
    self::assertFalse($filter->includeEvents);
    self::assertTrue($filter->includeCourses);
    // Nine weekly course occurrences until the end of November.
    self::assertCount(9, $this->repository->findOccurrences($filter, $from, $to));

    // Both legacy party keys together select both organizer terms.
    $filter = $this->filterFactory->fromQuery(['type' => 'party,external_party']);
    self::assertSame([$this->salsaAmSund, $this->externalOrganizer], $filter->organizerIds);
    self::assertCount(3, $this->repository->findOccurrences($filter, $from, $to));
  }

  /**
   * Tests filtering by organizer.
   */
  public function testFilterByOrganizer(): void {
    $from = $this->date('2026-10-01');
    $to = $this->date('2026-10-31', '23:59');

    $filter = $this->filterFactory->fromQuery(['organizer' => (string) $this->salsaAmSund]);
    self::assertTrue($filter->includeEvents);
    self::assertFalse($filter->includeCourses);
    $occurrences = $this->repository->findOccurrences($filter, $from, $to);
    self::assertCount(1, $occurrences);
    self::assertSame($this->partyId, (int) $occurrences[0]->node->id());

    $filter = $this->filterFactory->fromQuery(['organizer' => $this->salsaAmSund . ',' . $this->externalOrganizer]);
    $occurrences = $this->repository->findOccurrences($filter, $from, $to);
    self::assertCount(2, $occurrences);
    self::assertSame(
      [$this->openEndId, $this->partyId],
      array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $occurrences),
    );

    // An organizer without events matches nothing.
    self::assertSame([], $this->repository->findOccurrences(
      new EventFilter(includeEvents: TRUE, organizerIds: [9999], includeCourses: FALSE),
      $from,
      $to,
    ));
  }

  /**
   * Tests filtering by event node IDs.
   */
  public function testFilterByEventIds(): void {
    $from = $this->date('2026-10-01');
    $to = $this->date('2026-10-31', '23:59');

    $occurrences = $this->repository->findOccurrences(
      EventFilter::eventsOnly(eventIds: [$this->partyId]),
      $from,
      $to,
    );
    self::assertCount(1, $occurrences);
    self::assertSame($this->partyId, (int) $occurrences[0]->node->id());

    // The selection only matches events, not a course with the same ID.
    self::assertSame([], $this->repository->findOccurrences(
      EventFilter::eventsOnly(eventIds: [$this->courseId]),
      $from,
      $to,
    ));
  }

  /**
   * Tests the organizer and course combinations.
   */
  public function testFilterByOrganizerAndCourse(): void {
    $from = $this->date('2026-10-01');
    $to = $this->date('2026-10-31', '23:59');

    $course = $this->repository->findOccurrences(EventFilter::coursesOnly(), $from, $to);
    self::assertCount(4, $course);
    foreach ($course as $occurrence) {
      self::assertTrue($occurrence->isCourse());
      self::assertSame($this->courseId, (int) $occurrence->node->id());
    }

    $party = $this->repository->findOccurrences(EventFilter::eventsOnly([$this->salsaAmSund]), $from, $to);
    self::assertCount(1, $party);
    self::assertSame($this->partyId, (int) $party[0]->node->id());

    $courses_and_parties = $this->repository->findOccurrences(
      new EventFilter(includeEvents: TRUE, organizerIds: [$this->salsaAmSund], includeCourses: TRUE),
      $from,
      $to,
    );
    self::assertCount(5, $courses_and_parties);
    self::assertSame(4, count(array_filter(
      $courses_and_parties,
      static fn ($occurrence): bool => $occurrence->isCourse(),
    )));

    // The organizer and course parameters are combined with OR.
    $combined = $this->filterFactory->fromQuery([
      'organizer' => (string) $this->externalOrganizer,
      'course' => (string) $this->courseId,
    ]);
    $ids = array_map(
      static fn ($occurrence): int => (int) $occurrence->node->id(),
      $this->repository->findOccurrences($combined, $from, $to),
    );
    self::assertCount(5, $ids);
    self::assertContains($this->openEndId, $ids);
    self::assertNotContains($this->partyId, $ids);

    $all = $this->repository->findOccurrences(new EventFilter(), $from, $to);
    self::assertCount(6, $all);
    self::assertNotContains($this->unpublishedId, array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $all));
  }

  /**
   * Tests filtering by individual courses.
   */
  public function testFilterByCourse(): void {
    $second = $this->createCourse([
      'title' => 'Fortgeschrittene',
      'field_location' => $this->locationA,
      'field_when' => $this->recurringDeltas(
        $this->date('2026-10-06', '19:30'),
        $this->date('2026-10-06', '20:30'),
        'WEEKLY',
        'INTERVAL=1;BYDAY=TU',
        '',
        'course',
      ),
    ]);
    $second_id = (int) $second->id();
    $from = $this->date('2026-10-01');
    $to = $this->date('2026-10-31', '23:59');

    // Only the selected course.
    $selected = $this->repository->findOccurrences(EventFilter::coursesOnly([$second_id]), $from, $to);
    self::assertCount(4, $selected);
    foreach ($selected as $occurrence) {
      self::assertSame($second_id, (int) $occurrence->node->id());
      self::assertTrue($occurrence->isCourse());
    }

    // A course parameter implies the course selection.
    $filter = $this->filterFactory->fromQuery(['course' => (string) $this->courseId]);
    self::assertFalse($filter->includeEvents);
    self::assertTrue($filter->includeCourses);
    self::assertSame([$this->courseId], $filter->courseIds);
    self::assertCount(4, $this->repository->findOccurrences($filter, $from, $to));

    // Parties plus one selected course.
    $combined = $this->filterFactory->fromQuery([
      'type' => 'party,external_party',
      'course' => (string) $second_id,
    ]);
    $occurrences = $this->repository->findOccurrences($combined, $from, $to);
    $ids = array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $occurrences);
    self::assertCount(6, $occurrences);
    self::assertSame(4, count(array_filter($ids, static fn (int $id): bool => $id === $second_id)));
    self::assertContains($this->partyId, $ids);
    self::assertContains($this->openEndId, $ids);
    self::assertNotContains($this->courseId, $ids);

    // An empty course list keeps the previous behaviour: every course.
    self::assertCount(8, $this->repository->findOccurrences(EventFilter::coursesOnly(), $from, $to));
  }

  /**
   * Tests filtering by location.
   */
  public function testFilterByLocation(): void {
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(locationIds: [$this->locationA]),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );
    self::assertCount(5, $occurrences);
    foreach ($occurrences as $occurrence) {
      self::assertSame($this->locationA, (int) $occurrence->location?->id());
    }

    $occurrences = $this->repository->findOccurrences(
      new EventFilter(locationIds: [$this->locationB]),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );
    self::assertCount(1, $occurrences);
    self::assertSame($this->openEndId, (int) $occurrences[0]->node->id());
  }

  /**
   * Tests the limit and the time window boundaries.
   */
  public function testLimitAndWindow(): void {
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
      2,
    );
    self::assertCount(2, $occurrences);
    self::assertSame('2026-10-05', $occurrences[0]->start->format('Y-m-d'));
    self::assertSame('2026-10-09', $occurrences[1]->start->format('Y-m-d'));

    // The weekly course is not visible when the range starts after it.
    $occurrences = $this->repository->findOccurrences(
      EventFilter::coursesOnly(),
      $this->date('2026-11-01'),
      $this->date('2026-11-30', '23:59'),
    );
    self::assertCount(5, $occurrences);
    self::assertSame('2026-11-02', $occurrences[0]->start->format('Y-m-d'));

    // Reversed ranges produce no results.
    self::assertSame([], $this->repository->findOccurrences(
      new EventFilter(),
      $this->date('2026-12-01'),
      $this->date('2026-11-01'),
    ));
  }

  /**
   * Tests that events without an organizer are only part of the open filter.
   */
  public function testEventWithoutOrganizer(): void {
    $node = $this->createEvent([
      'title' => 'Ohne Veranstalter',
      'field_organizer' => NULL,
      'field_when' => [
        $this->dateDelta($this->date('2026-10-15', '20:00'), $this->date('2026-11-15', '20:00')),
      ],
    ]);
    $id = (int) $node->id();
    $from = $this->date('2026-10-01');
    $to = $this->date('2026-10-31', '23:59');
    self::assertNull($node->get('field_organizer')->entity);

    $all_ids = array_map(
      static fn ($occurrence): int => (int) $occurrence->node->id(),
      $this->repository->findOccurrences(new EventFilter(), $from, $to),
    );
    self::assertContains($id, $all_ids);

    $filtered = $this->repository->findOccurrences(
      EventFilter::eventsOnly([$this->salsaAmSund, $this->externalOrganizer]),
      $from,
      $to,
    );
    self::assertNotContains($id, array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $filtered));
  }

}
