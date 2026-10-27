<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Routing\RouteMatch;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\options\Plugin\views\filter\ListField;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\views\Plugin\views\filter\EntityReference;
use Drupal\views\Views;
use Drupal\views\ViewsData;
use Drupal\views\ViewExecutable;
use Drupal\workspace_events\Access\MyCoursesAccess;
use Drupal\workspace_events\DanceFigureFlag;
use Drupal\workspace_events\DanceFigureInstaller;
use Drupal\workspace_events\Difficulty;
use Drupal\workspace_events\Hook\DanceFigureAccessHooks;
use Drupal\workspace_events\Hook\LessonAccessHooks;
use Drupal\workspace_events\Hook\ViewsDataHooks;
use Drupal\workspace_events\LessonInstaller;
use Drupal\workspace_events\MemberOverviewInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests the member overview views and the access to "Meine Kurse".
 */
#[CoversClass(MemberOverviewInstaller::class)]
#[CoversClass(MyCoursesAccess::class)]
#[CoversClass(ViewsDataHooks::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class MemberOverviewViewsTest extends LessonKernelTestBase {

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The flag service.
   */
  private FlagServiceInterface $flagService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $entity_type_manager = $this->container->get('entity_type.manager');
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    $this->entityTypeManager = $entity_type_manager;

    $extension_path_resolver = $this->container->get(ExtensionPathResolver::class);
    \assert($extension_path_resolver instanceof ExtensionPathResolver);

    // The video thumbnail of the dance figure view joins the media table.
    $this->installEntitySchema('media');

    // The course select of the exposed filter only lists nodes the current
    // user may view. Kernel tests have no node access records, so grant view
    // access to every node.
    $this->container->get('database')->insert('node_access')
      ->fields([
        'nid' => 0,
        'langcode' => '',
        'gid' => 0,
        'realm' => 'all',
        'grant_view' => 1,
        'grant_update' => 0,
        'grant_delete' => 0,
      ])
      ->execute();

    Vocabulary::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Tanzfiguren',
    ])->save();
    $this->installDanceFigureFields();

    $flag_installer = $this->container->get(DanceFigureInstaller::class);
    \assert($flag_installer instanceof DanceFigureInstaller);
    $flag_installer->install(array_map(
      static fn (DanceFigureFlag $flag): string => $flag->configName(),
      DanceFigureFlag::cases(),
    ));

    $flag_service = $this->container->get('flag');
    \assert($flag_service instanceof FlagServiceInterface);
    $this->flagService = $flag_service;

    $lesson_installer = $this->container->get(LessonInstaller::class);
    \assert($lesson_installer instanceof LessonInstaller);
    $lesson_installer->install();

    $overview_installer = $this->container->get(MemberOverviewInstaller::class);
    \assert($overview_installer instanceof MemberOverviewInstaller);
    $overview_installer->install();

    $views_data = $this->container->get('views.views_data');
    \assert($views_data instanceof ViewsData);
    $views_data->clear();
  }

  /**
   * Tests the access rules of the "Meine Kurse" tab.
   */
  public function testMyCoursesAccess(): void {
    $member = $this->createMember();
    $other_member = $this->createMember();
    $editor = $this->createUser([LessonAccessHooks::EDIT_PERMISSION]);
    $access = new MyCoursesAccess();

    $own = $access->access($member, $this->userRouteMatch($member->id()));
    self::assertTrue($own->isAllowed());
    self::assertContains('user', $own->getCacheContexts());
    self::assertContains('user.permissions', $own->getCacheContexts());

    self::assertTrue($access->access($other_member, $this->userRouteMatch($member->id()))->isForbidden());
    self::assertTrue($access->access($editor, $this->userRouteMatch($member->id()))->isAllowed());

    $member_without_permission = $this->createUser(['access content']);
    self::assertTrue($access->access($member_without_permission, $this->userRouteMatch($member_without_permission->id()))->isForbidden());

    $anonymous = User::getAnonymousUser();
    self::assertTrue($access->access($anonymous, $this->userRouteMatch($member->id()))->isForbidden());
    self::assertTrue($access->access($member, $this->userRouteMatch($anonymous->id()))->isForbidden());
  }

  /**
   * Tests that "Meine Kurse" only lists the lessons of the route user.
   */
  public function testMyCoursesListsOnlyTheRouteUsersLessons(): void {
    $member = $this->createMember();
    $other_member = $this->createMember();
    $this->setCurrentUser($member);

    $course = $this->createCourse(['title' => 'Salsa Beginner']);
    $other_course = $this->createCourse(['title' => 'Salsa Advanced']);
    $figure = $this->createFigure('Dile que no', 1);
    $other_figure = $this->createFigure('Enchufla', 2);

    $first = $this->createLessonFor($course, $this->date('2026-10-01', '18:00'), $member, [$figure]);
    $second = $this->createLessonFor($course, $this->date('2026-10-08', '18:00'), $member, [$other_figure]);
    $foreign = $this->createLessonFor($other_course, $this->date('2026-10-09', '18:00'), $other_member, [$figure]);

    $view = $this->executeView('my_courses', [], [$member->id()]);

    self::assertCount(2, $view->result);
    $lesson_ids = array_map(
      static fn (object $row): int => (int) $row->nid,
      $view->result,
    );
    sort($lesson_ids);
    $expected = [(int) $first->id(), (int) $second->id()];
    sort($expected);
    self::assertSame($expected, $lesson_ids);
    self::assertNotContains((int) $foreign->id(), $lesson_ids);

    // The page must not expose participants, the photo or the course id.
    self::assertArrayHasKey('title', $view->field);
    self::assertArrayHasKey('field_lesson_date', $view->field);
    self::assertArrayHasKey('field_dance_figures', $view->field);
    self::assertArrayNotHasKey('field_participants', $view->field);
    self::assertArrayNotHasKey('field_attendance_photo', $view->field);
    self::assertTrue($view->field['course']->options['exclude']);

    // The course groups the rows and the date is sorted descending.
    $grouping = $view->style_plugin->options['grouping'] ?? [];
    self::assertSame('course', $grouping[0]['field'] ?? NULL);
    self::assertSame('DESC', $view->sort['field_lesson_date_value']->options['order']);
  }

  /**
   * Tests that the course filter returns every figure only once.
   */
  public function testDanceFiguresCourseFilterReturnsEachFigureOnce(): void {
    $member = $this->createMember();
    $this->setCurrentUser($member);

    $course = $this->createCourse(['title' => 'Salsa Beginner']);
    $other_course = $this->createCourse(['title' => 'Salsa Advanced']);
    $first = $this->createFigure('Dile que no', 3);
    $second = $this->createFigure('Enchufla', 1);
    $third = $this->createFigure('Setenta', 2);
    $foreign = $this->createFigure('Rueda', 0);

    $this->createLessonFor($course, $this->date('2026-10-01', '18:00'), $member, [$first, $second]);
    $this->createLessonFor($course, $this->date('2026-10-08', '18:00'), $member, [$second, $third]);
    $this->createLessonFor($other_course, $this->date('2026-10-09', '18:00'), $member, [$second, $foreign]);

    $view = $this->executeView('dance_figures', ['course' => [$course->id()]]);

    self::assertInstanceOf(EntityReference::class, $view->filter['course']);
    self::assertSame(['Enchufla', 'Setenta', 'Dile que no'], $this->resultNames($view, FALSE));
  }

  /**
   * Tests that the difficulty filter selects the requested level.
   */
  public function testDanceFiguresDifficultyFilter(): void {
    $member = $this->createMember();
    $this->setCurrentUser($member);

    $this->createFigure('Dile que no', 1);
    $this->createFigure('Enchufla', 2);
    $this->createFigure('Setenta', 3);

    $view = $this->executeView('dance_figures', ['field_difficulty' => ['2']]);

    self::assertInstanceOf(ListField::class, $view->filter['field_difficulty']);
    self::assertSame(['Enchufla'], $this->resultNames($view));
  }

  /**
   * Tests that the flag filters only show the current user's flags.
   */
  public function testFlagFiltersUseTheCurrentUser(): void {
    $first_member = $this->createMember();
    $second_member = $this->createMember();
    $first = $this->createFigure('Dile que no', 1);
    $second = $this->createFigure('Enchufla', 2);

    foreach (DanceFigureFlag::cases() as $flag) {
      $flag_entity = $this->getFlag($flag);

      $this->setCurrentUser($first_member);
      $this->flagService->flag($flag_entity, $first);
      $this->setCurrentUser($second_member);
      $this->flagService->flag($flag_entity, $second);

      $this->setCurrentUser($first_member);
      self::assertSame(['Dile que no'], $this->resultNames($this->executeView('dance_figures', [$flag->value => '1'])));
      self::assertSame(['Enchufla'], $this->resultNames($this->executeView('dance_figures', [$flag->value => '0'])));
      self::assertSame(['Dile que no', 'Enchufla'], $this->resultNames($this->executeView('dance_figures', [$flag->value => 'All'])));

      $this->setCurrentUser($second_member);
      self::assertSame(['Enchufla'], $this->resultNames($this->executeView('dance_figures', [$flag->value => '1'])));
    }
  }

  /**
   * Tests that the dance figure view varies by user.
   */
  public function testDanceFiguresAddTheUserCacheContext(): void {
    $member = $this->createMember();
    $this->setCurrentUser($member);
    $this->createFigure('Dile que no', 1);

    $contexts = $this->executeView('dance_figures')->display_handler->getCacheMetadata()->getCacheContexts();
    self::assertContains('user', $contexts);
    self::assertContains('user.permissions', $contexts);
  }

  /**
   * Returns both dance figure flags.
   *
   * @param \Drupal\workspace_events\DanceFigureFlag $flag
   *   The dance figure flag.
   *
   * @return \Drupal\flag\FlagInterface
   *   The flag entity.
   */
  private function getFlag(DanceFigureFlag $flag): FlagInterface {
    $flag_entity = $this->flagService->getFlagById($flag->value);
    self::assertInstanceOf(FlagInterface::class, $flag_entity);
    return $flag_entity;
  }

  /**
   * Creates a member with the member permissions.
   *
   * @return \Drupal\user\UserInterface
   *   The member.
   */
  private function createMember(): UserInterface {
    $permissions = [
      'access content',
      DanceFigureAccessHooks::VIEW_PERMISSION,
    ];
    foreach (DanceFigureFlag::cases() as $flag) {
      $permissions = array_merge($permissions, $flag->permissions());
    }
    return $this->createUser($permissions);
  }

  /**
   * Creates a dance figure term.
   *
   * @param string $name
   *   The term name.
   * @param int $difficulty
   *   The difficulty value.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The dance figure.
   */
  private function createFigure(string $name, int $difficulty): TermInterface {
    $term = Term::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => $name,
      'status' => 1,
      'field_difficulty' => $difficulty,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Creates a lesson with participants and dance figures.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   * @param \DateTimeImmutable $start
   *   The start of the lesson.
   * @param \Drupal\user\UserInterface $participant
   *   The participant.
   * @param \Drupal\taxonomy\TermInterface[] $figures
   *   The dance figures.
   *
   * @return \Drupal\node\NodeInterface
   *   The lesson.
   */
  private function createLessonFor(NodeInterface $course, \DateTimeImmutable $start, UserInterface $participant, array $figures): NodeInterface {
    $lesson = $this->createLesson([
      'title' => $course->label() . ' – ' . $start->format('d.m.Y'),
      'field_lesson_date' => [
        'value' => $start->format('Y-m-d\TH:i:s'),
        'end_value' => $start->modify('+1 hour')->format('Y-m-d\TH:i:s'),
      ],
      'field_participants' => [['target_id' => $participant->id()]],
      'field_dance_figures' => array_map(
        static fn (TermInterface $term): int => (int) $term->id(),
        $figures,
      ),
    ]);

    $course_lessons = [];
    foreach ($course->get('field_lessons') as $item) {
      $course_lessons[] = (int) $item->get('target_id')->getString();
    }
    $course_lessons[] = (int) $lesson->id();
    $course->set('field_lessons', $course_lessons);
    $course->save();

    return $lesson;
  }

  /**
   * Executes a view with optional exposed input and arguments.
   *
   * @param string $view_id
   *   The view ID.
   * @param array<string, mixed> $exposed_input
   *   The exposed input.
   * @param array<int|string> $arguments
   *   The contextual arguments.
   *
   * @return \Drupal\views\ViewExecutable
   *   The executed view.
   */
  private function executeView(string $view_id, array $exposed_input = [], array $arguments = []): ViewExecutable {
    $view = Views::getView($view_id);
    self::assertInstanceOf(ViewExecutable::class, $view);
    $view->setDisplay('default');
    $view->preExecute($arguments);
    if ($exposed_input !== []) {
      $view->setExposedInput($exposed_input);
    }
    $view->execute();
    return $view;
  }

  /**
   * Returns the dance figure names of a view result.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The executed view.
   * @param bool $sort
   *   Whether to sort the names.
   *
   * @return string[]
   *   The names.
   */
  private function resultNames(ViewExecutable $view, bool $sort = TRUE): array {
    $names = [];
    foreach ($view->result as $row) {
      $names[] = (string) $view->field['name']->getValue($row);
    }
    if ($sort) {
      sort($names);
    }
    return $names;
  }

  /**
   * Builds a route match for the "Meine Kurse" tab of a user.
   *
   * @param int|string $user_id
   *   The user ID in the route.
   *
   * @return \Drupal\Core\Routing\RouteMatch
   *   The route match.
   */
  private function userRouteMatch(int|string $user_id): RouteMatch {
    $route = new Route('/user/{user}/kurse');
    return new RouteMatch(
      'view.my_courses.page_1',
      $route,
      ['user' => (string) $user_id],
      ['user' => (string) $user_id],
    );
  }

  /**
   * Installs the dance figure fields used by the view.
   */
  private function installDanceFigureFields(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_difficulty',
      'entity_type' => 'taxonomy_term',
      'type' => 'list_integer',
      'cardinality' => 1,
      'settings' => [
        'allowed_values' => [],
        'allowed_values_function' => Difficulty::class . '::allowedValues',
      ],
      'module' => 'options',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_difficulty',
      'entity_type' => 'taxonomy_term',
      'bundle' => DanceFigureAccessHooks::VOCABULARY,
      'label' => 'Level',
      'required' => TRUE,
      'field_storage' => FieldStorageConfig::loadByName('taxonomy_term', 'field_difficulty'),
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_video',
      'entity_type' => 'taxonomy_term',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_video',
      'entity_type' => 'taxonomy_term',
      'bundle' => DanceFigureAccessHooks::VOCABULARY,
      'label' => 'Video',
      'required' => FALSE,
      'field_storage' => FieldStorageConfig::loadByName('taxonomy_term', 'field_video'),
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => [],
          'sort' => ['field' => '_none', 'direction' => 'ASC'],
          'auto_create' => FALSE,
        ],
      ],
    ])->save();
  }

}
