<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\workspace_events\DanceFigureFlag;
use Drupal\workspace_events\DanceFigureInstaller;
use Drupal\workspace_events\Hook\DanceFigureAccessHooks;
use Drupal\workspace_events\Hook\LessonLearnedFiguresHooks;
use Drupal\workspace_events\LearnedFigureFlagger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the learned flags of the lesson figures.
 */
#[CoversClass(LearnedFigureFlagger::class)]
#[CoversClass(LessonLearnedFiguresHooks::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LearnedFigureFlaggerTest extends LessonKernelTestBase {

  /**
   * The modules the flag test needs.
   *
   * @var string[]
   */
  protected static $modules = ['flag'];

  /**
   * The flag service.
   */
  private FlagServiceInterface $flagService;

  /**
   * The learned flag.
   */
  private FlagInterface $flag;

  /**
   * The first figure of the lesson.
   */
  private TermInterface $firstFigure;

  /**
   * The second figure of the lesson.
   */
  private TermInterface $secondFigure;

  /**
   * The first participant.
   */
  private UserInterface $firstParticipant;

  /**
   * The second participant.
   */
  private UserInterface $secondParticipant;

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
    $installer->install([DanceFigureFlag::Learned->configName()]);

    $flag_service = $this->container->get('flag');
    \assert($flag_service instanceof FlagServiceInterface);
    $this->flagService = $flag_service;
    $flag = $this->flagService->getFlagById(DanceFigureFlag::Learned->value);
    \assert($flag instanceof FlagInterface);
    $this->flag = $flag;

    $this->firstFigure = $this->createFigure('Dile que no');
    $this->secondFigure = $this->createFigure('Enchufla');
    $this->firstParticipant = User::create([
      'name' => 'first',
      'status' => 1,
    ]);
    $this->firstParticipant->save();
    $this->secondParticipant = User::create([
      'name' => 'second',
      'status' => 1,
    ]);
    $this->secondParticipant->save();
  }

  /**
   * Tests the flags created by inserting and updating a lesson.
   */
  public function testFlagsNewFigureAndParticipantCombinations(): void {
    $lesson = $this->createLesson([
      'title' => 'Lesson',
      'field_dance_figures' => [$this->firstFigure->id()],
      'field_participants' => [$this->firstParticipant->id()],
    ]);

    self::assertTrue($this->isFlagged($this->firstFigure, $this->firstParticipant));
    self::assertFalse($this->isFlagged($this->secondFigure, $this->firstParticipant));

    // A member may already have flagged a figure that the lesson adds later.
    $this->flagService->flag($this->flag, $this->secondFigure, $this->firstParticipant);

    $lesson->set('field_dance_figures', [
      $this->firstFigure->id(),
      $this->secondFigure->id(),
    ]);
    $lesson->set('field_participants', [
      $this->firstParticipant->id(),
      $this->secondParticipant->id(),
    ]);
    $lesson->save();

    foreach ([$this->firstParticipant, $this->secondParticipant] as $participant) {
      foreach ([$this->firstFigure, $this->secondFigure] as $figure) {
        self::assertTrue($this->isFlagged($figure, $participant));
      }
    }
    self::assertSame(1, $this->flaggingCount($this->firstParticipant, $this->secondFigure));

    // Removing a participant keeps the figures that member already learned.
    $lesson->set('field_participants', [$this->firstParticipant->id()]);
    $lesson->save();

    self::assertTrue($this->isFlagged($this->firstFigure, $this->secondParticipant));
    self::assertTrue($this->isFlagged($this->secondFigure, $this->secondParticipant));
  }

  /**
   * Returns whether a user has flagged a figure as learned.
   *
   * @param \Drupal\taxonomy\TermInterface $figure
   *   The figure.
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return bool
   *   TRUE when the figure is flagged as learned.
   */
  private function isFlagged(TermInterface $figure, UserInterface $user): bool {
    return $this->flag->isFlagged($figure, $user);
  }

  /**
   * Counts the learned flaggings of a user for a figure.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param \Drupal\taxonomy\TermInterface $figure
   *   The figure.
   *
   * @return int
   *   The number of flaggings.
   */
  private function flaggingCount(UserInterface $user, TermInterface $figure): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('flagging')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', DanceFigureFlag::Learned->value)
      ->condition('uid', $user->id())
      ->condition('entity_id', $figure->id())
      ->count()
      ->execute();
  }

  /**
   * Creates a published dance figure.
   *
   * @param string $name
   *   The name.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The figure.
   */
  private function createFigure(string $name): TermInterface {
    $figure = Term::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => $name,
      'status' => 1,
    ]);
    $figure->save();
    return $figure;
  }

}
