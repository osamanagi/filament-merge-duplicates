# Test-case map

Every acceptance case ID from the implementation specification, with its current
state and the test that proves it.

States: `not started`, `failing`, `passing`, `blocked by environment`.

Last updated: M0 (compatibility spike and design freeze).

## Detection (D)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| D01 | not started | — | |
| D02 | passing | `tests/Unit/Normalization/NormalizerTest.php` | Null, empty, whitespace, invalid email and unsupported types all produce no key. |
| D03 | passing | `tests/Unit/Normalization/NormalizerTest.php` | Case is an explicit option that changes the normalizer version; email domain is lowercased, local part only when opted in; originals are never mutated. |
| D04 | passing | `tests/Unit/Data/TupleEncoderTest.php`, `tests/Unit/Matching/ExactRuleTest.php` | Composite partial matches produce no key; delimiter and type collisions are structurally impossible; field order is significant. |
| D05 | passing | `tests/Unit/Data/RecordIdTest.php`, `tests/Unit/Normalization/NormalizerTest.php` | Bigint keys stay exact beyond PHP_INT_MAX; `0`, `'0'`, `false` and `null` remain distinct; ordering is symmetric and stable per key domain. |
| D06 | passing | `tests/Unit/Normalization/NormalizerTest.php` | Arabic, emoji and combining marks round-trip verbatim; NFC is opt-in and versioned. |
| D07 | not started | — | |
| D08 | not started | — | |
| D09 | passing | `tests/Unit/Data/KeyHashingTest.php`, `tests/Feature/DefinitionValidationTest.php` | Duplicate definition and rule IDs raise early configuration errors; revision, key version and secret changes all invalidate digests. |
| D10 | not started | — | |

## Scans (S)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| S01 | not started | — | |
| S02 | not started | — | |
| S03 | not started | — | |
| S04 | not started | — | |
| S05 | not started | — | |
| S06 | not started | — | |

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
| P01 | not started | — | |

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
