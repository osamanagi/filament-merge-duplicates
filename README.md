# Detect, review, and safely merge duplicate records in Filament apps

[![Latest Version on Packagist](https://img.shields.io/packagist/v/osamanagi/filament-merge-duplicates.svg?style=flat-square)](https://packagist.org/packages/osamanagi/filament-merge-duplicates)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/osamanagi/filament-merge-duplicates/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/osamanagi/filament-merge-duplicates/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/osamanagi/filament-merge-duplicates/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/osamanagi/filament-merge-duplicates/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/osamanagi/filament-merge-duplicates.svg?style=flat-square)](https://packagist.org/packages/osamanagi/filament-merge-duplicates)



> [!WARNING]
> **v1.0 is in development.** This repository is at milestone M0 (compatibility
> spike and design freeze). The developer API, migrations and UI described in the
> implementation plan are not implemented yet. Nothing here is installable for
> production use until the M7 release gate passes.

Detect, explain, review and deliberately merge duplicate records in Filament
apps, without losing declared relationships. The package is generic: developers
register an independent definition per Eloquent model exposed through a Filament
resource, supplying matching rules, a field allowlist, scope, authorization and
relationship strategies. No model, column or relationship is hardcoded.

Proposed behaviour, once v1.0 lands:

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
> The package is not published yet, so the command above fails until the v1.0 release. To work from a checkout, use the lane commands under Testing below.
>
> If you have not set up a custom theme and are using Filament Panels, follow the instructions for your installed major first: [Filament 4.x](https://filamentphp.com/docs/4.x/styling/overview#creating-a-custom-theme) or [Filament 5.x](https://filamentphp.com/docs/5.x/styling/overview#creating-a-custom-theme).

After setting up a custom theme add the plugin's views to your theme css file or your app's css file if using the standalone packages.

```css
@source '../../../../vendor/osamanagi/filament-merge-duplicates/resources/**/*.blade.php';
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="filament-merge-duplicates-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="filament-merge-duplicates-config"
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="filament-merge-duplicates-views"
```

This is the contents of the published config file:

```php
return [
];
```

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
cannot provide equivalent row-lock guarantees.

## Usage

The developer API is not implemented yet and is defined by the implementation
plan. The target shape is a definition class per model, plus a panel plugin:

```php
MergeDuplicatesPlugin::make()->definitions(['example-records', 'other-records']);
```

Definitions will supply matching rules, a field allowlist, a scope query,
authorization, relation strategies and a retirement contract. See
[docs/support-matrix.md](docs/support-matrix.md) for the exact v1 boundary and the
blockers for unsupported models and relationships.

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
