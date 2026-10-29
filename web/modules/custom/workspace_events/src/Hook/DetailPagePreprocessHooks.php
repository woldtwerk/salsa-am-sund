<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\DetailFieldResolver;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\workspace_events\StructuredDataBuilder;

/**
 * Prepares the data of the course and event detail pages.
 */
final class DetailPagePreprocessHooks {

  use StringTranslationTrait;

  /**
   * The number of upcoming dates shown on the detail page.
   */
  private const int NEXT_DATES = 6;

  /**
   * The number of days to look ahead for upcoming dates.
   */
  private const int LOOKAHEAD_DAYS = 365;

  /**
   * Constructs the detail page preprocess hooks.
   *
   * @param \Drupal\workspace_events\EventOccurrenceRepository $repository
   *   The occurrence repository.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\workspace_events\StructuredDataBuilder $structuredDataBuilder
   *   The schema.org structured data builder.
   * @param \Drupal\workspace_events\DetailFieldResolver $detailFieldResolver
   *   The reader of the location and link fields.
   */
  public function __construct(
    private readonly EventOccurrenceRepository $repository,
    private readonly TimeInterface $time,
    private readonly RouteMatchInterface $routeMatch,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly StructuredDataBuilder $structuredDataBuilder,
    private readonly DetailFieldResolver $detailFieldResolver,
  ) {}

  /**
   * Implements hook_preprocess_node().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface || !in_array($node->bundle(), ['course', 'event'], TRUE)) {
      return;
    }
    if (($variables['view_mode'] ?? NULL) !== 'full') {
      return;
    }

    $occurrences = $this->findUpcomingOccurrences($node);

    if ($node->bundle() === 'course') {
      $this->prepareCourse($variables, $node, $occurrences);
    }
    else {
      $this->prepareEvent($variables, $node, $occurrences);
    }

    $this->applyCacheability($variables, $node, $occurrences);
  }

  /**
   * Attaches the schema.org JSON-LD of a detail page to the document head.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   * @param array<string, mixed> $data
   *   The JSON-LD data, or an empty array when the page has none.
   *
   * @param-out array<string, mixed> $variables
   */
  private function attachStructuredData(array &$variables, array $data): void {
    if ($data === []) {
      return;
    }

    $attached = $variables['#attached'] ?? [];
    $attached = is_array($attached) ? $attached : [];
    $html_head = $attached['html_head'] ?? [];
    $html_head = is_array($html_head) ? $html_head : [];
    $html_head[] = [
      [
        '#tag' => 'script',
        '#attributes' => ['type' => 'application/ld+json'],
        // Json::encode() escapes the HTML characters that could end the
        // script element, so a title or description cannot inject markup.
        '#value' => Json::encode($data),
      ],
      'workspace_events_json_ld',
    ];
    $attached['html_head'] = $html_head;
    $variables['#attached'] = $attached;
  }

  /**
   * Implements hook_preprocess_page_title().
   *
   * Course and event pages render their own <h1> in the header of the node
   * template, so the route's page title block stays empty.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_page_title')]
  public function preprocessPageTitle(array &$variables): void {
    if ($this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return;
    }
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface || !in_array($node->bundle(), ['course', 'event'], TRUE)) {
      return;
    }

    $variables['title'] = '';

    // The title depends on the route, just like the route's main content.
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $contexts = $cache['contexts'] ?? [];
    $contexts = is_array($contexts) ? $contexts : [];
    $contexts[] = 'route';
    $cache['contexts'] = $contexts;
    $variables['#cache'] = $cache;
  }

  /**
   * Adds the variables of a course detail page.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   * @param \Drupal\node\NodeInterface $node
   *   The course node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   */
  private function prepareCourse(array &$variables, NodeInterface $node, array $occurrences): void {
    $variables['course_level'] = $this->detailFieldResolver->getDifficultyLabel($node);
    $variables['course_schedule'] = $this->buildCourseSchedule($node, $occurrences);
    $variables['course_next_dates'] = $this->buildNextDates($occurrences);
    $variables['course_location'] = $this->buildLocation($node);
    $variables['course_ical_url'] = $this->buildIcalUrl(['course' => (int) $node->id()]);
    $variables['course_overview_url'] = Url::fromUserInput('/salsa-kurse')->toString();

    $data = $this->isCanonicalPage($node)
      ? $this->structuredDataBuilder->buildCourse($node, $occurrences)
      : [];
    $this->attachStructuredData($variables, $data);
  }

  /**
   * Adds the variables of an event detail page.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   */
  private function prepareEvent(array &$variables, NodeInterface $node, array $occurrences): void {
    $organizer = $this->findOrganizer($node);
    $dates = $this->buildNextDates($occurrences);

    $variables['event_organizer'] = $organizer === NULL
      ? ''
      : (string) $organizer->label();
    $variables['event_next_date'] = $dates[0] ?? NULL;
    $variables['event_next_dates'] = $dates;
    $variables['event_location'] = $this->buildLocation($node);
    $variables['event_link'] = $this->buildLink($node);
    $variables['event_ical_url'] = $this->buildIcalUrl(
      $organizer === NULL ? [] : ['organizer' => (int) $organizer->id()],
    );
    $variables['event_overview_url'] = Url::fromUserInput('/veranstaltungen')->toString();

    $data = $this->isCanonicalPage($node)
      ? $this->structuredDataBuilder->buildEvent($node, $occurrences)
      : [];
    $this->attachStructuredData($variables, $data);
  }

  /**
   * Returns whether the node is rendered on its own canonical page.
   *
   * Previews, revision pages and nodes embedded on another page are excluded,
   * so the JSON-LD is only attached where its canonical URL is the page.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return bool
   *   TRUE when the current route is the node's canonical page.
   */
  private function isCanonicalPage(NodeInterface $node): bool {
    if ($node->isNew() || $this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return FALSE;
    }
    $route_node = $this->routeMatch->getParameter('node');
    if (!$route_node instanceof NodeInterface) {
      return FALSE;
    }
    return (int) $route_node->id() === (int) $node->id();
  }

  /**
   * Applies the cache metadata of a detail page.
   *
   * The list shifts as soon as the next date has ended, so it may not be
   * cached beyond the end of the next occurrence.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   *
   * @param-out array<mixed> $variables
   */
  private function applyCacheability(array &$variables, NodeInterface $node, array $occurrences): void {
    $max_age = 3600;
    if ($occurrences !== []) {
      $max_age = max(0, $occurrences[0]->endTimestamp() - $this->time->getRequestTime());
    }

    $cacheability = CacheableMetadata::createFromRenderArray($variables);
    $cacheability->addCacheableDependency($node);
    // The subscription URLs are absolute, so they depend on the site host. The
    // JSON-LD is only attached on the node's canonical route.
    $cacheability->addCacheContexts(['url.site', 'route']);
    foreach ($occurrences as $occurrence) {
      $cacheability->addCacheableDependency($occurrence->sourceNode());
      $cacheability->addCacheableDependency($occurrence->node);
    }
    if ($node->bundle() === 'course') {
      // A new lesson changes the next dates of its course.
      $cacheability->addCacheTags(['node_list:lesson']);
    }
    $location = $this->detailFieldResolver->getLocationTerm($node);
    if ($location !== NULL) {
      $cacheability->addCacheableDependency($location);
    }
    $organizer = $this->findOrganizer($node);
    if ($organizer !== NULL) {
      $cacheability->addCacheableDependency($organizer);
    }
    if ($node->bundle() === 'course') {
      $provider = $this->structuredDataBuilder->findCourseProvider();
      if ($provider !== NULL) {
        $cacheability->addCacheableDependency($provider);
      }
    }
    // The body is rendered through its text format.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $format = $node->get('body')->first()?->get('format')->getString() ?? '';
      if ($format !== '') {
        $cacheability->addCacheTags(['config:filter.format.' . $format]);
      }
    }
    $cacheability->setCacheMaxAge($max_age);
    $cacheability->applyTo($variables);
  }

  /**
   * Returns the next occurrences of a course or event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The upcoming occurrences, sorted by start date and time.
   */
  private function findUpcomingOccurrences(NodeInterface $node): array {
    $timezone = new \DateTimeZone(date_default_timezone_get());
    $from = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($timezone);
    $filter = $node->bundle() === 'course'
      ? EventFilter::coursesOnly([(int) $node->id()])
      : EventFilter::eventsOnly(eventIds: [(int) $node->id()]);
    return $this->repository->findOccurrences(
      $filter,
      $from,
      $from->modify('+' . self::LOOKAHEAD_DAYS . ' days'),
      self::NEXT_DATES,
    );
  }

  /**
   * Builds the webcal subscription URL of a detail page.
   *
   * A webcal:// URL opens directly in the calendar client, the same way the
   * blocks link to their feeds.
   *
   * @param array<string, int> $query
   *   The feed query parameters.
   *
   * @return string
   *   The absolute subscription URL.
   */
  private function buildIcalUrl(array $query): string {
    $absolute = Url::fromRoute('workspace_events.ical', [], [
      'query' => $query,
      'absolute' => TRUE,
    ])->toString();
    return preg_replace('#^https?://#', 'webcal://', $absolute) ?? $absolute;
  }

  /**
   * Builds the rows of the upcoming dates list.
   *
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   *
   * @return array<int, array{date: string, date_iso: string, weekday: string, time: string, ical_url: string, cancelled: bool}>
   *   The date rows.
   */
  private function buildNextDates(array $occurrences): array {
    $dates = [];
    foreach ($occurrences as $occurrence) {
      $dates[] = [
        'date' => $occurrence->start->format('d.m.Y'),
        'date_iso' => $occurrence->start->format('Y-m-d'),
        'weekday' => $this->formatWeekday($occurrence),
        'time' => $this->formatTime($occurrence) ?? '',
        'ical_url' => $occurrence->toIcalUrl()->toString(),
        'cancelled' => $occurrence->isCancelled(),
      ];
    }
    return $dates;
  }

  /**
   * Formats the schedule line of a course.
   *
   * The rhythm comes from the course field and is only mentioned when the
   * lessons do not take place every week. The weekday and time come from the
   * next lesson, so the line stays correct when the first lesson is in the
   * past.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   *
   * @return string
   *   The schedule, or an empty string without upcoming dates.
   */
  private function buildCourseSchedule(NodeInterface $course, array $occurrences): string {
    if (!isset($occurrences[0])) {
      return '';
    }

    $parts = [];
    $interval = $this->getCourseInterval($course);
    if ($interval !== NULL && $interval !== 1) {
      $label = $this->getCourseIntervalLabel($course, $interval);
      if ($label !== '') {
        $parts[] = $label;
      }
    }
    $schedule = $this->formatSchedule($occurrences[0]);
    if ($schedule !== '') {
      $parts[] = $schedule;
    }

    return implode(' · ', $parts);
  }

  /**
   * Returns the interval of a course in weeks.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   *
   * @return int|null
   *   The interval, or NULL when the course has no interval.
   */
  private function getCourseInterval(NodeInterface $course): ?int {
    if (!$course->hasField('field_course_interval') || $course->get('field_course_interval')->isEmpty()) {
      return NULL;
    }
    $value = $course->get('field_course_interval')->getString();
    return is_numeric($value) ? (int) $value : NULL;
  }

  /**
   * Returns the configured label of a course interval.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   * @param int $interval
   *   The interval value.
   *
   * @return string
   *   The label, or an empty string when the field has no label for the value.
   */
  private function getCourseIntervalLabel(NodeInterface $course, int $interval): string {
    if (!$course->hasField('field_course_interval')) {
      return '';
    }
    $definition = $course->get('field_course_interval')->getFieldDefinition();
    $allowed = $definition->getFieldStorageDefinition()->getSetting('allowed_values');
    if (!is_array($allowed) || !isset($allowed[$interval])) {
      return '';
    }
    $label = $allowed[$interval];
    return is_scalar($label) ? (string) $label : '';
  }

  /**
   * Formats the weekday and time of the series.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The next occurrence of the course.
   *
   * @return string
   *   The weekday and time, joined for display.
   */
  private function formatSchedule(EventOccurrence $occurrence): string {
    $parts = array_values(array_filter([
      $this->formatWeekday($occurrence),
      $this->formatTime($occurrence) ?? '',
    ]));
    return implode(' · ', $parts);
  }

  /**
   * Returns the translated weekday of an occurrence.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The weekday in the current interface language.
   */
  private function formatWeekday(EventOccurrence $occurrence): string {
    return $this->dateFormatter->format(
      $occurrence->start->getTimestamp(),
      'custom',
      'l',
      $occurrence->start->getTimezone()->getName(),
    );
  }

  /**
   * Formats the time of an occurrence for the detail page.
   *
   * Unlike the event cards and blocks, the detail page omits the "Uhr"
   * suffix, so the weekday line stays compact.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string|null
   *   The formatted time, or NULL for all-day events.
   */
  private function formatTime(EventOccurrence $occurrence): ?string {
    if ($occurrence->allDay) {
      return NULL;
    }
    $start = $occurrence->start->format('H:i');
    if ($occurrence->end === NULL) {
      return (string) $this->t('@start onwards', ['@start' => $start]);
    }
    return (string) $this->t('@start to @end', [
      '@start' => $start,
      '@end' => $occurrence->end->format('H:i'),
    ]);
  }

  /**
   * Returns the organizer term of an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The organizer, or NULL when the event has none.
   */
  private function findOrganizer(NodeInterface $node): ?TermInterface {
    if (!$node->hasField('field_organizer')) {
      return NULL;
    }
    $term = $node->get('field_organizer')->entity;
    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Builds the location card data of a course or event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return array{name: string, address: string, map_url: string}|null
   *   The location, or NULL when the node has no location.
   */
  private function buildLocation(NodeInterface $node): ?array {
    $term = $this->detailFieldResolver->getLocationTerm($node);
    if ($term === NULL) {
      return NULL;
    }

    return [
      'name' => (string) $term->label(),
      'address' => $this->detailFieldResolver->getAddress($term),
      'map_url' => $this->detailFieldResolver->getMapUrl($term)?->toString() ?? '',
    ];
  }

  /**
   * Builds the optional external link of a course or event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return array{label: string, href: string}|null
   *   The link, or NULL when the node has no link.
   */
  private function buildLink(NodeInterface $node): ?array {
    $link = $this->detailFieldResolver->getLink($node);
    if ($link === NULL) {
      return NULL;
    }
    return [
      'label' => $link['title'] === ''
        ? (string) $this->t('More information')
        : $link['title'],
      'href' => $link['url']->toString(),
    ];
  }

}
