<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\Core\Http\ClientFactory;
use Drupal\key\KeyRepositoryInterface;
use Drupal\workspace_events\AttendanceRecognition;
use GuzzleHttp\Exception\RequestException;

/**
 * Recognizes attendance photos with an OpenAI-compatible chat completion.
 *
 * Used for the OpenCode Zen and OpenCode Go endpoints, which both accept the
 * OpenAI chat completions format but serve different models.
 */
final class OpenAiCompatibleVisionBackend implements OcrBackendInterface {

  /**
   * The instruction sent to the vision model.
   *
   * The model returns plain names, one per line, so the local parser is the
   * only place that knows how the attendance list is matched to the members.
   */
  public const string PROMPT = 'Lies diese handschriftliche Anwesenheitsliste. Gib ausschließlich die Namen der Personen zurück, einen Namen pro Zeile, ohne Nummern, Überschriften oder sonstigen Text.';

  /**
   * Constructs an OpenAiCompatibleVisionBackend.
   *
   * @param string $baseUrl
   *   The base URL of the OpenAI-compatible API.
   * @param string $model
   *   The model that accepts images.
   * @param string $keyId
   *   The ID of the Key entity that holds the API key.
   * @param string $label
   *   The human readable name of the backend.
   * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
   *   The HTTP client factory.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The repository of the Key entities.
   */
  public function __construct(
    private readonly string $baseUrl,
    private readonly string $model,
    private readonly string $keyId,
    private readonly string $label,
    private readonly ClientFactory $httpClientFactory,
    private readonly KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function recognizeText(string $contents, string $mimeType): string {
    $key = $this->keyRepository->getKey($this->keyId);
    if ($key === NULL) {
      throw new \RuntimeException(sprintf('The API key %s for %s is not configured.', $this->keyId, $this->label));
    }

    $keyValue = $key->getKeyValue();
    if (!is_string($keyValue) || $keyValue === '') {
      throw new \RuntimeException(sprintf('The API key %s for %s is empty.', $this->keyId, $this->label));
    }

    $client = $this->httpClientFactory->fromOptions([
      'timeout' => AttendanceRecognition::TIMEOUT,
      'connect_timeout' => AttendanceRecognition::CONNECT_TIMEOUT,
    ]);

    $payload = [
      'model' => $this->model,
      'temperature' => 0,
      'messages' => [
        [
          'role' => 'user',
          'content' => [
            [
              'type' => 'text',
              'text' => self::PROMPT,
            ],
            [
              'type' => 'image_url',
              'image_url' => [
                'url' => sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents)),
              ],
            ],
          ],
        ],
      ],
    ];

    try {
      $response = $client->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
        'headers' => [
          'Authorization' => 'Bearer ' . $keyValue,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
      ]);
    }
    catch (RequestException $exception) {
      $status = $exception->getResponse()?->getStatusCode();
      throw new \RuntimeException(sprintf('The %s request failed%s.', $this->label, $status === NULL ? '' : sprintf(' with HTTP status %d', $status)), 0, $exception);
    }

    try {
      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \RuntimeException(sprintf('The %s response is not valid JSON.', $this->label), 0, $exception);
    }

    $content = NULL;
    if (is_array($data) && isset($data['choices']) && is_array($data['choices'])) {
      $choice = $data['choices'][0] ?? NULL;
      if (is_array($choice) && isset($choice['message']) && is_array($choice['message'])) {
        $content = $choice['message']['content'] ?? NULL;
      }
    }
    if (!is_string($content) || trim($content) === '') {
      throw new \RuntimeException(sprintf('The %s response contains no text.', $this->label));
    }

    return $content;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return $this->label;
  }

}
