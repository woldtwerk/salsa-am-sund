<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\SeasonInterface;

/**
 * Reads the course and figure progress of a member.
 *
 * The results are memoized per request, because a profile renders several
 * sections that read the same entities.
 */
final class MemberProgress {

  /**
   * The visited courses, keyed by user ID.
   *
   * @var array<int, array<int, array{course: \Drupal\node\NodeInterface, seasons: array<int, array{season: \Drupal\workspace_events\Entity\SeasonInterface, lessons: int}>}>>
   */
  private array $visitedCourses = [];

  /**
   * The flagged figures, keyed by flag ID and user ID.
   *
   * @var array<string, array<int, \Drupal\taxonomy\TermInterface>>
   */
  private array $flaggedFigures = [];

  /**
   * Constructs a MemberProgress.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the published courses the user has lessons in, grouped by season.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array<int, array{course: \Drupal\node\NodeInterface, seasons: array<int, array{season: \Drupal\workspace_events\Entity\SeasonInterface, lessons: int}>}>
   *   The courses, sorted by title, with the number of lessons the user
   *   participates in per season. The seasons are sorted newest first.
   */
  public function visitedCourses(int $uid): array {
    if (isset($this->visitedCourses[$uid])) {
      return $this->visitedCourses[$uid];
    }

    $lesson_storage = $this->entityTypeManager->getStorage('lesson');
    $lesson_ids = $lesson_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_participants.target_id', $uid)
      ->execute();
    if ($lesson_ids === []) {
      return $this->visitedCourses[$uid] = [];
    }

    $counts = [];
    $season_ids = [];
    foreach ($lesson_storage->loadMultiple($lesson_ids) as $lesson) {
      if (!$lesson instanceof LessonInterface) {
        continue;
      }
      $season_id = (int) $lesson->get('season')->getString();
      if ($season_id <= 0) {
        continue;
      }
      $season_ids[$season_id] = $season_id;
      $counts[$season_id] = ($counts[$season_id] ?? 0) + 1;
    }
    if ($season_ids === []) {
      return $this->visitedCourses[$uid] = [];
    }

    $seasons = $this->entityTypeManager->getStorage('course_season')->loadMultiple($season_ids);
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
      return $this->visitedCourses[$uid] = [];
    }

    $courses = $this->entityTypeManager->getStorage('node')->loadMultiple($course_ids);
    $grouped = [];
    foreach ($seasons as $season) {
      if (!$season instanceof SeasonInterface) {
        continue;
      }
      $course = $courses[(int) $season->get('course')->getString()] ?? NULL;
      if (!$course instanceof NodeInterface
        || $course->bundle() !== LessonGenerator::COURSE_BUNDLE
        || !$course->isPublished()) {
        continue;
      }
      $course_id = (int) $course->id();
      $grouped[$course_id] ??= ['course' => $course, 'seasons' => []];
      $grouped[$course_id]['seasons'][] = [
        'season' => $season,
        'lessons' => $counts[(int) $season->id()] ?? 0,
      ];
    }

    $visited = array_values($grouped);
    foreach ($visited as &$entry) {
      usort($entry['seasons'], static fn (array $a, array $b): int => ($b['season']->getFirstLessonStart() ?? 0) <=> ($a['season']->getFirstLessonStart() ?? 0));
    }
    unset($entry);
    usort($visited, static fn (array $a, array $b): int => strcasecmp((string) $a['course']->label(), (string) $b['course']->label()));

    return $this->visitedCourses[$uid] = $visited;
  }

  /**
   * Returns the published dance figures the user flagged.
   *
   * @param int $uid
   *   The user ID.
   * @param \Drupal\workspace_events\DanceFigureFlag $flag
   *   The flag.
   *
   * @return \Drupal\taxonomy\TermInterface[]
   *   The flagged terms, sorted by name.
   */
  public function flaggedFigures(int $uid, DanceFigureFlag $flag): array {
    $key = $flag->value . ':' . $uid;
    if (isset($this->flaggedFigures[$key])) {
      return $this->flaggedFigures[$key];
    }

    $flagging_storage = $this->entityTypeManager->getStorage('flagging');
    $flagging_ids = $flagging_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', $flag->value)
      ->condition('uid', $uid)
      ->condition('entity_type', 'taxonomy_term')
      ->execute();
    if ($flagging_ids === []) {
      return $this->flaggedFigures[$key] = [];
    }

    $term_ids = [];
    foreach ($flagging_storage->loadMultiple($flagging_ids) as $flagging) {
      if (!$flagging instanceof FlaggingInterface) {
        continue;
      }
      $term_ids[] = (int) $flagging->get('entity_id')->getString();
    }
    if ($term_ids === []) {
      return $this->flaggedFigures[$key] = [];
    }

    $terms = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids) as $term) {
      if ($term instanceof TermInterface && $term->isPublished()) {
        $terms[] = $term;
      }
    }
    usort($terms, static fn (TermInterface $a, TermInterface $b): int => strcasecmp((string) $a->label(), (string) $b->label()));

    return $this->flaggedFigures[$key] = $terms;
  }

}
