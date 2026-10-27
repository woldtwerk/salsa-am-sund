<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\LessonGenerator;

/**
 * Keeps the lessons of a course in sync with the course itself.
 */
final class LessonHooks {

  use StringTranslationTrait;

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
   * course. Lessons with figures, participants, a photo or a cancellation are
   * kept, so their history survives the course.
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

    $lessons = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);
    foreach ($lessons as $lesson) {
      if (!$lesson instanceof NodeInterface || $lesson->bundle() !== LessonGenerator::LESSON_BUNDLE) {
        continue;
      }
      if ($this->hasRecordedData($lesson)) {
        continue;
      }
      $lesson->delete();
    }
  }

  /**
   * Implements hook_form_alter().
   *
   * Explains how to change the dates of a course that already has lessons,
   * because the generator does not update existing lessons.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id !== 'node_course_form' || !isset($form['field_lessons']) || !is_array($form['field_lessons'])) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $course = $form_object->getEntity();
    if (!$course instanceof NodeInterface || $course->bundle() !== LessonGenerator::COURSE_BUNDLE) {
      return;
    }
    if ($course->get('field_lessons')->isEmpty()) {
      return;
    }

    $weight = $form['field_lessons']['#weight'] ?? 0;
    $form['workspace_events_lesson_hint'] = [
      '#markup' => $this->t('Termine ändern: Stunden löschen, dann Kurs speichern.'),
      '#prefix' => '<div class="messages messages--warning">',
      '#suffix' => '</div>',
      '#weight' => (is_numeric($weight) ? (int) $weight : 0) - 1,
    ];
  }

  /**
   * Returns whether a lesson has data that must survive a course deletion.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   *
   * @return bool
   *   TRUE when the lesson has figures, participants, a photo or is cancelled.
   */
  private function hasRecordedData(NodeInterface $lesson): bool {
    foreach (['field_dance_figures', 'field_participants', 'field_attendance_photo'] as $field_name) {
      if ($lesson->hasField($field_name) && !$lesson->get($field_name)->isEmpty()) {
        return TRUE;
      }
    }

    return $lesson->hasField('field_cancelled')
      && $lesson->get('field_cancelled')->getString() === '1';
  }

}
