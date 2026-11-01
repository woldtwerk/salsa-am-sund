#!/bin/bash
set -euo pipefail

# The image sticks with PHP's default memory_limit and only a rebuild on the
# host would change that. This script ships with every release, so it raises the
# limit itself, through an extra ini directory rather than a `php -d` flag:
# `drush deploy` runs updatedb, config:import and the deploy hooks as separate
# drush processes, and only the environment reaches those.
PHP_CONF_DIR="/tmp/php-conf.d"
mkdir -p "$PHP_CONF_DIR"
echo "memory_limit = 1024M" > "$PHP_CONF_DIR/zz-memory-limit.ini"
# The leading colon keeps the image's own conf.d in the scan path.
export PHP_INI_SCAN_DIR=":$PHP_CONF_DIR"

# locale:check builds its list of translatable projects from the extension lists,
# which are cached in cache.bootstrap. `drush deploy` only rebuilds that cache
# later on, so a newly added 'interface translation project' key in a .info.yml
# would go unnoticed until the next release. A full cache:rebuild is not an
# option here because it must not run before updatedb, hence the targeted reset.
echo "Refreshing module and theme info..."
vendor/bin/drush php:eval "\Drupal::service('extension.list.module')->reset(); \Drupal::service('extension.list.theme')->reset();" || echo 'Warning: could not reset the extension lists'

# Download and import remote translation updates (non-fatal). This also picks up
# the custom modules' translations/de.po: their 'interface translation server
# pattern' points locale:update at them.
echo "Checking for remote translation updates..."
vendor/bin/drush locale:check --yes || echo 'Warning: locale:check failed'
echo "Importing remote translation updates..."
vendor/bin/drush locale:update --yes || echo 'Warning: Some remote translations could not be updated'

echo "Running deploy hooks..."
vendor/bin/drush deploy --yes
