<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Entity\SeasonInterface;

/**
 * Migrates the Smart Date series of courses and events to the new date fields.
 */
final class ScheduleMigrator {

  /**
   * The legacy Smart Date field on courses and events.
   */
  public const string LEGACY_FIELD_NAME = 'field_when';

  /**
   * The node bundle of the courses.
   */
  private const string COURSE_BUNDLE = 'course';

  /**
   * The node bundle of the events.
   */
  private const string EVENT_BUNDLE = 'event';

  /**
   * The recurring date field of the events.
   */
  private const string EVENT_DATE_FIELD = 'field_event_date';

  /**
   * Constructs a ScheduleMigrator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager, used to detect the legacy field.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection, used for the exact delta copy.
   * @param \Drupal\workspace_events\SeasonRepository $seasonRepository
   *   The season repository, used to find courses that already have a season.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
    private readonly SeasonRepository $seasonRepository,
  ) {}

  /**
   * Migrates every course and event.
   *
   * @return string
   *   A message about the courses that were skipped, or an empty string when
   *   every legacy course could be migrated. Event series are copied without
   *   a message.
   */
  public function migrate(): string {
    // The migration only runs while the legacy field still exists. After the
    // legacy field removal update it does nothing.
    if (!array_key_exists(self::LEGACY_FIELD_NAME, $this->entityFieldManager->getFieldStorageDefinitions('node'))) {
      return '';
    }

    $message = $this->migrateCourses();
    $this->migrateEvents();

    return $message;
  }

  /**
   * Migrates the course series to one season per course.
   *
   * @return string
   *   A message naming the courses that have no location, or an empty string.
   */
  private function migrateCourses(): string {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::COURSE_BUNDLE)
      ->execute();
    if ($ids === []) {
      return '';
    }

    $migrated = array_flip($this->seasonRepository->courseIdsWithSeason());
    $skipped = [];

    foreach ($storage->loadMultiple($ids) as $course) {
      if (!$course instanceof NodeInterface) {
        continue;
      }
      if (isset($migrated[(int) $course->id()])) {
        continue;
      }
      if (!$course->hasField(self::LEGACY_FIELD_NAME) || $course->get(self::LEGACY_FIELD_NAME)->isEmpty()) {
        continue;
      }
      if (!$course->hasField('field_location') || $course->get('field_location')->isEmpty()) {
        $skipped[] = (string) $course->label();
        continue;
      }

      $season = $this->createSeason($course);
      if ($season === NULL) {
        continue;
      }

      // Saving the season generates its lessons through the insert hook.
      $season->setSyncing(TRUE);
      $season->save();
    }

    if ($skipped === []) {
      return '';
    }

    return sprintf(
      'No season was created for these courses because they have no location: %s',
      implode(', ', $skipped),
    );
  }

  /**
   * Creates the season of a course from its legacy series.
   *
   * Every legacy delta is copied one to one, so the season keeps the dates and
   * the recurrence of the course. Afterwards the recurrence rules point at the
   * season schedule.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course node.
   *
   * @return \Drupal\workspace_events\Entity\SeasonInterface|null
   *   The unsaved season, or NULL when the course has no usable series.
   */
  private function createSeason(NodeInterface $course): ?SeasonInterface {
    $items = $course->get(self::LEGACY_FIELD_NAME);
    if ($items->isEmpty()) {
      return NULL;
    }

    $schedule = [];
    $rule_ids = [];
    foreach ($items as $item) {
      $value = (int) $item->get('value')->getString();
      if ($value <= 0) {
        continue;
      }
      $end_value = (int) $item->get('end_value')->getString();
      $rrule = (int) $item->get('rrule')->getString();
      if ($rrule > 0) {
        $rule_ids[$rrule] = $rrule;
      }
      $schedule[] = [
        'value' => $value,
        'end_value' => $end_value > 0 ? $end_value : $value,
        'duration' => (int) $item->get('duration')->getString(),
        'rrule' => $rrule,
        'rrule_index' => (int) $item->get('rrule_index')->getString(),
        'timezone' => (string) $item->get('timezone')->getString(),
      ];
    }
    if ($schedule === []) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('course_season');
    $season = $storage->create([
      'course' => $course->id(),
      'field_schedule' => $schedule,
      'location' => ['target_id' => (int) $course->get('field_location')->target_id],
      'uid' => $course->getOwnerId(),
      'langcode' => $course->language()->getId(),
    ]);
    if (!$season instanceof SeasonInterface) {
      return NULL;
    }

    $this->repointRules(array_values($rule_ids));

    return $season;
  }

  /**
   * Points the migrated course rules at the season schedule.
   *
   * A direct update keeps the stored instances and overrides of the rules; the
   * Smart Date API would regenerate them.
   *
   * @param int[] $rule_ids
   *   The IDs of the rules that belong to the migrated schedule.
   */
  private function repointRules(array $rule_ids): void {
    if ($rule_ids === [] || !$this->entityTypeManager->hasDefinition('smart_date_rule')) {
      return;
    }

    $this->database->update('smart_date_rule')
      ->fields([
        'entity_type' => 'course_season',
        'bundle' => 'course_season',
        'field_name' => 'field_schedule',
      ])
      ->condition('rid', $rule_ids, 'IN')
      ->execute();
  }

  /**
   * Copies every legacy event delta to the recurring event date field.
   *
   * The rows are copied as they are, including every translation and revision,
   * so a series keeps all its dates and its data even when it has already
   * ended. Afterwards the recurrence rules of the events point at the new
   * field; saving the rule entities would not regenerate their stored
   * instances, but a direct update avoids touching them needlessly.
   */
  private function migrateEvents(): void {
    $source_table = 'node__field_when';
    $target_table = 'node__field_event_date';
    $revision_source_table = 'node_revision__field_when';
    $revision_target_table = 'node_revision__field_event_date';
    $schema = $this->database->schema();
    if (!$schema->tableExists($source_table) || !$schema->tableExists($target_table)) {
      return;
    }

    // Values of an earlier run are replaced, so the copy is exact. Event rows
    // only: field_event_date has no other bundle.
    $this->database->delete($target_table)
      ->condition('bundle', self::EVENT_BUNDLE)
      ->execute();
    if ($schema->tableExists($revision_source_table) && $schema->tableExists($revision_target_table)) {
      $this->database->delete($revision_target_table)
        ->condition('bundle', self::EVENT_BUNDLE)
        ->execute();
    }

    $columns = [
      'bundle' => 'bundle',
      'deleted' => 'deleted',
      'entity_id' => 'entity_id',
      'revision_id' => 'revision_id',
      'langcode' => 'langcode',
      'delta' => 'delta',
      'field_when_value' => 'field_event_date_value',
      'field_when_end_value' => 'field_event_date_end_value',
      'field_when_duration' => 'field_event_date_duration',
      'field_when_rrule' => 'field_event_date_rrule',
      'field_when_rrule_index' => 'field_event_date_rrule_index',
      'field_when_timezone' => 'field_event_date_timezone',
    ];
    $this->copyFieldRows($source_table, $target_table, $columns);
    if ($schema->tableExists($revision_source_table) && $schema->tableExists($revision_target_table)) {
      $this->copyFieldRows($revision_source_table, $revision_target_table, $columns);
    }

    if ($this->entityTypeManager->hasDefinition('smart_date_rule')) {
      $this->database->update('smart_date_rule')
        ->fields(['field_name' => self::EVENT_DATE_FIELD])
        ->condition('entity_type', 'node')
        ->condition('bundle', self::EVENT_BUNDLE)
        ->condition('field_name', self::LEGACY_FIELD_NAME)
        ->execute();
    }

    // The copied rows did not go through the entity API, so the node cache has
    // to forget the events that may have been loaded already.
    $this->entityTypeManager->getStorage('node')->resetCache();
  }

  /**
   * Copies all event rows of a legacy field table into the target table.
   *
   * @param string $source_table
   *   The source field data table.
   * @param string $target_table
   *   The target field data table.
   * @param array<string, string> $columns
   *   The target columns keyed by source column.
   */
  private function copyFieldRows(string $source_table, string $target_table, array $columns): void {
    $select = $this->database->select($source_table, 'source');
    $select->fields('source', array_keys($columns));
    $select->condition('source.bundle', self::EVENT_BUNDLE);
    $select->condition('source.deleted', 0);

    $insert = $this->database->insert($target_table);
    $insert->fields(array_values($columns));
    $insert->from($select);
    $insert->execute();
  }

}
