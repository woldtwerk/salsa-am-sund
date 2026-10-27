<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Defines the personal flags of the dance figures.
 */
enum DanceFigureFlag: string {

  // Marks a dance figure as mastered.
  case Mastered = 'mastered';

  // Marks a dance figure as liked.
  case Like = 'like';

  /**
   * Returns the configuration name of the flag.
   *
   * @return string
   *   The configuration name.
   */
  public function configName(): string {
    return 'flag.flag.' . $this->value;
  }

  /**
   * Returns the permissions that allow a member to flag and unflag.
   *
   * @return string[]
   *   The permission names.
   */
  public function permissions(): array {
    return [
      'flag ' . $this->value,
      'unflag ' . $this->value,
    ];
  }

  /**
   * Returns the machine names of all dance figure flags.
   *
   * @return string[]
   *   The flag IDs.
   */
  public static function ids(): array {
    return array_map(static fn (self $flag): string => $flag->value, self::cases());
  }

}
