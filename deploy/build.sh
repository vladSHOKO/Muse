#!/usr/bin/env bash
set -Eeuo pipefail
[[ $# == 2 && $1 =~ ^[a-f0-9]{40}$ ]] || { echo 'Usage: build.sh SHA OUTPUT.tar.gz' >&2; exit 2; }
sha=$1
output=$(realpath -m "$2")
[[ $(git rev-parse HEAD) == "$sha" ]] || { echo 'HEAD differs from SHA.' >&2; exit 1; }
build=$(mktemp -d)
trap 'rm -rf "$build"' EXIT
# Explicit allowlist excludes tests, local secrets, backups and development tools.
git archive "$sha" .env composer.json composer.lock bin config migrations public src templates | tar -x -C "$build"
cd "$build"
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
composer check-platform-reqs --no-dev
printf '%s\n' "$sha" > REVISION
tar -czf "$output" .env composer.json composer.lock REVISION bin config migrations public src templates vendor
