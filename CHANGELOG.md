# Changelog

All notable changes to `filament-merge-duplicates` will be documented in this file.

## v1.0.0 - 2026-10-03

The first stable release. One codebase for Filament 4 and Filament 5.

### Added

- **Definitions**: `DuplicateDefinition` plus the contracts a host implements -
  matching rules, field allowlists, relation strategies, retirement strategy,
  writer guard, scoped record query, authorizer and merge validator.
- **Detection**: scoped, chunked and resumable scans that store memberships per
  rule digest rather than record pairs, so index size stays linear and a review
  list is a suggestion rather than a verdict.
- **Review**: the review page, its pagination, per-group member titles, and the
  banner a resource header or render hook can show.
- **Comparison and merge**: the compare page with per-field choices, a single
  transaction that locks the scope and both parents in one order, fingerprint
  revalidation, child transfer, termination of the source, a terminal ledger entry
  and an audit history entry.
- **Audit**: the merge-history page and `AuditReader`, behind their own ability.
- **Dismissals**: "not duplicates" for one pair only, which reopens when the
  definition revision or the matching inputs change.
- **Commands**: `filament-merge-duplicates:scan` and
  `filament-merge-duplicates:prune`.
- **Events**: `ScanCompleted`, `ScanFailed`, `MergeCompleted` and
  `MergeFailed`, announced after the transaction commits, carrying sanitized
  error codes and no host data.
- **Authorization**: every ability is checked by the service that performs the
  operation, so a page, a command or a listener cannot bypass it.

### Fixed

- `RecordId::fromStored()` refused negative integer keys that `fromModel()`
  accepted, so such a record's ID could be written into a membership row and
  never read back.
- `DefinitionValidator` ended in a `TypeError` for a model with a composite
  primary key instead of reporting the identity blocker that explains it.

### Removed

- The unused `FilamentMergeDuplicates` facade, its class alias entry and the
  empty class it pointed at, plus a stub-publishing loop that iterated an empty
  directory.
