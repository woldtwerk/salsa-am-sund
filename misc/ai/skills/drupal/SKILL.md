---
name: drupal
description: Additional instructions for working with drupal.
---

## Test

## Development

- always use oop hooks instead of hooks in .module and .theme
- custom modules use the `workspace_` prefix: machine name, directory, file names and namespace (`web/modules/custom/workspace_foo`, `workspace_foo.info.yml`, `Drupal\workspace_foo`). a module's `composer.json` is named `workspace/workspace_foo`.
- a custom module's or theme's `composer.json` always has a `repositories` key with `{"type": "composer", "url": "https://packages.drupal.org/8"}`. composer ignores it in non-root packages, but renovate scans these files on their own and can't look up `drupal/*` dependencies without it. a renovate pr there only bumps the constraint; update the root lock with `ddev composer update workspace/workspace_foo <package> -W`.

## Frontend Development

- Always prefer using sdc over drupal templates.
- use cva with ui prop and tailwind merge.
- sdc should work with canvas
- never put a bare `&` in sdc markup, e.g. tailwind arbitrary variants like `[&_a]:z-10` in a class attribute. canvas parses the html before every `{{ variable }}` (`CanvasPropVisitor`, masterminds html5) and silently stops adding its prop/slot markers after the first parse error, so every later prop or slot in that template can't be clicked or edited in the canvas preview (layers still work). use named variants (`prose-a:`, `*:`, `has-[…]:`) or css instead.
- use canvas for building view modes, templates and pages
- prefer css/tailwind over js.
- prefer modern html apis over custom js, e.g. popover (`popovertarget`), invoker commands (`commandfor`/`command`), `<dialog>`, `<details name>`, `:has()`, `:popover-open`, `@starting-style`, css anchor positioning. only add js when there is no declarative way.
- style rich text (html props, formatted text fields) with the css-only port of tailwind typography (`prose`, `prose-invert`, size modifiers), never with ad-hoc `[&_p]:…` variants. it has the same features as the official plugin and uses em wherever possible: font-sizes, line-heights and spacing are written directly in em at the element rule, size modifiers (`prose-sm` … `prose-2xl`) only change the base font-size. custom properties only for colours, font families and max-width.
- write custom css with native css nesting (and `@scope` where a component needs a boundary, e.g. `@scope (.prose) to (.not-prose)`), not flat repeated selectors.

## Class Variance Authority

Components should expose a ui prop.

E.g.:

```twig
{% set ui = {
  root: html_cva(
    base: [
      'py-4',
      ui.root,
    ],
  ),
  summary: html_cva(
    base: [
      'flex',
      ui.summary,
    ],
  ),
  content: html_cva(
    base: [
      ui.content,
    ],
  ),
} %}

<details {{ attributes.addClass(tw_merge(ui.root.apply({}))) }}>
  <summary class="{{ html_classes(tw_merge(ui.summary.apply({}))) }}>
    <h3>
      {{- summary -}}
    </h3>
    {{ icon('foo', 'expand', { size: 48, class: [
      'shrink-0',
    ]|join(' ') }) }}
    {{ icon('foo', 'minus', { size: 48, class: [
      'hidden',
      'shrink-0',
    ]|join(' ') }) }}
  </summary>
  <div class="{{ html_classes(tw_merge(ui.content.apply({}))) }}">
    {{- content -}}
  </div>
</details>

```

## Session

Don't mess with the user sesssion. Instead use the masquerade module to masquereade as the user you need for testing.
