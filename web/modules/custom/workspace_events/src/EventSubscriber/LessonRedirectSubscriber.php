<?php

declare(strict_types=1);

namespace Drupal\workspace_events\EventSubscriber;

use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteMatch;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\LessonCourseResolver;
use Drupal\workspace_events\LessonGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects the detail page of a lesson to its course.
 *
 * Lessons have no page of their own: they are generated from the schedule of a
 * course and only shown inside the course page. Links from the admin content
 * overview and the redirect after saving a lesson still lead to the lesson, so
 * the request is forwarded to the course instead of rendering the lesson. A
 * lesson that belongs to no course has no page to show and returns 404.
 */
final class LessonRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The route of a node detail page.
   */
  private const string ROUTE_NAME = 'entity.node.canonical';

  /**
   * Constructs a LessonRedirectSubscriber.
   *
   * @param \Drupal\workspace_events\LessonCourseResolver $lessonCourseResolver
   *   The resolver of the course of a lesson.
   */
  public function __construct(
    private readonly LessonCourseResolver $lessonCourseResolver,
  ) {}

  /**
   * Redirects a lesson request to its course.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $route_match = RouteMatch::createFromRequest($event->getRequest());
    if ($route_match->getRouteName() !== self::ROUTE_NAME) {
      return;
    }

    $lesson = $route_match->getParameter('node');
    if (!$lesson instanceof NodeInterface || $lesson->bundle() !== LessonGenerator::LESSON_BUNDLE) {
      return;
    }

    $course = $this->lessonCourseResolver->findCourse((int) $lesson->id());
    if ($course === NULL) {
      throw new NotFoundHttpException();
    }

    $url = $course->toUrl()->toString(TRUE);
    $response = new LocalRedirectResponse($url->getGeneratedUrl());
    $response->addCacheableDependency($url);
    $response->addCacheableDependency($lesson);
    $response->addCacheableDependency($course);
    $response->getCacheableMetadata()->addCacheContexts(['route']);
    $event->setResponse($response);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // The router (priority 32) has matched the route and checked access
    // before this subscriber runs; the dynamic page cache (priority 27) runs
    // after it.
    return [KernelEvents::REQUEST => ['onRequest', 28]];
  }

}
