<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Controller\IcalController;
use Drupal\workspace_events\DetailFieldResolver;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the iCal controller.
 */
#[CoversClass(IcalController::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class IcalControllerTest extends EventKernelTestBase {

  /**
   * The iCal controller.
   */
  private IcalController $controller;

  /**
   * The course node.
   */
  private NodeInterface $course;

  /**
   * The lessons of the course.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  private array $lessons;

  /**
   * The all-day node ID.
   */
  private int $allDayId;

  /**
   * The multi-day all-day node ID.
   */
  private int $multiDayId;

  /**
   * The start of the multi-day all-day event.
   */
  private \DateTimeImmutable $multiDayStart;

  /**
   * The last day of the multi-day all-day event.
   */
  private \DateTimeImmutable $multiDayLastDay;

  /**
   * The external event node ID.
   */
  private int $externalId;

  /**
   * The past node ID.
   */
  private int $pastId;

  /**
   * The unpublished node ID.
   */
  private int $unpublishedId;

  /**
   * The location A term ID.
   */
  private int $locationA;

  /**
   * The location B term ID.
   */
  private int $locationB;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installLessonConfig();

    $repository = $this->container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $description_formatter = $this->container->get(EventDescriptionFormatter::class);
    \assert($description_formatter instanceof EventDescriptionFormatter);
    $filter_factory = $this->container->get(EventFilterFactory::class);
    \assert($filter_factory instanceof EventFilterFactory);
    $detail_field_resolver = $this->container->get(DetailFieldResolver::class);
    \assert($detail_field_resolver instanceof DetailFieldResolver);
    $transliteration = $this->container->get('transliteration');
    \assert($transliteration instanceof TransliterationInterface);
    $this->controller = new IcalController(
      $repository,
      $this->container->get('entity_type.manager'),
      $description_formatter,
      $filter_factory,
      $detail_field_resolver,
      $transliteration,
    );

    $location_a = $this->createLocation(
      'Bootshaus Stralsund',
      "Clubgaststätte „Zum Bootshaus“\nFriedrich-Naumann-Straße 5\n18435 Stralsund",
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
    );
    $location_b = $this->createLocation('Südamerikanische Vertretung');
    $this->locationA = (int) $location_a->id();
    $this->locationB = (int) $location_b->id();

    $start = $this->date('now +5 days', '18:15');
    $this->course = $this->createCourse([
      'title' => 'Anfänger',
      'field_difficulty' => [0],
      'field_location' => $this->locationA,
      'body' => [
        'value' => '<p>Ein Kurs für <strong>Einsteiger</strong>.</p>',
        'format' => 'plain_text',
      ],
      ...$this->courseSchedule(
        $start,
        $start->modify('+3 weeks'),
      ),
    ]);
    $this->lessons = $this->loadCourseLessons($this->course);

    $all_day = $this->createEvent([
      'title' => 'Salsa-Party',
      'field_location' => $this->locationB,
      'field_event_date' => $this->allDayRange($this->date('now +20 days')),
    ]);
    $this->allDayId = (int) $all_day->id();

    $this->multiDayStart = $this->date('now +30 days');
    $this->multiDayLastDay = $this->date('now +32 days');
    $multi_day = $this->createEvent([
      'title' => 'Festival',
      'field_location' => $this->locationB,
      'field_event_date' => $this->allDayRange($this->multiDayStart, $this->multiDayLastDay),
    ]);
    $this->multiDayId = (int) $multi_day->id();

    $external = $this->createEvent([
      'title' => 'Salsa im ballhaus goldfisch',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $this->locationA,
      'field_link' => ['uri' => 'https://www.ballhaus-goldfisch.de/', 'title' => 'ballhaus goldfisch'],
      'field_event_date' => $this->dateRange(
        $this->date('now +10 days', '20:00'),
        $this->date('now +10 days', '23:00'),
      ),
    ]);
    $this->externalId = (int) $external->id();

    $past = $this->createEvent([
      'title' => 'Vergangener Tanzabend',
      'field_location' => $this->locationA,
      'field_event_date' => $this->dateRange(
        $this->date('now -5 days', '18:00'),
        $this->date('now -5 days', '22:00'),
      ),
    ]);
    $this->pastId = (int) $past->id();

    $unpublished = $this->createEvent([
      'title' => 'Entwurf',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_location' => $this->locationA,
      'field_event_date' => $this->dateRange(
        $this->date('now +2 days', '18:00'),
        $this->date('now +2 days', '22:00'),
      ),
    ]);
    $this->unpublishedId = (int) $unpublished->id();
  }

  /**
   * Tests the unfiltered feed.
   */
  public function testFeed(): void {
    $calendar = $this->readCalendar('/termine.ics');

    self::assertSame('2.0', $calendar->VERSION?->getValue());
    self::assertSame('Salsa am Sund – Termine', $calendar->{'X-WR-CALNAME'}?->getValue());
    self::assertSame('Europe/Berlin', $calendar->{'X-WR-TIMEZONE'}?->getValue());
    self::assertSame('PT6H', $calendar->{'X-PUBLISHED-TTL'}?->getValue());
    self::assertNotNull($calendar->VTIMEZONE);
    self::assertSame('Europe/Berlin', $calendar->VTIMEZONE->TZID?->getValue());

    $events = $calendar->select('VEVENT');
    // Four weekly lessons, the all-day party, the multi-day festival, the
    // external party and the past party.
    self::assertCount(8, $events);
    $uids = [];
    foreach ($events as $event) {
      $uid = (string) $event->UID->getValue();
      self::assertStringContainsString('@salsa-am-sund.localhost', $uid);
      $uids[] = $uid;
    }
    self::assertNotContains('event-' . $this->unpublishedId . '@salsa-am-sund.localhost', $uids);
    self::assertContains('event-' . $this->allDayId . '@salsa-am-sund.localhost', $uids);
    self::assertContains('event-' . $this->multiDayId . '@salsa-am-sund.localhost', $uids);
    self::assertContains('event-' . $this->externalId . '@salsa-am-sund.localhost', $uids);
    self::assertContains('event-' . $this->pastId . '@salsa-am-sund.localhost', $uids);
    foreach ($this->lessons as $lesson) {
      self::assertContains('event-' . $lesson->id() . '@salsa-am-sund.localhost', $uids);
    }

    $this->assertNoValidationErrors($calendar);
  }

  /**
   * Tests the calendar name of filtered feeds.
   */
  public function testFiltering(): void {
    $calendar = $this->readCalendar('/termine.ics?type=course');
    self::assertSame('Salsa am Sund – Termine – Kurs', $calendar->{'X-WR-CALNAME'}?->getValue());

    $calendar = $this->readCalendar('/termine.ics?type=party,external_party');
    self::assertSame('Salsa am Sund – Termine – Salsa am Sund, Sonstige', $calendar->{'X-WR-CALNAME'}?->getValue());

    $calendar = $this->readCalendar('/termine.ics?organizer=' . $this->salsaAmSund);
    self::assertSame('Salsa am Sund – Termine – Salsa am Sund', $calendar->{'X-WR-CALNAME'}?->getValue());

    $calendar = $this->readCalendar('/termine.ics?organizer=' . $this->externalOrganizer);
    self::assertSame('Salsa am Sund – Termine – Sonstige', $calendar->{'X-WR-CALNAME'}?->getValue());

    $calendar = $this->readCalendar('/termine.ics?location=' . $this->locationA);
    self::assertStringContainsString('Bootshaus Stralsund', $calendar->{'X-WR-CALNAME'}?->getValue() ?? '');
  }

  /**
   * Tests selecting individual courses.
   */
  public function testCourseSelection(): void {
    $calendar = $this->readCalendar('/termine.ics?course=' . $this->course->id());
    $events = $calendar->select('VEVENT');
    self::assertCount(4, $events);
    $lesson_uids = array_map(static fn (NodeInterface $lesson): string => 'event-' . $lesson->id() . '@', $this->lessons);
    foreach ($events as $event) {
      $uid = (string) $event->UID->getValue();
      self::assertNotSame(
        [],
        array_filter($lesson_uids, static fn (string $prefix): bool => str_starts_with($uid, $prefix)),
        $uid,
      );
    }
    self::assertSame('Salsa am Sund – Termine – Anfänger', $calendar->{'X-WR-CALNAME'}?->getValue());

    // Organizers plus one selected course.
    $calendar = $this->readCalendar('/termine.ics?organizer=' . $this->salsaAmSund . ',' . $this->externalOrganizer . '&course=' . $this->course->id());
    self::assertSame('Salsa am Sund – Termine – Salsa am Sund, Sonstige, Anfänger', $calendar->{'X-WR-CALNAME'}?->getValue());

    // An empty course list keeps the previous behaviour and name.
    $calendar = $this->readCalendar('/termine.ics?type=course');
    self::assertSame('Salsa am Sund – Termine – Kurs', $calendar->{'X-WR-CALNAME'}?->getValue());
  }

  /**
   * Tests the event properties.
   */
  public function testEventProperties(): void {
    $calendar = $this->readCalendar('/termine.ics');
    $events = [];
    foreach ($calendar->select('VEVENT') as $event) {
      $events[(string) $event->UID->getValue()] = $event;
    }

    $course = $events['event-' . $this->lessons[0]->id() . '@salsa-am-sund.localhost'];
    self::assertSame('Anfänger (Level 0)', $course->SUMMARY?->getValue());
    self::assertSame('Kurs', $course->CATEGORIES?->getValue());
    self::assertSame('Europe/Berlin', $course->DTSTART['TZID']?->getValue());
    self::assertSame('Europe/Berlin', $course->DTEND['TZID']?->getValue());
    self::assertStringContainsString('Ein Kurs für Einsteiger.', (string) $course->DESCRIPTION?->getValue());
    self::assertStringNotContainsString('plain_text', (string) $course->DESCRIPTION?->getValue());
    self::assertStringNotContainsString('content_format', (string) $course->DESCRIPTION?->getValue());
    self::assertStringContainsString('https://salsa-am-sund.localhost/node/', (string) $course->DESCRIPTION?->getValue());
    self::assertSame('Bootshaus Stralsund, Clubgaststätte „Zum Bootshaus“, Friedrich-Naumann-Straße 5, 18435 Stralsund', $course->LOCATION?->getValue());
    self::assertSame('CONFIRMED', $course->STATUS?->getValue());

    $all_day = $events['event-' . $this->allDayId . '@salsa-am-sund.localhost'];
    self::assertSame('DATE', $all_day->DTSTART['VALUE']?->getValue());
    self::assertSame('DATE', $all_day->DTEND['VALUE']?->getValue());
    self::assertSame(
      $all_day->DTSTART->getDateTime()->modify('+1 day')->format('Ymd'),
      $all_day->DTEND->getValue(),
    );

    $external = $events['event-' . $this->externalId . '@salsa-am-sund.localhost'];
    self::assertSame('https://www.ballhaus-goldfisch.de/', $external->URL?->getValue());
    self::assertSame('Sonstige', $external->CATEGORIES?->getValue());
    self::assertSame('Salsa am Sund', $all_day->CATEGORIES?->getValue());
  }

  /**
   * Tests that a cancelled lesson is marked as cancelled.
   */
  public function testCancelledLesson(): void {
    $this->lessons[0]->set('field_cancelled', TRUE);
    $this->lessons[0]->save();

    $calendar = $this->readCalendar('/termine.ics?course=' . $this->course->id());
    $events = $calendar->select('VEVENT');
    self::assertCount(4, $events);
    $cancelled = $events[0];
    self::assertSame('CANCELLED', $cancelled->STATUS?->getValue());
    self::assertSame('CONFIRMED', $events[1]->STATUS?->getValue());
  }

  /**
   * Tests the exclusive end date of all-day occurrences.
   *
   * The format needs the day after the last day for whole-day events, so a
   * multi-day event must not be reduced to its first day.
   */
  public function testAllDayEnd(): void {
    $calendar = $this->readCalendar('/termine.ics');
    $events = $this->eventsByNodeId($calendar);

    $multi_day = $events[$this->multiDayId];
    self::assertSame('DATE', $multi_day->DTSTART['VALUE']?->getValue());
    self::assertSame('DATE', $multi_day->DTEND['VALUE']?->getValue());
    self::assertSame($this->multiDayStart->format('Ymd'), $multi_day->DTSTART->getValue());
    self::assertSame(
      $this->multiDayLastDay->modify('+1 day')->format('Ymd'),
      $multi_day->DTEND->getValue(),
    );

    // A single day still ends on the following day.
    $single_day = $events[$this->allDayId];
    self::assertSame('DATE', $single_day->DTEND['VALUE']?->getValue());
    self::assertSame(
      $single_day->DTSTART->getDateTime()->modify('+1 day')->format('Ymd'),
      $single_day->DTEND->getValue(),
    );
  }

  /**
   * Tests the response cache metadata.
   */
  public function testCacheMetadata(): void {
    $response = $this->request('/termine.ics?type=party&past=14');
    self::assertSame('text/calendar; charset=utf-8', $response->headers->get('Content-Type'));
    self::assertSame('inline; filename="salsa-am-sund.ics"', $response->headers->get('Content-Disposition'));

    $cacheability = $response->getCacheableMetadata();
    self::assertContains('node_list:event', $cacheability->getCacheTags());
    self::assertContains('node_list:course', $cacheability->getCacheTags());
    self::assertContains('node_list:lesson', $cacheability->getCacheTags());
    self::assertContains('taxonomy_term_list:location', $cacheability->getCacheTags());
    self::assertContains('taxonomy_term_list:organizer', $cacheability->getCacheTags());
    self::assertContains('url.query_args:type', $cacheability->getCacheContexts());
    self::assertContains('url.query_args:organizer', $cacheability->getCacheContexts());
    self::assertContains('url.query_args:location', $cacheability->getCacheContexts());
    self::assertContains('url.query_args:course', $cacheability->getCacheContexts());
    self::assertContains('url.query_args:past', $cacheability->getCacheContexts());
    self::assertSame(3600, $cacheability->getCacheMaxAge());
  }

  /**
   * Tests the past window boundaries.
   */
  public function testPastWindow(): void {
    $calendar = $this->readCalendar('/termine.ics?past=0');
    $past_found = FALSE;
    foreach ($calendar->select('VEVENT') as $event) {
      if (str_contains((string) $event->UID->getValue(), 'event-' . $this->pastId . '@')) {
        $past_found = TRUE;
      }
    }
    self::assertFalse($past_found);

    $calendar = $this->readCalendar('/termine.ics?past=10');
    $past_found = FALSE;
    foreach ($calendar->select('VEVENT') as $event) {
      if (str_contains((string) $event->UID->getValue(), 'event-' . $this->pastId . '@')) {
        $past_found = TRUE;
      }
    }
    self::assertTrue($past_found);
  }

  /**
   * Requests the feed and parses it.
   *
   * @param string $uri
   *   The request URI.
   *
   * @return \Sabre\VObject\Component\VCalendar
   *   The parsed calendar.
   */
  private function readCalendar(string $uri): VCalendar {
    $response = $this->request($uri);
    $content = $response->getContent();
    self::assertIsString($content);
    $calendar = Reader::read($content);
    self::assertInstanceOf(VCalendar::class, $calendar);
    return $calendar;
  }

  /**
   * Performs a feed request.
   *
   * @param string $uri
   *   The request URI.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The response.
   */
  private function request(string $uri): CacheableResponse {
    return $this->controller->__invoke(Request::create('https://salsa-am-sund.localhost' . $uri));
  }

  /**
   * Indexes the calendar events by their node ID.
   *
   * @param \Sabre\VObject\Component\VCalendar $calendar
   *   The calendar.
   *
   * @return \Sabre\VObject\Component\VEvent[]
   *   The events, keyed by node ID.
   */
  private function eventsByNodeId(VCalendar $calendar): array {
    $events = [];
    foreach ($calendar->select('VEVENT') as $event) {
      $events[(int) preg_replace('/^event-(\d+)@.*$/', '$1', (string) $event->UID->getValue())] = $event;
    }
    return $events;
  }

  /**
   * Asserts that the calendar has no validation errors.
   *
   * @param \Sabre\VObject\Component\VCalendar $calendar
   *   The calendar.
   */
  private function assertNoValidationErrors(VCalendar $calendar): void {
    $errors = array_filter(
      $calendar->validate(),
      static fn (array $warning): bool => ($warning['level'] ?? 0) === 3,
    );
    self::assertSame([], $errors, 'The iCal output contains validation errors.');
  }

}
