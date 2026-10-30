<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\pathauto\PathautoState;
use Drupal\workspace_events\LessonGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that generated lessons do not get URL aliases.
 */
#[CoversClass(LessonGenerator::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LessonAliasTest extends LessonKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path',
    'path_alias',
    'pathauto',
    'token',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installConfig(['pathauto']);
  }

  /**
   * Tests that a matching pattern does not alias the generated lessons.
   */
  public function testLessonsGetNoAlias(): void {
    PathautoPattern::create([
      'id' => 'all_nodes',
      'label' => 'All nodes',
      'type' => 'canonical_entities:node',
      'pattern' => '/[node:title]',
      'selection_criteria' => [],
      'selection_logic' => 'and',
    ])->save();

    $first = new \DateTimeImmutable('now +1 week 18:00', new \DateTimeZone(self::TIMEZONE));
    $course = $this->createCourse([
      'title' => 'Alias-Kurs',
      ...$this->courseSchedule($first, $first->modify('+1 week')),
    ]);
    $lessons = $this->loadCourseLessons($course);
    self::assertCount(2, $lessons);

    $database = $this->container->get('database');
    foreach ($lessons as $lesson) {
      self::assertSame(PathautoState::SKIP, (int) $lesson->get('path')->first()?->get('pathauto')->getString());
      $aliases = $database->select('path_alias', 'p')
        ->condition('path', '/node/' . $lesson->id())
        ->countQuery()
        ->execute()
        ->fetchField();
      self::assertSame(0, (int) $aliases);
    }
  }

}
