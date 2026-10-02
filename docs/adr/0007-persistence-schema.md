# ADR 0007 — Persistence schema

Status: accepted (M0). Migrations land in M1.

## Context

Package tables must sit on the target model's connection, must not collide with
host tables, and must avoid nullable columns inside unique constraints, because
`NULL` uniqueness differs between PostgreSQL and MySQL.

## Decision

All tables use the `filament_merge_duplicates_` prefix and UUID/ULID string
primary keys where the row is addressed from jobs or the browser, and
auto-increment only for high-volume membership rows.

### `filament_merge_duplicates_scopes`

Coordination and identity row. One row per definition per scope.

| Column | Notes |
| --- | --- |
| `id` | ULID, primary key |
| `definition_id` | `VARCHAR(191)`, registered definition ID |
| `definition_revision` | `VARCHAR(64)` |
| `scope_hash` | `CHAR(64)`, canonical scope identity digest |
| `connection` | `VARCHAR(64)` |
| `model_alias` | `VARCHAR(191)`, stable model alias |
| `current_generation_id` | nullable ULID, last successfully published generation |
| `timestamps` | `created_at`, `updated_at` |

Constraints: `unique(definition_id, scope_hash)`.

### `filament_merge_duplicates_scans`

One row per queued scan.

| Column | Notes |
| --- | --- |
| `id` | ULID, primary key |
| `scope_id` | ULID, indexed with `state` |
| `generation_id` | ULID |
| `config_revision` | `VARCHAR(64)`, definition revision at scan time |
| `state` | `VARCHAR(16)`: `queued`, `running`, `succeeded`, `failed`, `cancelled` |
| `cursor` | nullable `VARCHAR(191)`, keyset cursor (never an offset) |
| `counters` | JSON: scanned, skipped, memberships |
| `failure_code` | nullable `VARCHAR(64)`, sanitized |
| `heartbeat_at` | nullable timestamp |
| `started_at`, `finished_at` | nullable timestamps |
| `timestamps` | `created_at`, `updated_at` |

Constraints: `index(scope_id, state)`.

### `filament_merge_duplicates_memberships`

One row per record per rule per generation.

| Column | Notes |
| --- | --- |
| `id` | big increment, primary key |
| `generation_id` | ULID |
| `rule_id` | `VARCHAR(191)` |
| `digest` | `CHAR(64)`, HMAC of the encoded typed tuple |
| `record_id` | `VARCHAR(191)`, typed string |
| `record_id_type` | `VARCHAR(16)`: `int`, `string`, `uuid`, `ulid` |

Constraints: `unique(generation_id, rule_id, record_id)`,
`index(generation_id, rule_id, digest)`.

Bucket identity is `(generation_id, rule_id, digest)`. Pairs are never
materialised, so a shared generic value cannot produce quadratic rows.

### `filament_merge_duplicates_dismissals`

One row per dismissed pair per scope.

| Column | Notes |
| --- | --- |
| `id` | ULID, primary key |
| `scope_id` | ULID |
| `pair_hash` | `CHAR(64)`, canonical sorted typed pair |
| `record_ids` | JSON, sorted typed IDs |
| `signatures` | JSON, matching-input signatures of both records |
| `config_revision` | `VARCHAR(64)` |
| `definition_revision` | `VARCHAR(64)` |
| `actor_ref` | `VARCHAR(191)`, typed string actor reference |
| `reopened_at` | nullable timestamp |
| `timestamps` | `created_at`, `updated_at` |

Constraints: `unique(scope_id, pair_hash)`.

### `filament_merge_duplicates_previews`

Server-side merge plan. The browser receives only the opaque operation ID.

| Column | Notes |
| --- | --- |
| `id` | ULID, primary key |
| `operation_id` | ULID, `unique` |
| `scope_id` | ULID |
| `definition_id` | `VARCHAR(191)` |
| `panel_id` | `VARCHAR(191)` |
| `actor_ref` | `VARCHAR(191)` |
| `payload_hash` | `CHAR(64)` |
| `plan_payload` | encrypted text |
| `expires_at` | timestamp, indexed |
| `timestamps` | `created_at`, `updated_at` |

Constraints: `unique(operation_id)`, `index(expires_at)`.

### `filament_merge_duplicates_merges`

Terminal ledger plus audit.

| Column | Notes |
| --- | --- |
| `id` | ULID, primary key |
| `operation_id` | ULID, `unique` |
| `scope_id` | ULID |
| `retirement_domain` | `CHAR(64)`, excludes definition and panel |
| `source_id` | `VARCHAR(191)` |
| `source_id_type` | `VARCHAR(16)` |
| `survivor_id` | `VARCHAR(191)` |
| `survivor_id_type` | `VARCHAR(16)` |
| `actor_ref` | `VARCHAR(191)` |
| `definition_revision` | `VARCHAR(64)` |
| `audit_payload` | encrypted text |
| `committed_at` | timestamp |
| `timestamps` | `created_at`, `updated_at` |

Constraints: `unique(operation_id)`,
`unique(retirement_domain, source_id_type, source_id)`.

## Consequences

- The terminal uniqueness constraint is the idempotency and double-execution
  guard; a duplicate insert inside the merge transaction rolls the merge back.
- No nullable column appears in any unique index.
- Migrations are published and must install and roll back on MySQL 8 and
  PostgreSQL 15 in CI from M1 onward.
