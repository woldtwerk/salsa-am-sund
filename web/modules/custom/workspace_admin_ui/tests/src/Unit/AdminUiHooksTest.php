<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_admin_ui\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_admin_ui\Hook\AdminUiHooks;
use Drupal\workspace_admin_ui\MasqueradeLinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the library alter and the menu links the module moves.
 *
 * @see \Drupal\workspace_admin_ui\Hook\AdminUiHooks::libraryInfoAlter()
 * @see \Drupal\workspace_admin_ui\Hook\AdminUiHooks::menuLinksDiscoveredAlter()
 * @see web/modules/custom/workspace_admin_ui/css/layout-builder.css
 */
#[Group('workspace_admin_ui')]
#[CoversClass(AdminUiHooks::class)]
class AdminUiHooksTest extends UnitTestCase {

  /**
   * Tests the module's CSS rides along with core's Layout Builder UI library.
   */
  public function testAddsLayoutBuilderLibraryAsDependency(): void {
    $libraries = [
      'drupal.layout_builder' => [
        'dependencies' => ['core/sortable'],
      ],
    ];

    $this->hooks()->libraryInfoAlter($libraries, 'layout_builder');

    $this->assertSame(
      ['core/sortable', 'workspace_admin_ui/layout_builder'],
      $libraries['drupal.layout_builder']['dependencies']
    );
  }

  /**
   * Tests libraries of other extensions are left alone.
   */
  public function testIgnoresOtherExtensions(): void {
    $libraries = ['drupal.layout_builder' => []];

    $this->hooks()->libraryInfoAlter($libraries, 'node');

    $this->assertArrayNotHasKey('dependencies', $libraries['drupal.layout_builder']);
  }

  /**
   * Tests the Content links move into the Navigation content menu.
   */
  public function testMovesContentLinksToContentMenu(): void {
    $links = $this->linkDefinitions();

    $this->hooks()->menuLinksDiscoveredAlter($links);

    foreach ($this->contentLinkIds() as $id) {
      $this->assertSame('content', $links[$id]['menu_name']);
      $this->assertSame('', $links[$id]['parent']);
      // Route, title and description stay core's.
      $this->assertSame('route.' . $id, $links[$id]['route_name']);
      $this->assertSame('Title ' . $id, $links[$id]['title']);
    }
  }

  /**
   * Tests links that a site does not have are left alone.
   */
  public function testIgnoresMissingContentLinks(): void {
    $links = [
      'entity.taxonomy_vocabulary.collection' => [
        'menu_name' => 'admin',
        'parent' => 'system.admin_structure',
        'weight' => -10,
      ],
    ];

    $this->hooks()->menuLinksDiscoveredAlter($links);

    $this->assertArrayNotHasKey('entity.path_alias.collection', $links);
    $this->assertArrayNotHasKey('entity.menu.collection', $links);
    $this->assertSame(10, $links['entity.taxonomy_vocabulary.collection']['weight']);
  }

  /**
   * The menu link ids the module moves.
   *
   * @return list<string>
   *   The plugin ids.
   */
  protected function contentLinkIds(): array {
    return [
      'entity.taxonomy_vocabulary.collection',
      'entity.path_alias.collection',
      'entity.menu.collection',
      'workspace_events.lesson_collection',
    ];
  }

  /**
   * Builds core-like menu link definitions for the moved links.
   *
   * @return array<string, array<string, mixed>>
   *   The definitions, keyed by plugin id.
   */
  protected function linkDefinitions(): array {
    $links = [];
    foreach ($this->contentLinkIds() as $id) {
      $links[$id] = [
        'title' => 'Title ' . $id,
        'description' => 'Description',
        'route_name' => 'route.' . $id,
        'menu_name' => 'admin',
        'parent' => 'system.admin_structure',
        'weight' => -10,
        'options' => [],
      ];
    }

    return $links;
  }

  /**
   * Builds the hook implementation.
   *
   * @return \Drupal\workspace_admin_ui\Hook\AdminUiHooks
   *   The hook implementation.
   */
  protected function hooks(): AdminUiHooks {
    return new AdminUiHooks(
      new MasqueradeLinks($this->createMock(EntityTypeManagerInterface::class)),
    );
  }

}
