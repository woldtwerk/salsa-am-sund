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
   * Gets the start timestamp of the first lesson.
   *
   * @return int|null
   *   The earliest start timestamp of the schedule, or NULL without dates.
   */
  public function getFirstLessonStart(): ?int;

  /**
   * Gets the end timestamp of the last lesson.
   *
   * @return int|null
   *   The latest end timestamp of the schedule, or NULL without dates.
   */
  public function getLastLessonEnd(): ?int;

  /**
   * Gets the interval of the weekly recurrence in weeks.
   *
   * @return int
   *   The interval in weeks, or 1 for a schedule without a recurrence rule.
   */
  public function getInterval(): int;

  /**
   * Returns whether the schedule changed compared to the stored season.
   *
   * @return bool
   *   TRUE when a date of the schedule was added, removed or changed.
   */
  public function hasScheduleChanged(): bool;

}
