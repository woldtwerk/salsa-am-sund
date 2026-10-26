<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\workspace_events\Hook\DanceFigureAccessHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the access restriction of the dance figure vocabulary.
 */
#[CoversClass(DanceFigureAccessHooks::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class DanceFigureAccessHooksTest extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    Vocabulary::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Tanzfiguren',
    ])->save();
  }

  /**
   * Tests that anonymous users cannot view dance figures.
   */
  public function testAnonymousCannotViewDanceFigure(): void {
    $this->setUpAnonymousWithAccessContent();
    $term = $this->createDanceFigure('Dile que no');

    $access = $this->viewAccess($term);

    self::assertTrue($access->isForbidden());
    self::assertContains('user.permissions', $access->getCacheContexts());
  }

  /**
   * Tests that members can view dance figures.
   */
  public function testMemberCanViewDanceFigure(): void {
    $role = Role::create([
      'id' => 'member',
      'label' => 'Mitglied',
      'permissions' => ['access content', DanceFigureAccessHooks::VIEW_PERMISSION],
    ]);
    $role->save();
    $user = User::create([
      'name' => $this->randomMachineName(),
      'status' => 1,
      'roles' => ['member'],
    ]);
    $user->save();
    $this->setCurrentUser($user);

    $term = $this->createDanceFigure('Enchufla');

    self::assertTrue($this->viewAccess($term)->isAllowed());
  }

  /**
   * Tests that the access restriction only applies to dance figures.
   */
  public function testOtherVocabularyStaysViewableForAnonymous(): void {
    $this->setUpAnonymousWithAccessContent();
    $term = $this->createLocation('Salsa am Sund');

    self::assertTrue($this->viewAccess($term)->isAllowed());
  }

  /**
   * Sets the anonymous user up with the permission to view published terms.
   */
  private function setUpAnonymousWithAccessContent(): void {
    $role = Role::load('anonymous');
    if ($role === NULL) {
      $role = Role::create([
        'id' => 'anonymous',
        'label' => 'Gast',
      ]);
    }
    $role->grantPermission('access content');
    $role->save();

    $this->setCurrentUser(User::getAnonymousUser());
  }

  /**
   * Creates a dance figure term.
   *
   * @param string $name
   *   The term name.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The term.
   */
  private function createDanceFigure(string $name): TermInterface {
    $term = Term::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => $name,
      'status' => 1,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Returns the view access result of a term for the current user.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The term.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  private function viewAccess(TermInterface $term): AccessResultInterface {
    return $term->access('view', NULL, TRUE);
  }

}
