<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\user\UserInterface;
use Drupal\workspace_events\Entity\LessonAccessControlHandler;
use Drupal\workspace_events\Entity\LessonInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the lesson access rules and the protected participant field.
 */
#[CoversClass(LessonAccessControlHandler::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LessonAccessTest extends LessonKernelTestBase {

  /**
   * The participant of both lessons.
   */
  private UserInterface $participant;

  /**
   * A member who does not take part in the lessons.
   */
  private UserInterface $otherMember;

  /**
   * The editor.
   */
  private UserInterface $editor;

  /**
   * The published lesson.
   */
  private LessonInterface $publishedLesson;

  /**
   * The unpublished lesson.
   */
  private LessonInterface $unpublishedLesson;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->participant = $this->createUser(['access content']);
    $this->otherMember = $this->createUser(['access content']);
    $this->editor = $this->createUser([
      'access content',
      LessonAccessControlHandler::EDIT_PERMISSION,
      LessonAccessControlHandler::DELETE_PERMISSION,
    ]);

    $date = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $values = [
      'title' => 'Stunde mit Teilnehmer',
      'field_lesson_date' => $this->dateRange($date, $date->modify('+1 hour')),
      'field_participants' => [['target_id' => $this->participant->id()]],
    ];
    $this->publishedLesson = $this->createLesson($values);
    $this->unpublishedLesson = $this->createLesson($values + ['status' => 0]);
  }

  /**
   * Tests viewing, updating and the protected participant field.
   */
  public function testLessonAccess(): void {
    self::assertTrue($this->publishedLesson->access('view', $this->participant));
    self::assertFalse($this->unpublishedLesson->access('view', $this->participant));

    self::assertFalse($this->publishedLesson->access('view', $this->otherMember));
    self::assertFalse($this->unpublishedLesson->access('view', $this->otherMember));

    self::assertTrue($this->publishedLesson->access('view', $this->editor));
    self::assertTrue($this->unpublishedLesson->access('view', $this->editor));
    self::assertTrue($this->publishedLesson->access('update', $this->editor));
    self::assertTrue($this->publishedLesson->access('delete', $this->editor));

    self::assertFalse($this->publishedLesson->access('update', $this->participant));
    self::assertFalse($this->publishedLesson->access('delete', $this->participant));

    self::assertFalse($this->publishedLesson->get('field_participants')->access('view', $this->participant));
    self::assertFalse($this->publishedLesson->get('field_participants')->access('edit', $this->participant));
    self::assertTrue($this->publishedLesson->get('field_participants')->access('view', $this->editor));
    self::assertTrue($this->publishedLesson->get('field_participants')->access('edit', $this->editor));
  }

}
