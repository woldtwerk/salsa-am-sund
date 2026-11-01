<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Controller\IcalController;
use Drupal\workspace_events\DetailFieldResolver;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\workspace_events\SiteTimeZone;
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
      'season_location' => $location->id(),
      ...$this->courseSchedule($start, $start->modify('+2 weeks')),
    ]);
    $lessons = $this->loadCourseLessons($course);
    self::assertCount(3, $lessons);

    $response = $this->controller->lessonOccurrence($this->lessonRequest($lessons[1]), $lessons[1]);
    self::assertSame(200, $response->getStatusCode());
    $calendar = $this->readCalendar($response->getContent());
    $events = $calendar->select('VEVENT');
    self::assertCount(1, $events);
    $ical_event = array_values($events)[0];
    self::assertSame('event-lesson-' . $lessons[1]->id() . '@salsa-am-sund.localhost', $ical_event->UID?->getValue());
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
    self::assertContains('lesson:' . $lessons[1]->id(), $cacheability->getCacheTags());
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

    // A lesson of an unpublished course has no occurrence. The resolver and
    // the repository memoize the course, so use fresh instances.
    $course->set('status', NodeInterface::NOT_PUBLISHED);
    $course->save();
    // The lesson caches its season and the season its course, so clear the
    // entity caches and reload the lesson to see the unpublished course.
    $this->lessonStorage->resetCache();
    $this->seasonStorage->resetCache();
    $this->nodeStorage->resetCache();
    $unpublished_lesson = $this->lessonStorage->load((int) $lessons[1]->id());
    self::assertInstanceOf(LessonInterface::class, $unpublished_lesson);
    $repository = new EventOccurrenceRepository(
      $this->container->get('entity_type.manager'),
      $this->container->get(SiteTimeZone::class),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
    $controller = new IcalController(
      $repository,
      $this->container->get('entity_type.manager'),
      $this->container->get(EventDescriptionFormatter::class),
      $this->container->get(EventFilterFactory::class),
      $this->container->get(DetailFieldResolver::class),
      $this->container->get('transliteration'),
    );
    $this->assertNotFound(fn () => $controller->lessonOccurrence($this->lessonRequest($unpublished_lesson), $unpublished_lesson));

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
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \DateTimeImmutable
   *   The start.
   */
  private function lessonStart(LessonInterface $lesson): \DateTimeImmutable {
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
   * Builds the request of a lesson occurrence download.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  private function lessonRequest(LessonInterface $lesson): Request {
    return Request::create('https://salsa-am-sund.localhost/termine/stunde/' . $lesson->id() . '/ical');
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
