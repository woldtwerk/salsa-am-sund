<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\workspace_events\Plugin\Block\EventCalendarBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the build data of the event calendar block.
 */
#[CoversClass(EventCalendarBlock::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class EventCalendarBlockTest extends EventKernelTestBase {

  /**
   * The block plugin manager.
   */
  private BlockManagerInterface $blockManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installLessonConfig();
    $this->blockManager = $this->container->get('plugin.manager.block');
  }

  /**
   * Tests the component props, the cards and the calendar subscription slot.
   */
  public function testBuild(): void {
    $location = $this->createLocation('Bootshaus Stralsund');
    $start = $this->date('now +2 days', '18:15');
    $course = $this->createCourse([
      'title' => 'Anfänger',
      'field_location' => $location->id(),
      ...$this->courseSchedule($start, $start),
    ]);
    // One event before and one after the course, so the card order is testable.
    $guest = $this->createEvent([
      'title' => 'Gastparty',
      'field_organizer' => $this->externalOrganizer,
      'field_event_date' => $this->dateRange(
        $this->date('now +1 day', '21:00'),
        $this->date('now +1 day', '23:00'),
      ),
    ]);
    $this->createEvent([
      'title' => 'Salsa-Nacht',
      'field_location' => $location->id(),
      'field_event_date' => $this->dateRange(
        $this->date('now +3 days', '20:00'),
        $this->date('now +3 days', '23:00'),
      ),
    ]);
    // Outside the 28-day card window, so it must not become a card.
    $late = $this->date('now +200 days', '18:15');
    $this->createCourse([
      'title' => 'Später Kurs',
      ...$this->courseSchedule($late, $late),
    ]);

    $block = $this->blockManager->createInstance('salsa_event_calendar', []);
    $build = $block->build();

    self::assertArrayHasKey('calendar', $build);
    self::assertSame('salsa:calendar', $build['calendar']['#component']);

    $props = $build['calendar']['#props'];

    // Every organizer is offered as a filter.
    self::assertSame([
      ['value' => (string) $this->salsaAmSund, 'label' => 'Salsa am Sund'],
      ['value' => (string) $this->externalOrganizer, 'label' => 'Sonstige'],
    ], $props['organizers']);
    self::assertArrayNotHasKey('locations', $props);

    // The subscription offers the organizers checked and the course unchecked.
    $component = $build['calendar']['#slots']['subscribe'];
    self::assertSame('salsa:calendar-subscribe', $component['subscribe']['#component']);
    $options = $component['subscribe']['#props']['options'];
    self::assertSame(['organizers', 'organizers', 'courses'], array_column($options, 'group'));
    self::assertTrue($options[0]['checked']);
    self::assertTrue($options[1]['checked']);
    self::assertFalse($options[2]['checked']);
    self::assertSame([], $component['subscribe']['#props']['hidden']);

    // The cards are grid items, one per occurrence, in date order.
    $grid = $build['calendar']['#slots']['cards'];
    self::assertSame('salsa:grid', $grid['#component']);
    $items = $grid['#slots']['items'];
    self::assertCount(3, $items);
    self::assertSame(['event', 'course', 'event'], array_map(
      static fn (array $item): string => $item['#attributes']['data-kind'],
      $items,
    ));
    self::assertSame([
      (string) $this->externalOrganizer,
      '',
      (string) $this->salsaAmSund,
    ], array_map(
      static fn (array $item): string => $item['#attributes']['data-organizer'],
      $items,
    ));
    self::assertSame(['Gastparty', 'Anfänger', 'Salsa-Nacht'], array_map(
      static fn (array $item): string => $item['card']['#props']['title'],
      $items,
    ));
    self::assertSame(['event', 'course', 'event'], array_map(
      static fn (array $item): string => $item['card']['#props']['kind'],
      $items,
    ));
    self::assertSame('salsa:event', $items[0]['card']['#component']);
    self::assertSame(
      '/termine/' . $guest->id() . '/ical',
      $items[0]['card']['#props']['ical_href'],
    );

    $lessons = $this->loadCourseLessons($course);
    self::assertCount(1, $lessons);
    self::assertSame(
      '/termine/' . $lessons[0]->id() . '/ical',
      $items[1]['card']['#props']['ical_href'],
    );

    // The block output does not depend on the request.
    self::assertSame([], $build['#cache']['contexts'] ?? []);
    // The cards and the feed expire with the last shown occurrence.
    self::assertLessThanOrEqual(86400, $build['#cache']['max-age']);
    foreach ([
      'taxonomy_term_list:organizer',
      'node_list:event',
      'node_list:course',
      'node_list:lesson',
      'taxonomy_term:' . $this->salsaAmSund,
      'taxonomy_term:' . $this->externalOrganizer,
    ] as $tag) {
      self::assertContains($tag, $build['#cache']['tags']);
    }
  }

}
