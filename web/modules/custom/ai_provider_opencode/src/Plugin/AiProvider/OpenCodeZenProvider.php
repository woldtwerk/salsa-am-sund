<?php

declare(strict_types=1);

namespace Drupal\ai_provider_opencode\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the OpenCode Zen pay-as-you-go provider.
 */
#[AiProvider(
  id: self::ID,
  label: new TranslatableMarkup('OpenCode Zen (Guthaben)'),
)]
class OpenCodeZenProvider extends OpenCodeProviderBase {

  /**
   * The plugin ID.
   */
  public const string ID = 'opencode_zen';

  /**
   * The config key, used in the settings name.
   */
  public const string CONFIG_KEY = 'zen';

  /**
   * The default base URL of the OpenAI-compatible API.
   */
  public const string DEFAULT_BASE_URL = 'https://opencode.ai/inference/openai/v1';

  /**
   * The model that the provider is set up with.
   */
  public const string DEFAULT_MODEL = 'glm-5.3';

  /**
   * {@inheritdoc}
   */
  protected function configKey(): string {
    return self::CONFIG_KEY;
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultBaseUrl(): string {
    return self::DEFAULT_BASE_URL;
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultModel(): string {
    return self::DEFAULT_MODEL;
  }

}
