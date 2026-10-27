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
   * Tests anonymous, member and other-vocabulary access in one scenario.
   */
  public function testDanceFigureAccess(): void {
    Vocabulary::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Tanzfiguren',
    ])->save();
    $figure = Term::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Dile que no',
      'status' => 1,
    ]);
    $figure->save();

    $anonymous_role = Role::load('anonymous') ?? Role::create([
      'id' => 'anonymous',
      'label' => 'Gast',
    ]);
    $anonymous_role->grantPermission('access content');
    $anonymous_role->save();
    $this->setCurrentUser(User::getAnonymousUser());
    $anonymous_access = $this->viewAccess($figure);
    self::assertTrue($anonymous_access->isForbidden());
    self::assertContains('user.permissions', $anonymous_access->getCacheContexts());

    $role = Role::create([
      'id' => 'member',
      'label' => 'Mitglied',
      'permissions' => ['access content', DanceFigureAccessHooks::VIEW_PERMISSION],
    ]);
    $role->save();
    $member = User::create([
      'name' => $this->randomMachineName(),
      'status' => 1,
      'roles' => ['member'],
    ]);
    $member->save();
    $this->setCurrentUser($member);
    self::assertTrue($this->viewAccess($figure)->isAllowed());

    // Other vocabularies are not restricted for anonymous users.
    $this->setCurrentUser(User::getAnonymousUser());
    $location = $this->createLocation('Salsa am Sund');
    self::assertTrue($this->viewAccess($location)->isAllowed());
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
