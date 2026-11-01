<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Controller;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\DetailFieldResolver;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\taxonomy\TermInterface;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Provides a filterable iCal feed of event occurrences.
 */
final class IcalController implements ContainerInjectionInterface {

  /**
   * The timezone used for date output.
   */
  private const string TIMEZONE = 'Europe/Berlin';

  /**
   * The category of course occurrences in the feed.
   *
   * The feed is German, so the category is not translated.
   */
  private const string COURSE_CATEGORY = 'Kurs';

  /**
   * The number of days shown after today.
   */
  private const int FUTURE_DAYS = 365;

  /**
   * The default number of days shown in the past.
   */
  private const int DEFAULT_PAST_DAYS = 30;

  /**
   * The maximum number of days that may be requested in the past.
   */
  private const int MAX_PAST_DAYS = 90;

  /**
   * Constructs an IcalController.
   *
   * @param \Drupal\workspace_events\EventOccurrenceRepository $repository
   *   The occurrence repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\EventDescriptionFormatter $descriptionFormatter
   *   The event description formatter.
   * @param \Drupal\workspace_events\EventFilterFactory $filterFactory
   *   The filter factory that parses the query parameters.
   * @param \Drupal\workspace_events\DetailFieldResolver $detailFieldResolver
   *   The reader of the course difficulty.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   The transliteration service for the download filename.
   */
  public function __construct(
    private readonly EventOccurrenceRepository $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventDescriptionFormatter $descriptionFormatter,
    private readonly EventFilterFactory $filterFactory,
    private readonly DetailFieldResolver $detailFieldResolver,
    private readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $repository = $container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $entity_type_manager = $container->get(EntityTypeManagerInterface::class);
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $description_formatter = $container->get(EventDescriptionFormatter::class);
    \assert($description_formatter instanceof EventDescriptionFormatter);
    $filter_factory = $container->get(EventFilterFactory::class);
    \assert($filter_factory instanceof EventFilterFactory);
    $detail_field_resolver = $container->get(DetailFieldResolver::class);
    \assert($detail_field_resolver instanceof DetailFieldResolver);
    $transliteration = $container->get('transliteration');
    \assert($transliteration instanceof TransliterationInterface);

    return new static($repository, $entity_type_manager, $description_formatter, $filter_factory, $detail_field_resolver, $transliteration);
  }

  /**
   * Builds the calendar response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The iCal response.
   */
  public function __invoke(Request $request): CacheableResponse {
    $timezone = new \DateTimeZone(self::TIMEZONE);
    $now = new \DateTimeImmutable('now', $timezone);
    $past_days = $this->getPastDays($request);
    $from = $now->modify('-' . $past_days . ' days');
    $to = $now->modify('+' . self::FUTURE_DAYS . ' days');

    $filter = $this->filterFactory->fromQuery($request->query);
    $occurrences = $this->repository->findOccurrences($filter, $from, $to);

    $calendar = $this->buildCalendar($request, $filter, $occurrences);
    $response = new CacheableResponse($calendar->serialize(), Response::HTTP_OK, [
      'Content-Type' => 'text/calendar; charset=utf-8',
      'Content-Disposition' => 'inline; filename="salsa-am-sund.ics"',
    ]);

    $cacheability = $response->getCacheableMetadata();
    $cacheability->addCacheContexts([
      'url.query_args:type',
      'url.query_args:organizer',
      'url.query_args:location',
      'url.query_args:course',
      'url.query_args:past',
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
      if ($occurrence->location !== NULL) {
        $cacheability->addCacheableDependency($occurrence->location);
      }
    }
    // The calendar name lists the selected organizers and courses, so their
    // labels are part of the response.
    foreach ($this->loadTerms($filter->organizerIds) as $organizer) {
      $cacheability->addCacheableDependency($organizer);
    }
    foreach ($this->loadCourses($filter->courseIds) as $course) {
      $cacheability->addCacheableDependency($course);
    }
    // The window is relative to the current time, so the response is only
    // valid for a limited period.
    $cacheability->setCacheMaxAge(3600);

    return $response;
  }

  /**
   * Builds the calendar response for a single event occurrence.
   *
   * The download carries the same UID as the feed, so subscribing clients
   * recognise the single date instead of adding a duplicate.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The iCal response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the node has no occurrence.
   */
  public function occurrence(Request $request, NodeInterface $node): CacheableResponse {
    return $this->occurrenceResponse($request, $this->repository->findOccurrence($node));
  }

  /**
   * Builds the calendar response for a single lesson occurrence.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The iCal response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the lesson has no occurrence.
   */
  public function lessonOccurrence(Request $request, LessonInterface $lesson): CacheableResponse {
    return $this->occurrenceResponse($request, $this->repository->findLessonOccurrence($lesson));
  }

  /**
   * Builds the download response of a single occurrence.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\workspace_events\EventOccurrence|null $occurrence
   *   The occurrence, or NULL when there is none.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The iCal response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the occurrence is NULL.
   */
  private function occurrenceResponse(Request $request, ?EventOccurrence $occurrence): CacheableResponse {
    if ($occurrence === NULL) {
      throw new NotFoundHttpException();
    }

    $calendar = $this->buildCalendarHeader();
    $this->addEvent($calendar, $occurrence, $request->getHost(), $request->getSchemeAndHttpHost());

    $filename = $this->getFilename($occurrence);
    $response = new CacheableResponse($calendar->serialize(), Response::HTTP_OK, [
      'Content-Type' => 'text/calendar; charset=utf-8',
      'Content-Disposition' => HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_ATTACHMENT,
        $filename,
        $this->getAsciiFilename($filename),
      ),
    ]);

    // The single event only depends on its date entity, its display node and
    // its terms.
    $cacheability = $response->getCacheableMetadata();
    $cacheability->addCacheableDependency($occurrence->source());
    $cacheability->addCacheableDependency($occurrence->node);
    if ($occurrence->season !== NULL) {
      $cacheability->addCacheableDependency($occurrence->season);
    }
    if ($occurrence->location !== NULL) {
      $cacheability->addCacheableDependency($occurrence->location);
    }
    if ($occurrence->organizer !== NULL) {
      $cacheability->addCacheableDependency($occurrence->organizer);
    }

    return $response;
  }

  /**
   * Builds the iCal calendar object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The applied filter.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The occurrences to add.
   *
   * @return \Sabre\VObject\Component\VCalendar
   *   The calendar.
   */
  // @phpstan-ignore-next-line
  private function buildCalendar(Request $request, EventFilter $filter, array $occurrences): VCalendar {
    $calendar = $this->buildCalendarHeader();
    $calendar->add('X-WR-CALNAME', $this->getCalendarName($filter));
    $calendar->add('REFRESH-INTERVAL', 'PT6H', ['VALUE' => 'DURATION']);
    $calendar->add('X-PUBLISHED-TTL', 'PT6H');

    $host = $request->getHost();
    $scheme_and_host = $request->getSchemeAndHttpHost();
    foreach ($occurrences as $occurrence) {
      $this->addEvent($calendar, $occurrence, $host, $scheme_and_host);
    }

    return $calendar;
  }

  /**
   * Builds the calendar with the properties shared by the feed and a download.
   *
   * The name and the refresh hints are feed-only, so they are added by
   * buildCalendar().
   *
   * @return \Sabre\VObject\Component\VCalendar
   *   The calendar header, including the timezone definition.
   */
  // @phpstan-ignore-next-line
  private function buildCalendarHeader(): VCalendar {
    $calendar = new VCalendar();
    $calendar->remove('PRODID');
    $calendar->add('PRODID', '-//Salsa am Sund//Termine//DE');
    $calendar->remove('VERSION');
    $calendar->add('VERSION', '2.0');
    $calendar->remove('CALSCALE');
    $calendar->add('CALSCALE', 'GREGORIAN');
    $calendar->add('METHOD', 'PUBLISH');
    $calendar->add('X-WR-TIMEZONE', self::TIMEZONE);
    $this->addTimezone($calendar);

    return $calendar;
  }

  /**
   * Adds the Europe/Berlin timezone definition.
   *
   * @param \Sabre\VObject\Component\VCalendar $calendar
   *   The calendar.
   */
  // @phpstan-ignore-next-line
  private function addTimezone(VCalendar $calendar): void {
    $timezone = $calendar->add('VTIMEZONE');
    \assert($timezone instanceof Component);
    $timezone->add('TZID', self::TIMEZONE);

    $daylight = $calendar->createComponent('DAYLIGHT', [], FALSE);
    $timezone->add($daylight);
    $daylight->add('TZOFFSETFROM', '+0100');
    $daylight->add('TZOFFSETTO', '+0200');
    $daylight->add('TZNAME', 'CEST');
    $daylight->add('DTSTART', '19700329T020000');
    $daylight->add('RRULE', 'FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU');

    $standard = $calendar->createComponent('STANDARD', [], FALSE);
    $timezone->add($standard);
    $standard->add('TZOFFSETFROM', '+0200');
    $standard->add('TZOFFSETTO', '+0100');
    $standard->add('TZNAME', 'CET');
    $standard->add('DTSTART', '19701025T030000');
    $standard->add('RRULE', 'FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU');
  }

  /**
   * Adds a single event to the calendar.
   *
   * @param \Sabre\VObject\Component\VCalendar $calendar
   *   The calendar.
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   * @param string $host
   *   The request host, used for the UID.
   * @param string $schemeAndHost
   *   The request scheme and host, used for absolute URLs.
   */
  // @phpstan-ignore-next-line
  private function addEvent(VCalendar $calendar, EventOccurrence $occurrence, string $host, string $schemeAndHost): void {
    $source = $occurrence->source();
    $event = $calendar->add('VEVENT');
    \assert($event instanceof VEvent);
    $event->remove('UID');
    $event->add('UID', 'event-' . $occurrence->sourceKey() . '@' . $host);

    $changed = (new \DateTimeImmutable('@' . $source->getChangedTime()))
      ->setTimezone(new \DateTimeZone('UTC'));
    $event->remove('DTSTAMP');
    $event->add('DTSTAMP', $changed);
    $event->add('LAST-MODIFIED', $changed);
    $event->add('STATUS', $occurrence->isCancelled() ? 'CANCELLED' : 'CONFIRMED');

    $event->add('SUMMARY', $this->getSummary($occurrence));
    $event->add('DESCRIPTION', $this->getDescription($occurrence, $schemeAndHost));
    $event->add('LOCATION', $this->getLocation($occurrence));
    $event->add('URL', $this->getUrl($occurrence, $schemeAndHost));
    $categories = $this->getCategories($occurrence);
    if ($categories !== '') {
      $event->add('CATEGORIES', $categories);
    }

    if ($occurrence->allDay) {
      $event->add('DTSTART', $occurrence->start->format('Ymd'), ['VALUE' => 'DATE']);
      // The stored end date is inclusive, while iCal expects an exclusive
      // DTEND, so a multi-day event ends on the day after its last day.
      $end = $occurrence->end ?? $occurrence->start;
      $event->add('DTEND', $end->modify('+1 day')->format('Ymd'), ['VALUE' => 'DATE']);
      return;
    }

    $event->add('DTSTART', $occurrence->start->format('Ymd\THis'), ['TZID' => self::TIMEZONE]);
    if ($occurrence->end !== NULL) {
      $event->add('DTEND', $occurrence->end->format('Ymd\THis'), ['TZID' => self::TIMEZONE]);
    }
  }

  /**
   * Builds the calendar name including a filter summary.
   *
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The applied filter.
   *
   * @return string
   *   The calendar name.
   */
  private function getCalendarName(EventFilter $filter): string {
    $name = 'Salsa am Sund – Termine';
    if ($filter->isEmpty()) {
      return $name;
    }

    $parts = [];
    foreach ($this->loadTerms($filter->organizerIds) as $organizer) {
      $parts[] = (string) $organizer->label();
    }

    if ($filter->includeCourses) {
      // Selected courses are more informative than the generic "Kurs" label.
      $course_titles = array_map(
        static fn (NodeInterface $course): string => (string) $course->label(),
        $this->loadCourses($filter->courseIds),
      );
      if ($course_titles !== []) {
        $parts[] = implode(', ', $course_titles);
      }
      elseif (!$filter->includeEvents) {
        $parts[] = self::COURSE_CATEGORY;
      }
    }

    foreach ($this->loadTerms($filter->locationIds) as $location) {
      $parts[] = (string) $location->label();
    }

    if ($parts === []) {
      return $name;
    }
    return $name . ' – ' . implode(', ', $parts);
  }

  /**
   * Builds the download filename of an occurrence.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The filename, including the .ics extension.
   */
  private function getFilename(EventOccurrence $occurrence): string {
    // Slashes are not allowed in a Content-Disposition filename.
    $title = str_replace(['/', '\\'], '-', (string) $occurrence->node->label());
    return $title . '-' . $occurrence->start->format('Y-m-d') . '.ics';
  }

  /**
   * Returns an ASCII fallback of a download filename.
   *
   * Browsers that ignore RFC 5987 need a plain header value, so the title is
   * transliterated and reduced to safe characters.
   *
   * @param string $filename
   *   The filename.
   *
   * @return string
   *   The ASCII fallback.
   */
  private function getAsciiFilename(string $filename): string {
    $transliterated = $this->transliteration->transliterate($filename);
    return preg_replace('/[^A-Za-z0-9._-]+/', '-', $transliterated) ?? $filename;
  }

  /**
   * Loads the selected course nodes.
   *
   * @param int[] $courseIds
   *   The course node IDs.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The existing course nodes, keyed by a list.
   */
  private function loadCourses(array $courseIds): array {
    if ($courseIds === []) {
      return [];
    }
    return array_values(array_filter(
      $this->entityTypeManager->getStorage('node')->loadMultiple($courseIds),
      static fn (mixed $node): bool => $node instanceof NodeInterface,
    ));
  }

  /**
   * Loads taxonomy terms by ID.
   *
   * @param int[] $termIds
   *   The term IDs.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   The existing terms, keyed by a list.
   */
  private function loadTerms(array $termIds): array {
    if ($termIds === []) {
      return [];
    }
    return array_values(array_filter(
      $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($termIds),
      static fn (mixed $term): bool => $term instanceof TermInterface,
    ));
  }

  /**
   * Returns the event summary.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The summary.
   */
  private function getSummary(EventOccurrence $occurrence): string {
    $title = (string) $occurrence->node->label();
    if ($occurrence->node->bundle() === 'course') {
      $level = $this->detailFieldResolver->getDifficultyLabel($occurrence->node);
      if ($level !== '') {
        return $title . ' (' . $level . ')';
      }
    }
    return $title;
  }

  /**
   * Returns the plain text description including the node URL.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   * @param string $schemeAndHost
   *   The request scheme and host.
   *
   * @return string
   *   The description.
   */
  private function getDescription(EventOccurrence $occurrence, string $schemeAndHost): string {
    $node = $occurrence->node;
    $description = $this->descriptionFormatter->toPlainText($node);
    $url = $schemeAndHost . $node->toUrl()->toString();
    return trim($description . "\n\n" . $url);
  }

  /**
   * Returns the location string.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The location, or an empty string.
   */
  private function getLocation(EventOccurrence $occurrence): string {
    $term = $occurrence->location;
    if ($term === NULL) {
      return '';
    }
    $parts = [(string) $term->label()];
    if ($term->hasField('field_address') && !$term->get('field_address')->isEmpty()) {
      $address = preg_replace('/\s*\R\s*/u', ', ', trim($term->get('field_address')->getString()));
      if ($address !== NULL && $address !== '') {
        $parts[] = $address;
      }
    }
    return implode(', ', $parts);
  }

  /**
   * Returns the event URL.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   * @param string $schemeAndHost
   *   The request scheme and host.
   *
   * @return string
   *   The URL.
   */
  private function getUrl(EventOccurrence $occurrence, string $schemeAndHost): string {
    $node = $occurrence->node;
    if ($node->hasField('field_link') && !$node->get('field_link')->isEmpty()) {
      $uri = $node->get('field_link')->first()?->get('uri')->getString() ?? '';
      if ($uri !== '') {
        return $uri;
      }
    }
    return $schemeAndHost . $node->toUrl()->toString();
  }

  /**
   * Returns the iCal category of an occurrence.
   *
   * Events are categorized by their organizer, courses by a fixed label.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The category, or an empty string when the event has no organizer.
   */
  private function getCategories(EventOccurrence $occurrence): string {
    if ($occurrence->isCourse()) {
      return self::COURSE_CATEGORY;
    }
    return $occurrence->organizer === NULL ? '' : (string) $occurrence->organizer->label();
  }

  /**
   * Returns the number of days to include in the past.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return int
   *   The number of days, between 0 and 90.
   */
  private function getPastDays(Request $request): int {
    $parameters = $request->query->all();
    $past = $parameters['past'] ?? NULL;
    if (!is_scalar($past) || !is_numeric($past)) {
      return self::DEFAULT_PAST_DAYS;
    }
    return max(0, min(self::MAX_PAST_DAYS, (int) $past));
  }

}
