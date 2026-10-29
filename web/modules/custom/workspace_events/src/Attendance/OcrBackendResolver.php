<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\workspace_events\AttendanceRecognition;

/**
 * Resolves the OCR backend from the attendance recognition settings.
 *
 * The backend is built once per request, so a form that recognizes several
 * photos does not read the configuration again.
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
   * @param \Drupal\workspace_events\Attendance\MistralOcrBackend $mistralBackend
   *   The Mistral OCR backend.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $aiProviderManager
   *   The AI provider plugin manager (the ai.provider service).
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MistralOcrBackend $mistralBackend,
    private readonly PluginManagerInterface $aiProviderManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(): OcrBackendInterface {
    if ($this->backend !== NULL) {
      return $this->backend;
    }

    $config = $this->configFactory->get(AttendanceRecognition::SETTINGS);
    $backend = $config->get('backend');
    if ($backend === AttendanceRecognition::BACKEND_MISTRAL_OCR) {
      return $this->backend = $this->mistralBackend;
    }

    if ($backend === AttendanceRecognition::BACKEND_AI_PROVIDER) {
      return $this->backend = $this->resolveAiProviderBackend($config);
    }

    throw new \RuntimeException(sprintf('Unknown attendance recognition backend %s.', is_string($backend) ? $backend : get_debug_type($backend)));
  }

  /**
   * Builds the backend of a configured AI provider.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The attendance recognition configuration.
   *
   * @return \Drupal\workspace_events\Attendance\AiProviderVisionBackend
   *   The AI provider backend.
   */
  private function resolveAiProviderBackend(ImmutableConfig $config): AiProviderVisionBackend {
    $providerId = $config->get('provider_id');
    $modelId = $config->get('model_id');
    if (!is_string($providerId) || $providerId === '' || !is_string($modelId) || $modelId === '') {
      throw new \RuntimeException('The AI provider settings of the attendance recognition are incomplete.');
    }

    return new AiProviderVisionBackend($this->aiProviderManager, $providerId, $modelId);
  }

}
