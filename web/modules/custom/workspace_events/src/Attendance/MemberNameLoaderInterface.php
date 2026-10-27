<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

/**
 * Loads the names of all members that can attend a lesson.
 */
interface MemberNameLoaderInterface {

  /**
   * Loads the member names.
   *
   * @return array<int, string>
   *   The user IDs keyed to their display names.
   */
  public function load(): array;

}
