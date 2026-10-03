# Support matrix

Status: the boundaries below are implemented as described; detection, review, merge and
audit all work on both Filament majors. What is still missing is listed under "Explicit
non-claims" rather than left implicit. See [docs/test-case-map.md](test-case-map.md) for
the acceptance evidence and [docs/demo-walkthrough.md](demo-walkthrough.md) for the
hands-on demo on both majors.

## Platform

| Item | v1.0 |
| --- | --- |
| PHP | 8.2+ |
| Laravel | 12.x |
| Filament | 4.x **and** 5.x, from one release line |
| Livewire | 3.x on Filament 4, 4.x on Filament 5 (constrained by Filament) |
| Laravel 11 / 13 | Not supported in the initial promise |
| Filament 3 | Not supported |

PHP 8.2 supports both majors. One caveat is recorded here rather than hidden, because
"installable" and "verified" are different claims:

| Combination | Installable | Package tests executed |
| --- | --- | --- |
| Filament 4 on PHP 8.2 | Yes | Yes |
| Filament 4 on PHP 8.3+ | Yes | Yes |
| Filament 5 on PHP 8.2 | Yes | No — dev tooling needs PHP 8.3+ |
| Filament 5 on PHP 8.3+ | Yes | Yes |

See [ADR 0008](adr/0008-php-baseline-and-tooling.md).

## Database engines

| Engine | Detection, UI, dismissals | Merge execution |
| --- | --- | --- |
| MySQL 8 (InnoDB) | Supported | Supported |
| PostgreSQL 15+ | Supported | Supported |
| SQLite | Supported for tests and detection | **Refused** by design |

Merge rejects SQLite because it cannot provide equivalent row-lock guarantees.
Cross-database merges are unsupported.

## Capability boundary

| Capability | v1.0 |
| --- | --- |
| Exact normalized single-field and composite matching | Yes |
| Explainable buckets and resource banner | Yes |
| Review and merge exactly two records | Yes |
| Dismiss a pair as not duplicates | Yes |
| Allowlisted scalar fields, per-field conflict choice | Yes |
| Ordinary `HasMany` transfer with a declared inventory | Yes, within the writer contract |
| Soft-delete source + terminal merge ledger | Yes; SoftDeletes required to merge |
| Queued full scans, progress, cancellation | Yes |
| Tenant and authorization boundaries | Required |
| Fuzzy names, AI, probabilistic scores | No |
| Group or bulk merge | No |
| Undo / unmerge | No |
| Automatic merge or "merge all" | No |
| Media movement, external service writes | No |

## Per-definition requirements

A definition may be **detection-only** or **merge-capable**. Merge-capable
requires all of the following; anything missing is a configuration blocker, not a
partial merge.

| Requirement | Detection-only | Merge-capable |
| --- | --- | --- |
| Stable definition ID and `revision()` | Required | Required |
| Model with a single, supported primary key | Required | Required |
| Model on one connection, same as package tables | Required | Required |
| SQL-expressible visibility query | Required | Required |
| Authorizer (deny by default) | Required | Required |
| Field allowlist | Optional | Required |
| Validator | Optional | Required |
| Soft deletes | Optional | Required |
| Complete inbound reference inventory | Not required | Required |
| WriterGuard acknowledgement | Not required | Required |

## Relationship coverage

| Relation / reference | v1.0 behavior |
| --- | --- |
| Ordinary `HasMany` (conventional FK, same connection) | Transfer under lock, validation and authorization |
| Filtered `HasMany` (e.g. `activeItems`) | Not sufficient proof of coverage; requires an unfiltered ownership relation or an inventory adapter |
| `HasOne` | Block |
| `BelongsToMany` | Block |
| `MorphMany` / `MorphOne` / `MorphToMany` | Block |
| Self-referential trees | Block |
| `Through` relations | Block |
| Media library / attachments | Block transfer |
| External identifiers | Host declares a blocker or a tested integration |
| Cross-connection relations | Block |
| Composite primary keys | Block |

Child transfer uses per-model saves so configured casts and observers run, and is
capped (default 500 children per pair). Above the cap the merge is blocked with an
explanation instead of truncating. Bulk SQL is a future opt-in strategy with
different event semantics.

## Housekeeping

| Row | Pruned by | Notes |
| --- | --- | --- |
| Merge previews | `filament-merge-duplicates:prune` | Also refused as stale on read once expired, so pruning is housekeeping, not correctness. |
| Scans, memberships, dismissals | Nothing yet | `retention.scans_days` is reserved and unconsumed; these rows accumulate until a prune path is written. |
| Merge ledger and its audit payload | Never | A terminal source mapping has to outlive the record it refers to. |

## Explicit non-claims

- No generic support for every Eloquent model; unsupported cases stay visible with
  blockers.
- No "zero data loss" claim. The guarantee is conditional on the host upholding
  the WriterGuard protocol and on the declared reference inventory being complete.
- Soft-delete restore is **not** an unmerge.
- Scans, memberships and dismissals are **not pruned**: only expired previews are, and
  `retention.scans_days` currently does nothing.
- Merge execution is **not** queued: it runs inside the request that confirms it. Scans
  are queued and chunked.
- There is **no bulk or automatic merge**, and no unmerge.
