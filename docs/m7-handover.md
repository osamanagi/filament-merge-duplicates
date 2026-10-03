# M7 handover — stable v1.0

Status: **the package-side work of M7 is done and merged.** The release is held by
two items that only a human can produce: the demo GIF or video, and beta feedback
from real applications. They are listed under "Still open" rather than quietly
dropped.

Branch: `m7/stable-v1.0`, merged into `main` as PR #8 (`c2baba0`) on 2026-10-03,
carrying M6 (PR #7). No tag exists yet.

## What M7 delivered

| Gate item from the plan | Evidence |
| --- | --- |
| 100% of *reachable* `src/` lines, on both Filament majors, with reviewed branch gaps | 98.2% of all lines, with every remaining line enumerated below; 624 passed / 1 skipped / 1746 assertions, identical on Filament 4 and Filament 5 |
| No unresolved data-loss or authorization bug | Two real defects found and fixed (below); the authorization paths are covered by refusal tests in every layer |
| Every case ID mapped | `docs/test-case-map.md`, refreshed with the M7 tests |
| C02–C07 pass | Executed and recorded in `docs/test-case-map.md` |
| Version constraints match the tested range | `composer validate --strict` clean; `bin/resolve-lane.sh '^4.0'` resolves Filament 4.14.0 and `'^5.0'` Filament 5.9.0, both on Livewire as required; `docs/support-matrix.md` now separates "installs" from "verified" for Laravel 13, which Filament's own constraint pulls in |
| A real concurrency run | `tests/Concurrency` on MySQL 8.0 and PostgreSQL 15: 7 passed |
| A measured performance report | `MERGE_DUPLICATES_BENCHMARK=1`, numbers in `docs/testing.md` |

## Coverage

Measured with pcov on the full suite, `src/` only:

```
vendor/bin/pest --coverage --min=98        # 98.2%, 624 passed, 1 skipped
```

Both lanes report the same totals (`bin/lane-test.sh '^4.0'` and `'^5.0'`: 624
passed, 1 skipped, 1746 assertions each), which is the point of the single
release line: the same suite, the same percentages, two Filament majors. The
`^4.0` lane resolves Filament 4.14.0 and the `^5.0` lane Filament 5.9.0; the
hands-on browser pass in M6 was done on 4.11.6 and 5.9.0.

Started at 83.3% and ended at 98.2% in eleven batches:

| Commit | Coverage | Covered in that batch |
| --- | --- | --- |
| `0d393e5` | 85% | CLI paths, chunk job, error codes; unused facade deleted |
| `1f0169e` | 86% | context, configuration report, resolvers, normalizers, scan lifecycle |
| `e8d09b8` | 87% | audit page refusals and the history formatter |
| `8c9d84f` | 87.8% | value codec, audit writer, survival recommender, retry policy, hashing, record IDs |
| `9d14277` | 89.7% | definition validator blockers |
| `1a207d7` | 91.1% | definition registry, plan relation shapes |
| `bbaa5a5` | 92.2% | preview store integrity, lock manager, audit reader, fingerprint and diff, normalizers, key builder, writer guard |
| `3abe42a` | 93.9% | relation plan blockers, child transfer, survivor chains, scope races, preview service |
| `643175c` | 94.5% | dismissal service, CLI credential refusal, group titles, definition defaults, panel surface |
| `050ea56` | 95.1% | every refusal the merge executor answers before it commits (30 engine-marked cases) |
| `fc6417f` | 96.1% | validator inputs it cannot read, json-column refusal on both engines |
| `758ee49` | 97.4% | page refusals, value formats, guest-safe guards, conflicting plugin |
| `978dafd`, `c76e1e8` | **98.2%** | executor replays, tampered inventories, host-observer postconditions, committed-choice reading, a failed host write |

## Review of the coverage exceptions

The M7 gate asks for reviewed branch gaps, not for a metric that is satisfied by
writing tests against stubs that break their own contracts. Every line the suite
does not execute is listed here with why it cannot be reached, and each one is a
guard or a postcondition rather than untested behaviour.

| File and lines | Why the suite cannot reach it |
| --- | --- |
| `Merging/MergeExecutor` 217 | `DB::connection()->transaction()` can only return the closure's `MergeResult`; the check is the type bridge PHPStan needs |
| `Merging/MergeExecutor` 387, 719 | A soft-deleted source is filtered out by `fetchVisible()` several steps earlier, so the later "is it trashed" guards cannot fire |
| `Merging/MergeExecutor` 413, 529, 759-761 | A `relations()` entry that is not a `RelationStrategy` is refused by the definition validator, so no preview can exist to execute |
| `Merging/MergeExecutor` 536-538 | The inventory is compared with the same `childIdsFor()` the count comes from, so the inventory check always fires first |
| `Merging/MergeExecutor` 560, 715, 723, 729, 745, 762 | Postconditions that mirror a check made earlier in the same transaction; the ledger's unique index also makes the duplicate-row case impossible |
| `Merging/MergeExecutor` 834 | The second reader is only called for records whose existence was proven in the same transaction |
| `Merging/RelationPlanBuilder` 176, 184-185, 203-211 | Needs an inventory relation that is not a HasMany, and a unique index on the foreign key alone; neither exists in the fixture schema, and declaring the former is refused before planning |
| `Merging/MergePlanner` 184-185 | Schema introspection throws only on an unconfigured connection, which the validator refuses first |
| `Definitions/DefinitionValidator` 626-713 | Schema and connection reads that throw for a connection the validator has already rejected |
| `Normalization/TrimmedTextNormalizer` 24-26, 51 | The intl-missing guard cannot fire with intl loaded, and `\Normalizer::normalize()` cannot fail for a string whose UTF-8 was already checked |
| `Scanning/ReviewGroupQuery` 114-116, 153, `Scanning/SuggestionQuery` 171, 183 | Race guards: a record changing between the bucket query and the member load |
| `Filament/Pages/DuplicateMergePage` 470-472 | A non-scalar field value, which the validator stops a definition from declaring |
| `Filament/Concerns/HasDuplicateSuggestions` 144 | A booted Filament application always has a panel |

The threshold in `composer test:coverage` and in the CI coverage job is set to
the measured floor (**98**), and the CI job is now blocking rather than a draft.
It is a ratchet, not a claim that the lines above are covered.

## Defects found and fixed while raising coverage

1. **A signed key could be written and never read back.** `RecordId::fromStored()`
   rejected a negative integer (`-5`) with `ctype_digit()`, while `fromModel()`
   accepted one, so such an ID could land in a membership row and then fail to
   resolve. `isIntegerLiteral()` accepts one leading minus and still rejects a
   lone minus, letters, decimals and padded values.
2. **A composite primary key crashed the validator mid-run.**
   `DefinitionValidator::checkFields()` died on `getCasts()` with a `TypeError`
   for a model whose `getKeyName()` returns an array, instead of reporting the
   identity blocker the validation exists to produce. The key name is no longer
   coerced to a string, and the field scan stops for a key Eloquent cannot use.

## Dead code removed because coverage exposed it

Reported rather than silently deleted, because each removal changes what the
package claims to ship:

- `src/Facades/` and its `extra.laravel.aliases` entry — the alias pointed at a
  class that no longer exists, which a host's `package:discover` would have
  registered for nothing.
- `src/FilamentMergeDuplicates.php` — an empty class that only existed as the
  facade's target.
- The stub-publishing loop in the service provider: it iterated an empty
  `stubs/` directory (`Filesystem::files()` skips dotfiles), so it published
  nothing.
- The unreachable re-resolve branch in `DefinitionRegistry::get()`: registration
  always resolves eagerly, so `get()` can only fail for an unregistered ID.
- A `catch (Throwable $exception) { throw $exception; }` in
  `ScopeManager::ensure()`.
- The `strrpos() === false` guard in `EmailNormalizer`: `FILTER_VALIDATE_EMAIL`
  guarantees an `@`.
- The executor's cross-connection guard: the definition validator refuses a
  connection mismatch first.

## Release checks

| Check | Result |
| --- | --- |
| `composer validate --strict` | valid |
| `composer update --lock` audit | no security advisories |
| Licence review | MIT (this package), MIT and BSD-3-Clause/Apache-2.0 dependencies; the only GPL strings in the tree are the dual-licensed `nette/*` packages, used under BSD-3-Clause |
| Dependency review | runtime: `php ^8.2`, `filament/filament ^4.0 \|\| ^5.0`, `spatie/laravel-package-tools ^1.16`; everything else is a dev dependency |
| Static analysis | PHPStan level 5, no errors |
| Code style | Pint clean on every commit in the branch |
| Both lanes | 624 passed / 1 skipped / 1746 assertions on `^4.0` and `^5.0` |
| Final CI matrix | Green on `main` after the merge (run [37154968412](https://github.com/osamanagi/filament-merge-duplicates/actions/runs/37154968412)): Pint and PHPStan, **both coverage gates blocking and passing**, 16 lane jobs across `f4/f5` x `lowest/current` x PHP 8.2/8.3/8.4 with MySQL 8 and PostgreSQL 15 services, and 6 resolution jobs for `^4.0`/`^5.0` on PHP 8.2/8.3/8.4. `zizmor` and `fix-code-style` also pass |
| Execution suite on real engines | MySQL 8.0 and PostgreSQL 15, all green |
| Concurrency suite | 7 passed across both engines |
| Benchmark | recorded in `docs/testing.md`, with the M4 numbers alongside |
| Documentation consistency | every PHP example in the README and `docs/` is executed by `tests/Feature/DocumentationExamplesTest.php` |
| Hands-on journey on both majors | walked end to end on the merged `main`, on a Filament 5 and a Filament 4 installation (see below) |

## Hands-on verification on both majors

The M7 gate asks for the journey to be reproduced in both installations, not just
in tests. It was walked on `main` after the merge, with the package loaded by
PSR-4 so the merged code is what ran:

| Step | Filament 5 (`:8000`, MySQL) | Filament 4 (`:8124`, MySQL) |
| --- | --- | --- |
| Banner above the resource table | 2 possible duplicate groups, with the age of the last scan | empty state before the scan: "No possible duplicates found" |
| Scan | started from the review page; queue worker drained the chunk jobs | confirmation modal, then `ProcessScanChunk` ran twice (158 ms / 14 ms) and the banner moved to 1 group |
| Review | `Same name` group of 3 records for Ada Lovelace | `Same name` group for the pair seeded for the run |
| Compare | `Confirm merge` refused until the conflicting `phone` was chosen | same refusal, then the source value was chosen instead |
| Merge | completed, audit reference shown and notification sent | completed, audit reference shown and notification sent |
| Audit | full entry: definition, revision, `scope_hash`, `actor_ref user:1`, `panel_id admin`, fingerprint, per-field before/after | full entry, `choice: "source"` and `after: +1-555-0299` recording the taken value |
| Database | survivor kept, source soft-deleted at the merge timestamp | survivor took the source value, source soft-deleted, ledger row for the operation |

Both installations rendered the pages with plain semantic markup and no
major-specific view differences, which is the claim the single release line
rests on.

## Decisions taken in M7

- **The coverage threshold is a floor, not a target.** The target stays 100% of
  reachable lines; the exceptions above are enumerated and reviewed, and the CI
  job enforces the measured number so it cannot drop.
- **The dead code above was deleted rather than tested around.** A guard that no
  caller can reach is either a contract that is no longer needed or a contract
  that is enforced somewhere else; in each case the file that enforces it was
  identified before the guard was removed.
- **Two defensive guards were kept and documented instead of removed**: the
  `MergeResult` type bridge (PHPStan) and the postcondition set in the executor,
  because they protect against a host observer breaking an invariant mid-merge.
  Their coverage exception is the price of keeping the protection.
- **Nothing was weakened to satisfy a metric.** No assertion was deleted and no
  `--min` was lowered; the number moved from 83.3% to 98.2% by writing tests.

## Follow-ups recorded after the first green run

Both CI annotations from the merge run are now closed; neither was a gate item, so
they were fixed after the release gate rather than inside it.

- `actions/upload-artifact` was pinned at v4.6.2, which targets Node 20. The pin is
  now **v7.0.1** (`043fb46d1a93c77aae656e7c1c64a875d1fc6a0a`), the current release,
  which targets Node 24. The inputs used here (`name`, `path`,
  `if-no-files-found`) are unchanged in v7, and Dependabot already watches
  `github-actions` weekly, so this pin keeps moving on its own.
- `ubuntu-latest` moves to Ubuntu 26 on 2026-10-19. Every job in `tests.yml` is now
  pinned to **`ubuntu-24.04`**, with the reason recorded in the workflow header:
  the lane and coverage jobs provision MySQL 8 and PostgreSQL 15 service
  containers and install PHP extensions, so the image is part of what was tested.
  Moving the pin is a deliberate change with a green run, not something to inherit
  on a release day. The documentation-only workflows (`zizmor`, `fix-code-style`,
  `update-changelog`) stay on `ubuntu-latest` on purpose: they hold no service
  containers, and they are the cheapest early warning if Ubuntu 26 does break
  something.

Validated by the `main` run that followed the change.

### Formatter version drift, found on 2026-10-04

A `style:` commit on `main` (`88c9137`) was undone by the `fix-code-style` workflow
(`3089581`) because two formatters disagree about the same seven files. The pushed
version had `static fn(mixed $name)` and `!$flag`; the repository's Pint wants
`static fn (mixed $name)` and `! $flag`. The Pint job failed on `88c9137`, which is
the only red run since the merge.

Two traps came out of it, both worth remembering:

- The auto-commit pushes with the default `GITHUB_TOKEN`, and GitHub does not start
  workflow runs for those pushes. `main` therefore spent time with a tip
  (`3089581`) that no run had ever tested; the next hand-made push is what validates
  it. A green board does not prove the tip was tested.
- A style disagreement should be settled by running `vendor/bin/pint` from this
  repository, not an editor-bundled formatter. Compare `vendor/bin/pint --version`
  with the version the editor uses before pushing a `style:` commit.

## Still open

| Item | Owner |
| --- | --- |
| A GIF or short video of the journey (scan, review, merge, audit, banner) | Maintainer |
| Beta feedback from at least three applications with different schemas | Maintainer |
| An RTL and a keyboard-only pass on both majors (U01 stays `partial` for this) | Maintainer |
| Triaging beta blockers and recording the limitations beta found | Maintainer, with the package work that follows |
| Tagging `v1.0.0` and submitting to the registries | Maintainer, explicitly authorized. The merged `main` is the tag target, and the `v1.0.0` changelog section is already written for the release automation |
| The 40 lines listed under "Review of the coverage exceptions" | None: they are reviewed and closed as unreachable |

## After M7

The next milestone is triage of beta feedback. Two things are written down as
non-claims so they are not mistaken for features: scans, memberships and
dismissals are never pruned (`retention.scans_days` is reserved and unconsumed),
and merge execution is synchronous inside the confirming request while scans are
queued.
