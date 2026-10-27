<?php

declare(strict_types=1);

namespace Drupal\workspace_events\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\workspace_events\Access\MyCoursesAccess;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds the custom access check to the "Meine Kurse" view route.
 *
 * The view uses the "none" access plugin, so the permission is not checked
 * twice: this route requirement decides whether the tab and the page are
 * available.
 */
final class MyCoursesRouteSubscriber extends RouteSubscriberBase {

  /**
   * The route of the "Meine Kurse" view page.
   */
  private const string ROUTE_NAME = 'view.my_courses.page_1';

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get(self::ROUTE_NAME);
    if ($route === NULL) {
      return;
    }

    $route->setRequirement('_custom_access', MyCoursesAccess::class . '::access');
  }

}
