<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\node\NodeInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\workspace_events\Entity\SeasonInterface;
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
final class DetailPagePreprocessHooksTest extends LessonKernelTestBase {

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
   * Tests the schedule, cancellation, level and structured data of a course.
   */
  public function testCourse(): void {
    $start = $this->date('next monday', '19:30');
    $end = $this->date('next monday', '20:30');
    $location = $this->createLocation(
      'Bootshaus Stralsund',
      "Friedrich-Naumann-Straße 5\n18435 Stralsund",
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
    );
    $course = $this->createCourse([
      'title' => 'Leicht Fortgeschrittene',
      'field_difficulty' => [3, 1, 2],
      'season_location' => $location->id(),
      'body' => [
        'value' => '<p>Neue Figuren und Elemente für Fortgeschrittene.</p>',
        'format' => 'plain_text',
      ],
      ...$this->courseSchedule($start, $start->modify('+4 weeks'), 2, $end),
    ]);
    $lessons = $this->loadCourseLessons($course);
    self::assertCount(3, $lessons);
    $lessons[0]->set('field_cancelled', TRUE);
    $lessons[0]->save();

    $season = $this->loadSeason($course);
    $this->addRule($season, 'INTERVAL=2', 'COUNT=3');

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

    self::assertSame('Level 1–3', $variables['course_level']);
    self::assertSame('Every 2 weeks · ' . $weekday . ' · 19:30 to 20:30', $variables['course_schedule']);
    $dates = $variables['course_next_dates'];
    \assert(is_array($dates));
    self::assertCount(3, $dates);
    self::assertSame([
      $start->format('Y-m-d'),
      $start->modify('+2 weeks')->format('Y-m-d'),
      $start->modify('+4 weeks')->format('Y-m-d'),
    ], array_column($dates, 'date_iso'));
    self::assertTrue($dates[0]['cancelled']);
    self::assertFalse($dates[1]['cancelled']);
    self::assertSame('/termine/stunde/' . $lessons[0]->id() . '/ical', $dates[0]['ical_url']);
    foreach ($dates as $index => $date) {
      \assert(is_array($date));
      self::assertSame($weekday, $date['weekday']);
      self::assertSame('19:30 to 20:30', $date['time']);
      self::assertSame($start->modify('+' . ($index * 2) . ' weeks')->format('d.m.Y'), $date['date']);
      self::assertSame('/termine/stunde/' . $lessons[$index]->id() . '/ical', $date['ical_url']);
    }

    self::assertStringStartsWith('webcal://', $variables['course_ical_url']);
    self::assertStringEndsWith('/termine.ics?course=' . $course->id(), $variables['course_ical_url']);
    self::assertSame('/salsa-kurse', $variables['course_overview_url']);
    $seasons = $variables['course_seasons'];
    \assert(is_array($seasons));
    self::assertCount(1, $seasons);
    $season_item = $seasons[0];
    \assert(is_array($season_item));
    self::assertSame('next', $season_item['status']);
    self::assertSame((string) $season->label(), $season_item['label']);
    self::assertSame($weekday . ' · 19:30 to 20:30', $season_item['schedule']);
    self::assertSame('Every 2 weeks', $season_item['interval']);
    $location_data = $season_item['location'];
    \assert(is_array($location_data));
    self::assertSame('Bootshaus Stralsund', $location_data['name']);
    self::assertSame("Friedrich-Naumann-Straße 5\n18435 Stralsund", $location_data['address']);
    self::assertSame(
      'https://www.openstreetmap.org/search?query=Friedrich-Naumann-Stra%C3%9Fe%205',
      $location_data['map_url'],
    );
    $cache = $variables['#cache'];
    \assert(is_array($cache));
    self::assertContains('node:' . $course->id(), $cache['tags']);
    self::assertContains('lesson:' . $lessons[0]->id(), $cache['tags']);
    self::assertContains('lesson_list', $cache['tags']);
    self::assertContains('course_season_list', $cache['tags']);
    self::assertContains('course_season:' . $season->id(), $cache['tags']);
    self::assertContains('taxonomy_term:' . $location->id(), $cache['tags']);
    self::assertContains('taxonomy_term:' . $this->salsaAmSund, $cache['tags']);
    self::assertContains('config:filter.format.plain_text', $cache['tags']);
    self::assertContains('url.site', $cache['contexts']);
    self::assertContains('route', $cache['contexts']);
    $request_time = $this->container->get('datetime.time')->getRequestTime();
    self::assertSame($start->getTimestamp() - $request_time, $cache['max-age']);

    $structured_data = $this->getAttachedJsonLd($variables);
    self::assertSame('https://schema.org', $structured_data['@context']);
    $graph = $structured_data['@graph'];
    \assert(is_array($graph));
    self::assertSame('Course', $graph[0]['@type']);
  }

  /**
   * Tests that the course page shows the current and next season only.
   */
  public function testCourseSeasons(): void {
    $course = $this->createCourse(['title' => 'Saisons']);
    $this->createSeason($course, [
      'field_schedule' => $this->smartDateDeltas(
        $this->date('-20 weeks', '18:00'),
        $this->date('-16 weeks', '19:00'),
      ),
    ]);
    $current = $this->createSeason($course, [
      'field_schedule' => $this->smartDateDeltas(
        $this->date('-2 weeks', '18:00'),
        $this->date('+2 weeks', '19:00'),
        2,
      ),
    ]);
    $this->addRule($current, 'INTERVAL=2', 'COUNT=3');
    $next = $this->createSeason($course, [
      'field_schedule' => $this->smartDateDeltas(
        $this->date('+3 weeks', '20:00'),
        $this->date('+7 weeks', '21:00'),
      ),
    ]);
    $this->createSeason($course, [
      'field_schedule' => $this->smartDateDeltas(
        $this->date('+10 weeks', '18:00'),
        $this->date('+14 weeks', '19:00'),
      ),
    ]);
    $current->set('field_prices', [
      ['category' => 'normal', 'amount' => '12.00'],
      ['category' => 'student', 'amount' => '10.00'],
    ]);
    $current->save();
    $next->set('field_prices', [
      ['category' => 'normal', 'amount' => '13.50'],
    ]);
    $next->save();

    $this->setRoute('entity.node.canonical', $course);
    $variables = [
      'node' => $course,
      'view_mode' => 'full',
    ];
    $this->hooks->preprocessNode($variables);

    $seasons = $variables['course_seasons'];
    \assert(is_array($seasons));
    self::assertCount(2, $seasons);
    self::assertSame('current', $seasons[0]['status']);
    self::assertSame((string) $current->label(), $seasons[0]['label']);
    self::assertSame('Every 2 weeks', $seasons[0]['interval']);
    self::assertSame([
      ['category' => 'Normal', 'amount' => '12,00 €'],
      ['category' => 'Student', 'amount' => '10,00 €'],
    ], $seasons[0]['prices']);
    self::assertSame('next', $seasons[1]['status']);
    self::assertSame((string) $next->label(), $seasons[1]['label']);
    self::assertSame([
      ['category' => 'Normal', 'amount' => '13,50 €'],
    ], $seasons[1]['prices']);
  }

  /**
   * Tests a full event, a past event and their structured data.
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
      'time' => '21:00 to 23:30',
      'ical_url' => '/termine/' . $event->id() . '/ical',
      'cancelled' => FALSE,
    ], $next_date);
    $dates = $variables['event_next_dates'];
    \assert(is_array($dates));
    self::assertCount(1, $dates);
    self::assertSame($start->format('Y-m-d'), $dates[0]['date_iso']);
    self::assertStringStartsWith('webcal://', $variables['event_ical_url']);
    self::assertStringEndsWith('/termine.ics?organizer=' . $this->externalOrganizer, $variables['event_ical_url']);
    self::assertSame('/veranstaltungen', $variables['event_overview_url']);
    $location_data = $variables['event_location'];
    \assert(is_array($location_data));
    self::assertSame('Kulturhaus Stralsund', $location_data['name']);
    self::assertSame("Olof-Palme-Platz 6\n18439 Stralsund", $location_data['address']);
    self::assertSame('https://www.openstreetmap.org/search?query=Olof-Palme-Platz%206', $location_data['map_url']);
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
    $request_time = $this->container->get('datetime.time')->getRequestTime();
    self::assertSame($end->getTimestamp() - $request_time, $cache['max-age']);

    $structured_data = $this->getAttachedJsonLd($variables);
    self::assertSame('https://schema.org', $structured_data['@context']);
    $graph = $structured_data['@graph'];
    \assert(is_array($graph));
    self::assertCount(1, $graph);
    self::assertSame('Event', $graph[0]['@type']);
    self::assertSame('Salsa Night', $graph[0]['name']);

    $past = $this->createEvent([
      'title' => 'Vergangene Party',
      'field_event_date' => $this->smartDateRange(
        $this->date('2020-01-04', '21:00'),
        $this->date('2020-01-04', '23:30'),
      ),
    ]);
    $variables = [
      'node' => $past,
      'view_mode' => 'full',
    ];
    $this->hooks->preprocessNode($variables);
    self::assertNull($variables['event_next_date']);
    self::assertSame([], $variables['event_next_dates']);
    self::assertStringStartsWith('webcal://', $variables['event_ical_url']);
    self::assertStringEndsWith('/termine.ics?organizer=' . $this->salsaAmSund, $variables['event_ical_url']);
    $cache = $variables['#cache'];
    \assert(is_array($cache));
    self::assertSame(3600, $cache['max-age']);
  }

  /**
   * Tests other view modes and non-canonical routes.
   */
  public function testOtherViewModesAndRoutes(): void {
    $event = $this->createEvent([
      'title' => 'Tanzabend',
      'field_event_date' => $this->smartDateRange(
        $this->date('now +1 day', '20:00'),
        $this->date('now +1 day', '23:00'),
      ),
    ]);
    $variables = [
      'node' => $event,
      'view_mode' => 'teaser',
    ];
    $this->hooks->preprocessNode($variables);
    self::assertArrayNotHasKey('event_next_dates', $variables);

    $start = $this->date('now +2 days', '18:15');
    $course = $this->createCourse([
      'title' => 'Anfänger',
      ...$this->courseSchedule($start, $start),
    ]);
    $variables = [
      'node' => $course,
      'view_mode' => 'teaser',
    ];
    $this->hooks->preprocessNode($variables);
    self::assertArrayNotHasKey('course_next_dates', $variables);

    $other = $this->createEvent([
      'title' => 'Andere Party',
      'field_event_date' => $this->smartDateRange($start->modify('+1 week'), $start->modify('+1 week')->modify('+2 hours')),
    ]);
    foreach ([
      'entity.node.preview' => $event,
      'entity.node.canonical' => $other,
    ] as $route_name => $route_node) {
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
   * Adds a weekly recurrence rule to the dates of a season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   * @param string $parameters
   *   The recurrence parameters, for example INTERVAL=2.
   * @param string $limit
   *   The recurrence limit, for example COUNT=3.
   */
  private function addRule(SeasonInterface $season, string $parameters, string $limit): void {
    $items = $season->get('field_schedule');
    $first = $items->first();
    $last = $items->last();
    \assert($first !== NULL && $last !== NULL);

    $rule = SmartDateRule::create([
      'freq' => 'WEEKLY',
      'limit' => $limit,
      'unlimited' => FALSE,
      'entity_type' => 'course_season',
      'bundle' => 'course_season',
      'field_name' => 'field_schedule',
      'start' => (int) $first->get('value')->getString(),
      'end' => (int) $first->get('end_value')->getString(),
      'parameters' => $parameters,
    ]);
    $rule->save();

    foreach ($items as $index => $item) {
      $item->set('rrule', (int) $rule->id());
      $item->set('rrule_index', $index);
    }
    $season->save();
  }

  /**
   * Pushes a request that makes the node the current route.
   *
   * @param string $route_name
   *   The route name.
   * @param \Drupal\node\NodeInterface $node
   *   The node passed as the route's node parameter.
   */
  private function setRoute(string $route_name, NodeInterface $node): void {
    $route = new Route('/node/{node}');
    $request = Request::create('/node/' . $node->id());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
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
