<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Base class for kernel tests of the course lessons.
 *
 * The event content model of the base class already contains the lesson
 * bundle, the course schedule and the event date field.
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
   * Creates a lesson node.
   *
   * @param array<string, mixed> $values
   *   The node values.
   *
   * @return \Drupal\node\NodeInterface
   *   The lesson.
   */
  protected function createLesson(array $values): NodeInterface {
    $values += [
      'type' => 'lesson',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
    ];
    $node = Node::create($values);
    $node->save();
    return $node;
  }

}
