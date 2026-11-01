<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\workspace_events\ConfigRecordInstaller;
use Drupal\workspace_events\ConfigRecordLoader;
use Drupal\workspace_events\Difficulty;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\SeasonInterface;
use Drupal\workspace_events\LessonInstaller;
use Drupal\workspace_events\OrganizerResolver;
use Drupal\workspace_events\SeasonRepository;

/**
 * Base class for workspace_events kernel tests.
 *
 * Builds the event content model programmatically, so the tests only cover
 * this module's logic and not the site's configuration. Courses carry their
 * schedule in a season, and the season owns the generated lessons; events
 * carry one date range.
 */
abstract class EventKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The timezone used by the test date values.
   */
  protected const string TIMEZONE = 'Europe/Berlin';

  /**
   * The modules every event test of this module needs.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'options',
    'datetime',
    'datetime_range',
    'flag',
    'link',
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
   * The lesson storage.
   */
  protected EntityStorageInterface $lessonStorage;

  /**
   * The course season storage.
   */
  protected EntityStorageInterface $seasonStorage;

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
    $this->installEntitySchema('course_season');
    $this->installEntitySchema('lesson');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('flagging');
    $this->installSchema('flag', ['flag_counts']);
    $this->installEntitySchema('smart_date_rule');
    $this->installEntitySchema('smart_date_override');
    $this->installConfig(['system', 'node', 'taxonomy', 'filter', 'smart_date']);
    $this->config('system.date')
      ->set('timezone.default', self::TIMEZONE)
      ->save();
    date_default_timezone_set(self::TIMEZONE);

    $this->createContentModel();
    $this->setUpCurrentUser([], ['access content']);
    $this->termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $this->nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->lessonStorage = $this->container->get('entity_type.manager')->getStorage('lesson');
    $this->seasonStorage = $this->container->get('entity_type.manager')->getStorage('course_season');

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
    // The tests build their own render arrays, so they need the bundle and its
    // fields but not the shipped form and view displays.
    $installer->install(array_values(array_filter(
      LessonInstaller::CONFIG_NAMES,
      static fn (string $name): bool => !str_starts_with($name, 'core.entity_'),
    )));

    // Creating the fields can rebuild the container. Refresh the storage so
    // the tests use the field definitions of the new configuration.
    $this->nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->lessonStorage = $this->container->get('entity_type.manager')->getStorage('lesson');
    $this->seasonStorage = $this->container->get('entity_type.manager')->getStorage('course_season');
  }

  /**
   * Installs configuration entities from the module's configuration records.
   *
   * @param string[] $names
   *   The configuration names, in creation order.
   */
  protected function installConfigRecords(array $names): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $loader = $this->container->get(ConfigRecordLoader::class);
    \assert($loader instanceof ConfigRecordLoader);

    // ConfigRecordInstaller is abstract because its subclasses name their
    // configuration list; the helper passes the names to install() instead.
    $installer = new class($entity_type_manager, $loader) extends ConfigRecordInstaller {};
    $installer->install($names);
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
    $this->createFieldConfig('field_location', 'event', 'Ort', $location_settings);

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
    $this->createFieldConfig('field_link', 'event', 'Weitere Infos (Link)', $link_settings);

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
   * Loads the lessons of all seasons of a course in date order.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return \Drupal\workspace_events\Entity\LessonInterface[]
   *   The lessons, in date order.
   */
  protected function loadCourseLessons(NodeInterface $course): array {
    $ids = $this->lessonStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('season.entity:course_season.course', $course->id())
      ->sort('field_lesson_date.value')
      ->execute();
    $this->lessonStorage->resetCache($ids);

    $lessons = [];
    foreach ($this->lessonStorage->loadMultiple($ids) as $lesson) {
      if ($lesson instanceof LessonInterface) {
        $lessons[] = $lesson;
      }
    }

    return $lessons;
  }

  /**
   * Loads the only season of a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return \Drupal\workspace_events\Entity\SeasonInterface
   *   The season.
   */
  protected function loadSeason(NodeInterface $course): SeasonInterface {
    $this->seasonStorage->resetCache();
    $ids = $this->seasonStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('course', $course->id())
      ->execute();
    $season = $this->seasonStorage->load((int) reset($ids));
    self::assertInstanceOf(SeasonInterface::class, $season);
    return $season;
  }

  /**
   * Loads the lessons of one season in date order.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return \Drupal\workspace_events\Entity\LessonInterface[]
   *   The lessons, in date order.
   */
  protected function loadSeasonLessons(SeasonInterface $season): array {
    $repository = $this->container->get(SeasonRepository::class);
    \assert($repository instanceof SeasonRepository);

    return $repository->loadLessons($season);
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
   * Builds a Smart Date field value from timestamps.
   *
   * @param \DateTimeImmutable $start
   *   The start.
   * @param \DateTimeImmutable|null $end
   *   The end, or NULL for the start.
   *
   * @return array<string, mixed>
   *   The field value.
   */
  protected function smartDateRange(\DateTimeImmutable $start, ?\DateTimeImmutable $end = NULL): array {
    $start_timestamp = $start->getTimestamp();
    $end_timestamp = ($end ?? $start)->getTimestamp();

    return [
      'value' => $start_timestamp,
      'end_value' => $end_timestamp,
      'duration' => (int) round(($end_timestamp - $start_timestamp) / 60),
      'timezone' => self::TIMEZONE,
    ];
  }

  /**
   * Builds an all-day Smart Date field value.
   *
   * @param \DateTimeImmutable $day
   *   The first day.
   * @param \DateTimeImmutable|null $lastDay
   *   The last day of a multi-day range, or NULL for a single day.
   *
   * @return array<string, mixed>
   *   The field value.
   */
  protected function allDaySmartDateRange(\DateTimeImmutable $day, ?\DateTimeImmutable $lastDay = NULL): array {
    return $this->smartDateRange(
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
   * Creates a course node and its season, if a schedule is given.
   *
   * The season values are passed with the "season_" prefix, so a test can
   * create a course with its first season in one call.
   *
   * @param array<string, mixed> $values
   *   The node values.
   *
   * @return \Drupal\node\NodeInterface
   *   The course.
   */
  protected function createCourse(array $values): NodeInterface {
    $season_values = [];
    if (isset($values['season_schedule'])) {
      $season_values['field_schedule'] = $values['season_schedule'];
    }
    if (isset($values['season_last'])) {
      $season_values['last'] = $values['season_last'];
    }
    if (isset($values['season_interval'])) {
      $season_values['interval'] = $values['season_interval'];
    }
    if (isset($values['season_location'])) {
      $season_values['location'] = $values['season_location'];
    }
    unset(
      $values['season_schedule'],
      $values['season_last'],
      $values['season_interval'],
      $values['season_location'],
    );

    $values += [
      'type' => 'course',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
    ];
    $node = Node::create($values);
    $node->save();

    if (isset($season_values['field_schedule'])) {
      $this->createSeason($node, $season_values);
    }

    return $node;
  }

  /**
   * Creates a course season and generates its lessons.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   * @param array<string, mixed> $values
   *   The season values, for example schedule, last, interval and location.
   *
   * @return \Drupal\workspace_events\Entity\SeasonInterface
   *   The season.
   */
  protected function createSeason(NodeInterface $course, array $values = []): SeasonInterface {
    $values += [
      'course' => $course->id(),
      'uid' => $course->getOwnerId(),
      'interval' => 1,
    ];
    $season = $this->seasonStorage->create($values);
    \assert($season instanceof SeasonInterface);
    $season->save();
    return $season;
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
      'season_schedule' => $this->dateRange($first, $end ?? $first->modify('+1 hour')),
      'season_last' => ['value' => $last->format('Y-m-d')],
      'season_interval' => $interval,
    ];
  }

}
