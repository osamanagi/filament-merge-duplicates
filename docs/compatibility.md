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

These four commands are the same lanes CI runs.

## Re-verification points

| Milestone | Must re-verify |
| --- | --- |
| M5 | Page/action/hooks/asset APIs on both majors, Livewire state updates, modal actions |
| M6 | A Filament 4 → 5 upgrade against existing package data, without a data reset |
| M7 | Full matrix, resolved version range matching the published constraints |

## Known M0 limitations

- `--prefer-lowest` resolves 4.12.6 / 5.7.6 rather than 4.0.0 / 5.0.0, because
  Filament's own sub-package minimums dominate. The lowest genuinely resolvable
  set is what is tested.
- PHP 8.2 is not available locally, so the PHP minimum lane is CI-only.
- PHP 8.4 emits a `symfony/translation` implicit-nullable deprecation on the
  lowest lanes. It is a vendor deprecation, not a package failure; it is recorded
  rather than suppressed.
