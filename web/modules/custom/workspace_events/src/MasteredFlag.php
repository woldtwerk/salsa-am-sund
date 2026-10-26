<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Defines the "mastered" flag of the dance figures.
 */
final class MasteredFlag {

  /**
   * The machine name of the flag.
   */
  public const string FLAG_ID = 'mastered';

  /**
   * The configuration name of the flag.
   */
  public const string CONFIG_NAME = 'flag.flag.mastered';

  /**
   * The permissions that allow a member to flag and unflag a dance figure.
   */
  public const array PERMISSIONS = [
    'flag mastered',
    'unflag mastered',
  ];

}
