<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai_provider_mistral\Plugin\AiProvider\MistralProvider;
use Drupal\Core\Http\ClientFactory;
use Drupal\workspace_events\AttendanceRecognition;
use Partitech\PhpMistral\Clients\Mistral\MistralClient;

/**
 * Creates the Mistral client through the installed Mistral AI provider.
 *
 * The provider reads the API key from the Key entity configured in
 * ai_provider_mistral.settings, so the key is configured in one place only.
 *
 * The AI provider is optional until the AI modules are enabled: the manager is
 * injected through an optional service reference, so the container compiles
 * without the ai module and create() reports the missing provider instead.
 */
final class MistralOcrClientFactory implements MistralOcrClientFactoryInterface {

  /**
   * The plugin ID from the Mistral provider attribute.
   */
  private const string PROVIDER_ID = 'mistral';

  /**
   * Constructs a MistralOcrClientFactory.
   *
   * @param \Drupal\ai\AiProviderPluginManager|null $aiProviderManager
   *   The AI provider plugin manager, or NULL when the ai module is not
   *   enabled.
   * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
   *   The HTTP client factory.
   */
  public function __construct(
    private readonly ?AiProviderPluginManager $aiProviderManager,
    private readonly ClientFactory $httpClientFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function create(): MistralClient {
    if ($this->aiProviderManager === NULL) {
      throw new \RuntimeException('The Mistral AI provider is not available.');
    }

    $provider = $this->aiProviderManager->createInstance(self::PROVIDER_ID)->getPlugin();
    if (!$provider instanceof MistralProvider) {
      throw new \RuntimeException('The Mistral AI provider is not available.');
    }

    $client = $provider->getClient();

    // The partitech client discovers its own HTTP client without a timeout.
    // Set one from the site's HTTP client factory, so an unresponsive OCR
    // endpoint cannot block the form request indefinitely.
    $client->setClient($this->httpClientFactory->fromOptions([
      'timeout' => AttendanceRecognition::TIMEOUT,
      'connect_timeout' => AttendanceRecognition::CONNECT_TIMEOUT,
    ]));

    return $client;
  }

}
