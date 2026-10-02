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
| F01 | passing | `tests/Feature/MergePlannerTest.php` | Equal values retain the survivor and are not reported as a difference; a blank survivor takes the source value; a blank source keeps the survivor value; both blank reports nothing to change. A blank string only counts as missing when the field opts in. |
| F02 | passing | `tests/Feature/MergePlannerTest.php` | Two different non-blank values require an explicit choice, are reported as a blocker and are never resolved by last-write-wins. |
| F03 | passing | `tests/Feature/MergePlannerTest.php` | Comparison is typed, never `empty()`: `false` and `0` are values, `5` and `'5'` differ, `'1.50'` and `'1.5'` differ, enums compare by case, and the same instant expressed in two offsets stays distinct. Asserted against `ValueCodec` directly, with the planner wiring covered by the difference tests. |
| F04 | partial | `tests/Feature/MergePlannerTest.php` | The definition validator and the developer `MergeValidator` errors surface as field-level blockers, so a plan that would violate them is not confirmable. Database-level enforcement and rollback-free "no changes" proof arrive with execution in M4. |
| F05 | passing | `tests/Feature/MergePlannerTest.php` | A unique field that cannot be transferred while the retired source keeps its value is blocked with that reason, and is not blocked when only one side holds a value. A source retired by soft delete blocks the merge outright. The "final constraint demonstrably valid" proof at write time belongs to M4. |
| F06 | partial | `tests/Feature/MergePlannerTest.php`, `tests/Feature/DefinitionValidationTest.php` | Key, scope, timestamp, soft-delete, credential and relationship columns are rejected server-side by the validator, and a field that shadows a model method is rejected rather than silently read as a relation. Rejecting tampered selections submitted from a browser is M5. |
| F07 | passing | `tests/Feature/MergePlannerTest.php` | An allowlisted field with an unsupported cast (array/JSON) is a configuration blocker, and an invalid definition now aborts planning before any field value is read, so the reported reason is the real cause. |

## Relationships and retirement (R)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| R01 | passing | `tests/Execution/MergeExecutionTest.php` | A merge moves every declared child to the survivor exactly once, and the retired source keeps its unique values and its row. The transfer is proven on real MySQL and PostgreSQL. |
| R02 | partial | `tests/Feature/MergePlannerTest.php` | A transfer that would collide with an existing child on a per-parent composite unique constraint is blocked before anything is written. The whole-merge rollback proof is M4. |
| R03 | partial | `tests/Execution/MergeGuardsTest.php` | Soft-deleted children move when the definition declares that ownership transfer is required, and stay with the retired source when it does not - the declared inventory is the inventory that moves. Child-level visibility scoping inside a relation is still only covered by the definition contract. |
| R04 | not started | — | |
| R05 | partial | `tests/Feature/MergePlannerTest.php`, `tests/Feature/DefinitionValidationTest.php` | Relation types v1 cannot transfer (has-one, belongs-to-many, morph-many, media library) are rejected as configuration blockers instead of being guessed, and an unsupported type declared inline is rejected the same way. Cross-connection relations are not covered yet. |
| R06 | passing | `tests/Feature/MergePlannerTest.php` | A transfer above the configured cap is blocked with the configured limit, and the boundary value is allowed. |
| R07 | passing | `tests/Execution/MergeRollbackTest.php` | A cancelled child save aborts the whole merge: fields, sibling children, retirement and the ledger entry all return to their original state, proven on both engines. |
| R08 | passing | `tests/Execution/MergeExecutionTest.php` | Proven with two real connections: a child written by a cooperating writer after the review makes the preview stale, and a writer that tries to attach a child to a retired source is refused. Raw SQL that ignores the protocol remains the documented limitation. |

## Merge execution (M)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| M01 | partial | `tests/Feature/MergePlannerTest.php` | A record merged into itself is refused, and a source already retired by an earlier merge is blocked. A survivor that is not part of the pair is refused. Missing/deleted-source handling and the cross-definition case arrive with execution in M4. |
| M02 | partial | `tests/Feature/MergePlannerTest.php` | Switching the survivor rebuilds the plan instead of reusing the previous one, and an expired preview is rejected. Definition-revision staleness is checked by `revalidate()` but its end-to-end case is M4. |
| M03 | passing | `tests/Feature/MergePlannerTest.php` | A merge-relevant field changed without touching `updated_at` is caught by the fingerprint and the plan is refused, while an untouched pair produces a stable fingerprint. |
| M04 | passing | `tests/Feature/MergePlannerTest.php` | A relationship change made after the preview is detected and the plan is refused instead of being silently recomputed. |
| M05 | partial | `tests/Execution/MergeExecutionTest.php`, `tests/Execution/MergeRollbackTest.php` | A retired source cannot be merged again and a competing plan is blocked at planning time; locks are taken in one canonical typed-key order; a held lock produces bounded retries and then `RetryExhausted`, not a hang. Live parallel execution of two merges in flight is not yet exercised end to end - the M0 lock spike plus these terminal-state cases cover the mechanisms, and M7 repeats it as a concurrency run. |
| M06 | passing | `tests/Execution/MergeExecutionTest.php` | A repeated operation token returns the recorded result with no second ledger row and no double transfer, which is the double-click and lost-response case. |
| M07 | passing | `tests/Execution/MergeExecutionTest.php`, `tests/Execution/MergeRollbackTest.php` | A reused token from another actor, in another scope, or with different choices is refused; a preview with an unresolved blocking reason cannot be executed; an unreadable history entry fails explicitly. |
| M08 | passing | `tests/Execution/MergeRollbackTest.php` | Failure is injected at all four mutation boundaries with real model events - survivor save, child save, source retirement, ledger write - and each one leaves fields, children, retirement state and ledger exactly as they were. |
| M09 | passing | `tests/Execution/MergeExecutionTest.php`, `tests/Unit/Merging/RetryPolicyTest.php` | Retries are bounded at three and exhausted rather than waiting for a lock; classification is proven for both drivers' concurrency error shapes and for the errors that must never be retried; an after-commit listener failure is reported on the result while the merge stays committed. |

## Authorization (A)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| A01 | partial | `tests/Execution/MergeExecutionTest.php`, `tests/Execution/MergeGuardsTest.php` | Reading merge history needs its own ability and the same scope; resolving a retired record needs the review ability and refuses records outside the acting scope; the executor fetches both records through the definition's visibility rules, so a hidden record cannot be merged by calling the service directly. UI-level leakage (counts, direct URLs) is M5. |
| A02 | passing | `tests/Execution/MergeExecutionTest.php` | A permission revoked between the preview and the execution aborts before any write, and the executor reauthorizes again inside the transaction. |
| A03 | passing | `tests/Feature/DefinitionValidationTest.php` | An unknown definition ID cannot be resolved, and an ability map grants nothing to an undeclared actor. |
| A04 | passing | `tests/Feature/DefinitionValidationTest.php` | Deny-by-default authorizer denies every ability; a service actor gets only explicitly declared abilities; review never implies merge. |
| A05 | partial | `tests/Feature/DuplicateReviewPageTest.php`, `tests/Feature/DuplicateMergePageTest.php` | The review page mounts through the panel plugin with the definition in the route. A definition ID the panel does not expose 404s before the registry is consulted; a forged `startScan` call from an actor without the scan ability 403s and queues nothing; the merge page requires the merge ability before it will even build a plan; every value in the review, comparison and audit views is escaped. Branch-tampered preview state is covered by the plan fingerprint and the choice allowlist, exercised at the executor level in M4. |

## Ledger (L)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| L01 | passing | `tests/Execution/MergeExecutionTest.php`, `tests/Execution/MergeGuardsTest.php` | A retired source stays terminal even after an external process restores the row, chains resolve to the active record, a loop is refused rather than followed, and no unmerge path exists. |
| L02 | passing | `tests/Execution/MergeExecutionTest.php`, `tests/Execution/MergeRollbackTest.php` | Pruning previews never touches the ledger and the operation still replays; a payload written under a different application key fails explicitly instead of returning blank history; history stays readable when the actor row is gone. |

## UI (U) and performance (P)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| U01 | partial | `tests/Feature/DuplicateReviewPageTest.php`, `tests/Feature/DuplicateMergePageTest.php`, `tests/Feature/FilamentTwoModelJourneyTest.php`, `tests/Feature/AssetRegistrationTest.php` | The pages ship translated strings, labelled radio inputs, `focus-visible` rings and staleness conveyed by text as well as tone; record markup is escaped on the review, comparison and audit views, and a package stylesheet adds a focus fallback, forced-colours borders and print behaviour. The browser walkthrough rendered the pages in a light and a dark panel on Filament 4.11.6 and 5.9.0, so those two themes are seen; an RTL pass and a keyboard-only pass on both majors are still outstanding. |
| U02 | partial | `tests/Feature/DuplicateReviewPageTest.php`, `tests/Feature/DuplicateMergePageTest.php`, `tests/Execution/MergePageJourneyTest.php` | Never-scanned, scanning, failed (sanitized reason code only), empty and has-results are distinct states that keep published results on screen; stale members are labelled and a capped preview says how many are hidden. The pair page renders the field comparison, relationship impact, blockers and the retirement warning; an incomplete choice blocks confirmation and a refused engine is reported as a failure with no success claim. On real MySQL and PostgreSQL a confirmed merge commits through the page and a pair changed after the preview is refused. Dismissal redirects to the review page. The browser walkthrough on both majors confirmed the scanning state (a page-started scan completed while the previous results stayed on screen), the completion panel with its audit reference, and that the merged group disappears from the list. |
| P01 | passing | `tests/Performance/ScanBenchmarkTest.php` | 100,000 records, 1,000-record chunks: 100 chunks, 7.11s, 48.5 MiB peak, 8 MiB growth, 1,413 SQL queries, 300,000 linear membership rows, 200 suggestions. Skipped unless `MERGE_DUPLICATES_BENCHMARK=1`. |

## Compatibility (C)

| ID | State | Test | Notes |
| --- | --- | --- | --- |
| C01 | partial | `tests/Execution/MergeRefusalTest.php`, `tests/Concurrency/LockSpikeTest.php` | Executing on SQLite is refused with a configuration error naming the engine and the supported ones, a definition whose model lives on another connection is refused as non-atomic, and the execution suite runs on real MySQL and PostgreSQL. The published engine/version matrix as a whole is M7. |
| C02 | partial | `tests/Feature/PackageBootTest.php`, `tests/Feature/FilamentActionRenderTest.php`, `tests/Feature/DuplicateReviewPageTest.php`, `docs/demo-walkthrough.md` | M0 evidence: both majors install, boot, resolve the panel plugin and render/mount a real action, on all four dependency lanes. M5 slice 4 adds an authenticated review journey on two unrelated models (integer key and UUID) via the real page, with forged-id and forged-action refusals. M5 slice 7 walks scan → review → compare → merge → audit by hand in two demo applications (Filament 5.9.0 and Filament 4.11.6, MySQL, two unrelated definitions per panel), including the merge and its audit entry; the walkthrough also produced the page-started-scan fix. Render hooks remain. |
| C03 | not started | `bin/lane-test.sh`, `bin/resolve-lane.sh` | M0 evidence: four lanes resolve and run, the published constraint `^4.0 \|\| ^5.0` resolves for both majors, and a separate required CI job proves installation on PHP 8.2, 8.3 and 8.4 for each major. Filament 5 behaviour is not executed on PHP 8.2 (dev tooling needs 8.3+) — see ADR 0008. |
| C04 | not started | — | 4 → 5 upgrade with existing data is M6. |
| C05 | partial | `tests/Feature/FilamentActionRenderTest.php`, `tests/Feature/DuplicateReviewPageTest.php`, `tests/Feature/DuplicateMergePageTest.php`, `tests/Feature/AssetRegistrationTest.php` | M0 evidence: no adapter was needed for the surfaces verified so far, and both majors passed identical assertions. M5 adds page route generation, panel page registration, Livewire mount from route parameters, Livewire state updates that rebuild a server-side plan, a Filament action with a confirmation modal mounted and called on a real page, notification/redirect assertions and package asset registration, verified on both majors with identical results and no adapter. The same pages were then rendered in a browser on Filament 4.11.6 and 5.9.0, and the panel chrome differences (topbar vs sidebar, modal host component) are the only visible divergence. Render hooks remain. |
| C06 | partial | `tests/Feature/DefinitionValidationTest.php`, `tests/Feature/DuplicateReviewPageTest.php`, `tests/Feature/FilamentTwoModelJourneyTest.php` | M1 evidence: two unrelated definitions with different rules, fields and labels coexist in one registry and are resolved by ID. M5 proves one panel plugin serves both an integer-keyed and a UUID-keyed definition, each with its own review URL and independent heading, that the UUID key domain survives route parameters and membership reads, and that an ID outside the panel list 404s. Shared terminal retirement identity across panels is M6. |
| C07 | partial | `tests/Feature/DuplicateReviewPageTest.php`, `tests/Feature/FilamentTwoModelJourneyTest.php` | The review page renders both the scalar-only UUID-keyed resource and the integer-keyed resource; the UUID definition with no soft deletes is shown as unmergeable rather than failing. Relation impact in the UI is covered on the relation-bearing fixture. Relation-bearing merge execution is already covered by M4. |

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
