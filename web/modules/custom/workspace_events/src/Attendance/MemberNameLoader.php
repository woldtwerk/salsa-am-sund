<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_events\AttendanceRecognition;

/**
 * Loads all members once per request.
 */
final class MemberNameLoader implements MemberNameLoaderInterface {

  /**
   * The loaded names, initialized on first use.
   *
   * @var array<int, string>|null
   */
  private ?array $names = NULL;

  /**
   * Constructs a MemberNameLoader.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    if ($this->names !== NULL) {
      return $this->names;
    }

    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('roles', AttendanceRecognition::MEMBER_ROLE)
      ->sort('name')
      ->execute();

    $names = [];
    /** @var \Drupal\user\UserInterface $user */
    foreach ($storage->loadMultiple($ids) as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }
      $names[(int) $user->id()] = (string) $user->getDisplayName();
    }

    return $this->names = $names;
  }

}
