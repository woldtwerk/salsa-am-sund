#!/usr/bin/env bash
#
# Decides whether the promote workflow fast-forwards production: only when
# runtime-relevant files changed since the production branch. Renovate
# automerges tooling updates (pnpm, composer, action digests); they reach
# production with the next runtime change instead of deploying on their own.
# The whole BASE..HEAD range is compared, so commits from cancelled runs are
# still judged.
#
# Reads GITHUB_EVENT_NAME, GITHUB_SHA, GITHUB_OUTPUT and GITHUB_STEP_SUMMARY,
# and writes promote=true|false to $GITHUB_OUTPUT.

set -euo pipefail

# same <file> <filter...>: is the filtered file identical at BASE and HEAD?
# A missing file or a failing filter counts as changed.
same() {
  local file=$1 old new
  shift
  old=$(git show "$base:$file" | "$@") || return 1
  new=$(git show "$GITHUB_SHA:$file" | "$@") || return 1
  [ "$old" = "$new" ]
}

# Paths that cannot change what production runs: tooling, CI and docs.
non_runtime() {
  case "$1" in
    .github/* | .ddev/* | .vscode/* | .vite-hooks/* | .agents/* | .claude/* | .opencode/*) return 0 ;;
    */tests/* | *.md) return 0 ;;
    renovate.json | taze.config.js | phpcs.xml.dist | phpstan.neon | skills-lock.json) return 0 ;;
    tsconfig.json | jsconfig.json | globals.d.ts | .editorconfig) return 0 ;;
  esac
  return 1
}

if [ "$GITHUB_EVENT_NAME" = "workflow_dispatch" ]; then
  promote=true
  echo "Manual run: promoting." >> "$GITHUB_STEP_SUMMARY"
elif ! base=$(git rev-parse --verify --quiet origin/production); then
  # A new project has no production branch yet.
  promote=true
  echo "No production branch yet: promoting." >> "$GITHUB_STEP_SUMMARY"
else
  files=()
  while read -r file; do
    non_runtime "$file" && continue
    # Dependency files only count if they change what gets installed.
    case "$file" in
      # composer/composer is the Composer runtime, not a dependency.
      composer.lock)
        same "$file" jq -S 'del(."packages-dev", ."content-hash") | .packages |= map(select(.name != "composer/composer"))' && continue ;;
      composer.json)
        same "$file" jq -S 'del(."require-dev") | del(.require."composer/composer")' && continue ;;
      package.json)
        same "$file" jq -S 'del(.devEngines)' && continue ;;
      # The first document only locks pnpm itself, the second the project.
      pnpm-lock.yaml)
        same "$file" yq 'select(document_index == 1)' && continue ;;
    esac
    files+=("$file")
  done < <(git diff --name-only --no-renames "$base" "$GITHUB_SHA")

  if [ ${#files[@]} -eq 0 ]; then
    promote=false
    echo "No runtime changes since production, nothing to promote." >> "$GITHUB_STEP_SUMMARY"
  else
    promote=true
    {
      echo "Runtime changes since production:"
      printf -- '- `%s`\n' "${files[@]}"
    } >> "$GITHUB_STEP_SUMMARY"
  fi
fi

echo "promote=$promote" >> "$GITHUB_OUTPUT"
