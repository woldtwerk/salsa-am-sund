<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\workspace_events\Entity\SeasonInterface;
use Drupal\workspace_events\SeasonRepository;
use Drupal\workspace_events\SiteTimeZone;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the schedule of a course season.
 */
final class SeasonScheduleLockedValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a SeasonScheduleLockedValidator.
   *
   * @param \Drupal\workspace_events\SeasonRepository $seasonRepository
   *   The season repository.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   */
  public function __construct(
    private readonly SeasonRepository $seasonRepository,
    private readonly SiteTimeZone $siteTimeZone,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $repository = $container->get(SeasonRepository::class);
    \assert($repository instanceof SeasonRepository);
    $site_timezone = $container->get(SiteTimeZone::class);
    \assert($site_timezone instanceof SiteTimeZone);

    return new static($repository, $site_timezone);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof SeasonInterface || !$constraint instanceof SeasonScheduleLocked) {
      return;
    }

    $this->validateRecordedLessons($value, $constraint);
    $this->validateLastLesson($value, $constraint);
  }

  /**
   * Refuses a schedule change when a lesson already has recorded data.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   * @param \Drupal\workspace_events\Plugin\Validation\Constraint\SeasonScheduleLocked $constraint
   *   The constraint.
   */
  private function validateRecordedLessons(SeasonInterface $season, SeasonScheduleLocked $constraint): void {
    if ($season->isNew() || !$season->hasScheduleChanged() || !$this->seasonRepository->hasRecordedLessons($season)) {
      return;
    }

    $this->context->buildViolation($constraint->message)
      ->atPath('field_schedule')
      ->addViolation();
  }

  /**
   * Refuses a last lesson before the first lesson.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   * @param \Drupal\workspace_events\Plugin\Validation\Constraint\SeasonScheduleLocked $constraint
   *   The constraint.
   */
  private function validateLastLesson(SeasonInterface $season, SeasonScheduleLocked $constraint): void {
    $schedule = $season->get('field_schedule')->first();
    $last = $season->get('last')->first();
    if ($schedule === NULL || $last === NULL) {
      return;
    }

    $first_value = $schedule->get('value')->getString();
    $last_value = $last->get('value')->getString();
    if ($first_value === '' || $last_value === '') {
      return;
    }

    $first_day = $this->siteTimeZone->fromStorageValue($first_value)->format('Y-m-d');
    if ($last_value >= $first_day) {
      return;
    }

    $this->context->buildViolation($constraint->lastMessage)
      ->atPath('last')
      ->addViolation();
  }

}
