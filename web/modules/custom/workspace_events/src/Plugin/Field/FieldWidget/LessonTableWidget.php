<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Field\FieldWidget;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\LessonGenerator;
use Drupal\workspace_events\SiteTimeZone;

/**
 * Shows the generated lessons of a course as a compact, read-only table.
 *
 * The lesson references are owned by the lesson generator: the widget never
 * adds, removes or reorders them. Each row links to the edit form of its
 * lesson, so an editor can change a single lesson without scrolling through
 * one autocomplete row per lesson.
 */
#[FieldWidget(
  id: 'workspace_events_lesson_table',
  label: new TranslatableMarkup('Stundentabelle'),
  field_types: ['entity_reference'],
  multiple_values: TRUE,
)]
final class LessonTableWidget extends WidgetBase {

  /**
   * Constructs a LessonTableWidget.
   *
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array<string, mixed> $settings
   *   The widget settings.
   * @param array<string, mixed> $third_party_settings
   *   The third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly SiteTimeZone $siteTimeZone,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition): bool {
    if ($field_definition->getType() !== 'entity_reference' || $field_definition->getSetting('target_type') !== 'node') {
      return FALSE;
    }
    $handler_settings = $field_definition->getSetting('handler_settings');
    if (!is_array($handler_settings)) {
      return FALSE;
    }
    $target_bundles = $handler_settings['target_bundles'] ?? [];
    return is_array($target_bundles) && array_key_exists(LessonGenerator::LESSON_BUNDLE, $target_bundles);
  }

  /**
   * Builds the lesson table for the course form.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The lesson reference field.
   * @param int $delta
   *   The delta, always 0 because the widget handles all values.
   * @param array<string, mixed> $element
   *   The form element.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form element.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    /** @var array<string, mixed> $build */
    $build = $element;

    $lessons = $this->loadLessons($items);
    $course = $items->getEntity();
    $destination = $course instanceof NodeInterface && !$course->isNew()
      ? $course->toUrl('edit-form')->toString()
      : NULL;

    $build['#type'] = 'details';
    $build['#title'] = $this->t('Stunden (@count)', ['@count' => count($lessons)]);
    $build['#open'] = TRUE;

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['languages:language_interface', 'url.site', 'user', 'user.permissions']);
    $cacheability->addCacheableDependency($course);
    $cacheability->addCacheableDependency($this->configFactory->get('system.date'));

    if ($lessons === []) {
      $build['empty'] = [
        '#markup' => $this->t('Die Stunden werden beim Speichern aus Beginn, Ende und Rhythmus erzeugt.'),
      ];
      $build['#cache'] = [
        'contexts' => $cacheability->getCacheContexts(),
        'tags' => $cacheability->getCacheTags(),
        'max-age' => $cacheability->getCacheMaxAge(),
      ];
      return $build;
    }

    $build['hint'] = [
      '#markup' => $this->t('Termine ändern: Stunden löschen, dann Kurs speichern.'),
      '#prefix' => '<div class="messages messages--warning">',
      '#suffix' => '</div>',
    ];

    $rows = [];
    foreach ($lessons as $lesson) {
      $cacheability->addCacheableDependency($lesson);
      $rows[] = $this->buildRow($lesson, $destination);
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Datum'),
        $this->t('Zeit'),
        $this->t('Status'),
        $this->t('Teilnehmer'),
        $this->t('Figuren'),
        $this->t('Aktionen'),
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
   * {@inheritdoc}
   *
   * The lesson generator owns the references. The widget renders no inputs, so
   * an empty submission must not clear the field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The lesson reference field.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state): void {
  }

  /**
   * Loads the referenced lessons that still exist, sorted by their start.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The lesson reference field.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The lessons, sorted by start. Lessons without a date come last.
   */
  private function loadLessons(FieldItemListInterface $items): array {
    $ids = [];
    foreach ($items as $item) {
      $target_id = $item->get('target_id')->getString();
      if ($target_id === '' || !ctype_digit($target_id)) {
        continue;
      }
      $ids[] = (int) $target_id;
    }
    if ($ids === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $lessons = [];
    foreach ($storage->loadMultiple(array_unique($ids)) as $lesson) {
      if ($lesson instanceof NodeInterface && $lesson->bundle() === LessonGenerator::LESSON_BUNDLE) {
        $lessons[] = $lesson;
      }
    }

    /** @var array<int, array{lesson: \Drupal\node\NodeInterface, start: \DateTimeImmutable|null}> $sorted */
    $sorted = [];
    foreach ($lessons as $lesson) {
      $sorted[] = [
        'lesson' => $lesson,
        'start' => $this->lessonStart($lesson),
      ];
    }
    usort($sorted, static fn (array $a, array $b): int => ($a['start']?->getTimestamp() ?? PHP_INT_MAX) <=> ($b['start']?->getTimestamp() ?? PHP_INT_MAX));

    return array_map(static fn (array $item): NodeInterface => $item['lesson'], $sorted);
  }

  /**
   * Builds the table row of a lesson.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   * @param string|null $destination
   *   The course edit URL used as link destination, or NULL when unknown.
   *
   * @return array<int, mixed>
   *   The cells of the row.
   */
  private function buildRow(NodeInterface $lesson, ?string $destination): array {
    $start = $this->lessonStart($lesson);
    $end = $this->lessonEnd($lesson, $start);

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

    $actions = ['#markup' => ''];
    if ($destination !== NULL && $lesson->access('update')) {
      $actions = [
        '#type' => 'link',
        '#title' => $this->t('Bearbeiten'),
        '#url' => $lesson->toUrl('edit-form', ['query' => ['destination' => $destination]]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    return [
      $date,
      $time,
      $this->statusLabel($lesson),
      $this->fieldCount($lesson, 'field_participants'),
      $this->fieldCount($lesson, 'field_dance_figures'),
      $actions,
    ];
  }

  /**
   * Returns the start of a lesson in the site timezone.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   *
   * @return \DateTimeImmutable|null
   *   The start, or NULL when the lesson has no date.
   */
  private function lessonStart(NodeInterface $lesson): ?\DateTimeImmutable {
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
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   * @param \DateTimeImmutable|null $start
   *   The start of the lesson.
   *
   * @return \DateTimeImmutable|null
   *   The end, the start when no end is stored, or NULL without a date.
   */
  private function lessonEnd(NodeInterface $lesson, ?\DateTimeImmutable $start): ?\DateTimeImmutable {
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
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   *
   * @return \Drupal\Core\Field\FieldItemInterface|null
   *   The date item, or NULL when the lesson has no date.
   */
  private function lessonDateItem(NodeInterface $lesson): ?FieldItemInterface {
    if (!$lesson->hasField('field_lesson_date')) {
      return NULL;
    }
    return $lesson->get('field_lesson_date')->first();
  }

  /**
   * Returns the status label of a lesson.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The status label.
   */
  private function statusLabel(NodeInterface $lesson): TranslatableMarkup {
    if ($lesson->hasField('field_cancelled') && $lesson->get('field_cancelled')->getString() === '1') {
      return $this->t('Fällt aus');
    }
    return $lesson->isPublished() ? $this->t('Veröffentlicht') : $this->t('Geplant');
  }

  /**
   * Counts the values of an optional lesson field.
   *
   * @param \Drupal\node\NodeInterface $lesson
   *   The lesson.
   * @param string $field_name
   *   The field name.
   *
   * @return int
   *   The number of values, or 0 when the lesson has no such field.
   */
  private function fieldCount(NodeInterface $lesson, string $field_name): int {
    return $lesson->hasField($field_name) ? $lesson->get($field_name)->count() : 0;
  }

}
