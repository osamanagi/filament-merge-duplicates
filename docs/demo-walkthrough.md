# Demo walkthrough — both Filament majors

Evidence for the M5 gate line «hands-on demo reproduces scan, review, merge and audit in
both installations». It records the two installations, the definitions they register, the
setup steps that a fresh clone needs, and what was observed in a real browser.

## Installations

| | Filament 5 | Filament 4 |
| --- | --- | --- |
| App | `/Users/nagi/code/demo` | `/Users/nagi/code/demo-f4` |
| Source | [`filamentphp/demo`](https://github.com/filamentphp/demo) branch `5.x` | same repo, branch `4.x` |
| Filament | 5.9.0 | 4.11.6 |
| Laravel | 12.69.2 | 12.61.0 |
| PHP | 8.4.22 | 8.4.22 |
| Database | MySQL `demo_merge` | MySQL `demo_merge_f4` |
| URL | `http://127.0.0.1:8123` | `http://127.0.0.1:8124` |
| Queue | `sync` | `sync` |

The package source is not a composer dependency in either app. The demo loads
`../filament-merge-duplicates/src/` through a PSR-4 autoload entry, so the checkout under
review is what the browser exercises. The package's own service provider is registered by
hand (`bootstrap/providers.php` on Laravel 11+, `config/app.php` on the 4.x app), because
package discovery only finds composer dependencies.

## Definitions registered

| Definition ID | Model | Match rule | Merge fields |
| --- | --- | --- | --- |
| `shop-customers` | `App\Models\Shop\Customer` (both apps) | `name`, trimmed and lowercased | `name`, `phone` |
| `hr-employees` | `App\Models\HR\Employee` (5.x only) | `name` + `phone` | `name`, `job_title`, `phone` |
| `blog-posts` | `App\Models\Blog\Post` (4.x only) | `title`, trimmed and lowercased | `title`, `seo_title` |

The second definition differs per major because the 4.x demo has no HR module; blog posts
are its second, unrelated resource. That still satisfies the plan's requirement ("a second
resource with different fields and rules must work in the same panel without modifying
plugin internals"): nothing in the package is configured per model, and the only difference
between the two demos is the host's own definition class.

Two host-side constraints surface when choosing a model, and both are documented
requirements rather than demo quirks:

- The model **must be soft-deletable**, because v1 retires the source instead of deleting
  it. `shop-customers` and `hr-employees` already are; `blog_posts` gained a `deleted_at`
  column in the 4.x demo (`database/migrations/2026_10_03_000001_add_soft_deletes_to_blog_posts_table.php`).
- **Unique columns must stay out of both the rule and the merge fields.** `shop_customers.email`,
  `blog_posts.slug` and `employees.email` are unique, so copying one row's value into the
  other would violate the index and a difference on them would be a fatal blocker.

## Setup steps for a fresh clone

The 5.x steps were recorded while integrating; the 4.x steps are the same plus the two
differences noted at the end.

1. `git clone -b <branch> git@github.com:filamentphp/demo.git`, then `composer install`.
2. `.env`: MySQL database, `APP_URL` on the demo's port, `php artisan key:generate`.
3. Add `"Nagi\\FilamentMergeDuplicates\\": "../filament-merge-duplicates/src/"` to the
   `autoload.psr-4` block and run `composer dump-autoload`.
4. Register `Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesServiceProvider`.
5. `config/merge-duplicates.php`: list the definition classes under `definitions` and keep
   `supported_merge_drivers` at `['mysql', 'pgsql']`.
6. `php artisan vendor:publish --tag=filament-merge-duplicates-migrations` then
   `php artisan migrate --seed`. The package does not auto-run its migrations in a host app.
7. Seed the duplicate pairs the demo's own seeder does not create; see
   `database/seeders/DuplicateDemoSeeder.php` in the 4.x app.
8. `php artisan filament:assets`. Without it the package CSS 404s at
   `/css/osamanagi/filament-merge-duplicates/filament-merge-duplicates.css`.
9. Register the plugin on the panel:
   `FilamentMergeDuplicatesPlugin::make()->definitions([...])`.
10. Scan. Either the review page's own action, or the CLI with the same attribution as the
    UI: `php artisan filament-merge-duplicates:scan shop-customers --actor=user:1 --panel=admin --sync`.
    The panel and actor matter: they are part of the scope identity, so a scan started under
    another panel produces a generation this panel never shows.

4.x-only differences:

- `bootstrap/providers.php` does not exist; providers are listed in `config/app.php`.
- The 4.x demo never registers its Vite theme on the panel, so Tailwind utilities that its
  own Blade (the brand logo, heroicons) relies on are missing and icons render at full size.
  `->viteTheme('resources/css/app.css')` plus `npm install && npm run build` fixes it.
- The panel path is `/`, so the login page is `/login`, not `/admin/login`.

## Seeded states

`database/seeders/MergeDuplicatesDemoSeeder.php` in the Filament 5 demo produces every
state the walkthrough has to show, so nothing is hand-edited before a review:

| State | How it is produced | What the pages show |
| --- | --- | --- |
| Mergeable | Two customers with the same name and the same phone | Confirmation needs no choice and is enabled |
| Conflicting choice | Two customers with the same name and different phones | The comparison requires a value for the phone |
| Dismissed | A third pair, dismissed after the scan | The pair is absent from the list and stays absent after a rescan |
| Blocked | Authors: the model is not soft-deletable, so the definition is detection-only | The comparison states that merging is not enabled and reports `invalid_configuration` |
| Blank | Employees are scanned with no duplicate to find | The list reports an empty result with the scan time, not "never scanned" |

The seeder scans through `filament-merge-duplicates:scan … --sync` with the same panel
and actor the UI uses, because both are part of the scope identity. It is idempotent:
rows are keyed on their unique column and the dismissed pair is dismissed again on every
run.

## Observed results

### The queued path, drained by a real worker

The Filament 5 demo runs `QUEUE_CONNECTION=database`, so the review page's scan control
queues work instead of doing it inside the request. With no worker running, the page
reports "Scanning for duplicates…" and keeps the previously published groups on screen.
Running `php artisan queue:work --stop-when-empty` then processes `ProcessScanChunk` chunk
by chunk, each job re-dispatching itself while records remain, and the page afterwards
reports the new completion time with the groups in place. Before the `ScanStarter` fix this
run would have queued a scan that nothing ever drained.

The dismissed pair was suppressed before that rescan and stayed suppressed after it, which
is the behaviour the plan asks for: a dismissal survives a scan that finds the same pair
unchanged.

Filament 5.9.0 (`demo_merge`), definition `shop-customers`:

| Step | Result |
| --- | --- |
| Review page | "Customer duplicates · 1 possible group", group **Same name** with `#1024` and `#1025`, banner, staleness line and Scan action |
| Compare page | Survivor choice, `name` keeps the survivor, `phone` requires a choice; Confirm stays disabled until the choice exists |
| Confirm merge | "Merge completed", audit reference `01M3ZD1X9KNATYNPYED6NZ6A2Q`, links to the audit entry and back to the list |
| Audit page | Full entry: actor `user:1`, panel `admin`, `choice: source` for `phone` with before/after values |
| List afterwards | 0 groups — the retired source is excluded |
| Second definition | `hr-employees` renders its own label, rule label and group; its merge produced audit entry `01M3ZDGDWXNZJWMMPK813K50KS` |
| UI-started scan | Completes ("Last completed scan: 3 seconds ago"); the scan row is `succeeded` with 118 records |

Filament 4.11.6 (`demo_merge_f4`):

| Step | Result |
| --- | --- |
| Review page | "Customer duplicates · 1 possible group", group **Same name** with `#1001` and `#1002` |
| Compare page | Same states as 5.9.0 on the Filament 4 markup (heading, region, radio groups) |
| Confirm merge | "Merge completed", audit reference `01M3ZE0HWC9CWABP54R983BWW5`, plus the "Merged into Ada Lovelace." notification |
| Audit page | Full entry: actor `user:1`, panel `admin`, `source_id int:1002`, `survivor_id int:1001` |
| List afterwards | 0 groups |
| Second definition | `blog-posts` renders "Post duplicates · 1 possible group", group **Same title** with `#101` and `#102` |
| UI-started scan | Completes ("Last completed scan: 4 seconds ago") |

The user-facing pages use no Filament Blade components, so the same views render on both
majors; the only markup difference observed is the surrounding panel chrome
(`filament-actions::modals`, topbar vs sidebar navigation), which the pages do not control.

## Defects the demo surfaced

- **The package config file was never loaded** in a real app: the provider derived the
  config name from the package name and looked for `config/filament-merge-duplicates.php`
  while the file is `config/merge-duplicates.php`. Fixed, with a boot test.
- **A scan started from the page never ran.** `ScanStarter` created the queued scan row but
  nothing dispatched `ProcessScanChunk`; only the CLI did. The page therefore showed
  "Scanning for duplicates…" forever and the scan row stayed `queued` with zero chunks.
  Fixed in `ScanStarter`, pinned by `Queue::assertPushed` in the page and starter tests.
- **Blade escapes a `View` object.** `{{ $bannerView }}` HTML-escaped the banner despite
  `Htmlable`; the review page uses `{!! ... !!}`.

## Still open after this walkthrough

- **A Filament 4 run of the queued path.** The worker-based scan was walked through on the
  Filament 5 demo; the Filament 4 demo still runs a synchronous queue, where the same
  dispatch drains inline.
- **A GIF or short video of the journey.** Recorded by hand, not by the test suite.
