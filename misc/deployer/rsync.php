<?php

/**
 * @file
 * Rsync deployment configuration.
 */

declare(strict_types=1);

namespace Deployer;

require 'recipe/common.php';

// The site's name, which deploy.yaml uses for the deploy path, the systemd unit
// and the container. It is the name of the project directory, which matches the
// repository name both in a local clone and in the GitHub Actions checkout.
set('application', basename(dirname(__DIR__, 2)));

// Seeds the new release with the previous one, so rsync only transfers what
// changed.
set('copy_dirs', [
  'misc',
  'node_modules',
  'vendor',
  'web',
]);

task('deploy', [
  'deploy:prepare',
  'deploy:publish',
]);

task('deploy:update_code', function () {
  $options = [
    '--archive',
    '--checksum',
    '--compress',
    // The dump, the fonts and every image are already compressed; running them
    // through zlib again only burns CPU on both ends.
    '--skip-compress=gz/tgz/zip/br/zst/png/jpg/jpeg/webp/avif/gif/ico/woff/woff2/mp4/webm/mp3',
    '--delete',
    '--omit-dir-times',
    '--no-owner',
    '--no-group',
    '--verbose',
  ];

  // Filter rules are evaluated in order and the first match wins, so every
  // include has to precede the exclude it carves an exception out of. An
  // exclude also protects what it matches from `--delete`, which is what keeps
  // what `deploy:copy_dirs` carried over from the previous release in place.
  $filters = [];

  // The repository root is an allow list. Everything the server runs lives in
  // one of these seven entries; everything else up there is tooling, CI,
  // editor or agent state, and none of it has ever been read by a release.
  // composer.json and composer.lock stay because Drush and Package Manager
  // read them to report the installed versions. node_modules stays because
  // Tailwind compiles at run time and the theme reads its icons from it.
  foreach (['composer.json', 'composer.lock', 'drush/', 'misc/', 'node_modules/', 'vendor/', 'web/'] as $keep) {
    $filters[] = '+ /' . $keep;
  }
  $filters[] = '- /*';

  // misc/ is mostly a workstation directory. Only these are read by a
  // release: the hooks, the configuration that `drush deploy`
  // imports, and the snapshot script that CI's restricted SSH key is allowed
  // to run.
  foreach (['hooks/', 'snapshot/', 'sync/'] as $keep) {
    $filters[] = '+ /misc/' . $keep;
  }
  $filters[] = '- /misc/*';

  $web_excludes = [
    // Shared directories; deploy:shared symlinks them into the release.
    '/web/sites/default/files/',
    '/web/sites/default/private/',
    // Test scaffolding and the linter baselines that ship inside core and the
    // contrib projects. Nothing boots them in production, and no enabled
    // module lives under a tests/ directory.
    '/web/phpunit.xml',
    '/web/.phpunit.cache/',
    '/web/core/.phpstan-baseline.php',
    '/web/core/tests/',
    '/web/core/modules/*/tests/',
    '/web/core/modules/*/src/Tests/',
    '/web/core/recipes/*/tests/',
    '/web/core/profiles/demo_umami/',
    '/web/core/profiles/nightwatch_*',
    '/web/core/profiles/test_*',
    '/web/core/profiles/testing*',
    '/web/modules/contrib/*/tests/',
    '/web/themes/contrib/*/tests/',
    // Plain-text core documentation, ~200 KB of it.
    '/web/core/CHANGELOG.txt',
    '/web/core/COPYRIGHT.txt',
    '/web/core/INSTALL.mysql.txt',
    '/web/core/INSTALL.pgsql.txt',
    '/web/core/INSTALL.sqlite.txt',
    '/web/core/INSTALL.txt',
    '/web/core/MAINTAINERS.txt',
    '/web/core/UPDATE.txt',
    '/web/core/USAGE.txt',
    '/web/modules/README.txt',
    '/web/themes/README.txt',
  ];

  // Unanchored, so they match at every depth: metadata that composer and pnpm
  // packages carry along.
  $global_excludes = [
    'README.md',
    '.git/',
    '.github/',
    '.gitignore',
    '.gitattributes',
    '.editorconfig',
    '.ddev/',
  ];

  $excludes = array_merge(
    $web_excludes,
    $global_excludes,
    get('rsync_exclude') ?? []
  );

  foreach ($excludes as $string) {
    $filters[] = '- ' . $string;
  }

  foreach ($filters as $filter) {
    $options[] = '--filter=' . $filter;
  }

  upload('./', '{{release_path}}', [
    'options' => $options,
  ]);
});

before('deploy:update_code', 'deploy:copy_dirs');

after('deploy:failed', 'deploy:unlock');
