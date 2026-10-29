<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\key\KeyRepositoryInterface;
use Drupal\workspace_events\AttendanceRecognition;

/**
 * Resolves the OCR backend from the attendance recognition settings.
 *
 * The backend is built once per request, so a form that recognizes several
 * photos does not read the configuration and the Key entities again.
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
   * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
   *   The HTTP client factory.
   * @param \Drupal\key\KeyRepositoryInterface|null $keyRepository
   *   The repository of the Key entities, or NULL when the Key module is not
   *   available.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MistralOcrBackend $mistralBackend,
    private readonly ClientFactory $httpClientFactory,
    private readonly ?KeyRepositoryInterface $keyRepository,
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
    if ($backend === AttendanceRecognition::BACKEND_MISTRAL) {
      return $this->backend = $this->mistralBackend;
    }

    $label = match ($backend) {
      AttendanceRecognition::BACKEND_OPENCODE_ZEN => 'OpenCode Zen (Guthaben)',
      AttendanceRecognition::BACKEND_OPENCODE_GO => 'OpenCode Go (Abo)',
      default => throw new \RuntimeException(sprintf('Unknown attendance recognition backend %s.', is_string($backend) ? $backend : get_debug_type($backend))),
    };

    $settings = $config->get($backend);
    if (!is_array($settings)) {
      throw new \RuntimeException(sprintf('The %s settings are missing.', $label));
    }

    $baseUrl = $settings['base_url'] ?? NULL;
    $model = $settings['model'] ?? NULL;
    $keyId = $settings['key'] ?? NULL;
    if (!is_string($baseUrl) || $baseUrl === '' || !is_string($model) || $model === '' || !is_string($keyId) || $keyId === '') {
      throw new \RuntimeException(sprintf('The %s settings are incomplete.', $label));
    }

    if ($this->keyRepository === NULL) {
      throw new \RuntimeException(sprintf('The Key module is required for %s.', $label));
    }

    return $this->backend = new OpenAiCompatibleVisionBackend(
      $baseUrl,
      $model,
      $keyId,
      $label,
      $this->httpClientFactory,
      $this->keyRepository,
    );
  }

}
