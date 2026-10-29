<?php

declare(strict_types=1);

namespace Drupal\ai_provider_opencode\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base form for the settings of one OpenCode provider.
 */
abstract class OpenCodeConfigFormBase extends ConfigFormBase {

  /**
   * Constructs an OpenCodeConfigFormBase.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\ai\AiProviderPluginManager $aiProviderManager
   *   The AI provider plugin manager.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The repository of the Key entities.
   */
  final public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected readonly AiProviderPluginManager $aiProviderManager,
    protected readonly KeyRepositoryInterface $keyRepository,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
      $container->get('key.repository'),
    );
  }

  /**
   * Returns the name of the provider settings configuration.
   *
   * @return string
   *   The configuration name.
   */
  abstract protected function configName(): string;

  /**
   * Returns the provider plugin ID.
   *
   * @return string
   *   The plugin ID.
   */
  abstract protected function providerId(): string;

  /**
   * Returns the default base URL of the provider.
   *
   * @return string
   *   The base URL.
   */
  abstract protected function defaultBaseUrl(): string;

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The editable configuration names.
   */
  protected function getEditableConfigNames(): array {
    return [$this->configName()];
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
    $config = $this->config($this->configName());

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('API key'),
      '#description' => $this->t('The Key entity that holds the OpenCode API key.'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
    ];

    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Base URL'),
      '#description' => $this->t('The base URL of the OpenAI-compatible API. Change it only for a proxy or a compatible endpoint.'),
      '#default_value' => $config->get('base_url') ?: $this->defaultBaseUrl(),
      '#required' => TRUE,
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

    $key_id = $form_state->getValue('api_key');
    if (!is_string($key_id) || $key_id === '') {
      $form_state->setErrorByName('api_key', $this->t('Select the Key entity that holds the API key.'));
      return;
    }

    $key_value = $this->keyRepository->getKey($key_id)?->getKeyValue();
    if (!is_string($key_value) || $key_value === '') {
      $form_state->setErrorByName('api_key', $this->t('The selected key has no value. Check the key configuration and the environment variable behind it.'));
      return;
    }

    $base_url = $form_state->getValue('base_url');
    if (!is_string($base_url) || trim($base_url) === '') {
      $form_state->setErrorByName('base_url', $this->t('Enter the base URL of the API.'));
      return;
    }

    // Test the settings against the model list of the provider. The form
    // values are saved temporarily because the provider reads them from the
    // configuration, and restored afterwards.
    $config = $this->config($this->configName());
    $original_api_key = $config->get('api_key');
    $original_base_url = $config->get('base_url');
    $config
      ->set('api_key', $key_id)
      ->set('base_url', rtrim(trim($base_url), '/'))
      ->save();

    try {
      $provider = $this->aiProviderManager->createInstance($this->providerId());
      if ($provider->getPlugin()->getConfiguredModels('chat') === []) {
        $form_state->setErrorByName('api_key', $this->t('The API did not return any models. Check the API key and the base URL.'));
      }
    }
    catch (\Throwable) {
      $form_state->setErrorByName('api_key', $this->t('The API key or the base URL is not working. Check both values and try again.'));
    }
    finally {
      $config
        ->set('api_key', $original_api_key)
        ->set('base_url', $original_base_url)
        ->save();
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
    $api_key = $form_state->getValue('api_key');
    $base_url = $form_state->getValue('base_url');

    $this->config($this->configName())
      ->set('api_key', is_string($api_key) ? $api_key : '')
      ->set('base_url', is_string($base_url) && trim($base_url) !== '' ? rtrim(trim($base_url), '/') : $this->defaultBaseUrl())
      ->save();

    $this->setDefaultModels();

    parent::submitForm($form, $form_state);
  }

  /**
   * Sets the default models of the provider if none are configured yet.
   */
  private function setDefaultModels(): void {
    $provider = $this->aiProviderManager->createInstance($this->providerId());
    $setup_data = $provider->getPlugin()->getSetupData();
    $default_models = $setup_data['default_models'] ?? NULL;
    if (!is_array($default_models)) {
      return;
    }

    foreach ($default_models as $operation_type => $model_id) {
      if (is_string($operation_type) && is_string($model_id)) {
        $this->aiProviderManager->defaultIfNone($operation_type, $this->providerId(), $model_id);
      }
    }
  }

}
