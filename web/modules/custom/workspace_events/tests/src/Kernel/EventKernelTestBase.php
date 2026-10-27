<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\workspace_events\Difficulty;
use Drupal\workspace_events\LessonInstaller;
use Drupal\workspace_events\OrganizerResolver;

/**
 * Base class for workspace_events kernel tests.
 *
 * Builds the event content model programmatically, so the tests only cover
 * this module's logic and not the site's configuration. Courses carry a
 * schedule and reference their generated lessons; events carry one date range.
 */
abstract class EventKernelTestBase extends CanvasKernelTestBase {

  use UserCreationTrait;

  /**
   * The timezone used by the test date values.
   */
  protected const string TIMEZONE = 'Europe/Berlin';

  /**
   * The modules every test of this module needs.
   */
  public const array MODULES = [
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
    'key',
    'ai',
    'ai_automators',
    'ai_provider_mistral',
    'field_widget_actions',
    'datetime_range',
    'image',
    'scheduler',
    'workspace_events',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = self::MODULES;

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
    $this->installEntitySchema('flagging');
    $this->installSchema('flag', ['flag_counts']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['node', 'taxonomy', 'filter', 'scheduler']);
    if (\Drupal::moduleHandler()->moduleExists('smart_date')) {
      // Only the legacy schedule migration test needs the removed modules.
      $this->installEntitySchema('smart_date_rule');
      $this->installEntitySchema('smart_date_override');
      $this->installConfig(['smart_date', 'smart_date_recur']);
    }
    $this->config('system.date')
      ->set('timezone.default', self::TIMEZONE)
      ->save();
    date_default_timezone_set(self::TIMEZONE);

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
   * Installs the lesson bundle, its fields and the course and event fields.
   */
  protected function installLessonConfig(): void {
    $installer = $this->container->get(LessonInstaller::class);
    \assert($installer instanceof LessonInstaller);
    $installer->install();

    // Creating the fields can rebuild the container. Refresh the storage so
    // the tests use the field definitions of the new configuration.
    $this->nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
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
   * Loads the referenced lessons of a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The lessons, in the stored order.
   */
  protected function loadCourseLessons(NodeInterface $course): array {
    $ids = [];
    foreach ($course->get('field_lessons') as $item) {
      $ids[] = (int) $item->get('target_id')->getString();
    }
    $this->nodeStorage->resetCache($ids);

    $lessons = [];
    foreach ($ids as $id) {
      $lesson = $this->nodeStorage->load($id);
      self::assertInstanceOf(NodeInterface::class, $lesson);
      $lessons[] = $lesson;
    }
    return $lessons;
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
   * Formats a date for a date range field, which stores dates in UTC.
   *
   * @param \DateTimeImmutable $date
   *   The date in the test timezone.
   *
   * @return string
   *   The stored value.
   */
  protected function storageDate(\DateTimeImmutable $date): string {
    return $date
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('Y-m-d\TH:i:s');
  }

  /**
   * Builds a date range field value.
   *
   * @param \DateTimeImmutable $start
   *   The start.
   * @param \DateTimeImmutable|null $end
   *   The end, or NULL for the start.
   *
   * @return array<string, string>
   *   The field value.
   */
  protected function dateRange(\DateTimeImmutable $start, ?\DateTimeImmutable $end = NULL): array {
    return [
      'value' => $this->storageDate($start),
      'end_value' => $this->storageDate($end ?? $start),
    ];
  }

  /**
   * Builds an all-day date range field value.
   *
   * @param \DateTimeImmutable $day
   *   The first day.
   * @param \DateTimeImmutable|null $lastDay
   *   The last day of a multi-day range, or NULL for a single day.
   *
   * @return array<string, string>
   *   The field value.
   */
  protected function allDayRange(\DateTimeImmutable $day, ?\DateTimeImmutable $lastDay = NULL): array {
    return $this->dateRange(
      $day->setTime(0, 0),
      ($lastDay ?? $day)->setTime(23, 59),
    );
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
   * Creates a course node and generates its lessons.
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

  /**
   * Creates a course schedule with weekly lessons.
   *
   * @param \DateTimeImmutable $first
   *   The start of the first lesson.
   * @param \DateTimeImmutable $last
   *   The date of the last lesson.
   * @param int $interval
   *   The interval in weeks.
   * @param \DateTimeImmutable|null $end
   *   The end of the first lesson, or NULL to use one hour after the start.
   *
   * @return array<string, mixed>
   *   The node values.
   */
  protected function courseSchedule(\DateTimeImmutable $first, \DateTimeImmutable $last, int $interval = 1, ?\DateTimeImmutable $end = NULL): array {
    return [
      'field_course_first' => $this->dateRange($first, $end ?? $first->modify('+1 hour')),
      'field_course_last' => ['value' => $last->format('Y-m-d')],
      'field_course_interval' => ['value' => $interval],
    ];
  }

}
