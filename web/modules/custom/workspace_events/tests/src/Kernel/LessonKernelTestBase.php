<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\workspace_events\Entity\LessonInterface;

/**
 * Base class for kernel tests of the course lessons.
 *
 * The event content model of the base class already contains the lesson
 * entity type, the course schedule and the event date field.
 */
abstract class LessonKernelTestBase extends EventKernelTestBase {

  /**
   * The modules the lesson tests need on top of the event modules.
   *
   * @var string[]
   */
  protected static $modules = [
    'file',
    'image',
    'scheduler',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installLessonConfig();
  }

  /**
   * Creates a lesson.
   *
   * @param array<string, mixed> $values
   *   The lesson values.
   *
   * @return \Drupal\workspace_events\Entity\LessonInterface
   *   The lesson.
   */
  protected function createLesson(array $values): LessonInterface {
    $values += [
      'status' => 1,
      'uid' => 1,
    ];
    $lesson = $this->lessonStorage->create($values);
    \assert($lesson instanceof LessonInterface);
    $lesson->save();
    return $lesson;
  }

}
