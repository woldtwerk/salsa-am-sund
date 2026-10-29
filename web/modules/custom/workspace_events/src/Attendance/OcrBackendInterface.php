<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

/**
 * Sends an attendance photo to an OCR or vision provider.
 */
interface OcrBackendInterface {

  /**
   * Recognizes the text on an image.
   *
   * @param string $contents
   *   The image bytes.
   * @param string $mimeType
   *   The MIME type of the image.
   *
   * @return string
   *   The raw text or markdown that the provider returned.
   *
   * @throws \Throwable
   *   When the provider rejects the request or returns no usable text.
   */
  public function recognizeText(string $contents, string $mimeType): string;

  /**
   * Returns the human readable name of the backend.
   *
   * @return string
   *   The label shown in log messages and the settings form.
   */
  public function label(): string;

}
