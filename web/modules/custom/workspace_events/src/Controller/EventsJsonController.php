<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\EventOccurrenceRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the occurrences of a date range as JSON for the calendar.
 */
final class EventsJsonController implements ContainerInjectionInterface {

  /**
   * The timezone used for date output.
   */
  private const string TIMEZONE = 'Europe/Berlin';

  /**
   * The maximum number of days a single request may cover.
   */
  private const int MAX_DAYS = 100;

  /**
   * Constructs an EventsJsonController.
   *
   * @param \Drupal\workspace_events\EventOccurrenceRepository $repository
   *   The occurrence repository.
   * @param \Drupal\workspace_events\EventFilterFactory $filterFactory
   *   The filter factory that parses the query parameters.
   */
  public function __construct(
    private readonly EventOccurrenceRepository $repository,
    private readonly EventFilterFactory $filterFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $repository = $container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $filter_factory = $container->get(EventFilterFactory::class);
    \assert($filter_factory instanceof EventFilterFactory);

    return new static($repository, $filter_factory);
  }

  /**
   * Builds the JSON response of the requested occurrences.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The JSON response, or a 400 response for an invalid range.
   */
  public function __invoke(Request $request): Response {
    $start = $this->parseDate($request->query->get('start'));
    $end = $this->parseDate($request->query->get('end'));
    if ($start === NULL || $end === NULL) {
      return $this->badRequest('The "start" and "end" parameters must be ISO 8601 dates.');
    }
    if ($end <= $start) {
      return $this->badRequest('The "end" parameter must be after "start".');
    }
    if ((int) $start->diff($end)->days > self::MAX_DAYS) {
      return $this->badRequest('The requested range must not exceed ' . self::MAX_DAYS . ' days.');
    }

    // One query finds every occurrence of the range.
    $occurrences = $this->repository->findOccurrences(
      $this->filterFactory->fromQuery($request->query),
      $start,
      $end,
    );

    $timezone = new \DateTimeZone(self::TIMEZONE);
    $items = [];
    foreach ($occurrences as $occurrence) {
      $items[] = $this->buildEvent($occurrence, $timezone);
    }

    $response = new CacheableJsonResponse($items);
    $cacheability = $response->getCacheableMetadata();
    $cacheability->addCacheContexts([
      'url.query_args:start',
      'url.query_args:end',
      'url.query_args:organizer',
      'url.query_args:location',
      'url.query_args:course',
      'url.query_args:type',
    ]);
    $cacheability->addCacheTags([
      'node_list:event',
      'node_list:course',
      'lesson_list',
      'course_season_list',
      'taxonomy_term_list:location',
      'taxonomy_term_list:organizer',
    ]);
    foreach ($occurrences as $occurrence) {
      $cacheability->addCacheableDependency($occurrence->source());
      $cacheability->addCacheableDependency($occurrence->node);
      if ($occurrence->season !== NULL) {
        $cacheability->addCacheableDependency($occurrence->season);
      }
      if ($occurrence->organizer !== NULL) {
        $cacheability->addCacheableDependency($occurrence->organizer);
      }
      if ($occurrence->location !== NULL) {
        $cacheability->addCacheableDependency($occurrence->location);
      }
    }

    return $response;
  }

  /**
   * Builds the JSON item of an occurrence.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   * @param \DateTimeZone $timezone
   *   The timezone used for date output.
   *
   * @return array{id: string, title: string, start: string, end: string|null, allDay: bool, url: string, classNames: string[], extendedProps: array{location: string|null, organizer: string|null, course: bool, cancelled: bool}}
   *   The event as FullCalendar expects it.
   */
  private function buildEvent(EventOccurrence $occurrence, \DateTimeZone $timezone): array {
    $start = $occurrence->start->setTimezone($timezone);
    $end = $occurrence->end?->setTimezone($timezone);
    $class_names = [$occurrence->isCourse() ? 'is-course' : 'is-event'];
    if ($occurrence->isCancelled()) {
      $class_names[] = 'is-cancelled';
    }

    return [
      'id' => $occurrence->sourceKey() . '-' . $occurrence->start->getTimestamp(),
      'title' => (string) $occurrence->node->label(),
      'start' => $occurrence->allDay ? $start->format('Y-m-d') : $start->format(\DateTimeInterface::ATOM),
      'end' => $this->formatEnd($end, $occurrence->allDay),
      'allDay' => $occurrence->allDay,
      'url' => $this->getUrl($occurrence->node),
      'classNames' => $class_names,
      'extendedProps' => [
        'location' => $occurrence->location === NULL ? NULL : (string) $occurrence->location->label(),
        'organizer' => $occurrence->organizer === NULL ? NULL : (string) $occurrence->organizer->label(),
        'course' => $occurrence->isCourse(),
        'cancelled' => $occurrence->isCancelled(),
      ],
    ];
  }

  /**
   * Formats the end of an occurrence.
   *
   * @param \DateTimeImmutable|null $end
   *   The end in the output timezone.
   * @param bool $allDay
   *   Whether the occurrence lasts the whole day.
   *
   * @return string|null
   *   The formatted end, or NULL when the occurrence has no known end.
   */
  private function formatEnd(?\DateTimeImmutable $end, bool $allDay): ?string {
    if ($end === NULL) {
      return NULL;
    }
    if ($allDay) {
      // FullCalendar expects an exclusive end for whole-day events.
      return $end->modify('+1 day')->format('Y-m-d');
    }
    return $end->format(\DateTimeInterface::ATOM);
  }

  /**
   * Returns the link target of an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   The external link when set, otherwise the node path.
   */
  private function getUrl(NodeInterface $node): string {
    if ($node->hasField('field_link') && !$node->get('field_link')->isEmpty()) {
      $uri = $node->get('field_link')->first()?->get('uri')->getString() ?? '';
      if ($uri !== '') {
        return $uri;
      }
    }
    return $node->toUrl()->toString();
  }

  /**
   * Parses an ISO 8601 date or date and time.
   *
   * @param mixed $value
   *   The raw query argument.
   *
   * @return \DateTimeImmutable|null
   *   The date, or NULL when the value is missing or malformed.
   */
  private function parseDate(mixed $value): ?\DateTimeImmutable {
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    $timezone = new \DateTimeZone(self::TIMEZONE);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
      return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : NULL;
    }

    // FullCalendar sends dates as ISO 8601 with an explicit offset. A date
    // without an offset is interpreted in the calendar timezone.
    $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/';
    if (preg_match($pattern, $value) !== 1) {
      return NULL;
    }
    try {
      return new \DateTimeImmutable($value, $timezone);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Builds a 400 response with a JSON error message.
   *
   * @param string $message
   *   The error message.
   */
  private function badRequest(string $message): JsonResponse {
    return new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
  }

}
