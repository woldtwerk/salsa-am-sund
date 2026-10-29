<?php

declare(strict_types=1);

namespace Drupal\ai_provider_opencode\Form;

use Drupal\ai_provider_opencode\Plugin\AiProvider\OpenCodeGoProvider;

/**
 * Configures the OpenCode Go provider.
 */
final class OpenCodeGoConfigForm extends OpenCodeConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_provider_opencode_go_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function configName(): string {
    return 'ai_provider_opencode.' . OpenCodeGoProvider::CONFIG_KEY . '.settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function providerId(): string {
    return OpenCodeGoProvider::ID;
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultBaseUrl(): string {
    return OpenCodeGoProvider::DEFAULT_BASE_URL;
  }

}
