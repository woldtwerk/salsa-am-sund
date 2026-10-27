<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a block with the weekly course schedule.
 */
#[Block(
  id: 'salsa_course_schedule',
  admin_label: new TranslatableMarkup('Course schedule'),
  category: new TranslatableMarkup('Salsa am Sund'),
)]
final class CourseScheduleBlock extends EventBlockBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default configuration.
   */
  public function defaultConfiguration(): array {
    /** @var array<string, mixed> $defaults */
    $defaults = parent::defaultConfiguration();
    return [
      'locations' => [],
    ] + $defaults;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The block configuration form.
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['locations'] = $this->buildLocationsElement();

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The block configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['locations'] = $this->getSubmittedIds($form_state->getValue('locations'));
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the block.
   */
  public function build(): array {
    $courses = $this->findCourses($this->getConfiguredLocations());

    /** @var array<string, mixed> $build */
    $build = [];
    $url_cacheability = new CacheableMetadata();
    if ($courses !== []) {
      $items = [];
      foreach ($courses as $occurrence) {
        $node = $occurrence->node;
        $props = [
          'day' => self::WEEKDAYS[(int) $occurrence->start->format('N')],
          'time' => $this->formatTime($occurrence) ?? '',
          'title' => (string) $node->label(),
        ];
        // Unsaved nodes have no URL yet, and their alias can still change.
        if (!$node->isNew()) {
          $generated_url = $node->toUrl()->toString(TRUE);
          $props['href'] = $generated_url->getGeneratedUrl();
          $url_cacheability->addCacheableDependency($generated_url);
        }
        $level = $this->detailFieldResolver->getDifficultyLabel($node);
        if ($level !== '') {
          $props['level'] = $level;
        }
        if ($occurrence->location !== NULL) {
          $props['location'] = (string) $occurrence->location->label();
          if ($occurrence->location->hasField('field_map_link') && !$occurrence->location->get('field_map_link')->isEmpty()) {
            $map_link = $occurrence->location->get('field_map_link')->first()?->get('uri')->getString() ?? '';
            if ($map_link !== '') {
              $props['location_href'] = $map_link;
            }
          }
        }
        $items[] = [
          '#type' => 'component',
          '#component' => 'salsa:course',
          '#props' => $props,
        ];
      }

      $build['schedule'] = [
        '#type' => 'component',
        '#component' => 'salsa:schedule',
        '#slots' => [
          'courses' => $items,
        ],
      ];

      $build['ical'] = $this->buildIcalLinks([], $this->getConfiguredLocations(), $courses, FALSE, TRUE);
    }

    $this->applyOccurrenceCache($build, $courses, $url_cacheability);

    return $build;
  }

}
