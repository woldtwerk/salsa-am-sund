# Admin UI

## Functionality

- add the _Masquerade_ tab with its two shortcuts, _User_ and _Editor_, to
  the toolbar
- add _Masquerade as User_ and _Masquerade as Editor_ to the user popover in
  the footer of core's Navigation sidebar
- add the _Unmasquerade_ link to the toolbar user menu
  (`#toolbar-item-user-tray`), next to _View profile_, _Edit profile_ and
  _Log out_, and to the Navigation user popover
- keep the toolbar and the Navigation sidebar for an admin masquerading as an
  account without `access toolbar` / `access navigation`
- drop the status message masquerade shows after a switch
- move _Taxonomy_, _Menus_ (both from _Structure_), _URL aliases_ (from
  _Configuration_) and _Unterrichtsstunden_ (from _Content_) to the Content
  section of the Navigation sidebar, after _Media_
- keep Layout Builder in gin's palette, so its canvas, drop zones and remove
  button are dark in dark mode instead of white

## The masquerade shortcuts

`hook_toolbar()` adds a _Masquerade_ tab whose tray holds a _User_ and an
_Editor_ link into `entity.user.masquerade`. One tab rather than two: names
sitting in the toolbar with nothing above them say nothing about what clicking
them does. The tab itself goes to `/masquerade`, masquerade's own form, which
is both a sensible target and the fallback where the JavaScript that opens the
tray has not run.

The same two shortcuts appear in the user popover of core's Navigation
sidebar. `workspace_admin_ui.links.menu.yml` defines them in the
`navigation-user-links` menu, which `NavigationUserBlock` renders in the
footer. A menu link definition cannot carry a uid: a uid is a fact about one
database, and the account behind a name may be recreated with another id. The
definitions therefore carry the name, and `MasqueradeMenuLink` resolves it to
the route's `user` parameter when the link is used. `MasqueradeLinks` holds the
name lookup, the access check and the cache tags for both surfaces, so they
cannot drift apart.

The links carry no `destination`: `SwitchController` then sends you back to
the referrer if the account you stepped into may see it, and to `<front>`
otherwise.

Each link asks `masquerade_target_user_access()` and is left out when the
answer is no — the account does not exist, or the current user may not
masquerade as it, or a masquerade is already running, which takes every link
and with them the whole tab away and leaves _Unmasquerade_ as the only move.
The toolbar item carries `user.permissions`, `session.is_masquerading` and the
accounts' cache tags — `user_list` for a name no account holds, so the link
appears once one does. The menu links carry the same contexts and tags.

Menu links are cached in the menu tree, so the answer cannot be baked into a
definition. Masquerade's switch route only requires a CSRF token, which needs
the incoming request; a menu tree cannot evaluate that and would drop every
link to the route as inaccessible. `RouteSubscriber` adds
`MasqueradeTargetAccessCheck` to the route as a second requirement, so the
menu tree decides whether to show a link and a request without permission is
refused before the controller runs.

Gin's secondary toolbar renders every toolbar tab except the main menu one, so
the tab needs nothing beyond `#type: toolbar_item` to land there. The CSRF
token the route requires is added by core's outbound route processor.

The icon is `icons/masquerade.svg`, attached as `workspace_admin_ui/toolbar` and picked
up through `--icon` on `.toolbar-icon-masquerade::before`, the custom property
gin masks the pseudo-element with — so the shape is all the file carries and
the colour follows the theme into dark mode.

## The Unmasquerade link

`ToolbarLinkBuilder` decorates core's `user.toolbar_link_builder` and inserts
the link above _Log out_ while masquerading; the tray varies by
`session.is_masquerading`. `hook_toolbar_alter()` drops masquerade's own
standalone toolbar tab so the action appears once, in the user menu.

For the Navigation sidebar, `workspace_admin_ui.links.menu.yml` adds an
_Unmasquerade_ link to `navigation-user-links`. It reuses
`masquerade.unmasquerade`, whose route already refuses access except while
masquerading, so the link is hidden by the menu tree's access check and needs
no custom class.

## The toolbar and sidebar while masquerading

`MasqueradeToolbarAccessPolicy` is an access policy that grants `access
toolbar` and `access navigation` to the masqueraded account, so the toolbar,
the Navigation sidebar and _Unmasquerade_ in them stay when an admin steps into
`user`, which holds no role. Core, gin and gin_toolbar check `access toolbar`;
core's Navigation module checks `access navigation`, so both are granted in the
one place that reaches them all.

The account gets them only for as long as the masquerade runs, and no other
account does: permissions for other accounts are calculated in the same
request too, so the policy compares the account against the session's `uid`.
It adds `session.is_masquerading` as a persistent cache context, and `user`
only while masquerading. Without `user`, the result calculated for one account
would be served to another account with the same roles. The context is only
added for requests with masquerade's own session bag: Drush and other CLI
requests carry Symfony's metadata bag, where the context throws.

## The switch message

`MasqueradeMessageSubscriber` takes masquerade's "You are now masquerading
as @user" off the session at `kernel.response`, on `entity.user.masquerade`
and `masquerade.unmasquerade`. Only the status type goes — the same controller
reports a refused switch as an error. Nothing else runs on those two routes,
so there is no other status message to take with it.

## Taxonomy, URL aliases, menus and lessons under Content

Core's Navigation module renders the Content section from the `content` menu,
not from the Content subtree of the admin menu: its admin block starts at
level 2, and `NavigationHooks` removes `system.admin_content` entirely. The
links in Structure and Configuration therefore live in the wrong menu.

`hook_menu_links_discovered_alter()` moves the existing definitions out of the
`admin` menu and into `content`, dropping their old parent and giving them a
weight after _Media_ (0) and before the trash (1000). Titles, descriptions,
paths and access checks stay their providers'; Navigation's menu tree
still runs the route access check, so a link appears only for a user who may
reach the page. To move another link, add it to `AdminUiHooks::contentLinks()`
with an icon id.

Navigation takes an item's icon from its URL options, `options.icon`, and
falls back to the `navigation` pack under the plugin id. Gin ships no icons
for these pages, so `workspace_admin_ui.icons.yml` defines a pack over
`icons/`, and the links name it explicitly (`pack_id: workspace_admin_ui`).
Navigation then renders the SVG inline through the pack's template.

The old gin sidebar is gone: `preprocessNavigation()` and
`hook_theme_registry_alter()` only fed the `navigation` template of gin's
pre-Navigation sidebar, which gin stops using once the Navigation module is
installed. They were removed together with the `.toolbar-link--*` icon rules;
`css/toolbar.css` now only carries the Masquerade toolbar tab icon.

## Layout Builder in dark mode

Core's `layout-builder.css` paints the builder in fixed light colours — a white
canvas inside a `#2f91da` frame, `#f7f7f7` and `#eff6fc` drop zones, a white
remove button — which gin's dark mode does not touch. `css/layout-builder.css`
points every one of those colours at a gin custom property (`--gin-bg-app`,
`--gin-bg-layer`, `--gin-bg-layer2`, `--gin-border-color*`,
`--gin-color-primary*`, `--gin-color-text*`, `--gin-color-warning*`), so the
light and the dark palette both come from the theme and no dark-mode selector
is needed. The plus and cross icons travel inside data URIs, where a custom
property cannot reach them, so they are masked and painted with
`background-color` instead.

The rules are scoped to `body[data-gin-accent]`, an attribute only gin writes,
so the front-end theme is unaffected. `hook_library_info_alter()` adds
`workspace_admin_ui/layout_builder` as a dependency of core's
`drupal.layout_builder` library: the stylesheet loads wherever the builder UI
does, and stays declared in this module's `libraries.yml` with a path relative
to the module.
