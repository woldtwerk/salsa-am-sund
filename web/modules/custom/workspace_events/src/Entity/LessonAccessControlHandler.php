<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Controls access to lessons based on their participants and editors.
 */
final class LessonAccessControlHandler extends EntityAccessControlHandler {

  /**
   * The permission of the lesson editors.
   */
  public const string EDIT_PERMISSION = 'edit lessons';

  /**
   * The permission to delete lessons.
   */
  public const string DELETE_PERMISSION = 'delete lessons';

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if (!$entity instanceof LessonInterface) {
      return parent::checkAccess($entity, $operation, $account);
    }

    return match ($operation) {
      'view' => $this->checkViewAccess($entity, $account),
      'update' => AccessResult::allowedIfHasPermission($account, self::EDIT_PERMISSION),
      'delete' => AccessResult::allowedIfHasPermission($account, self::DELETE_PERMISSION),
      default => parent::checkAccess($entity, $operation, $account),
    };
  }

  /**
   * Checks whether an account may view a lesson.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $entity
   *   The lesson.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  private function checkViewAccess(LessonInterface $entity, AccountInterface $account): AccessResultInterface {
    $access = $account->hasPermission(self::EDIT_PERMISSION)
      ? AccessResult::allowed()
      : AccessResult::allowedIf($entity->isPublished() && $this->isParticipant($entity, $account));

    return $access
      ->addCacheContexts(['user'])
      ->cachePerPermissions()
      ->addCacheableDependency($entity);
  }

  /**
   * Returns whether an account is listed as a participant of a lesson.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $entity
   *   The lesson.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return bool
   *   TRUE when the account is a participant of the lesson.
   */
  private function isParticipant(LessonInterface $entity, AccountInterface $account): bool {
    $account_id = (int) $account->id();
    if ($account_id <= 0 || !$entity->hasField('field_participants')) {
      return FALSE;
    }

    foreach ($entity->get('field_participants') as $item) {
      if ((int) $item->get('target_id')->getString() === $account_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>|null $items
   *   The field values, if available.
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL) {
    if ($field_definition->getName() !== 'field_participants' || !in_array($operation, ['view', 'edit'], TRUE)) {
      return parent::checkFieldAccess($operation, $field_definition, $account, $items);
    }

    $access = $account->hasPermission(self::EDIT_PERMISSION)
      ? AccessResult::allowed()
      : AccessResult::forbidden();

    return $access
      ->addCacheableDependency($field_definition)
      ->cachePerPermissions();
  }

}
