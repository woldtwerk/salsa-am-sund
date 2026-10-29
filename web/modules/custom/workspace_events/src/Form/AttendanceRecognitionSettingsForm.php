<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workspace_events\AttendanceRecognition;

/**
 * Configures the OCR backend of the attendance recognition.
 */
final class AttendanceRecognitionSettingsForm extends ConfigFormBase {

  /**
   * Model name markers of free models that may train on the uploaded data.
   */
  private const array TRAINING_MODEL_MARKERS = ['free', 'big-pickle', 'nemotron'];

  /**
   * The OpenCode backends with their configuration key.
   */
  private const array OPENCODE_BACKENDS = [
    AttendanceRecognition::BACKEND_OPENCODE_ZEN => 'OpenCode Zen (Guthaben)',
    AttendanceRecognition::BACKEND_OPENCODE_GO => 'OpenCode Go (Abo)',
  ];

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
        AttendanceRecognition::BACKEND_MISTRAL => $this->t('Mistral OCR'),
        AttendanceRecognition::BACKEND_OPENCODE_ZEN => $this->t('OpenCode Zen (Guthaben)'),
        AttendanceRecognition::BACKEND_OPENCODE_GO => $this->t('OpenCode Go (Abo)'),
      ],
      '#default_value' => $config->get('backend'),
      '#required' => TRUE,
    ];

    foreach (self::OPENCODE_BACKENDS as $name => $label) {
      $settings = $this->backendSettings($config, $name);
      $form[$name] = [
        '#type' => 'details',
        '#title' => $this->t('@backend', ['@backend' => $label]),
        '#open' => TRUE,
        '#tree' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="backend"]' => ['value' => $name],
          ],
        ],
      ];
      $form[$name]['model'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Modell'),
        '#default_value' => $settings['model'],
        '#required' => TRUE,
        '#description' => $this->t('Das Modell muss Bilder akzeptieren. Kostenlose Modelle, die die Daten zum Training verwenden, werden abgelehnt.'),
      ];
      $form[$name]['base_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Basis-URL'),
        '#default_value' => $settings['base_url'],
        '#required' => TRUE,
      ];
      $form[$name]['key'] = [
        '#type' => 'key_select',
        '#title' => $this->t('API-Schlüssel'),
        '#default_value' => $settings['key'],
        '#required' => TRUE,
        '#description' => $this->t('Die Key-Entität liest den Schlüssel aus der Umgebungsvariable OPENCODE_API_KEY.'),
      ];
    }

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

    $backend = $form_state->getValue('backend');
    if (!is_string($backend) || !isset(self::OPENCODE_BACKENDS[$backend])) {
      return;
    }

    $values = $form_state->getValue($backend);
    $model = is_array($values) ? ($values['model'] ?? NULL) : NULL;
    if (!is_string($model) || $model === '') {
      return;
    }

    $backendForm = $form[$backend] ?? NULL;
    $modelElement = is_array($backendForm) ? ($backendForm['model'] ?? NULL) : NULL;
    if (!is_array($modelElement)) {
      return;
    }

    foreach (self::TRAINING_MODEL_MARKERS as $marker) {
      if (str_contains(mb_strtolower($model), $marker)) {
        $form_state->setError($modelElement, $this->t('Das Modell %model darf nicht verwendet werden: Kostenlose Modelle können die Anwesenheitsliste zum Training verwenden. Wählen Sie ein kostenpflichtiges Modell.', [
          '%model' => $model,
        ]));
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
    $config = $this->config(AttendanceRecognition::SETTINGS);

    $backend = $form_state->getValue('backend');
    $config->set('backend', is_string($backend) && $backend !== '' ? $backend : AttendanceRecognition::BACKEND_MISTRAL);

    foreach (array_keys(self::OPENCODE_BACKENDS) as $name) {
      $values = $form_state->getValue($name);
      $values = is_array($values) ? $values : [];
      foreach (['base_url', 'model', 'key'] as $field) {
        $value = $values[$field] ?? '';
        $config->set($name . '.' . $field, is_string($value) ? $value : '');
      }
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the settings of an OpenCode backend.
   *
   * @param \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig $config
   *   The attendance recognition configuration.
   * @param string $name
   *   The configuration key of the backend.
   *
   * @return array{base_url: string, model: string, key: string}
   *   The base URL, model and key ID.
   */
  private function backendSettings(Config|ImmutableConfig $config, string $name): array {
    $settings = $config->get($name);
    if (!is_array($settings)) {
      return ['base_url' => '', 'model' => '', 'key' => ''];
    }

    return [
      'base_url' => is_string($settings['base_url'] ?? NULL) ? $settings['base_url'] : '',
      'model' => is_string($settings['model'] ?? NULL) ? $settings['model'] : '',
      'key' => is_string($settings['key'] ?? NULL) ? $settings['key'] : '',
    ];
  }

}
