<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\workspace_events\EventSubscriber\LessonRedirectSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the redirect of a lesson detail page to its course.
 */
#[CoversClass(LessonRedirectSubscriber::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LessonRedirectTest extends LessonKernelTestBase {

  /**
   * Tests the lesson redirect, the missing course and the course page.
   */
  public function testLessonRedirectsToItsCourse(): void {
    // Bypass the lesson grants, so only the redirect decides the response.
    $this->setCurrentUser($this->createUser(['access content', 'bypass node access']));

    $date = $this->date('2026-11-02', '18:00');
    $lesson = $this->createLesson([
      'title' => 'Stunde im Kurs',
      'field_lesson_date' => $this->dateRange($date, $date->modify('+1 hour')),
    ]);
    $course = $this->createCourse([
      'title' => 'Salsa Kurs',
      'field_lessons' => [['target_id' => $lesson->id()]],
    ]);
    $without_course = $this->createLesson([
      'title' => 'Stunde ohne Kurs',
      'field_lesson_date' => $this->dateRange($date, $date->modify('+1 hour')),
    ]);

    $response = $this->handle('/node/' . $lesson->id());
    self::assertSame(302, $response->getStatusCode());
    self::assertStringEndsWith(
      '/node/' . $course->id(),
      (string) $response->headers->get('Location'),
    );

    $response = $this->handle('/node/' . $without_course->id());
    self::assertSame(404, $response->getStatusCode());

    $response = $this->handle('/node/' . $course->id());
    self::assertSame(200, $response->getStatusCode());
  }

  /**
   * Dispatches a request through the HTTP kernel.
   *
   * @param string $path
   *   The request path.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function handle(string $path): Response {
    return $this->container->get('http_kernel')->handle(Request::create($path));
  }

}
