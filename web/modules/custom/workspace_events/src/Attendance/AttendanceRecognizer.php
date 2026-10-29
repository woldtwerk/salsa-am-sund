<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Recognizes the names on an attendance photo with the configured OCR backend.
 */
final class AttendanceRecognizer {

  /**
   * Constructs an AttendanceRecognizer.
   *
   * @param \Drupal\workspace_events\Attendance\OcrBackendResolverInterface $backendResolver
   *   The resolver of the configured OCR backend.
   * @param \Drupal\workspace_events\Attendance\AttendancePhotoTextParser $parser
   *   The parser of the OCR markdown.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    private readonly OcrBackendResolverInterface $backendResolver,
    private readonly AttendancePhotoTextParser $parser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Recognizes the candidate names on an attendance photo.
   *
   * The image bytes are sent to the configured OCR or vision provider. No
   * member data is sent to the provider; the recognized lines are matched
   * locally afterwards. The bytes come from the uploaded file of the request
   * and are never written to the file system here.
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

    try {
      $markdown = $this->backendResolver->resolve()->recognizeText($contents, $mimeType);
    }
    catch (\Throwable $exception) {
      $this->loggerFactory->get('workspace_events')->error('The OCR request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new AttendanceRecognitionException('Das Anwesenheitsfoto konnte nicht erkannt werden.', 0, $exception);
    }

    return $this->parser->parse($markdown);
  }

}
