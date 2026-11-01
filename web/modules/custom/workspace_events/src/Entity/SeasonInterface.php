<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\node\NodeInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for course season entities.
 */
interface SeasonInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Gets the course of the season.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The course, or NULL when the referenced course no longer exists.
   */
  public function getCourse(): ?NodeInterface;

  /**
   * Returns whether the schedule changed compared to the stored season.
   *
   * @return bool
   *   TRUE when the first lesson, the last lesson or the interval changed.
   */
  public function hasScheduleChanged(): bool;

}
