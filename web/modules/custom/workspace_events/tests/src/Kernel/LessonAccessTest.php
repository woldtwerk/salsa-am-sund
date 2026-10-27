<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_events\Hook\LessonAccessHooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the lesson grants and the protected lesson fields.
 */
#[CoversClass(LessonAccessHooks::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LessonAccessTest extends LessonKernelTestBase {

  /**
   * The participant of the lesson.
   */
  private UserInterface $participant;

  /**
   * A member who does not take part in the lesson.
   */
  private UserInterface $otherMember;

  /**
   * The editor.
   */
  private UserInterface $editor;

  /**
   * The lesson.
   */
  private NodeInterface $lesson;

  /**
   * The attendance photo.
   */
  private FileInterface $photo;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->participant = $this->createUser(['access content']);
    $this->otherMember = $this->createUser(['access content']);
    $this->editor = $this->createUser([
      'access content',
      LessonAccessHooks::EDIT_PERMISSION,
      'delete any lesson content',
    ]);

    $file = File::create([
      'uri' => 'private://lessons/2026-10/photo.jpg',
      'filename' => 'photo.jpg',
      'filemime' => 'image/jpeg',
      'filesize' => 1024,
      'uid' => 1,
      'status' => 1,
    ]);
    $file->save();
    $this->photo = $file;

    $date = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $this->lesson = $this->createLesson([
      'title' => 'Stunde mit Foto',
      'field_lesson_date' => [
        'value' => $date->format('Y-m-d\TH:i:s'),
        'end_value' => $date->modify('+1 hour')->format('Y-m-d\TH:i:s'),
      ],
      'field_participants' => [['target_id' => $this->participant->id()]],
      'field_attendance_photo' => [['target_id' => $file->id(), 'alt' => '']],
    ]);

    $this->createLesson([
      'title' => 'Stunde ohne Teilnehmer',
      'field_lesson_date' => [
        'value' => $date->format('Y-m-d\TH:i:s'),
        'end_value' => $date->modify('+1 hour')->format('Y-m-d\TH:i:s'),
      ],
    ]);
  }

  /**
   * Tests the view access and the access-checked entity query.
   */
  public function testLessonViewAccessAndQuery(): void {
    self::assertFalse($this->lesson->access('view', new AnonymousUserSession()));
    self::assertFalse($this->lesson->access('view', $this->otherMember));
    self::assertTrue($this->lesson->access('view', $this->participant));
    self::assertTrue($this->lesson->access('view', $this->editor));

    $this->setCurrentUser($this->participant);
    self::assertSame([(int) $this->lesson->id()], $this->queryLessonIds());

    $this->setCurrentUser($this->otherMember);
    self::assertSame([], $this->queryLessonIds());

    $this->setCurrentUser(new AnonymousUserSession());
    self::assertSame([], $this->queryLessonIds());

    $this->setCurrentUser($this->editor);
    self::assertCount(2, $this->queryLessonIds());
  }

  /**
   * Tests the editor-only fields and the private photo download.
   */
  public function testProtectedFieldsAndPhoto(): void {
    self::assertFalse($this->lesson->get('field_participants')->access('view', $this->participant));
    self::assertFalse($this->lesson->get('field_participants')->access('edit', $this->participant));
    self::assertFalse($this->lesson->get('field_attendance_photo')->access('view', $this->participant));

    self::assertTrue($this->lesson->get('field_participants')->access('view', $this->editor));
    self::assertTrue($this->lesson->get('field_participants')->access('edit', $this->editor));
    self::assertTrue($this->lesson->get('field_attendance_photo')->access('view', $this->editor));

    self::assertFalse($this->photo->access('download', $this->participant));
    self::assertTrue($this->photo->access('download', $this->editor));
  }

  /**
   * Returns the lesson IDs an access-checked entity query returns.
   *
   * @return int[]
   *   The lesson IDs, sorted.
   */
  private function queryLessonIds(): array {
    $ids = $this->nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'lesson')
      ->execute();
    $ids = array_map(static fn (mixed $id): int => (int) $id, array_values($ids));
    sort($ids);
    return $ids;
  }

}
