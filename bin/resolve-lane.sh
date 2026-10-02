#!/usr/bin/env bash
#
# Proves that the package's *published* requirements resolve for one Filament
# major on a given PHP version.
#
# This is deliberately separate from the test lanes. The package supports PHP
# 8.2+, but its dev tooling (Pest 4, PHPUnit 12, Pint) requires PHP 8.3+, so the
# Filament 5 test suite cannot execute on PHP 8.2. Host installability on PHP 8.2
# is therefore proven by dependency resolution in a throwaway consumer project
# instead of being assumed.
#
# Usage:
#   bin/resolve-lane.sh '^4.0'              # resolve against the local PHP
#   bin/resolve-lane.sh '^5.0' 8.2.0        # resolve as if the host ran PHP 8.2
#
# Exits non-zero when the constraints do not resolve.

set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: bin/resolve-lane.sh <filament-constraint> [platform-php]" >&2
    exit 64
fi

CONSTRAINT="$1"
PLATFORM_PHP="${2:-}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/filament-merge-duplicates-resolve.XXXXXX")"

cleanup() {
    rm -rf "$WORK"
}
trap cleanup EXIT

PLATFORM_BLOCK=""
if [[ -n "$PLATFORM_PHP" ]]; then
    PLATFORM_BLOCK=",
        \"platform\": {
            \"php\": \"${PLATFORM_PHP}\"
        }"
fi

cat > "${WORK}/composer.json" <<JSON
{
    "name": "osamanagi/resolution-probe",
    "description": "Throwaway consumer used to prove published constraints resolve.",
    "license": "MIT",
    "repositories": [
        {
            "type": "path",
            "url": "${ROOT}",
            "options": {
                "symlink": false
            }
        }
    ],
    "require": {
        "php": "^8.2",
        "filament/filament": "${CONSTRAINT}",
        "osamanagi/filament-merge-duplicates": "*"
    },
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }${PLATFORM_BLOCK}
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON

echo "==> resolving filament/filament ${CONSTRAINT} on PHP ${PLATFORM_PHP:-$(php -r 'echo PHP_VERSION;')}"

cd "$WORK"

composer update --no-install --no-interaction --no-scripts --no-audit --no-progress > /dev/null

echo "==> resolved"

php -r '
    $lock = "composer.lock";
    if (! is_file($lock)) {
        echo "    (no lockfile written)\n";
        exit(1);
    }
    $data = json_decode(file_get_contents($lock), true);
    $wanted = ["filament/filament", "livewire/livewire", "laravel/framework"];
    $map = [];
    foreach (array_merge($data["packages"] ?? [], $data["packages-dev"] ?? []) as $package) {
        $map[$package["name"]] = $package["version"];
    }
    foreach ($wanted as $name) {
        printf("    %-22s %s\n", $name, $map[$name] ?? "ABSENT");
    }
'
