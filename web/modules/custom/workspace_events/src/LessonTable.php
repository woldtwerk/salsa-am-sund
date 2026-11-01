<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspace_events\Entity\LessonInterface;
use Drupal\workspace_events\Entity\SeasonInterface;

/**
 * Shows the generated lessons of a season as a compact, read-only table.
 *
 * The lesson references are owned by the lesson generator: the table never
 * adds, removes or reorders them. Each row links to the edit form of its
 * lesson, so an editor can change a single lesson without scrolling through
 * one autocomplete row per lesson.
 */
final class LessonTable {

  use StringTranslationTrait;

  /**
   * Constructs a LessonTable.
   *
   * @param \Drupal\workspace_events\SeasonRepository $seasonRepository
   *   The season repository.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly SeasonRepository $seasonRepository,
    private readonly SiteTimeZone $siteTimeZone,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the lesson table of a season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return array<string, mixed>
   *   The render array of the table.
   */
  public function build(SeasonInterface $season): array {
    $lessons = $this->seasonRepository->loadLessons($season);
    $destination = $season->isNew() ? NULL : $season->toUrl('edit-form')->toString();

    $build = [
      '#type' => 'details',
      '#title' => $this->t('Lessons (@count)', ['@count' => count($lessons)]),
      '#open' => TRUE,
    ];

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['languages:language_interface', 'url.site', 'user', 'user.permissions']);
    $cacheability->addCacheableDependency($season);
    $cacheability->addCacheableDependency($this->configFactory->get('system.date'));
    $cacheability->addCacheTags(['lesson_list']);

    if ($lessons === []) {
      $build['empty'] = [
        '#markup' => $this->t('Lessons are generated from the dates when saving the season.'),
      ];
      $build['#cache'] = [
        'contexts' => $cacheability->getCacheContexts(),
        'tags' => $cacheability->getCacheTags(),
        'max-age' => $cacheability->getCacheMaxAge(),
      ];
      return $build;
    }

    $build['hint'] = [
      '#markup' => $this->t('Changing the dates updates the lessons. Lessons with participants, figures or a cancellation keep their date.'),
      '#prefix' => '<div class="messages messages--warning">',
      '#suffix' => '</div>',
    ];

    $relevant = $this->findRelevantLesson($lessons, $this->siteTimeZone->now());

    $rows = [];
    foreach ($lessons as $lesson) {
      $cacheability->addCacheableDependency($lesson);
      $rows[] = $this->buildRow($lesson, $destination, $relevant);
    }
    $build['#attached'] = ['library' => ['workspace_events/lesson_table']];
    $build['table'] = [
      '#type' => 'table',
      '#prefix' => '<div class="lesson-table__wrapper">',
      '#suffix' => '</div>',
      '#header' => [
        $this->t('Date'),
        $this->t('Time'),
        $this->t('Status'),
        $this->t('Participants'),
        $this->t('Figures'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
    ];
    $build['#cache'] = [
      'contexts' => $cacheability->getCacheContexts(),
      'tags' => $cacheability->getCacheTags(),
      'max-age' => $cacheability->getCacheMaxAge(),
    ];

    return $build;
  }

  /**
   * Finds the most relevant lesson of the table.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface[] $lessons
   *   The lessons, sorted by start.
   * @param \DateTimeImmutable $now
   *   The current time in the site timezone.
   *
   * @return array{id: int, today: bool}|null
   *   The lesson ID and whether it runs today, or NULL when all are past.
   */
  private function findRelevantLesson(array $lessons, \DateTimeImmutable $now): ?array {
    $today = $now->format('Y-m-d');
    $today_lesson = NULL;
    $next_lesson = NULL;

    foreach ($lessons as $lesson) {
      $start = $this->lessonStart($lesson);
      if ($start === NULL) {
        continue;
      }
      $end = $this->lessonEnd($lesson, $start);
      $is_today = $start->format('Y-m-d') === $today;

      if ($start <= $now && ($end === NULL || $end >= $now)) {
        return ['id' => (int) $lesson->id(), 'today' => TRUE];
      }
      if ($is_today && $today_lesson === NULL) {
        $today_lesson = ['id' => (int) $lesson->id(), 'today' => TRUE];
      }
      if (!$is_today && $start > $now && $next_lesson === NULL && !$lesson->isCancelled()) {
        $next_lesson = ['id' => (int) $lesson->id(), 'today' => FALSE];
      }
    }

    return $today_lesson ?? $next_lesson;
  }

  /**
   * Builds the table row of a lesson.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   * @param string|null $destination
   *   The season edit URL used as link destination, or NULL when unknown.
   * @param array{id: int, today: bool}|null $relevant
   *   The most relevant lesson and whether it runs today, or NULL.
   *
   * @return array<string, mixed>
   *   The row with its cells and attributes.
   */
  private function buildRow(LessonInterface $lesson, ?string $destination, ?array $relevant): array {
    $start = $this->lessonStart($lesson);
    $end = $this->lessonEnd($lesson, $start);
    $is_relevant = $relevant !== NULL && $relevant['id'] === (int) $lesson->id();

    $date = '';
    $time = '';
    if ($start !== NULL) {
      $date = $this->dateFormatter->format(
        $start->getTimestamp(),
        'custom',
        'D., d.m.Y',
        $this->siteTimeZone->get()->getName(),
      );
      $time = $start->format('H:i');
      if ($end !== NULL) {
        $time .= '–' . $end->format('H:i');
      }
    }

    $date_cell = $date;
    if ($is_relevant) {
      $badge = $relevant['today'] ? $this->t('Today') : $this->t('Next lesson');
      $date_cell = [
        '#markup' => $date . ' <span class="lesson-table__badge">' . $badge . '</span>',
      ];
    }

    $actions = ['#markup' => ''];
    if ($destination !== NULL && $lesson->access('update')) {
      $actions = [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => $lesson->toUrl('edit-form', ['query' => ['destination' => $destination]]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    $row = [
      'data' => [
        $is_relevant ? ['data' => $date_cell] : $date,
        $time,
        $this->statusLabel($lesson),
        $this->fieldCount($lesson, 'field_participants'),
        $this->fieldCount($lesson, 'field_dance_figures'),
        ['data' => $actions],
      ],
      'class' => $is_relevant ? ['lesson-table__row--current'] : [],
    ];
    if ($is_relevant) {
      $row['data-current'] = 'true';
    }

    return $row;
  }

  /**
   * Returns the start of a lesson in the site timezone.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \DateTimeImmutable|null
   *   The start, or NULL when the lesson has no date.
   */
  private function lessonStart(LessonInterface $lesson): ?\DateTimeImmutable {
    $item = $this->lessonDateItem($lesson);
    if ($item === NULL) {
      return NULL;
    }
    $value = $item->get('value')->getString();
    return $value === '' ? NULL : $this->siteTimeZone->fromStorageValue($value);
  }

  /**
   * Returns the end of a lesson in the site timezone.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   * @param \DateTimeImmutable|null $start
   *   The start of the lesson.
   *
   * @return \DateTimeImmutable|null
   *   The end, the start when no end is stored, or NULL without a date.
   */
  private function lessonEnd(LessonInterface $lesson, ?\DateTimeImmutable $start): ?\DateTimeImmutable {
    $item = $this->lessonDateItem($lesson);
    if ($item === NULL) {
      return NULL;
    }
    $value = $item->get('end_value')->getString();
    return $value === '' ? $start : $this->siteTimeZone->fromStorageValue($value);
  }

  /**
   * Returns the first date item of a lesson.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \Drupal\Core\Field\FieldItemInterface|null
   *   The date item, or NULL when the lesson has no date.
   */
  private function lessonDateItem(LessonInterface $lesson): ?FieldItemInterface {
    if (!$lesson->hasField('field_lesson_date')) {
      return NULL;
    }
    return $lesson->get('field_lesson_date')->first();
  }

  /**
   * Returns the status label of a lesson.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The status label.
   */
  private function statusLabel(LessonInterface $lesson): TranslatableMarkup {
    if ($lesson->isCancelled()) {
      return $this->t('Cancelled');
    }
    return $lesson->isPublished() ? $this->t('Published') : $this->t('Scheduled', [], ['context' => 'workspace_events']);
  }

  /**
   * Counts the values of an optional lesson field.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson.
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The number of values, or 0 when the lesson has no such field.
   */
  private function fieldCount(LessonInterface $lesson, string $field_name): int {
    return $lesson->hasField($field_name) ? $lesson->get($field_name)->count() : 0;
  }

}
