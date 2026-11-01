<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\SeasonInterface;

/**
 * Finds event occurrences in the new date fields.
 *
 * Events store their recurring dates as Smart Date timestamps, one delta per
 * occurrence. A course occurrence is one lesson of a season of a published
 * course. The lessons themselves are access-restricted, but their dates are
 * public: the queries only look at the published course.
 */
final class EventOccurrenceRepository {

  /**
   * The occurrence cache of the current request.
   *
   * @var array<string, \Drupal\workspace_events\EventOccurrence[]>
   */
  private array $occurrenceCache = [];

  /**
   * The single-event cache of the current request.
   *
   * @var array<int, \Drupal\workspace_events\EventOccurrence|null>
   */
  private array $nodeCache = [];

  /**
   * The single-lesson cache of the current request.
   *
   * Kept apart from the node cache because lesson and node IDs overlap.
   *
   * @var array<int, \Drupal\workspace_events\EventOccurrence|null>
   */
  private array $lessonCache = [];

  /**
   * Constructs an EventOccurrenceRepository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The timezone of the site.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly SiteTimeZone $siteTimeZone,
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Finds event occurrences within a time range.
   *
   * One query finds the lessons in the range whose course is published.
   * Events are queried directly. The results are memoized for the request, so
   * rendering several blocks does not repeat the queries.
   *
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The filter to apply.
   * @param \DateTimeInterface $from
   *   The start of the time range.
   * @param \DateTimeInterface $to
   *   The end of the time range.
   * @param int|null $limit
   *   The maximum number of occurrences to return, or NULL for no limit.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The occurrences, sorted by start date and time.
   */
  public function findOccurrences(EventFilter $filter, \DateTimeInterface $from, \DateTimeInterface $to, ?int $limit = NULL): array {
    $from_timestamp = $from->getTimestamp();
    $to_timestamp = $to->getTimestamp();
    if ($from_timestamp > $to_timestamp || $limit === 0) {
      return [];
    }

    $cache_key = md5(serialize([$filter, $from_timestamp, $to_timestamp, $limit]));
    if (isset($this->occurrenceCache[$cache_key])) {
      return $this->occurrenceCache[$cache_key];
    }

    $occurrences = [];
    if ($filter->includeCourses) {
      $occurrences = $this->findCourseOccurrences($filter, $from_timestamp, $to_timestamp, $limit);
    }
    if ($filter->includeEvents) {
      $occurrences = array_merge(
        $occurrences,
        $this->findEventOccurrences($filter, $from_timestamp, $to_timestamp, $limit),
      );
    }

    usort($occurrences, static function (EventOccurrence $a, EventOccurrence $b): int {
      return [
        $a->start->getTimestamp(),
        $a->sourceKey(),
        (int) $a->node->id(),
      ] <=> [
        $b->start->getTimestamp(),
        $b->sourceKey(),
        (int) $b->node->id(),
      ];
    });

    if ($limit !== NULL) {
      $occurrences = array_slice($occurrences, 0, max(0, $limit));
    }

    $this->occurrenceCache[$cache_key] = $occurrences;

    return $occurrences;
  }

  /**
   * Finds the occurrence of an event.
   *
   * Courses and other bundles have no occurrence of their own anymore, so old
   * per-delta links return 404. Lessons are resolved by
   * findLessonOccurrence(), because their IDs overlap with node IDs.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\workspace_events\EventOccurrence|null
   *   The occurrence, or NULL when the node is not a published event or has no
   *   date.
   */
  public function findOccurrence(NodeInterface $node): ?EventOccurrence {
    $id = (int) $node->id();
    if (array_key_exists($id, $this->nodeCache)) {
      return $this->nodeCache[$id];
    }

    $occurrence = $node->bundle() === 'event' && $node->isPublished()
      ? $this->buildNextEventOccurrence($node)
      : NULL;
    $this->nodeCache[$id] = $occurrence;

    return $occurrence;
  }

  /**
   * Finds the occurrence of a lesson of a published course.
   *
   * Memoized separately from the event cache, because a lesson and a node can
   * share the same numeric ID.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \Drupal\workspace_events\EventOccurrence|null
   *   The occurrence, or NULL when the lesson has no date or belongs to no
   *   published course.
   */
  public function findLessonOccurrence(LessonInterface $lesson): ?EventOccurrence {
    $id = (int) $lesson->id();
    if (array_key_exists($id, $this->lessonCache)) {
      return $this->lessonCache[$id];
    }

    $season = $lesson->getSeason();
    $course = $season?->getCourse();
    $occurrence = $course === NULL || !$course->isPublished()
      ? NULL
      : $this->buildOccurrence(
        $course,
        $lesson,
        'field_lesson_date',
        NULL,
        $this->getSeasonLocation($season),
        $season,
      );
    $this->lessonCache[$id] = $occurrence;

    return $occurrence;
  }

  /**
   * Finds the occurrences of the lessons of published courses.
   *
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The filter to apply.
   * @param int $from
   *   The start timestamp of the range.
   * @param int $to
   *   The end timestamp of the range.
   * @param int|null $limit
   *   The maximum number of course occurrences, or NULL for no limit.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The course occurrences.
   */
  private function findCourseOccurrences(EventFilter $filter, int $from, int $to, ?int $limit): array {
    $lesson_storage = $this->entityTypeManager->getStorage('lesson');
    $query = $lesson_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_lesson_date.value', $this->toStorageValue($to), '<=')
      ->condition('field_lesson_date.end_value', $this->toStorageValue($from), '>=')
      ->condition('season.entity:course_season.course.entity:node.status', NodeInterface::PUBLISHED)
      ->sort('field_lesson_date.value')
      ->sort('id');
    if ($filter->courseIds !== []) {
      $query->condition('season.entity:course_season.course', $filter->courseIds, 'IN');
    }
    if ($filter->locationIds !== []) {
      $query->condition('season.entity:course_season.location', $filter->locationIds, 'IN');
    }
    if ($limit !== NULL) {
      $query->range(0, max(0, $limit));
    }

    $lesson_ids = array_map(static fn (mixed $id): int => (int) $id, array_values($query->execute()));
    if ($lesson_ids === []) {
      return [];
    }

    $lessons = $lesson_storage->loadMultiple($lesson_ids);
    $season_ids = [];
    foreach ($lessons as $lesson) {
      if ($lesson instanceof LessonInterface) {
        $season_id = (int) $lesson->get('season')->getString();
        if ($season_id > 0) {
          $season_ids[$season_id] = $season_id;
        }
      }
    }
    if ($season_ids === []) {
      return [];
    }

    $season_storage = $this->entityTypeManager->getStorage('course_season');
    $seasons = $season_storage->loadMultiple($season_ids);
    $course_ids = [];
    foreach ($seasons as $season) {
      if ($season instanceof SeasonInterface) {
        $course_id = (int) $season->get('course')->getString();
        if ($course_id > 0) {
          $course_ids[$course_id] = $course_id;
        }
      }
    }
    if ($course_ids === []) {
      return [];
    }

    $courses = $this->entityTypeManager->getStorage('node')->loadMultiple($course_ids);
    $occurrences = [];
    foreach ($lessons as $lesson) {
      if (!$lesson instanceof LessonInterface) {
        continue;
      }
      $season = $seasons[(int) $lesson->get('season')->getString()] ?? NULL;
      if (!$season instanceof SeasonInterface) {
        continue;
      }
      $course = $courses[(int) $season->get('course')->getString()] ?? NULL;
      if (!$course instanceof NodeInterface) {
        continue;
      }
      $occurrence = $this->buildOccurrence(
        $course,
        $lesson,
        'field_lesson_date',
        NULL,
        $this->getSeasonLocation($season),
        $season,
      );
      if ($occurrence !== NULL) {
        $occurrences[] = $occurrence;
      }
    }

    return $occurrences;
  }

  /**
   * Finds the occurrences of published events.
   *
   * @param \Drupal\workspace_events\EventFilter $filter
   *   The filter to apply.
   * @param int $from
   *   The start timestamp of the range.
   * @param int $to
   *   The end timestamp of the range.
   * @param int|null $limit
   *   The maximum number of event occurrences, or NULL for no limit.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The event occurrences.
   */
  private function findEventOccurrences(EventFilter $filter, int $from, int $to, ?int $limit): array {
    $query = $this->database->select('node__field_event_date', 'f');
    $query->join('node_field_data', 'n', 'n.nid = f.entity_id AND n.default_langcode = 1 AND n.langcode = f.langcode');
    $query->addField('f', 'entity_id');
    $query->addField('f', 'delta');
    $query->addField('f', 'field_event_date_value', 'value');
    $query->addField('f', 'field_event_date_end_value', 'end_value');
    $query->condition('f.deleted', 0)
      ->condition('n.type', 'event')
      ->condition('n.status', NodeInterface::PUBLISHED)
      ->condition('f.field_event_date_value', $to, '<=')
      ->condition('f.field_event_date_end_value', $from, '>=');
    if ($filter->organizerIds !== []) {
      $query->join('node__field_organizer', 'o', 'o.entity_id = f.entity_id AND o.langcode = f.langcode AND o.deleted = 0');
      $query->condition('o.field_organizer_target_id', $filter->organizerIds, 'IN');
    }
    if ($filter->eventIds !== []) {
      $query->condition('f.entity_id', $filter->eventIds, 'IN');
    }
    if ($filter->locationIds !== []) {
      $query->join('node__field_location', 'l', 'l.entity_id = f.entity_id AND l.langcode = f.langcode AND l.deleted = 0');
      $query->condition('l.field_location_target_id', $filter->locationIds, 'IN');
    }
    $query->orderBy('f.field_event_date_value');
    $query->orderBy('f.entity_id');
    $query->orderBy('f.delta');
    if ($limit !== NULL) {
      $query->range(0, max(0, $limit));
    }

    $statement = $query->execute();
    \assert($statement instanceof StatementInterface);
    /** @var array<int, \stdClass> $rows */
    $rows = $statement->fetchAll();
    if ($rows === []) {
      return [];
    }

    $ids = array_values(array_unique(array_map(
      static fn (\stdClass $row): int => self::rowInt($row, 'entity_id'),
      $rows,
    )));
    $events = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);

    $occurrences = [];
    foreach ($rows as $row) {
      $event = $events[self::rowInt($row, 'entity_id')] ?? NULL;
      if (!$event instanceof NodeInterface) {
        continue;
      }
      $start = self::rowInt($row, 'value');
      if ($start <= 0) {
        continue;
      }
      $occurrences[] = $this->buildEventOccurrence($event, $start, self::rowInt($row, 'end_value'));
    }

    return $occurrences;
  }

  /**
   * Reads an integer column from a database row.
   *
   * @param \stdClass $row
   *   The database row.
   * @param string $property
   *   The column alias.
   *
   * @return int
   *   The integer value, or 0 when the column is missing or not numeric.
   */
  private static function rowInt(\stdClass $row, string $property): int {
    $value = $row->{$property} ?? NULL;
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Builds the next or currently running occurrence of an event.
   *
   * A series that has already ended keeps its last date, so the occurrence of
   * an old event stays available.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\workspace_events\EventOccurrence|null
   *   The occurrence, or NULL when the event has no dates.
   */
  private function buildNextEventOccurrence(NodeInterface $event): ?EventOccurrence {
    if (!$event->hasField('field_event_date')) {
      return NULL;
    }

    $now = $this->time->getRequestTime();
    $next = NULL;
    $last = NULL;
    foreach ($event->get('field_event_date') as $item) {
      $value = (int) $item->get('value')->getString();
      if ($value <= 0) {
        continue;
      }
      $end_value = (int) $item->get('end_value')->getString();
      $end_value = $end_value > 0 ? $end_value : $value;
      $candidate = ['value' => $value, 'end_value' => $end_value];
      if ($end_value >= $now && ($next === NULL || $value < $next['value'])) {
        $next = $candidate;
      }
      if ($last === NULL || $value > $last['value']) {
        $last = $candidate;
      }
    }

    $selected = $next ?? $last;
    if ($selected === NULL) {
      return NULL;
    }

    return $this->buildEventOccurrence($event, $selected['value'], $selected['end_value']);
  }

  /**
   * Builds an occurrence from Smart Date timestamps.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param int $start_timestamp
   *   The start timestamp.
   * @param int $end_timestamp
   *   The end timestamp, or 0 when the date has no end.
   *
   * @return \Drupal\workspace_events\EventOccurrence
   *   The occurrence.
   */
  private function buildEventOccurrence(NodeInterface $event, int $start_timestamp, int $end_timestamp): EventOccurrence {
    $start = $this->dateFromTimestamp($start_timestamp);
    $end = $end_timestamp > 0 ? $this->dateFromTimestamp($end_timestamp) : NULL;

    return new EventOccurrence(
      $event,
      NULL,
      $start,
      $end,
      self::isAllDay($start, $end),
      $this->getOrganizer($event),
      $this->getLocation($event),
      FALSE,
    );
  }

  /**
   * Builds an occurrence from a date range field of a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event or course node.
   * @param \Drupal\workspace_events\Entity\LessonInterface|null $lesson
   *   The lesson of a course occurrence, NULL for an event.
   * @param string $field_name
   *   The name of the date range field.
   * @param \Drupal\taxonomy\TermInterface|null $organizer
   *   The organizer term, if any.
   * @param \Drupal\taxonomy\TermInterface|null $location
   *   The location term, if any.
   * @param \Drupal\workspace_events\Entity\SeasonInterface|null $season
   *   The season of a course occurrence, NULL for an event.
   *
   * @return \Drupal\workspace_events\EventOccurrence|null
   *   The occurrence, or NULL when the field is empty.
   */
  private function buildOccurrence(NodeInterface $node, ?LessonInterface $lesson, string $field_name, ?TermInterface $organizer, ?TermInterface $location, ?SeasonInterface $season = NULL): ?EventOccurrence {
    $date_node = $lesson ?? $node;
    if (!$date_node->hasField($field_name)) {
      return NULL;
    }
    $item = $date_node->get($field_name)->first();
    if ($item === NULL) {
      return NULL;
    }

    $start_value = $item->get('value')->getString();
    if ($start_value === '') {
      return NULL;
    }
    $end_value = $item->get('end_value')->getString();
    $start = $this->dateFromStorage($start_value);
    $end = $end_value === '' ? NULL : $this->dateFromStorage($end_value);
    $cancelled = $lesson !== NULL
      && $lesson->hasField('field_cancelled')
      && $lesson->get('field_cancelled')->getString() === '1';

    return new EventOccurrence(
      $node,
      $lesson,
      $start,
      $end,
      self::isAllDay($start, $end),
      $organizer,
      $location,
      $cancelled,
      $season,
    );
  }

  /**
   * Converts a stored date to the site timezone.
   *
   * Drupal stores date and time fields in UTC.
   *
   * @param string $value
   *   The stored value.
   *
   * @return \DateTimeImmutable
   *   The date in the site timezone.
   */
  private function dateFromStorage(string $value): \DateTimeImmutable {
    return (new \DateTimeImmutable($value, new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE)))
      ->setTimezone($this->siteTimeZone->get());
  }

  /**
   * Converts a Smart Date timestamp to the site timezone.
   *
   * @param int $timestamp
   *   The timestamp.
   *
   * @return \DateTimeImmutable
   *   The date in the site timezone.
   */
  private function dateFromTimestamp(int $timestamp): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone($this->siteTimeZone->get());
  }

  /**
   * Converts a timestamp to the storage format of date fields.
   *
   * @param int $timestamp
   *   The timestamp.
   *
   * @return string
   *   The value in the storage timezone.
   */
  private function toStorageValue(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE))
      ->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
  }

  /**
   * Returns the organizer term of a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The organizer, or NULL when the node has none.
   */
  private function getOrganizer(NodeInterface $node): ?TermInterface {
    if (!$node->hasField('field_organizer')) {
      return NULL;
    }
    $organizer = $node->get('field_organizer')->entity;
    return $organizer instanceof TermInterface ? $organizer : NULL;
  }

  /**
   * Returns the location term of an event node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The location, or NULL when the node has none.
   */
  private function getLocation(NodeInterface $node): ?TermInterface {
    if (!$node->hasField('field_location')) {
      return NULL;
    }
    $location = $node->get('field_location')->entity;
    return $location instanceof TermInterface ? $location : NULL;
  }

  /**
   * Returns the location term of a course season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface|null $season
   *   The season, or NULL when the lesson has none.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The location, or NULL when the season has none.
   */
  private function getSeasonLocation(?SeasonInterface $season): ?TermInterface {
    if ($season === NULL || !$season->hasField('location')) {
      return NULL;
    }
    $location = $season->get('location')->entity;
    return $location instanceof TermInterface ? $location : NULL;
  }

  /**
   * Determines whether a date range represents a whole day.
   *
   * @param \DateTimeImmutable $start
   *   The start in the site timezone.
   * @param \DateTimeImmutable|null $end
   *   The end in the site timezone, if any.
   *
   * @return bool
   *   TRUE when the range starts at 00:00 and ends at 23:59.
   */
  private static function isAllDay(\DateTimeImmutable $start, ?\DateTimeImmutable $end): bool {
    return $end !== NULL
      && $start->format('H:i') === '00:00'
      && $end->format('H:i') === '23:59';
  }

}
