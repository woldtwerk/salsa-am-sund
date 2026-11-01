<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\LessonGenerator;

/**
 * Keeps the lessons of a course in sync with the course itself.
 */
final class LessonHooks {

  /**
   * Constructs the lesson hooks.
   *
   * @param \Drupal\workspace_events\LessonGenerator $lessonGenerator
   *   The lesson generator.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly LessonGenerator $lessonGenerator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_presave() for node entities.
   *
   * Creates the lessons of a course before the course is saved, so the course
   * stores the references in the same save. References to lessons that no
   * longer exist are dropped at the same time.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being saved.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    if ($node->bundle() !== LessonGenerator::COURSE_BUNDLE) {
      return;
    }
    $this->lessonGenerator->generate($node);
  }

  /**
   * Implements hook_ENTITY_TYPE_predelete() for node entities.
   *
   * Lessons without any recorded attendance data are deleted with their
   * course. Lessons with figures, participants or a cancellation are kept, so
   * their history survives the course.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being deleted.
   */
  #[Hook('node_predelete')]
  public function nodePredelete(NodeInterface $node): void {
    if ($node->bundle() !== LessonGenerator::COURSE_BUNDLE || !$node->hasField('field_lessons')) {
      return;
    }

    $ids = [];
    foreach ($node->get('field_lessons') as $item) {
      $target_id = $item->get('target_id')->getString();
      if ($target_id !== '' && ctype_digit($target_id)) {
        $ids[] = (int) $target_id;
      }
    }
    if ($ids === []) {
      return;
    }

    $lessons = $this->entityTypeManager->getStorage('lesson')->loadMultiple($ids);
    foreach ($lessons as $lesson) {
      if (!$lesson instanceof LessonInterface) {
        continue;
      }
      if ($this->hasRecordedData($lesson)) {
        continue;
      }
      $lesson->delete();
    }
  }

  /**
   * Returns whether a lesson has data that must survive a course deletion.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return bool
   *   TRUE when the lesson has figures, participants or is cancelled.
   */
  private function hasRecordedData(LessonInterface $lesson): bool {
    foreach (['field_dance_figures', 'field_participants'] as $field_name) {
      if ($lesson->hasField($field_name) && !$lesson->get($field_name)->isEmpty()) {
        return TRUE;
      }
    }

    return $lesson->hasField('field_cancelled')
      && $lesson->get('field_cancelled')->getString() === '1';
  }

}
