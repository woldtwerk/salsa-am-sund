<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;
use Drupal\workspace_events\Form\LessonForm;

/**
 * Defines the lesson entity class.
 */
#[ContentEntityType(
  id: 'lesson',
  label: new TranslatableMarkup('Lesson'),
  label_collection: new TranslatableMarkup('Lessons'),
  label_singular: new TranslatableMarkup('lesson'),
  label_plural: new TranslatableMarkup('lessons'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'langcode' => 'langcode',
    'label' => 'title',
    'owner' => 'uid',
    'published' => 'status',
  ],
  handlers: [
    'access' => LessonAccessControlHandler::class,
    'views_data' => LessonViewsData::class,
    'list_builder' => EntityListBuilder::class,
    'form' => [
      'default' => LessonForm::class,
      'edit' => LessonForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'edit-form' => '/admin/content/lessons/{lesson}/edit',
    'delete-form' => '/admin/content/lessons/{lesson}/delete',
    'delete-multiple-form' => '/admin/content/lessons/delete',
    'collection' => '/admin/content/lessons',
  ],
  admin_permission: 'administer lessons',
  base_table: 'lesson',
  label_count: [
    'singular' => '@count lesson',
    'plural' => '@count lessons',
  ],
)]
class Lesson extends ContentEntityBase implements LessonInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The base field definitions, keyed by field name.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $published_fields = static::publishedBaseFieldDefinitions($entity_type);
    $published_fields['status']
      ->setRevisionable(FALSE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE);
    $fields += $published_fields;

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('form', TRUE);

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
  public function getCreatedTime(): int {
    return (int) $this->get('created')->getString();
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime(int $timestamp): static {
    $this->set('created', $timestamp);
    return $this;
  }

}
