<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Keeps the schedule of a season that already has recorded lesson data.
 */
#[Constraint(
  id: 'SeasonScheduleLocked',
  label: new TranslatableMarkup('Season schedule locked', [], ['context' => 'Validation']),
  type: ['entity']
)]
class SeasonScheduleLocked extends SymfonyConstraint {

  /**
   * The message when recorded lessons block a schedule change.
   */
  public string $message = "The dates can't be changed because lessons of this season already have participants, figures or a cancellation.";

  /**
   * The message when the last lesson is before the first lesson.
   */
  public string $lastMessage = 'The last lesson must not be before the first lesson.';

  /**
   * {@inheritdoc}
   */
  #[HasNamedArguments]
  public function __construct(
    mixed $options = NULL,
    ?string $message = NULL,
    ?string $lastMessage = NULL,
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    if ($message !== NULL) {
      $this->message = $message;
    }
    if ($lastMessage !== NULL) {
      $this->lastMessage = $lastMessage;
    }
    parent::__construct($options, $groups, $payload);
  }

}
