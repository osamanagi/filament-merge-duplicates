#!/usr/bin/env bash
#
# Runs the package test suite against one Filament compatibility lane in an
# isolated copy of the repository.
#
# This exists because the package must support `^4.0 || ^5.0` from a single
# release line, while a checkout can only have one vendor tree installed at a
# time. The checked-in lockfile is never mutated by this script.
#
# Usage:
#   bin/lane-test.sh '^4.0'                  # Filament 4, current dependency set
#   bin/lane-test.sh '^4.0' --prefer-lowest  # Filament 4, lowest permitted set
#   bin/lane-test.sh '^5.0'                  # Filament 5, current dependency set
#
# Extra Composer flags may be passed after the constraint.

set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: bin/lane-test.sh <filament-constraint> [composer-update-flags...]" >&2
    exit 64
fi

CONSTRAINT="$1"
shift

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/filament-merge-duplicates-lane.XXXXXX")"

cleanup() {
    rm -rf "$WORK"
}
trap cleanup EXIT

echo "==> lane: filament/filament ${CONSTRAINT} $*"
echo "==> workdir: ${WORK}"

rsync -a \
    --exclude '.git' \
    --exclude '.phpunit.cache' \
    --exclude 'build' \
    --exclude 'composer.lock' \
    --exclude 'node_modules' \
    --exclude 'vendor' \
    "${ROOT}/" "${WORK}/"

cd "$WORK"

composer require --no-interaction --no-update "filament/filament:${CONSTRAINT}"
composer update --no-interaction --prefer-dist --no-progress "$@"

echo "==> resolved versions"
php -r '
    $lock = json_decode(file_get_contents("composer.lock"), true);
    $packages = array_merge($lock["packages"], $lock["packages-dev"]);
    $wanted = ["filament/filament", "laravel/framework", "livewire/livewire", "orchestra/testbench"];
    $map = [];
    foreach ($packages as $package) {
        $map[$package["name"]] = $package["version"];
    }
    foreach ($wanted as $name) {
        printf("    %-24s %s\n", $name, $map[$name] ?? "ABSENT");
    }
'

vendor/bin/pest --colors=never
