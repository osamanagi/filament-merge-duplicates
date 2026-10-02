<?php

namespace Nagi\FilamentMergeDuplicates\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Scanning\ScanChunkProcessor;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Throwable;

/**
 * Processes one chunk of a scan and re-dispatches itself while work remains.
 *
 * The job carries only primitives: the definition ID and a storable context
 * array. It re-establishes its context explicitly and fails closed when it
 * cannot, so no session object, panel instance or closure is ever serialised.
 * The job is idempotent because membership writes ignore duplicates and a
 * finished scan is a no-op.
 */
final class ProcessScanChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, string|null>  $context
     */
    public function __construct(
        public readonly string $definitionId,
        public readonly array $context,
        public readonly string $scanId,
    ) {}

    public function handle(
        DefinitionRegistry $registry,
        ScopeManager $scopes,
        ScanChunkProcessor $processor,
        ScanCoordinator $coordinator,
    ): void {
        $definition = $registry->get($this->definitionId);
        $context = DuplicateContext::fromStorableArray($this->context);

        $scan = ScanRecord::on($context->connection)->find($this->scanId);

        if ($scan === null || $scan->state->isFinished()) {
            // A cancelled or already-finished scan must not publish anything.
            return;
        }

        $moreWork = $processor->process($definition, $context, $scan);

        $scan->refresh();

        if ($moreWork && $scan->isActive()) {
            self::dispatch($this->definitionId, $this->context, $this->scanId);

            return;
        }

        $coordinator->publish($definition, $scan);
    }

    public function failed(Throwable $exception): void
    {
        $context = DuplicateContext::fromStorableArray($this->context);
        $scan = ScanRecord::on($context->connection)->find($this->scanId);

        if ($scan === null || $scan->state->isFinished()) {
            return;
        }

        app(ScanCoordinator::class)->fail($scan, $exception);
    }
}
