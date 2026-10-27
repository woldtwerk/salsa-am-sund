<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides the views data of the course lessons.
 */
final class ViewsDataHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_views_data_alter().
   *
   * The course of a lesson is not a field of the lesson; it is the reverse of
   * the course's "Stunden" field. The generic entity views data can therefore
   * only filter the course by its numeric node ID. This adds a course
   * reference filter to the node table, so the exposed filter lists the
   * courses by title.
   *
   * @param array<mixed> $data
   *   The views data.
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    if (!isset($data['node_field_data']) || !is_array($data['node_field_data'])) {
      return;
    }

    $data['node_field_data']['course_reference'] = [
      'title' => $this->t('Kurs'),
      'help' => $this->t('Filtert Unterrichtsstunden nach ihrem Kurs.'),
      'real field' => 'nid',
      'filter' => [
        'title' => $this->t('Kurs'),
        'help' => $this->t('Filtert Unterrichtsstunden nach ihrem Kurs.'),
        'id' => 'entity_reference',
        'entity type' => 'node',
        'entity_type' => 'node',
        'entity field' => 'field_lessons',
      ],
    ];
  }

}
