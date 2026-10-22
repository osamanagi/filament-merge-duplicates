# Implementation review — 22 October 2026

The plugin implements the central idea well: exact matching produces suggestions,
review creates a scoped preview, and a transaction performs a deliberate pair merge.
The architecture is mostly justified for destructive operations. It was not ready
to call fully correct before this review: important checks existed only in the UI
or preview, and several database and concurrency paths needed fixes.

The review compared the implementation plan with package services, configuration,
migrations, Filament pages, fixtures, CI, and the tests. This is a source review
and automated verification, not certification of arbitrary host applications.

## Outstanding findings

### P1 — Key rotation changes permanent retirement identities

`RetirementResolver::domainDigest()` uses `KeyHasher`, whose input includes the
matching secret and `key_version`. A merge records its source under that digest.
After either changes, `isRetired()` and survivor resolution search a different
domain. A restored source can then escape terminal retirement checks. This also
happens on application-key rotation when the matching secret falls back to
`app.key`; encryption-key fallback alone cannot fix the lookup.

Reproduction: merge a matching pair, restore its source, change `key_version`,
restart the process to rebuild the singleton hasher, and ask whether the source
is retired. The old mapping is no longer found. Rescanning does not repair it.

Keep these values stable after merging. README and configuration guidance now say
so. Before promising rotation support, give terminal identity a stable encoding
independent of matching-key rotation and migrate existing ledger domains while
the original configuration is available. The migration must preserve uniqueness
across definitions sharing a model/ownership domain. Silently changing the digest
algorithm would itself strand existing mappings, so this review does not do that.

### P2 — The release evidence does not satisfy the original plan

The plan explicitly requires 100% executable `src/` line coverage on both Filament
majors. Composer and CI enforce 98%. Existing handover documentation recasts the
requirement as coverage of “reachable” lines, which is not what the plan says.
The measured 98.0% in this review is not 100%, and passing this gate is not evidence
that M7 is complete. Settle the requirement explicitly rather than describing it
as achieved. Important bugs found here were compatible with high line coverage.

Only the installed Filament 5.9.0 / PHP 8.4.8 lane was rerun here. Both database
engines were exercised. Historical Filament 4 results and CI descriptions were
read, but the Filament 4 matrix, dependency lower bounds, and browser journeys
were not independently rerun during this review.

### P2 — Scope and policy guarantees depend on host integration

`MergeAuthorizer` answers a context-level ability; it does not accept the actual
pair or children. Eloquent `save()` does not automatically authorize record-specific
update/delete policies. Hosts must explicitly make their definition's scope and
merge ability appropriate for every affected record. If independent per-record
mutation permissions are a supported requirement, extend the authorization
contract and test mixed-permission pairs and children. A visible record is not
automatically an editable record.

The WriterGuard contract also remains essential. Unknown host writers and external
observer side effects cannot be made transactional by the package.

### P2 — Scan housekeeping and recovery remain incomplete

Expired previews are pruned, but finished scans, memberships, and dismissals
accumulate. Heartbeats and cursors exist, but there is no automatic recovery of a
scan abandoned after worker/process or queue-dispatch failure. A queued/running
row can block subsequent scans until cancelled. These are operational gaps, not
reasons to replace the detection algorithm. Unused retention options were removed
so configuration does not imply an implemented feature.

## Correctness fixes made

| Area | Problem | Result |
| --- | --- | --- |
| Merge execution | Direct matching was only a UI blocker; forged component state could bypass it | Executor checks a configured direct match using freshly loaded records |
| Validation | Domain validation only saw default preview values | Selected values are validated with the trusted tenant context before any field write, including merges that change no scalar fields |
| Relationship revalidation | New relation blockers were computed but ignored | Execution refuses newly discovered conflicts |
| Postconditions | Child deletion/reassignment and changes to retained fields could evade checks | Persisted child IDs/foreign keys and all selected scalar values are verified before commit; both parent states are refreshed |
| Replay | An empty choice array bypassed the changed-payload check; a request waiting for locks did not recheck the ledger | Choices are compared in canonical field order, including empty input; the ledger is checked again after locks |
| Completion | Events could run before an enclosing transaction committed; notification reads could throw after a successful commit | Completion waits for the outer commit; rolled-back outer transactions dispatch nothing; notification reads are included in failure handling |
| Primary keys | Locks hardcoded `id` | Executor and writer guard pass the model's actual primary-key column |
| Relations | Alternate local keys could be transferred as if they referenced the parent primary key | Nonconventional relations are explicitly blocked, consistent with the v1 support boundary |
| Scans | Stale workers could overwrite cursors/counters; cancellation could be overwritten or followed by publication | Chunk state is reloaded under a row lock; memberships and progress commit together; terminal states cannot be revived by stale coordinator instances |
| Scan context | Jobs did not verify scan scope/revision against the supplied definition | Each chunk validates its stored scope, connection, and revision |
| CLI/queue | Naming an actor was enough to start a CLI scan; dispatch could precede a host transaction's commit | CLI checks the scan ability; chunk jobs are dispatched after commit |
| Suggestions | Scope depended on `visibleTo()` also applying tenancy | Scope and actor visibility are both applied explicitly; fixtures now exercise them separately |
| PostgreSQL | Integer visible IDs were joined directly to string membership IDs | Visible keys are explicitly cast to the engine's string type |
| Restored sources | Old memberships could count a merged source as a suggestion | Suggestion queries exclude terminal source mappings |
| Pagination | SQL pagination happened before dismissal filtering | Offsets and limits apply to the filtered suggestion sequence |
| Persistence | Configured package connection overrode explicit `Model::on()` connections | Explicit model connections take precedence |
| Scope creation | Handwritten duplicate-insert recovery could leave a PostgreSQL transaction aborted | Eloquent `firstOrCreate()` supplies its built-in savepoint-aware conflict handling |

## Efficiency and complexity

Keep the typed ID/value codecs, scoped definitions, HMAC matching keys, unpublished
scan generations, encrypted previews, permanent ledger, and transaction checks.
Each protects a concrete invariant. Replacing the child saves with one bulk update
would change observer semantics: Laravel does not dispatch per-model update events
for mass updates. See [Eloquent mass updates](https://laravel.com/docs/12.x/eloquent#mass-updates).

The inefficient relationship reads were unnecessary. The planner now counts in
SQL, bounds source hydration, and uses indexed existence queries for survivor
collisions. The transfer adapter loads at most the configured cap plus one before
rejecting a large inventory. Bucket counts stream results instead of building an
array of every bucket. Pair persistence remains linear in records/rules, not
quadratic in possible pairs.

There is still a worthwhile future optimization: two-member buckets require live
dismissal checks, producing multiple queries per bucket, and review totals and page
contents repeat some work. Batch those lookups before adding caching layers or
more service abstractions. Larger fully dismissed buckets remain approximate, as
already documented. Do not replace that with unbounded pair generation.

`MergeExecutor` and `DefinitionValidator` are large. Extract a focused collaborator
only when it removes duplicated behavior or isolates a meaningful responsibility;
splitting every private method into a new class would add indirection without
improving this plugin.

## Laravel conventions and cleanup

The existing `Contracts`, `Models`, `Jobs`, `Commands`, `Events`, `Exceptions`,
`Filament/Pages`, and `Filament/Concerns` names are conventional and useful.
`Merging` and `Scanning` describe cohesive package domains; a generic `Services`
folder would not be an improvement. Renaming every `*Record` model or moving
`Data` to `DTOs` would create public API churn without fixing behavior.

Changes here remove the empty Livewire testing mixin and its boot registration,
the commented factory and unused factory namespace/resolver, the empty JavaScript
entry point/build, and unused retention settings. The debug prohibition test now
lives at `tests/Architecture/ArchitectureTest.php`.

Filament page route identities and merge operation tokens use Livewire's `Locked`
attribute. Services still validate merge inputs; locked properties supplement those
checks. Queue dispatch follows Laravel's
[after-commit behavior](https://laravel.com/docs/12.x/queues#jobs-and-database-transactions).

## Verification

- Full Pest suite with pcov, real local MySQL and PostgreSQL: **674 passed,
  1 skipped, 1,907 assertions**. The skipped case is the opt-in benchmark, run
  separately below. Coverage: **98.0%** of `src/` on the installed Filament 5 lane.
- PHPStan level 5: no errors.
- Pint: checked changed files, including the new regression tests.
- Composer strict validation: valid; no dependency versions changed.
- CSS build: successful after removing the unused JavaScript build.
- Benchmark: see the recorded review result in `docs/testing.md`.

New tests exercise observable database outcomes, rollback, cancellation, stale
worker state, forged Livewire updates, selected-value validation, PostgreSQL joins,
bounded model hydration, and outer-transaction completion behavior. Existing
tests expecting restored terminal sources to remain in suggestions were updated
to assert their exclusion. Execution fixtures now declare a second matching rule
so field-conflict tests still exercise legitimately matching pairs.

The changes improve correctness without replacing the architecture. Resolve the
terminal-identity rotation problem and reconcile the remaining release criteria
before calling the plugin production-ready against the original plan.
