<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\SiteTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the event occurrence repository.
 */
#[CoversClass(EventOccurrenceRepository::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventOccurrenceRepositoryTest extends LessonKernelTestBase {

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
   * The external event node ID.
   */
  private int $externalId;

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
    $factory = $this->container->get(EventFilterFactory::class);
    \assert($factory instanceof EventFilterFactory);
    $this->filterFactory = $factory;

    $location_a = $this->createLocation('Bootshaus Stralsund', "Friedrich-Naumann-Straße 5\n18435 Stralsund");
    $location_b = $this->createLocation('Treff ZuverSICht');
    $this->locationA = (int) $location_a->id();
    $this->locationB = (int) $location_b->id();

    $course = $this->createCourse([
      'title' => 'Anfänger',
      'season_location' => $this->locationA,
      'field_difficulty' => [0],
      ...$this->courseSchedule(
        $this->date('2026-10-05', '18:15'),
        $this->date('2026-12-31'),
      ),
    ]);
    $this->courseId = (int) $course->id();

    $party = $this->createEvent([
      'title' => 'Tanzabend',
      'field_location' => $this->locationA,
      'field_event_date' => $this->smartDateRange(
        $this->date('2026-10-26', '18:00'),
        $this->date('2026-10-26', '22:00'),
      ),
    ]);
    $this->partyId = (int) $party->id();

    $all_day = $this->createEvent([
      'title' => 'Salsa-Party',
      'field_location' => $this->locationB,
      'field_event_date' => $this->allDaySmartDateRange($this->date('2026-11-20')),
    ]);
    $this->allDayId = (int) $all_day->id();

    $external = $this->createEvent([
      'title' => 'Salsa im ballhaus goldfisch',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $this->locationB,
      'field_event_date' => $this->smartDateRange(
        $this->date('2026-10-09', '20:00'),
        $this->date('2026-10-09', '22:00'),
      ),
    ]);
    $this->externalId = (int) $external->id();

    $unpublished = $this->createEvent([
      'title' => 'Entwurf',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_location' => $this->locationA,
      'field_event_date' => $this->smartDateRange(
        $this->date('2026-10-10', '20:00'),
        $this->date('2026-10-10', '22:00'),
      ),
    ]);
    $this->unpublishedId = (int) $unpublished->id();
  }

  /**
   * Tests finding occurrences of events and lessons in a range.
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
    self::assertCount(4, array_filter($node_ids, fn (int $id): bool => $id === $this->courseId));

    // The 18:00 party is sorted before the 18:15 course on the same day.
    $last_day = array_values(array_filter($occurrences, static fn ($occurrence): bool => $occurrence->start->format('Y-m-d') === '2026-10-26'));
    self::assertCount(2, $last_day);
    self::assertSame('Salsa am Sund', $last_day[0]->organizer?->label());
    self::assertFalse($last_day[0]->isCourse());
    self::assertSame('Anfänger', (string) $last_day[1]->node->label());
    self::assertTrue($last_day[1]->isCourse());
    self::assertNull($last_day[1]->organizer);
    self::assertNotNull($last_day[1]->lesson);

    $external = array_values(array_filter($occurrences, fn ($occurrence): bool => (int) $occurrence->node->id() === $this->externalId));
    self::assertCount(1, $external);
    self::assertNull($external[0]->lesson);
    self::assertFalse($external[0]->allDay);
    self::assertSame('Sonstige', $external[0]->organizer?->label());
  }

  /**
   * Tests every filter of the repository.
   */
  public function testFilters(): void {
    $from = $this->date('2026-10-01');
    $to = $this->date('2026-10-31', '23:59');
    $until_december = $this->date('2026-12-01');

    // Legacy type mapping.
    $filter = $this->filterFactory->fromQuery(['type' => 'party']);
    self::assertTrue($filter->includeEvents);
    self::assertFalse($filter->includeCourses);
    self::assertSame([$this->salsaAmSund], $filter->organizerIds);
    $occurrences = $this->repository->findOccurrences($filter, $from, $until_december);
    self::assertCount(2, $occurrences);
    self::assertSame('Salsa am Sund', $occurrences[0]->organizer?->label());
    self::assertTrue($occurrences[1]->allDay);
    self::assertSame($this->allDayId, (int) $occurrences[1]->node->id());

    $filter = $this->filterFactory->fromQuery(['type' => 'external_party']);
    self::assertSame([$this->externalOrganizer], $filter->organizerIds);
    $occurrences = $this->repository->findOccurrences($filter, $from, $until_december);
    self::assertCount(1, $occurrences);
    self::assertSame($this->externalId, (int) $occurrences[0]->node->id());

    $filter = $this->filterFactory->fromQuery(['type' => 'course']);
    self::assertFalse($filter->includeEvents);
    self::assertTrue($filter->includeCourses);
    self::assertCount(9, $this->repository->findOccurrences($filter, $from, $until_december));

    $filter = $this->filterFactory->fromQuery(['type' => 'party,external_party']);
    self::assertSame([$this->salsaAmSund, $this->externalOrganizer], $filter->organizerIds);
    self::assertCount(3, $this->repository->findOccurrences($filter, $from, $until_december));

    // Organizer filter.
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
      [$this->externalId, $this->partyId],
      array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $occurrences),
    );
    self::assertSame([], $this->repository->findOccurrences(
      new EventFilter(includeEvents: TRUE, organizerIds: [9999], includeCourses: FALSE),
      $from,
      $to,
    ));

    // Event IDs only match events, not a course with the same ID.
    $occurrences = $this->repository->findOccurrences(
      EventFilter::eventsOnly(eventIds: [$this->partyId]),
      $from,
      $to,
    );
    self::assertCount(1, $occurrences);
    self::assertSame($this->partyId, (int) $occurrences[0]->node->id());
    self::assertSame([], $this->repository->findOccurrences(
      EventFilter::eventsOnly(eventIds: [$this->courseId]),
      $from,
      $to,
    ));

    // Organizer and course combinations.
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
    $combined = $this->filterFactory->fromQuery([
      'organizer' => (string) $this->externalOrganizer,
      'course' => (string) $this->courseId,
    ]);
    $ids = array_map(
      static fn ($occurrence): int => (int) $occurrence->node->id(),
      $this->repository->findOccurrences($combined, $from, $to),
    );
    self::assertCount(5, $ids);
    self::assertContains($this->externalId, $ids);
    self::assertNotContains($this->partyId, $ids);

    $all = $this->repository->findOccurrences(new EventFilter(), $from, $to);
    self::assertCount(6, $all);
    self::assertNotContains($this->unpublishedId, array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $all));

    // Location filter.
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(locationIds: [$this->locationA]),
      $from,
      $to,
    );
    self::assertCount(5, $occurrences);
    foreach ($occurrences as $occurrence) {
      self::assertSame($this->locationA, (int) $occurrence->location?->id());
    }
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(locationIds: [$this->locationB]),
      $from,
      $to,
    );
    self::assertCount(1, $occurrences);
    self::assertSame($this->externalId, (int) $occurrences[0]->node->id());

    // Course filter, including one selected course and combined selections.
    $second = $this->createCourse([
      'title' => 'Fortgeschrittene',
      'season_location' => $this->locationA,
      ...$this->courseSchedule(
        $this->date('2026-10-06', '19:30'),
        $this->date('2026-12-31'),
      ),
    ]);
    $second_id = (int) $second->id();
    $selected = $this->repository->findOccurrences(EventFilter::coursesOnly([$second_id]), $from, $to);
    self::assertCount(4, $selected);
    foreach ($selected as $occurrence) {
      self::assertSame($second_id, (int) $occurrence->node->id());
      self::assertTrue($occurrence->isCourse());
    }

    $filter = $this->filterFactory->fromQuery(['course' => (string) $this->courseId]);
    self::assertFalse($filter->includeEvents);
    self::assertTrue($filter->includeCourses);
    self::assertSame([$this->courseId], $filter->courseIds);
    self::assertCount(4, $this->repository->findOccurrences($filter, $from, $to));

    $combined = $this->filterFactory->fromQuery([
      'type' => 'party,external_party',
      'course' => (string) $second_id,
    ]);
    $occurrences = $this->repository->findOccurrences($combined, $from, $to);
    $ids = array_map(static fn ($occurrence): int => (int) $occurrence->node->id(), $occurrences);
    self::assertCount(6, $occurrences);
    self::assertSame(4, count(array_filter($ids, static fn (int $id): bool => $id === $second_id)));
    self::assertContains($this->partyId, $ids);
    self::assertContains($this->externalId, $ids);
    self::assertNotContains($this->courseId, $ids);
    // The repository memoizes the earlier unfiltered course query, so use a
    // fresh instance to see the course that was created afterwards.
    $repository = new EventOccurrenceRepository(
      $this->container->get('entity_type.manager'),
      $this->container->get(SiteTimeZone::class),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
    self::assertCount(8, $repository->findOccurrences(EventFilter::coursesOnly(), $from, $to));
  }

  /**
   * Tests that every delta of a recurring event is one occurrence.
   */
  public function testRecurringEventDeltas(): void {
    $event = $this->createEvent([
      'title' => 'Jeden Mittwoch',
      'field_event_date' => [
        $this->smartDateRange($this->date('2026-10-07', '20:00'), $this->date('2026-10-07', '22:00')),
        $this->smartDateRange($this->date('2026-10-14', '20:00'), $this->date('2026-10-14', '22:00')),
        $this->smartDateRange($this->date('2026-10-21', '20:00'), $this->date('2026-10-21', '22:00')),
        $this->smartDateRange($this->date('2026-10-28', '20:00'), $this->date('2026-10-28', '22:00')),
      ],
    ]);
    $filter = EventFilter::eventsOnly(eventIds: [(int) $event->id()]);
    $from = $this->date('2026-10-01');
    $to = $this->date('2026-10-31', '23:59');

    $occurrences = $this->repository->findOccurrences($filter, $from, $to);
    self::assertCount(4, $occurrences);
    self::assertSame([
      '2026-10-07',
      '2026-10-14',
      '2026-10-21',
      '2026-10-28',
    ], array_map(
      static fn ($occurrence): string => $occurrence->start->format('Y-m-d'),
      $occurrences,
    ));
    foreach ($occurrences as $occurrence) {
      self::assertSame((int) $event->id(), (int) $occurrence->node->id());
      self::assertNull($occurrence->lesson);
    }

    $limited = $this->repository->findOccurrences($filter, $from, $to, 2);
    self::assertCount(2, $limited);
    self::assertSame('2026-10-07', $limited[0]->start->format('Y-m-d'));
    self::assertSame('2026-10-14', $limited[1]->start->format('Y-m-d'));
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

    $occurrences = $this->repository->findOccurrences(
      EventFilter::coursesOnly(),
      $this->date('2026-11-01'),
      $this->date('2026-11-30', '23:59'),
    );
    self::assertCount(5, $occurrences);
    self::assertSame('2026-11-02', $occurrences[0]->start->format('Y-m-d'));

    self::assertSame([], $this->repository->findOccurrences(
      new EventFilter(),
      $this->date('2026-12-01'),
      $this->date('2026-11-01'),
    ));
  }

  /**
   * Tests the visibility of lessons, their course and a cancellation.
   */
  public function testLessonVisibility(): void {
    $unpublished = $this->createCourse([
      'title' => 'Entwurfskurs',
      'status' => NodeInterface::NOT_PUBLISHED,
      ...$this->courseSchedule(
        $this->date('2026-10-01', '18:00'),
        $this->date('2026-10-01', '19:00'),
      ),
    ]);
    $occurrences = $this->repository->findOccurrences(
      EventFilter::coursesOnly(),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );
    foreach ($occurrences as $occurrence) {
      self::assertNotSame((int) $unpublished->id(), (int) $occurrence->node->id());
    }
    $limited = $this->repository->findOccurrences(
      EventFilter::coursesOnly(),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
      1,
    );
    self::assertCount(1, $limited);
    self::assertSame('2026-10-05', $limited[0]->start->format('Y-m-d'));
    $lesson = $this->loadCourseLessons($unpublished)[0] ?? NULL;
    self::assertInstanceOf(LessonInterface::class, $lesson);
    self::assertNull($this->repository->findLessonOccurrence($lesson));

    // The lesson dates are public even though the lessons are restricted.
    $lesson = $this->loadCourseLessons($this->nodeStorage->load($this->courseId))[0] ?? NULL;
    self::assertInstanceOf(LessonInterface::class, $lesson);
    $anonymous = new AnonymousUserSession();
    $this->container->get('current_user')->setAccount($anonymous);
    self::assertFalse($lesson->access('view', $anonymous));
    // Scheduler publishes lessons only after they are over, but the calendar
    // must show them before that.
    $lesson->setUnpublished()->save();
    $occurrences = $this->repository->findOccurrences(
      EventFilter::coursesOnly([$this->courseId]),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );
    self::assertCount(4, $occurrences);
    self::assertTrue($occurrences[0]->isCourse());

    $cancelled_course = $this->createCourse([
      'title' => 'Ausfall',
      ...$this->courseSchedule(
        $this->date('2026-10-08', '18:00'),
        $this->date('2026-10-08', '19:00'),
      ),
    ]);
    $lesson = $this->loadCourseLessons($cancelled_course)[0] ?? NULL;
    self::assertInstanceOf(LessonInterface::class, $lesson);
    $lesson->set('field_cancelled', TRUE);
    $lesson->save();
    $occurrences = $this->repository->findOccurrences(
      EventFilter::coursesOnly([(int) $cancelled_course->id()]),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );
    self::assertCount(1, $occurrences);
    self::assertTrue($occurrences[0]->isCancelled());
  }

  /**
   * Tests that the location of a course occurrence comes from its season.
   */
  public function testLocationFilterUsesTheSeasonOfTheLesson(): void {
    $course = $this->createCourse(['title' => 'Zwei Säle']);
    $season_a = $this->createSeason($course, [
      'field_schedule' => $this->smartDateDeltas(
        $this->date('2026-10-05', '18:00'),
        $this->date('2026-10-26', '19:00'),
      ),
      'location' => $this->locationA,
    ]);
    $season_b = $this->createSeason($course, [
      'field_schedule' => $this->smartDateDeltas(
        $this->date('2026-10-06', '18:00'),
        $this->date('2026-10-27', '19:00'),
      ),
      'location' => $this->locationB,
    ]);

    $occurrences = $this->repository->findOccurrences(
      EventFilter::coursesOnly([(int) $course->id()], [$this->locationB]),
      $this->date('2026-10-01'),
      $this->date('2026-10-31', '23:59'),
    );

    self::assertCount(4, $occurrences);
    foreach ($occurrences as $occurrence) {
      self::assertSame($this->locationB, (int) $occurrence->location?->id());
      self::assertSame((int) $season_b->id(), (int) $occurrence->season?->id());
      self::assertNotSame((int) $season_a->id(), (int) $occurrence->season?->id());
    }
  }

}
