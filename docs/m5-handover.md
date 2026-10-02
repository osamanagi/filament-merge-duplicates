# M5 handover — Filament experience (in progress)

Status: **M5 is not finished and its gate is not met.** Four slices are implemented,
tested and pushed; the Filament-facing page is the next step.

## Branch state

- Working branch: `m5/filament-experience`
- Latest commit: `e76631d` (authorized scan starter)
- Earlier M5 commits: `dd03dd2` (review summary), `a13278b` (banner), `0226bf3` (review list)
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

## Next step (slice 4), in order

1. **Verify the Page/Livewire APIs first.** `docs/compatibility.md` lists
   Page/action/hooks/asset APIs, Livewire state updates and modal actions as M5
   re-verification points; only `Plugin`/`Panel`/`PanelProvider`, `HasActions`/
   `InteractsWithActions`, `HasSchemas`/`InteractsWithSchemas` and the `TestsActions`
   assertions are verified so far. `Filament\Pages\Page`, its route generation, panel
   navigation and panel-aware Livewire page testing are **unverified**.
2. **The review page**: bucket/group list using `ReviewGroupQuery` (already returns
   `groups`, `total`, `page`, `perPage`, `lastPage`, and per-group `members`,
   `memberCount`, `isReviewable()`, `hasStaleMember()`, `hiddenMemberCount()`),
   pagination, per-group member preview with a "showing X of Y" affordance, and the
   stale/empty/never-scanned/failed states from `ReviewSummary`/`DuplicateBanner`.
3. **`HasDuplicateSuggestions` trait** — deleted from an earlier attempt on purpose:
   PHPStan reports `trait.unused` while nothing in `src/` consumes it, and an
   unanalysed trait body is worse than no trait. Add it together with the page that
   uses it. Intended API: `duplicateDefinitionId()` (host implements, throws if
   missing), `duplicateDefinition()`, `duplicateContext()`, `canReviewDuplicates()`,
   `duplicateBanner()`, `duplicateBannerView($reviewUrl, $scanAction)`.
4. **`MergeDuplicatesPlugin::definitions([...])`** (plan §5) and page registration in
   the plugin's `register(Panel $panel)` (currently an empty stub).
5. **Wire the links the earlier slices already accept**: the banner takes
   `$reviewUrl` and `$scanAction`; `ScanStarter::start()` is the authorized way to
   start a scan. Nothing needs to invent a route or duplicate business logic.

## Remaining after slice 4

- Slice 5: merge preview (field comparison grid, survivor choice with older-`created_at`
  then stable-ID recommendation, explicit choices that rebuild the preview and
  invalidate prior tokens, relationship impact text from configured labels, blocker
  display, retirement warning, confirmation that calls the M4 `MergeExecutor`,
  success with survivor + audit reference, stale → refresh, failure → never success),
  dismissal ("not duplicates" dismisses the pair only), the direct-match manual pair
  action, and the audit view gated by `Ability::ViewAudit`.
- Slice 6: accessibility (dark, RTL, keyboard, visible focus, labelled radios,
  non-colour indicators, escaped values, rich text as plain text in v1), asset
  registration (currently commented out in the provider; `bin/build.js` builds only
  `resources/js/index.js` to `resources/dist/filament-merge-duplicates.js`, no CSS
  build step), the Livewire and forged-request suites on **two unrelated resource
  models**, and manual browser review on both majors.

## M5 gate (from the plan) and what is still missing for it

> Filament/Livewire tests exercise authenticated journeys and forged requests on two
> unrelated resource models; U01/U02/A05/C06/C07 pass. Manual browser review on both
> majors verifies layout and keyboard behavior. C02/C05 pass, including Livewire state
> updates, modal actions, hooks and asset rendering. Use the completed executor; no
> duplicate business logic in actions.

Case-map rows still `not started`: `U01`, `U02`, `A05`, `C06` and `C07` are partial
(`C02`/`C05` have M0 evidence only). Update `docs/test-case-map.md` as each lands.

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
./vendor/bin/pest --no-coverage            # 272 passed, 1 skipped, 864 assertions at e76631d
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
