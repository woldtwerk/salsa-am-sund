<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Recognizes attendance photos with a chat model that accepts images.
 *
 * Any provider of the AI module can be used, so the photo leaves the site
 * through the provider that an administrator selected in the AI settings.
 */
final class AiProviderVisionBackend implements OcrBackendInterface {

  /**
   * The instruction sent to the vision model.
   *
   * The model returns plain names, one per line, so the local parser is the
   * only place that knows how the attendance list is matched to the members.
   */
  public const string PROMPT = 'Lies diese handschriftliche Anwesenheitsliste. Gib ausschließlich die Namen der Personen zurück, einen Namen pro Zeile, ohne Nummern, Überschriften oder sonstigen Text.';

  /**
   * Constructs an AiProviderVisionBackend.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface|null $aiProviderManager
   *   The AI provider plugin manager (the ai.provider service), or NULL when
   *   the AI module is not available.
   * @param string $providerId
   *   The ID of the AI provider.
   * @param string $modelId
   *   The model that accepts images.
   */
  public function __construct(
    private readonly ?PluginManagerInterface $aiProviderManager,
    private readonly string $providerId,
    private readonly string $modelId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function recognizeText(string $contents, string $mimeType): string {
    if ($contents === '') {
      throw new \RuntimeException('The attendance photo is empty.');
    }

    if ($this->aiProviderManager === NULL) {
      throw new \RuntimeException('The AI module is not available.');
    }

    $provider = $this->aiProviderManager->createInstance($this->providerId);
    if (!$provider instanceof ProviderProxy) {
      throw new \RuntimeException(sprintf('The AI provider %s is not available.', $this->providerId));
    }

    $plugin = $provider->getPlugin();
    if (!$plugin instanceof ChatInterface) {
      throw new \RuntimeException(sprintf('The AI provider %s does not support chat.', $this->providerId));
    }

    $input = new ChatInput([
      new ChatMessage('user', self::PROMPT, [new ImageFile($contents, $mimeType)]),
    ]);
    $output = $plugin->chat($input, $this->modelId, ['attendance_recognition']);
    $message = $output->getNormalized();
    if (!$message instanceof ChatMessage) {
      throw new \RuntimeException(sprintf('The AI provider %s returned no chat message.', $this->label()));
    }

    $text = $message->getText();
    if (trim($text) === '') {
      throw new \RuntimeException(sprintf('The AI provider %s returned no text.', $this->label()));
    }

    return $text;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return sprintf('%s (%s)', $this->providerId, $this->modelId);
  }

}
