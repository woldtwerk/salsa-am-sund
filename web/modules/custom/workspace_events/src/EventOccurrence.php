<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\SeasonInterface;

/**
 * Immutable value object for a single event occurrence.
 *
 * An occurrence belongs to an event node or to one lesson of a course. The
 * node carries the data shown to visitors, so a course occurrence points at
 * the course while the lesson provides the date and the cancellation flag.
 */
final readonly class EventOccurrence {

  /**
   * Constructs an EventOccurrence.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event or course node.
   * @param \Drupal\workspace_events\Entity\LessonInterface|null $lesson
   *   The lesson of a course occurrence, NULL for an event.
   * @param \DateTimeImmutable $start
   *   The start date and time, in the site timezone.
   * @param \DateTimeImmutable|null $end
   *   The end date and time, or NULL when the occurrence has no known end.
   * @param bool $allDay
   *   Whether the occurrence lasts the whole day.
   * @param \Drupal\taxonomy\TermInterface|null $organizer
   *   The organizer term of an event, NULL for courses.
   * @param \Drupal\taxonomy\TermInterface|null $location
   *   The location term, if any.
   * @param bool $cancelled
   *   Whether the lesson was cancelled.
   * @param \Drupal\workspace_events\Entity\SeasonInterface|null $season
   *   The season of a course occurrence, NULL for an event.
   */
  public function __construct(
    public NodeInterface $node,
    public ?LessonInterface $lesson,
    public \DateTimeImmutable $start,
    public ?\DateTimeImmutable $end,
    public bool $allDay,
    public ?TermInterface $organizer,
    public ?TermInterface $location,
    public bool $cancelled = FALSE,
    public ?SeasonInterface $season = NULL,
  ) {}

  /**
   * Returns the entity that stores this occurrence's date.
   *
   * That is the lesson of a course occurrence and the event itself otherwise.
   */
  public function source(): LessonInterface|NodeInterface {
    return $this->lesson ?? $this->node;
  }

  /**
   * Returns a key that identifies the source across entity types.
   *
   * Lesson and node IDs overlap, so the key prefixes a lesson ID. Event keys
   * stay the bare node ID, because the deployed iCal UIDs and JSON/JSON-LD
   * identifiers must not change.
   */
  public function sourceKey(): string {
    return $this->lesson !== NULL
      ? 'lesson-' . $this->lesson->id()
      : (string) $this->node->id();
  }

  /**
   * Returns whether the occurrence comes from a course node.
   */
  public function isCourse(): bool {
    return $this->node->bundle() === 'course';
  }

  /**
   * Returns whether the occurrence was cancelled.
   */
  public function isCancelled(): bool {
    return $this->cancelled;
  }

  /**
   * Returns the download URL of this occurrence.
   *
   * The route takes the lesson of a course or the event itself.
   */
  public function toIcalUrl(): Url {
    if ($this->lesson !== NULL) {
      return Url::fromRoute('workspace_events.lesson_ical', [
        'lesson' => (int) $this->lesson->id(),
      ]);
    }
    return Url::fromRoute('workspace_events.occurrence_ical', [
      'node' => (int) $this->node->id(),
    ]);
  }

  /**
   * Returns the timestamp at which the occurrence ends.
   *
   * For occurrences without a known end the start is used.
   */
  public function endTimestamp(): int {
    return $this->end?->getTimestamp() ?? $this->start->getTimestamp();
  }

}
