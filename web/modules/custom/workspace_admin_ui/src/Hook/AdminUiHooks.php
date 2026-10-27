<?php

declare(strict_types=1);

namespace Drupal\workspace_admin_ui\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\workspace_admin_ui\MasqueradeLinks;

/**
 * Hook implementations for the Admin UI module.
 */
class AdminUiHooks {

  use StringTranslationTrait;

  /**
   * The icon pack defined in workspace_admin_ui.icons.yml.
   */
  private const ICON_PACK = 'workspace_admin_ui';

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\workspace_admin_ui\MasqueradeLinks $masqueradeLinks
   *   The masquerade link helper.
   */
  public function __construct(
    protected MasqueradeLinks $masqueradeLinks,
  ) {
  }

  /**
   * Implements hook_toolbar().
   *
   * The Masquerade tab is one click into the accounts the site is built
   * for, so stepping into a user or an editor is one click from anywhere in
   * the admin rather than a trip to /admin/people. It carries the accounts in
   * its tray rather than as tabs of their own — names with nothing above them
   * would say nothing about what clicking them does.
   *
   * @return array<string, mixed>
   *   The toolbar items.
   */
  #[Hook('toolbar')]
  public function toolbar(): array {
    $item = [
      '#cache' => [
        'contexts' => ['user.permissions', 'session.is_masquerading'],
        'tags' => $this->masqueradeLinks->cacheTags(),
      ],
    ];

    $links = [];
    $shortcuts = [
      MasqueradeLinks::USER => $this->t('User'),
      MasqueradeLinks::EDITOR => $this->t('Editor'),
    ];
    foreach ($this->masqueradeLinks->available() as $name => $account) {
      $links[] = [
        'title' => $shortcuts[$name],
        'url' => $account->toUrl('masquerade'),
        'attributes' => [
          'title' => $this->t('Masquerade as @name', ['@name' => $account->getDisplayName()]),
        ],
      ];
    }

    // Nothing to offer: none of the accounts is there, or the current user may
    // not masquerade as any — while already masquerading, most of all, which
    // is what `session.is_masquerading` above varies on.
    return [
      'workspace_admin_ui_masquerade' => $links === [] ? $item : $item + [
        '#type' => 'toolbar_item',
        'tab' => [
          '#type' => 'link',
          '#title' => $this->t('Masquerade'),
          // Where the tab itself goes, and what it falls back to without the
          // JavaScript that opens the tray: masquerade's own form, which can
          // reach every account rather than these two.
          '#url' => Url::fromRoute('masquerade.block'),
          '#attributes' => [
            'title' => $this->t('Masquerade as a user or an editor'),
            'class' => ['toolbar-icon', 'toolbar-icon-masquerade'],
          ],
        ],
        'tray' => [
          '#heading' => $this->t('Masquerade'),
          'links' => [
            '#theme' => 'links__toolbar_masquerade',
            '#links' => $links,
            '#attributes' => ['class' => ['toolbar-menu']],
          ],
        ],
        '#weight' => 110,
        '#attached' => ['library' => ['workspace_admin_ui/toolbar']],
      ],
    ];
  }

  /**
   * Implements hook_toolbar_alter().
   *
   * @param array<string, mixed> $items
   *   The toolbar items, keyed by item id.
   */
  #[Hook('toolbar_alter')]
  public function toolbarAlter(array &$items): void {
    // The Unmasquerade action lives in the user tray, not in its own tab.
    unset($items['masquerade_switch_back']);
  }

  /**
   * Implements hook_library_info_alter().
   *
   * Core's Layout Builder UI is painted in fixed light colours, which stay
   * light in gin's dark mode. The module's CSS points them at gin's custom
   * properties, and rides along as a dependency of core's UI library so it is
   * there exactly where the builder is — and nowhere else.
   *
   * A dependency rather than appending the stylesheet to core's definition:
   * the file then stays declared in this module's libraries.yml, where its
   * path is relative to the module, instead of being spelled out as a
   * DRUPAL_ROOT-relative path here. Dependencies resolve before the library
   * that needs them, so this CSS is emitted before core's; every rule in it is
   * scoped to body[data-gin-accent] and so outranks core's selectors either
   * way.
   *
   * @param array<string, mixed> $libraries
   *   The library definitions of the extension, keyed by library name.
   * @param string $extension
   *   The name of the extension whose libraries are being altered.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension !== 'layout_builder' || !isset($libraries['drupal.layout_builder'])) {
      return;
    }
    $library = $libraries['drupal.layout_builder'];
    if (!is_array($library)) {
      return;
    }

    $library['dependencies'] = array_merge(
      is_array($library['dependencies'] ?? NULL) ? $library['dependencies'] : [],
      ['workspace_admin_ui/layout_builder'],
    );
    $libraries['drupal.layout_builder'] = $library;
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   *
   * Taxonomy, URL aliases and menus are day-to-day editing, not site
   * building, so they leave Structure and Configuration for the Content
   * section of the Navigation sidebar. Navigation's Content block renders the
   * `content` menu, and its admin block starts below `system.admin_content`,
   * which Navigation removes. The links are therefore moved into `content`
   * rather than tucked under the admin menu.
   *
   * Navigation takes an item's icon from its URL options, and falls back to an
   * icon of the same name in the `navigation` pack. These links have no icon
   * there, so they point at the module's pack, which discovers `icons/`.
   *
   * @param array<string, array<string, mixed>> $links
   *   The menu link definitions, keyed by plugin id.
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(array &$links): void {
    foreach (self::contentLinks() as $id => $link) {
      if (!isset($links[$id])) {
        continue;
      }

      // The parent belongs to the admin menu and does not exist in `content`;
      // an empty parent puts the link at the top level, after Media.
      $links[$id]['menu_name'] = 'content';
      $links[$id]['parent'] = '';
      $links[$id]['weight'] = $link['weight'];
      $options = $links[$id]['options'] ?? [];
      if (!is_array($options)) {
        $options = [];
      }
      $options['icon'] = [
        'pack_id' => self::ICON_PACK,
        'icon_id' => $link['icon'],
      ];
      $links[$id]['options'] = $options;
    }
  }

  /**
   * The admin links that belong under Content, in the order they appear.
   *
   * @return array<string, array{icon: string, weight: int}>
   *   The link's icon id and menu weight, keyed by menu link plugin id, which
   *   is also the route name. Core's own link definitions keep their titles,
   *   descriptions and access checks.
   */
  private static function contentLinks(): array {
    return [
      // Media sits at weight 0; these follow it, before the trash at 1000.
      'entity.taxonomy_vocabulary.collection' => ['icon' => 'taxonomy', 'weight' => 10],
      'entity.path_alias.collection' => ['icon' => 'url-aliases', 'weight' => 11],
      'entity.menu.collection' => ['icon' => 'menus', 'weight' => 12],
      'workspace_events.lesson_collection' => ['icon' => 'lessons', 'weight' => 13],
    ];
  }

}
