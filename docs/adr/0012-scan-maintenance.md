# ADR 0012 — Recover abandoned scans without impersonating an actor

Status: accepted — 23 October 2026.

## Context

Scan chunks persist a cursor and heartbeat, but their queued job carries trusted
definition and context primitives only for its own execution. A worker crash or a
job that never starts can leave a queued or running scan blocking its scope.
Resuming later from the database alone would require reconstructing an actor,
tenant and panel identity, which would widen authority or guess context.

Finished scan rows and unpublished memberships are derived data. They accumulate
over time, while a scope's current generation is needed for review results.
Dismissals and merge history have different retention requirements.

## Decision

Add `ScanMaintenance` and `filament-merge-duplicates:recover-scans`. Maintenance
marks a queued scan older than `scan.heartbeat_timeout_minutes`, or a running scan
without a recent heartbeat, as failed with `scan_abandoned`. It rechecks the stale
condition in the update, so a recovered worker is not overwritten. The operator
starts a new scan through the ordinary authorized entry point.

Extend `filament-merge-duplicates:prune` to remove expired previews plus terminal
scan generations older than `retention.scans_days` when they are not the scope's
current generation. Memberships for each deleted generation are removed in the
same transaction. Add indexes for stale-heartbeat and terminal-retention queries.

## Consequences

Hosts schedule recovery more frequently than the configured timeout and pruning
daily. A stale scan does not resume automatically, even though its cursor exists.
Current published results remain visible through a failure. Dismissals, the
published generation, and merge ledger/audit data are never automatically pruned.
