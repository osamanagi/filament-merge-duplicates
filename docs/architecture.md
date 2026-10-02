# Architecture

Status: M0 design freeze. Implementation starts in M1.

## Dependency direction

```
Filament actions / pages / traits
        │  (thin: mounts actions, binds Livewire state, renders)
        ▼
Application services      (ScanCoordinator, MergePlanner, MergeExecutor, …)
        │  (authorization + validation enforced here, not in the UI)
        ▼
Contracts and DTOs        (DuplicateDefinition, Normalizer, MatchingRule, …)
        ▼
Persistence adapters      (Eloquent models, queries, ledger)
```

Merge logic must never live in a Livewire click handler. Calling a service
outside the panel must not bypass authorization.

## Planned source layout

| Path | Contents |
| --- | --- |
| `src/Contracts` | `DuplicateDefinition`, `ContextResolver`, `ScopedRecordQuery`, `Normalizer`, `MatchingRule`, `MergeAuthorizer`, `MergeValidator`, `RelationStrategy`, `RetirementStrategy`, `WriterGuard` |
| `src/Definitions` | Base definition class, field/rule builders, configuration validation |
| `src/Matching` | Rule implementations, stable rule IDs, reason generation |
| `src/Normalization` | Built-in normalizers and their version identifiers |
| `src/Data` | Key hashing, ID codecs, typed tuple encoding, field codecs |
| `src/Scanning` | `ScopeManager`, `KeyBuilder`, `ScanChunkProcessor`, `ScanCoordinator`, `SuggestionQuery`, `DismissalService`, `ScanState` |
| `src/Merging` | `MergePlanner`, `Fingerprinter`, `FieldDiffBuilder`, `SurvivorRecommender`, `RelationPlanBuilder`, `MergePlan`, `PreviewStore` |
| `src/Retirement` | `RetirementResolver` and the retirement domain digest |
| `src/Definitions` | `DefinitionRegistry` in addition to the base class and builders |
| `src/Relations` | `HasManyTransfer` and the relation inventory validator |
| `src/Authorization` | Default deny-by-default authorizer |
| `src/Models` | Package Eloquent models over the six package tables |
| `src/Jobs` | Chunked scan jobs, generations publication, bounded cleanup |
| `src/Commands` | `filament-merge-duplicates:scan`, diagnostics |
| `src/Exceptions` | Stable error codes |
| `src/Events` | Scan completed/failed, merge completed |
| `src/Filament/Actions` | `ReviewDuplicatesAction`, `MergePairAction`, `DismissPairAction` |
| `src/Filament/Pages` | Duplicate review page |
| `src/Filament/Concerns` | `HasDuplicateSuggestions` trait |

UI differences between Filament majors are isolated in small compatibility
adapters. Domain, persistence, authorization and merge logic stay shared.

## DTOs

`DuplicateContext`, `MatchReason`, `CandidateBucket`, `FieldDifference`,
`RelationImpact`, `MergePlan`, `MergeResult`.

## Stable error codes

`InvalidConfiguration`, `ForbiddenOperation`, `MissingContext`,
`RecordUnavailable`, `StalePreview`, `UnsupportedRelation`, `DomainConflict`,
`MergeTooLarge`, `RetryExhausted`.

## Design decisions

Recorded as ADRs:

- [0001 — Scope identity](adr/0001-scope-identity.md)
- [0002 — Visibility, counts and background scans](adr/0002-visibility-and-counts.md)
- [0003 — Retirement identity](adr/0003-retirement-identity.md)
- [0004 — Concurrency, lock ordering and the WriterGuard protocol](adr/0004-concurrency-and-writer-guard.md)
- [0005 — Audit content, encryption and retention](adr/0005-audit-and-encryption.md)
- [0006 — Record ID and field value codecs](adr/0006-id-and-field-codecs.md)
- [0007 — Persistence schema](adr/0007-persistence-schema.md)
- [0008 — PHP baseline, Filament 5 and the test-tooling boundary](adr/0008-php-baseline-and-tooling.md)

## M0 evidence

- Package boots and resolves its panel plugin on Filament 4 and Filament 5:
  `tests/Feature/PackageBootTest.php`.
- A real Filament action renders and mounts on both majors:
  `tests/Feature/FilamentActionRenderTest.php`.
- Row locks, writer-guard rejection and stale-child detection behave correctly on
  real MySQL 8 and PostgreSQL 15: `tests/Concurrency/LockSpikeTest.php`.
