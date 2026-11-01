<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates the schedule of a course season.
 */
#[Constraint(
  id: 'SeasonScheduleLocked',
  label: new TranslatableMarkup('Season schedule', [], ['context' => 'Validation']),
  type: ['entity']
)]
class SeasonScheduleLocked extends SymfonyConstraint {

  /**
   * The message when recorded lessons block the removal of their dates.
   */
  public string $message = 'The dates of lessons with participants, figures or a cancellation cannot be removed: @dates.';

  /**
   * The message when a recurrence is not weekly or has no end.
   */
  public string $recurrenceMessage = 'Lessons repeat weekly and need an end date or a number of lessons.';

  /**
   * {@inheritdoc}
   */
  #[HasNamedArguments]
  public function __construct(
    mixed $options = NULL,
    ?string $message = NULL,
    ?string $recurrenceMessage = NULL,
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    if ($message !== NULL) {
      $this->message = $message;
    }
    if ($recurrenceMessage !== NULL) {
      $this->recurrenceMessage = $recurrenceMessage;
    }
    parent::__construct($options, $groups, $payload);
  }

}
