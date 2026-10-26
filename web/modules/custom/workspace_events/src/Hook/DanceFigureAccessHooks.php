<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Restricts access to the dance figure terms.
 */
final class DanceFigureAccessHooks {

  /**
   * The machine name of the dance figure vocabulary.
   */
  public const string VOCABULARY = 'dance_figure';

  /**
   * The permission required to view dance figures.
   */
  public const string VIEW_PERMISSION = 'view dance figures';

  /**
   * Implements hook_taxonomy_term_access().
   *
   * The dance figures are only visible to members and editors. Other
   * vocabularies keep their normal taxonomy access.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The taxonomy term.
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  #[Hook('taxonomy_term_access')]
  public function taxonomyTermAccess(TermInterface $term, string $operation, AccountInterface $account): AccessResultInterface {
    if ($term->bundle() !== self::VOCABULARY || $operation !== 'view') {
      return AccessResult::neutral();
    }

    return AccessResult::forbiddenIf(!$account->hasPermission(self::VIEW_PERMISSION))
      ->cachePerPermissions();
  }

}
