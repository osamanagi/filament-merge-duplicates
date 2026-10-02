# Filament compatibility

Status: M0 evidence. One package release line supports Filament 4 and Filament 5
simultaneously via `"filament/filament": "^4.0 || ^5.0"`.

## Verified lanes

All four lanes ran the identical test suite in an isolated checkout. Versions are
the ones actually resolved and executed, recorded from `composer.lock`.

| Lane | Filament | Livewire | Laravel | Testbench | Result |
| --- | --- | --- | --- | --- | --- |
| F4 minimum | 4.12.6 | 3.8.3 | 12.69.0 | 10.0.0 | 6 passed |
| F4 current | 4.14.0 | 3.8.10 | 12.69.3 | 10.12.0 | 6 passed |
| F5 minimum | 5.7.6 | 4.3.4 | 12.69.0 | 10.2.0 | 6 passed |
| F5 current | 5.9.0 | 4.4.7 | 12.69.3 | 10.12.0 | 6 passed |

Local PHP for these runs was 8.4.22. The published minimum PHP 8.2 is exercised in
CI only; it is not claimed as locally verified.

### PHP baseline

PHP 8.2 is supported, but it is covered differently per major:

| Combination | Installable | Test suite executed in CI |
| --- | --- | --- |
| Filament 4 on PHP 8.2 | Yes | Yes |
| Filament 4 on PHP 8.3 / 8.4 | Yes | Yes |
| Filament 5 on PHP 8.2 | Yes | **No** — dev tooling needs PHP 8.3+ |
| Filament 5 on PHP 8.3 / 8.4 | Yes | Yes |

Filament 5 on PHP 8.2 installs. A throwaway consumer project requiring the package plus
`filament/filament:^5.0` resolves with `php: 8.2` as platform and installs Filament 5.9.0,
Livewire 4.4.7 and Laravel 12.69.3. What needs PHP 8.3+ is the package's test tooling:
Pest 4, PHPUnit 12, Pint and `brianium/paratest`, reached through
Filament 5 → Livewire 4 → `pest-plugin-livewire` 4 → Pest 4. There is no Pest 3 line that
supports Livewire 4.

So `bin/resolve-lane.sh` proves installability on the minimum PHP for both majors as a
required CI job, and the Filament 5 lanes execute on 8.3 and 8.4. See
[ADR 0008](adr/0008-php-baseline-and-tooling.md). "Installable" and "behaviourally
verified" are not the same claim, and the support matrix says which is which.

For comparison, this machine previously resolved `laravel/framework` 13.34.0 via
Testbench 11. That pairing is outside the published support promise and is no
longer used: the development stack is pinned to Testbench 10 (Laravel 12).

## Adapter decisions

No compatibility adapter is required for the surfaces M0 must prove. The following
were verified to exist with identical signatures on both majors and are used
directly:

| Surface | Verified location |
| --- | --- |
| Panel plugin contract | `Filament\Contracts\Plugin`, `Filament\Panel` |
| Panel provider | `Filament\PanelProvider`, `Panel::plugin()`, `Panel::getPlugin()` |
| Panel manager | `Filament\Facades\Filament`, `Filament\FilamentManager::setCurrentPanel()`, `filament()` helper |
| Action host contract | `Filament\Actions\Contracts\HasActions` |
| Action host trait | `Filament\Actions\Concerns\InteractsWithActions` |
| Schema host | `Filament\Schemas\Contracts\HasSchemas`, `InteractsWithSchemas` |
| Action testing API | `Filament\Actions\Testing\TestsActions` (`assertActionExists`, `assertActionVisible`, `mountAction`, `assertActionMounted`, `assertMountedActionModalSee`) |
| Action rendering | `Action::toHtml()`, `filament-actions::modals` Blade component |

Rules that keep this true:

- Prefer APIs common to 4 and 5. Do not sprinkle version checks through services.
- If an unavoidable difference appears, isolate it behind a small compatibility
  adapter that preserves identical data and permission behavior. Never fork
  domain, persistence or merge logic.
- Never add a Filament-3 API, and never assume a 5-only API works on 4.
- The package does not declare Livewire directly; Filament constrains it. If a
  direct declaration ever becomes necessary it must be a validated union, never a
  v4-only pin.
- The installed major is validated server-side from Composer metadata and an
  unsupported major is rejected with a clear message.

## Local lane testing

The checked-in lockfile stays on the current Filament major. Other lanes run in an
isolated copy so the lockfile is never mutated:

```bash
bin/lane-test.sh '^4.0'                  # Filament 4, current set
bin/lane-test.sh '^4.0' --prefer-lowest  # Filament 4, lowest permitted set
bin/lane-test.sh '^5.0'                  # Filament 5, current set
bin/lane-test.sh '^5.0' --prefer-lowest  # Filament 5, lowest permitted set
```

These four commands are the same lanes CI runs. Published constraints are verified
separately, including on PHP versions the test tooling cannot run on:

```bash
bin/resolve-lane.sh '^4.0'         # resolve as the local PHP
bin/resolve-lane.sh '^5.0' 8.2.0   # resolve as if the host ran PHP 8.2
```

## M5 verification — Filament page and Livewire surfaces

M5 slice 4 added the first real panel page, so the page, route, navigation and
Livewire surfaces are now verified instead of assumed. The page classes were read
directly in both installed trees (Filament 5.9.0 locally, Filament 4.14.0 from the
`v4.14.0` tag), and the identical suite was executed on both lanes.

| Surface | Filament 4.14.0 | Filament 5.9.0 |
| --- | --- | --- |
| `Filament\Pages\Page` / `BasePage` | extends `Livewire\Component`, uses the actions and schemas traits | identical |
| Page route registration | `HasRoutes::routes()` — `Route::get('/'.getSlug(), static::class)->name(getRelativeRouteName())` inside `Route::name('pages.')->group()` | identical |
| Page route naming | `Page::getRouteName()` returns the panel-prefixed `pages.<relative>` name | identical |
| Page URL generation | static `Page::getUrl(array $parameters, ...)` → `route(getRouteName(), $parameters)` | identical |
| Navigation | `getNavigationItems()` / `getNavigationUrl()`; skipped when `shouldRegisterNavigation()` is false | identical |
| Panel page registration | `Panel::pages(array)` (`Panel\Concerns\HasComponents`) | identical |
| Plugin `register(Panel)` | invoked by `Panel::plugin()` | identical |
| Livewire mount from a route parameter | `mount(string $definition)` receives the route segment | identical |
| Livewire state updates | `->call('startScan')` re-renders with the new summary and state | identical |
| Panel-aware Livewire page test | `livewire(Page::class, ['definition' => $id])`; `mount()` `abort(404)`/`abort(403)` surface as `assertNotFound()`/`assertForbidden()` | identical |

Evidence: the full suite ran on both lanes in an isolated checkout —
Filament 4.14.0 / Livewire 3.8.10 / Laravel 12.69.3 / Testbench 10.12.0 and
Filament 5.9.0 / Livewire 4.4.7 / Laravel 12.69.3 / Testbench 10.12.0 —
291 passed, 1 skipped, 927 assertions on each. The page tests are
`tests/Feature/DuplicateReviewPageTest.php`.

No compatibility adapter was needed for these surfaces. The page deliberately uses
plain semantic markup and `wire:click` rather than Filament Blade components, for the
same cross-major reason as the banner: the rendered output is then identical on both
majors without a version check. Render hooks are **not** verified here and remain open
for the M5 gate; Filament actions are, see below.

M5 slice 5 added the pair comparison, merge confirmation and audit pages on the same
page/route/parameter surfaces, plus Livewire state updates that rebuild a server-side
plan (`setSurvivor`, `setChoice`), Filament notification assertions (`assertNotified`)
and a redirect on dismissal (`assertRedirect`). Those additions were read on both
majors and the suite passed on both lanes: 311 passed, 1 skipped, 982 assertions each
(Filament 4.14.0/Livewire 3.8.10 and Filament 5.9.0/Livewire 4.4.7). The new page tests
are `tests/Feature/DuplicateMergePageTest.php`.

M5 slice 6 registers one package stylesheet through `FilamentAsset::register` and
asserts the file the asset points at exists (`tests/Feature/AssetRegistrationTest.php`).
The stylesheet does not import Filament's theme - the panel already ships it - and adds
only focus, forced-colours and print robustness. The UUID-keyed, non-soft-deleting
fixture is driven through the same pages in
`tests/Feature/FilamentTwoModelJourneyTest.php`, which also checks that record markup
is escaped. `tests/Execution/MergePageJourneyTest.php` then confirms a merge through
the page and verifies that a pair changed after the preview is refused, on both MySQL
and PostgreSQL. A Filament action with a confirmation modal is mounted and called on
the review page (`mountAction`/`assertActionMounted`/`assertMountedActionModalSee`/
`callMountedAction`), which exercises the action and modal surfaces on a real page on
both majors. Both lanes ran 326 passed, 1 skipped, 1043 assertions each.

## Re-verification points

| Milestone | Must re-verify |
| --- | --- |
| M5 | Page/route/navigation, Livewire state, Filament action/modal and asset surfaces: verified on both majors (see above). Render hooks and manual browser review are outstanding. |
| M6 | A Filament 4 → 5 upgrade against existing package data, without a data reset |
| M7 | Full matrix, resolved version range matching the published constraints |

## Known M0 limitations

- `--prefer-lowest` resolves 4.12.6 / 5.7.6 rather than 4.0.0 / 5.0.0, because
  Filament's own sub-package minimums dominate. The lowest genuinely resolvable
  set is what is tested.
- PHP 8.2 is not available locally, so the PHP minimum lanes are CI-only.
- Filament 5 behaviour is not executed on PHP 8.2, only proven installable there
  (ADR 0008).
- PHP 8.4 emits a `symfony/translation` implicit-nullable deprecation on the
  lowest lanes. It is a vendor deprecation, not a package failure; it is recorded
  rather than suppressed.
