<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\DetailFieldResolver;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\Entity\SeasonInterface;
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
final class StructuredDataBuilderTest extends LessonKernelTestBase {

  /**
   * The structured data builder.
   */
  private StructuredDataBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $builder = $this->container->get(StructuredDataBuilder::class);
    \assert($builder instanceof StructuredDataBuilder);
    $this->builder = $builder;
  }

  /**
   * Tests the structured data of events.
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
      'field_event_date' => $this->smartDateRange($start, $end),
    ]);

    $organizer = $event->get('field_organizer')->entity;
    \assert($organizer instanceof TermInterface);
    $location_term = $event->get('field_location')->entity;
    \assert($location_term instanceof TermInterface);
    $data = $this->builder->buildEvent($event, [
      new EventOccurrence($event, NULL, $start, $end, FALSE, $organizer, $location_term),
    ]);
    self::assertSame('https://schema.org', $data['@context']);
    $events = $data['@graph'];
    \assert(is_array($events));
    self::assertCount(1, $events);
    $url = $event->toUrl('canonical', ['absolute' => TRUE])->toString();
    $first = $events[0];
    \assert(is_array($first));
    self::assertSame('Event', $first['@type']);
    self::assertSame($url . '#event-' . $event->id() . '-' . $start->getTimestamp(), $first['@id']);
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

    $day = $this->date('next saturday', '00:00');
    $all_day = $this->createEvent([
      'title' => 'Tanzfest',
      'field_event_date' => $this->allDaySmartDateRange($day),
    ]);
    $data = $this->builder->buildEvent($all_day, [
      new EventOccurrence($all_day, NULL, $day, NULL, TRUE, NULL, NULL),
    ]);
    $first = $data['@graph'][0];
    \assert(is_array($first));
    self::assertSame($day->format('Y-m-d'), $first['startDate']);
    self::assertArrayNotHasKey('endDate', $first);
    self::assertArrayNotHasKey('location', $first);
    self::assertArrayNotHasKey('organizer', $first);

    $past = $this->createEvent(['title' => 'Vergangene Party']);
    self::assertSame([], $this->builder->buildEvent($past, []));
  }

  /**
   * Tests the structured data of courses.
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
      'season_location' => $location->id(),
      'body' => [
        'value' => '<p>Neue Figuren und Elemente für Fortgeschrittene.</p>',
        'format' => 'plain_text',
      ],
      ...$this->courseSchedule($start, $start->modify('+1 week'), 1, $end),
    ]);
    $lessons = $this->loadCourseLessons($course);
    self::assertCount(2, $lessons);
    $season = $this->loadSeason($course);
    $season->set('field_prices', [
      ['category' => 'normal', 'amount' => '12.00'],
      ['category' => 'student', 'amount' => '10.00'],
    ]);
    $season->save();
    $location_term = $season->get('location')->entity;
    \assert($location_term instanceof TermInterface);
    $occurrences = [];
    foreach ($lessons as $lesson) {
      $occurrences[] = $this->lessonOccurrence($course, $lesson, $location_term, $season);
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
    // A location without a map link falls back to an address search.
    self::assertSame(
      'https://www.openstreetmap.org/search?query=' . rawurlencode("Friedrich-Naumann-Straße 5, 18435 Stralsund"),
      $place['hasMap'],
    );

    $offers = $first['offers'];
    \assert(is_array($offers));
    self::assertCount(2, $offers);
    self::assertSame('Offer', $offers[0]['@type']);
    self::assertSame('Normal', $offers[0]['category']);
    self::assertSame('EUR', $offers[0]['priceCurrency']);
    self::assertSame('12.00', $offers[0]['price']);
    self::assertSame('UnitPriceSpecification', $offers[0]['priceSpecification']['@type']);
    self::assertSame('12.00', $offers[0]['priceSpecification']['price']);
    self::assertSame('EUR', $offers[0]['priceSpecification']['priceCurrency']);
    self::assertSame([
      '@type' => 'QuantitativeValue',
      'value' => 1,
      'unitText' => 'lesson',
    ], $offers[0]['priceSpecification']['referenceQuantity']);
    self::assertSame('Student', $offers[1]['category']);
    self::assertSame('10.00', $offers[1]['price']);

    $empty = $this->createCourse(['title' => 'Anfänger']);
    $course_data = $this->builder->buildCourse($empty, [])['@graph'][0];
    \assert(is_array($course_data));
    self::assertSame('Course', $course_data['@type']);
    self::assertArrayNotHasKey('hasCourseInstance', $course_data);

    $unsaved = $this->nodeStorage->create(['type' => 'course', 'title' => 'Neuer Kurs']);
    \assert($unsaved->isNew());
    self::assertSame([], $this->builder->buildCourse($unsaved, []));

    $provider_term->delete();
    $without_provider = $this->createCourse(['title' => 'Anfänger']);
    // The builder caches the provider, so use a fresh instance after deleting
    // the term.
    $builder = new StructuredDataBuilder(
      $this->container->get(EventDescriptionFormatter::class),
      $this->container->get(DetailFieldResolver::class),
      new OrganizerResolver($this->container->get('entity_type.manager')),
      $this->container->get('entity_type.manager'),
    );
    $course_data = $builder->buildCourse($without_provider, [])['@graph'][0];
    \assert(is_array($course_data));
    self::assertArrayNotHasKey('provider', $course_data);
  }

  /**
   * Builds an occurrence from a generated lesson.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   * @param \Drupal\taxonomy\TermInterface|null $location
   *   The location of the course.
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season of the course.
   *
   * @return \Drupal\workspace_events\EventOccurrence
   *   The occurrence.
   */
  private function lessonOccurrence(NodeInterface $course, LessonInterface $lesson, ?TermInterface $location, SeasonInterface $season): EventOccurrence {
    $item = $lesson->get('field_lesson_date')->first();
    self::assertNotNull($item);
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $utc = new \DateTimeZone('UTC');
    $start = (new \DateTimeImmutable($item->get('value')->getString(), $utc))->setTimezone($timezone);
    $end = (new \DateTimeImmutable($item->get('end_value')->getString(), $utc))->setTimezone($timezone);
    return new EventOccurrence($course, $lesson, $start, $end, FALSE, NULL, $location, FALSE, $season);
  }

}
