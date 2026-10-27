<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\node\NodeInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\workspace_events\LessonInstaller;

/**
 * Base class for kernel tests of the course lessons.
 *
 * Adds the scheduler, the datetime range field and the lesson configuration
 * to the event content model of the module's tests.
 */
abstract class LessonKernelTestBase extends EventKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'field',
    'node',
    'options',
    'taxonomy',
    'content_moderation',
    'workflows',
    'pathauto',
    'token',
    'flag',
    'smart_date',
    'smart_date_recur',
    'datetime_range',
    'scheduler',
    'workspace_events',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['scheduler']);
    $this->config('system.date')
      ->set('timezone.default', self::TIMEZONE)
      ->save();
    date_default_timezone_set(self::TIMEZONE);
  }

  /**
   * Installs the lesson bundle, its fields and the course and event fields.
   */
  protected function installLessonConfig(): void {
    $installer = $this->container->get(LessonInstaller::class);
    \assert($installer instanceof LessonInstaller);
    $installer->install();

    // Creating the fields can rebuild the container. Refresh the storage so
    // the tests use the field definitions of the new configuration.
    $this->nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
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
