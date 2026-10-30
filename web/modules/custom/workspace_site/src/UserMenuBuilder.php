<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Template\Attribute;
use Drupal\user\UserInterface;

/**
 * Builds the user menu in the site header from the account menu block.
 *
 * The header is shared between users, so the account menu block is rendered
 * through a lazy builder: BigPipe replaces the placeholder with the current
 * user's avatar and links.
 */
final class UserMenuBuilder implements TrustedCallbackInterface {

  /**
   * The block plugin that renders the account menu.
   */
  public const string BLOCK_PLUGIN_ID = 'system_menu_block:account';

  /**
   * Constructs the user menu builder.
   *
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   The block plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\workspace_site\UserAvatar $userAvatar
   *   The avatar resolver.
   */
  public function __construct(
    private readonly BlockManagerInterface $blockManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly UserAvatar $userAvatar,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['build'];
  }

  /**
   * Builds the user menu component.
   *
   * The arguments are the account block's own menu settings from the Canvas
   * component instance. They are scalars so the render array can be passed to
   * a lazy builder.
   *
   * @param int $level
   *   The initial visibility level of the account menu.
   * @param int $depth
   *   The number of menu levels to display.
   * @param bool $expand_all_items
   *   Whether all menu items are expanded.
   *
   * @return array<mixed>
   *   The render array of the user menu.
   */
  public function build(int $level = 1, int $depth = 1, bool $expand_all_items = FALSE): array {
    $configuration = [
      'id' => self::BLOCK_PLUGIN_ID,
      'provider' => 'system',
      'label' => 'User account menu',
      'label_display' => '0',
      'level' => $level,
      'depth' => $depth,
      'expand_all_items' => $expand_all_items,
    ];

    $block = $this->blockManager->createInstance(self::BLOCK_PLUGIN_ID, $configuration);
    \assert($block instanceof BlockPluginInterface);
    $menu = $block->build();
    $this->prepareAccountMenu($menu);

    $cache = CacheableMetadata::createFromObject($block);
    $cache->merge(CacheableMetadata::createFromRenderArray($menu));
    // The rendered link text, avatar and logout token depend on the user.
    $cache->addCacheContexts(['user']);

    $props = [
      'is_authenticated' => FALSE,
      'name' => '',
      'initials' => '',
    ];

    $account = $this->currentUser->getAccount();
    if ($account->isAuthenticated()) {
      $user = $this->entityTypeManager->getStorage('user')->load($account->id());
      if ($user instanceof UserInterface) {
        $avatar = $this->userAvatar->get($user);
        $props['is_authenticated'] = TRUE;
        $props['name'] = (string) $user->getDisplayName();
        $props['initials'] = $avatar['initials'];
        if ($avatar['picture'] !== []) {
          $props['picture'] = $avatar['picture'];
        }
        $cache->merge($this->userAvatar->getCacheability($user));
      }
    }

    /** @var array<string, mixed> $build */
    $build = [
      '#type' => 'component',
      '#component' => 'salsa:user-menu',
      '#props' => $props,
      '#slots' => [
        'menu' => $menu,
      ],
    ];
    $cache->applyTo($build);

    return $build;
  }

  /**
   * Moves the logout link to the end and marks it as a separate item.
   *
   * @param array<mixed> $menu
   *   The rendered account menu, passed by reference.
   *
   * @param-out array<mixed> $menu
   */
  private function prepareAccountMenu(array &$menu): void {
    $items = $menu['#items'] ?? NULL;
    if (!is_array($items)) {
      return;
    }

    $logout = NULL;
    foreach ($items as $key => $item) {
      if (!is_array($item)) {
        continue;
      }
      $link = $item['original_link'] ?? NULL;
      if ($link instanceof MenuLinkInterface && $link->getRouteName() === 'user.logout') {
        $logout = $item;
        unset($items[$key]);
        break;
      }
    }
    if ($logout === NULL) {
      return;
    }

    $attributes = $logout['attributes'] ?? NULL;
    $logout['attributes'] = $attributes instanceof Attribute
      ? $attributes->addClass('salsa-user-menu__logout')
      : new Attribute(['class' => ['salsa-user-menu__logout']]);
    $items[] = $logout;
    $menu['#items'] = $items;
  }

}
