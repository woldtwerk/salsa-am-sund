<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\workspace_events\AttendanceRecognition;

/**
 * Recognizes the names on an attendance photo with Mistral OCR.
 */
final class AttendanceRecognizer {

  /**
   * Constructs an AttendanceRecognizer.
   *
   * @param \Drupal\workspace_events\Attendance\MistralOcrClientFactoryInterface $clientFactory
   *   The factory of the Mistral client.
   * @param \Drupal\workspace_events\Attendance\AttendancePhotoTextParser $parser
   *   The parser of the OCR markdown.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    private readonly MistralOcrClientFactoryInterface $clientFactory,
    private readonly AttendancePhotoTextParser $parser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Recognizes the candidate names on an attendance photo.
   *
   * The image bytes are sent to Mistral OCR as a base64 data URL. No member
   * data is sent to the provider; the recognized lines are matched locally
   * afterwards. The bytes come from the uploaded file of the request and are
   * never written to the file system here.
   *
   * @param string $contents
   *   The image bytes.
   * @param string $mimeType
   *   The MIME type of the image.
   *
   * @return string[]
   *   The candidate name lines, empty when nothing was recognized.
   *
   * @throws \Drupal\workspace_events\Attendance\AttendanceRecognitionException
   *   When the image is empty or the OCR request fails.
   */
  public function recognizeImage(string $contents, string $mimeType): array {
    if ($contents === '') {
      throw new AttendanceRecognitionException('Das Anwesenheitsfoto konnte nicht gelesen werden.');
    }

    $dataUrl = sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));

    try {
      $client = $this->clientFactory->create();
      $messages = $client->newMessages();
      $messages->addDocumentMessage('image_url', $dataUrl);
      $response = $client->ocr($messages, ['model' => AttendanceRecognition::MODEL]);
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('workspace_events')->error('The Mistral OCR request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new AttendanceRecognitionException('Das Anwesenheitsfoto konnte nicht erkannt werden.', 0, $exception);
    }

    $markdown = '';
    foreach ($response->getPages() ?? [] as $page) {
      if (is_array($page) && isset($page['markdown']) && is_string($page['markdown'])) {
        $markdown .= $page['markdown'] . "\n";
      }
    }

    return $this->parser->parse($markdown);
  }

}
