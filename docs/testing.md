# Testing

## Scan maintenance follow-up — 23 October 2026

Scan maintenance was verified sequentially on both compatibility lanes, each
exercising local MySQL and PostgreSQL databases:

| Lane | Framework versions | Result |
| --- | --- | --- |
| Installed Filament 5 | Filament 5.9.0, Livewire 4.4.7, Laravel 12.69.3, PHP 8.4.8 | 765 passed, 1 skipped, 2,247 assertions; 98.0% `src/` line coverage with pcov |
| Isolated current Filament 4 | Filament 4.15.3, Livewire 3.8.11, Laravel 12.70.1, PHP 8.4.8 | 765 passed, 1 skipped, 2,247 assertions; coverage not measured in this lane |

The focused cases cover stale queued/running recovery, missing heartbeats,
scheduler command output, and retention of unpublished terminal generations.
PHPStan level 5 and Pint passed. The opt-in benchmark was skipped; the original
100% package coverage requirement remains unmet.

## Record authorization follow-up — 23 October 2026

The record-policy implementation was verified sequentially on both compatibility
lanes, each exercising real local MySQL and PostgreSQL databases:

| Lane | Framework versions | Result |
| --- | --- | --- |
| Installed Filament 5 | Filament 5.9.0, Livewire 4.4.7, Laravel 12.69.3, PHP 8.4.8 | 762 passed, 1 skipped, 2,231 assertions; 98.1% `src/` line coverage with pcov |
| Isolated current Filament 4 | Filament 4.15.3, Livewire 3.8.11, Laravel 12.70.1, PHP 8.4.8 | 762 passed, 1 skipped, 2,231 assertions; coverage not measured in this lane |

`GateMergeAuthorizer` and `MergeAuthorization` each have 100% measured line
coverage in the Filament 5 run. PHPStan level 5, Pint, strict Composer validation,
and diff whitespace checks passed. The isolated installation did not change the
checkout's dependency files. The opt-in benchmark was skipped; dependency lower
bounds and manual browser journeys were not rerun. The original requirement of
100% package coverage on both majors remains unmet.

New tests cover mixed parent/child permissions, exact transfer targets, changed
survivor direction, policy revocation after preview and during writes, complete
rollback, soft-deleted children, child locks before writes, forged Livewire state,
and scoped read-only replay. See [record authorization](record-authorization.md)
for the integration change and [the case map](test-case-map.md) for test files.

## Permanent identity follow-up — 22 October 2026

The final stable-retirement implementation was verified sequentially against both
local MySQL and PostgreSQL engines, so the two compatibility lanes did not share
test databases concurrently:

| Lane | Framework versions | Result |
| --- | --- | --- |
| Installed Filament 5 | Filament 5.9.0, Livewire 4.4.7, Laravel 12.69.3, PHP 8.4.8 | 701 passed, 1 skipped, 2,064 assertions; 98.0% `src/` line coverage with pcov |
| Isolated current Filament 4 | Filament 4.15.3, Livewire 3.8.11, Laravel 12.70.1, PHP 8.4.8 | 701 passed, 1 skipped, 2,064 assertions; coverage not measured in this lane |

The skipped case in each invocation is the opt-in 100,000-record benchmark.
The prior benchmark result remains recorded below; it was not rerun for this
follow-up. The new `RetirementLedgerMigrator` and `LedgerScopeGuard` services each
have 100% measured line coverage in the Filament 5 run. PHPStan level 5, Pint, and
strict Composer validation passed. Dependency lower bounds and manual browser
journeys were not rerun, and the original 100% package-wide coverage requirement
is still unmet. Current dependency versions came from the isolated lane's
resolution; this checkout's dependency files were not changed.

See [ADR 0010](adr/0010-stable-retirement-domains.md) for the identity decision and
[retirement-identity-upgrade.md](retirement-identity-upgrade.md) for deployment.

## Test organization

Pest is the single test runner. Tests are organised by what they prove:

| Directory | Proves | Mocking policy |
| --- | --- | --- |
| `tests/Unit` | Normalizers, tuple encoding, rule evaluation, field choice policy, configuration validation, ID codecs, fingerprinting | Only genuine external boundaries |
| `tests/Feature` | Migrations, scans, generations, dismissals, planner, executor, ledger, authorization, constraints, rollback against real SQL databases | No database or queue fakes for persistence, retry or transaction paths |
| Filament cases in `tests/Feature` and `tests/Execution` | Authenticated banner, review page, choices, forged requests, merge, errors, audit on both majors | Outbound notifications may be faked; database state and visible UI must be asserted |
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

The threshold in CI and in `composer test:coverage` is the measured floor (**98**).
This differs from the implementation plan's explicit requirement for 100% of
executable `src/` lines independently on both majors. The 98% gate does not satisfy
that requirement, even when individual uncovered lines have been reviewed.
See [the implementation review](implementation-review.md) for the current result
and remaining release gaps.

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

Review run, 22 October 2026: local macOS, PHP 8.4.8, in-memory SQLite; the
Filament 5 full suite was running separately at the same time. The existing
100,000-record benchmark passed: 100 chunks, 300,000 memberships, 200 suggestions,
11.20 seconds measured scan time (17.96 seconds for the test invocation),
52.5 MiB peak memory, 10 MiB growth, and 1,615 counted SQL queries. The additional
queries include transactional scan-state/context checks. Timing is a local
measurement, not a cross-machine comparison or production guarantee.

The final review suite on Filament 5.9.0 passed 674 tests with 1,907 assertions;
the benchmark was skipped in that invocation and passed separately. Measured
`src/` coverage was 98.0%. PHPStan level 5, changed-file Pint checks, Composer
validation, and the CSS build passed. Filament 4 was not rerun in this review.

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
