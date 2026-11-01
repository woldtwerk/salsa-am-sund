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

    $fields['last'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Last lesson'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', 'date')
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 1,
      ])
      ->setTranslatable(FALSE);

    $fields['interval'] = BaseFieldDefinition::create('list_integer')
      ->setLabel(new TranslatableMarkup('Interval'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setSetting('allowed_values', [
        1 => new TranslatableMarkup('Weekly'),
        2 => new TranslatableMarkup('Every 2 weeks'),
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 2,
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
    if (!$this->hasField('field_schedule')) {
      return NULL;
    }

    $schedule = $this->get('field_schedule')->first();
    $last = $this->get('last')->first();
    if ($schedule === NULL || $last === NULL) {
      return NULL;
    }

    $start_value = $schedule->get('value')->getString();
    $last_value = $last->get('value')->getString();
    if ($start_value === '' || $last_value === '') {
      return NULL;
    }

    $site_timezone = \Drupal::service(SiteTimeZone::class);
    \assert($site_timezone instanceof SiteTimeZone);
    $timezone = $site_timezone->get();
    $start = $site_timezone->fromStorageValue($start_value);
    $last_day = \DateTimeImmutable::createFromFormat('!Y-m-d', $last_value, $timezone);
    if ($last_day === FALSE) {
      return NULL;
    }

    $formatter = \Drupal::service('date.formatter');
    \assert($formatter instanceof DateFormatterInterface);
    return sprintf(
      '%s – %s',
      $formatter->format($start->getTimestamp(), 'custom', 'M Y', $timezone->getName()),
      $formatter->format($last_day->getTimestamp(), 'custom', 'M Y', $timezone->getName()),
    );
  }

  /**
   * Returns the schedule values that decide whether lessons are regenerated.
   *
   * @return array<string, string>
   *   The schedule values, keyed by field and property.
   */
  private function scheduleValues(): array {
    $schedule = $this->hasField('field_schedule') ? $this->get('field_schedule')->first() : NULL;

    return [
      'field_schedule.value' => $schedule?->get('value')->getString() ?? '',
      'field_schedule.end_value' => $schedule?->get('end_value')->getString() ?? '',
      'last' => $this->get('last')->getString(),
      'interval' => $this->get('interval')->getString(),
    ];
  }

}
