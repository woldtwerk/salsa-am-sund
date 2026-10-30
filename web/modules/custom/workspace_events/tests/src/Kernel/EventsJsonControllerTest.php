<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Controller\EventsJsonController;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\workspace_events\LessonCourseResolver;
use Drupal\workspace_events\SiteTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the JSON endpoint of the calendar.
 */
#[CoversClass(EventsJsonController::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventsJsonControllerTest extends LessonKernelTestBase {

  /**
   * The JSON controller.
   */
  private EventsJsonController $controller;

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

    $repository = $this->container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $filter_factory = $this->container->get(EventFilterFactory::class);
    \assert($filter_factory instanceof EventFilterFactory);
    $this->controller = new EventsJsonController($repository, $filter_factory);

    $location_a = $this->createLocation('Bootshaus Stralsund');
    $location_b = $this->createLocation('Treff ZuverSICht');
    $this->locationA = (int) $location_a->id();
    $this->locationB = (int) $location_b->id();

    $start = $this->date('now +2 days', '18:15');
    $this->course = $this->createCourse([
      'title' => 'Anfänger',
      'field_location' => $this->locationA,
      ...$this->courseSchedule($start, $start->modify('+3 weeks')),
    ]);
    $this->lessons = $this->loadCourseLessons($this->course);

    $this->createEvent([
      'title' => 'Salsa-Nacht am Sund',
      'field_location' => $this->locationA,
      'field_link' => ['uri' => 'https://www.ballhaus-goldfisch.de/', 'title' => 'ballhaus goldfisch'],
      'field_event_date' => $this->dateRange(
        $this->date('now +3 days', '18:00'),
        $this->date('now +3 days', '22:00'),
      ),
    ]);
    $this->createEvent([
      'title' => 'Tanzabend im Freien',
      'field_location' => $this->locationB,
      'field_event_date' => $this->allDayRange($this->date('now +10 days')),
    ]);
    $this->createEvent([
      'title' => 'Externe Party',
      'field_organizer' => $this->externalOrganizer,
      'field_location' => $this->locationA,
      'field_event_date' => $this->dateRange(
        $this->date('now +5 days', '20:00'),
        $this->date('now +5 days', '23:00'),
      ),
    ]);
    $this->createEvent([
      'title' => 'Entwurf',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_event_date' => $this->dateRange(
        $this->date('now +4 days', '18:00'),
        $this->date('now +4 days', '22:00'),
      ),
    ]);
  }

  /**
   * Tests events, all-day events, course lessons and a cancellation.
   */
  public function testContent(): void {
    $items = $this->items($this->range());
    self::assertCount(7, $items);

    $event = $this->findByTitle($items, 'Salsa-Nacht am Sund');
    $start = $this->date('now +3 days', '18:00');
    self::assertSame('Salsa-Nacht am Sund', $event['title']);
    self::assertMatchesRegularExpression('/^\d+-\d+$/', $event['id']);
    self::assertSame($start->format(\DateTimeInterface::ATOM), $event['start']);
    self::assertSame($start->setTime(22, 0)->format(\DateTimeInterface::ATOM), $event['end']);
    self::assertFalse($event['allDay']);
    self::assertSame('https://www.ballhaus-goldfisch.de/', $event['url']);
    self::assertSame(['is-event'], $event['classNames']);
    self::assertSame('Bootshaus Stralsund', $event['extendedProps']['location']);
    self::assertSame('Salsa am Sund', $event['extendedProps']['organizer']);
    self::assertFalse($event['extendedProps']['course']);
    self::assertFalse($event['extendedProps']['cancelled']);
    self::assertSame('Sonstige', $this->findByTitle($items, 'Externe Party')['extendedProps']['organizer']);

    $all_day = $this->findByTitle($items, 'Tanzabend im Freien');
    $day = $this->date('now +10 days');
    self::assertTrue($all_day['allDay']);
    self::assertSame($day->format('Y-m-d'), $all_day['start']);
    self::assertSame($day->modify('+1 day')->format('Y-m-d'), $all_day['end']);
    self::assertSame('Treff ZuverSICht', $all_day['extendedProps']['location']);

    $items = $this->items($this->range() + ['type' => 'course']);
    self::assertCount(4, $items);
    $starts = [];
    foreach ($items as $index => $item) {
      self::assertSame('Anfänger', $item['title']);
      self::assertSame(['is-course'], $item['classNames']);
      self::assertTrue($item['extendedProps']['course']);
      self::assertFalse($item['extendedProps']['cancelled']);
      self::assertNull($item['extendedProps']['organizer']);
      self::assertSame('Bootshaus Stralsund', $item['extendedProps']['location']);
      self::assertStringStartsWith($this->lessons[$index]->id() . '-', $item['id']);
      $starts[] = $item['start'];
    }
    self::assertCount(4, array_unique($starts));

    $this->lessons[1]->set('field_cancelled', TRUE);
    $this->lessons[1]->save();
    // The repository memoizes its results, so a fresh request is needed to see
    // the changed lesson.
    $repository = new EventOccurrenceRepository(
      $this->container->get('entity_type.manager'),
      $this->container->get(SiteTimeZone::class),
      $this->container->get(LessonCourseResolver::class),
    );
    $this->controller = new EventsJsonController(
      $repository,
      $this->container->get(EventFilterFactory::class),
    );
    $items = $this->items($this->range() + ['type' => 'course']);
    self::assertCount(4, $items);
    self::assertFalse($items[0]['extendedProps']['cancelled']);
    self::assertTrue($items[1]['extendedProps']['cancelled']);
    self::assertContains('is-cancelled', $items[1]['classNames']);
    self::assertNotContains('is-cancelled', $items[0]['classNames']);
  }

  /**
   * Tests the OR filter and the response cache metadata.
   */
  public function testOrFilterAndCacheMetadata(): void {
    $items = $this->items($this->range() + [
      'organizer' => (string) $this->externalOrganizer,
      'course' => (string) $this->course->id(),
    ]);
    self::assertSame(
      ['Anfänger', 'Externe Party', 'Anfänger', 'Anfänger', 'Anfänger'],
      array_column($items, 'title'),
    );

    $response = $this->request($this->range());
    self::assertInstanceOf(CacheableJsonResponse::class, $response);
    $cacheability = $response->getCacheableMetadata();
    foreach ([
      'url.query_args:start',
      'url.query_args:end',
      'url.query_args:organizer',
      'url.query_args:location',
      'url.query_args:course',
      'url.query_args:type',
    ] as $context) {
      self::assertContains($context, $cacheability->getCacheContexts());
    }
    foreach ([
      'node_list:event',
      'node_list:course',
      'node_list:lesson',
      'taxonomy_term_list:location',
      'taxonomy_term_list:organizer',
      'taxonomy_term:' . $this->locationA,
      'taxonomy_term:' . $this->locationB,
    ] as $tag) {
      self::assertContains($tag, $cacheability->getCacheTags());
    }
    self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
  }

  /**
   * Returns a range that covers the test fixtures.
   *
   * @return array<string, string>
   *   The start and end query arguments.
   */
  private function range(): array {
    return [
      'start' => $this->date('now -1 day')->format(\DateTimeInterface::ATOM),
      'end' => $this->date('now +30 days')->format(\DateTimeInterface::ATOM),
    ];
  }

  /**
   * Requests the endpoint and returns the decoded items.
   *
   * @param array<string, string> $query
   *   The query arguments.
   *
   * @return array<int, array<string, mixed>>
   *   The decoded events.
   */
  private function items(array $query): array {
    $response = $this->request($query);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $items = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($items);
    return array_values($items);
  }

  /**
   * Performs a request against the controller.
   *
   * @param array<string, string> $query
   *   The query arguments.
   */
  private function request(array $query): Response {
    return $this->controller->__invoke(
      Request::create('https://salsa-am-sund.localhost/termine.json', 'GET', $query),
    );
  }

  /**
   * Finds an item by title.
   *
   * @param array<int, array<string, mixed>> $items
   *   The decoded events.
   * @param string $title
   *   The title.
   *
   * @return array<string, mixed>
   *   The item.
   */
  private function findByTitle(array $items, string $title): array {
    foreach ($items as $item) {
      if ($item['title'] === $title) {
        return $item;
      }
    }
    self::fail('No event with title ' . $title);
  }

}
