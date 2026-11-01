<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Finds the course that references a lesson.
 *
 * Lessons are generated from the schedule of a course and belong to exactly
 * one course. Both the queries that need the course of a lesson and the
 * consumers that output a lesson resolve it here, so the lookup exists only
 * once.
 */
final class LessonCourseResolver {

  /**
   * The course cache of the current request.
   *
   * @var array<string, \Drupal\node\NodeInterface|null>
   */
  private array $courseCache = [];

  /**
   * Constructs a LessonCourseResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Finds the course that references a lesson.
   *
   * @param int $lesson_id
   *   The lesson ID.
   * @param bool $published_only
   *   Whether only a published course may be returned.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The course, or NULL when the lesson belongs to no matching course.
   */
  public function findCourse(int $lesson_id, bool $published_only = FALSE): ?NodeInterface {
    $cache_key = $lesson_id . ':' . (int) $published_only;
    if (array_key_exists($cache_key, $this->courseCache)) {
      return $this->courseCache[$cache_key];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', LessonGenerator::COURSE_BUNDLE)
      ->condition('field_lessons.target_id', $lesson_id)
      ->sort('nid')
      ->range(0, 1);
    if ($published_only) {
      $query->condition('status', NodeInterface::PUBLISHED);
    }

    $ids = $query->execute();
    $course = NULL;
    if ($ids !== []) {
      $loaded = $storage->load(reset($ids));
      $course = $loaded instanceof NodeInterface ? $loaded : NULL;
    }

    return $this->courseCache[$cache_key] = $course;
  }

}
