<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\LearnedFigureFlagger;
use Drupal\workspace_events\LessonGenerator;

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
   * Implements hook_ENTITY_TYPE_insert() for node entities.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The created node.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->flag($node);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for node entities.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The updated node.
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->flag($node);
  }

  /**
   * Flags the lesson figures for the lesson participants.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The saved node.
   */
  private function flag(NodeInterface $node): void {
    if ($node->bundle() !== LessonGenerator::LESSON_BUNDLE) {
      return;
    }
    $this->flagger->flag($node);
  }

}
