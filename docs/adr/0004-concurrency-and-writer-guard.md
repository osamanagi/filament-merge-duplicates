# ADR 0004 — Concurrency, lock ordering and the WriterGuard protocol

Status: accepted (M0, spike verified)

## Context

The merge transaction must lock both parents deterministically, serialize
overlapping plugin merges, and stay safe against concurrent host writers. Two
limits are easy to get wrong:

- Row locks on the parents alone are **not** a portable guarantee that another
  endpoint will not attach a new child to the source mid-merge.
- After a soft delete the source row still exists, so a foreign key does not
  prevent new references.

The specification therefore requires a documented WriterGuard protocol for every
host path that creates or reassigns declared children, and requires that the
guarantee be proven against real engines on separate connections.

## Decision

1. All plugin mutations for one merge run in a single transaction on the
   definition's connection. Coordination rows, then both parents, are locked in
   **deterministic typed-key order** (record ID type, then canonical ID bytes).
2. Overlapping merges for the same scope serialize on a coordination row.
   Deadlock retries are bounded (default 3) and retried callbacks may not perform
   external side effects; model instances are reloaded and revalidated on retry.
3. Child sets are re-read and re-fingerprinted **inside** the transaction, so a
   child added between preview and execution produces `StalePreview` instead of a
   silent partial transfer.
4. The published WriterGuard protocol is: inside the writer's own transaction,
   lock the affected parent(s) using the same deterministic order, then read the
   terminal ledger/retirement state, then reject the write if the parent is
   retired. The plugin executor uses the exact same protocol.
5. Host onboarding must acknowledge the protocol and declare a complete inbound
   reference inventory. If the host cannot uphold it, detection remains available
   but merge is disabled with a configuration explanation.
6. Merge execution refuses SQLite outright, because SQLite cannot provide
   equivalent row-lock guarantees.
7. Observers are never globally suppressed. Host listeners receive a
   `MergeContext` and must make side effects after-commit and idempotent.

## Evidence (M0 spike)

`tests/Concurrency/LockSpikeTest.php` runs against real engines on separate
connections and proves:

| Case | MySQL 8.0.27 | PostgreSQL 15.13 |
| --- | --- | --- |
| Parent row lock is mutually exclusive across connections | pass | pass |
| WriterGuard path rejects writes to a retired source | pass | pass |
| Child added between preview and execution is detected as stale | pass | pass |
| SQLite is not accepted as a merge engine | pass | pass |

The spike is skipped, not passed, when no engine is reachable.

## Consequences

- Cross-database merges are unsupported and blocked, since no portable atomicity
  exists.
- The package cannot enforce unknown raw-SQL writers universally. The published
  guarantee is conditional on host integration, and documentation must say so
  rather than claiming "zero data loss".
