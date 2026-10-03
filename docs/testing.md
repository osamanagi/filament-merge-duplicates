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
composer test:coverage  # pest --coverage --min=98 (the reviewed floor, see below)
```

Lanes:

```bash
bin/lane-test.sh '^4.0' --prefer-lowest   # run a lane in an isolated copy
bin/resolve-lane.sh '^5.0' 8.2.0          # prove published constraints resolve
```

`bin/resolve-lane.sh` exists because Filament 5 is installable on PHP 8.2 while the test
tooling is not. Resolution is verified separately so the PHP lower bound is genuinely
tested rather than assumed. See [ADR 0008](adr/0008-php-baseline-and-tooling.md).

## Coverage gate

- Driver: **pcov** (xdebug is not installed).
- Filter: the `src/` directory only, declared in `phpunit.xml.dist`.
- Reports: HTML to `build/coverage`, text to `build/coverage.txt`, Clover to
  `build/logs/clover.xml`.
- Target: **100% of reachable PHP lines in `src/`, on both Filament majors**,
  measured on the final full suite.
- Measured at M7: **98.2%** with 624 passing tests, identical on both lanes.

Every line the suite does not execute is enumerated with the reason it cannot be
reached in [`docs/m7-handover.md`](m7-handover.md#reviewed-coverage-exceptions).
Those are defensive guards and postconditions that a documented contract makes
unreachable, not untested behaviour: covering them would require a stub that
breaks the contract the guard exists to protect.

The threshold in CI and in `composer test:coverage` is therefore the measured
floor (**98**), enforced so it can only move up. It is deliberately not a claim
that the residual lines are covered, and it is not a threshold that was lowered
to make a lane pass: the lane passes at 98.2, and the exceptions above are the
review the M7 gate asks for.

Measured totals: M2 69.6%, M3 76.1%, M4 80.7%, M5 83.3%, M7 **98.2%** (`src/`
lines, pcov, full suite).

## Execution suite

`tests/Execution/` proves what SQLite cannot: row locks, contention and
transactional rollback. It runs on real MySQL and PostgreSQL through
`EngineConnections`, sets the engine as both the default and the package
connection, and skips (loudly, through `markTestSkipped`) when a service is
unreachable instead of pretending another engine proved the property.

The dataset is named `engines`, so every execution case runs twice. The engine
schema is rebuilt once per engine per process, and rebuilt again if either the
package or the fixture tables are missing, because `MigrationEngineTest` drops
and rolls back the same database.

Coverage is a code-execution metric, not behavioural proof. The acceptance cases
in the case map, the real-database lanes and the UI assertions are what prove
behaviour. Blade templates, migrations and frontend behaviour are verified
through integration and browser assertions because PHP line coverage does not
measure their semantics.

## Scan benchmark

The plan requires a measured scan of 100,000 records in 1,000-record chunks. It is a
measurement, not a promised SLA:

```bash
MERGE_DUPLICATES_BENCHMARK=1 vendor/bin/pest tests/Performance
```

It is skipped by default because it is slow and a laptop is not a benchmark rig.

Recorded runs (Apple arm64, in-memory SQLite, PHP 8.4.22, single process):

| Metric | M4 (2026-10-02) | M7 (2026-10-03) |
| --- | --- | --- |
| Records | 100,000 | 100,000 |
| Chunk size / chunks | 1,000 / 100 | 1,000 / 100 |
| Wall time | 7.11 s (~14,058 records/second) | 10.34 s (~9,671 records/second) |
| Peak memory | 48.5 MiB (target: under 256 MiB) | 50.5 MiB |
| Memory growth | 8 MiB | 10 MiB |
| SQL queries | 1,413 (~14 per chunk, not per record) | 1,413 |
| Membership rows | 300,000 = records x rules (linear, never pairs) | 300,000 |
| Suggestions | 200 | 200 |

The M7 run is slower than the M4 run on the same machine and the same fixtures.
The query count, the membership count and the memory profile are unchanged, so
the difference is in the scan path or in the machine, not in what the scan
writes; the numbers are recorded as measured rather than adjusted. The assertions
enforce the properties that matter rather than the timings: chunking is real,
index storage stays linear in records x rules, and peak memory stays under the
target.

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
