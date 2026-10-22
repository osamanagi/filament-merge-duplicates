<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Contracts\RecordMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Relations\HasManyTransfer;

/** Record checks shared by preview, execution, and read-only operation replay. */
final class MergeAuthorization
{
    public function __construct(private readonly HasManyTransfer $transfer) {}

    public function assertView(DuplicateContext $context, DuplicateDefinition $definition, Model $record): void
    {
        if (! $this->authorizer($context, $definition)->allowsView($context, $record)) {
            throw new ForbiddenOperation('The acting user may not view a record affected by this merge.');
        }
    }

    public function assertPair(DuplicateContext $context, DuplicateDefinition $definition, Model $survivor, Model $source): void
    {
        $this->assertView($context, $definition, $survivor);
        $this->assertView($context, $definition, $source);

        if (! $this->authorizer($context, $definition)->allowsPair($context, $survivor, $source)) {
            throw new ForbiddenOperation('The acting user may not update this survivor or retire this source as a pair.');
        }
    }

    public function assertChildren(DuplicateContext $context, DuplicateDefinition $definition, Model $survivor, Model $source, bool $lock = false): void
    {
        foreach ($definition->relations() as $strategy) {
            foreach ($this->transfer->childrenFor($strategy, $source, $lock) as $child) {
                $this->assertChild($context, $definition, $child, $survivor, $source, $strategy->name());
            }
        }
    }

    public function assertChild(DuplicateContext $context, DuplicateDefinition $definition, Model $child, Model $survivor, Model $source, string $relation): void
    {
        $this->assertView($context, $definition, $child);

        if (! $this->authorizer($context, $definition)->allowsChildTransfer($context, $child, $survivor, $source, $relation)) {
            throw new ForbiddenOperation('The acting user may not transfer every child affected by this merge.');
        }
    }

    private function authorizer(DuplicateContext $context, DuplicateDefinition $definition): RecordMergeAuthorizer
    {
        $authorizer = $definition->authorizer();

        if ($context->definitionId !== $definition->id() || $context->connection !== $definition->connection()
            || ! $authorizer instanceof RecordMergeAuthorizer) {
            throw new ForbiddenOperation('This definition does not authorize individual merge records in the current context.');
        }

        return $authorizer;
    }
}
