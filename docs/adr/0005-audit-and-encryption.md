# ADR 0005 — Audit content, encryption and retention

Status: accepted (M0)

## Context

The audit entry is written in the same transaction as the merge and is the only
record of what was chosen. The specification requires that audit:

- contains before/after values only for declared audit fields;
- is encrypted with Laravel encryption;
- is access-controlled by a separate permission;
- never contains raw matching values, credentials, hidden fields or preview
  payloads, and never receives raw SQL or bindings on failure;
- remains readable without dereferencing a deleted actor;
- is not pruned while a terminal source mapping may still be needed.

## Decision

1. Default audit payload captures only configured merge fields and their chosen
   values, the survivor/source pair, moved child IDs and counts, and actor,
   panel, scope and configuration revision metadata.
2. The payload is stored encrypted (`encrypted` cast) in
   `filament_merge_duplicates_merges.audit_payload`. Preview plans are stored the
   same way in `filament_merge_duplicates_previews.plan_payload`.
3. Actor and tenant references are stored as **typed strings**, not hardwired
   integer foreign keys, and audit must render without resolving the actor row.
4. Matching digests are derived data and are never exposed or logged. Failure
   records store a sanitized error code and nothing else.
5. Key rotation is an explicit, documented operation. If decryption fails, the
   history view reports the failure for that entry instead of crashing, and the
   affected entries are reported rather than silently dropped.
6. All package tables live on the target model's connection. Cross-connection
   package tables are unsupported.
7. Retention: previews and stale scans/memberships have short, configurable
   retention; merge ledger and audit rows are retained while the source row or a
   terminal mapping may still be required.

## Consequences

- Audit is **not** an undo feature. There is no unmerge in v1, and restoring a
  soft-deleted source is explicitly not an unmerge.
- Losing `APP_KEY` makes audit and preview payloads unreadable. This is a
  deliberate trade-off, documented in the support matrix and error guide.
