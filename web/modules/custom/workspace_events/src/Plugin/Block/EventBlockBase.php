<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Block;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\DetailFieldResolver;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventOccurrence;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\workspace_events\OrganizerResolver;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for blocks that render event occurrences.
 */
abstract class EventBlockBase extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * German weekday keys, indexed by ISO-8601 day number.
   */
  protected const array WEEKDAYS = [
    1 => 'montag',
    2 => 'dienstag',
    3 => 'mittwoch',
    4 => 'donnerstag',
    5 => 'freitag',
    6 => 'samstag',
    7 => 'sonntag',
  ];

  /**
   * Constructs an EventBlockBase.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\workspace_events\EventOccurrenceRepository $repository
   *   The occurrence repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\EventDescriptionFormatter $descriptionFormatter
   *   The event description formatter.
   * @param \Drupal\workspace_events\DetailFieldResolver $detailFieldResolver
   *   The reader of the location, link and difficulty fields.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected readonly EventOccurrenceRepository $repository,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EventDescriptionFormatter $descriptionFormatter,
    protected readonly DetailFieldResolver $detailFieldResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param array<string, mixed> $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $repository = $container->get(EventOccurrenceRepository::class);
    \assert($repository instanceof EventOccurrenceRepository);
    $entity_type_manager = $container->get(EntityTypeManagerInterface::class);
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $description_formatter = $container->get(EventDescriptionFormatter::class);
    \assert($description_formatter instanceof EventDescriptionFormatter);
    $detail_field_resolver = $container->get(DetailFieldResolver::class);
    \assert($detail_field_resolver instanceof DetailFieldResolver);

    // @phpstan-ignore-next-line
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $repository,
      $entity_type_manager,
      $description_formatter,
      $detail_field_resolver,
    );
  }

  /**
   * Returns the organizer terms, in vocabulary order.
   *
   * @return array<int, \Drupal\taxonomy\TermInterface>
   *   The terms, keyed by term ID.
   */
  protected function getOrganizerTerms(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => OrganizerResolver::VOCABULARY]);
    uasort($terms, static fn (TermInterface $a, TermInterface $b): int => [
      $a->getWeight(),
      (string) $a->label(),
    ] <=> [
      $b->getWeight(),
      (string) $b->label(),
    ]);
    $options = [];
    foreach ($terms as $term) {
      \assert($term instanceof TermInterface);
      $options[(int) $term->id()] = $term;
    }
    return $options;
  }

  /**
   * Returns the organizer options for the block form.
   *
   * @return array<int, string>
   *   The term labels, keyed by term ID, in vocabulary order.
   */
  protected function getOrganizerOptions(): array {
    $options = [];
    foreach ($this->getOrganizerTerms() as $tid => $term) {
      $options[$tid] = (string) $term->label();
    }
    return $options;
  }

  /**
   * Returns the location terms, sorted by label.
   *
   * @return array<int, \Drupal\taxonomy\TermInterface>
   *   The terms, keyed by term ID.
   */
  protected function getLocationTerms(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties(['vid' => 'location']);
    uasort($terms, static fn (TermInterface $a, TermInterface $b): int => strcasecmp((string) $a->label(), (string) $b->label()));
    $options = [];
    foreach ($terms as $term) {
      \assert($term instanceof TermInterface);
      $options[(int) $term->id()] = $term;
    }
    return $options;
  }

  /**
   * Returns the location options for the block form.
   *
   * @return array<int, string>
   *   The term labels, keyed by term ID.
   */
  protected function getLocationOptions(): array {
    $options = [];
    foreach ($this->getLocationTerms() as $tid => $term) {
      $options[$tid] = (string) $term->label();
    }
    return $options;
  }

  /**
   * Builds the location filter element for the block configuration form.
   *
   * @return array<string, mixed>
   *   The checkboxes form element.
   */
  protected function buildLocationsElement(): array {
    return [
      '#type' => 'checkboxes',
      '#title' => $this->t('Locations'),
      '#description' => $this->t('Leave empty to show all locations.'),
      '#options' => $this->getLocationOptions(),
      '#default_value' => $this->getConfiguredLocations(),
    ];
  }

  /**
   * Returns the configured location term IDs.
   *
   * @return int[]
   *   The location term IDs.
   */
  protected function getConfiguredLocations(): array {
    return $this->getConfiguredIds('locations');
  }

  /**
   * Extracts the checked term IDs from a checkboxes form value.
   *
   * Checkbox values are keyed by term ID and hold the term ID when checked
   * and 0 when unchecked.
   *
   * @param mixed $value
   *   The submitted form value.
   *
   * @return int[]
   *   The checked term IDs, as a list.
   */
  protected function getSubmittedIds(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $ids = [];
    foreach (array_filter($value) as $id) {
      if (is_scalar($id) && is_numeric($id)) {
        $ids[] = (int) $id;
      }
    }
    return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
  }

  /**
   * Returns a list of configured taxonomy term IDs.
   *
   * @param string $key
   *   The configuration key.
   *
   * @return int[]
   *   The positive term IDs.
   */
  protected function getConfiguredIds(string $key): array {
    $values = $this->getConfiguration()[$key] ?? [];
    if (!is_array($values)) {
      return [];
    }
    $ids = [];
    foreach ($values as $value) {
      if (!is_int($value) && !is_string($value)) {
        continue;
      }
      if (is_string($value) && !ctype_digit($value)) {
        continue;
      }
      $id = (int) $value;
      if ($id > 0 && !in_array($id, $ids, TRUE)) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  /**
   * Builds the component props of an event card.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return array<string, string|\Drupal\Component\Render\MarkupInterface>
   *   The props of the salsa:event component.
   */
  protected function buildEventCardProps(EventOccurrence $occurrence): array {
    $node = $occurrence->node;
    $props = [
      'date' => $occurrence->start->format('Y-m-d'),
      'title' => (string) $node->label(),
      'href' => $this->getHref($node),
      'ical_href' => $occurrence->toIcalUrl()->toString(),
    ];
    $time = $this->formatTime($occurrence);
    if ($time !== NULL) {
      $props['time'] = $time;
    }
    if ($occurrence->location !== NULL) {
      $props['location'] = (string) $occurrence->location->label();
    }
    $description = $this->getDescription($node);
    if ($description !== NULL) {
      $props['text'] = $description;
    }
    return $props;
  }

  /**
   * Formats the time of an occurrence for display.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string|null
   *   The formatted time, or NULL for all-day events.
   */
  protected function formatTime(EventOccurrence $occurrence): ?string {
    if ($occurrence->allDay) {
      return NULL;
    }
    $start = $occurrence->start->format('H:i');
    if ($occurrence->end === NULL) {
      return (string) $this->t('from @start', ['@start' => $start]);
    }
    return (string) $this->t('@start – @end', [
      '@start' => $start,
      '@end' => $occurrence->end->format('H:i'),
    ]);
  }

  /**
   * Returns the next occurrence of every course.
   *
   * The repository finds the lessons in the lookahead window; the first
   * upcoming lesson per course is kept and the result is sorted by weekday and
   * time, like the course schedule.
   *
   * @param int[] $locations
   *   The location term IDs to restrict to. Empty for all locations.
   * @param int $days
   *   The number of days to look ahead.
   *
   * @return \Drupal\workspace_events\EventOccurrence[]
   *   The next occurrence of each course.
   */
  protected function findCourses(array $locations = [], int $days = 120): array {
    $now = new \DateTimeImmutable('now');
    $occurrences = $this->repository->findOccurrences(
      EventFilter::coursesOnly(locationIds: $locations),
      $now,
      $now->modify('+' . $days . ' days'),
    );

    // Show one entry per course, based on its next occurrence.
    $by_node = [];
    foreach ($occurrences as $occurrence) {
      $by_node[(int) $occurrence->node->id()] ??= $occurrence;
    }
    $courses = array_values($by_node);
    usort($courses, static function (EventOccurrence $a, EventOccurrence $b): int {
      return [
        (int) $a->start->format('N'),
        $a->start->format('H:i'),
        (int) $a->node->id(),
      ] <=> [
        (int) $b->start->format('N'),
        $b->start->format('H:i'),
        (int) $b->node->id(),
      ];
    });

    return $courses;
  }

  /**
   * Builds the calendar subscription component.
   *
   * The component offers the given organizers and the courses passed in by the
   * caller: the visitor decides in the popover what ends up in the calendar.
   * The configured locations stay a hidden filter of the feed.
   *
   * @param int[] $organizerIds
   *   The configured organizer term IDs, empty for every organizer.
   * @param int[] $locations
   *   The configured location term IDs.
   * @param \Drupal\workspace_events\EventOccurrence[] $courses
   *   The next occurrence of every course to offer.
   * @param bool $organizersChecked
   *   Whether the organizer options are pre-checked.
   * @param bool $coursesChecked
   *   Whether the course options are pre-checked.
   *
   * @return array<string, mixed>
   *   The render array of the subscription component.
   */
  protected function buildIcalLinks(array $organizerIds, array $locations, array $courses, bool $organizersChecked, bool $coursesChecked): array {
    $options = [];
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags(['taxonomy_term_list:organizer']);
    $offered = $this->getOrganizerTerms();
    if ($organizerIds !== []) {
      // Only the organizers allowed by the block configuration are offered.
      $offered = array_intersect_key($offered, array_flip($organizerIds));
    }
    foreach ($offered as $tid => $term) {
      $options[] = [
        'name' => 'organizer[]',
        'value' => (string) $tid,
        'label' => (string) $term->label(),
        'checked' => $organizersChecked,
        'group' => 'organizers',
      ];
      $cacheability->addCacheableDependency($term);
    }
    foreach ($courses as $occurrence) {
      $options[] = [
        'name' => 'course[]',
        'value' => (string) $occurrence->node->id(),
        'label' => (string) $occurrence->node->label(),
        'checked' => $coursesChecked,
        'group' => 'courses',
        'meta' => $this->formatCourseMeta($occurrence),
      ];
    }

    /** @var array<string, mixed> $build */
    $build = [];
    if ($options === []) {
      // Without a selectable entry the popover has nothing to offer, so the
      // plain links are kept.
      $absolute = $this->getCalendarUrl($organizerIds, $locations, TRUE)->toString();
      $build['subscribe'] = [
        '#type' => 'component',
        '#component' => 'salsa:button',
        '#props' => [
          'label' => (string) $this->t('Subscribe to calendar'),
          'href' => preg_replace('#^https?://#', 'webcal://', $absolute) ?? $absolute,
          'variant' => 'outline',
          'size' => 'md',
          'icon' => 'external-link',
        ],
      ];
      $build['download'] = [
        '#type' => 'link',
        '#title' => $this->t('Download .ics'),
        '#url' => $this->getCalendarUrl($organizerIds, $locations),
      ];
      return $build;
    }

    $hidden = [];
    if ($locations !== []) {
      $hidden[] = [
        'name' => 'location',
        'value' => implode(',', $locations),
      ];
    }

    /** @var array<string, mixed> $subscribe */
    $subscribe = [
      '#type' => 'component',
      '#component' => 'salsa:calendar-subscribe',
      '#props' => [
        'action' => $this->getCalendarUrl([], [], TRUE)->toString(),
        'id' => Html::getUniqueId('salsa-calendar-subscribe'),
        'options' => $options,
        'hidden' => $hidden,
      ],
    ];

    // New courses must appear in the popover.
    $cacheability->addCacheTags(['node_list:course']);
    foreach ($courses as $occurrence) {
      $cacheability->addCacheableDependency($occurrence->node);
      if ($occurrence->location !== NULL) {
        $cacheability->addCacheableDependency($occurrence->location);
      }
    }
    $cacheability->applyTo($subscribe);
    $build['subscribe'] = $subscribe;

    return $build;
  }

  /**
   * Formats the meta line of a course option.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The next occurrence of the course.
   *
   * @return string
   *   The weekday, time and location, joined for display.
   */
  protected function formatCourseMeta(EventOccurrence $occurrence): string {
    $parts = [];
    $weekday = self::WEEKDAYS[(int) $occurrence->start->format('N')] ?? '';
    if ($weekday !== '') {
      $parts[] = ucfirst($weekday);
    }
    $time = $this->formatTime($occurrence);
    if ($time !== NULL) {
      $parts[] = $time;
    }
    if ($occurrence->location !== NULL) {
      $parts[] = (string) $occurrence->location->label();
    }
    return implode(' · ', $parts);
  }

  /**
   * Returns the link target of an event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string
   *   The external link when set, otherwise the node path.
   */
  protected function getHref(NodeInterface $node): string {
    if ($node->hasField('field_link') && !$node->get('field_link')->isEmpty()) {
      $uri = $node->get('field_link')->first()?->get('uri')->getString() ?? '';
      if ($uri !== '') {
        return $uri;
      }
    }
    return $node->toUrl()->toString();
  }

  /**
   * Returns the description of an event as safe HTML.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Component\Render\MarkupInterface|null
   *   The description, or NULL when the event has no body.
   */
  protected function getDescription(NodeInterface $node): ?MarkupInterface {
    return $this->descriptionFormatter->toHtml($node);
  }

  /**
   * Returns the calendar route for the given filter.
   *
   * @param int[] $organizerIds
   *   The organizer term IDs.
   * @param int[] $locations
   *   The location term IDs.
   * @param bool $absolute
   *   Whether to generate an absolute URL.
   *
   * @return \Drupal\Core\Url
   *   The URL object.
   */
  protected function getCalendarUrl(array $organizerIds, array $locations, bool $absolute = FALSE): Url {
    $query = [];
    if ($organizerIds !== []) {
      $query['organizer'] = implode(',', $organizerIds);
    }
    if ($locations !== []) {
      $query['location'] = implode(',', $locations);
    }
    return Url::fromRoute('workspace_events.ical', [], [
      'query' => $query,
      'absolute' => $absolute,
    ]);
  }

  /**
   * Applies cache metadata for a list of occurrences to a render array.
   *
   * @param array<mixed> $build
   *   The render array.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The rendered occurrences.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $extraCacheability
   *   Additional cacheability of the block output, e.g. of generated URLs.
   */
  protected function applyOccurrenceCache(array &$build, array $occurrences, ?CacheableMetadata $extraCacheability = NULL): void {
    $cacheability = new CacheableMetadata();
    if ($extraCacheability !== NULL) {
      $cacheability->addCacheableDependency($extraCacheability);
    }
    $cacheability->addCacheTags([
      'node_list:event',
      'node_list:course',
      'node_list:lesson',
      'taxonomy_term_list:location',
      'taxonomy_term_list:organizer',
    ]);

    $now = time();
    $max_age = 86400;
    foreach ($occurrences as $occurrence) {
      $cacheability->addCacheableDependency($occurrence->sourceNode());
      $cacheability->addCacheableDependency($occurrence->node);
      if ($occurrence->organizer !== NULL) {
        $cacheability->addCacheableDependency($occurrence->organizer);
      }
      if ($occurrence->location !== NULL) {
        $cacheability->addCacheableDependency($occurrence->location);
      }
      // The description is rendered through the body's text format.
      if ($occurrence->node->hasField('body') && !$occurrence->node->get('body')->isEmpty()) {
        $format = $occurrence->node->get('body')->first()?->get('format')->getString() ?? '';
        if ($format !== '') {
          $cacheability->addCacheTags(['config:filter.format.' . $format]);
        }
      }
      $end = $occurrence->end !== NULL
        ? $occurrence->endTimestamp()
        : $occurrence->start->setTime(23, 59)->getTimestamp();
      $max_age = min($max_age, max(0, $end - $now));
    }
    $cacheability->setCacheMaxAge($max_age);
    $cacheability->applyTo($build);
  }

}
