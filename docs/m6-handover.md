# M6 handover — documentation, demo and beta

Status: **the package-side work of M6 is done.** What remains needs a human: a
GIF or short video of the journey, and beta feedback from real applications. Both
are listed under "Still open" rather than quietly dropped.

## What M6 delivered

| Task from the plan | Where it landed |
| --- | --- |
| Install and configuration guide | `README.md` (installation, every consumed config key, panel registration, authorization, requirements) |
| Walkthrough: two unrelated resources in one panel | `README.md` step by step, with the definition, the plugin, the scan and the banner; both demo applications follow that shape |
| Writer and observer integration | `docs/integration.md`: the writer-guard protocol, observer rules, why retirement is terminal, the soft-delete requirement, relation declarations |
| Supported matrix | `docs/support-matrix.md`, refreshed off M0 and extended with the housekeeping boundary |
| Error guide | `docs/errors.md`: every code, what triggers it, where it surfaces, what to do |
| API reference | `docs/api-reference.md`: contracts, provided implementations, services, pages, commands, events, exceptions, DTOs |
| Release and community templates | `.github/` audited: workflow names match the README badges, actions are pinned, permissions are least-privilege, CI already covers the documented matrix. The bug template now asks for the Filament major and the database engine |
| Demo seeders for every state | `MergeDuplicatesDemoSeeder` in the Filament 5 demo: mergeable, conflicting choice, dismissed, blocked and blank |
| Demo walkthrough evidence | `docs/demo-walkthrough.md`, extended with the seeded states, the worker-drained queued scan on both majors and the surviving dismissal |

Two things the plan listed elsewhere were also closed while doing this:

- **Render hooks**, the last M5 gate item, are verified by
  `tests/Feature/BannerRenderHookTest.php` and by the banner that both demos render
  above their own resource table.
- **Preview pruning** exists as `filament-merge-duplicates:prune`. The retention
  keys are no longer inert, and the config comment now says what is true: scans,
  memberships and dismissals are still not pruned.

## Evidence

- Both Filament lanes pass: 335 passed, 1 skipped, 1067 assertions on each, with
  Pint and PHPStan clean. CI runs the same four lanes across PHP 8.2-8.4.
- The hands-on journey was walked through on **Filament 5.9.0** and **Filament
  4.11.6**, both on MySQL: scan (queued, drained by a worker), review, compare,
  merge, audit, the banner above each resource table, and the five seeded states.
- A test parses every PHP example in the documentation, so a drifted snippet fails
  the suite instead of a reader.

## Decisions taken in M6

- **No hook helper was added to the package.** `DuplicateBannerFactory::viewFor()`
  already returns a banner or `null`, so a host needs one line in a render hook and
  no new API. The demo wires it with a route guard.
- **The blocked state is demonstrated with a real limitation**, not a fake error:
  the demo's author definition points at a model without soft deletes, so detection
  works and merging is refused with `invalid_configuration`.
- **Pruning stays narrow.** Only expired previews are pruned. Deleting scans,
  memberships or dismissals needs a retention policy that has to be argued with the
  ledger's permanence, and M6 is not the place for that.
- **Merge execution is still synchronous** inside the confirming request; scans are
  the queued part. Written down as a non-claim.

## Still open

| Item | Owner |
| --- | --- |
| A GIF or short video of the journey | Maintainer |
| Beta feedback from at least three applications with different schemas | Maintainer |
| Triaging beta blockers, then recording beta limitations against what was found | Maintainer, with the package work that follows |

## After M6

M7 is the release milestone: final CI matrix and a database concurrency run the
release gate can point at, a performance report from `tests/Performance/`, a
documentation consistency pass, `composer validate`, dependency and license review,
changelog and tag metadata, and 100% executable line coverage across `src/` on both
Filament majors. Publishing credentials and registry submissions are separate,
explicitly authorized steps outside this branch.
