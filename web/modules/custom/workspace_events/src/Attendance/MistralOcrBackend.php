<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\workspace_events\AttendanceRecognition;

/**
 * Recognizes attendance photos with the Mistral OCR API.
 */
final class MistralOcrBackend implements OcrBackendInterface {

  /**
   * Constructs a MistralOcrBackend.
   *
   * @param \Drupal\workspace_events\Attendance\MistralOcrClientFactoryInterface $clientFactory
   *   The factory of the Mistral client.
   */
  public function __construct(
    private readonly MistralOcrClientFactoryInterface $clientFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function recognizeText(string $contents, string $mimeType): string {
    $dataUrl = sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));

    $client = $this->clientFactory->create();
    $messages = $client->newMessages();
    $messages->addDocumentMessage('image_url', $dataUrl);
    $response = $client->ocr($messages, ['model' => AttendanceRecognition::MODEL]);

    $markdown = '';
    foreach ($response->getPages() ?? [] as $page) {
      if (is_array($page) && isset($page['markdown']) && is_string($page['markdown'])) {
        $markdown .= $page['markdown'] . "\n";
      }
    }

    return $markdown;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Mistral OCR';
  }

}
