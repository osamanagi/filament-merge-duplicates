# Test-case map

Every acceptance case ID from the implementation specification, with its current
state and the test that proves it.

States: `not started`, `failing`, `passing`, `blocked by environment`.

Last updated: M0 (compatibility spike and design freeze).

## Detection (D)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| D01 | passing | `tests/Feature/ScanTest.php` | Only the pair sharing a normalised matching value is suggested; blank and invalid values produce no key. |
| D02 | passing | `tests/Unit/Normalization/NormalizerTest.php` | Null, empty, whitespace, invalid email and unsupported types all produce no key. |
| D03 | passing | `tests/Unit/Normalization/NormalizerTest.php` | Case is an explicit option that changes the normalizer version; email domain is lowercased, local part only when opted in; originals are never mutated. |
| D04 | passing | `tests/Unit/Data/TupleEncoderTest.php`, `tests/Unit/Matching/ExactRuleTest.php` | Composite partial matches produce no key; delimiter and type collisions are structurally impossible; field order is significant. |
| D05 | passing | `tests/Unit/Data/RecordIdTest.php`, `tests/Unit/Normalization/NormalizerTest.php` | Bigint keys stay exact beyond PHP_INT_MAX; `0`, `'0'`, `false` and `null` remain distinct; ordering is symmetric and stable per key domain. |
| D06 | passing | `tests/Unit/Normalization/NormalizerTest.php` | Arabic, emoji and combining marks round-trip verbatim; NFC is opt-in and versioned. |
| D07 | passing | `tests/Feature/ScanTest.php` | Overlapping A-B and B-C buckets are both shown and no A-C match is inferred; reasons are per rule. |
| D08 | passing | `tests/Feature/ScanTest.php` | Ten records sharing one value produce exactly ten membership rows (one per record, never one per pair) and members are paginated. |
| D09 | passing | `tests/Unit/Data/KeyHashingTest.php`, `tests/Feature/DefinitionValidationTest.php` | Duplicate definition and rule IDs raise early configuration errors; revision, key version and secret changes all invalidate digests. |
| D10 | passing | `tests/Feature/ScanTest.php` | Five UUID-keyed records at a chunk size of two are indexed exactly once across three keyset chunks. |

## Scans (S)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| S01 | passing | `tests/Feature/ScanTest.php` | Re-processing a chunk does not duplicate memberships; a cancelled scan never publishes a generation; a failed scan leaves the previous generation active. |
| S02 | passing | `tests/Feature/ScanTest.php` | A second active scan for the same scope is refused, and two tenants scan and store independently. |
| S03 | passing | `tests/Feature/ScanTest.php` | A record added after a scan is picked up by the next scan; the run is acknowledged as eventually consistent rather than a snapshot. |
| S04 | passing | `tests/Feature/ScanTest.php` | Never-scanned is distinguishable from scanned-with-no-results. |
| S05 | passing | `tests/Feature/ScanTest.php` | A service context without an actor fails closed, and a scope with no tenant indexes nothing instead of falling back to an unscoped query. |
| S06 | passing | `tests/Feature/ScanTest.php` | A dismissed pair is suppressed while other buckets remain; an unrelated field change does not resurrect it while a matching-input change does; dismissals are canonical and reopenable. |

## Field merging (F)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| F01 | not started | — | |
| F02 | not started | — | |
| F03 | not started | — | |
| F04 | not started | — | |
| F05 | not started | — | |
| F06 | not started | — | |
| F07 | not started | — | |

## Relationships and retirement (R)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| R01 | not started | — | |
| R02 | not started | — | |
| R03 | not started | — | |
| R04 | not started | — | |
| R05 | not started | — | |
| R06 | not started | — | |
| R07 | not started | — | |
| R08 | not started | — | |

## Merge execution (M)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| M01 | not started | — | |
| M02 | not started | — | |
| M03 | not started | — | |
| M04 | not started | — | |
| M05 | not started | — | |
| M06 | not started | — | |
| M07 | not started | — | |
| M08 | not started | — | |
| M09 | not started | — | |

## Authorization (A)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| A01 | not started | — | |
| A02 | not started | — | |
| A03 | passing | `tests/Feature/DefinitionValidationTest.php` | An unknown definition ID cannot be resolved, and an ability map grants nothing to an undeclared actor. |
| A04 | passing | `tests/Feature/DefinitionValidationTest.php` | Deny-by-default authorizer denies every ability; a service actor gets only explicitly declared abilities; review never implies merge. |
| A05 | not started | — | |

## Ledger (L)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| L01 | not started | — | |
| L02 | not started | — | |

## UI (U) and performance (P)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| U01 | not started | — | |
| U02 | not started | — | |
| P01 | passing | `tests/Performance/ScanBenchmarkTest.php` | 100,000 records, 1,000-record chunks: 100 chunks, 7.11s, 48.5 MiB peak, 8 MiB growth, 1,413 SQL queries, 300,000 linear membership rows, 200 suggestions. Skipped unless `MERGE_DUPLICATES_BENCHMARK=1`. |

## Compatibility (C)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| C01 | not started | `tests/Concurrency/LockSpikeTest.php` | M0 evidence: merge execution refusal of SQLite is proven, and locking is proven on real MySQL 8 and PostgreSQL 15. The full engine/version matrix is M4/M7. |
| C02 | not started | `tests/Feature/PackageBootTest.php`, `tests/Feature/FilamentActionRenderTest.php` | M0 evidence: both majors install, boot, resolve the panel plugin and render/mount a real action, on all four dependency lanes. Authenticated review and merge journeys are M5. |
| C03 | not started | `bin/lane-test.sh`, `bin/resolve-lane.sh` | M0 evidence: four lanes resolve and run, the published constraint `^4.0 \|\| ^5.0` resolves for both majors, and a separate required CI job proves installation on PHP 8.2, 8.3 and 8.4 for each major. Filament 5 behaviour is not executed on PHP 8.2 (dev tooling needs 8.3+) — see ADR 0008. |
| C04 | not started | — | 4 → 5 upgrade with existing data is M6. |
| C05 | not started | `tests/Feature/FilamentActionRenderTest.php` | M0 evidence: no adapter was needed for the surfaces verified so far, and both majors passed identical assertions. Adapter coverage for the full UI is M5. |
| C06 | not started | `tests/Feature/DefinitionValidationTest.php` | M1 evidence: two unrelated definitions with different rules, fields and labels coexist in one registry and are resolved by ID. Panel UI and per-panel permissions are M5. |
| C07 | not started | — | Scalar-only and relation-bearing definitions is M1/M5. |

## Property tests

| Property | State | Test |
| --- | --- | --- |
| Normalization idempotence | not started | — |
| Tuple encoding collision resistance | not started | — |
| ID ordering symmetry | not started | — |
| Failed merge leaves no database changes | not started | — |
| Repeated operation causes no extra transfer | not started | — |

## Standing M0 checks

| Check | State | Test |
| --- | --- | --- |
| Package boots and resolves its plugin | passing | `tests/Feature/PackageBootTest.php` |
| A Filament action renders and mounts | passing | `tests/Feature/FilamentActionRenderTest.php` |
| Parent row locks are mutually exclusive on real engines | passing | `tests/Concurrency/LockSpikeTest.php` |
| WriterGuard path rejects writes to a retired source | passing | `tests/Concurrency/LockSpikeTest.php` |
| Child added between preview and execution is detected | passing | `tests/Concurrency/LockSpikeTest.php` |
| Debug helpers are not used in the codebase | passing | `tests/DebugTest.php` |
