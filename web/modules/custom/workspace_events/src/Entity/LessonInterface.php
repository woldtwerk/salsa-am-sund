<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\node\NodeInterface;
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

  /**
   * Gets the season of the lesson.
   *
   * @return \Drupal\workspace_events\Entity\SeasonInterface|null
   *   The season, or NULL when the referenced season no longer exists.
   */
  public function getSeason(): ?SeasonInterface;

  /**
   * Gets the course of the lesson through its season.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The course, or NULL when the lesson has no season or course.
   */
  public function getCourse(): ?NodeInterface;

  /**
   * Returns whether the lesson has data that must never be deleted.
   *
   * @return bool
   *   TRUE when the lesson has participants, figures or a cancellation.
   */
  public function hasRecordedData(): bool;

  /**
   * Returns whether the lesson is cancelled.
   *
   * @return bool
   *   TRUE when the lesson is cancelled.
   */
  public function isCancelled(): bool;

}
