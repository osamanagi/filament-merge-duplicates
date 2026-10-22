# Record authorization

Merge authorization requires an operation grant and explicit permission for the
actual records. Query visibility alone does not grant mutation permission.

## Upgrade existing definitions

`MergeAuthorizer` still supports detection-only definitions. Merge-capable
implementations must implement its extension, `RecordMergeAuthorizer`:

| Check | Required permission |
| --- | --- |
| `allowsView(context, record)` | View each parent and each transferred child |
| `allowsPair(context, survivor, source)` | Update the survivor, retire the source, and permit this exact pairing |
| `allowsChildTransfer(context, child, survivor, source, relation)` | Update this child and attach it to this exact survivor |

An operation-only authorizer produces a merge configuration blocker; detection
continues. `AbilityMapAuthorizer` denies record access by default, even when its
operation map includes `Merge`. Tests or intentionally trusted service accounts
can explicitly use `allowAllRecords: true`, preferably with an actor reference.
That option grants all affected records within the definition's scope. Applications
with record permissions should use policies instead. No schema migration is
required for this authorization change.

## Laravel Gate adapter

`Authorization\GateMergeAuthorizer` implements both contracts. Construct it with
the Gate contract, an explicitly resolved `Authenticatable` actor, its trusted
context actor reference, and a map from operation values to host Gate abilities:

```php
return new GateMergeAuthorizer(
    gate: app(\Illuminate\Contracts\Auth\Access\Gate::class),
    actor: $resolvedActor,
    actorRef: $trustedActorRef,
    abilities: [
        Ability::Review->value => 'duplicates.review',
        Ability::Merge->value => 'duplicates.merge',
    ],
    pairAbility: 'mergeWith', // Optional extra survivor/source policy check.
);
```

Register the operation abilities in the application. Unmapped abilities deny
access. Resolve the actor from the same guard or service identity as the
`ContextResolver`; never accept an actor reference from browser input. The adapter
requires a non-null actor and a nonempty actor reference matching the context.
It calls [Gate::forUser](https://laravel.com/docs/12.x/authorization#authorizing-actions-for-other-users),
so a different ambient authenticated user cannot supply the permission.

The adapter checks parent `view`, survivor `update`, and source `delete` policies.
When supplied, `pairAbility` additionally receives the survivor and source.
Children need `view`, `update`, and the explicit `transferForMerge` policy ability.
The child transfer ability name is configurable; an empty name denies transfers.
A conventional update grant alone never authorizes reassignment.

Laravel passes [additional policy context](https://laravel.com/docs/12.x/authorization#supplying-additional-context)
after the first model. A child policy can therefore check the exact target:

```php
final class NotePolicy
{
    public function transferForMerge(
        User $actor,
        Note $child,
        Customer $survivor,
        Customer $source,
        string $relation,
    ): bool {
        return $relation === 'notes'
            && (string) $child->customer_id === (string) $source->getKey()
            && $actor->can('update', $survivor)
            && $child->tenant_id === $survivor->tenant_id;
    }
}
```

Adapt the ownership and tenant checks to the application's schema. Register
policies through Laravel's discovery or `Gate::policy`. Host Gate hooks, including
administrative overrides, remain part of the application's authorization rules.

## When checks run

Preview verifies parent model/scope visibility, the chosen pair, and every child
in a transferable inventory before storing the preview. Unsupported or oversized
relations remain blocked previews. Changing survivor direction creates a new
preview and checks the new direction.

Execution reloads and locks parents and children, then checks their permissions
before any model write. It checks each child again immediately before changing
its foreign key, and refreshes and rechecks the parents before retiring the source.
Any denial aborts the whole transaction, including scalar edits, earlier child
transfers and the merge ledger. A denied child is never silently skipped.
Declared soft-deleted children need the same permissions. The declared relation
and its global scopes still define which children belong to the inventory; the
host must declare a complete supported reference inventory.

A repeated operation is read-only. It requires the operation's original actor and
context, current scoped parent visibility, and current parent view policies. It
can return the committed result after update/delete permissions are revoked,
but refuses when either parent becomes invisible or loses view permission.
The replay lookup includes the retired source while preserving other global and
tenant scopes. Audit history has its separate `view-audit` permission.

These checks do not authorize arbitrary host writers. Continue using the
[writer and observer contract](integration.md); external observer side effects
cannot be undone by a database rollback.
