# M5 handover — Filament experience (in progress)

Status: **M5 is not finished and its gate is not met.** Eight slices are implemented,
with the merge journey verified end to end on real MySQL and PostgreSQL and a Filament
confirmation modal exercised on both majors. What remains is manual browser review on
both majors and render hooks.

## Branch state

- Working branch: `m5/filament-experience`
- Latest commit: this commit (asset registration, two-model suite, escaping)
- Earlier M5 commits: `f812b6f` (merge preview, confirmation, dismissal, audit),
  `b4fed81` (review page, host trait, plugin page registration),
  `f0b2702` (handover), `e76631d` (authorized scan starter),
  `dd03dd2` (review summary), `a13278b` (banner), `0226bf3` (review list)
- `main` is at `61f726f` (M4 merged via PR #5). M0–M4 are complete and merged.
- Spec: `filament-merge-duplicates-implementation-plan.md` — §4 (UI, lines 71–95), §5 (integration API),
  §7–9 (merge policy, relations, transaction), §11 (cases), §13 M5 (line 431), plus the M5 gate text.

## Done in M5 so far

| Slice | Committed in | Files |
| --- | --- | --- |
| Review state read model | `dd03dd2` | `src/Scanning/ReviewState.php`, `ReviewSummary.php`, `ReviewSummaryQuery.php`, `tests/Feature/ReviewSummaryTest.php` |
| Banner | `a13278b` | `src/Filament/Banner/DuplicateBanner.php`, `DuplicateBannerFactory.php`, `resources/views/banner.blade.php`, `resources/lang/{en,ar}/merge-duplicates.php`, `tests/Feature/DuplicateBannerTest.php`, fixture `tests/Fixtures/Definitions/BannerResourceDuplicates.php` |
| Review list | `0226bf3` | `src/Scanning/ReviewGroupQuery.php`, `ReviewGroup.php`, `ReviewMember.php`, `tests/Feature/ReviewGroupQueryTest.php` |
| Authorized scan seam | `e76631d` | `src/Scanning/ScanStarter.php`, `tests/Feature/ScanStarterTest.php` |
| Review page + host trait + plugin wiring | `b4fed81` | `src/Filament/Pages/DuplicateReviewPage.php`, `src/Filament/Concerns/HasDuplicateSuggestions.php`, `resources/views/review-page.blade.php`, `src/FilamentMergeDuplicatesPlugin.php`, `resources/lang/{en,ar}/merge-duplicates.php`, `tests/Feature/DuplicateReviewPageTest.php`, fixture `tests/Fixtures/Livewire/DuplicateBannerProbe.php`, `docs/compatibility.md`, `docs/test-case-map.md` |
| Merge preview, dismissal and audit | `f812b6f` | `src/Filament/Pages/DuplicateMergePage.php`, `DuplicateAuditPage.php`, `resources/views/{merge-page,audit-page}.blade.php`, `src/Merging/MergePreviewService.php`, `src/Scanning/DirectPairMatcher.php`, `src/Merging/MergePlan.php`, `src/FilamentMergeDuplicatesPlugin.php`, `tests/Feature/DuplicateMergePageTest.php`, `tests/Unit/Merging/MergePlanResolutionTest.php`, `tests/Unit/Scanning/DirectPairMatcherTest.php` |
| Assets, two-model suite, escaping | `965614d` | `resources/css/index.css`, `resources/dist/filament-merge-duplicates.css`, `bin/build.js`, `src/FilamentMergeDuplicatesServiceProvider.php`, `tests/Feature/AssetRegistrationTest.php`, `tests/Feature/FilamentTwoModelJourneyTest.php` |
| Real-engine merge page journey | `bcf62f0` | `tests/Execution/MergePageJourneyTest.php`, `docs/compatibility.md`, `docs/test-case-map.md` |
| Scan confirmation modal action | this commit | `src/Filament/Pages/DuplicateReviewPage.php`, `resources/views/review-page.blade.php`, `resources/lang/{en,ar}/merge-duplicates.php`, `tests/Feature/DuplicateReviewPageTest.php` |

Guarantees these already enforce (do not regress them):

- The banner and the list agree on the group count because both use the same query
  (`SuggestionQuery`) against the published generation. Rendering does no scan work.
- A hidden member is never shown **and never counted**: a group needs at least two
  visible members to exist at all, so a group with one visible member is not listed.
- A group that can no longer offer two mergeable records disappears rather than
  appearing as a group of one.
- Member staleness has three distinct flags: `missing` (vanished in the reading
  window), `retired` (ledger says merged away), `changed` (its rule key no longer
  matches, recomputed with `KeyBuilder::keysFor`).
- Authorization lives in services (`ReviewGroupQuery`, `ScanStarter`,
  `DuplicateBannerFactory`), never only in a page, so a direct call cannot bypass it.
- A failed scan shows only the sanitized reason code, never exception text.
- An unregistered definition ID throws `InvalidConfiguration` rather than hiding a
  misconfiguration behind a missing banner.

## Slice 4 — implemented (this commit)

1. **Page/Livewire APIs verified on both majors.** `docs/compatibility.md` now records
   the verified page route registration, route naming, URL generation, navigation,
   panel page registration, `mount()` route parameters, Livewire state updates and
   panel-aware page testing, with the lane versions and results. No adapter was needed.
2. **The review page** (`src/Filament/Pages/DuplicateReviewPage.php`) lists groups from
   `ReviewGroupQuery` with pagination, a capped member preview with a "showing X of Y"
   affordance, and the never-scanned/scanning/failed/empty/has-results states. Its
   header reuses the banner model, so the wording and count cannot drift from the
   banner. The scan button calls `ScanStarter`, so authorization is never duplicated.
3. **`HasDuplicateSuggestions`** is back and consumed by the page (PHPStan analyses it);
   the fixture `DuplicateBannerProbe` exercises the host path with a review URL and an
   HTML-safe scan action.
4. **`MergeDuplicatesPlugin::definitions([...])`** validates its IDs and
   `register(Panel)` now registers the page. A definition ID outside the panel list
   404s before the registry is consulted.
5. **Wiring**: the page URL comes from `DuplicateReviewPage::urlForDefinition()` and the
   trait passes it to the banner; the banner's scan slot accepts a rendered `Htmlable`
   action. No route is invented at a call site.

### Decisions taken in slice 4

- One page class, with the definition ID in the route (`merge-duplicates/{definition}`)
  and `getRelativeRouteName()` overridden so the route name is parameter-free. The page
  is not registered in navigation (`shouldRegisterNavigation()` is false): a single nav
  entry cannot know which definition a visitor wants, and the banner is the entry
  point. Hosts can add one entry per definition with `urlForDefinition()`.
- The page renders with plain semantic markup and `wire:click`, not Filament Blade
  components, for the same cross-major reason as the banner.
- Blade renders the embedded banner `View` with `{!! ... !!}` rather than `{{ ... }}`:
  `{{ }}` HTML-escapes a `View` here even though it implements `Htmlable`.

## Slice 5 — implemented (this commit)

- **Merge preview** (`src/Filament/Pages/DuplicateMergePage.php`) builds a server-side
  plan through `MergePreviewService` and shows the field comparison grid, the survivor
  choice (defaulting to the planner's older-`created_at` then stable-ID recommendation),
  relationship impact text, blockers and the retirement warning. Changing the survivor
  rebuilds the plan and clears prior choices, so the old operation identifier is dead.
- **Confirmation** calls the M4 `MergeExecutor` with the field choices. Success shows
  the survivor and the audit reference; a stale preview is reported and the plan
  rebuilt; any failure is reported by its sanitized code and never as success.
- **Dismissal** calls `DismissalService` for the pair only, then returns to the review
  page.
- **Audit view** (`DuplicateAuditPage`) reads one entry through `AuditReader`, so
  `Ability::ViewAudit` and the scope check are enforced before anything is decrypted.
- **Direct match** is enforced on the preview: `DirectPairMatcher` recomputes the rule
  digests, and a pair that no longer matches becomes a fatal blocker rather than a
  merge. This is the domain half of the manual pair action; a host resource-table action
  that calls `DuplicateMergePage::urlForPair()` is still to be added, because the package
  ships no resource to attach it to.
- `MergePlan` now derives `choiceFields()`, `resolvableBlockers()`, `fatalBlockers()`,
  `choicesComplete()` and `canConfirm()` from the differences, and the planner and
  executor share one `choiceBlockerFor()` wording instead of duplicating it.

Still outstanding: a real-engine page test for the success and stale-refresh paths. The
engine-refusal path is tested on SQLite, and the executor's success path is already
proven in M4.

## Slice 6 — implemented (this commit)

- **Assets**: one package stylesheet is registered through `FilamentAsset::register`.
  It does not import Filament's theme (the panel already ships it) and adds focus,
  forced-colours and print robustness. `bin/build.js` now builds the CSS alongside the
  JS, and `tests/Feature/AssetRegistrationTest.php` asserts both the registration and
  the file it points at.
- **Two-model suite**: `tests/Feature/FilamentTwoModelJourneyTest.php` drives the pages
  on the UUID-keyed, non-soft-deleting fixture - review list, compare link, forged id
  (404), forged ability (403) and the unmergeable state - so the pages are not tied to
  the integer-keyed merge fixture.
- **Escaping**: tests pin that record markup is escaped in the review list, the
  comparison grid and the audit view.

## Remaining for M5 (deferred to the demo phase)

- **Render hooks** — no genuine use in the package yet, so deferred to the demo phase
  rather than added for the gate. Agreed with the maintainer.
- Decision (agreed): the host resource-table manual pair action is left to the
  consumer/demo. The domain guard (`DirectPairMatcher`) and
  `DuplicateMergePage::urlForPair()` are in place for it.

## Manual browser review (done)

Performed in the two demo installations and recorded in `docs/demo-walkthrough.md`:
Filament 5.9.0 under `/Users/nagi/code/demo` and Filament 4.11.6 under
`/Users/nagi/code/demo-f4`, both on MySQL, both with two unrelated definitions in one
panel. Scan, review, compare, merge and audit were walked through by hand on both, and the
states were checked against the list afterwards (the merged group disappears).

The walkthrough surfaced three real defects, all fixed on this branch: the config file was
never loaded, a scan started from a page never dispatched its chunk job, and the banner
`View` was escaped by Blade.

## M5 gate (from the plan) and what is still missing for it

> Filament/Livewire tests exercise authenticated journeys and forged requests on two
> unrelated resource models; U01/U02/A05/C06/C07 pass. Manual browser review on both
> majors verifies layout and keyboard behavior. C02/C05 pass, including Livewire state
> updates, modal actions, hooks and asset rendering. Use the completed executor; no
> duplicate business logic in actions.

Case-map rows: `A05`, `C02`, `C05`, `C06`, `C07`, `U01` and `U02` are partial, with
slice-4/5 evidence recorded in `docs/test-case-map.md`. Modal actions, asset rendering, the
real-engine success/stale page paths and the manual browser review on both majors are now
covered; **render hooks are the only remaining gate item**, deliberately deferred to the
demo phase by agreement and therefore not claimable as done.

## Decisions already made (do not re-litigate without reason)

- Two unrelated resource fixtures go in this repo's test fixtures, because CI must
  exercise them; `/Users/nagi/code/demo` is for the browser review.
- Review read models live in `src/Scanning` (no new top-level namespace).
- The banner count reuses `SuggestionQuery::count()`; it is exact and matches the
  list, at the cost of materialising buckets. Revisit only if a scope can hold
  thousands of groups.
- The banner view is rendered with `Factory::file()`, not the namespaced view, because
  larastan cannot see the runtime-registered namespace and rejects the literal as a
  `view-string`. A test asserts `View::exists('filament-merge-duplicates::banner')`.
- The banner view uses no Filament Blade components, so it renders the same on both
  majors.

## Known debt and flags to carry forward

- `DomainConflict` carries three different situations (event cancellation, unreadable
  audit payload, already-committed-with-different-choices). Distinct public codes
  would be better; flagged, not yet changed.
- `CompleteHasMany::signature()` omits the soft-delete flag.
- `(Filament 5, PHP 8.2)` is installable but not behaviourally verified (ADR 0008).
- Coverage gate stays non-blocking (`--min=100`) until M7. Local total after M4: 80.7%.
- M05's live-parallel execution case is deferred to M7's concurrency run.

## Verification commands for this branch

```bash
./vendor/bin/pest --no-coverage            # 327 passed, 1 skipped, 1050 assertions
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pint --test
./vendor/bin/pest --coverage --min=0       # coverage total (pcov; xdebug is absent)
bin/lane-test.sh '^5.0'                    # Filament 5 lane; positional arg, not an env var
bin/lane-test.sh '^4.0' --prefer-lowest
```

Execution tests (`tests/Execution/`) need real MySQL and PostgreSQL; they skip loudly
when unreachable. CI provides both as service containers whose credentials already
match `EngineConnections` defaults. Local services: MySQL `127.0.0.1:3306`,
PostgreSQL `127.0.0.1:5432`, database `merge_duplicates`, user `merge`, password `secret`.

## Environment gotcha

The shell is **fish**. Heredocs (`<<EOF`) and `for ...; do` one-liners hang it waiting
for input — use the file-editing tools for multi-line content instead of shell
redirection. Long `pest` runs should have their output redirected to a file and then
grepped. See repo memory for the rest.
