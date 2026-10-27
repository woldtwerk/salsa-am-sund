<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Constants shared by the AI attendance recognition.
 */
final class AttendanceRecognition {

  /**
   * The permission that allows using the attendance recognition.
   */
  public const string PERMISSION = 'use attendance recognition';

  /**
   * The role whose users can be recognized on an attendance photo.
   */
  public const string MEMBER_ROLE = 'member';

  /**
   * The key entity that holds the Mistral API key.
   */
  public const string KEY_ID = 'mistral_api_key';

  /**
   * The Mistral OCR model.
   */
  public const string MODEL = 'mistral-ocr-latest';

  /**
   * The timeout of an OCR request in seconds.
   */
  public const int TIMEOUT = 60;

  /**
   * The connection timeout of an OCR request in seconds.
   */
  public const int CONNECT_TIMEOUT = 10;

}
