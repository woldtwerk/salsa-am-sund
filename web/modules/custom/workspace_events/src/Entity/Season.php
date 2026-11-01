<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;
use Drupal\workspace_events\Form\SeasonForm;
use Drupal\workspace_events\SiteTimeZone;

/**
 * Defines the course season entity class.
 *
 * A course has one season per schedule, for example October to March and
 * April to September. The season owns the lessons that are generated from its
 * schedule.
 */
#[ContentEntityType(
  id: 'course_season',
  label: new TranslatableMarkup('Season'),
  label_collection: new TranslatableMarkup('Seasons'),
  label_singular: new TranslatableMarkup('season'),
  label_plural: new TranslatableMarkup('seasons'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'label' => 'title',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => SeasonAccessControlHandler::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'default' => SeasonForm::class,
      'add' => SeasonForm::class,
      'edit' => SeasonForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'add-form' => '/admin/content/seasons/add',
    'edit-form' => '/admin/content/seasons/{course_season}/edit',
    'delete-form' => '/admin/content/seasons/{course_season}/delete',
  ],
  base_table: 'course_season',
  label_count: [
    'singular' => '@count season',
    'plural' => '@count seasons',
  ],
  constraints: [
    'SeasonScheduleLocked' => [],
  ],
)]
class Season extends ContentEntityBase implements SeasonInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The base field definitions, keyed by field name.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setTranslatable(FALSE);

    $fields['course'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Course'))
      ->setRequired(TRUE)
      ->setCardinality(1)
      ->setSetting('target_type', 'node')
      ->setSetting('handler', 'default:node')
      ->setSetting('handler_settings', ['target_bundles' => ['course' => 'course']])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'match_limit' => 10,
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setTranslatable(FALSE);

    $fields['location'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Location'))
      ->setRequired(TRUE)
      ->setCardinality(1)
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['location' => 'location']])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 3,
      ])
      ->setTranslatable(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    /** @var array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields */
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $title = $this->buildTitle();
    if ($title !== NULL) {
      $this->set('title', $title);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCourse(): ?NodeInterface {
    $course = $this->get('course')->entity;
    return $course instanceof NodeInterface ? $course : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getFirstLessonStart(): ?int {
    if (!$this->hasField('field_schedule')) {
      return NULL;
    }

    $first = NULL;
    foreach ($this->get('field_schedule') as $item) {
      $value = (int) $item->get('value')->getString();
      if ($value <= 0) {
        continue;
      }
      $first = $first === NULL ? $value : min($first, $value);
    }

    return $first;
  }

  /**
   * {@inheritdoc}
   */
  public function getLastLessonEnd(): ?int {
    if (!$this->hasField('field_schedule')) {
      return NULL;
    }

    $last = NULL;
    foreach ($this->get('field_schedule') as $item) {
      $value = (int) $item->get('value')->getString();
      if ($value <= 0) {
        continue;
      }
      $end = (int) $item->get('end_value')->getString();
      if ($end <= 0) {
        $end = $value;
      }
      $last = $last === NULL ? $end : max($last, $end);
    }

    return $last;
  }

  /**
   * {@inheritdoc}
   */
  public function getInterval(): int {
    if (!$this->hasField('field_schedule')) {
      return 1;
    }

    $rule_id = 0;
    foreach ($this->get('field_schedule') as $item) {
      $rrule = (int) $item->get('rrule')->getString();
      if ($rrule > 0) {
        $rule_id = $rrule;
        break;
      }
    }
    if ($rule_id <= 0 || !\Drupal::entityTypeManager()->hasDefinition('smart_date_rule')) {
      return 1;
    }

    $rule = \Drupal::entityTypeManager()->getStorage('smart_date_rule')->load($rule_id);
    if (!$rule instanceof SmartDateRule) {
      return 1;
    }

    $parameters = $rule->getParametersArray();
    if (!is_array($parameters)) {
      return 1;
    }
    $interval = $parameters['interval'] ?? NULL;

    return is_numeric($interval) ? max(1, (int) $interval) : 1;
  }

  /**
   * {@inheritdoc}
   */
  public function hasScheduleChanged(): bool {
    $original = $this->getOriginal();
    if (!$original instanceof self) {
      if ($this->isNew()) {
        return FALSE;
      }
      // Validation runs before the save that assigns the original entity.
      $original = \Drupal::entityTypeManager()
        ->getStorage($this->getEntityTypeId())
        ->loadUnchanged($this->id());
      if (!$original instanceof self) {
        return FALSE;
      }
    }

    return $this->scheduleValues() !== $original->scheduleValues();
  }

  /**
   * Builds the title from the first and last lesson.
   *
   * @return string|null
   *   The title, or NULL when the schedule is incomplete.
   */
  private function buildTitle(): ?string {
    $first = $this->getFirstLessonStart();
    $last = $this->getLastLessonEnd();
    if ($first === NULL || $last === NULL) {
      return NULL;
    }

    $site_timezone = \Drupal::service(SiteTimeZone::class);
    \assert($site_timezone instanceof SiteTimeZone);
    $timezone = $site_timezone->get();
    $formatter = \Drupal::service('date.formatter');
    \assert($formatter instanceof DateFormatterInterface);

    return sprintf(
      '%s – %s',
      $formatter->format($first, 'custom', 'M Y', $timezone->getName()),
      $formatter->format($last, 'custom', 'M Y', $timezone->getName()),
    );
  }

  /**
   * Returns the schedule values that decide whether lessons are synchronized.
   *
   * @return array<int, array{value: string, end_value: string}>
   *   The start and end value of every date.
   */
  private function scheduleValues(): array {
    if (!$this->hasField('field_schedule')) {
      return [];
    }

    $values = [];
    foreach ($this->get('field_schedule') as $item) {
      $values[] = [
        'value' => $item->get('value')->getString(),
        'end_value' => $item->get('end_value')->getString(),
      ];
    }

    return $values;
  }

}
