<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspace_events\SeasonRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controls access to course seasons.
 */
final class SeasonAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * The permission to create and edit seasons.
   */
  public const string EDIT_PERMISSION = 'edit course seasons';

  /**
   * The permission to delete seasons.
   */
  public const string DELETE_PERMISSION = 'delete course seasons';

  /**
   * Constructs a SeasonAccessControlHandler.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\workspace_events\SeasonRepository $seasonRepository
   *   The season repository.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    protected readonly SeasonRepository $seasonRepository,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   *
   * @return static
   *   The access control handler.
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $repository = $container->get(SeasonRepository::class);
    \assert($repository instanceof SeasonRepository);

    return new static($entity_type, $repository);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if (!$entity instanceof SeasonInterface) {
      $access = parent::checkAccess($entity, $operation, $account);
      \assert($access instanceof AccessResultInterface);
      return $access;
    }

    if (!in_array($operation, ['view', 'update', 'delete'], TRUE)) {
      $access = parent::checkAccess($entity, $operation, $account);
      \assert($access instanceof AccessResultInterface);
      return $access;
    }

    if ($operation === 'view') {
      $access = AccessResult::allowedIfHasPermission($account, 'access content');
    }
    elseif ($operation === 'delete') {
      $access = AccessResult::allowedIfHasPermission($account, self::DELETE_PERMISSION);
      if ($access->isAllowed()) {
        $access = $access->andIf(AccessResult::allowedIf(!$this->seasonRepository->hasRecordedLessons($entity)));
      }
    }
    else {
      $access = AccessResult::allowedIfHasPermission($account, self::EDIT_PERMISSION);
    }

    $access = $access->addCacheableDependency($entity);
    if ($operation === 'delete') {
      $access = $access->addCacheTags(['lesson_list']);
    }

    return $access;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   * @param array<string, mixed> $context
   *   Additional context for the access check, including the entity bundle.
   * @param string|null $entity_bundle
   *   The bundle of the entity, or NULL for entity types without bundles.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, self::EDIT_PERMISSION)
      ->cachePerPermissions();
  }

}
