# ADR 0002 — Visibility, counts and background scans

Status: accepted (M0)

## Context

A scan is queued per definition and trusted scope, and may index records that a
particular panel actor cannot view. The specification requires that the resource
banner count only buckets containing at least two records the actor may view, and
that hidden-record counts never leak. It also requires that a definition provide
a performant visibility query, because callbacks alone cannot justify leaking
aggregate counts.

A closure-based visibility check cannot be pushed into SQL, which would force
materialising every member of a bucket to compute a count.

## Decision

1. `ScopedRecordQuery` is a required contract with two explicit capabilities:
   - `constrain(Builder $query, DuplicateContext $context): Builder` — applies
     tenant/domain constraints, retains the model's global scopes, and excludes
     retired IDs.
   - `visibleTo(Builder $query, DuplicateContext $context): Builder` — a
     **SQL-expressible** authorization constraint, not a callback.
2. Banner counts are computed with `visibleTo()` joined against memberships
   inside the database, never by hydrating members in PHP.
3. Counts are bucket counts of visible matching buckets, not people and not
   pairs. Buckets are labelled as suggestions in all UI copy.
4. Oversized buckets are paginated for display, and any approximate counting
   behaviour must be stated explicitly in the UI before release.
5. Scan workers re-establish context explicitly. If the scope identity resolves
   to nothing (deleted tenant, missing actor intent), the job fails closed with
   `MissingContext` rather than querying unscoped data.
6. Only authorized actors may initiate a scope scan. CLI contexts are explicit
   and require a configured service actor; there is no implicit admin.

## Consequences

- Definitions that cannot express visibility in SQL are a configuration error
  (`InvalidConfiguration`), not a partially supported case.
- Global scopes on the host model are never removed or bypassed.
- `docs/support-matrix.md` records that the visibility query is a hard
  requirement for merge-capable definitions.
