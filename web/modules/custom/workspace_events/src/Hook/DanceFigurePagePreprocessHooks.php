<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\taxonomy\TermInterface;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\workspace_events\DetailFieldResolver;

/**
 * Prepares the data of the dance figure pages.
 */
final class DanceFigurePagePreprocessHooks {

  use StringTranslationTrait;

  /**
   * Constructs the dance figure page hooks.
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

    $this->addDifficultyCacheability($variables, $term);
  }

  /**
   * Implements hook_preprocess_views_view_fields().
   *
   * The overview cards read the difficulty from the already loaded term, so
   * the multiple-value labels do not depend on the view's field handler.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_views_view_fields')]
  public function preprocessViewsViewFields(array &$variables): void {
    $view = $variables['view'] ?? NULL;
    if (!$view instanceof ViewExecutable || $view->id() !== 'dance_figures') {
      return;
    }
    $row = $variables['row'] ?? NULL;
    if (!$row instanceof ResultRow) {
      return;
    }
    $term = $row->_entity;
    if (!$term instanceof TermInterface || $term->bundle() !== DanceFigureAccessHooks::VOCABULARY) {
      return;
    }

    $variables['dance_figure_levels'] = $this->detailFieldResolver->getDifficultyLabels($term);
    $this->addDifficultyCacheability($variables, $term);
  }

  /**
   * Implements hook_form_views_exposed_form_alter().
   *
   * The flag filters share the generic boolean True/False labels. The overview
   * names the actual learned and favorite states instead.
   *
   * @param array<string, mixed> $form
   *   The exposed form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_views_exposed_form_alter')]
  public function formViewsExposedFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $view = $form_state->get('view');
    if (!$view instanceof ViewExecutable || $view->id() !== 'dance_figures') {
      return;
    }

    $learned = $form['learned'] ?? NULL;
    if (is_array($learned)) {
      $learned['#options'] = [
        'All' => $this->t('All'),
        1 => $this->t('Learned'),
        0 => $this->t('Not learned yet'),
      ];
      $form['learned'] = $learned;
    }

    $like = $form['like'] ?? NULL;
    if (is_array($like)) {
      $like['#options'] = [
        'All' => $this->t('All'),
        1 => $this->t('Favorites only'),
        0 => $this->t('No favorites'),
      ];
      $form['like'] = $like;
    }
  }

  /**
   * Adds the cache metadata of the difficulty labels to a render array.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   * @param \Drupal\taxonomy\TermInterface $term
   *   The dance figure term.
   *
   * @param-out array<mixed> $variables
   */
  private function addDifficultyCacheability(array &$variables, TermInterface $term): void {
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
