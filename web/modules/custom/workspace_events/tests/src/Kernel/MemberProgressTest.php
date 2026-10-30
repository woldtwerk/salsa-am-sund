<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_events\DanceFigureFlag;
use Drupal\workspace_events\DanceFigureInstaller;
use Drupal\workspace_events\Hook\DanceFigureAccessHooks;
use Drupal\workspace_events\Hook\LessonAccessHooks;
use Drupal\workspace_events\Hook\MemberProfileHooks;
use Drupal\workspace_events\MemberProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the member progress and its profile sections.
 */
#[CoversClass(MemberProgress::class)]
#[CoversClass(MemberProfileHooks::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class MemberProgressTest extends LessonKernelTestBase {

  /**
   * The modules the member progress needs.
   *
   * @var string[]
   */
  protected static $modules = ['flag'];

  /**
   * The member progress service.
   */
  private MemberProgress $memberProgress;

  /**
   * The flag service.
   */
  private FlagServiceInterface $flagService;

  /**
   * The learned flag.
   */
  private FlagInterface $learnedFlag;

  /**
   * The like flag.
   */
  private FlagInterface $likeFlag;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    Vocabulary::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Tanzfiguren',
    ])->save();
    $installer = $this->container->get(DanceFigureInstaller::class);
    \assert($installer instanceof DanceFigureInstaller);
    $installer->install(array_map(
      static fn (DanceFigureFlag $flag): string => $flag->configName(),
      DanceFigureFlag::cases(),
    ));

    $flag_service = $this->container->get('flag');
    \assert($flag_service instanceof FlagServiceInterface);
    $this->flagService = $flag_service;
    $learned = $this->flagService->getFlagById(DanceFigureFlag::Learned->value);
    \assert($learned instanceof FlagInterface);
    $this->learnedFlag = $learned;
    $like = $this->flagService->getFlagById(DanceFigureFlag::Like->value);
    \assert($like instanceof FlagInterface);
    $this->likeFlag = $like;

    $progress = $this->container->get(MemberProgress::class);
    \assert($progress instanceof MemberProgress);
    $this->memberProgress = $progress;
  }

  /**
   * Tests the visited courses and the flagged figures of a member.
   */
  public function testProgress(): void {
    $member = $this->createUser(['access content']);
    $other_member = $this->createUser(['access content']);

    $this->createCourseWithLessons('Zouk', [
      $this->createParticipantLesson($member, 'Zouk 1'),
      $this->createParticipantLesson($member, 'Zouk 2'),
    ]);
    $salsa = $this->createCourseWithLessons('Salsa', [
      $this->createParticipantLesson($member, 'Salsa 1'),
      $this->createParticipantLesson($other_member, 'Salsa 2'),
    ]);
    $this->createCourseWithLessons('Unpublished', [
      $this->createParticipantLesson($member, 'Hidden'),
    ], NodeInterface::NOT_PUBLISHED);

    $courses = $this->memberProgress->visitedCourses((int) $member->id());
    self::assertSame(['Salsa', 'Zouk'], array_map(
      static fn (array $visited): string => (string) $visited['course']->label(),
      $courses,
    ));
    self::assertSame([1, 2], array_map(
      static fn (array $visited): int => $visited['lessons'],
      $courses,
    ));
    self::assertSame([(int) $salsa->id()], array_map(
      static fn (array $visited): int => (int) $visited['course']->id(),
      $this->memberProgress->visitedCourses((int) $other_member->id()),
    ));

    $learned = $this->createFigure('Dile que no');
    $liked = $this->createFigure('Enchufla');
    $unpublished = $this->createFigure('Hidden figure', 0);
    $foreign = $this->createFigure('Foreign figure');
    $this->flagService->flag($this->learnedFlag, $learned, $member);
    $this->flagService->flag($this->learnedFlag, $unpublished, $member);
    $this->flagService->flag($this->learnedFlag, $foreign, $other_member);
    $this->flagService->flag($this->likeFlag, $liked, $member);

    self::assertSame([(int) $learned->id()], array_map(
      static fn (TermInterface $term): int => (int) $term->id(),
      $this->memberProgress->flaggedFigures((int) $member->id(), DanceFigureFlag::Learned),
    ));
    self::assertSame([(int) $liked->id()], array_map(
      static fn (TermInterface $term): int => (int) $term->id(),
      $this->memberProgress->flaggedFigures((int) $member->id(), DanceFigureFlag::Like),
    ));
  }

  /**
   * Tests who may see the profile sections.
   */
  public function testProfileSections(): void {
    $owner = $this->createUser([
      'access content',
      DanceFigureAccessHooks::VIEW_PERMISSION,
    ]);
    $this->createCourseWithLessons('Salsa', [
      $this->createParticipantLesson($owner, 'Salsa 1'),
    ]);
    $this->flagService->flag($this->learnedFlag, $this->createFigure('Dile que no'), $owner);

    EntityViewDisplay::create([
      'targetEntityType' => 'user',
      'bundle' => 'user',
      'mode' => 'default',
      'status' => TRUE,
      'content' => [
        'member_for' => ['weight' => 5, 'region' => 'content'],
        'visited_courses' => ['weight' => 6, 'region' => 'content'],
        'learned_figures' => ['weight' => 7, 'region' => 'content'],
        'liked_figures' => ['weight' => 8, 'region' => 'content'],
      ],
    ])->save();

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($owner);
    $build = $this->buildProfile($owner);
    self::assertTrue($this->containsFlagLinkBuilder($build));
    self::assertContains('user', $build['visited_courses']['#cache']['contexts'] ?? []);
    self::assertContains('user.permissions', $build['visited_courses']['#cache']['contexts'] ?? []);
    $html = $this->renderBuild($build);
    self::assertStringContainsString('Visited courses', $html);
    self::assertStringContainsString('Salsa (1 lesson)', $html);
    self::assertStringContainsString('Learned figures', $html);
    self::assertStringContainsString('Liked figures', $html);
    self::assertStringContainsString('<p>No figures yet.</p>', $html);
    self::assertStringContainsString('member-profile-section', $html);

    $editor = $this->createUser([
      'access content',
      DanceFigureAccessHooks::VIEW_PERMISSION,
      LessonAccessHooks::EDIT_PERMISSION,
    ]);
    $switcher->switchTo($editor);
    $build = $this->buildProfile($owner);
    self::assertFalse($this->containsFlagLinkBuilder($build));
    $html = $this->renderBuild($build);
    self::assertStringContainsString('Visited courses', $html);
    self::assertStringContainsString('Learned figures', $html);
    self::assertStringContainsString('Liked figures', $html);

    $other_member = $this->createUser([
      'access content',
      DanceFigureAccessHooks::VIEW_PERMISSION,
    ]);
    $switcher->switchTo($other_member);
    $build = $this->buildProfile($owner);
    self::assertContains('user', $build['#cache']['contexts'] ?? []);
    self::assertContains('user.permissions', $build['#cache']['contexts'] ?? []);
    $html = $this->renderBuild($build);
    self::assertStringNotContainsString('Visited courses', $html);
    self::assertStringNotContainsString('Learned figures', $html);
    self::assertStringNotContainsString('Liked figures', $html);

    $switcher->switchTo(new AnonymousUserSession());
    $html = $this->renderProfile($owner);
    self::assertStringNotContainsString('Visited courses', $html);
    self::assertStringNotContainsString('Learned figures', $html);
    self::assertStringNotContainsString('Liked figures', $html);
  }

  /**
   * Returns whether a render array contains a flag link placeholder.
   *
   * @param array<string, mixed> $build
   *   The render array.
   *
   * @return bool
   *   TRUE when the flag link builder is used.
   */
  private function containsFlagLinkBuilder(array $build): bool {
    $found = FALSE;
    array_walk_recursive($build, static function (mixed $value) use (&$found): void {
      if ($value === 'flag.link_builder:build') {
        $found = TRUE;
      }
    });
    return $found;
  }

  /**
   * Builds the default display of a user profile.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function buildProfile(UserInterface $user): array {
    $builder = $this->container->get('entity_type.manager')->getViewBuilder('user');
    return $builder->build($builder->view($user, 'default'));
  }

  /**
   * Renders the default display of a user profile.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return string
   *   The rendered HTML.
   */
  private function renderProfile(UserInterface $user): string {
    return $this->renderBuild($this->buildProfile($user));
  }

  /**
   * Renders a user profile render array.
   *
   * @param array<string, mixed> $build
   *   The render array.
   *
   * @return string
   *   The rendered HTML.
   */
  private function renderBuild(array $build): string {
    $renderer = $this->container->get('renderer');
    \assert($renderer instanceof RendererInterface);
    return (string) $renderer->renderInIsolation($build);
  }

  /**
   * Creates a course that references the given lessons.
   *
   * @param string $title
   *   The course title.
   * @param \Drupal\node\NodeInterface[] $lessons
   *   The lessons.
   * @param int $status
   *   The publication status.
   *
   * @return \Drupal\node\NodeInterface
   *   The course.
   */
  private function createCourseWithLessons(string $title, array $lessons, int $status = NodeInterface::PUBLISHED): NodeInterface {
    return $this->createCourse([
      'title' => $title,
      'status' => $status,
      'field_lessons' => array_map(
        static fn (NodeInterface $lesson): array => ['target_id' => $lesson->id()],
        $lessons,
      ),
    ]);
  }

  /**
   * Creates a lesson with one participant and no figures.
   *
   * @param \Drupal\user\UserInterface $participant
   *   The participant.
   * @param string $title
   *   The lesson title.
   *
   * @return \Drupal\node\NodeInterface
   *   The lesson.
   */
  private function createParticipantLesson(UserInterface $participant, string $title): NodeInterface {
    return $this->createLesson([
      'title' => $title,
      'field_participants' => [$participant->id()],
    ]);
  }

  /**
   * Creates a dance figure.
   *
   * @param string $name
   *   The name.
   * @param int $status
   *   The publication status.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The figure.
   */
  private function createFigure(string $name, int $status = 1): TermInterface {
    $figure = Term::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => $name,
      'status' => $status,
    ]);
    $figure->save();
    return $figure;
  }

}
