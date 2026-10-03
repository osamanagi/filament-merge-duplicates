# Writer and observer integration

A merge moves declared children onto the survivor and retires the source. That
touches rows the rest of an application also writes, so the package and the host
have to agree on one protocol. This page is the host's half.

## The window a merge opens

Between the moment a reviewer opens a preview and the moment a merge commits, a
host path can attach children to the record that is about to be retired. Nothing
in the package can see that writer: it is ordinary application code.

Two mechanisms close the window:

1. **A writer guard.** A host path that creates or reassigns a child must, inside
   its own transaction, lock the parent and ask the guard whether the parent still
   accepts children. `LockingWriterGuard` answers that for a retired parent with
   `RecordUnavailable`. The executor follows the same protocol, which is what makes
   the guarantee portable instead of a convention.
2. **A staleness re-check.** A merge is confirmed against a preview. Every
   matching input of both records plus the definition revision form a fingerprint,
   and the plan carries the child inventory it was built from. If a child arrives
   in between, confirmation is refused as `StalePreview`, the plan is rebuilt, and
   nothing is written. This path is covered on real MySQL and PostgreSQL in
   `tests/Execution/MergePageJourneyTest.php`.

## Wiring the guard

```php
<?php

namespace App\Listeners;

use App\Models\Shop\Order;
use Nagi\FilamentMergeDuplicates\Contracts\WriterGuard;
use Nagi\FilamentMergeDuplicates\Data\RecordId;

class MoveOrderLines
{
    public function __construct(private readonly WriterGuard $guard) {}

    public function handle(Order $order, iterable $lines): void
    {
        // Lock the parent first, then ask the guard before attaching anything.
        \DB::transaction(function () use ($order, $lines): void {
            $order->newQuery()->whereKey($order->getKey())->lockForUpdate()->first();

            $this->guard->assertAcceptsNewChildren(
                connection: $order->getConnectionName(),
                modelAlias: 'orders',
                ownershipDomain: 'shop',
                parentId: RecordId::fromModel($order),
            );

            foreach ($lines as $line) {
                $order->lines()->save($line);
            }
        });
    }
}
```

The declaration is on the definition, so the guard knows which parents matter:

```php
<?php

namespace App\MergeDuplicates;

use Nagi\FilamentMergeDuplicates\Contracts\WriterGuard;
use Nagi\FilamentMergeDuplicates\Definitions\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Relations\LockingWriterGuard;

final class CustomerDuplicates extends DuplicateDefinition
{
    // ... the rest of the definition

    public function writerGuard(): ?WriterGuard
    {
        return app(LockingWriterGuard::class);
    }
}
```

A definition without a writer guard cannot merge: the configuration report lists it
as a blocker rather than allowing a merge the application cannot protect.

## Observers

Merging writes through Eloquent, so observers run. Three consequences are worth
knowing before you add one:

- **Per-model saves, not bulk updates.** Children are transferred through the
  relation, so each child fires `saving`, `saved`, `updating` and `updated` with
  the values the model would normally see. A cast, a slug generator or a
  `saved` hook behaves exactly as it does outside a merge. Bulk-updating children
  would have been faster and would have skipped all of it.
- **An observer that cancels a write aborts the merge.** If `deleting` returns
  `false` while the source is retired, or a `saving` hook throws, the executor
  aborts and reports `domain_conflict`; nothing is left half-written. That is
  deliberate: the package would rather refuse than commit a merge whose invariants
  the host just rejected.
- **An observer must not mutate the other record.** A hook that writes to the
  survivor while the source is being retired writes outside the plan, so the
  fingerprint no longer describes the pair. Keep hooks to the model they are
  attached to.

## Retirement is terminal, not reversible

The source is soft-deleted and a ledger row maps it to the survivor in the same
transaction. Restoring the row outside the package is not an unmerge: the ledger
still marks it as merged, so it is excluded from scans, from pair selection and
from execution, and the writer guard still refuses new children for it. There is
no unmerge in v1, which is why the confirmation step names the retiring record
explicitly.

## Soft deletes are required

Merging refuses a model that is not soft-deletable, because a retired row must
stay in place to keep its unique keys. Detection still works for such a model, so
the definition is not useless: the review page lists the group and reports that
merging is not enabled for it. The demo application carries exactly that case
(`blog-authors`) next to two mergeable ones.

## Relations

Only an ordinary `HasMany` is transferable in v1. Declare every inbound reference
that a merge has to move:

- `ownsCompleteInventory()` must be `true` only when the declaration covers the
  whole foreign key. A filtered relation such as `activeItems` is not proof of
  complete ownership and is rejected rather than guessed at.
- `includesSoftDeletedChildren()` decides whether trashed children move too.
- Any other relation type must still be declared: the validator then blocks the
  merge with `unsupported_relation` and names the relation, instead of silently
  orphaning the children.
- `relations.max_children_per_merge` caps a transfer. Above the cap the merge is
  refused with `merge_too_large`; children are never truncated.

`acknowledgesCompleteReferenceInventory()` is the host's statement that the
inventory is complete and tested. A definition that returns `false` cannot merge,
which is the honest answer for a model whose references nobody has audited yet.

## Where this is verified

| Guarantee | Evidence |
| --- | --- |
| A child is never attached to a retired parent by the package's own transfer | `tests/Execution/MergeExecutionTest.php`, `tests/Concurrency/LockSpikeTest.php` |
| A child arriving after the preview refuses confirmation | `tests/Execution/MergePageJourneyTest.php` (MySQL and PostgreSQL) |
| An observer that cancels the source retirement aborts the merge | `tests/Execution/MergeRollbackTest.php` |
| Children move with observers and casts intact | `tests/Execution/MergeExecutionTest.php` |
| A relation above the cap is refused, not truncated | `tests/Execution/MergeRefusalTest.php`, `tests/Feature/DefinitionValidationTest.php` |
| A model without soft deletes detects but cannot merge | `tests/Feature/FilamentTwoModelJourneyTest.php` |
| A pair that no longer matches a rule cannot be merged | `tests/Feature/DuplicateMergePageTest.php`, `tests/Unit/Scanning/DirectPairMatcherTest.php` |
