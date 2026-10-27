<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Creates the dance figure vocabulary and its configuration.
 *
 * The installer reads the configuration records from the sync or install
 * storage, so the entities get the UUIDs of the exported configuration.
 */
final class DanceFigureInstaller extends ConfigRecordInstaller {

  /**
   * The configuration entities of the dance figure vocabulary.
   *
   * The order is the order the entities have to be created in: the vocabulary
   * first, then its fields, then the displays and finally the role.
   */
  public const array CONFIG_NAMES = [
    'taxonomy.vocabulary.dance_figure',
    'field.storage.taxonomy_term.field_difficulty',
    'field.field.taxonomy_term.dance_figure.field_difficulty',
    'field.storage.taxonomy_term.field_video',
    'field.field.taxonomy_term.dance_figure.field_video',
    'core.entity_form_display.taxonomy_term.dance_figure.default',
    'core.entity_view_display.taxonomy_term.dance_figure.default',
    'pathauto.pattern.dance_figure_path',
    'user.role.member',
  ];

}
