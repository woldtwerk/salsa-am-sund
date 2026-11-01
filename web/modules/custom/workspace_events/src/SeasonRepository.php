<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\SeasonInterface;

/**
 * Queries the lessons of a course season.
 */
final class SeasonRepository {

  /**
   * The current and next seasons of the current request, keyed by course ID.
   *
   * @var array<int, \Drupal\workspace_events\Entity\SeasonInterface[]>
   */
  private array $currentAndNextCache = [];

  /**
   * Constructs a SeasonRepository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection, used for the lesson count per season.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager, used to resolve the storage of the season reference.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly SiteTimeZone $siteTimeZone,
  ) {}

  /**
   * Returns the current and the next season of a course.
   *
   * A season is current or upcoming while its last lesson has not passed. The
   * query returns at most two seasons in schedule order, so a course with
   * several future seasons only shows the two nearest ones. The result is
   * memoized for the request.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return \Drupal\workspace_events\Entity\SeasonInterface[]
   *   The current and next season, at most two.
   */
  public function currentAndNext(NodeInterface $course): array {
    if ($course->isNew()) {
      return [];
    }

    $course_id = (int) $course->id();
    if (isset($this->currentAndNextCache[$course_id])) {
      return $this->currentAndNextCache[$course_id];
    }

    $today = $this->siteTimeZone->now()->format('Y-m-d');
    $storage = $this->entityTypeManager->getStorage('course_season');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('course', $course_id)
      ->condition('last', $today, '>=')
      ->sort('field_schedule.value', 'ASC')
      ->range(0, 2)
      ->execute();
    if ($ids === []) {
      return $this->currentAndNextCache[$course_id] = [];
    }

    $seasons = [];
    foreach ($storage->loadMultiple($ids) as $season) {
      if ($season instanceof SeasonInterface) {
        $seasons[] = $season;
      }
    }

    return $this->currentAndNextCache[$course_id] = $seasons;
  }

  /**
   * Returns whether a course or season has lessons with recorded data.
   *
   * Recorded data is a participant, a figure or a cancellation. The lessons of
   * such a course or season must not be regenerated or deleted.
   *
   * @param \Drupal\node\NodeInterface|\Drupal\workspace_events\Entity\SeasonInterface $target
   *   The course or the season.
   *
   * @return bool
   *   TRUE when at least one lesson has recorded data.
   */
  public function hasRecordedLessons(NodeInterface|SeasonInterface $target): bool {
    if ($target instanceof SeasonInterface && $target->isNew()) {
      return FALSE;
    }

    $query = $this->entityTypeManager->getStorage('lesson')->getQuery()
      ->accessCheck(FALSE);

    if ($target instanceof SeasonInterface) {
      $query->condition('season', $target->id());
    }
    else {
      $query->condition('season.entity:course_season.course', $target->id());
    }

    $recorded = $query->orConditionGroup()
      ->condition('field_participants.target_id', NULL, 'IS NOT NULL')
      ->condition('field_dance_figures.target_id', NULL, 'IS NOT NULL')
      ->condition('field_cancelled', 1);

    $query->condition($recorded)
      ->count()
      ->range(0, 1);

    return (bool) $query->execute();
  }

  /**
   * Returns the IDs of the courses that already have a season.
   *
   * @return int[]
   *   The course IDs.
   */
  public function courseIdsWithSeason(): array {
    $storage = $this->entityTypeManager->getStorage('course_season');
    \assert($storage instanceof SqlContentEntityStorage);
    $field_storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions('course_season');
    if (!isset($field_storage_definitions['course'])) {
      return [];
    }

    $table_mapping = $storage->getTableMapping();
    $table = $table_mapping->getFieldTableName('course');
    $column = $table_mapping->getFieldColumnName($field_storage_definitions['course'], 'target_id');
    $result = $this->database->select($table, 'season')
      ->fields('season', [$column])
      ->execute();
    \assert($result instanceof StatementInterface);

    /** @var array<int|string> $ids */
    $ids = $result->fetchCol();

    return array_map(static fn (int|string $id): int => (int) $id, $ids);
  }

  /**
   * Loads the lessons of a season in date order.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return \Drupal\workspace_events\Entity\LessonInterface[]
   *   The lessons, in date order.
   */
  public function loadLessons(SeasonInterface $season): array {
    if ($season->isNew()) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('lesson');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('season', $season->id())
      ->sort('field_lesson_date.value')
      ->execute();
    if ($ids === []) {
      return [];
    }

    $lessons = [];
    foreach ($storage->loadMultiple($ids) as $lesson) {
      if ($lesson instanceof LessonInterface) {
        $lessons[] = $lesson;
      }
    }

    return $lessons;
  }

  /**
   * Counts the lessons of several seasons in one query.
   *
   * @param int[] $season_ids
   *   The season IDs.
   *
   * @return array<int, int>
   *   The lesson count keyed by season ID.
   */
  public function countLessonsBySeason(array $season_ids): array {
    if ($season_ids === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('lesson');
    \assert($storage instanceof SqlContentEntityStorage);
    $field_storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions('lesson');
    if (!isset($field_storage_definitions['season'])) {
      return [];
    }

    $table_mapping = $storage->getTableMapping();
    $table = $table_mapping->getFieldTableName('season');
    $season_column = $table_mapping->getFieldColumnName($field_storage_definitions['season'], 'target_id');
    $id_columns = $table_mapping->getColumnNames('id');
    $id_column = $table === $storage->getEntityType()->getBaseTable()
      ? (string) reset($id_columns)
      : 'entity_id';

    $query = $this->database->select($table, 'lesson');
    $query->addField('lesson', $season_column, 'season_id');
    $query->addExpression(sprintf('COUNT(%s)', $id_column), 'lesson_count');
    $result = $query
      ->condition($season_column, $season_ids, 'IN')
      ->groupBy($season_column)
      ->execute();
    \assert($result instanceof StatementInterface);

    /** @var array<int|string, int|string> $counts */
    $counts = $result->fetchAllKeyed();

    $lesson_counts = [];
    foreach ($counts as $season_id => $count) {
      $lesson_counts[(int) $season_id] = (int) $count;
    }

    return $lesson_counts;
  }

}
