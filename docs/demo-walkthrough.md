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

## Observed results

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

- **Render hooks.** No genuine use in the package yet; deferred to the demo phase by
  agreement. The demo banner is therefore not yet rendered above a resource table.
- **A queued (asynchronous) scan.** Both demos run `QUEUE_CONNECTION=sync`, so the browser
  path is queued but drained inline. A worker-based run (`database` queue plus
  `php artisan queue:work`) still has to be walked through once, which also exercises
  `failed()` and retry handling for the chunk job.
- **Dismissal journey.** "Not duplicates" was not exercised in the browser on either major;
  dismissal is covered by tests and is on the M6 list.
