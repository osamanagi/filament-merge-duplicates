# Testing

Pest is the single test runner. Tests are organised by what they prove:

| Directory | Proves | Mocking policy |
| --- | --- | --- |
| `tests/Unit` | Normalizers, tuple encoding, rule evaluation, field choice policy, configuration validation, ID codecs, fingerprinting | Only genuine external boundaries |
| `tests/Feature` | Migrations, scans, generations, dismissals, planner, executor, ledger, authorization, constraints, rollback against real SQL databases | No database or queue fakes for persistence, retry or transaction paths |
| `tests/Filament` | Authenticated banner, review page, choices, forged requests, merge, errors, audit on both majors | Outbound notifications may be faked; database state and visible UI must be asserted |
| `tests/Concurrency` | Separate MySQL/PostgreSQL connections racing scans, merges and child writes | No mocked locks, no SQLite |
| Demo application (`/Users/nagi/code/demo`) | Fresh host install, two unrelated definitions, queued scan, review, merge, audit, 4 → 5 upgrade | Real services |

## Commands

```bash
composer test           # full suite on the current lane
composer test:lint      # pint --test
composer analyse        # phpstan
composer check          # lint + static analysis + tests
composer test:coverage  # pest --coverage --min=100
```

## Coverage gate

- Driver: **pcov** (xdebug is not installed).
- Filter: the `src/` directory only, declared in `phpunit.xml.dist`.
- Reports: HTML to `build/coverage`, text to `build/coverage.txt`, Clover to
  `build/logs/clover.xml`.
- Target: **100% executable PHP line coverage of `src/` on both Filament majors**,
  measured on the final full suite.

Status at M0: the gate is wired and reporting, and the threshold is **not** yet
met, because most of `src/` is still skeleton code from the plugin template. This
is the draft required by M0. The release gate for the 100% requirement is M7 and
the threshold must never be lowered to make a lane pass. CI runs the coverage job
in draft (non-blocking) mode until M7, and reports the measured percentage so
regressions are visible.

Coverage is a code-execution metric, not behavioural proof. The acceptance cases
in the case map, the real-database lanes and the UI assertions are what prove
behaviour. Blade templates, migrations and frontend behaviour are verified
through integration and browser assertions because PHP line coverage does not
measure their semantics.

## Database services

Feature, persistence and concurrency tests require real engines:

| Service | Version | Local | CI |
| --- | --- | --- | --- |
| MySQL | 8.0 | `127.0.0.1:3306` | `mysql:8.0` service container |
| PostgreSQL | 15 | `127.0.0.1:5432` | `postgres:15` service container |

`docker-compose.yml` is provided for a containerised alternative; it maps MySQL to
`3308` and PostgreSQL to `5433`, so set `SPIKE_MYSQL_PORT` / `SPIKE_POSTGRES_PORT`
when using it.

The concurrency spike is **skipped** (not passed) when no engine is reachable, so
that the default SQLite suite stays runnable:

```bash
vendor/bin/pest tests/Concurrency
```

If services or credentials are unavailable, record the case as unrun. Never report
an unrun check as passing.

## Test-case map

Every acceptance case ID from the specification is tracked in
[test-case-map.md](test-case-map.md) with its current state.
