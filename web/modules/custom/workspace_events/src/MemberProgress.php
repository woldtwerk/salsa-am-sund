<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

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
   * @var array<int, array<int, array{course: \Drupal\node\NodeInterface, lessons: int}>>
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
   * Returns the published courses the user has lessons in.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array<int, array{course: \Drupal\node\NodeInterface, lessons: int}>
   *   The courses, sorted by title, with the number of lessons the user
   *   participates in.
   */
  public function visitedCourses(int $uid): array {
    if (isset($this->visitedCourses[$uid])) {
      return $this->visitedCourses[$uid];
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $lesson_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', LessonGenerator::LESSON_BUNDLE)
      ->condition('field_participants.target_id', $uid)
      ->execute();
    if ($lesson_ids === []) {
      return $this->visitedCourses[$uid] = [];
    }

    $lesson_ids = array_map(static fn (mixed $id): int => (int) $id, array_values($lesson_ids));
    $course_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', LessonGenerator::COURSE_BUNDLE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_lessons.target_id', $lesson_ids, 'IN')
      ->execute();
    if ($course_ids === []) {
      return $this->visitedCourses[$uid] = [];
    }

    $attended = array_fill_keys($lesson_ids, TRUE);
    $courses = [];
    foreach ($node_storage->loadMultiple($course_ids) as $course) {
      if (!$course instanceof NodeInterface || !$course->hasField('field_lessons')) {
        continue;
      }
      $count = 0;
      foreach ($course->get('field_lessons') as $item) {
        $lesson_id = (int) $item->get('target_id')->getString();
        if (isset($attended[$lesson_id])) {
          $count++;
        }
      }
      if ($count > 0) {
        $courses[] = ['course' => $course, 'lessons' => $count];
      }
    }

    usort($courses, static fn (array $a, array $b): int => strcasecmp((string) $a['course']->label(), (string) $b['course']->label()));

    return $this->visitedCourses[$uid] = $courses;
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
