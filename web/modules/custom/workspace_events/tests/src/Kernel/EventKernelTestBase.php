<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\workspace_events\Difficulty;
use Drupal\workspace_events\OrganizerResolver;

/**
 * Base class for workspace_events kernel tests.
 *
 * Builds the event content model programmatically, so the tests only cover
 * this module's logic and not the site's configuration.
 */
abstract class EventKernelTestBase extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * The timezone used by the test date values.
   */
  protected const string TIMEZONE = 'Europe/Berlin';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'field',
    'node',
    'options',
    'taxonomy',
    'content_moderation',
    'workflows',
    'pathauto',
    'token',
    'flag',
    'smart_date',
    'smart_date_recur',
    'workspace_events',
  ];

  /**
   * The taxonomy term storage.
   */
  protected EntityStorageInterface $termStorage;

  /**
   * The node storage.
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * The ID of the "Salsa am Sund" organizer term.
   */
  protected int $salsaAmSund;

  /**
   * The ID of the "Sonstige" organizer term.
   */
  protected int $externalOrganizer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('smart_date_rule');
    $this->installEntitySchema('smart_date_override');
    $this->installEntitySchema('flagging');
    $this->installSchema('flag', ['flag_counts']);
    $this->installConfig(['node', 'taxonomy', 'filter', 'smart_date', 'smart_date_recur']);

    $this->createContentModel();
    $this->setUpCurrentUser([], ['access content']);
    $this->termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $this->nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');

    $salsa = reset($this->termStorage->loadByProperties(['uuid' => OrganizerResolver::SALSA_AM_SUND_UUID]));
    $external = reset($this->termStorage->loadByProperties(['uuid' => OrganizerResolver::EXTERNAL_UUID]));
    self::assertInstanceOf(TermInterface::class, $salsa);
    self::assertInstanceOf(TermInterface::class, $external);
    $this->salsaAmSund = (int) $salsa->id();
    $this->externalOrganizer = (int) $external->id();
  }

  /**
   * Creates the event content model.
   */
  protected function createContentModel(): void {
    Vocabulary::create([
      'vid' => 'location',
      'name' => 'Orte',
    ])->save();
    Vocabulary::create([
      'vid' => OrganizerResolver::VOCABULARY,
      'name' => 'Veranstalter',
      'description' => 'Wer eine Veranstaltung ausrichtet',
    ])->save();
    foreach (OrganizerResolver::TERMS as $uuid => $values) {
      Term::create([
        'uuid' => $uuid,
        'vid' => OrganizerResolver::VOCABULARY,
        'name' => $values['name'],
        'weight' => $values['weight'],
      ])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'taxonomy_term',
      'type' => 'string_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'location',
      'label' => 'Adresse',
      'field_storage' => FieldStorageConfig::loadByName('taxonomy_term', 'field_address'),
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_map_link',
      'entity_type' => 'taxonomy_term',
      'type' => 'link',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_map_link',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'location',
      'label' => 'Kartenlink',
      'field_storage' => FieldStorageConfig::loadByName('taxonomy_term', 'field_map_link'),
      'settings' => ['title' => 1, 'link_type' => 17],
    ])->save();

    NodeType::create([
      'type' => 'event',
      'name' => 'Termin',
      'new_revision' => TRUE,
      'display_submitted' => FALSE,
    ])->save();

    NodeType::create([
      'type' => 'course',
      'name' => 'Kurs',
      'description' => 'Wöchentliche Tanzkurse',
      'new_revision' => TRUE,
      'display_submitted' => FALSE,
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'node',
      'type' => 'smartdate',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    ])->save();
    foreach (['event' => 'Datum & Uhrzeit', 'course' => 'Wann'] as $bundle => $label) {
      $when = $this->createFieldConfig('field_when', $bundle, $label, [], TRUE);
      $when->setThirdPartySetting('smart_date_recur', 'allow_recurring', TRUE);
      $when->setThirdPartySetting('smart_date_recur', 'month_limit', 12);
      $when->save();
    }

    FieldStorageConfig::create([
      'field_name' => 'field_organizer',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    $this->createFieldConfig('field_organizer', 'event', 'Veranstalter', [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => [OrganizerResolver::VOCABULARY => OrganizerResolver::VOCABULARY],
        'sort' => ['field' => 'weight', 'direction' => 'ASC'],
        'auto_create' => FALSE,
      ],
    ], TRUE);

    FieldStorageConfig::create([
      'field_name' => 'field_location',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    $location_settings = [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => ['location' => 'location'],
        'sort' => ['field' => 'name', 'direction' => 'ASC'],
        'auto_create' => FALSE,
      ],
    ];
    foreach (['event', 'course'] as $bundle) {
      $this->createFieldConfig('field_location', $bundle, 'Ort', $location_settings);
    }

    FieldStorageConfig::create([
      'field_name' => 'field_difficulty',
      'entity_type' => 'node',
      'type' => 'list_integer',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'settings' => [
        'allowed_values' => [],
        'allowed_values_function' => Difficulty::class . '::allowedValues',
      ],
    ])->save();
    $this->createFieldConfig('field_difficulty', 'course', 'Level');

    FieldStorageConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'node',
      'type' => 'link',
      'cardinality' => 1,
    ])->save();
    $link_settings = ['title' => 1, 'link_type' => 17];
    foreach (['event' => 'Weitere Infos (Link)', 'course' => 'Link'] as $bundle => $label) {
      $this->createFieldConfig('field_link', $bundle, $label, $link_settings);
    }

    if (FieldStorageConfig::loadByName('node', 'body') === NULL) {
      FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
        'cardinality' => 1,
      ])->save();
    }
    foreach (['event', 'course'] as $bundle) {
      $this->createFieldConfig('body', $bundle, 'Beschreibung', [
        'display_summary' => TRUE,
        'allowed_formats' => [],
      ]);
    }
  }

  /**
   * Creates a field instance for a node bundle.
   *
   * @param string $field_name
   *   The field name.
   * @param string $bundle
   *   The node bundle machine name.
   * @param string $label
   *   The field label.
   * @param array<string, mixed> $settings
   *   The field settings.
   * @param bool $required
   *   Whether the field is required.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The field config.
   */
  protected function createFieldConfig(string $field_name, string $bundle, string $label, array $settings = [], bool $required = FALSE): FieldConfig {
    $field = FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $label,
      'required' => $required,
      'field_storage' => FieldStorageConfig::loadByName('node', $field_name),
      'settings' => $settings,
    ]);
    $field->save();
    return $field;
  }

  /**
   * Creates a location term.
   *
   * @param string $name
   *   The term name.
   * @param string $address
   *   The address.
   * @param string $mapLink
   *   The map link.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The term.
   */
  protected function createLocation(string $name, string $address = '', string $mapLink = ''): TermInterface {
    $term = Term::create([
      'vid' => 'location',
      'name' => $name,
      'field_address' => $address,
      'field_map_link' => $mapLink === '' ? NULL : ['uri' => $mapLink, 'title' => 'Karte'],
    ]);
    $term->save();
    return $term;
  }

  /**
   * Creates a date value in the test timezone.
   *
   * @param string $modifier
   *   A date string, optionally relative to now.
   * @param string $time
   *   The time of day.
   *
   * @return \DateTimeImmutable
   *   The date.
   */
  protected function date(string $modifier, string $time = '00:00'): \DateTimeImmutable {
    return new \DateTimeImmutable($modifier . ' ' . $time, new \DateTimeZone(self::TIMEZONE));
  }

  /**
   * Creates a single date delta for a smart date field.
   *
   * @param \DateTimeImmutable $start
   *   The start.
   * @param \DateTimeImmutable|null $end
   *   The end, or NULL for an event without a known end.
   *
   * @return array<string, mixed>
   *   The field delta.
   */
  protected function dateDelta(\DateTimeImmutable $start, ?\DateTimeImmutable $end): array {
    $end ??= $start;
    return [
      'value' => $start->getTimestamp(),
      'end_value' => $end->getTimestamp(),
      'duration' => (int) (($end->getTimestamp() - $start->getTimestamp()) / 60),
      'timezone' => self::TIMEZONE,
    ];
  }

  /**
   * Creates an all-day date delta.
   *
   * @param \DateTimeImmutable $day
   *   The first day.
   * @param \DateTimeImmutable|null $lastDay
   *   The last day of a multi-day range, or NULL for a single day.
   *
   * @return array<string, mixed>
   *   The field delta.
   */
  protected function allDayDelta(\DateTimeImmutable $day, ?\DateTimeImmutable $lastDay = NULL): array {
    $start = $day->setTime(0, 0);
    $end = ($lastDay ?? $day)->setTime(23, 59);
    return [
      'value' => $start->getTimestamp(),
      'end_value' => $end->getTimestamp(),
      'duration' => (int) (($end->getTimestamp() - $start->getTimestamp()) / 60),
      'timezone' => self::TIMEZONE,
    ];
  }

  /**
   * Creates a recurrence rule and returns its materialized date deltas.
   *
   * @param \DateTimeImmutable $start
   *   The first occurrence start.
   * @param \DateTimeImmutable $end
   *   The first occurrence end.
   * @param string $frequency
   *   The recurrence frequency.
   * @param string $parameters
   *   The recurrence parameters.
   * @param string $limit
   *   The recurrence limit, for example COUNT=4. Empty for unlimited.
   * @param string $bundle
   *   The node bundle the rule belongs to.
   *
   * @return array<int, array<string, mixed>>
   *   The date deltas.
   */
  protected function recurringDeltas(\DateTimeImmutable $start, \DateTimeImmutable $end, string $frequency, string $parameters, string $limit = '', string $bundle = 'event'): array {
    $rule = SmartDateRule::create([
      'freq' => $frequency,
      'limit' => $limit,
      'unlimited' => $limit === '',
      'entity_type' => 'node',
      'bundle' => $bundle,
      'field_name' => 'field_when',
      'start' => $start->getTimestamp(),
      'end' => $end->getTimestamp(),
      'parameters' => $parameters,
    ]);
    $rule->setTimezone(self::TIMEZONE);
    $instances = $rule->getRuleInstances(strtotime('+12 months'));
    self::assertIsArray($instances);
    $rule->set('instances', ['data' => $instances]);
    $rule->save();

    $deltas = [];
    foreach ($rule->getStoredInstances() as $index => $instance) {
      $deltas[] = [
        'value' => $instance['value'],
        'end_value' => $instance['end_value'],
        'duration' => (int) (($instance['end_value'] - $instance['value']) / 60),
        'timezone' => self::TIMEZONE,
        'rrule' => $rule->id(),
        'rrule_index' => $index,
      ];
    }
    return $deltas;
  }

  /**
   * Creates an event node.
   *
   * @param array<string, mixed> $values
   *   The node values.
   *
   * @return \Drupal\node\NodeInterface
   *   The event.
   */
  protected function createEvent(array $values): NodeInterface {
    $values += [
      'type' => 'event',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
      'field_organizer' => $this->salsaAmSund,
    ];
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Creates a course node.
   *
   * @param array<string, mixed> $values
   *   The node values.
   *
   * @return \Drupal\node\NodeInterface
   *   The course.
   */
  protected function createCourse(array $values): NodeInterface {
    $values += [
      'type' => 'course',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
    ];
    $node = Node::create($values);
    $node->save();
    return $node;
  }

}
