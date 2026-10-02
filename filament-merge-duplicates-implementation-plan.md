# Filament Merge Duplicates — AI Implementation Plan

Version: 1.1 planning specification (Filament 4 + 5 required) · 2 October 2026

Owner: Osama Nagi. Package name: Filament Merge Duplicates. Composer name: `osamanagi/filament-merge-duplicates`. Namespace: `Nagi\FilamentMergeDuplicates`. Verify repository and Packagist availability before publishing.

This is a proposed implementation specification, not an existing package API. Implement the decisions below in order. Do not interpret examples as methods provided by Filament. Defaults are deliberate and can be changed through a documented architecture decision, not silently during implementation.

## 1. Product goal and release boundaries

Give eligible Filament resources an iPhone Contacts-style experience: discover possible duplicate records, explain the match, review differences, and merge deliberately without losing declared relationships. The plugin must not assume a particular business model or schema: developers register independent definitions for any supported Eloquent model exposed through a Filament resource, including resources in the same panel and model used in multiple panels. Resource names, fields, matching rules, scope, authorization and relationship strategies come from those definitions. “Any resource” means a resource can opt in through configuration when its model, key, connection, lifecycle and relationships satisfy the published support matrix; unsupported cases remain visible with clear blockers rather than unsafe generic merging.

Primary users: Laravel developers configuring resources and authorized panel users reviewing duplicate records. Example applications may use contacts, products, articles or other models solely as fixtures; none is a required host schema.

Success means a developer can install the package, configure one resource definition, run a scan, see its resource banner, review a pair, merge it, and verify the final record and audit entry. A second resource with different fields and rules must work in the same panel without modifying plugin internals. If the definition declares a supported relation, its references are transferred and verified. A detection suggestion never authorizes a merge.

| Capability | v1.0 | Later |
| --- | --- | --- |
| Exact normalized single-field and composite matching | Yes | — |
| Explainable duplicate buckets and resource banner | Yes | — |
| Review and merge exactly two records | Yes | Group/bulk merge |
| Dismiss a pair as not duplicates | Yes | Personal dismissals |
| Allowlisted scalar fields and per-field conflict selection | Yes | JSON, translations, custom editing |
| Explicit ordinary HasMany transfers | Yes, within documented writer contract | Additional relation adapters |
| Soft-delete the source and store merge ledger | Yes; SoftDeletes required to merge | Custom retirement, hard-delete |
| Queued full scans, progress, cancellation | Yes | Incremental indexing |
| Tenant and authorization boundaries | Required | — |
| Fuzzy names, AI matching, probabilistic scores | No | Optional later |
| Undo/unmerge | No; audit is not undo | Separate feature with change tracking |
| Automatic merge or Merge All | No | Only after independent safety design |
| Media movement, external service writes | No | Custom integrations |

Non-SoftDeletes models may use detection and dismissal in v1; merge remains disabled with a configuration explanation. Multi-database merges, composite primary keys, arbitrary relation discovery, and authenticated User/account merging are unsupported in v1. Do not claim generic support for every Eloquent model.

## 2. Platform and compatibility decisions

Required v1.0 support: PHP 8.2+, Laravel 12.x, and **both Filament 4.x and 5.x**, from the first stable package release. Proposed Composer requirement: `"filament/filament": "^4.0 || ^5.0"`. This permits either major in a host application, not both simultaneously. Filament 3 is unsupported. Laravel 11/13 are outside the initial package support promise until explicitly added and tested. Filament's documented minimum requirements are broader than this package's Laravel baseline.

Filament 4 uses Livewire 3; Filament 5 requires Livewire 4. Let Filament constrain its compatible Livewire version. If the package must declare Livewire directly, use a compatible union validated in M0, not a v4-only pin. Never override Composer to force an unsupported pairing. Other Filament component requirements, if directly declared, must also allow both supported majors and resolve together.

One package release line and one public developer API must support both majors. Keep matching, persistence, validation, authorization, and merge execution shared. Prefer APIs common to 4 and 5; isolate unavoidable UI/testing differences behind small compatibility adapters. Avoid sprinkling version checks through services. Validate the installed major from Composer metadata server-side; reject unsupported majors clearly. Adapters must preserve identical data and permission behavior. Support is an acceptance requirement, not a future roadmap item.

### Required compatibility CI

| Lane | Laravel | Filament | Livewire | Required checks |
| --- | --- | --- | --- | --- |
| F4 minimum | 12.x | Lowest permitted 4.x dependency set | Compatible 3.x | Composer resolution, package boot, core and Filament journey tests |
| F4 current | 12.x | Latest compatible 4.x | Compatible 3.x | Full suite, static analysis, real MySQL/PostgreSQL safety tests |
| F5 minimum | 12.x | Lowest permitted 5.x dependency set | Compatible 4.x | Composer resolution, package boot, core and Filament journey tests |
| F5 current | 12.x | Latest compatible 5.x | Compatible 4.x | Full suite, static analysis, real MySQL/PostgreSQL safety tests |

Run the published minimum PHP and at least one current compatible PHP version per major; record exact resolved versions and configure Testbench/Pest versions accordingly. Dependency lower bounds must be genuinely tested: if an early minor lacks a required API, introduce a shared fallback or explicitly document an approved narrower constraint. Never silently raise the lower bound or leave `^4.0 || ^5.0` in Composer while requiring a later-only API. Lower-bound resolution must respect known security fixes; record justified security exclusions without bypassing advisory checks. Demo fixtures must exercise both installation lanes. Keep a tested 4→5 upgrade scenario using existing package tables, scans, dismissals and merge history; upgrading Filament must not require a data reset.

Production merge support: MySQL 8 with InnoDB and PostgreSQL 15+, tested in real database CI. SQLite can run normalization, detection, and UI tests, but merge execution must reject SQLite in v1 rather than imply equivalent row-lock guarantees. Store package tables on the same connection as the target model; reject cross-connection relation strategies. Optional engines are future work.

Use an MIT license, Composer package discovery, a Laravel service provider, a Filament panel plugin, publishable migrations/config/translations, Pest + Orchestra Testbench, Pint, and PHPStan. All package tests use Pest. Exact dependency versions must come from the baseline compatibility spike and committed lockfile for the demo. Do not add a Node runtime, external service, or AI dependency to detection.

## 3. Invariants — non-negotiable implementation rules

1. Detection, preview, dismissals, execution, and audit access are scoped to a registered definition and trusted context.
2. Never merge across models, connections, tenants, or authorization boundaries. Never accept a model class, field name, relation name, or tenant scope directly from browser input.
3. Preserve the survivor's primary key. Do not manufacture a new record or change IDs.
4. No background or similarity rule merges records automatically.
5. No successful merge may silently drop an approved related record or an unresolved conflicting value.
6. All database merge mutations and the success audit are committed in one transaction. Any failure rolls them back.
7. Recheck permissions, scope, versions, rules, and constraints at execution, even after a valid preview.
8. Merged source IDs are terminal in the ledger and cannot be reused in another merge, even if restored outside the plugin.
9. Unsupported relationships and constraints must produce a blocker, not a guessed strategy.
10. Host-application side effects and concurrent writers have explicit integration requirements. Database rollback does not undo an email, filesystem write, or external API call.

## 4. User journeys and UI specification

### 4.1 Resource list

An opt-in resource integration displays a lightweight banner above the table: “3 possible duplicate groups found — Review”. Count means visible matching buckets, not unique people or pairs. Show scan completion time and “results may have changed”. Count only buckets containing at least two records the actor may view; do not leak hidden-record counts.

When no scan exists, show Scan for duplicates if authorized. During scan, show progress without replacing the last successful results. Failure shows a retry and sanitized reason; an empty result after successful scanning is distinct from “not scanned”. Provide configurable polling only during active scans; do not scan on every page render.

### 4.2 Duplicate review page

List buckets with rule label, visible members, timestamp, and Review. Re-fetch scoped live records before displaying details; removed/changed members are marked stale. Groups can overlap because multiple rules can match. Clearly label them as suggestions. Paginate members of oversized buckets. Review two members at a time.

Review page layout: record headers with primary-key/title; matching reasons; a field comparison grid; survivor selection; relationship impact; blockers; final confirmation. Changing survivor rebuilds the proposed result and invalidates previous preview tokens. Choosing Not duplicates dismisses the pair rather than the whole bucket.

### 4.3 Merge preview

Defaults: recommend the older record using created_at, then stable ID ordering, but require confirmation. For models without created_at, use stable ID ordering. Proposed scalar values follow section 7. Show every configured field that differs, including those retaining the survivor's value. Generate impact text from the resource's configured labels and relation adapters, for example “Move 2 related items to Record #42; resulting total 7”. Source retirement uses the configured resource label, for example “Record #87 will be soft-deleted and marked merged”. Warn that later restoring that row is not an unmerge. Never hardcode a particular model, column or relationship label in plugin UI.

Confirmation is enabled only with complete choices, valid authorization, supported relation plan, and no blockers. Disable repeated clicks, but enforce idempotency server-side. On success show survivor link and audit reference. On stale data show refresh/review action; never silently rebuild and submit. On failure do not announce success.

### 4.4 Accessibility and presentation

Use native Filament components where possible. Support dark mode, responsive stacked comparisons, keyboard navigation, visible focus, labeled radio options, non-color-only match/conflict indicators, translated strings, and RTL layouts. English strings required; Arabic translation is a useful first contribution. Escape record values and render rich text as plain text in v1 comparisons.

## 5. Developer integration and proposed API

Definitions live in container-resolvable classes registered by a Laravel config/service provider. Panels reference definition IDs; workers resolve definitions without loading panel middleware or serializing closures. Each registered resource gets its own stable definition ID, model, label/record title, matching rules, field allowlist, context resolver, scope query, authorization, relation strategies, validation, retirement and revision string. One panel may contain multiple unrelated definitions; one model may appear in multiple panels. Definitions sharing a model and ownership domain share terminal retirement identity as described in section 8.3. Resources without relationships may declare an empty relation strategy list only after explicitly acknowledging a complete reference inventory and writer contract. The resource integration must not require changes to the host model's table schema beyond published package tables.

Illustrative target API, to implement and test. `ExampleRecord` and `relatedItems` are placeholders from a sample application, not package requirements:

```php
final class ExampleRecordDuplicates extends DuplicateDefinition
{
    public function id(): string { return 'example-records'; }
    public function model(): string { return ExampleRecord::class; }
    public function revision(): string { return '1'; }

    public function matchingRules(): array
    {
        return [
            ExactRule::make('reference')->fields(['reference'])
                ->normalizeWith(TrimmedTextNormalizer::class),
        ];
    }

    public function fields(): array
    {
        return [
            MergeField::make('reference')->label('Reference'),
            MergeField::make('display_name')->label('Display name'),
            MergeField::make('notes')->label('Notes'),
        ];
    }

    public function relations(): array
    {
        return [HasManyTransfer::make('relatedItems')];
    }
    // Implement required context, scopedQuery, authorize, validate,
    // relationship inventory and writer-guard contracts; no permissive defaults.
}
```

If the sample `reference` field is unique in the host database, v1 must block transfer when the source retains the key; see section 7. Sample fixtures should include both unique and nonunique fields to demonstrate this distinction.

Proposed panel registration: `MergeDuplicatesPlugin::make()->definitions(['example-records', 'other-records'])`. Resource integration: `HasDuplicateSuggestions` trait plus a documented hook/page registration after verifying actual Filament 4 and 5 extension points. Action APIs: `ReviewDuplicatesAction`, `MergePairAction`, `DismissPairAction`. Do not depend on replacing vendor views globally. CLI target: `filament-merge-duplicates:scan example-records --scope=<scope-reference>`; scope references resolve server-side through the definition. Provide a guide showing at least two unrelated resources with different matching rules and field schemas in one panel, with no edits to package source.

### Required contracts

| Contract | Responsibility |
| --- | --- |
| DuplicateDefinition | Stable registry ID, model, revision, complete integration configuration |
| ContextResolver | Resolve trusted scope and actor; CLI/service contexts must be explicit |
| ScopedRecordQuery | Apply tenant/domain constraints and exclude merged IDs; retain global scopes |
| Normalizer | Pure, deterministic, versioned typed value to normalized value or no-match |
| MatchingRule | Stable ID; composite key; human-readable reason |
| MergeAuthorizer | Review/dismiss/scan and pair merge permissions; deny by default |
| MergeValidator | Validate proposed values, uniqueness, domain rules and relationship effects |
| RelationStrategy | Preview, locks, fingerprint, validate, execute and result summary |
| RetirementStrategy | v1 built-in soft-delete with terminal merge ledger |
| WriterGuard | Coordinate writes targeting mergeable parents; reject merged source IDs |

Runtime configuration checks must catch missing contracts, repeated IDs, unsupported model keys/connections, unsafe fields, conflicting relation declarations, unsupported engine, and inconsistent revision. Closures may be local callbacks but must never be serialized in jobs; class-based definitions are the canonical supported path.

## 6. Detection engine

### 6.1 Rules and normalization

Rules match exact normalized keys. A composite rule requires all components and equality of the full tuple. Multiple rules are OR suggestions, not proof of identity. Null, whitespace-only strings, invalid values, and missing required components produce no key; do not group every blank email together. Preserve original stored values.

Use typed canonical serialization with fixed field order, not delimiter concatenation. Distinguish integer 0, string '0', false, null, and empty unless a field-specific normalizer intentionally equates them. Do not cast record IDs to integers; support bigint-as-string and UUID/ULID.

Built-ins: trimmed text with explicit case option; conservative email trimming and lowercasing domain only by default (local-part lowercasing opt-in); identity scalar. Optional phone normalizer requires an explicitly configured country and a maintained phone parser; defer built-in phone support if dependency/baseline work is significant. Never assume +20 or strip numbers into accidental matches. Unicode NFC normalization needs ext-intl and tests if enabled. Do not remove Arabic diacritics, transliterate names, strip email plus tags/dots, or assume names are unique by default.

Key construction: HMAC-SHA256 of typed tuple, scoped by definition/rule/revision/normalizer version, using configured application secret. Persist digest, not raw matching data. Digests are still sensitive derived data; do not expose them. Key rotation invalidates generations and dismissals; document rescan. No probabilistic “98% match” for exact rules.

### 6.2 Buckets, overlap and false positives

Index one membership per record/rule. Bucket identity is scoped definition + generation + rule + digest. A bucket with two or more current eligible members is a suggestion. Avoid materializing every pair; that is quadratic for shared phone numbers or generic emails.

If A matches B by email and B matches C by phone, do not automatically infer A equals C. Show overlapping buckets. v1 only merges pairs that directly match at execution. Deduplicate identical pair reasons in the review UI. A bucket with multiple members does not grant group merge.

Dismissal is scope-wide by default for authorized reviewers. Store canonical sorted pair IDs and signatures of both records' matching inputs plus definition revision. Suppress the unchanged pair across scans. Reconsider when matching inputs or configuration changes. An irrelevant address update does not invalidate dismissal. Allow authorized re-open. An entirely dismissed visible pair must not keep a two-member banner alive; larger buckets can remain while undisposed pairs exist. Compute this without generating all pair rows; bound work/paginate large buckets and describe any approximate banner behavior explicitly before release.

### 6.3 Scan lifecycle

Queue a scan for one definition and trusted scope. Persist scope identity and service/actor intent, never session objects. Workers re-establish context explicitly; if unavailable, fail closed. Permit one publishing scan per definition/scope using an atomic lock or database guard. States: queued, running, succeeded, failed, cancelled. Jobs must be idempotent with bounded retries, heartbeat and resumable chunk cursor.

Build an unpublished generation by stable keyset chunks. For UUID/string keys, validate stable ordering and cursor behavior; never use offset paging as the resume mechanism. Persist membership in idempotent batches. On success atomically publish the generation; retain previous results until then. Failed/cancelled generations never become active. Cleanup old generations in bounded jobs.

A chunked scan is eventually consistent, not a database snapshot. Changes while scanning can be missed until the next scan. Exclude retired records when rendering and validate direct matches again at merge time. Include scan timestamp and offer rescan. Do not advertise real-time detection in v1. Scan-completed events may update banners; no per-record observer required initially.

Application-wide tenant scan may index records a particular actor cannot view. UI must filter individual members and counts using current authorization. The definition must provide a performant visibility query for list/count access; callbacks alone cannot justify leaking aggregate counts. Only authorized actors can initiate scope scans.

## 7. Scalar merge policy

Fields are explicit allowlists, never all fillable attributes. Reject ID/key, tenant/owner scope keys, timestamps, deleted_at, credentials, secrets, generated columns and ordinary relationship foreign keys from generic scalar merging. Never render hidden/sensitive fields unless a separate explicit safe display configuration is present. Unsupported casts block configuration.

| Values | Proposed result |
| --- | --- |
| Equal under field comparison | Retain survivor's original stored value |
| Survivor missing; source present | Propose source value, show it in preview |
| Survivor present; source missing | Retain survivor |
| Both missing | Retain survivor representation; validate required rules |
| Both present and different | Require explicit survivor/source choice |
| Source false or 0; survivor null | Propose false/0; never use PHP empty() |
| Arrays, JSON, media, translations | Unsupported in v1 unless future adapter |

Missing means null or configured blank-string treatment only. Blank-string behavior is field-specific. Support string, boolean, integer, decimal-as-string, backed enum and date/datetime through explicit codecs with lossless canonical comparisons. No float conversion of money or large integers. Preserve timezone meaning and enum types. Keep long text intact; truncate display only with expansion. v1 choices select existing values; arbitrary user editing/custom computed resolutions are later work.

Developer supplies validation rules/validator; do not assume Filament form validation is reused automatically. Validate required values, length, type, domain invariants and tenant-aware unique/exists checks server-side. DB constraints remain final enforcement.

Unique source-owned values: soft-deleting a row does not normally release its unique key. v1 blocks choosing a value already owned by the source or any conflicting row if the final DB state violates uniqueness. Do not ignore source IDs in validation to pretend transfer is safe. Do not null/rename unique fields secretly. Later adapter can explicitly release/transfer values transactionally with audit and compatible schema.

## 8. Relationship and retirement policy

### 8.1 v1 relation adapter

Support ordinary HasMany using a conventional parent key/child FK, same connection, and declared complete inbound reference inventory. Move children by updating the FK to the survivor. Do not duplicate them. Include soft-deleted children where the definition explicitly declares that ownership transfer is required. Define the effect of child scopes and authorization; hidden children cannot be silently omitted or exposed.

A filtered relation such as `activeItems` is not proof of complete ownership coverage. Require an unfiltered ownership relation or inventory adapter that covers the whole FK, with trusted scope checks. Check constraints such as `unique(parent_id, reference)`: two child rows with the same reference would collide after transfer. Block instead of deleting, replacing or guessing.

v1 uses per-model saves for children so configured casts/observers run; cap default transfer at 500 children per pair (configurable after performance testing). Above the limit, block with explanation. Bulk SQL is a future opt-in strategy with different event semantics. A deleted/hidden child still needs declared handling.

Require an explicit host declaration that the supported relation inventory is complete and tested. A diagnostics command may inspect FK metadata as a best-effort warning, but cannot discover polymorphic references, external systems or every Eloquent relation. Do not promise automatic completeness. Block merge until inventory and writer-guard integration are acknowledged/configured. Detection may still work.

### 8.2 Relation coverage matrix

| Relation/reference | v1 behavior | Future design requirement |
| --- | --- | --- |
| Ordinary HasMany | Transfer with lock/validation/authorization | Batch strategy |
| BelongsTo on source/survivor | Generic field merge blocked for its FK; add explicit strategy later | Resolve differing associated records |
| HasOne | Block configuration for merge | Both sides occupied; pick/archive conflict |
| BelongsToMany | Block | Pivot deduplication, metadata, custom pivot models |
| MorphMany/MorphOne/MorphToMany | Block | Morph map aliases, pair keys, conflicts |
| Self-referential trees | Block | Cycle detection and descendants |
| Through relations | Not transferable directly; unresolved ownership blocks | Resolve underlying links |
| Attachments/Spatie Media Library | Block transfer; source soft-delete alone does not establish safety | Files and model lifecycle behavior |
| External identifiers/references | Host declares blocker or tested integration | Redirect/reference resolution |
| Multiple database connections | Block | No claimed cross-DB atomicity |

### 8.3 Retirement and references

Only merge active, non-retired records in v1. After all mutations validate, soft-delete source and write ledger mapping source to survivor in the same transaction. Survivor stays active. Ledger lookup excludes merged sources from scans, pair selection and execution even after external restore. Retirement identity is connection + registered model alias + canonical ownership domain + typed source ID, independent of definition and panel IDs. Definitions for the same model/domain must share this ledger; a second definition must not make a retired record mergeable again. Provide a host query helper/guard and diagnostics for restored merged rows.

Do not install a global model scope automatically. Explain that host applications must exclude retired sources on normal reads and refuse new references to retired IDs. Plugin pages enforce this regardless. Old application URLs are not automatically redirected; provide an explicit authorized resolver helper. Resolving a survivor must apply current scope and view authorization, with bounded traversal and cycle protection if a survivor is later merged into another record.

Retention of the source is not retention of its former relationships: moved children now point at survivor. No unmerge button in v1. No hard-delete or nulling fields merely to satisfy uniqueness.

## 9. Merge transaction and concurrency specification

### 9.1 Preview request

Create server-side MergePlan with operation UUID, actor/panel/scope/definition, survivor/source typed IDs, definition revision, expiry (15 minutes default), field choice map, input fingerprints and relationship plan. Store encrypted input snapshot/plan; browser receives opaque preview ID. Unique input signature uses deterministic lossless field encoding, not just updated_at. Include matching inputs and every merge-relevant field, retired status, and child IDs/versions relevant to the relation plan.

Browser selections are validated against allowlists and trigger a new plan. Tokens are actor-bound; session/tenant/panel changes invalidate them. Preview is advisory; execution repeats validation. Matching index values and display data from Livewire are never trusted.

### 9.2 Execution sequence

1. Resolve trusted registry/context and actor; validate preview ownership, expiry, revision and request token.
2. Begin transaction on model/package connection. Lock scope/operation coordination rows and both parents in deterministic typed-key order. Serialize overlapping plugin merges. Acquire locks on relevant children in deterministic order.
3. Re-fetch scoped parents; reauthorize view, update survivor, retire source, merge pair and affected child transfers. Reject missing, deleted, retired, same-ID, cross-scope or cross-model pairs.
4. Verify preview fingerprints and that the pair still directly matches. Revalidate relation membership, counts, field choices, unique/foreign constraints and domain rules. If anything materially changed, abort as StalePreview.
5. Save selected survivor fields through Eloquent. Transfer declared children through adapter. Ensure every expected update/delete succeeds; event cancellation returning false must abort.
6. Soft-delete source; insert terminal source ledger and audit entry. Idempotency uniqueness must prevent a second execution. Audit contains before/after values only for declared audit fields, choices, moved IDs/counts and actor/context/config revision.
7. Recheck transactional postconditions: survivor active, source retired, children point correctly, expected row counts, no duplicate ledger. Commit.
8. Dispatch after-commit completion/invalidation work. Return same result for a repeated operation token with the same actor/context; changed payload using that token is rejected. Never report an after-commit notification failure as a rolled-back merge.

Bound deadlock retries (e.g. 3) with no external side effects inside retried callbacks. On retry, reload and revalidate; never replay stale model instances. Any observer/adapter failure rolls back DB work. Record failure metadata outside the rolled-back transaction with sanitized error codes; never save raw SQL/bindings/secrets to user-visible audit.

### 9.3 Concurrent non-plugin writers — important limitation

Parent and child row locks alone are not a portable guarantee against another endpoint adding new children to a source mid-merge. Also, source remains in the database after soft deletion, so a foreign key alone does not prevent future references.

Required WriterGuard protocol: every host path that creates/reassigns declared children (UI, import, queue, API, raw SQL integration) locks affected parents in the same order inside its transaction, then checks terminal ledger/active status before writing. Plugin executor uses the same protocol. Require onboarding acknowledgement and documented integration; provide helper/service plus demo usage. If host cannot uphold it, allow detection but disable merge. A package cannot enforce unknown raw SQL writers universally; communicate the guarantee condition clearly.

Relation fingerprints detect many stale changes but do not replace writer coordination. Tests must use separate real DB connections to prove both orderings: child write before merge makes preview stale; merge before child write causes the writer to reject the retired source.

Observers may perform external side effects. Provide a MergeContext for host listeners and document after-commit/idempotent side effects. Do not globally suppress observers. Host integration tests are required for application-specific cascades, observers and triggers.

## 10. Internal architecture and persistence

Keep domain services independent of Filament rendering. Dependency direction: Filament actions/pages → application services → contracts/DTOs → persistence adapters. Never put merge logic in a Livewire click handler. Services also enforce authorization; calling them outside the panel must not bypass it.

Suggested structure: `src/Contracts`, `Definitions`, `Matching`, `Normalization`, `Data`, `Services`, `Relations`, `Authorization`, `Models`, `Jobs`, `Commands`, `Exceptions`, `Events`, `Filament/Actions`, `Filament/Pages`, `Filament/Concerns`; `config`, `database/migrations`, `resources/views`, `resources/lang`, `tests/Unit`, `tests/Feature`, `tests/Concurrency`, `docs`, and a separate demo application.

Services: DefinitionRegistry, ScanCoordinator, ScanChunkProcessor, SuggestionQuery, DismissalService, MergePlanner, MergeExecutor, AuditReader and RetirementResolver. DTOs: DuplicateContext, MatchReason, CandidateBucket, FieldDifference, RelationImpact, MergePlan, MergeResult. Stable exception/error codes: InvalidConfiguration, ForbiddenOperation, MissingContext, RecordUnavailable, StalePreview, UnsupportedRelation, DomainConflict, MergeTooLarge, RetryExhausted.

Proposed tables (all `filament_merge_duplicates_` prefix; migrations publishable):

| Table | Main fields and constraints |
| --- | --- |
| scopes | UUID ID; definition ID; hashed canonical scope identity; current generation ID; unique(definition, scope hash); concurrency coordination row |
| scans | UUID ID, scope ID, generation ID, config revision, state, cursor, counters, timestamps, sanitized failure code, heartbeat; indexed(scope, state) |
| memberships | generation ID, rule ID, digest, typed/string record ID; unique(generation, rule, record ID); index(generation, rule, digest) |
| dismissals | scope ID, sorted typed record IDs, matching-input signatures, config revision, actor reference, timestamps; unique(scope, pair hash); reopening state |
| previews | operation UUID unique, scope, actor/panel binding, encrypted plan payload, expiry, payload hash; no browser-trusted values |
| merges | UUID ID, scope ID, operation UUID unique, source ID, survivor ID, actor ref, model/definition revision, retirement-domain digest, encrypted allowlisted audit payload, committed_at; unique(retirement domain, typed source ID) |

Prefer scope IDs over nullable tenant columns in unique constraints; null uniqueness differs by engine. Canonical scope identity must cover connection/domain/tenant and definition. Same definition in multiple panels shares a data scope; panel-specific authorization still applies. Retirement-domain identity deliberately omits definition and panel so other definitions cannot bypass terminal sources. The connection and stable model alias are included in that identity; aliases must not change casually. Validate ID length/types and use digest indexes where database index sizes require them. Host user/tenant IDs are typed strings, not hardwired integer FKs; choose an actor reference codec and test it. Model IDs can outlive host rows; audit must remain readable without dereferencing deleted actors.

Previews expire and are pruned; stale memberships/scans have configurable retention. Audit/ledger have different retention: do not prune terminal source mappings while the source may still exist. Encrypt audit payloads with Laravel encryption; identify key-rotation implications. Default audit captures configured merge fields only, never all model attributes. UI history access requires separate permission. Raw matching values, credentials, hidden fields and preview payloads must not appear in logs. Failed operations store metadata only.

## 11. Case coverage and acceptance test catalogue

Each row is a required case family; create specific Pest tests for its listed subcases. “Blocked” is a valid, deliberate behavior for unsupported cases. This covers the planned support boundary; host-specific rules still require host tests.

| ID | Cases | Expected behavior / assertions |
| --- | --- | --- |
| D01 | Exact email pair; unmatched records | Only configured matching pair suggested |
| D02 | Null, empty, whitespace, invalid email | Missing/invalid inputs never form blank buckets |
| D03 | Trim/case configuration; email domain/local part | Matches obey explicit normalizer semantics; originals unchanged |
| D04 | Composite partial match; all components; tuple delimiter collision | Only complete equal typed tuples match |
| D05 | 0, '0', false, null; bigint IDs | No accidental coercion or precision loss |
| D06 | Arabic/Unicode/emoji/combining marks; optional NFC | Defined semantics; round-trip content intact |
| D07 | A–B/B–C overlap; identical reasons | No inferred A–C merge; reasons deduplicated |
| D08 | Shared generic email/phone; 10,000-member bucket | Bounded memory; pagination; no quadratic pair persistence |
| D09 | Duplicate IDs/classes/rule config; revision/key rotation | Early actionable error or generation invalidation |
| D10 | UUID/ULID, bigint/string key scan cursor | Stable chunk resume without duplicate memberships |
| S01 | Worker crash/retry; duplicate job; scan cancellation | Idempotent batches, valid lifecycle, no partial publication |
| S02 | Two scans same scope; two tenants | Serialize same-scope publication; independent scope isolation |
| S03 | Insert/update/delete/merge during scan | Eventual consistency disclosed; render/execution live validation |
| S04 | Failure/no results/not scanned | Distinct UI states; last successful generation preserved |
| S05 | Missing tenant in queue/CLI; deleted tenant | Fail closed, no unscoped queries |
| S06 | One/many/all pairs dismissed in bucket | Correct visible suggestions; no false duplicate claim |
| F01 | Equal values; blank survivor; blank source | Apply section 7 exactly |
| F02 | Two nonblank conflicting values | Explicit choice required; no last-write-wins |
| F03 | false/0, decimal, enum, datetime, long text | Lossless selected values and comparison |
| F04 | Required/length/domain/unique violation | No changes; field-level actionable errors |
| F05 | Unique value owned by source; soft-delete | Block unless final constraint demonstrably valid; no covert release |
| F06 | Hidden/password/tenant/ID fields; tampered selections | Reject unsafe fields server-side; no value leakage |
| F07 | JSON/array/generated/encrypted unsupported casts | Clear configuration blocker |
| R01 | No children; survivor/source/both have children | Every declared eligible child transferred once |
| R02 | FK errors; per-parent composite unique collision | Whole merge rolls back; no child discarded |
| R03 | Scoped relation; hidden/deleted children | Complete declared inventory or block; no leaked hidden counts |
| R04 | Other tenant child; forbidden child operation | Block whole merge; no partial permitted subset |
| R05 | Cross-connection; polymorphic/pivot/HasOne/tree/media | Unsupported blocker rather than guessed transfer |
| R06 | >500 children; boundary 500 | Predictable configured cap; no UI timeout-driven truncation |
| R07 | Child save false/throw; parent save/delete observer cancellation | Abort and rollback all writes |
| R08 | New/reassigned child concurrent with merge | WriterGuard protocol proven with real DB connections |
| M01 | Same record; missing/deleted/source restored after merge; another definition targeting a retired source | Reject; restored ledger source remains terminal |
| M02 | Switch survivor; expire preview; definition change | Rebuild/refresh explicitly; stale token rejected |
| M03 | Field changed without updated_at; same-second updates | Fingerprint catches merge-relevant differences |
| M04 | Relationship changed after preview | Abort StalePreview; no hidden recomputation |
| M05 | Concurrent A→B and A→C; A→B and B→C | Consistent locks; one operation stale/retired; no cycles/duplicate source |
| M06 | Double-click; request replay; lost HTTP response | Same operation returns same authorized result; mutations once |
| M07 | Reused token with altered payload/actor/scope | Reject; no result data disclosure |
| M08 | Failure after fields, children, retirement or audit | DB returns to original state at every injection point |
| M09 | Deadlock retry exhaustion; after-commit notification failure | Bounded retries; distinguish failed merge from failed notification |
| A01 | Hidden member, count, direct URL and audit access | No IDs/values/count leakage; deny direct service bypass |
| A02 | Permission revoked between preview/execute | Abort transaction |
| A03 | Tenant/panel/session changed; malicious record IDs | Trusted-context re-resolution and denial |
| A04 | Service account CLI; unauthorized scan/dismiss | Explicit credentials/permissions; no implicit admin |
| A05 | CSRF/XSS/SQL identifiers/tampered Livewire state | Standard protection + escaped output + fixed registries |
| L01 | Source soft-delete; external restore; resolver chains | Ledger enforced; no fake undo; authorized bounded resolver |
| L02 | Pruning; app key rotation; deleted actor | Ledger retained; encryption failure explicit; history doesn't crash |
| U01 | Dark/light/RTL/mobile/keyboard/large values | Readable, accessible review and errors |
| U02 | Loading/failure/stale/success; direct manual pair action | Consistent states; manual pair still requires direct configured match |
| P01 | SQL query count, 100k scan, memory, large bucket | Recorded metrics, linear index storage, bounded chunks |
| C01 | Supported engine/version matrix | Tests actually run; SQLite execution refusal verified |
| C02 | Filament 4 + Livewire 3 and Filament 5 + Livewire 4 | Both install/boot; identical core behavior and authenticated review/merge journeys |
| C03 | Lowest/current dependency lanes; declared PHP bounds | Composer resolution and runtime tests prove published version constraints |
| C04 | 4→5 upgrade with existing package data | No destructive migration/reset; suggestions, dismissals, retirement ledger and audit remain valid |
| C05 | Compatibility adapters, UI assets, state updates | Actions/hooks/validation/errors/authorization work on both majors; unsupported major rejected |
| C06 | Two unrelated resource definitions in one panel, second panel sharing one model | Independent labels/rules/fields/counts; correct permissions and shared terminal retirement identity |
| C07 | Scalar-only eligible resource and relation-bearing resource | Both supported when explicit reference inventory and writer contracts are satisfied; unsupported relations block execution |

Property tests worth adding: normalization idempotence; tuple serialization collision resistance for distinct inputs; ID ordering symmetry; failed merge leaves no database changes; repeated operation causes no extra transfer. They complement, not replace, concrete integration tests.

### 11.1 Pest testing and 100% coverage requirement

Use Pest as the single test runner, with descriptive datasets for variants. Organize tests by what they prove rather than just directory name:

| Layer | Realistic assertion point | May be mocked? |
| --- | --- | --- |
| Unit/domain | Normalizers, tuple encoding, rule evaluation, field choice policy, configuration validation, scope/ID codecs and plan fingerprinting with real inputs | Only genuine external boundaries; do not mock the class under test |
| Feature/persistence | Migrations, scans, generations, dismissals, planner, executor, ledger, authorization, unique constraints and rollback against actual SQL databases | No database/queue fakes for paths whose persistence, retries or transaction behavior is under test |
| Filament/Livewire | Authenticated table banner, review page, choices, forged requests, merge, errors and audit on both Filament majors using real models and pages | Fake outbound notifications if needed, but assert resulting database state and visible UI |
| Concurrency | Separate MySQL/PostgreSQL connections/processes racing scans, merges and child writes | No mocked locks or in-memory SQLite |
| End-to-end demo | Fresh Laravel app, two unrelated resource definitions, queued scan, review, merge, audit and upgrade from Filament 4 to 5 | Real services for merge and scan; no mocked package service |

**Coverage gate:** Target and enforce 100% executable PHP line coverage for package-owned `src/` on the final full Pest suite in both Filament 4 and Filament 5 lanes. Measure with a supported coverage driver, use an explicit `src/` filter, and fail CI below 100%; never lower the threshold merely to pass. Generate an HTML/Clover report and attach the uncovered-line report to CI failures. Cover all meaningful error, rollback, permission and stale branches; collect branch coverage where the selected driver supports it and review uncovered branches separately. Generated scaffolding outside `src/` can be excluded only with a documented reason; package business code must not use blanket coverage-ignore annotations. Blade templates, migrations and frontend behavior are verified by integration/browser assertions because PHP line coverage does not measure their semantics. If 100% line coverage is unattainable for a supported version without distorting production code, report the exact lines and reason, keep the release gate failing, and resolve the design/test gap before claiming completion.

**Assertion gate:** Every case ID needs a Pest test whose assertion checks observable results: exact survivor field values; exact child IDs and parent references; source retirement and ledger entry; authorization denial; rendered match reason; dismissal state; transaction rollback; idempotent replay; successful 4→5 data reuse. For failures, assert original database snapshots remain equal, including every affected row, and that no success audit exists. Avoid tests that only assert a method was called, a class exists, a page renders, or a response is 200 when the behavior requires more. Use failure injection, property tests and mutation testing for critical domain/merge services where feasible; surviving mutations affecting invariants must be addressed. No test should replicate production algorithms to calculate its expected result.

**Milestone discipline:** Add and run the relevant Pest tests with each functional slice. Review `docs/test-case-map.md` after every milestone; each case is `not started`, `failing`, `passing`, or `blocked by environment` with links to tests. Run unit/feature tests during development; use the entire suite and measured coverage as the final release checkpoint, followed by hands-on demo verification. 100% measured coverage is a code-execution metric, not a proof that arbitrary host schemas and external side effects are safe. The real database and UI assertions are the behavioral proof within the declared support matrix.

## 12. Risks and mitigation decisions

| Risk | Mitigation / release requirement |
| --- | --- |
| False positive merges | Suggestions only, match explanation, review, dismissals |
| Relationship data loss | Explicit inventory, adapter coverage, transactional postconditions |
| Unique field transfer impossible with retained source | Block in v1; document schema-aware future strategy |
| Non-plugin concurrent writers | Required WriterGuard, ledger validation, host integration tests |
| Observer/trigger/external side effects | No global event suppression; after-commit contract and failure tests |
| Tenant leak from background scans | Explicit context and visibility-aware queries/counts |
| Large dataset stalls | Keyset chunks, indexed digests, publish generations, caps |
| Audit sensitive data | Allowlist, encryption, permissions, retention and redacted failures |
| Framework API drift | Required 4/5 CI, verified signatures, shared core and small compatibility adapters |
| Universal merge promise too broad | Publish supported matrix and explicit blockers |
| Scope creep | Ship exact detection + pair merge before fuzzy/pivot/undo |
| Existing plugins overlap | Focus on explainable detection-to-review workflow; don't claim first/only |

Do not claim “zero data loss” for arbitrary host applications. State tested guarantees, supported strategies and required host writer/observer contracts.

## 13. Milestones and implementation tasks

Each milestone is a reviewable PR-sized group. DeepSeek should implement one at a time, preserve public contracts, report tests and stop on material ambiguity. Dependencies below are sequential unless a task is explicitly independent.

### M0 — Compatibility spike and design freeze

Tasks: bootstrap package from official skeleton; create demo/test fixtures for Filament 4 + Livewire 3 and Filament 5 + Livewire 4 on Laravel 12; resolve all four compatibility lanes; verify page/action/hooks/testing APIs on both majors; run MySQL/PostgreSQL services; spike parent locks and writer ordering; choose exact ID/field codecs and schema; record ADRs for scope, visibility, retirement, concurrency and audit.

Deliverables: composer.json, Pest/Testbench bootstrap, coverage driver/filter and fail-under CI draft, demo skeleton, `docs/architecture.md`, `docs/support-matrix.md`. Gate: clean install and an actual action render on both Filament majors; real DB lock spike passes; no guessed Filament methods; all compatibility lanes and minimum/current PHP dependency resolution documented. Record adapter decisions in docs/compatibility.md. Do not proceed with a v5-only bootstrap.

### M1 — Definitions, context and data foundations

Tasks: registry/contracts/DTOs/codecs; definition validation; scopes and migrations; authorization interfaces; normalizers and rule signatures. Establish fixtures for at least two unrelated resource models and schemas (for example, tenanted Contact/Note and InventoryItem/Review), including scalar-only records, declared HasMany relationships, integer IDs and UUID IDs. These are sample fixtures, not supported-domain limits. Provide failure codes.

Gate: D02–D06/D09/A03/A04/C06 configuration tests pass; default permissions deny; migrations install/rollback on both engines. Configuration validation distinguishes detection-only and merge-capable definitions.

### M2 — Detection and scan orchestration

Tasks: key hashing; queued keyset chunk scans; generation publication; retries/cancel/progress; suggestion repository and visibility-aware counts; CLI with explicit contexts; dismissal signatures/reopening; cleanup jobs.

Gate: D01/D07/D08/D10 and S01–S06 pass. Demonstrate 100k records in 1,000-row default chunks on a recorded environment; report wall time, peak worker memory, SQL count and index size. Initial target: under 256 MiB peak memory; timing is measured, not a universal promised SLA. No all-pairs persistence or full-model loading.

### M3 — Merge planner, no mutations

Tasks: codecs/field diffs; survivor selection; domain validation; relation preview and inventory validation; encrypted actor-bound preview persistence; expiry; stale fingerprints. Build unique-source conflict fixture and unsupported relation fixtures.

Gate: F01–F07, R02–R06, M01–M04 pass. Calling planner cannot change target models. Every blocker is available before confirmation; DB races still rechecked later.

### M4 — Executor and safety integration

Tasks: deterministic locks; WriterGuard; Eloquent saves/child transfers; soft retirement; ledger/audit/idempotency; postconditions; retries and after-commit events; authorized survivor resolver. Fault injection at every mutation boundary.

Gate: R01/R07/R08, M05–M09, A01–A04, L01/L02 and C01 pass on real MySQL/PostgreSQL. Prove child-write ordering using separate connections, not mocked locks or SQLite. Parent/child event cancellation is tested. No merge UI enabled before gate passes.

### M5 — Filament experience

Tasks: opt-in resource banner; duplicates page; pair comparison; choices/rebuilt preview; dismissal; confirmation; audit view; loading/error states; dark/mobile/keyboard/RTL; translations; table pair action enforcing direct match.

Gate: Filament/Livewire tests exercise authenticated journeys and forged requests on two unrelated resource models; U01/U02/A05/C06/C07 pass. Manual browser review on both majors verifies layout and keyboard behavior. C02/C05 pass, including Livewire state updates, modal actions, hooks and asset rendering. Use the completed executor; no duplicate business logic in actions.

### M6 — Documentation, demo and beta

Tasks: install/config guide; walkthrough configuring two unrelated resources in the same panel; writer and observer integration; supported matrix; error guide; API reference; release/security/contribution templates; demo seeders with blank, conflict, dismissed, blocked and mergeable cases; short GIF/video. Beta feedback from at least three real apps with differing schemas.

Gate: a developer following README from a fresh app reproduces the entire workflow; all beta blockers triaged. Record beta limitations honestly. Code examples tested for syntax/integration. No default User account merger or unsafe hard-delete option.

### M7 — Stable v1.0

Tasks: final CI matrix and database concurrency run; performance report; documentation consistency; Composer validate; dependency/license review; changelog/tag/release metadata; package and Filament directory submissions as separately authorized publication steps.

Gate: all required case IDs mapped to passing Pest tests or explicit supported-boundary rejection tests; 100% executable PHP line coverage across package `src/` independently on both Filament major lanes, with reviewed branch gaps; no unresolved data-loss/authorization bug; C02–C07 pass; version constraints match tested range for both Filament majors; hands-on demo reproduces scan, review, merge and audit in both installations; beta evidence recorded. Publishing credentials and package availability are setup requirements, not invented by AI.

## 14. Definition of done and review checklist

- Fresh installation works with published migrations/config and documented queue setup.
- Only registered, scoped, authorized records are discoverable or mergeable.
- Matching keys are deterministic, configurable and explainable.
- The reviewed result equals committed scalar values and declared relationship transfers.
- Stale data, unsupported relationships, event cancellation, unique conflicts and concurrency cause a safe abort.
- Pair dismissal works across unchanged scans and reopens on relevant change.
- Failure at each database boundary leaves original fields, children and source active status intact.
- Idempotency and ledger constraints work under real concurrent requests.
- Audit is access-controlled and encrypted; terminal mappings are not pruned accidentally.
- Pest tests, 100% `src/` PHP line coverage independently on both required Filament 4/Livewire 3 and Filament 5/Livewire 4 lanes, static analysis, formatting and Composer checks pass on the published database/PHP matrix; all meaningful branch gaps are reviewed.
- Existing package data survives a tested Filament 4→5 upgrade without resetting history. Two unrelated resource definitions work in the same panel; generic plugin UI uses their configured labels.
- Docs describe writer coordination, observer side effects, limitations and absence of undo.
- Demo/GIF conveys detect → review → merge, with meaningful failure examples.

Meet the 100% line coverage target **and** the real behavior assertions in section 11.1. Coverage cannot replace behavioral proof. Maintain `docs/test-case-map.md` mapping each case ID to actual Pest tests and their outcomes.

## 15. AI execution protocol — paste into DeepSeek

```text
You are implementing the Filament Merge Duplicates package from the attached plan.
Read this entire plan and existing repository instructions before editing.
The plan's proposed API is ours to build, not an existing Filament API.

Work on milestone [M0 initially] only. Inspect repository and dependencies;
state the milestone scope, prerequisites and expected acceptance cases.
Verify framework APIs against the installed supported versions. Do not mix
Filament 3 APIs into this package or assume Filament 5-only APIs work on 4.
Support both ^4.0 and ^5.0 in one package from v1.0. This is a generic resource plugin: do not hardcode Customer, Order, Contact or Product fields or queries into package logic or UI. Prove two unrelated resource definitions. Verify Livewire 3/4
compatibility and run both framework lanes. Isolate UI differences in adapters;
never fork domain/merge logic or remove a major from CI to make tests pass.

Implement one cohesive slice with meaningful Pest tests. Keep domain logic out
of Filament UI handlers. Never remove failing tests, relax authorization,
hide constraint errors, suppress events globally, or silently drop records.
Do not implement future scope (fuzzy matching, bulk merge, pivot adapters,
hard-delete, undo) while completing v1 milestones.

Run applicable Pest tests, coverage for changed src/ code, static analysis,
formatting and Composer validation. Keep the final full-suite target at 100%
executable PHP line coverage on BOTH Filament major lanes; assert real DB/UI
effects and rollbacks, not only mocked calls or status codes.
Use real MySQL/PostgreSQL for lock/concurrency cases. If services or credentials
are unavailable, record those tests as unrun; do not report them as passing.

When a decision changes guarantees, scope or public API, propose a written
ADR and stop dependent implementation for review. Resolve minor internal
details yourself within this specification. Never publish without instruction.

Finish with: files changed; behavior added; case IDs/Pest tests executed and results;
measured src/ coverage by Filament major and any uncovered branches;
unrun checks; known limitations; decisions requiring review; next milestone.
Update implementation status and test-case map. Leave the code reviewable.
```

Codex review prompt after each milestone:

```text
Review milestone [Mx] against the attached specification and actual diff.
Trace tenant/actor data from entry point to services and queued workers.
Verify Filament 4/Livewire 3 and Filament 5/Livewire 4 compatibility evidence,
Composer lower bounds, adapters and non-destructive upgrade behavior.
Check data-loss paths, constraint behavior, stale previews, event cancellation,
idempotency and external writer assumptions. Inspect tests for assertions
that prove behavior rather than just mock implementation details.
List findings by severity with concrete reproduction and affected files.
Identify unmet acceptance cases and unsupported claims. Do not approve a
milestone solely because formatting/unit tests pass. Recommend the next
milestone only after blockers are addressed.
```

Recommended AI cadence: M0 → Codex review → resolve findings → M1 → review, and so on. Keep one implementation chat per milestone when context grows. At handoff include plan, ADRs, current milestone status, latest test results and case map. Do not send only the original idea and ask for the entire package in one response.

## 16. Later roadmap — independent design work

v1.1 candidates: create-form duplicate warning; incremental indexing with reconciliation; explicit phone normalization; more efficient counts and scan metrics. These should not weaken v1 authorization or consistency.

v1.2 candidates: HasOne and BelongsTo strategies; pivot union with explicit conflict policies and custom pivot events; polymorphic adapters with morph-map support. Each gets its own constraint/concurrency test catalogue.

v2 candidates: fuzzy matching with candidate blocking, explainable reasons and false-positive evaluation; group merge with pairwise versus transitive policy; schema-aware unique transfer; background large merges; undo based on a journal and subsequent-change detection. Never market soft-delete restore as undo.

Community extensions: normalizers, translations, tested relation adapters, framework compatibility ports, sample definitions and docs improvements. Keep core free/open-source if community adoption is the primary goal.

## 17. Grounding references and evidence limits

Checked 2 October 2026. Most of this document is proposed design, not quoted framework behavior.

- Apple Contacts UX inspiration: https://support.apple.com/en-euro/guide/iphone/iph2ab28320d/ios — review duplicates and merge; linking across accounts is a distinct operation.
- Filament 4 installation: https://filamentphp.com/docs/4.x/introduction/installation — verify the 4.x installation lane independently.
- Filament 5 upgrade guide: https://filamentphp.com/docs/5.x/upgrade-guide — Livewire 4 requirement and upgrade considerations.
- Filament 5 installation: https://filamentphp.com/docs/5.x/introduction/installation — documented runtime requirements; package baseline above is deliberately narrower.
- Filament panel plugins: https://filamentphp.com/docs/5.x/plugins/panel-plugins — panel plugin interface and registration. Verify concrete UI extension points at M0.
- Filament tenancy: https://filamentphp.com/docs/5.x/users/tenancy — tenancy context and security; worker/query safety must be designed explicitly.
- Laravel 12 query builder: https://laravel.com/docs/12.x/queries — transaction-scoped pessimistic locking. The WriterGuard protocol is this plan's integration design, not an automatic Laravel guarantee.
- Laravel 12 Eloquent: https://laravel.com/docs/12.x/eloquent — bulk writes omit per-model events; source retirement uses soft deletion. Relationship/observer guarantees require host tests.
- Official skeleton: https://github.com/filamentphp/plugin-skeleton — bootstrap aid, not a merge implementation.
- Existing overlap: https://ptplugins.com/ — lists a free Model Merger and planned deduplication offering. No assertion that this idea is first, unique, or currently absent.

Before public release, repeat the ecosystem/version check and ensure README claims match actual implementation and tests.
