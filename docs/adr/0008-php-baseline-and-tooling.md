# ADR 0008 — PHP baseline, Filament 5 and the test-tooling boundary

Status: accepted (M0). Flagged for review because it narrows what is behaviourally
verified.

## Context

The specification requires PHP 8.2+, Laravel 12.x and both Filament 4.x and 5.x from one
release line.

CI initially ran the Filament 5 lanes on PHP 8.2 and they failed with
"Your requirements could not be resolved to an installable set of packages". That failure
is **not** a package limitation:

- `filament/support` 5.9.0 requires `php ^8.2` and `livewire/livewire ^4.4.2`.
- `livewire/livewire` 4.4.7 requires `php ^8.1`.
- A throwaway consumer project requiring the package plus `filament/filament:^5.0` resolves
  successfully with `php: 8.2` as its platform, installing Filament 5.9.0.

What actually blocks PHP 8.2 is the package's **dev tooling**. `composer prohibits php 8.2`
reports, among others:

| Package | PHP requirement |
| --- | --- |
| `pestphp/pest` 4.7.8 | `^8.3.0` |
| `pestphp/pest-plugin-livewire` 4.1.0 | `^8.3` |
| `phpunit/phpunit` 12.5.33 | `>=8.3` |
| `laravel/pint` 1.32.1 | `^8.3.0` |
| `brianium/paratest` 7.20.0 | `~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0` |
| `sebastian/*`, `phpunit/php-code-coverage` | `>=8.3` |

The chain is: Filament 5 → Livewire 4 → `pest-plugin-livewire` 4 → Pest 4 → PHP 8.3+. There
is no Pest 3 line that supports Livewire 4.

## Decision

1. Keep `"php": "^8.2"` and `"filament/filament": "^4.0 || ^5.0"`. Host applications on
   PHP 8.2 may use either Filament major, and must not be blocked because our test runner
   needs a newer PHP.
2. Test lanes run Filament 4 on PHP 8.2, 8.3 and 8.4, and Filament 5 on PHP 8.3 and 8.4.
   The `(Filament 5, PHP 8.2)` test combination is excluded, with the reason recorded in
   the workflow itself.
3. Host installability on the minimum PHP is proven separately and explicitly by
   `bin/resolve-lane.sh`, which builds a throwaway consumer project from a `path`
   repository and runs a real Composer resolution for each `(constraint, PHP)` pair. This
   job is required, not informational.
4. Documentation must distinguish **installed** from **behaviourally verified**. The
   support matrix states that `(Filament 5, PHP 8.2)` is installable but that package
   behaviour on that exact combination is not executed in CI.

## Consequences

- PHP 8.2 keeps working for both majors, and the lower bound is genuinely tested rather
  than assumed.
- The Filament 5 lanes cannot claim "tested on the published minimum PHP". The minimum
  *installable* PHP is proven; the minimum *executed* PHP for Filament 5 is 8.3.
- If behaviour on `(Filament 5, PHP 8.2)` must be executed later, the tooling needs a
  Pest 3-compatible runner path, which does not currently exist.
- Two options were rejected: raising the package's PHP floor to 8.3 (would narrow the
  support promise the specification requires) and removing Filament 5 from a lane to make
  CI pass (would break the dual-major requirement).
