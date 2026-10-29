<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Constants and checks shared by the AI attendance recognition.
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
   * The key entity that the AI provider OpenCode uses by default.
   *
   * Kept here because update 10015 installed the key entity for the former
   * OpenCode OCR backends.
   */
  public const string OPENCODE_KEY_ID = 'opencode_api_key';

  /**
   * The AI prompt type of the attendance recognition.
   */
  public const string PROMPT_TYPE = 'workspace_events_attendance';

  /**
   * The AI prompt that instructs the vision model.
   */
  public const string PROMPT_ID = 'workspace_events_attendance__default';

  /**
   * The AI operation type that describes a chat model with image vision.
   */
  public const string OPERATION_TYPE = 'chat_with_image_vision';

  /**
   * Model name markers of free models that may train on the uploaded data.
   *
   * @var string[]
   */
  public const array TRAINING_MODEL_MARKERS = ['free', 'big-pickle', 'nemotron'];

  /**
   * Checks whether a model may train on the images it is sent.
   *
   * Attendance lists contain the names of the members, so a model that uses
   * uploaded data for training must never be used for them.
   *
   * @param string $model_id
   *   The ID of the AI model.
   *
   * @return bool
   *   TRUE when the model must not be used for attendance lists.
   */
  public static function isTrainingModel(string $model_id): bool {
    $model_id = mb_strtolower($model_id);
    foreach (self::TRAINING_MODEL_MARKERS as $marker) {
      if (str_contains($model_id, $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
