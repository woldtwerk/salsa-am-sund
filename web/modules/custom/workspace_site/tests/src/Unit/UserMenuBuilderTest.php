<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_site\Unit;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\workspace_site\UserAvatar;
use Drupal\workspace_site\UserMenuBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests that the user menu is built for the current user.
 */
#[CoversClass(UserMenuBuilder::class)]
#[Group('workspace_site')]
final class UserMenuBuilderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * Two users must get their own name, initials and user cache context.
   */
  public function testBuildVariesByUser(): void {
    $first = $this->buildForUser(1, 'Alice Example');
    $second = $this->buildForUser(2, 'Bob Example');

    self::assertTrue($first['#props']['is_authenticated']);
    self::assertSame('Alice Example', $first['#props']['name']);
    self::assertSame('Bob Example', $second['#props']['name']);
    self::assertNotSame($first['#props']['initials'], $second['#props']['initials']);

    self::assertContains('user', $first['#cache']['contexts']);
    self::assertContains('user', $second['#cache']['contexts']);
  }

  /**
   * Builds the user menu for one authenticated user.
   *
   * @param int $uid
   *   The user ID.
   * @param string $name
   *   The display name.
   *
   * @return array<mixed>
   *   The user menu render array.
   */
  private function buildForUser(int $uid, string $name): array {
    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('id')->willReturn($uid);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('getAccount')->willReturn($account);

    $user = $this->createMock(UserInterface::class);
    $user->method('getDisplayName')->willReturn($name);
    $user->method('hasField')->with('user_picture')->willReturn(FALSE);
    $user->method('getCacheContexts')->willReturn([]);
    $user->method('getCacheTags')->willReturn(['user:' . $uid]);
    $user->method('getCacheMaxAge')->willReturn(-1);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with($uid)->willReturn($user);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('user')->willReturn($storage);

    $block = $this->createMock(BlockPluginInterface::class);
    $block->method('build')->willReturn(['#items' => [], '#cache' => []]);
    $block->method('getCacheContexts')->willReturn(['route.menu_active_trails:account']);
    $block->method('getCacheTags')->willReturn(['config:system.menu.account']);
    $block->method('getCacheMaxAge')->willReturn(-1);

    $blockManager = $this->createMock(BlockManagerInterface::class);
    $blockManager->method('createInstance')
      ->with('system_menu_block:account')
      ->willReturn($block);

    $avatar = new UserAvatar($this->createMock(FileUrlGeneratorInterface::class));
    $builder = new UserMenuBuilder($blockManager, $entityTypeManager, $currentUser, $avatar);

    return $builder->build(1, 1, FALSE);
  }

}
