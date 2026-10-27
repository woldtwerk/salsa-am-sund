<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\LessonGenerator;

/**
 * Restricts the access to lessons to their participants and editors.
 *
 * The lesson grant records are the only records this module writes. Every
 * other bundle keeps the default grant of the node module.
 */
final class LessonAccessHooks {

  /**
   * The node grant realm of the lesson participants.
   */
  public const string PARTICIPANT_REALM = 'workspace_events_lesson_participant';

  /**
   * The node grant realm of the lesson editors.
   */
  public const string EDITOR_REALM = 'workspace_events_lesson_editor';

  /**
   * The permission of the content editors.
   */
  public const string EDIT_PERMISSION = 'edit any lesson content';

  /**
   * The fields that only editors may see and edit.
   *
   * @var string[]
   */
  private const array EDITOR_FIELDS = [
    'field_participants',
    'field_attendance_photo',
  ];

  /**
   * Implements hook_node_grants().
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param string $operation
   *   The operation being performed.
   *
   * @return array<string, int[]>
   *   The grant IDs, keyed by realm.
   */
  #[Hook('node_grants')]
  public function nodeGrants(AccountInterface $account, string $operation): array {
    if ($operation !== 'view') {
      return [];
    }

    $grants = [];
    if ($account->hasPermission(self::EDIT_PERMISSION)) {
      $grants[self::EDITOR_REALM] = [1];
    }
    if ($account->isAuthenticated()) {
      $grants[self::PARTICIPANT_REALM] = [(int) $account->id()];
    }
    return $grants;
  }

  /**
   * Implements hook_node_access_records().
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return array<int, array{realm: string, gid: int, grant_view: int, grant_update: int, grant_delete: int}>
   *   The grant records.
   */
  #[Hook('node_access_records')]
  public function nodeAccessRecords(NodeInterface $node): array {
    if ($node->bundle() !== LessonGenerator::LESSON_BUNDLE) {
      return [];
    }

    $records = [
      [
        'realm' => self::EDITOR_REALM,
        'gid' => 1,
        'grant_view' => 1,
        'grant_update' => 0,
        'grant_delete' => 0,
      ],
    ];

    if (!$node->hasField('field_participants')) {
      return $records;
    }

    $participant_ids = [];
    foreach ($node->get('field_participants') as $item) {
      $target_id = $item->get('target_id')->getString();
      if ($target_id === '' || !ctype_digit($target_id)) {
        continue;
      }
      $participant_id = (int) $target_id;
      if ($participant_id > 0 && !in_array($participant_id, $participant_ids, TRUE)) {
        $participant_ids[] = $participant_id;
      }
    }
    foreach ($participant_ids as $participant_id) {
      $records[] = [
        'realm' => self::PARTICIPANT_REALM,
        'gid' => $participant_id,
        'grant_view' => 1,
        'grant_update' => 0,
        'grant_delete' => 0,
      ];
    }

    return $records;
  }

  /**
   * Implements hook_entity_field_access().
   *
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>|null $items
   *   The field values, if available.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if (!in_array($operation, ['view', 'edit'], TRUE)) {
      return AccessResult::neutral();
    }
    if ($field_definition->getTargetEntityTypeId() !== 'node' || $field_definition->getTargetBundle() !== LessonGenerator::LESSON_BUNDLE) {
      return AccessResult::neutral();
    }
    if (!in_array($field_definition->getName(), self::EDITOR_FIELDS, TRUE)) {
      return AccessResult::neutral();
    }

    $access = $account->hasPermission(self::EDIT_PERMISSION)
      ? AccessResult::allowed()
      : AccessResult::forbidden();

    return $access
      ->addCacheableDependency($field_definition)
      ->cachePerPermissions();
  }

}
