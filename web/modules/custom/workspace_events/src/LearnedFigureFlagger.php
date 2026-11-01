<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_events\Entity\LessonInterface;

/**
 * Marks the dance figures of a lesson as learned for its participants.
 *
 * Members become participants of a lesson by an editor, so the figures the
 * lesson covered are added to their learned figures automatically. The flagger
 * only ever adds flags: removing a participant or a figure from a lesson keeps
 * what the member already learned.
 */
final class LearnedFigureFlagger {

  /**
   * The flag ID of the learned dance figures.
   */
  public const string FLAG_ID = 'learned';

  /**
   * Constructs a LearnedFigureFlagger.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\flag\FlagServiceInterface|null $flagService
   *   The flag service, or NULL when the Flag module is not installed yet.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?FlagServiceInterface $flagService,
  ) {}

  /**
   * Flags the new figure and participant combinations of a lesson.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson that was saved.
   */
  public function flag(LessonInterface $lesson): void {
    if ($this->flagService === NULL) {
      return;
    }
    if (!$lesson->hasField('field_participants') || !$lesson->hasField('field_dance_figures')) {
      return;
    }

    $flag = $this->flagService->getFlagById(self::FLAG_ID);
    if (!$flag instanceof FlagInterface) {
      return;
    }

    $participant_ids = $this->targetIds($lesson, 'field_participants');
    $figure_ids = $this->targetIds($lesson, 'field_dance_figures');
    if ($participant_ids === [] || $figure_ids === []) {
      return;
    }

    $original = $lesson->getOriginal();
    $new_participant_ids = $original === NULL
      ? $participant_ids
      : array_values(array_diff($participant_ids, $this->targetIds($original, 'field_participants')));
    $new_figure_ids = $original === NULL
      ? $figure_ids
      : array_values(array_diff($figure_ids, $this->targetIds($original, 'field_dance_figures')));

    if ($new_participant_ids === [] && $new_figure_ids === []) {
      return;
    }

    $pairs = [];
    foreach ($participant_ids as $participant_id) {
      foreach ($new_figure_ids as $figure_id) {
        $pairs[$participant_id][$figure_id] = TRUE;
      }
    }
    foreach ($new_participant_ids as $participant_id) {
      foreach ($figure_ids as $figure_id) {
        $pairs[$participant_id][$figure_id] = TRUE;
      }
    }
    if ($pairs === []) {
      return;
    }

    $existing = $this->existingFlaggings($flag, array_keys($pairs), $figure_ids);
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($pairs));
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($figure_ids);

    foreach ($pairs as $participant_id => $figure_ids_for_user) {
      $account = $users[$participant_id] ?? NULL;
      if (!$account instanceof UserInterface || $account->isBlocked()) {
        continue;
      }
      foreach (array_keys($figure_ids_for_user) as $figure_id) {
        $term = $terms[$figure_id] ?? NULL;
        if (!$term instanceof TermInterface || !$term->isPublished()) {
          continue;
        }
        if (isset($existing[$participant_id][$figure_id])) {
          continue;
        }
        $this->flagService->flag($flag, $term, $account);
        $existing[$participant_id][$figure_id] = TRUE;
      }
    }
  }

  /**
   * Returns the existing flaggings for the given users and terms.
   *
   * @param \Drupal\flag\FlagInterface $flag
   *   The flag.
   * @param int[] $user_ids
   *   The user IDs.
   * @param int[] $term_ids
   *   The taxonomy term IDs.
   *
   * @return array<int, array<int, true>>
   *   The existing combinations, keyed by user ID and term ID.
   */
  private function existingFlaggings(FlagInterface $flag, array $user_ids, array $term_ids): array {
    $storage = $this->entityTypeManager->getStorage('flagging');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', $flag->id())
      ->condition('uid', $user_ids, 'IN')
      ->condition('entity_type', 'taxonomy_term')
      ->condition('entity_id', $term_ids, 'IN')
      ->execute();

    $existing = [];
    foreach ($storage->loadMultiple($ids) as $flagging) {
      if (!$flagging instanceof FlaggingInterface) {
        continue;
      }
      $user_id = (int) $flagging->get('uid')->getString();
      $term_id = (int) $flagging->get('entity_id')->getString();
      $existing[$user_id][$term_id] = TRUE;
    }
    return $existing;
  }

  /**
   * Returns the unique target IDs of an entity reference field.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   * @param string $field_name
   *   The field name.
   *
   * @return int[]
   *   The target IDs.
   */
  private function targetIds(LessonInterface $lesson, string $field_name): array {
    if (!$lesson->hasField($field_name)) {
      return [];
    }

    $ids = [];
    foreach ($lesson->get($field_name) as $item) {
      $target_id = $item->get('target_id')->getString();
      if ($target_id === '' || !ctype_digit($target_id)) {
        continue;
      }
      $id = (int) $target_id;
      if ($id > 0) {
        $ids[$id] = $id;
      }
    }
    return array_values($ids);
  }

}
