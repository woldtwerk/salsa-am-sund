<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileInterface;
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
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    private readonly MistralOcrClientFactoryInterface $clientFactory,
    private readonly AttendancePhotoTextParser $parser,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Recognizes the candidate names on an attendance photo.
   *
   * The photo is sent to Mistral OCR as a base64 data URL. No member data is
   * sent to the provider; the recognized lines are matched locally afterwards.
   *
   * @param \Drupal\file\FileInterface $photo
   *   The attendance photo.
   *
   * @return string[]
   *   The candidate name lines, empty when nothing was recognized.
   *
   * @throws \Drupal\workspace_events\Attendance\AttendanceRecognitionException
   *   When the photo cannot be read or the OCR request fails.
   */
  public function recognize(FileInterface $photo): array {
    $uri = $photo->getFileUri();
    if ($uri === NULL) {
      throw new AttendanceRecognitionException('Das Anwesenheitsfoto konnte nicht gelesen werden.');
    }
    $path = $this->fileSystem->realpath($uri);
    if ($path === FALSE || !is_file($path) || !is_readable($path)) {
      throw new AttendanceRecognitionException('Das Anwesenheitsfoto konnte nicht gelesen werden.');
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE || $contents === '') {
      throw new AttendanceRecognitionException('Das Anwesenheitsfoto konnte nicht gelesen werden.');
    }

    $mimeType = $photo->getMimeType() ?: 'image/jpeg';
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
