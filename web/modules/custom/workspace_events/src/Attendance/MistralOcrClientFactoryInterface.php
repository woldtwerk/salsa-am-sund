<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Partitech\PhpMistral\Clients\Mistral\MistralClient;

/**
 * Creates the Mistral client used for the OCR request.
 */
interface MistralOcrClientFactoryInterface {

  /**
   * Creates the Mistral client.
   *
   * @return \Partitech\PhpMistral\Clients\Mistral\MistralClient
   *   The client, authenticated through the configured Mistral provider.
   */
  public function create(): MistralClient;

}
