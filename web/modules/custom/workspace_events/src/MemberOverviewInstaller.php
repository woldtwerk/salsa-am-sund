<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Creates the member overview views.
 */
final class MemberOverviewInstaller extends ConfigRecordInstaller {

  /**
   * The configuration entities of the member overview pages.
   */
  public const array CONFIG_NAMES = [
    'views.view.dance_figures',
    'views.view.my_courses',
  ];

}
