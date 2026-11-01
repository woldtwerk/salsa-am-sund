<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Entity\SeasonInterface;
use Drupal\workspace_events\LessonGenerator;
use Drupal\workspace_events\LessonTable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the course season forms.
 */
final class SeasonForm extends ContentEntityForm {

  /**
   * Constructs a SeasonForm.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\workspace_events\LessonTable $lessonTable
   *   The lesson table.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected readonly LessonTable $lessonTable,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $lesson_table = $container->get(LessonTable::class);
    \assert($lesson_table instanceof LessonTable);

    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $lesson_table,
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $season = $this->getEntity();
    if ($season instanceof SeasonInterface && $season->isNew()) {
      $this->prefillCourse($season);
      if ($season->get('uid')->isEmpty()) {
        $season->setOwnerId((int) $this->currentUser()->id());
      }
    }

    $form = parent::buildForm($form, $form_state);
    /** @var array<string, mixed> $form */
    // The series is edited in its first row (recurrence) and through Smart
    // Date's instance management, so further rows are not needed.
    if (isset($form['field_schedule']) && is_array($form['field_schedule'])) {
      /** @var array{widget: array{add_more?: mixed}} $schedule */
      $schedule = &$form['field_schedule'];
      unset($schedule['widget']['add_more']);
    }
    if ($season instanceof SeasonInterface && !$season->isNew()) {
      $form['lessons'] = $this->lessonTable->build($season);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return int
   *   The save result.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $season = $this->getEntity();
    $this->messenger()->addStatus($this->t('Season %label has been saved.', [
      '%label' => $season instanceof SeasonInterface ? (string) $season->label() : '',
    ]));

    $course = $season instanceof SeasonInterface ? $season->getCourse() : NULL;
    if ($course instanceof NodeInterface) {
      $form_state->setRedirectUrl($course->toUrl('edit-form'));
    }

    return $result;
  }

  /**
   * Prefills the course of a new season from the course query parameter.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The new season.
   */
  private function prefillCourse(SeasonInterface $season): void {
    if (!$season->get('course')->isEmpty()) {
      return;
    }

    $course_id = (int) $this->getRequest()->query->get('course');
    if ($course_id <= 0) {
      return;
    }

    $course = $this->entityTypeManager->getStorage('node')->load($course_id);
    if (!$course instanceof NodeInterface || $course->bundle() !== LessonGenerator::COURSE_BUNDLE || !$course->access('update')) {
      return;
    }

    $season->set('course', $course->id());
  }

}
