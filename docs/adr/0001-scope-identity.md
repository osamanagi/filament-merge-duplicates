# ADR 0001 — Scope identity

Status: accepted (M0)

## Context

Detection, preview, dismissal, execution and audit must all be scoped to a
registered definition and a trusted context. The specification forbids accepting
a model class, field name, relation name or tenant scope from browser input, and
requires that the same definition used in multiple panels shares one data scope
while authorization stays per-panel.

Nullable tenant columns in unique constraints behave differently per engine
(PostgreSQL treats `NULL`s as distinct; MySQL does not), so a nullable
`tenant_id` cannot carry scope uniqueness.

## Decision

1. A `DuplicateDefinition` is the only source of model, connection, rules,
   fields and relation strategies. Definitions are container-resolved classes
   with a stable string ID and a `revision()` string.
2. A canonical **scope identity** is computed server-side as the tuple
   `(connection, definition ID, ownership domain, tenant discriminator)` and is
   persisted only as `scope_hash` (SHA-256 of the canonical encoded tuple,
   salted with `APP_KEY`).
3. Uniqueness is enforced on `unique(definition_id, scope_hash)` in
   `filament_merge_duplicates_scopes`. No nullable tenant column participates in
   any unique index.
4. Panel identity is **not** part of the scope hash. Two panels that expose the
   same definition with the same tenant share one scope, one scan and one
   suggestion set. Panel-specific authorization is applied when reading.
5. Any browser-supplied value (record ID, definition ID, field name, relation
   name, tenant) is treated as an untrusted lookup key that must resolve through
   the registry and the scope before use.

## Consequences

- Adding a tenant dimension to a definition changes the scope hash, so existing
  scans and dismissals for that definition are invalidated. This is intended and
  must be surfaced through the `revision()` string plus a documented rescan.
- `scope_hash` is derived data. It is not exposed in the UI and is not logged.
- Cross-connection relation strategies are rejected at configuration validation
  time because they cannot share the scope's connection.
