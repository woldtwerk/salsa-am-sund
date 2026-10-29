<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\workspace_events\AttendanceRecognition;

/**
 * Resolves the attendance recognition backend from the default AI provider.
 *
 * The backend uses the default provider of the "chat with image vision"
 * operation and the prompt of the AI prompt library. It is built once per
 * request, so a form that recognizes several photos does not resolve the
 * provider and the prompt again.
 */
final class OcrBackendResolver implements OcrBackendResolverInterface {

  /**
   * The resolved backend, cached for the request.
   */
  private ?OcrBackendInterface $backend = NULL;

  /**
   * Constructs an OcrBackendResolver.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\ai\AiProviderPluginManager|null $aiProviderManager
   *   The AI provider plugin manager (the ai.provider service), or NULL when
   *   the AI module is not available.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?AiProviderPluginManager $aiProviderManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(): OcrBackendInterface {
    if ($this->backend !== NULL) {
      return $this->backend;
    }

    if ($this->aiProviderManager === NULL) {
      throw new \RuntimeException('The AI module is not available.');
    }

    $default = $this->aiProviderManager->getDefaultProviderForOperationType(AttendanceRecognition::OPERATION_TYPE);
    $providerId = is_array($default) ? ($default['provider_id'] ?? NULL) : NULL;
    $modelId = is_array($default) ? ($default['model_id'] ?? NULL) : NULL;
    if (!is_string($providerId) || $providerId === '' || !is_string($modelId) || $modelId === '') {
      throw new \RuntimeException('No default AI provider for chat with image vision is configured.');
    }

    $prompt = $this->configFactory->get('ai.ai_prompt.' . AttendanceRecognition::PROMPT_ID)->get('prompt');
    if (!is_string($prompt) || $prompt === '') {
      throw new \RuntimeException('The prompt of the attendance recognition is missing.');
    }

    return $this->backend = new AiProviderVisionBackend($this->aiProviderManager, $providerId, $modelId, $prompt);
  }

}
