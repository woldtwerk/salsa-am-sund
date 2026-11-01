<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_events\Entity\LessonAccessControlHandler;
use Drupal\workspace_events\Hook\DanceFigureAccessHooks;

/**
 * Controls access to the "Meine Kurse" tab of a user profile.
 *
 * The view itself has no access plugin, so this route requirement is the only
 * access check. Members may see their own page, editors may see every page.
 */
final class MyCoursesAccess implements AccessInterface {

  /**
   * Checks access to the courses tab of a user profile.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, RouteMatchInterface $route_match): AccessResultInterface {
    $route_user = $route_match->getParameter('user');
    if ($route_user instanceof UserInterface) {
      $route_user_id = (int) $route_user->id();
    }
    elseif (is_numeric($route_user)) {
      $route_user_id = (int) $route_user;
    }
    else {
      return $this->forbidden();
    }

    if ($account->hasPermission(LessonAccessControlHandler::EDIT_PERMISSION)) {
      return $this->allowed();
    }

    if ($route_user_id === (int) $account->id() && $account->hasPermission(DanceFigureAccessHooks::VIEW_PERMISSION)) {
      return $this->allowed();
    }

    return $this->forbidden();
  }

  /**
   * Returns an allowed result with the cache contexts of the check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  private function allowed(): AccessResultInterface {
    return AccessResult::allowed()->addCacheContexts(['user', 'user.permissions']);
  }

  /**
   * Returns a forbidden result with the cache contexts of the check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  private function forbidden(): AccessResultInterface {
    return AccessResult::forbidden()->addCacheContexts(['user', 'user.permissions']);
  }

}
