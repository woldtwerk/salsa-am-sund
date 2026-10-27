# Agent guidance for this Drupal site

This codebase is a Composer-managed Drupal site.

## Detect the command runner first

Do not assume DDEV is installed. Before running anything, work out how this codebase invokes Composer
and Drush, from the project root:

1. **DDEV (preferred)** — a `.ddev/` directory exists _and_ `ddev` is on `PATH` (`command -v ddev`).
   Use `ddev composer` and `ddev drush`, as described below.
2. **Local PHP + Composer** — no usable `ddev`, but `composer` is on `PATH` (`command -v composer`).
   Run `composer install` if `vendor/` doesn't exist yet. Use plain `composer`, and check
   whether `vendor/bin/drush` exists (some packages of this project ship without Drush), if it does, use that (not a global `drush`); if it doesn't, skip the Drush workflow below.

State which one you picked before running commands. If neither applies, stop and report it rather
than guessing at a command that will fail. In the workflow sections below, substitute the Composer
and Drush commands for the option you resolved.

## In GitHub Actions

When `GITHUB_ACTIONS=true`, DDEV is installed but not started, and there is no site yet.

- If the task needs a working site or tooling (PHP, modules, config, theme, frontend build, tests), run
  `.github/scripts/ci-site-install.sh` once before any `ddev` command. It starts DDEV, imports the
  scrubbed production database from `$CI_DB_DUMP` when the workflow fetched one (otherwise it installs
  the site from the repository configuration) and installs the frontend dependencies. Then use DDEV as
  described below: frontend tooling with `ddev exec -s viteplus vp` (e.g. `vp check`), tests with
  `ddev exec composer run-script test`, and `ddev drush config:export --yes` after config changes.
- For docs, workflows or other changes that don't need a running site, skip the script and don't run
  any `ddev` command; setting up the site takes several minutes.

## Local environment (DDEV)

Run commands from the project root:

- Start or restart the local environment with `ddev start`, `ddev restart`, and `ddev stop`.
- Install PHP dependencies with `ddev composer install`.
- Open the site with `ddev launch`.
- Run Drush commands with `ddev drush <command>` such as `status`, `user:login`, `cache:rebuild`, and `updatedb`.
- Import the scrubbed production database with `ddev drush ci-snapshot:pull` (it asks first; `ddev snapshot`
  keeps the current one). `ddev drush ci-snapshot:check < ~/path/to/key` tests the CI endpoint.

DDEV project config lives in `.ddev/config.yaml`. Use `.ddev/config.local.yaml` for machine-specific overrides.

## Common Drupal workflows

- Start every feature that changes data or config with a database snapshot, e.g.
  `ddev snapshot --name <feature>`, so you can roll back with `ddev snapshot restore <feature>`.
- Add a module with `ddev composer require drupal/<project>`, then `ddev drush pm:enable --yes <module_machine_name>`, then `ddev drush cache:rebuild`.
- Apply database updates after code changes with `ddev drush updatedb --yes`.
- Import repository configuration into the site with `ddev drush config:import --yes`.
- Export site configuration back to the repo with `ddev drush config:export --yes`.

## Guardrails

- Do not commit secrets or machine-local overrides such as `.env`, `settings.local.php`, or `.ddev/config.local.yaml`.
- Do not commit `vendor/` or uploaded files under `web/sites/*/files`.
- Do not edit Drupal core or contributed projects in place.
- Put custom code in `web/modules/custom` and `web/themes/custom`.

## References

- https://docs.ddev.com/en/stable/
- https://www.drupal.org/docs/administering-a-drupal-site/configuration-management/workflow-using-drush

## Performance

Always keep in mind to write efficient code that scales nicely. Avoid unnecessary queries, and make
sure to use caching where possible. Inform me if I suggest any poorly performing code
or data structure and propose a better solution.

## Testing

Only test what matters: the custom logic in this repo.

- Don't test Drupal core, contributed modules or other third-party code.
- Don't write UI tests (no browser, rendering or markup tests).
- During development, run only the tests you created or changed. Run the whole suite
  (`ddev composer test`) once, when the feature is done.
- Run single test files with `--file` (`--class` fails here, because test discovery trips over a contrib test):
  `ddev exec -d /var/www/html/web php core/scripts/run-tests.sh --sqlite /tmp/rt.sqlite --dburl mysql://db:db@db/db --url http://localhost --suppress-deprecations --non-html --concurrency 4 --file modules/custom/<module>/tests/src/Kernel/FooTest.php,modules/custom/<module>/tests/src/Unit/BarTest.php`
  Pass all changed test files comma-separated in one call with `--concurrency 4`, not one call per file.
- Keep kernel tests cheap, because each test method installs its modules again:
  - Pure logic (date calculation, parsing, matching, formatting) goes into unit tests.
  - Kernel tests extend the lightest base class that covers them and install only the modules and
    config they need. The full base with Canvas is only for tests of Canvas components.
  - Group the assertions for one scenario into one test method instead of one method per assertion.

## Writing Drupal code: checklist

These are the mistakes reviews keep finding. Check every change against them.

Before you write code:

- Read the code next to your change. If a field or value is already read or computed elsewhere
  (e.g. in a preprocess hook), reuse it or move it into a shared service. Never copy the logic:
  copies drift apart and then behave differently.
- Check `composer.json` and `web/modules/contrib` for a module that already covers the task. If you
  build it custom anyway, say why in the PR body. Don't add a dependency without asking.
- Don't hardcode content that lives in the database (term or node labels, the site name). Load the
  entity (e.g. by the UUID constants in `OrganizerResolver`) and add it as a cache dependency.

Every render-time code path must handle:

- Unsaved entities: node previews render with `isNew() === TRUE`, and `toUrl()` throws then.
- Other routes: the same view mode renders on previews, revision pages and embedded in other pages
  (Canvas, blocks, views). Add page-level output (`html_head`, metatags, page title) only on the
  entity's canonical route, checking that the route parameter is the same entity, and add the
  `route` cache context.
- Cacheability: add every entity or config you output as a cacheable dependency, and a cache context
  for everything the output varies by (`route`, `url.site`, `user.permissions`, …).
- Empty fields and missing referenced entities.

Efficiency:

- Move work that doesn't change out of loops: URL generation, entity loads, building the same array.
  Memoize lookups per request in the service.
- Don't branch on the same condition in both caller and callee; call the specific method directly.

Tests: cover each edge case above that applies to your change (unsaved entity, other route, missing
referenced entity), not only the happy path.

Before you open the PR, re-read your whole diff as a reviewer and fix what this checklist finds. In the
PR body, list the edge cases you handled and the design decisions you made (e.g. contrib vs. custom).

## Claude Code: delegate coding to opencode

This section is for Claude Code only. opencode reads this file too: if you are
opencode, ignore this section — you are the one who writes the code.

Claude does not write code in this repo itself. Claude plans and reviews;
opencode writes the code. Every coding task goes to opencode as a plan of
concrete todos — files, functions, behaviour, tests to add — with the research
and design decisions already made:

```
opencode run --auto -m 'opencode-go/deepseek-v4.1-flash#high' --session ses_<task> --title "<task>" "<plan>" </dev/null
```

- Name the session (`--session` creates it), so the user can watch the run
  live with `opencode -s ses_<task>`.
- Put the rules from this file that apply to the task into the prompt — ddev,
  pnpm scripts, UI/logic split with vitest, URL state, never touch `.data/`,
  `touch nuxt/nuxt.config.ts` after layer edits.
- Run several opencode agents in parallel when tasks are independent, each in
  its own git worktree under `.worktrees/<task>` (inside the repo, so ddev
  sees it) and its own session. Tasks that touch `pnpm-workspace.yaml`, the
  lockfile or `.ddev/` don't run alongside others. Review and merge the
  results one at a time.
- Review the diff (correctness, security, this file's rules) and run
  `ddev exec pnpm run lint`, `ddev exec pnpm run typecheck` and the tests.
- Send findings back as concrete todos with
  `opencode run --auto -m 'opencode-go/deepseek-v4.1-flash#high' --session ses_<task> "<findings>"` until the review
  passes.
- `--auto` approves every permission opencode asks for, so commits, deploys,
  migrations and deletions still need the user's go-ahead first.

## Skills

When working in web always use these skills:

- drupal
- drupal-expert
- drupal-security
- drupal-testing

## Commit

Always commit in english

<!--VITE PLUS START-->

# Using Vite+, the Unified Toolchain for the Web

This project is using Vite+, a unified toolchain built on top of Vite, Rolldown, Vitest, tsdown, Oxlint, Oxfmt, and Vite Task. Vite+ wraps runtime management, package management, and frontend tooling in a single global CLI called `vp`. Vite+ is distinct from Vite, and it invokes Vite through `vp dev` and `vp build`. Run `vp help` to print a list of commands and `vp <command> --help` for information about a specific command.

Docs are local at `node_modules/vite-plus/docs` or online at https://viteplus.dev/guide/.

## Built-in Commands vs Scripts

`vp <name>` runs a built-in command. `vp run <name>` runs a `package.json` script or a `vite.config.ts` task. Scripts cannot overwrite built-ins, so `vp dev` and `vp run dev` may do different things. Check `package.json` and `vite.config.ts` first, and run `vp run <name>` when the project defines a script or task with that name.

## Running Scripts

Run TypeScript and JavaScript files with `vpx <file>`, for example `vpx ./scripts/seed.ts --dry-run`. It handles enums, decorators, JSX, and tsconfig `paths`, so do not add `tsx` or `ts-node` to run scripts. `vpx --watch <file>` reruns the file when a file it uses changes, in place of `tsx watch`. `vp node <file>` runs plain Node.js.

## Tool Versions

Run `vp toolchain` to show versions and relationships in the active Vite+
release. Add a tool name to select part of the graph. For example, run
`vp toolchain vite`. Use `--global` to ignore the local `vite-plus` package. Use
`vp why <package>` to show the package-manager dependency graph.

## Review Checklist

- [ ] Run `vp install` after pulling remote changes and before getting started.
- [ ] Run `vp check` and `vp test` to format, lint, type check and test changes.
- [ ] Check if there are `vite.config.ts` tasks or `package.json` scripts necessary for validation, run via `vp run <script>`.
- [ ] If setup, runtime, or package-manager behavior looks wrong, run `vp env doctor` and include its output when asking for help.

<!--VITE PLUS END-->
