# Upgrade permanent merge identities

This upgrade separates terminal merge mappings from matching-key rotation.
New installations receive it with the other package migrations. Existing
installations must convert their ledger before rotating matching keys.

## Existing installations

1. Back up the database, put the host in maintenance mode, and drain/stop queue
   workers and every host writer that can merge or attach declared children.
   Old package workers must not write during or after the conversion.
2. Keep the original matching secret (`MERGE_DUPLICATES_SECRET`, or `APP_KEY`
   when it is unset) and `key_version`. Keep every original definition registered
   with its original connection name, model alias and ownership domain. A
   definition does not need to remain exposed on a panel to be registered.
3. Install the updated package and publish its new migration. Do not overwrite
   previously applied application migrations:

   ```bash
   php artisan vendor:publish --tag="filament-merge-duplicates-migrations"
   php artisan migrate --force
   ```

   For package tables on a named connection, use the matching
   `php artisan migrate --database=CONNECTION --force` invocation. Apply the
   published migration on each connection holding a package ledger.
4. Confirm migration completion before restarting workers on the updated code.
   Never resume old package workers against the converted ledger.

Conversion preserves all source/survivor mappings, operation IDs, audit ciphertext,
and timestamps. The existing source and operation uniqueness constraints remain
in force. Unknown domains stop the migration; register the missing definitions or
restore the original key configuration, then rerun `migrate`. A MySQL failure may
leave the new version column installed, but unconverted version-1 rows block
retirement-dependent operations until conversion succeeds. Do not manually mark
them version 2. Conflicting rows need investigation, not deletion to force a pass.

The migration refuses rollback while the ledger contains entries: older code
cannot resolve the converted domains. Keep this migration and deploy compatible
code; rolling back the package alone is not a safe downgrade.

## Subsequent key rotation

Once conversion succeeds, changing the matching secret or `key_version` no longer
changes terminal identity. Restart workers to load the new configuration and run
new scans. Old previews/dismissals and scan generations remain tied to their old
matching configuration and must not be treated as current results.

When rotating `APP_KEY`, retain the old encryption key in `APP_PREVIOUS_KEYS`
using [Laravel's key-rotation mechanism](https://laravel.com/docs/12.x/encryption#gracefully-rotating-encryption-keys).
Audit and preview payloads are encrypted independently of terminal lookup. Without
the old encryption keys, retirement checks remain valid but old audit ciphertext
cannot be decrypted. Historical audit access verifies authenticated tenant metadata
when the original scope hash no longer matches. Other tenants remain denied.

Connection names, model aliases, and ownership-domain names are still identity
inputs. Renaming them requires a separate migration. If matching keys were
already rotated before this upgrade, recover the original configuration before
conversion; a rescan cannot reconstruct the old ledger domain.
