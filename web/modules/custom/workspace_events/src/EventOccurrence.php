<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

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
   * @param \Drupal\node\NodeInterface|null $lesson
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
   */
  public function __construct(
    public NodeInterface $node,
    public ?NodeInterface $lesson,
    public \DateTimeImmutable $start,
    public ?\DateTimeImmutable $end,
    public bool $allDay,
    public ?TermInterface $organizer,
    public ?TermInterface $location,
    public bool $cancelled = FALSE,
  ) {}

  /**
   * Returns the node that stores this occurrence's date.
   */
  public function sourceNode(): NodeInterface {
    return $this->lesson ?? $this->node;
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
   * The route takes the node that stores the date: the lesson of a course or
   * the event itself.
   */
  public function toIcalUrl(): Url {
    return Url::fromRoute('workspace_events.occurrence_ical', [
      'node' => (int) $this->sourceNode()->id(),
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
