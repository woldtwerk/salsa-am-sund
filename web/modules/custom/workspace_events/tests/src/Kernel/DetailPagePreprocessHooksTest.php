<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Hook\DetailPagePreprocessHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Tests the data of the course and event detail pages.
 */
#[CoversClass(DetailPagePreprocessHooks::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class DetailPagePreprocessHooksTest extends EventKernelTestBase {

  /**
   * The detail page preprocess hooks.
   */
  private DetailPagePreprocessHooks $hooks;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $hooks = $this->container->get(DetailPagePreprocessHooks::class);
    \assert($hooks instanceof DetailPagePreprocessHooks);
    $this->hooks = $hooks;
  }

  /**
   * Tests the prepared data of a full course page.
   */
  public function testPreprocessFullCourse(): void {
    $start = $this->date('next monday', '19:30');
    $end = $this->date('next monday', '20:30');
    $deltas = $this->recurringDeltas(
      $start,
      $end,
      'WEEKLY',
      'INTERVAL=1;BYDAY=' . strtoupper(substr($start->format('D'), 0, 2)),
      '',
      'course',
    );

    $location = $this->createLocation(
      'Bootshaus Stralsund',
      "Friedrich-Naumann-Straße 5\n18435 Stralsund",
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
    );
    $course = $this->createCourse([
      'title' => 'Leicht Fortgeschrittene',
      'field_difficulty' => [2],
      'field_location' => $location->id(),
      'field_link' => [
        'uri' => 'https://example.com/kurs',
        'title' => 'Kurs buchen',
      ],
      'body' => [
        'value' => '<p>Neue Figuren und Elemente für Fortgeschrittene.</p>',
        'format' => 'plain_text',
      ],
      'field_when' => $deltas,
    ]);

    $this->setRoute('entity.node.canonical', $course);

    $variables = [
      'node' => $course,
      'view_mode' => 'full',
    ];
    $this->hooks->preprocessNode($variables);

    $date_formatter = $this->container->get('date.formatter');
    \assert($date_formatter instanceof DateFormatterInterface);
    $weekday = $date_formatter->format(
      $start->getTimestamp(),
      'custom',
      'l',
      $start->getTimezone()->getName(),
    );

    self::assertSame('Level 2', $variables['course_level']);
    self::assertSame($weekday . ' · 19:30 – 20:30', $variables['course_schedule']);

    $dates = $variables['course_next_dates'];
    \assert(is_array($dates));
    self::assertCount(6, $dates);

    $expected_dates = [];
    for ($week = 0; $week < 6; $week++) {
      $expected_dates[] = $start->modify('+' . $week . ' weeks')->format('Y-m-d');
    }
    self::assertSame($expected_dates, array_column($dates, 'date_iso'));
    self::assertSame(
      '/termine/' . $course->id() . '/0/ical',
      $dates[0]['ical_url'],
    );
    foreach ($dates as $index => $date) {
      \assert(is_array($date));
      self::assertSame($weekday, $date['weekday']);
      self::assertSame('19:30 – 20:30', $date['time']);
      self::assertSame($start->modify('+' . $index . ' weeks')->format('d.m.Y'), $date['date']);
    }

    self::assertStringStartsWith('webcal://', $variables['course_ical_url']);
    self::assertStringEndsWith(
      '/termine.ics?course=' . $course->id(),
      $variables['course_ical_url'],
    );
    self::assertSame('/salsa-kurse', $variables['course_overview_url']);

    $location_data = $variables['course_location'];
    \assert(is_array($location_data));
    self::assertSame('Bootshaus Stralsund', $location_data['name']);
    self::assertSame("Friedrich-Naumann-Straße 5\n18435 Stralsund", $location_data['address']);
    self::assertSame(
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
      $location_data['map_url'],
    );

    $link = $variables['course_link'];
    \assert(is_array($link));
    self::assertSame('Kurs buchen', $link['label']);
    self::assertSame('https://example.com/kurs', $link['href']);

    $cache = $variables['#cache'];
    \assert(is_array($cache));
    self::assertContains('node:' . $course->id(), $cache['tags']);
    self::assertContains('taxonomy_term:' . $location->id(), $cache['tags']);
    // The provider of the course is the "Salsa am Sund" organizer term.
    self::assertContains('taxonomy_term:' . $this->salsaAmSund, $cache['tags']);
    self::assertContains('config:filter.format.plain_text', $cache['tags']);
    self::assertContains('url.site', $cache['contexts']);
    self::assertContains('route', $cache['contexts']);

    // The page may be cached until the next date has ended.
    $request_time = $this->container->get('datetime.time')->getRequestTime();
    self::assertSame($deltas[0]['end_value'] - $request_time, $cache['max-age']);

    $structured_data = $this->getAttachedJsonLd($variables);
    self::assertSame('https://schema.org', $structured_data['@context']);
    $graph = $structured_data['@graph'];
    \assert(is_array($graph));
    self::assertSame('Course', $graph[0]['@type']);
  }

  /**
   * Tests the difficulty labels of courses with several levels.
   */
  public function testCourseDifficultyLabels(): void {
    $cases = [
      [[3, 1, 2], 'Level 1–3'],
      [[2, 1], 'Level 1 + 2'],
      [[0, 2], 'Level 0 + 2'],
    ];
    foreach ($cases as [$values, $label]) {
      $course = $this->createCourse([
        'title' => 'Kurs ' . $label,
        'field_difficulty' => $values,
      ]);
      $variables = [
        'node' => $course,
        'view_mode' => 'full',
      ];
      $this->hooks->preprocessNode($variables);
      self::assertSame($label, $variables['course_level']);
    }
  }

  /**
   * Tests the prepared data of a full event page.
   */
  public function testPreprocessFullEvent(): void {
    $start = $this->date('next wednesday', '21:00');
    $end = $this->date('next wednesday', '23:30');
    $deltas = $this->recurringDeltas(
      $start,
      $end,
      'WEEKLY',
      'INTERVAL=1;BYDAY=' . strtoupper(substr($start->format('D'), 0, 2)),
      '',
      'event',
    );

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
      'field_when' => $deltas,
    ]);

    $this->setRoute('entity.node.canonical', $event);

    $variables = [
      'node' => $event,
      'view_mode' => 'full',
    ];
    $this->hooks->preprocessNode($variables);

    $date_formatter = $this->container->get('date.formatter');
    \assert($date_formatter instanceof DateFormatterInterface);
    $weekday = $date_formatter->format(
      $start->getTimestamp(),
      'custom',
      'l',
      $start->getTimezone()->getName(),
    );

    self::assertSame('Sonstige', $variables['event_organizer']);

    $next_date = $variables['event_next_date'];
    \assert(is_array($next_date));
    self::assertSame([
      'date' => $start->format('d.m.Y'),
      'date_iso' => $start->format('Y-m-d'),
      'weekday' => $weekday,
      'time' => '21:00 – 23:30',
      'ical_url' => '/termine/' . $event->id() . '/0/ical',
    ], $next_date);

    $dates = $variables['event_next_dates'];
    \assert(is_array($dates));
    self::assertCount(6, $dates);

    $expected_dates = [];
    for ($week = 0; $week < 6; $week++) {
      $expected_dates[] = $start->modify('+' . $week . ' weeks')->format('Y-m-d');
    }
    self::assertSame($expected_dates, array_column($dates, 'date_iso'));
    foreach ($dates as $index => $date) {
      \assert(is_array($date));
      self::assertSame($weekday, $date['weekday']);
      self::assertSame('21:00 – 23:30', $date['time']);
      self::assertSame($start->modify('+' . $index . ' weeks')->format('d.m.Y'), $date['date']);
    }

    self::assertStringStartsWith('webcal://', $variables['event_ical_url']);
    self::assertStringEndsWith(
      '/termine.ics?organizer=' . $this->externalOrganizer,
      $variables['event_ical_url'],
    );
    self::assertSame('/veranstaltungen', $variables['event_overview_url']);

    $location_data = $variables['event_location'];
    \assert(is_array($location_data));
    self::assertSame('Kulturhaus Stralsund', $location_data['name']);
    self::assertSame("Olof-Palme-Platz 6\n18439 Stralsund", $location_data['address']);
    self::assertSame(
      'https://www.openstreetmap.org/search?query=Olof-Palme-Platz%206',
      $location_data['map_url'],
    );

    $link = $variables['event_link'];
    \assert(is_array($link));
    self::assertSame('Tickets', $link['label']);
    self::assertSame('https://example.com/party', $link['href']);

    self::assertArrayNotHasKey('course_next_dates', $variables);

    $cache = $variables['#cache'];
    \assert(is_array($cache));
    self::assertContains('node:' . $event->id(), $cache['tags']);
    self::assertContains('taxonomy_term:' . $location->id(), $cache['tags']);
    self::assertContains('taxonomy_term:' . $this->externalOrganizer, $cache['tags']);
    self::assertContains('config:filter.format.plain_text', $cache['tags']);
    self::assertContains('url.site', $cache['contexts']);
    self::assertContains('route', $cache['contexts']);

    // The page may be cached until the next date has ended.
    $request_time = $this->container->get('datetime.time')->getRequestTime();
    self::assertSame($deltas[0]['end_value'] - $request_time, $cache['max-age']);

    $structured_data = $this->getAttachedJsonLd($variables);
    self::assertSame('https://schema.org', $structured_data['@context']);
    $graph = $structured_data['@graph'];
    \assert(is_array($graph));
    self::assertCount(6, $graph);
    self::assertSame('Event', $graph[0]['@type']);
    self::assertSame('Salsa Night', $graph[0]['name']);
  }

  /**
   * Tests an event without upcoming dates.
   */
  public function testPreprocessEventWithoutUpcomingDates(): void {
    $event = $this->createEvent([
      'title' => 'Vergangene Party',
      'field_when' => [
        $this->dateDelta($this->date('2020-01-04', '21:00'), $this->date('2020-01-04', '23:30')),
      ],
    ]);

    $variables = [
      'node' => $event,
      'view_mode' => 'full',
    ];
    $this->hooks->preprocessNode($variables);

    self::assertNull($variables['event_next_date']);
    self::assertSame([], $variables['event_next_dates']);
    self::assertStringStartsWith('webcal://', $variables['event_ical_url']);
    self::assertStringEndsWith(
      '/termine.ics?organizer=' . $this->salsaAmSund,
      $variables['event_ical_url'],
    );

    $cache = $variables['#cache'];
    \assert(is_array($cache));
    self::assertSame(3600, $cache['max-age']);
  }

  /**
   * Tests that only full detail pages are prepared.
   */
  public function testOtherViewModesAreIgnored(): void {
    $event = $this->createEvent([
      'title' => 'Tanzabend',
      'field_when' => [
        $this->dateDelta($this->date('now +1 day', '20:00'), NULL),
      ],
    ]);
    $variables = [
      'node' => $event,
      'view_mode' => 'teaser',
    ];
    $this->hooks->preprocessNode($variables);
    self::assertArrayNotHasKey('event_next_dates', $variables);

    $course = $this->createCourse([
      'title' => 'Anfänger',
      'field_when' => [
        $this->dateDelta($this->date('now +2 days', '18:15'), $this->date('now +2 days', '19:15')),
      ],
    ]);
    $variables = [
      'node' => $course,
      'view_mode' => 'teaser',
    ];
    $this->hooks->preprocessNode($variables);
    self::assertArrayNotHasKey('course_next_dates', $variables);
  }

  /**
   * Tests that the JSON-LD is only attached on the node's canonical page.
   */
  public function testNonCanonicalRouteHasNoStructuredData(): void {
    $start = $this->date('next wednesday', '21:00');
    $end = $start->modify('+2 hours');
    $event = $this->createEvent([
      'title' => 'Salsa Night',
      'field_when' => [$this->dateDelta($start, $end)],
    ]);
    $other = $this->createEvent([
      'title' => 'Andere Party',
      'field_when' => [$this->dateDelta($start, $end)],
    ]);

    $routes = [
      'entity.node.preview' => $event,
      'entity.node.canonical' => $other,
    ];
    foreach ($routes as $route_name => $route_node) {
      $this->setRoute($route_name, $route_node);
      $variables = [
        'node' => $event,
        'view_mode' => 'full',
      ];
      $this->hooks->preprocessNode($variables);

      $attached = $variables['#attached'] ?? [];
      \assert(is_array($attached));
      self::assertArrayNotHasKey('html_head', $attached);
    }
  }

  /**
   * Pushes a request that makes the node the current route.
   *
   * @param string $routeName
   *   The route name.
   * @param \Drupal\node\NodeInterface $node
   *   The node passed as the route's node parameter.
   */
  private function setRoute(string $routeName, NodeInterface $node): void {
    $route = new Route('/node/{node}');
    $request = Request::create('/node/' . $node->id());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $routeName);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('node', $node);
    // KernelTestBase::tearDown() clears the session of the current request.
    $request->setSession(new Session(new MockArraySessionStorage()));

    $request_stack = $this->container->get('request_stack');
    \assert($request_stack instanceof RequestStack);
    $request_stack->push($request);
  }

  /**
   * Returns the JSON-LD attached to a node's theme variables.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   *
   * @return array<string, mixed>
   *   The decoded structured data.
   */
  private function getAttachedJsonLd(array $variables): array {
    $attached = $variables['#attached'] ?? [];
    \assert(is_array($attached));
    $html_head = $attached['html_head'] ?? [];
    \assert(is_array($html_head));
    self::assertArrayHasKey(0, $html_head);

    $script = $html_head[0][0] ?? [];
    \assert(is_array($script));
    self::assertSame('script', $script['#tag']);
    self::assertSame('application/ld+json', $script['#attributes']['type']);

    $data = json_decode((string) $script['#value'], TRUE);
    \assert(is_array($data));
    return $data;
  }

}
