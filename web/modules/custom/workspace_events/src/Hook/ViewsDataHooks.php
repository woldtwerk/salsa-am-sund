<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Provides the views data of the course season.
 */
final class ViewsDataHooks {

  /**
   * Implements hook_views_data_alter().
   *
   * Entity reference base fields default to a numeric filter on the target ID.
   * The course of a season should be selectable by title, so the filter uses
   * core's entity reference filter, which reads the target type and bundles
   * from the "course" base field.
   *
   * @param array<mixed> $data
   *   The views data.
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    if (!isset($data['course_season']) || !is_array($data['course_season'])) {
      return;
    }
    if (!isset($data['course_season']['course']) || !is_array($data['course_season']['course'])) {
      return;
    }

    $field = &$data['course_season']['course'];
    if (!isset($field['filter']) || !is_array($field['filter'])) {
      return;
    }
    $field['filter']['id'] = 'entity_reference';
  }

}
