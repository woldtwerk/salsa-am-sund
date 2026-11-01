<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for lesson entities.
 */
interface LessonInterface extends ContentEntityInterface, EntityOwnerInterface, EntityPublishedInterface, EntityChangedInterface {

  /**
   * Gets the lesson creation timestamp.
   *
   * @return int
   *   Creation timestamp of the lesson.
   */
  public function getCreatedTime(): int;

  /**
   * Sets the lesson creation timestamp.
   *
   * @param int $timestamp
   *   The lesson creation timestamp.
   *
   * @return static
   *   The called lesson entity.
   */
  public function setCreatedTime(int $timestamp): static;

}
