<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\workspace_events\AttendanceRecognition;

/**
 * Guards the AI settings against models that train on attendance lists.
 */
final class AiSettingsFormHooks {

  use StringTranslationTrait;

  /**
   * The form field that holds the model of the image vision operation.
   */
  private const string MODEL_FIELD = 'model__' . AttendanceRecognition::OPERATION_TYPE;

  /**
   * Implements hook_form_FORM_ID_alter() for the AI settings form.
   *
   * @param array<mixed> $form
   *   The form structure.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_ai_settings_alter')]
  public function formAiSettingsAlter(array &$form): void {
    $validators = $form['#validate'] ?? [];
    $validators = is_array($validators) ? $validators : [];
    $validators[] = [$this, 'validateModel'];
    $form['#validate'] = $validators;
  }

  /**
   * Rejects free models for the image vision operation.
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateModel(array &$form, FormStateInterface $form_state): void {
    $model = $form_state->getValue(self::MODEL_FIELD);
    if (!is_string($model) || !AttendanceRecognition::isTrainingModel($model)) {
      return;
    }

    $form_state->setErrorByName(self::MODEL_FIELD, $this->t('The model %model must not be used for image vision: free models may use uploaded attendance lists for training. Choose a paid model.', [
      '%model' => $model,
    ]));
  }

}
