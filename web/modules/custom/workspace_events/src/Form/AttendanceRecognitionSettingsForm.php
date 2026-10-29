<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workspace_events\AttendanceRecognition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the OCR backend of the attendance recognition.
 */
final class AttendanceRecognitionSettingsForm extends ConfigFormBase {

  /**
   * Model name markers of free models that may train on the uploaded data.
   *
   * @var string[]
   */
  private const array TRAINING_MODEL_MARKERS = ['free', 'big-pickle', 'nemotron'];

  /**
   * Constructs an AttendanceRecognitionSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\ai\AiProviderPluginManager $aiProviderManager
   *   The AI provider plugin manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected readonly AiProviderPluginManager $aiProviderManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'workspace_events_attendance_recognition_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The editable configuration names.
   */
  protected function getEditableConfigNames(): array {
    return [AttendanceRecognition::SETTINGS];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(AttendanceRecognition::SETTINGS);

    $form['intro'] = [
      '#type' => 'item',
      '#markup' => $this->t('Das Foto der Anwesenheitsliste wird an den gewählten Anbieter gesendet und dort ausgewertet. Die Zuordnung zu den Mitgliedern findet erst danach auf dieser Website statt.'),
    ];

    $form['backend'] = [
      '#type' => 'radios',
      '#title' => $this->t('Anbieter'),
      '#options' => [
        AttendanceRecognition::BACKEND_MISTRAL_OCR => $this->t('Mistral OCR'),
        AttendanceRecognition::BACKEND_AI_PROVIDER => $this->t('KI-Anbieter mit Bildverständnis'),
      ],
      '#default_value' => $config->get('backend'),
      '#required' => TRUE,
    ];

    $options = $this->providerModelOptions();
    $provider_id = $config->get('provider_id');
    $model_id = $config->get('model_id');
    $default = is_string($provider_id) && $provider_id !== '' && is_string($model_id) && $model_id !== ''
      ? $provider_id . '__' . $model_id
      : '';

    $form['provider_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Modell'),
      '#description' => $this->t('Modelle von KI-Anbietern, die Chat mit Bildern unterstützen. Kostenlose Modelle, die die Daten zum Training verwenden, werden nicht angeboten. Weitere Anbieter und Modelle lassen sich unter <a href=":url">AI Providers</a> einrichten.', [
        ':url' => '/admin/config/ai/providers',
      ]),
      '#options' => $options,
      '#default_value' => $default,
      '#required' => FALSE,
      '#empty_option' => $this->t('- Kein Modell -'),
      '#states' => [
        'visible' => [
          ':input[name="backend"]' => ['value' => AttendanceRecognition::BACKEND_AI_PROVIDER],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ($form_state->getValue('backend') !== AttendanceRecognition::BACKEND_AI_PROVIDER) {
      return;
    }

    $option = $form_state->getValue('provider_model');
    if (!is_string($option) || $option === '' || !str_contains($option, '__')) {
      $form_state->setErrorByName('provider_model', $this->t('Wählen Sie ein Modell für die Anwesenheitserkennung.'));
      return;
    }

    $model_id = substr($option, (int) strpos($option, '__') + 2);
    foreach (self::TRAINING_MODEL_MARKERS as $marker) {
      if (str_contains(mb_strtolower($model_id), $marker)) {
        $element = $form['provider_model'] ?? NULL;
        if (is_array($element)) {
          $form_state->setError($element, $this->t('Das Modell %model darf nicht verwendet werden: Kostenlose Modelle können die Anwesenheitsliste zum Training verwenden. Wählen Sie ein kostenpflichtiges Modell.', [
            '%model' => $model_id,
          ]));
        }
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $backend = $form_state->getValue('backend');
    if (!is_string($backend) || !in_array($backend, [
      AttendanceRecognition::BACKEND_MISTRAL_OCR,
      AttendanceRecognition::BACKEND_AI_PROVIDER,
    ], TRUE)) {
      $backend = AttendanceRecognition::BACKEND_MISTRAL_OCR;
    }

    $config = $this->config(AttendanceRecognition::SETTINGS)
      ->set('backend', $backend)
      ->set('provider_id', '')
      ->set('model_id', '');

    if ($backend === AttendanceRecognition::BACKEND_AI_PROVIDER) {
      $option = $form_state->getValue('provider_model');
      if (is_string($option) && str_contains($option, '__')) {
        [$provider_id, $model_id] = explode('__', $option, 2);
        $config
          ->set('provider_id', $provider_id)
          ->set('model_id', $model_id);
      }
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the selectable provider and model options.
   *
   * @return array<string, string>
   *   The option labels keyed by "provider__model".
   */
  private function providerModelOptions(): array {
    $options = $this->aiProviderManager->getSimpleProviderModelOptions('chat', TRUE, TRUE, [
      AiModelCapability::ChatWithImageVision,
    ]);

    $filtered = [];
    foreach ($options as $option => $label) {
      if (!is_string($option) || $option === '') {
        continue;
      }
      if ($this->isTrainingModel($option)) {
        continue;
      }
      if ($label instanceof \Stringable || is_string($label)) {
        $filtered[$option] = (string) $label;
      }
    }

    return $filtered;
  }

  /**
   * Checks whether an option points at a free or logged model.
   *
   * @param string $option
   *   The "provider__model" option.
   *
   * @return bool
   *   TRUE when the model must not be used for attendance lists.
   */
  private function isTrainingModel(string $option): bool {
    $parts = explode('__', $option, 2);
    $model_id = mb_strtolower($parts[1] ?? '');
    foreach (self::TRAINING_MODEL_MARKERS as $marker) {
      if (str_contains($model_id, $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
