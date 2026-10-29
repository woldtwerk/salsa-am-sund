<?php

declare(strict_types=1);

namespace Drupal\ai_provider_opencode\Plugin\AiProvider;

use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\Traits\OperationType\ChatTrait;
use Drupal\ai_provider_opencode\OpenCodeSessionClient;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use GuzzleHttp\Psr7\Request;
use OpenAI\Client;

/**
 * Base class for the OpenCode providers.
 *
 * Both providers speak the OpenAI chat completions format and only differ in
 * their default endpoint and the models that it serves.
 */
abstract class OpenCodeProviderBase extends OpenAiBasedProviderClientBase {

  use ChatTrait;

  /**
   * Model name markers of free models that may train on the submitted data.
   *
   * Attendance lists contain personal data, so models that OpenCode documents
   * as free, as a feedback build or as a logged trial are never offered.
   *
   * @var string[]
   */
  public const array EXCLUDED_MODEL_MARKERS = [
    'free',
    'big-pickle',
    'nemotron',
  ];

  /**
   * How long discovered model lists are cached, in seconds.
   */
  private const int MODELS_CACHE_TTL = 86400;

  /**
   * The session ID that OpenCode Go requires, created on first use.
   */
  private ?string $sessionId = NULL;

  /**
   * Models are discovered from the provider API, not hardcoded.
   *
   * {@inheritdoc}
   */
  protected bool $hasPredefinedModels = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('ai_provider_opencode.' . $this->configKey() . '.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $operation_type
   *   The operation type.
   * @param array<mixed> $capabilities
   *   The model capabilities to filter by.
   *
   * @return array<string, string>
   *   The models keyed by model ID.
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    if ($operation_type !== NULL && $operation_type !== 'chat') {
      return [];
    }

    return $this->getModels('chat', $capabilities);
  }

  /**
   * {@inheritdoc}
   *
   * @param string $model_id
   *   The model ID.
   * @param array<mixed> $generalConfig
   *   The general model configuration.
   *
   * @return array<mixed>
   *   The model configuration.
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The setup data.
   */
  public function getSetupData(): array {
    return [
      'key_config_name' => 'api_key',
      'default_models' => [
        'chat' => $this->defaultModel(),
        'chat_with_image_vision' => $this->defaultModel(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function postSetup(): void {
    if ($this->getConfiguredModels('chat') !== []) {
      return;
    }

    throw new AiSetupFailureException(sprintf('The %s provider returned no usable models. Check the API key and the base URL.', $this->getPluginId()));
  }

  /**
   * {@inheritdoc}
   */
  public function getClient(string $api_key = ''): Client {
    if ($api_key !== '') {
      $this->setAuthentication($api_key);
    }
    $this->loadClient();
    if (!$this->client instanceof Client) {
      throw new AiSetupFailureException(sprintf('The %s client could not be initialized.', $this->getPluginId()));
    }
    return $this->client;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadClient(): void {
    if ($this->client === NULL) {
      $this->setEndpoint($this->getBaseUrl());
    }
    parent::loadClient();
  }

  /**
   * {@inheritdoc}
   */
  protected function createClient(): Client {
    // OpenCode Go routes a conversation by the session header. The decorator
    // adds it to every request without changing the OpenAI-compatible client.
    $http_client = $this->httpClient;
    $this->httpClient = new OpenCodeSessionClient($http_client, $this->getSessionId());
    try {
      return parent::createClient();
    }
    finally {
      $this->httpClient = $http_client;
    }
  }

  /**
   * Returns the session ID of this provider instance.
   *
   * @return string
   *   The session ID.
   */
  private function getSessionId(): string {
    return $this->sessionId ??= 'ses_' . bin2hex(random_bytes(16));
  }

  /**
   * Returns the discovered models that match the requested capabilities.
   *
   * @param string $operation_type
   *   The operation type. Only chat is supported.
   * @param array<mixed> $capabilities
   *   The model capabilities to filter by.
   *
   * @return array<string, string>
   *   The models keyed by model ID.
   */
  public function getModels(string $operation_type, array $capabilities = []): array {
    if ($operation_type !== 'chat') {
      return [];
    }

    $discovered = $this->discoverModels();
    $wants_vision = in_array(AiModelCapability::ChatWithImageVision, $capabilities, TRUE);

    // The model list of OpenCode does not describe the capabilities of its
    // models. Only filter when at least one record actually reports vision
    // support; otherwise every chat model stays selectable and the
    // administrator picks one that accepts images.
    if ($wants_vision && array_filter($discovered, static fn (array $model): bool => $model['vision'] !== NULL) !== []) {
      $discovered = array_filter($discovered, static fn (array $model): bool => $model['vision'] === TRUE);
    }

    $models = [];
    foreach ($discovered as $model_id => $model) {
      $models[$model_id] = $model['label'];
    }

    // Models that an administrator added by hand are not part of the API
    // list. Respect the vision flag that was saved for them.
    $provider_models = $this->getModelsConfig()[$this->getPluginId()] ?? NULL;
    $manual_models = is_array($provider_models) ? ($provider_models['chat'] ?? NULL) : NULL;
    if (is_array($manual_models)) {
      foreach ($manual_models as $model_id => $model_config) {
        if (!is_string($model_id) || $model_id === '' || isset($models[$model_id]) || $this->isExcluded($model_id)) {
          continue;
        }
        if ($wants_vision && (!is_array($model_config) || empty($model_config['chat_with_image_vision']))) {
          continue;
        }
        $label = is_array($model_config) && is_string($model_config['label'] ?? NULL) && $model_config['label'] !== ''
          ? $model_config['label']
          : $model_id;
        $models[$model_id] = $label;
      }
    }

    asort($models);

    return $models;
  }

  /**
   * Returns the configured or default base URL, without a trailing slash.
   *
   * @return string
   *   The base URL of the OpenAI-compatible API.
   */
  protected function getBaseUrl(): string {
    $base_url = $this->getConfig()->get('base_url');
    if (is_string($base_url) && trim($base_url) !== '') {
      return rtrim(trim($base_url), '/');
    }
    return $this->defaultBaseUrl();
  }

  /**
   * Returns the config key of the provider, as used in its settings name.
   *
   * @return string
   *   The config key, "zen" or "go".
   */
  abstract protected function configKey(): string;

  /**
   * Returns the default base URL of the provider.
   *
   * @return string
   *   The base URL of the OpenAI-compatible API.
   */
  abstract protected function defaultBaseUrl(): string;

  /**
   * Returns the model that the provider is set up with.
   *
   * @return string
   *   The default model ID.
   */
  abstract protected function defaultModel(): string;

  /**
   * Returns the models that the provider API offers.
   *
   * @return array<string, array{label: string, vision: bool|null}>
   *   The models keyed by model ID, with their label and the vision support
   *   that the API reports, or NULL when the API does not report it.
   */
  private function discoverModels(): array {
    $cache_key = 'ai_provider_opencode:models:' . $this->getPluginId() . ':' . md5($this->getBaseUrl());
    $cached = $this->cacheBackend->get($cache_key);
    if ($cached !== FALSE && is_array($cached->data)) {
      /** @var array<string, array{label: string, vision: bool|null}> $data */
      $data = $cached->data;
      return $data;
    }

    try {
      $models = $this->fetchModels();
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('ai_provider_opencode')->error('Could not discover the models of @provider: @message', [
        '@provider' => $this->getPluginId(),
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }

    $this->cacheBackend->set($cache_key, $models, time() + self::MODELS_CACHE_TTL);

    return $models;
  }

  /**
   * Fetches the model list from the provider API.
   *
   * @return array<string, array{label: string, vision: bool|null}>
   *   The models keyed by model ID.
   *
   * @throws \RuntimeException
   *   When the API cannot be reached or returns an unexpected response.
   */
  private function fetchModels(): array {
    $errors = [];
    foreach ($this->candidateModelUrls() as $url) {
      try {
        $data = $this->requestJson($url);
      }
      catch (\Throwable $exception) {
        $errors[] = $exception->getMessage();
        continue;
      }

      $items = $data['data'] ?? NULL;
      if (!is_array($items)) {
        $errors[] = sprintf('The model list at %s has no data array.', $url);
        continue;
      }

      $models = [];
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $model_id = $item['id'] ?? NULL;
        if (!is_string($model_id) || $model_id === '' || $this->isExcluded($model_id)) {
          continue;
        }
        $models[$model_id] = [
          'label' => $model_id,
          'vision' => $this->visionSupport($item),
        ];
      }

      // An empty list usually means that the first candidate URL is not the
      // catalogue endpoint, so the documented fallback is still tried.
      if ($models !== []) {
        return $models;
      }
    }

    if ($errors !== []) {
      throw new \RuntimeException(implode(' ', $errors));
    }

    return [];
  }

  /**
   * Returns the URLs that may serve the model list, in order of preference.
   *
   * OpenCode Zen documents its catalogue one level above the OpenAI-compatible
   * base URL ("/inference/v1/models" instead of
   * "/inference/openai/v1/models"), so that fallback is tried as well.
   *
   * @return string[]
   *   The candidate URLs.
   */
  private function candidateModelUrls(): array {
    $base_url = $this->getBaseUrl();
    $urls = [$base_url . '/models'];

    $fallback = preg_replace('~/openai(/v1)?$~', '$1', $base_url);
    if (is_string($fallback) && $fallback !== $base_url) {
      $urls[] = $fallback . '/models';
    }

    return array_values(array_unique($urls));
  }

  /**
   * Requests and decodes a JSON document.
   *
   * @param string $url
   *   The URL to request.
   *
   * @return array<mixed>
   *   The decoded response.
   *
   * @throws \RuntimeException
   *   When the request fails or the response is not a JSON object.
   */
  private function requestJson(string $url): array {
    $headers = ['Accept' => 'application/json'];

    $key_id = $this->getConfig()->get('api_key');
    if (is_string($key_id) && $key_id !== '') {
      $key_value = $this->keyRepository->getKey($key_id)?->getKeyValue();
      if (is_string($key_value) && $key_value !== '') {
        $headers['Authorization'] = 'Bearer ' . $key_value;
      }
    }

    $response = $this->httpClient->sendRequest(new Request('GET', $url, $headers));
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException(sprintf('%s returned HTTP %d.', $url, $response->getStatusCode()));
    }

    $data = Json::decode((string) $response->getBody());
    if (!is_array($data)) {
      throw new \RuntimeException(sprintf('The response of %s is not a JSON object.', $url));
    }

    return $data;
  }

  /**
   * Checks whether a model is excluded from the list.
   *
   * @param string $model_id
   *   The model ID.
   *
   * @return bool
   *   TRUE when the model may train on or log the submitted data.
   */
  private function isExcluded(string $model_id): bool {
    $model_id = mb_strtolower($model_id);
    foreach (self::EXCLUDED_MODEL_MARKERS as $marker) {
      if (str_contains($model_id, $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reads the vision capability from one model record.
   *
   * @param array<mixed> $model
   *   The model record of the API.
   *
   * @return bool|null
   *   TRUE or FALSE when the record reports vision support, NULL otherwise.
   */
  private function visionSupport(array $model): ?bool {
    $capabilities = $model['capabilities'] ?? NULL;
    if (is_array($capabilities)) {
      foreach (['vision', 'image_input', 'images'] as $flag) {
        if (isset($capabilities[$flag]) && is_bool($capabilities[$flag])) {
          return $capabilities[$flag];
        }
      }
    }

    foreach (['supports_vision', 'vision'] as $flag) {
      if (isset($model[$flag]) && is_bool($model[$flag])) {
        return $model[$flag];
      }
    }

    $modalities = $model['input_modalities'] ?? NULL;
    if (!is_array($modalities) && isset($model['architecture']) && is_array($model['architecture'])) {
      $modalities = $model['architecture']['input_modalities'] ?? NULL;
    }
    if (is_array($modalities) && $modalities !== []) {
      return in_array('image', $modalities, TRUE);
    }

    return NULL;
  }

}
