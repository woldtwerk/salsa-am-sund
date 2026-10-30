<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\DetailFieldResolver;

/**
 * Prepares the data of a dance figure detail page.
 */
final class DanceFigurePagePreprocessHooks {

  /**
   * Constructs the dance figure page preprocess hooks.
   *
   * @param \Drupal\workspace_events\DetailFieldResolver $detailFieldResolver
   *   The reader of the shared detail fields.
   */
  public function __construct(
    private readonly DetailFieldResolver $detailFieldResolver,
  ) {}

  /**
   * Implements hook_preprocess_taxonomy_term().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_taxonomy_term')]
  public function preprocessTaxonomyTerm(array &$variables): void {
    $term = $variables['term'] ?? NULL;
    if (!$term instanceof TermInterface || $term->bundle() !== DanceFigureAccessHooks::VOCABULARY) {
      return;
    }

    $variables['dance_figure_levels'] = $this->detailFieldResolver->getDifficultyLabels($term);
    $variables['dance_figure_has_video'] = $term->hasField('field_video') && !$term->get('field_video')->isEmpty();
    $variables['dance_figure_has_description'] = $term->hasField('description')
      && trim($term->get('description')->getString()) !== '';

    $cacheability = CacheableMetadata::createFromRenderArray($variables);
    $cacheability->addCacheableDependency($term);
    // The labels come from the allowed values of the difficulty field.
    $cacheability->addCacheTags([
      'config:field.field.taxonomy_term.dance_figure.field_difficulty',
      'config:field.storage.taxonomy_term.field_difficulty',
    ]);
    $cacheability->applyTo($variables);
  }

}
