<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the block plugins of this module are Canvas components.
 */
#[CoversNothing]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class CanvasComponentsTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'canvas_test_sdc',
    'flag',
    // The season entity references a course node and a location term and uses
    // a date range, so its views data cannot be built without these modules.
    'node',
    'taxonomy',
    'datetime_range',
    'workspace_events',
  ];

  /**
   * Tests that both block plugins are discovered as enabled components.
   */
  public function testBlocksAreDiscoveredAsComponents(): void {
    // Trigger Canvas component discovery, like a cache rebuild would.
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    foreach (['block.salsa_events', 'block.salsa_course_schedule', 'block.salsa_event_calendar'] as $id) {
      $component = Component::load($id);
      self::assertInstanceOf(Component::class, $component, "The component $id was not discovered.");
      self::assertTrue($component->status(), "The component $id is not enabled.");
      self::assertSame('workspace_events', $component->get('provider'));
      self::assertSame('block', $component->get('source'));
    }
  }

}
