<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Controller\IcalController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the iCal download of a single occurrence.
 */
#[CoversClass(IcalController::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class OccurrenceIcalTest extends LessonKernelTestBase {

  /**
   * The iCal controller.
   */
  private IcalController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->controller = IcalController::create($this->container);
  }

  /**
   * Tests lesson and event downloads and the cases without an occurrence.
   */
  public function testOccurrenceDownload(): void {
    $start = $this->date('now +2 days', '20:00');
    $location = $this->createLocation('Kulturhaus Stralsund');
    $course = $this->createCourse([
      'title' => 'Salsa-Nacht',
      'field_location' => $location->id(),
      ...$this->courseSchedule($start, $start->modify('+2 weeks')),
    ]);
    $lessons = $this->loadCourseLessons($course);
    self::assertCount(3, $lessons);

    $response = $this->controller->occurrence($this->request($lessons[1]), $lessons[1]);
    self::assertSame(200, $response->getStatusCode());
    $calendar = $this->readCalendar($response->getContent());
    $events = $calendar->select('VEVENT');
    self::assertCount(1, $events);
    $ical_event = array_values($events)[0];
    self::assertSame('event-' . $lessons[1]->id() . '@salsa-am-sund.localhost', $ical_event->UID?->getValue());
    self::assertSame('Salsa-Nacht', $ical_event->SUMMARY?->getValue());
    $expected_start = $this->lessonStart($lessons[1]);
    self::assertSame($expected_start->format('Ymd\THis'), $ical_event->DTSTART?->getValue());
    self::assertSame(self::TIMEZONE, $ical_event->DTSTART['TZID']?->getValue());
    $disposition = $response->headers->get('Content-Disposition');
    \assert(is_string($disposition));
    self::assertStringStartsWith('attachment;', $disposition);
    self::assertStringContainsString('Salsa-Nacht-' . $expected_start->format('Y-m-d') . '.ics', $disposition);
    $cacheability = $response->getCacheableMetadata();
    self::assertContains('node:' . $course->id(), $cacheability->getCacheTags());
    self::assertContains('node:' . $lessons[1]->id(), $cacheability->getCacheTags());
    self::assertContains('taxonomy_term:' . $location->id(), $cacheability->getCacheTags());

    $event = $this->createEvent([
      'title' => 'Tanzabend',
      'field_organizer' => $this->externalOrganizer,
      'field_event_date' => $this->smartDateRange($start, $start->setTime(23, 0)),
    ]);
    $response = $this->controller->occurrence($this->request($event), $event);
    $events = $this->readCalendar($response->getContent())->select('VEVENT');
    self::assertCount(1, $events);
    $ical_event = array_values($events)[0];
    self::assertSame('event-' . $event->id() . '@salsa-am-sund.localhost', $ical_event->UID?->getValue());
    self::assertSame('Tanzabend', $ical_event->SUMMARY?->getValue());
    self::assertSame('Sonstige', $ical_event->CATEGORIES?->getValue());
    self::assertSame($start->format('Ymd\THis'), $ical_event->DTSTART?->getValue());
    $cacheability = $response->getCacheableMetadata();
    self::assertContains('node:' . $event->id(), $cacheability->getCacheTags());
    self::assertContains('taxonomy_term:' . $this->externalOrganizer, $cacheability->getCacheTags());

    $this->assertNotFound(fn () => $this->controller->occurrence($this->request($course), $course));

    NodeType::create([
      'type' => 'page',
      'name' => 'Seite',
    ])->save();
    $page = Node::create([
      'type' => 'page',
      'title' => 'Über uns',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
    ]);
    $page->save();
    $this->assertNotFound(fn () => $this->controller->occurrence($this->request($page), $page));

    $unpublished = $this->createEvent([
      'title' => 'Entwurf',
      'status' => NodeInterface::NOT_PUBLISHED,
      'field_event_date' => $this->smartDateRange($start, $start->setTime(23, 0)),
    ]);
    $this->assertNotFound(fn () => $this->controller->occurrence($this->request($unpublished), $unpublished));
  }

  /**
   * Asserts that an occurrence request returns 404.
   *
   * @param callable(): mixed $request
   *   The request call.
   */
  private function assertNotFound(callable $request): void {
    try {
      $request();
    }
    catch (NotFoundHttpException) {
      self::assertTrue(TRUE);
      return;
    }
    self::fail('The occurrence request did not return 404.');
  }

  /**
   * Returns the start of a lesson in the site timezone.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   *
   * @return \DateTimeImmutable
   *   The start.
   */
  private function lessonStart(NodeInterface $lesson): \DateTimeImmutable {
    $item = $lesson->get('field_lesson_date')->first();
    self::assertNotNull($item);
    return (new \DateTimeImmutable(
      $item->get('value')->getString(),
      new \DateTimeZone('UTC'),
    ))->setTimezone(new \DateTimeZone(self::TIMEZONE));
  }

  /**
   * Builds the request of an occurrence download.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  private function request(NodeInterface $node): Request {
    return Request::create('https://salsa-am-sund.localhost/termine/' . $node->id() . '/ical');
  }

  /**
   * Parses an iCal response.
   *
   * @param string|false $content
   *   The response content.
   *
   * @return \Sabre\VObject\Component\VCalendar
   *   The parsed calendar.
   */
  private function readCalendar(string|false $content): VCalendar {
    self::assertIsString($content);
    $calendar = Reader::read($content);
    self::assertInstanceOf(VCalendar::class, $calendar);
    return $calendar;
  }

}
