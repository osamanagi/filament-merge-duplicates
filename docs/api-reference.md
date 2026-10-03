# API reference

The v1 surface: what a host implements, what it calls, and what the package
guarantees. Internal classes are not part of the contract even when they are
`public` — the tables below say which side of that line each one is on.

## Registration

| Surface | Where |
| --- | --- |
| Definition classes | `config/merge-duplicates.php` → `definitions` |
| Panel exposure | `FilamentMergeDuplicatesPlugin::make()->definitions(['id', 'other-id'])` on the panel |

The registry resolves definitions from configuration, not from panels, so a
queued worker can resolve one without loading panel middleware. A panel lists the
subset it exposes; the review page rejects a definition the panel does not list
before the registry is consulted.

## `DuplicateDefinition`

Hosts extend the abstract class
(`Nagi\FilamentMergeDuplicates\Definitions\DuplicateDefinition`), which implements
`Contracts\DuplicateDefinition`. Implementations live in the consuming app.

| Method | Required | Contract |
| --- | --- | --- |
| `id(): string` | yes | Stable ID used by configuration, panels, jobs and the CLI. Renaming it orphans that definition's scans and dismissals. |
| `model(): string` | yes | Eloquent model. Must be soft-deletable for merging. |
| `label(): string` | yes | Shown on the review page and in notices. |
| `ownershipDomain(): string` | yes | Groups definitions that share a retirement identity. |
| `matchingRules(): list<MatchingRule>` | yes | What counts as a candidate. |
| `fields(): list<MergeField>` | yes | The scalar allowlist that may be merged. |
| `contextResolver(): ContextResolver` | yes | Resolves the trusted actor, panel and tenant. Must fail closed. |
| `scopedRecordQuery(): ScopedRecordQuery` | yes | Restricts records to the scope and to what the actor may view. |
| `authorizer(): MergeAuthorizer` | yes | Answers the five abilities. |
| `recordTitleAttribute(): ?string` | no | Attribute used to label a record in the UI. |
| `revision(): string` | no | Bump when the rules or fields change; part of the matching key. |
| `relations(): list<RelationStrategy>` | no | Declared inbound references. Default `[]`. |
| `scopeKeys(): list<string>` | no | Columns that make up the data scope. Default `[]`. |
| `validator(): ?MergeValidator` | no | Required to merge; a blocker otherwise. |
| `retirementStrategy(): ?RetirementStrategy` | no | Required to merge; a blocker otherwise. |
| `writerGuard(): ?WriterGuard` | no | Required to merge; a blocker otherwise. |
| `acknowledgesCompleteReferenceInventory(): bool` | no | Required to merge. `false` is a blocker. |
| `connection(): string` | no | Resolved from config or the model; overridable. |

`DefinitionValidator::validate()` turns a definition into a
`ConfigurationReport` of `ConfigurationIssue`s with a severity and a field name —
blockers stop merging, detection keeps working.

## Host contracts

The behavioural half of these contracts - what a host writer and an observer have to
do, and what the package guarantees in return - is in
[docs/integration.md](docs/integration.md).

| Contract | Methods | What an implementation has to guarantee |
| --- | --- | --- |
| `ContextResolver` | `actorRef()`, `panelId()`, `tenant()`, `contextFor(definitionId, connection, scopeHash)` | Returns primitives only, and throws `MissingContext` instead of guessing. The result is persisted with a queued scan and re-established by the worker. |
| `ScopedRecordQuery` | `constrain(Builder, DuplicateContext)`, `visibleTo(Builder, DuplicateContext)` | Both are SQL-side. `visibleTo` is what makes counts non-leaking: a filter applied in PHP after counting would leak group sizes. |
| `MergeAuthorizer` | `allows(DuplicateContext, Ability)` | Deny by default; answer each ability independently. |
| `MergeValidator` | `validate(DuplicateContext, array $proposed): array` | Returns per-field failures. Database constraints stay the final word; Filament form validation is never reused implicitly. |
| `RetirementStrategy` | `id()`, `requiresSoftDeletes()` | Declares how a source is retired. v1 always soft-deletes and requires the model to support it. |
| `WriterGuard` | `assertAcceptsNewChildren(...)` | Throws `RecordUnavailable` when a host writer is about to attach children to a retired record. |
| `RelationStrategy` | `name()`, `type()`, `ownsCompleteInventory()`, `includesSoftDeletedChildren()`, `signature()` | Only `HasMany` is transferable in v1; every other declared type must be declared so the validator can block it explicitly. |
| `MatchingRule` | `id()`, `label()`, `fieldNames()`, `signature()`, `components(array)` | A rule produces normalized tuple components; the digest is derived from them. |
| `Normalizer` | `version()`, `normalize(mixed): ?TypedValue` | Deterministic and versioned; the version is part of the key. |

## Provided implementations

| Class | Use |
| --- | --- |
| `Authorization\AbilityMapAuthorizer` | Grants a fixed list of abilities, optionally to one actor reference. Handy for a demo; a real app substitutes policy checks. |
| `Authorization\DenyAllMergeAuthorizer` | Denies everything. |
| `Authorization\ServiceContextResolver` | Explicit context for a service account: actor ref, panel id and tenant are supplied by the caller. Used by the CLI. |
| `Authorization\NullContextResolver` | Fails closed; a definition that does not override the resolver cannot run unscoped. |
| `Matching\ExactRule` | `ExactRule::make('name')->fields(['name'])->normalizeWith(TrimmedTextNormalizer::class, lowercase: true)->describedAs('Same name')`. Composite rules pass several field names. |
| `Normalization\TrimmedTextNormalizer` | Trims; optionally lowercases. |
| `Normalization\EmailNormalizer` | Trims and lowercases an email address. |
| `Normalization\IdentityNormalizer` | Passes a value through as-is. |
| `Definitions\MergeField` | `MergeField::make('phone')->label('Phone')`, plus `blankIsMissing()` and `audited(false)`. |
| `Relations\LockingWriterGuard` | `WriterGuard` that refuses new children for a retired record. |
| `Relations\HasManyTransfer` | Moves declared `HasMany` children, per-model save. |
| `Relations\RelationType` | Enum of relation types; `isSupportedInV1()`. |

## Merge flow

| Step | Service | Notes |
| --- | --- | --- |
| Preview | `Merging\MergePreviewService::preview(definition, context, first, second)` | Loads both records through the scope and visibility query, resolves the survivor, builds a `MergePlan` and stores it. Throws `RecordUnavailable` when a record is gone or invisible. |
| Plan | `Merging\MergePlan` | `differences` (with `FieldDiffBuilder`), `resolvableBlockers()`, `fatalBlockers()`, `choiceFields()`, `choicesComplete($choices)`, `childIdsFor($relation)`, `isConfirmable()`. The plan carries a fingerprint of both records' matching inputs plus the definition revision. |
| Execute | `Merging\MergeExecutor::execute(...)` | Re-checks the fingerprint, locks the scope and the records (`LockManager`), writes the survivor, transfers declared children, retires the source, writes the ledger and the audit entry — in one transaction. Returns a `MergeResult`. |
| Staleness | `Exceptions\StalePreview` | A preview that expired or a pair that changed since the preview. The UI rebuilds the plan instead of guessing. |
| Dismissal | `Scanning\DismissalService::dismiss(...)` | Records the pair's signatures so the unchanged pair is suppressed across scans and reopens when a matching input or the configuration changes. |
| History | `Merging\AuditReader::forOperation(...)`, `AuditWriter` | Encrypted audit payload, keyed by operation id. Reading requires the `view-audit` ability. |

`Merging\PreviewStore` keeps previews for `preview.ttl_minutes` and refuses an
expired one with `StalePreview`. It has a `pruneExpired()` method that no command
or schedule calls yet — see [docs/support-matrix.md](docs/support-matrix.md).

## Scanning

| Class | Role |
| --- | --- |
| `Scanning\ScopeManager` | Derives the scope identity, resolves the context, and ensures the scope row exists. |
| `Scanning\ScanStarter::start(definition, context)` | The authorized seam: checks `scan`, creates the scan row and queues the first `ProcessScanChunk` job. |
| `Scanning\ScanCoordinator` | Owns the lifecycle: `start()`, `run()` (drains inline, used by the CLI `--sync` and tests), `publish()`, `cancel()`, `fail()`. One queued or running scan per scope, enforced by a lock on the scope row. |
| `Jobs\ProcessScanChunk` | One chunk, re-dispatching itself while work remains; publishes only when every chunk succeeded. Carries primitives only. |
| `Scanning\ScanChunkProcessor` | The keyset chunk reader and membership writer. Idempotent. |
| `Scanning\DirectPairMatcher` | Re-computes the rule digests for two records so a pair that no longer matches a configured rule cannot be merged, even after a merge was accepted earlier. |
| `Scanning\SuggestionQuery` | Counts and reads buckets, visibility-aware. |
| `Scanning\ReviewGroupQuery`, `ReviewGroup`, `ReviewMember` | The review list read model: groups with members the acting user may see, plus `hasStaleMember()`. |
| `Scanning\ReviewSummaryQuery`, `ReviewSummary`, `ReviewState`, `ScanState` | What the header shows: published results, whether a scan is in progress, the last completion time and a sanitized failure code. |

## Filament surfaces

| Surface | Detail |
| --- | --- |
| `Filament\Pages\DuplicateReviewPage` | Slug `merge-duplicates/{definition}`. `urlForDefinition($id)`, `startScan()`, `goToPage()`. |
| `Filament\Pages\DuplicateMergePage` | Slug `merge-duplicates/{definition}/compare/{first}/{second}`. `urlForPair($definitionId, $first, $second)`, `setSurvivor()`, `setChoice()`, `confirm()`, `dismiss()`. |
| `Filament\Pages\DuplicateAuditPage` | Slug `merge-duplicates/{definition}/audit/{operation}`. `urlForOperation($definitionId, $operationId)`. |
| `FilamentMergeDuplicatesPlugin` | `definitions(iterable $ids)`, `getDefinitions()`. Registers the three pages on the panel. |
| `Filament\Concerns\HasDuplicateSuggestions` | For a host page: `duplicateDefinitionId()`, `duplicateDefinition()`, `duplicateContext()`, `canReviewDuplicates()`, `canMergeDuplicates()`, `canDismissDuplicates()`, `canViewDuplicateAudit()`, `duplicateBanner()`, `duplicateBannerView(?string $reviewUrl, string\|Htmlable\|null $scanAction)`, `panelDefinitionIds()`. |
| `Filament\Banner\DuplicateBannerFactory` | `viewFor($definitionId, $reviewUrl, $scanAction): ?View`, `forDefinitionId(): ?DuplicateBanner`, `canReview(): bool`. An unresolvable context or a denied ability yields `null`, never a zero count. |
| `Filament\Banner\DuplicateBanner` | Immutable banner state: group count, scan state, last completion, `failureCode()`. |

The pages are plain Livewire components with semantic markup and no Filament Blade
components, which is why one implementation renders on both majors.

## Commands

| Command | Options |
| --- | --- |
| `filament-merge-duplicates:scan {definition}` | `--actor=` (service actor reference), `--panel=cli`, `--tenant=`, `--sync` (drain inline instead of queueing). |

## Events

| Event | Payload |
| --- | --- |
| `Events\ScanCompleted` | The finished `ScanRecord`. |
| `Events\ScanFailed` | The `ScanRecord` and the sanitized failure code. |
| `Events\MergeCompleted` | The merge record and the `MergeResult`. |
| `Events\MergeFailed` | The merge record and the failure code. |

Events are announced after the transaction commits, so a listener never observes
a state the database does not have. Loading UI is not wired to them; the pages
poll while a scan is active.

## Exceptions

All of them extend `Exceptions\MergeDuplicatesException` and expose
`errorCode()`, which is the only thing that is ever persisted or shown. See
[docs/errors.md](docs/errors.md) for what triggers each code and what a host
should do.

## Data types

| Class | Purpose |
| --- | --- |
| `Data\DuplicateContext` | definition id, connection, scope hash, actor ref, panel id, tenant. `toStorableArray()` / `fromStorableArray()` for queued work. |
| `Data\RecordId` | A typed record key (int-string or UUID) with ordering and comparison rules, so a bigint key stays exact. |
| `Data\TypedValue` | A normalized value with its type, used in digests and in the comparison grid. |
| `Data\ScopeIdentity`, `Data\ScopeHasher` | Derive and compare scope identity. |
| `Data\KeyHasher`, `Data\TupleEncoder` | HMAC over an encoded tuple. The secret defaults to `app.key`. |
| `Data\ConfigurationReport`, `Data\ConfigurationIssue`, `Data\IssueSeverity` | Validation output for a definition. |
| `Merging\MergeResult`, `Merging\RelationImpact`, `Merging\SurvivorRecommendation` | Execution and comparison results. |
