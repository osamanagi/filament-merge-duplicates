# Detect, review, and safely merge duplicate records in Filament apps

[![Latest Version on Packagist](https://img.shields.io/packagist/v/osamanagi/filament-merge-duplicates.svg?style=flat-square)](https://packagist.org/packages/osamanagi/filament-merge-duplicates)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/osamanagi/filament-merge-duplicates/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/osamanagi/filament-merge-duplicates/actions/workflows/tests.yml)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/osamanagi/filament-merge-duplicates/fix-code-style.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/osamanagi/filament-merge-duplicates/actions/workflows/fix-code-style.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/osamanagi/filament-merge-duplicates.svg?style=flat-square)](https://packagist.org/packages/osamanagi/filament-merge-duplicates)



> [!WARNING]
> **v1.0 is in development.** The detection, merge and Filament surfaces described
> below are implemented and pass on both Filament majors, but the package is not
> published on Packagist and the developer API may still change before the M7
> release gate. Do not use it in production yet.

Detect, explain, review and deliberately merge duplicate records in Filament
apps, without losing declared relationships. The package is generic: developers
register an independent definition per Eloquent model exposed through a Filament
resource, supplying matching rules, a field allowlist, scope, authorization and
relationship strategies. No model, column or relationship is hardcoded.

What the package does:

- Exact normalized single-field and composite matching, with an explanation for
  every suggested match. No fuzzy or AI scoring, and no probabilistic match
  claims.
- Duplicate buckets surfaced as suggestions only. A suggestion never authorizes a
  merge.
- Review of exactly two records at a time, with a per-field conflict choice.
- Explicit allowlisted scalar merging, declared `HasMany` transfers, and a
  documented blocker for every unsupported relationship instead of a guessed
  strategy.
- Soft-delete retirement plus a terminal merge ledger, so a merged source can
  never be merged again even if the row is restored outside the package.
- Queued scans with progress and cancellation, tenant-aware visibility, encrypted
  audit, and real MySQL/PostgreSQL concurrency guarantees.

There is no undo, no automatic merge, no bulk merge and no hard delete in v1.

## Installation

You can install the package via composer:

```bash
composer require osamanagi/filament-merge-duplicates
```

> [!IMPORTANT]
> The package is not published yet, so the command above fails until the v1.0
> release. To work from a checkout, add a path repository to the consuming app
> instead:
>
> ```json
> "repositories": [
>     { "type": "path", "url": "../filament-merge-duplicates" }
> ]
> ```

If you have not set up a custom theme and are using Filament Panels, follow the
instructions for your installed major first:
[Filament 4.x](https://filamentphp.com/docs/4.x/styling/overview#creating-a-custom-theme)
or [Filament 5.x](https://filamentphp.com/docs/5.x/styling/overview#creating-a-custom-theme).

After setting up a custom theme add the plugin's views to your theme css file or your app's css file if using the standalone packages.

```css
@source '../../../../vendor/osamanagi/filament-merge-duplicates/resources/**/*.blade.php';
```

Publish the config file and the migrations, then migrate:

```bash
php artisan vendor:publish --tag="filament-merge-duplicates-config"
php artisan vendor:publish --tag="filament-merge-duplicates-migrations"
php artisan migrate
```

The pages are built from Filament's own components - sections, badges, callouts,
buttons, fieldsets and radio inputs - so the panel's padding, palette and dark mode
apply everywhere. One package stylesheet then carries the comparison itself, which
Filament has no component for: the value-by-value diff rows, the two record cards, a
visible focus fallback, and isolation of record values from the interface direction,
so `+1-555-0199` or `#1024` keeps its own reading order in an Arabic panel. If your
app serves Filament's assets from `public/`, publish them as usual so the stylesheet
is not a 404:

```bash
php artisan filament:assets
```

Schedule the prune command if you want expired merge previews cleaned up. They are
refused as stale whether or not they are pruned, so this is housekeeping rather than
correctness:

```php
$schedule->command('filament-merge-duplicates:prune')->daily();
```

The translations and views are publishable under
`filament-merge-duplicates-translations` and `filament-merge-duplicates-views`,
but neither has to be published: the package ships working defaults.

## Configuration

`config/merge-duplicates.php` holds every knob. The keys that are consumed today:

| Key | Default | Meaning |
| --- | --- | --- |
| `definitions` | `[]` | Definition classes the registry resolves. A queued worker resolves them from here, so this is the whole list, not a per-panel list. |
| `connection` | `null` | Connection the package tables live on. `null` means the merged model's own connection, which must be the same one. |
| `secret` | `null` | HMAC secret for matching keys. `null` falls back to `app.key`. |
| `key_version` | `'v1'` | Part of the key digest. Bump it to invalidate every published generation and dismissal on purpose. |
| `preview.ttl_minutes` | `15` | How long a merge preview stays confirmable. An expired preview has to be rebuilt. |
| `scan.chunk_size` | `1000` | Records per chunk job. Keyset paging, never offsets. |
| `relations.max_children_per_merge` | `500` | Above this a merge is blocked with an explanation instead of truncated. |
| `supported_merge_drivers` | `['mysql', 'pgsql']` | Merge execution refuses a driver that cannot give the row locks the transaction needs. |
| `forbidden_field_names` | see file | Names that may never be part of a scalar merge, on top of the model-derived checks. |

Two things to know before you configure anything:

- Matching keys are stored as HMAC digests, never as raw values. Rotating the
  secret or `app.key` invalidates published generations and dismissals, so a
  rotation needs a rescan. Matching keys are also not portable between
  applications.
- `retention.scans_days` exists but is not consumed: pruning finished scans, their
  memberships and dismissals is not implemented, so those rows accumulate. Expired
  previews are pruned by `filament-merge-duplicates:prune`, and the merge ledger is
  never pruned. See [docs/support-matrix.md](docs/support-matrix.md) for the exact
  boundary.

## Walkthrough: two unrelated resources in one panel

The steps below are the whole integration. Both demo applications in
[docs/demo-walkthrough.md](docs/demo-walkthrough.md) follow exactly this shape
(same panel, two unrelated models, different rules and fields).

### 1. Write a definition per model

One class per model, resolved from the container. It declares what may match, what
may be merged, who may do it and how a source is retired.

```php
<?php

namespace App\MergeDuplicates;

use App\Models\Shop\Customer;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\MergeValidator;
use Nagi\FilamentMergeDuplicates\Contracts\RetirementStrategy;
use Nagi\FilamentMergeDuplicates\Contracts\ScopedRecordQuery;
use Nagi\FilamentMergeDuplicates\Contracts\WriterGuard;
use Nagi\FilamentMergeDuplicates\Definitions\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Normalization\TrimmedTextNormalizer;
use Nagi\FilamentMergeDuplicates\Relations\LockingWriterGuard;

final class CustomerDuplicates extends DuplicateDefinition
{
    public function id(): string
    {
        return 'shop-customers';
    }

    public function model(): string
    {
        return Customer::class;
    }

    public function label(): string
    {
        return 'Customer';
    }

    public function ownershipDomain(): string
    {
        return 'shop';
    }

    public function recordTitleAttribute(): ?string
    {
        return 'name';
    }

    /**
     * @return list<ExactRule>
     */
    public function matchingRules(): array
    {
        return [
            ExactRule::make('name')
                ->fields(['name'])
                ->normalizeWith(TrimmedTextNormalizer::class, lowercase: true)
                ->describedAs('Same name'),
        ];
    }

    /**
     * @return list<MergeField>
     */
    public function fields(): array
    {
        return [
            MergeField::make('name')->label('Name'),
            MergeField::make('phone')->label('Phone'),
        ];
    }

    public function acknowledgesCompleteReferenceInventory(): bool
    {
        return true;
    }

    public function contextResolver(): ContextResolver
    {
        return new PanelActorResolver;
    }

    public function scopedRecordQuery(): ScopedRecordQuery
    {
        return new UnscopedRecordQuery;
    }

    public function authorizer(): MergeAuthorizer
    {
        return new AbilityMapAuthorizer([
            Ability::Review,
            Ability::Dismiss,
            Ability::Scan,
            Ability::Merge,
            Ability::ViewAudit,
        ]);
    }

    public function validator(): ?MergeValidator
    {
        return new PassThroughValidator;
    }

    public function retirementStrategy(): ?RetirementStrategy
    {
        return new SoftDeleteRetirement;
    }

    public function writerGuard(): ?WriterGuard
    {
        return app(LockingWriterGuard::class);
    }
}
```

`PanelActorResolver`, `UnscopedRecordQuery`, `PassThroughValidator` and
`SoftDeleteRetirement` are host classes; [docs/api-reference.md](docs/api-reference.md)
lists the contracts and what each implementation has to guarantee. A definition is
rejected with a blocker when it cannot merge safely — no soft deletes on the model,
no explicit retirement strategy, no validator, no writer guard, or an unacknowledged
reference inventory.

### 2. Register the definitions and enable the panel

Add your definitions to the published config file:

```php
return [
    'definitions' => [
        App\MergeDuplicates\CustomerDuplicates::class,
        App\MergeDuplicates\BlogPostDuplicates::class,
    ],
];
```

Enable the panel, keeping the rest of the provider as it is:

```php
<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // ... the configuration the panel already had
            ->plugin(
                FilamentMergeDuplicatesPlugin::make()
                    ->definitions(['shop-customers', 'blog-posts'])
            );
    }
}
```

The plugin registers the review, comparison and audit pages on that panel. A
definition that is registered but not listed on the panel is not reachable from
it, which is what keeps one panel's definitions out of another's URLs.

### 3. Scan

Either let a reviewer start a scan from the review page, or run it from the CLI:

```bash
php artisan filament-merge-duplicates:scan shop-customers --actor=user:1 --panel=admin
php artisan filament-merge-duplicates:scan shop-customers --actor=user:1 --panel=admin --sync
```

The panel and the actor are part of the scope identity, so a scan started under a
different panel or actor publishes a generation that this panel will not show. Use
the same values the UI uses.

The default (non-`--sync`) form queues the first chunk job, so a worker has to be
running:

```bash
php artisan queue:work
```

The job processes one chunk, re-dispatches itself while work remains and publishes
the generation only when every chunk has succeeded. A failed scan leaves the
previous generation published and stores a sanitized reason code, never SQL or
values.

### 4. Review, compare, merge, audit

| Page | URL | What it does |
| --- | --- | --- |
| Review | `merge-duplicates/{definition}` | Buckets the acting user may see, with staleness and scan state |
| Compare | `merge-duplicates/{definition}/compare/{first}/{second}` | Per-field choice, blockers, retirement warning, confirm |
| Audit | `merge-duplicates/{definition}/audit/{operation}` | One merge operation, including the choices that were recorded |

The pages build their own URLs through
`DuplicateReviewPage::urlForDefinition()`, `DuplicateMergePage::urlForPair()` and
`DuplicateAuditPage::urlForOperation()`, so a host never has to reproduce a route
name.

### 5. Optional: put the suggestion count above the resource table

A panel render hook is enough. The package supplies the banner and its review URL;
the host decides where it belongs:

```php
<?php

namespace App\Providers\Filament;

use Closure;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBannerFactory;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateReviewPage;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // ... the panel's other configuration
            ->renderHook(
                PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE,
                $this->duplicateBanner('filament.admin.resources.shop.customers.index', 'shop-customers'),
            );
    }

    private function duplicateBanner(string $routeName, string $definitionId): Closure
    {
        return fn (): ?View => request()->routeIs($routeName)
            ? app(DuplicateBannerFactory::class)->viewFor(
                $definitionId,
                DuplicateReviewPage::urlForDefinition($definitionId),
            )
            : null;
    }
}
```

The route guard keeps the banner on its own resource instead of every list page.
`viewFor()` returns `null` for an actor without the review ability and for a guest,
so the hook never has to guard those cases and a panel never breaks for someone who
may not see a count. Pass a rendered scan action as the third argument if the banner
should offer one; otherwise the scan action stays on the review page.

The same banner is available inside a page through the `HasDuplicateSuggestions`
trait, which delegates every check to the same services:

```php
<?php

namespace App\Filament\Resources\Shop\Customers\Pages;

use App\Filament\Resources\Shop\CustomerResource;
use Filament\Resources\Pages\ListRecords;
use Nagi\FilamentMergeDuplicates\Filament\Concerns\HasDuplicateSuggestions;

class ListCustomers extends ListRecords
{
    use HasDuplicateSuggestions;

    protected static string $resource = CustomerResource::class;

    public function duplicateDefinitionId(): string
    {
        return 'shop-customers';
    }
}
```

`duplicateBannerView()` returns the rendered banner for the trait's definition.

## Authorization

Every operation is authorized separately, and the defaults deny:

| Ability | Gates |
| --- | --- |
| `review` | Seeing the review page, the group list and the banner |
| `dismiss` | Marking a pair as not duplicates |
| `scan` | Starting a scan, from the page or the CLI |
| `merge` | Building a preview and confirming a merge |
| `view-audit` | Reading a merge history entry |

The definition's `authorizer()` answers them. The pages, the banner and the CLI go
through services that check the ability themselves, so a forgotten check at a call
site cannot widen access. A suggestion never authorizes a merge: review and merge
are different abilities.

Depth on every contract, DTO, event and error code:
[docs/api-reference.md](docs/api-reference.md) and [docs/errors.md](docs/errors.md).
Host writers and observers:
[docs/integration.md](docs/integration.md).


## Requirements

| Item | Supported |
| --- | --- |
| PHP | 8.2+ |
| Laravel | 12.x |
| Filament | 4.x **or** 5.x, from this single release line |
| Livewire | constraining version comes from Filament: 3.x on Filament 4, 4.x on Filament 5 |
| Merge execution database | MySQL 8 (InnoDB) or PostgreSQL 15+ |

PHP 8.2 supports both Filament majors. One caveat is worth knowing up front: Filament 5
installs on PHP 8.2, but this package's test tooling needs PHP 8.3+, so Filament 5
behaviour is verified on 8.3 and 8.4. See
[docs/compatibility.md](docs/compatibility.md) and [ADR 0008](docs/adr/0008-php-baseline-and-tooling.md).

SQLite can run detection and UI tests, but merge execution refuses it because it
cannot provide equivalent row-lock guarantees. The same rules apply to a host
application's test suite.

## Testing

```bash
composer test           # full suite on the current lane
composer check          # Pint + PHPStan + Pest
composer test:coverage  # pcov coverage of src/
```

The package must pass on both Filament majors. The checked-in lockfile stays on
one major, so the other lanes run in an isolated copy:

```bash
bin/lane-test.sh '^4.0'
bin/lane-test.sh '^4.0' --prefer-lowest
bin/lane-test.sh '^5.0'
bin/lane-test.sh '^5.0' --prefer-lowest
```

Concurrency tests need real engines and are skipped when none is reachable:

```bash
docker compose up -d
vendor/bin/pest tests/Concurrency
```

See [docs/testing.md](docs/testing.md) for the coverage gate and database
services, and [docs/test-case-map.md](docs/test-case-map.md) for the acceptance
case status.

## Trying it in a real application

[docs/demo-walkthrough.md](docs/demo-walkthrough.md) walks through the two demo
installations used for the browser review (Filament 5.9 and Filament 4.11 on
MySQL), the definitions they register, the host-side setup steps a fresh clone
needs, and the states that were observed on each page.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [osamanagi](https://github.com/osamanagi)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
