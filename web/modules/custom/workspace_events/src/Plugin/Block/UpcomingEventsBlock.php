<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspace_events\EventFilter;

/**
 * Provides a block with the upcoming events.
 */
#[Block(
  id: 'salsa_events',
  admin_label: new TranslatableMarkup('Upcoming events'),
  category: new TranslatableMarkup('Salsa am Sund'),
)]
final class UpcomingEventsBlock extends EventBlockBase {

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
      'organizers' => [],
      'courses' => FALSE,
      'locations' => [],
      'limit' => 3,
      'show_ical' => TRUE,
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
    // A multiple select, see EventBlockBase::buildLocationsElement().
    $form['organizers'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this->t('Organizers'),
      '#description' => $this->t('Leave empty to show all organizers.'),
      '#options' => $this->getOrganizerOptions(),
      '#default_value' => $this->getConfiguredOrganizers(),
    ];

    $form['courses'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show courses'),
      '#default_value' => $this->getConfiguredCourses(),
    ];

    $form['locations'] = $this->buildLocationsElement();

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of events'),
      '#min' => 1,
      '#max' => 50,
      '#default_value' => $this->getConfiguredLimit(),
      '#required' => TRUE,
    ];

    $form['show_ical'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show calendar subscription links'),
      '#default_value' => $this->getConfiguredShowIcal(),
    ];

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
    $this->configuration['organizers'] = $this->getSubmittedIds($form_state->getValue('organizers'));
    $this->configuration['courses'] = (bool) $form_state->getValue('courses');
    $this->configuration['locations'] = $this->getSubmittedIds($form_state->getValue('locations'));

    $limit = $form_state->getValue('limit');
    $this->configuration['limit'] = is_scalar($limit) ? max(1, min(50, (int) $limit)) : 3;
    $this->configuration['show_ical'] = (bool) $form_state->getValue('show_ical');
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the block.
   */
  public function build(): array {
    $organizers = $this->getConfiguredOrganizers();
    $courses = $this->getConfiguredCourses();
    $locations = $this->getConfiguredLocations();
    $limit = $this->getConfiguredLimit();

    $now = new \DateTimeImmutable('now');
    $occurrences = $this->repository->findOccurrences(
      new EventFilter(
        includeEvents: TRUE,
        organizerIds: $organizers,
        includeCourses: $courses,
        locationIds: $locations,
      ),
      $now,
      $now->modify('+365 days'),
      $limit,
    );

    /** @var array<string, mixed> $build */
    $build = [];
    if ($occurrences === []) {
      $build['empty'] = [
        '#markup' => $this->t('No upcoming events.'),
      ];
    }
    else {
      $items = [];
      foreach ($occurrences as $occurrence) {
        $items[] = [
          '#type' => 'component',
          '#component' => 'salsa:event',
          '#props' => $this->buildEventCardProps($occurrence),
        ];
      }

      $build['events'] = [
        '#type' => 'component',
        '#component' => 'salsa:grid',
        '#props' => [
          'columns' => '3',
          'gap' => 'md',
        ],
        '#slots' => [
          'items' => $items,
        ],
      ];
    }

    if ($this->getConfiguredShowIcal()) {
      $build['ical'] = $this->buildIcalLinks($organizers, $locations, $this->findCourses(), TRUE, FALSE);
    }

    $this->applyOccurrenceCache($build, $occurrences);

    return $build;
  }

  /**
   * Returns the configured organizer term IDs.
   *
   * @return int[]
   *   The organizer term IDs, empty for every organizer.
   */
  private function getConfiguredOrganizers(): array {
    return $this->getConfiguredIds('organizers');
  }

  /**
   * Returns whether the block shows courses.
   */
  private function getConfiguredCourses(): bool {
    return (bool) ($this->getConfiguration()['courses'] ?? FALSE);
  }

  /**
   * Returns the configured number of events.
   */
  private function getConfiguredLimit(): int {
    $limit = $this->getConfiguration()['limit'] ?? 3;
    return is_scalar($limit) ? max(1, min(50, (int) $limit)) : 3;
  }

  /**
   * Returns whether the calendar links are enabled.
   */
  private function getConfiguredShowIcal(): bool {
    return (bool) ($this->getConfiguration()['show_ical'] ?? TRUE);
  }

}
