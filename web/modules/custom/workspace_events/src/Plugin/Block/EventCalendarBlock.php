<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\workspace_events\EventFilter;
use Drupal\workspace_events\EventOccurrence;

/**
 * Provides a calendar and card list of events and courses.
 *
 * The calendar itself is a FullCalendar web component that loads its
 * occurrences from the JSON endpoint. The cards are rendered with the block,
 * so they also work without JavaScript. Filtering and navigation happen in the
 * browser, so the block output does not depend on the request.
 */
#[Block(
  id: 'salsa_event_calendar',
  admin_label: new TranslatableMarkup('Event calendar'),
  category: new TranslatableMarkup('Salsa am Sund'),
)]
final class EventCalendarBlock extends EventBlockBase {

  /**
   * The maximum number of events shown as cards.
   */
  private const int EVENT_CARD_LIMIT = 100;

  /**
   * The number of days courses are shown as cards.
   */
  private const int COURSE_CARD_DAYS = 28;

  /**
   * The maximum number of course occurrences shown as cards.
   */
  private const int COURSE_CARD_LIMIT = 50;

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the block.
   */
  public function build(): array {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([
      'taxonomy_term_list:organizer',
      'node_list:course',
      'lesson_list',
    ]);

    $organizers = [];
    foreach ($this->getOrganizerTerms() as $tid => $term) {
      $organizers[] = [
        'value' => (string) $tid,
        'label' => (string) $term->label(),
      ];
      $cacheability->addCacheableDependency($term);
    }

    $now = new \DateTimeImmutable('now');
    // Weekly courses would push the events out of the list, so both kinds are
    // loaded separately and merged afterwards.
    $occurrences = [
      ...$this->repository->findOccurrences(
        EventFilter::eventsOnly(),
        $now,
        $now->modify('+365 days'),
        self::EVENT_CARD_LIMIT,
      ),
      ...$this->repository->findOccurrences(
        EventFilter::coursesOnly(),
        $now,
        $now->modify('+' . self::COURSE_CARD_DAYS . ' days'),
        self::COURSE_CARD_LIMIT,
      ),
    ];
    usort($occurrences, static function (EventOccurrence $a, EventOccurrence $b): int {
      return [
        $a->start->getTimestamp(),
        $a->sourceKey(),
        (int) $a->node->id(),
      ] <=> [
        $b->start->getTimestamp(),
        $b->sourceKey(),
        (int) $b->node->id(),
      ];
    });

    /** @var array<string, mixed> $slots */
    $slots = [
      // Every organizer is checked, the courses are offered unchecked, like
      // the upcoming events block.
      'subscribe' => $this->buildIcalLinks([], [], $this->findCourses(), TRUE, FALSE),
    ];

    $cards = $this->buildCards($occurrences);
    if ($cards !== []) {
      $slots['cards'] = [
        '#type' => 'component',
        '#component' => 'salsa:grid',
        '#props' => [
          'columns' => '3',
          'gap' => 'md',
        ],
        '#slots' => [
          'items' => $cards,
        ],
      ];
    }

    /** @var array<string, mixed> $build */
    $build = [
      'calendar' => [
        '#type' => 'component',
        '#component' => 'salsa:calendar',
        '#props' => [
          'id' => Html::getUniqueId('salsa-calendar'),
          'events_url' => Url::fromRoute('workspace_events.events_json')->toString(),
          'ical_url' => Url::fromRoute('workspace_events.ical')->toString(),
          'organizers' => $organizers,
        ],
        '#slots' => $slots,
      ],
    ];

    $this->applyOccurrenceCache($build, $occurrences);

    // The filters list every organizer, so their labels are part of the
    // output. Merge these dependencies into the occurrence metadata.
    CacheableMetadata::createFromRenderArray($build)
      ->merge($cacheability)
      ->applyTo($build);

    return $build;
  }

  /**
   * Builds the grid items of the card list.
   *
   * The wrappers carry the data the browser filters on, so calendar.js can
   * show and hide cards without touching their markup.
   *
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The occurrences to render.
   *
   * @return array<int, array<string, mixed>>
   *   The render arrays of the card wrappers.
   */
  private function buildCards(array $occurrences): array {
    $cards = [];
    foreach ($occurrences as $occurrence) {
      $props = $this->buildEventCardProps($occurrence);
      // UpcomingEventsBlock passes no kind, so its cards stay unchanged.
      $props['kind'] = $occurrence->isCourse() ? 'course' : 'event';
      $cards[] = [
        '#type' => 'container',
        '#attributes' => [
          'data-salsa-calendar-card' => '',
          'data-kind' => $props['kind'],
          'data-organizer' => $occurrence->organizer !== NULL ? (string) $occurrence->organizer->id() : '',
        ],
        'card' => [
          '#type' => 'component',
          '#component' => 'salsa:event',
          '#props' => $props,
        ],
      ];
    }
    return $cards;
  }

}
