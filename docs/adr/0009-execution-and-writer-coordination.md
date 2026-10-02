# ADR 0009 — Merge execution, writer coordination and failure semantics

Status: accepted (M4)

## Context

M4 makes the planner's proposal real: it writes the reviewed scalar result,
moves declared children, retires the source and records a terminal ledger entry.
Everything up to this point could be thrown away without consequence; from here
on a mistake destroys data. The decisions below fix what the executor refuses to
do, what it retries, and what a host has to uphold for the guarantee to hold.

## Decisions

### 1. Execution requires a driver that can make the merge atomic

`config('merge-duplicates.supported_merge_drivers')` (default `mysql`, `pgsql`)
is enforced at execution time, not just documented. A connection on SQLite is
refused with `invalid_configuration`, naming the engine and the supported ones.
Detection and review may still run there, because they are read-only; a merge may
not, because SQLite ignores `FOR UPDATE` and cannot prove the rollback the
guarantee depends on.

`tests/Execution/MergeRefusalTest.php` pins both the refusal and its message.

### 2. The model connection must equal the definition's connection

The transaction lives on `definition->connection()`. If the model resolves to a
different connection, the writes would commit outside the transaction and a
failure could not roll the merge back, so the executor refuses with
`invalid_configuration`. The definition validator already warns about a
connection mismatch; the executor blocks it, because a warning is not enough
where data loss is the outcome.

### 3. Locks are taken in one canonical typed-key order

`LockManager` sorts typed identifiers with a single rule and locks the scope
coordination row before both parents, then children. Every merge uses the same
order, so two overlapping merges queue instead of deadlocking. Any host path that
writes declared children must take the parent lock through the same protocol.

### 4. The writer protocol is a contract, not enforcement

`LockingWriterGuard` implements the coordination half that the package can
provide: lock the parent the same way, then refuse a parent the ledger says was
merged away. Raw SQL that never asks is outside what any portable package can
guarantee, which is exactly why `acknowledgesCompleteReferenceInventory()` and
the writer guard are required before merge is enabled at all. `tests/Execution`
proves both orderings with two real connections.

### 5. A preview carries the reasons it cannot be executed

Execution refuses a plan whose blockers include anything other than an unresolved
field choice. The resolvable blockers are re-derived from the plan's structured
differences, so if the planner's wording ever changes the re-derivation stops
matching and the check becomes stricter rather than looser.

### 6. Retries are bounded, and only concurrency failures are retried

`RetryPolicy` treats serialization failures, deadlocks and lock timeouts as
retryable on both drivers and nothing else. A constraint violation is never
retried: the same statement would fail again, and hiding it behind attempts only
delays the honest answer. Each attempt re-reads both records inside a fresh
transaction, so a retry never replays a stale model instance. Exhaustion raises
`RetryExhausted`.

### 7. After-commit work is not part of the merge

`MergeCompleted` is dispatched after the commit, with a `MergeContext` for host
listeners. A listener failure is logged, reported on `MergeResult`
(`notificationFailed`), and never reported as a rolled-back merge, because the
database contains the merge. Listeners must be idempotent and must not assume
they can undo anything.

### 8. Failure metadata is logged, not stored in a seventh table

The plan requires failure metadata outside the rolled-back transaction, and warns
that raw SQL, bindings and secrets must never reach user-visible audit. The
executor therefore logs a stable error code plus the operation, definition and
attempt counters, and dispatches `MergeFailed` with the same metadata. Adding a
table would change the published schema for information that is operational
rather than historical. This is a deliberate deviation from the spec's six-table
list and can be revisited if hosts need to query failures.

### 9. History needs its own ability: `Ability::ViewAudit`

Merging and reading what other actors merged are different permissions. The enum
gains one case, and `AuditReader` requires it *and* a matching scope before it
will decrypt anything. An unreadable entry (rotated application key, corrupt
payload) fails explicitly rather than returning blank history.

### 10. Audit keeps declared audit fields, and captures them before the write

The entry stores before/after values for fields declared as audited, the actor's
choices for every field, moved child identifiers and counts, the input
fingerprint and the configuration revision. Replaced values are captured before
`save()`, because Eloquent syncs its originals during save and reading them
afterwards would record the new value as the old one.

## Consequences

- Child identifiers in plans and ledgers are typed (`int:33`) rather than raw
  keys, so a plan, a ledger entry and a transfer can be compared directly.
- A trashed child moves only when the definition declares it; otherwise it stays
  with the retired source, and the declared inventory is the inventory that moves.
- `DomainConflict` currently carries three different situations: an event
  cancelling a write, an unreadable audit payload, and an already-committed
  operation with different choices. They are distinguishable by message only. A
  future milestone should give them distinct public codes rather than keep
  overloading one.
- `CompleteHasMany::signature()` does not include the soft-delete flag, so two
  strategies differing only in that flag share a relation signature. The
  definition revision still covers the change; a future revision should fold the
  flag into the signature.
