<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\AiAutomatorType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_automators\Attribute\AiAutomatorType;
use Drupal\ai_automators\Exceptions\AiAutomatorRequestErrorException;
use Drupal\ai_automators\PluginBaseClasses\ExternalBase;
use Drupal\ai_automators\PluginInterfaces\AiAutomatorTypeInterface;
use Drupal\file\FileInterface;
use Drupal\workspace_events\Attendance\AttendanceRecognitionException;
use Drupal\workspace_events\Attendance\AttendanceRecognizer;
use Drupal\workspace_events\Attendance\MemberMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fills a participant reference from an attendance photo.
 *
 * The photo is read with Mistral OCR and the recognized names are matched
 * locally, so the member list is never sent to the provider.
 */
#[AiAutomatorType(
  id: 'attendance_from_photo',
  label: new TranslatableMarkup('Anwesenheit aus Foto'),
  field_rule: 'entity_reference',
  target: 'user',
)]
final class AttendanceFromPhoto extends ExternalBase implements AiAutomatorTypeInterface, ContainerFactoryPluginInterface {

  /**
   * The title shown in the automator selection.
   *
   * @var string
   */
  public $title = 'Anwesenheit aus Foto';

  /**
   * Constructs an AttendanceFromPhoto automator.
   *
   * @param \Drupal\workspace_events\Attendance\AttendanceRecognizer $recognizer
   *   The attendance photo recognizer.
   * @param \Drupal\workspace_events\Attendance\MemberMatcher $memberMatcher
   *   The member matcher.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    private readonly AttendanceRecognizer $recognizer,
    private readonly MemberMatcher $memberMatcher,
    private readonly MessengerInterface $messenger,
  ) {}

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
    return new static(
      $container->get(AttendanceRecognizer::class),
      $container->get(MemberMatcher::class),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function needsPrompt(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function advancedMode(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The field types that can be used as input.
   */
  public function allowedInputs(): array {
    return ['image'];
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being processed.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field definition.
   * @param array<string, mixed> $automatorConfig
   *   The automator configuration.
   *
   * @return array<array{target_id: int}>
   *   The matched user references.
   */
  public function generate(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): array {
    $baseField = $automatorConfig['base_field'] ?? NULL;
    if (!is_string($baseField) || $baseField === '' || !$entity->hasField($baseField)) {
      return [];
    }

    $photo = $entity->get($baseField)->entity;
    if (!$photo instanceof FileInterface) {
      return [];
    }

    try {
      $names = $this->recognizer->recognize($photo);
    }
    catch (AttendanceRecognitionException $exception) {
      throw new AiAutomatorRequestErrorException($exception->getMessage(), 0, $exception);
    }

    if ($names === []) {
      $this->messenger->addWarning($this->t('Auf dem Anwesenheitsfoto wurden keine Namen erkannt.'));
      return [];
    }

    $result = $this->memberMatcher->match($names);
    if ($result['unmatched'] !== []) {
      $this->messenger->addWarning($this->t('Nicht eindeutig erkannt: @names', [
        '@names' => implode(', ', $result['unmatched']),
      ]));
    }

    return array_map(
      static fn (int $id): array => ['target_id' => $id],
      $result['matched'],
    );
  }

  /**
   * {@inheritdoc}
   *
   * Existing participants are kept and the new matches are added without
   * duplicates.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity being processed.
   * @param array<int, array{target_id?: int|string}> $values
   *   The generated values.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field definition.
   * @param array<string, mixed> $automatorConfig
   *   The automator configuration.
   */
  public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $fieldDefinition, array $automatorConfig): void {
    $ids = [];
    foreach ($entity->get($fieldDefinition->getName()) as $item) {
      $id = $item->get('target_id')->getValue();
      if (is_numeric($id)) {
        $ids[(int) $id] = (int) $id;
      }
    }

    foreach ($values as $value) {
      $id = is_array($value) ? ($value['target_id'] ?? NULL) : NULL;
      if (is_numeric($id)) {
        $ids[(int) $id] = (int) $id;
      }
    }

    unset($ids[0]);
    $entity->set($fieldDefinition->getName(), array_values($ids));
  }

}
