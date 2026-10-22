<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\RecordMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/** Uses an explicitly resolved actor, rather than the ambient Gate user. */
final class GateMergeAuthorizer implements RecordMergeAuthorizer
{
    /** @param array<string, string> $abilities operation value => Gate ability */
    public function __construct(
        private readonly Gate $gate,
        private readonly ?Authenticatable $actor,
        private readonly string $actorRef,
        private readonly array $abilities = [],
        private readonly ?string $pairAbility = null,
        private readonly string $childTransferAbility = 'transferForMerge',
    ) {}

    public function allows(DuplicateContext $context, Ability $ability): bool
    {
        $mapped = $this->abilities[$ability->value] ?? null;

        return $this->isActor($context) && is_string($mapped) && $mapped !== ''
            && $this->gate->forUser($this->actor)->allows($mapped);
    }

    public function allowsView(DuplicateContext $context, Model $record): bool
    {
        return $this->isActor($context) && $this->gate->forUser($this->actor)->allows('view', $record);
    }

    public function allowsPair(DuplicateContext $context, Model $survivor, Model $source): bool
    {
        if (! $this->isActor($context)) {
            return false;
        }

        $gate = $this->gate->forUser($this->actor);

        return $gate->allows('update', $survivor) && $gate->allows('delete', $source)
            && ($this->pairAbility === null || $gate->allows($this->pairAbility, [$survivor, $source]));
    }

    public function allowsChildTransfer(DuplicateContext $context, Model $child, Model $survivor, Model $source, string $relation): bool
    {
        if (! $this->isActor($context) || $this->childTransferAbility === '') {
            return false;
        }

        $gate = $this->gate->forUser($this->actor);

        return $gate->allows('update', $child)
            && $gate->allows($this->childTransferAbility, [$child, $survivor, $source, $relation]);
    }

    private function isActor(DuplicateContext $context): bool
    {
        return $this->actor !== null && $this->actorRef !== '' && $context->actorRef === $this->actorRef;
    }
}
