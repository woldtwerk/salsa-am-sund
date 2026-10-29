<?php

declare(strict_types=1);

namespace Drupal\ai_provider_opencode\Form;

use Drupal\ai_provider_opencode\Plugin\AiProvider\OpenCodeZenProvider;

/**
 * Configures the OpenCode Zen provider.
 */
final class OpenCodeZenConfigForm extends OpenCodeConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_provider_opencode_zen_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function configName(): string {
    return 'ai_provider_opencode.' . OpenCodeZenProvider::CONFIG_KEY . '.settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function providerId(): string {
    return OpenCodeZenProvider::ID;
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultBaseUrl(): string {
    return OpenCodeZenProvider::DEFAULT_BASE_URL;
  }

}
