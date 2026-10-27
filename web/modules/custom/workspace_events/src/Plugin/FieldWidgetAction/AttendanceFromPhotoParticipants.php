<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\FieldWidgetAction;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_automators\Plugin\FieldWidgetAction\AutomatorBaseAction;
use Drupal\field_widget_actions\Attribute\FieldWidgetAction;
use Drupal\user\UserInterface;
use Drupal\workspace_events\AttendanceRecognition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds the attendance recognition button to the participant field.
 */
#[FieldWidgetAction(
  id: 'attendance_from_photo_participants',
  label: new TranslatableMarkup('Teilnehmer aus Foto erkennen'),
  widget_types: [
    'entity_reference_autocomplete',
    'entity_reference_autocomplete_tags',
  ],
  field_types: ['entity_reference'],
  category: new TranslatableMarkup('Anwesenheit'),
)]
final class AttendanceFromPhotoParticipants extends AutomatorBaseAction {

  /**
   * The property of the autocomplete element that holds the reference.
   */
  public const FORM_ELEMENT_PROPERTY = 'target_id';

  /**
   * The current user.
   */
  protected ?AccountProxyInterface $currentUser = NULL;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    \assert($instance instanceof self);
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    if ($this->currentUser === NULL || !$this->currentUser->hasPermission(AttendanceRecognition::PERMISSION)) {
      return FALSE;
    }
    return parent::isAvailable();
  }

  /**
   * {@inheritdoc}
   *
   * The recognition fills the whole field from one photo, so the button is
   * shown once per field instead of once per delta.
   */
  public function getMultiple(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * The tags widget renders one text input for all values and expects the
   * comma separated "Name (ID)" format instead of one element per delta.
   */
  protected function setFormInput(FieldableEntityInterface $entity, FormStateInterface $form_state, $form_key): void {
    if (!$this->widgetHandlesMultipleValues($form_state, $form_key)) {
      parent::setFormInput($entity, $form_state, $form_key);
      return;
    }

    $tags = [];
    foreach ($entity->get($form_key) as $item) {
      $user = $item->get('entity')->getValue();
      if (!$user instanceof UserInterface) {
        continue;
      }
      $tags[] = $user->getDisplayName() . ' (' . $user->id() . ')';
    }

    $input = $form_state->getUserInput();
    $fieldInput = $input[$form_key] ?? [];
    if (!is_array($fieldInput)) {
      $fieldInput = [];
    }
    $fieldInput[self::FORM_ELEMENT_PROPERTY] = implode(', ', $tags);
    $input[$form_key] = $fieldInput;
    $form_state->setUserInput($input);
  }

}
