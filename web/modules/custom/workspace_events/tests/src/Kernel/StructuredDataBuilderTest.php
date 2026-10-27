<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\OrganizerResolver;
use Drupal\workspace_events\StructuredDataBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the schema.org JSON-LD of course and event detail pages.
 */
#[CoversClass(StructuredDataBuilder::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class StructuredDataBuilderTest extends EventKernelTestBase {

  /**
   * The structured data builder.
   */
  private StructuredDataBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installLessonConfig();

    // The base class creates the fixed terms; guard against a configuration
    // where only the content model is reused without them.
    $provider = reset($this->termStorage->loadByProperties([
      'uuid' => OrganizerResolver::SALSA_AM_SUND_UUID,
    ]));
    if (!$provider instanceof TermInterface) {
      Term::create([
        'uuid' => OrganizerResolver::SALSA_AM_SUND_UUID,
        'vid' => OrganizerResolver::VOCABULARY,
        'name' => OrganizerResolver::TERMS[OrganizerResolver::SALSA_AM_SUND_UUID]['name'],
      ])->save();
    }

    $builder = $this->container->get(StructuredDataBuilder::class);
    \assert($builder instanceof StructuredDataBuilder);
    $this->builder = $builder;
  }

  /**
   * Tests the structured data of an event.
   */
  public function testEvent(): void {
    $start = $this->date('next wednesday', '21:00');
    $end = $this->date('next wednesday', '23:30');
    $location = $this->createLocation(
      'Kulturhaus Stralsund',
      "Olof-Palme-Platz 6\n18439 Stralsund",
      'https://www.openstreetmap.org/search?query=Olof-Palme-Platz%206',
    );
    $event = $this->createEvent([
      'title' => 'Salsa Night',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $location->id(),
      'field_link' => [
        'uri' => 'https://example.com/party',
        'title' => 'Tickets',
      ],
      'body' => [
        'value' => '<p>Jeden Mittwoch Tanzen bis Mitternacht.</p>',
        'format' => 'plain_text',
      ],
      'field_event_date' => $this->dateRange($start, $end),
    ]);

    $organizer = $event->get('field_organizer')->entity;
    \assert($organizer instanceof TermInterface);
    $location_term = $event->get('field_location')->entity;
    \assert($location_term instanceof TermInterface);
    $occurrences = [
      new EventOccurrence($event, NULL, $start, $end, FALSE, $organizer, $location_term),
    ];

    $data = $this->builder->buildEvent($event, $occurrences);
    self::assertSame('https://schema.org', $data['@context']);

    $events = $data['@graph'];
    \assert(is_array($events));
    self::assertCount(1, $events);

    $url = $event->toUrl('canonical', ['absolute' => TRUE])->toString();
    $first = $events[0];
    \assert(is_array($first));
    self::assertSame('Event', $first['@type']);
    self::assertSame($url . '#event-' . $event->id(), $first['@id']);
    self::assertSame('Salsa Night', $first['name']);
    self::assertSame($url, $first['url']);
    self::assertSame($start->format(\DateTimeInterface::ATOM), $first['startDate']);
    self::assertSame($end->format(\DateTimeInterface::ATOM), $first['endDate']);
    self::assertSame('https://schema.org/EventScheduled', $first['eventStatus']);
    self::assertSame('https://schema.org/OfflineEventAttendanceMode', $first['eventAttendanceMode']);
    self::assertSame('Jeden Mittwoch Tanzen bis Mitternacht.', $first['description']);
    self::assertSame('https://example.com/party', $first['sameAs']);

    $place = $first['location'];
    \assert(is_array($place));
    self::assertSame('Place', $place['@type']);
    self::assertSame('Kulturhaus Stralsund', $place['name']);
    self::assertSame('https://www.openstreetmap.org/search?query=Olof-Palme-Platz%206', $place['hasMap']);
    self::assertSame([
      '@type' => 'PostalAddress',
      'streetAddress' => 'Olof-Palme-Platz 6',
      'postalCode' => '18439',
      'addressLocality' => 'Stralsund',
      'addressCountry' => 'DE',
    ], $place['address']);

    $event_organizer = $first['organizer'];
    \assert(is_array($event_organizer));
    self::assertSame('Organization', $event_organizer['@type']);
    self::assertSame('Sonstige', $event_organizer['name']);
    self::assertArrayNotHasKey('url', $event_organizer);
  }

  /**
   * Tests an all-day event without a known end.
   */
  public function testAllDayEventWithoutEnd(): void {
    $day = $this->date('next saturday', '00:00');
    $event = $this->createEvent([
      'title' => 'Tanzfest',
      'field_event_date' => $this->allDayRange($day),
    ]);

    $occurrences = [
      new EventOccurrence($event, NULL, $day, NULL, TRUE, NULL, NULL),
    ];
    $data = $this->builder->buildEvent($event, $occurrences);

    $events = $data['@graph'];
    \assert(is_array($events));
    $first = $events[0];
    \assert(is_array($first));
    self::assertSame($day->format('Y-m-d'), $first['startDate']);
    self::assertArrayNotHasKey('endDate', $first);
    self::assertArrayNotHasKey('location', $first);
    self::assertArrayNotHasKey('organizer', $first);
  }

  /**
   * Tests that past events have no structured data.
   */
  public function testEventWithoutOccurrences(): void {
    $event = $this->createEvent(['title' => 'Vergangene Party']);

    self::assertSame([], $this->builder->buildEvent($event, []));
  }

  /**
   * Tests the structured data of a course with one instance per lesson.
   */
  public function testCourse(): void {
    $start = $this->date('next monday', '19:30');
    $end = $this->date('next monday', '20:30');
    $location = $this->createLocation(
      'Bootshaus Stralsund',
      "Friedrich-Naumann-Straße 5\n18435 Stralsund",
    );
    $course = $this->createCourse([
      'title' => 'Leicht Fortgeschrittene',
      'field_difficulty' => [2],
      'field_location' => $location->id(),
      'body' => [
        'value' => '<p>Neue Figuren und Elemente für Fortgeschrittene.</p>',
        'format' => 'plain_text',
      ],
      ...$this->courseSchedule($start, $start->modify('+1 week'), 1, $end),
    ]);
    $lessons = $this->loadCourseLessons($course);
    self::assertCount(2, $lessons);

    $location_term = $course->get('field_location')->entity;
    \assert($location_term instanceof TermInterface);
    $occurrences = [];
    foreach ($lessons as $lesson) {
      $occurrences[] = $this->lessonOccurrence($course, $lesson, $location_term);
    }

    $data = $this->builder->buildCourse($course, $occurrences);
    self::assertSame('https://schema.org', $data['@context']);

    $graph = $data['@graph'];
    \assert(is_array($graph));
    self::assertCount(1, $graph);

    $course_data = $graph[0];
    \assert(is_array($course_data));
    $url = $course->toUrl('canonical', ['absolute' => TRUE])->toString();
    self::assertSame('Course', $course_data['@type']);
    self::assertSame($url . '#course', $course_data['@id']);
    self::assertSame('Leicht Fortgeschrittene', $course_data['name']);
    self::assertSame($url, $course_data['url']);
    self::assertSame('Neue Figuren und Elemente für Fortgeschrittene.', $course_data['description']);

    $provider = $course_data['provider'];
    \assert(is_array($provider));
    $provider_term = $this->termStorage->load($this->salsaAmSund);
    \assert($provider_term instanceof TermInterface);
    self::assertSame('Organization', $provider['@type']);
    self::assertSame((string) $provider_term->label(), $provider['name']);
    self::assertStringStartsWith('http', $provider['url']);

    $instances = $course_data['hasCourseInstance'];
    \assert(is_array($instances));
    self::assertCount(2, $instances);
    $first = $instances[0];
    \assert(is_array($first));
    self::assertSame('CourseInstance', $first['@type']);
    self::assertSame('onsite', $first['courseMode']);
    self::assertSame($start->format(\DateTimeInterface::ATOM), $first['startDate']);
    self::assertSame($end->format(\DateTimeInterface::ATOM), $first['endDate']);

    $place = $first['location'];
    \assert(is_array($place));
    self::assertSame('Bootshaus Stralsund', $place['name']);
    self::assertSame([
      '@type' => 'PostalAddress',
      'streetAddress' => 'Friedrich-Naumann-Straße 5',
      'postalCode' => '18435',
      'addressLocality' => 'Stralsund',
      'addressCountry' => 'DE',
    ], $place['address']);
  }

  /**
   * Tests a course without upcoming dates.
   */
  public function testCourseWithoutOccurrences(): void {
    $course = $this->createCourse(['title' => 'Anfänger']);

    $data = $this->builder->buildCourse($course, []);
    $graph = $data['@graph'];
    \assert(is_array($graph));
    $course_data = $graph[0];
    \assert(is_array($course_data));
    self::assertSame('Course', $course_data['@type']);
    self::assertArrayNotHasKey('hasCourseInstance', $course_data);
  }

  /**
   * Tests that a location without a map link falls back to the address search.
   */
  public function testLocationAddressFallbackMap(): void {
    $start = $this->date('next friday', '20:00');
    $address = "Friedrich-Naumann-Straße 5\n18435 Stralsund";
    $location = $this->createLocation('Bootshaus Stralsund', $address);
    $event = $this->createEvent([
      'title' => 'Tanzabend',
      'field_location' => $location->id(),
      'field_event_date' => $this->dateRange($start, $start->modify('+2 hours')),
    ]);

    $occurrences = [
      new EventOccurrence($event, NULL, $start, $start->modify('+2 hours'), FALSE, NULL, $location),
    ];
    $data = $this->builder->buildEvent($event, $occurrences);

    $events = $data['@graph'];
    \assert(is_array($events));
    $first = $events[0];
    \assert(is_array($first));
    $place = $first['location'];
    \assert(is_array($place));
    self::assertSame(
      'https://www.openstreetmap.org/search?query=' . rawurlencode("Friedrich-Naumann-Straße 5, 18435 Stralsund"),
      $place['hasMap'],
    );
  }

  /**
   * Tests that a course without the provider term has no provider.
   */
  public function testCourseWithoutProvider(): void {
    $provider = $this->termStorage->load($this->salsaAmSund);
    \assert($provider instanceof TermInterface);
    $provider->delete();

    $course = $this->createCourse(['title' => 'Anfänger']);

    $data = $this->builder->buildCourse($course, []);
    $graph = $data['@graph'];
    \assert(is_array($graph));
    $course_data = $graph[0];
    \assert(is_array($course_data));
    self::assertArrayNotHasKey('provider', $course_data);
  }

  /**
   * Tests that an unsaved course has no structured data.
   */
  public function testNewCourseHasNoStructuredData(): void {
    $course = $this->nodeStorage->create([
      'type' => 'course',
      'title' => 'Neuer Kurs',
    ]);
    \assert($course->isNew());

    self::assertSame([], $this->builder->buildCourse($course, []));
  }

  /**
   * Builds an occurrence from a generated lesson.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   * @param \Drupal\taxonomy\TermInterface|null $location
   *   The location of the course.
   *
   * @return \Drupal\workspace_events\EventOccurrence
   *   The occurrence.
   */
  private function lessonOccurrence(NodeInterface $course, NodeInterface $lesson, ?TermInterface $location): EventOccurrence {
    $item = $lesson->get('field_lesson_date')->first();
    self::assertNotNull($item);
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $utc = new \DateTimeZone('UTC');
    $start = (new \DateTimeImmutable($item->get('value')->getString(), $utc))->setTimezone($timezone);
    $end = (new \DateTimeImmutable($item->get('end_value')->getString(), $utc))->setTimezone($timezone);

    return new EventOccurrence($course, $lesson, $start, $end, FALSE, NULL, $location);
  }

}
