# ADR 0003 — Retirement identity

Status: accepted (M0)

## Context

After a successful merge the source row is soft-deleted and a terminal ledger
entry maps source to survivor. The specification requires that:

- a merged source ID is terminal and can never be merged again, even if the row
  is restored outside the plugin;
- the source remains present in the database, so a foreign key alone does not
  prevent new references to it;
- a second definition for the same model and ownership domain must not make a
  retired record mergeable again;
- the ledger must not be pruned while the source row may still exist.

If retirement identity were scoped by definition ID or panel ID, a second
definition (or a second panel) would silently bypass the terminal guarantee.

## Decision

1. Retirement identity is the tuple
   `(connection, registered model alias, canonical ownership domain, record ID type, record ID)`.
   It deliberately **omits** definition ID and panel ID, so every definition that
   shares a model and ownership domain shares the same terminal ledger.
2. Identity is persisted as `retirement_domain` (SHA-256 of the encoded tuple
   minus the record ID) plus `source_id_type` and `source_id`, with
   `unique(retirement_domain, source_id_type, source_id)` in
   `filament_merge_duplicates_merges`.
3. `Record ID type` is explicit (`int`, `string`, `uuid`, `ulid`) because the
   same textual ID can exist in different key domains, and because record IDs are
   never cast to integers (bigint-as-string and UUID/ULID keys must round-trip).
4. Model aliases are treated as stable. Changing an alias invalidates retirement
   identity; the diagnostics command reports orphaned ledger entries instead of
   silently reusing them.
5. `RetirementResolver` applies the current scope and view authorization and is
   bounded with cycle protection, because a survivor may itself be merged later.
6. Ledger entries are never pruned while the source row may still exist. Stale
   memberships, scans and previews have separate, shorter retention.

## Consequences

- `UniqueConstraintViolation` on the merges table is the concurrency guard for
  double execution, and is used as an idempotency signal (`M06`).
- Restoring a merged source row does not make it mergeable or attachable; host
  integration must call the published guard, and plugin pages enforce it
  themselves regardless of host behaviour.
- No global model scope is installed automatically: silently hiding restored rows
  would be a surprising host-wide behaviour change.
