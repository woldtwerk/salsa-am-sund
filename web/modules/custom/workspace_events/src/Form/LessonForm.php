<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the lesson edit forms.
 */
final class LessonForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('Lesson %label has been saved.', [
      '%label' => (string) $this->getEntity()->label(),
    ]));
    $form_state->setRedirect('entity.lesson.collection');

    return $result;
  }

}
