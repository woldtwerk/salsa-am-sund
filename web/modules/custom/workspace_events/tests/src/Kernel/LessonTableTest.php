<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\workspace_events\Entity\LessonAccessControlHandler;
use Drupal\workspace_events\Entity\SeasonInterface;
use Drupal\workspace_events\LessonTable;
use Drupal\workspace_events\SiteTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the read-only lesson table of the season form.
 */
#[CoversClass(LessonTable::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LessonTableTest extends LessonKernelTestBase {

  /**
   * Tests the table rows, the edit links and the relevant lesson badge.
   */
  public function testLessonTable(): void {
    $now = new \DateTimeImmutable('2026-11-03 12:00:00', new \DateTimeZone(self::TIMEZONE));
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now->getTimestamp());
    $time->method('getRequestTime')->willReturn($now->getTimestamp());
    $this->container->set('datetime.time', $time);
    $this->container->set(SiteTimeZone::class, new SiteTimeZone($this->container->get('config.factory'), $time));

    $editor = $this->createUser(['access content', LessonAccessControlHandler::EDIT_PERMISSION]);
    $non_editor = $this->createUser(['access content']);

    $course = $this->createCourse(['title' => 'Salsa Kurs']);
    $season = $this->createSeason($course);

    // Create the later lesson first, so the table has to sort by start.
    $this->createLesson([
      'title' => 'Regulär',
      'season' => $season->id(),
      'field_lesson_date' => $this->dateRange(
        $now->modify('+2 weeks')->setTime(19, 0),
        $now->modify('+2 weeks')->setTime(20, 0),
      ),
    ]);
    $cancelled = $this->createLesson([
      'title' => 'Ausgefallen',
      'season' => $season->id(),
      'field_lesson_date' => $this->dateRange(
        $now->modify('+1 week')->setTime(19, 0),
        $now->modify('+1 week')->setTime(20, 0),
      ),
      'field_cancelled' => TRUE,
    ]);

    $this->setCurrentUser($editor);
    $build = $this->buildTable($season);
    $rows = $build['table']['#rows'];
    self::assertCount(2, $rows);
    self::assertSame('Cancelled', (string) $rows[0]['data'][2]);
    self::assertSame('Published', (string) $rows[1]['data'][2]);
    self::assertArrayHasKey('#url', $rows[0]['data'][5]['data']);
    self::assertArrayHasKey('#url', $rows[1]['data'][5]['data']);
    self::assertSame(
      $season->toUrl('edit-form')->toString(),
      $rows[0]['data'][5]['data']['#url']->getOption('query')['destination'],
    );

    // The cancelled lesson is the next one, but the table skips it.
    $current_rows = array_keys(array_filter(
      $rows,
      static fn (array $row): bool => in_array('lesson-table__row--current', $row['class'] ?? [], TRUE),
    ));
    self::assertSame([1], $current_rows);

    // Rendering the table must not treat the link render array as attributes.
    $output = (string) \Drupal::service('renderer')->renderInIsolation($build);
    self::assertStringContainsString($cancelled->toUrl('edit-form')->toString(), $output);

    $this->setCurrentUser($non_editor);
    $rows = $this->buildTable($season)['table']['#rows'];
    self::assertCount(2, $rows);
    self::assertArrayNotHasKey('#url', $rows[0]['data'][5]['data']);
    self::assertArrayNotHasKey('#url', $rows[1]['data'][5]['data']);
  }

  /**
   * Builds the lesson table of a season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return array<string, mixed>
   *   The table render array.
   */
  private function buildTable(SeasonInterface $season): array {
    $table = $this->container->get(LessonTable::class);
    \assert($table instanceof LessonTable);

    return $table->build($season);
  }

}
