<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\workspace_events\Entity\SeasonInterface;
use Drupal\workspace_events\LessonGenerator;
use Drupal\workspace_events\SeasonRepository;
use Drupal\workspace_events\SiteTimeZone;

/**
 * Keeps the lessons of a course season in sync with the season.
 */
final class SeasonHooks {

  use StringTranslationTrait;

  /**
   * Constructs the season hooks.
   *
   * @param \Drupal\workspace_events\LessonGenerator $lessonGenerator
   *   The lesson generator.
   * @param \Drupal\workspace_events\SeasonRepository $seasonRepository
   *   The season repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\workspace_events\SiteTimeZone $siteTimeZone
   *   The site timezone.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly LessonGenerator $lessonGenerator,
    private readonly SeasonRepository $seasonRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly SiteTimeZone $siteTimeZone,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Implements hook_sam_allowed_widget_types_alter().
   *
   * Lets the SAM module simplify the price widget, so the empty price rows are
   * hidden behind its "Add another item" button instead of showing all five
   * rows of the field.
   *
   * @param array<mixed> $widget_types
   *   The widget IDs that the SAM module may simplify.
   */
  #[Hook('sam_allowed_widget_types_alter')]
  public function samAllowedWidgetTypesAlter(array &$widget_types): void {
    $widget_types[] = 'workspace_events_price_default';
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for course seasons.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The new season.
   */
  #[Hook('course_season_insert')]
  public function courseSeasonInsert(SeasonInterface $season): void {
    $this->lessonGenerator->generate($season);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for course seasons.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The updated season.
   */
  #[Hook('course_season_update')]
  public function courseSeasonUpdate(SeasonInterface $season): void {
    if (!$season->hasScheduleChanged()) {
      return;
    }

    // Validation refuses a schedule change with recorded lessons. An API save
    // can bypass validation, so keep the lessons in that case as well.
    if ($this->seasonRepository->hasRecordedLessons($season)) {
      $this->loggerFactory->get('workspace_events')->warning(
        'Kept the lessons of season %season because they have recorded data; the changed schedule was not applied to them.',
        ['%season' => $season->id()],
      );
      return;
    }

    $this->lessonGenerator->regenerate($season);
  }

  /**
   * Implements hook_ENTITY_TYPE_predelete() for course seasons.
   *
   * Lessons without recorded data are deleted with their season. Lessons with
   * figures, participants or a cancellation are kept, so their history
   * survives even when a season is deleted by an API call that skips access.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season being deleted.
   */
  #[Hook('course_season_predelete')]
  public function courseSeasonPredelete(SeasonInterface $season): void {
    foreach ($this->seasonRepository->loadLessons($season) as $lesson) {
      if ($lesson->hasRecordedData()) {
        $this->loggerFactory->get('workspace_events')->warning(
          'Kept lesson %id of season %season because it has recorded data.',
          ['%id' => $lesson->id(), '%season' => $season->id()],
        );
        continue;
      }
      $lesson->delete();
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_predelete() for node entities.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being deleted.
   */
  #[Hook('node_predelete')]
  public function nodePredelete(NodeInterface $node): void {
    if ($node->bundle() !== LessonGenerator::COURSE_BUNDLE) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('course_season');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('course', $node->id())
      ->execute();
    if ($ids === []) {
      return;
    }

    $storage->delete($storage->loadMultiple($ids));
  }

  /**
   * Implements hook_node_access().
   *
   * Forbids deleting a course while any of its lessons has recorded data.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  #[Hook('node_access')]
  public function nodeAccess(NodeInterface $node, string $operation, AccountInterface $account): AccessResultInterface {
    if ($node->bundle() !== LessonGenerator::COURSE_BUNDLE || $operation !== 'delete') {
      return AccessResult::neutral();
    }

    $access = AccessResult::neutral()
      ->addCacheableDependency($node)
      ->addCacheTags(['lesson_list']);

    if ($this->seasonRepository->hasRecordedLessons($node)) {
      return AccessResult::forbidden()->inheritCacheability($access);
    }

    return $access;
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for node forms.
   *
   * Adds the seasons table to the add and edit forms of courses.
   *
   * @param array<string, mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $course = $form_object->getEntity();
    if (!$course instanceof NodeInterface || $course->bundle() !== LessonGenerator::COURSE_BUNDLE) {
      return;
    }

    $form['seasons'] = [
      '#type' => 'details',
      '#title' => $this->t('Seasons'),
      '#open' => TRUE,
      '#weight' => -5,
    ];

    if ($course->isNew()) {
      $form['seasons']['message'] = [
        '#markup' => $this->t('Save the course first, then add its seasons.'),
      ];
      return;
    }

    $access_handler = $this->entityTypeManager->getAccessControlHandler('course_season');
    $create_access = $access_handler->createAccess(NULL, NULL, [], TRUE);
    \assert($create_access instanceof AccessResultInterface);

    if ($create_access->isAllowed()) {
      $form['seasons']['add'] = [
        '#type' => 'link',
        '#title' => $this->t('Add season'),
        '#url' => Url::fromRoute('entity.course_season.add_form', [], [
          'query' => ['course' => $course->id()],
        ]),
        '#weight' => 10,
        '#attributes' => [
          'class' => ['button', 'button--small', 'button--action', 'button--primary'],
        ],
      ];
    }

    $seasons = $this->loadSeasons($course);
    $counts = $this->seasonRepository->countLessonsBySeason(array_map(
      static fn (SeasonInterface $season): int => (int) $season->id(),
      $seasons,
    ));

    $destination = $course->toUrl('edit-form')->toString();
    $rows = [];
    foreach ($seasons as $season) {
      $operations = [];
      if ($season->access('update')) {
        $operations['edit'] = [
          'title' => $this->t('Edit'),
          'url' => $season->toUrl('edit-form', ['query' => ['destination' => $destination]]),
        ];
      }
      if ($season->access('delete')) {
        $operations['delete'] = [
          'title' => $this->t('Delete'),
          'url' => $season->toUrl('delete-form', ['query' => ['destination' => $destination]]),
        ];
      }

      $rows[] = [
        (string) $season->label(),
        $this->firstLesson($season),
        $this->locationLabel($season),
        $counts[(int) $season->id()] ?? 0,
        ['data' => ['#type' => 'operations', '#links' => $operations]],
      ];
    }

    $form['seasons']['table'] = [
      '#type' => 'table',
      '#weight' => 0,
      '#header' => [
        $this->t('Season'),
        $this->t('First lesson'),
        $this->t('Location'),
        $this->t('Lessons'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No seasons yet.'),
    ];

    CacheableMetadata::createFromRenderArray($form['seasons'])
      ->addCacheableDependency($create_access)
      ->applyTo($form['seasons']);
  }

  /**
   * Loads the seasons of a course in reverse schedule order.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return \Drupal\workspace_events\Entity\SeasonInterface[]
   *   The seasons.
   */
  private function loadSeasons(NodeInterface $course): array {
    $storage = $this->entityTypeManager->getStorage('course_season');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('course', $course->id())
      ->sort('field_schedule.value', 'DESC')
      ->execute();
    if ($ids === []) {
      return [];
    }

    $seasons = [];
    foreach ($storage->loadMultiple($ids) as $season) {
      if ($season instanceof SeasonInterface) {
        $seasons[] = $season;
      }
    }

    return $seasons;
  }

  /**
   * Returns the weekday and time of the first lesson of a season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return string
   *   The formatted first lesson, or an empty string without a schedule.
   */
  private function firstLesson(SeasonInterface $season): string {
    $item = $season->get('field_schedule')->first();
    if ($item === NULL) {
      return '';
    }
    $value = $item->get('value')->getString();
    if ($value === '') {
      return '';
    }

    $date = $this->siteTimeZone->fromStorageValue($value);
    return $this->dateFormatter->format(
      $date->getTimestamp(),
      'custom',
      'D, H:i',
      $this->siteTimeZone->get()->getName(),
    );
  }

  /**
   * Returns the location label of a season.
   *
   * @param \Drupal\workspace_events\Entity\SeasonInterface $season
   *   The season.
   *
   * @return string
   *   The location label, or an empty string when the term is missing.
   */
  private function locationLabel(SeasonInterface $season): string {
    $location = $season->get('location')->entity;
    return $location instanceof TermInterface ? (string) $location->label() : '';
  }

}
