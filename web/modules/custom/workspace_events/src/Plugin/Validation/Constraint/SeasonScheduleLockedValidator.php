<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\workspace_events\Entity\SeasonInterface;
use Drupal\workspace_events\LessonGenerator;
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
   * @param \Drupal\workspace_events\LessonGenerator $lessonGenerator
   *   The lesson generator.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly LessonGenerator $lessonGenerator,
    private readonly SiteTimeZone $siteTimeZone,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $lesson_generator = $container->get(LessonGenerator::class);
    \assert($lesson_generator instanceof LessonGenerator);
    $site_timezone = $container->get(SiteTimeZone::class);
    \assert($site_timezone instanceof SiteTimeZone);
    $entity_type_manager = $container->get('entity_type.manager');
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);

    return new static($lesson_generator, $site_timezone, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof SeasonInterface || !$constraint instanceof SeasonScheduleLocked) {
      return;
    }

    $this->validateRecordedLessons($value, $constraint);
    $this->validateRecurrence($value, $constraint);
  }

  /**
   * Refuses to remove the dates of lessons that already have recorded data.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   * @param \Drupal\workspace_events\Plugin\Validation\Constraint\SeasonScheduleLocked $constraint
   *   The constraint.
   */
  private function validateRecordedLessons(SeasonInterface $season, SeasonScheduleLocked $constraint): void {
    if ($season->isNew() || !$season->hasScheduleChanged()) {
      return;
    }

    $blocked = $this->lessonGenerator->blockedLessons($season);
    if ($blocked === []) {
      return;
    }

    $dates = [];
    foreach ($blocked as $lesson) {
      $item = $lesson->get('field_lesson_date')->first();
      if ($item === NULL) {
        continue;
      }
      $value = (string) $item->get('value')->getString();
      if ($value === '') {
        continue;
      }
      $dates[] = $this->siteTimeZone->fromStorageValue($value)->format('d.m.Y');
    }
    if ($dates === []) {
      return;
    }

    $this->context->buildViolation($constraint->message)
      ->setParameter('@dates', implode(', ', array_unique($dates)))
      ->atPath('field_schedule')
      ->addViolation();
  }

  /**
   * Refuses a recurrence that is not weekly or has no end.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   * @param \Drupal\workspace_events\Plugin\Validation\Constraint\SeasonScheduleLocked $constraint
   *   The constraint.
   */
  private function validateRecurrence(SeasonInterface $season, SeasonScheduleLocked $constraint): void {
    if (!$season->hasField('field_schedule')) {
      return;
    }

    $rule_ids = [];
    foreach ($season->get('field_schedule') as $item) {
      $rule_id = (int) $item->get('rrule')->getString();
      if ($rule_id > 0) {
        $rule_ids[$rule_id] = $rule_id;
      }
    }
    if ($rule_ids === [] || !$this->entityTypeManager->hasDefinition('smart_date_rule')) {
      return;
    }

    $rules = $this->entityTypeManager->getStorage('smart_date_rule')->loadMultiple($rule_ids);
    foreach ($rules as $rule) {
      if (!$rule instanceof SmartDateRule) {
        continue;
      }
      $unlimited = $rule->get('unlimited')->getString() === '1';
      if ($rule->get('freq')->getString() === 'WEEKLY' && !$unlimited) {
        continue;
      }

      $this->context->buildViolation($constraint->recurrenceMessage)
        ->atPath('field_schedule')
        ->addViolation();
      return;
    }
  }

}
