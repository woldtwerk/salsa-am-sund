<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\EntityReference;

/**
 * Filters a course node by the course of a lesson.
 *
 * A lesson has no course field: the course references its lessons. The views
 * data therefore maps this filter to the node ID of the course node, and the
 * exposed selection handler must list course nodes. The reference field
 * (field_lessons) targets lessons, so its target entity type cannot be used
 * for the selection handler.
 */
#[ViewsFilter('workspace_events_course_reference')]
final class CourseReference extends EntityReference {

  /**
   * {@inheritdoc}
   */
  protected function getReferencedEntityType(): EntityTypeInterface {
    $entity_type_id = $this->definition['entity_type'] ?? 'node';
    if (!is_string($entity_type_id) || $entity_type_id === '') {
      $entity_type_id = 'node';
    }
    return $this->entityTypeManager->getDefinition($entity_type_id);
  }

}
