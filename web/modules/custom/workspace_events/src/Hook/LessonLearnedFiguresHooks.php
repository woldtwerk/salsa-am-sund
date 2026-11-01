<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\LearnedFigureFlagger;

/**
 * Marks the dance figures of saved lessons as learned for their participants.
 */
final class LessonLearnedFiguresHooks {

  /**
   * Constructs the lesson learned figures hooks.
   *
   * @param \Drupal\workspace_events\LearnedFigureFlagger $flagger
   *   The learned figure flagger.
   */
  public function __construct(
    private readonly LearnedFigureFlagger $flagger,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for lesson entities.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The created lesson.
   */
  #[Hook('lesson_insert')]
  public function lessonInsert(LessonInterface $lesson): void {
    $this->flagger->flag($lesson);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for lesson entities.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The updated lesson.
   */
  #[Hook('lesson_update')]
  public function lessonUpdate(LessonInterface $lesson): void {
    $this->flagger->flag($lesson);
  }

}
