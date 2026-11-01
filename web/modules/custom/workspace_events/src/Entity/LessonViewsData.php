<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Entity;

use Drupal\views\EntityViewsData;

/**
 * Provides the views data for the lesson entity type.
 */
final class LessonViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The views data, keyed by table and field.
   */
  public function getViewsData(): array {
    /** @var array<string, mixed> $data */
    $data = parent::getViewsData();

    $lesson_data = $data['lesson'] ?? [];
    if (!is_array($lesson_data)) {
      $lesson_data = [];
    }
    $lesson_data['lesson_bulk_form'] = [
      'title' => $this->t('Lesson operations bulk form'),
      'help' => $this->t('Add a form element that lets you run operations on multiple lessons.'),
      'field' => [
        'id' => 'bulk_form',
      ],
    ];
    $data['lesson'] = $lesson_data;

    return $data;
  }

}
