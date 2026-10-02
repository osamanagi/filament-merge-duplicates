<?php

namespace Nagi\FilamentMergeDuplicates\Commands;

use Illuminate\Console\Command;
use Nagi\FilamentMergeDuplicates\Authorization\ServiceContextResolver;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeDuplicatesException;
use Nagi\FilamentMergeDuplicates\Jobs\ProcessScanChunk;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Throwable;

/**
 * Queues a scan for one definition and trusted scope.
 *
 * The scope is resolved server-side through the definition, never from the
 * browser or from an arbitrary model class. Credentials are explicit: a console
 * command is not an implicit administrator, so a missing actor or tenant fails
 * closed.
 */
final class ScanDuplicatesCommand extends Command
{
    protected $signature = 'filament-merge-duplicates:scan
        {definition : The registered definition ID}
        {--actor= : The service actor reference to run as}
        {--panel=cli : The panel reference to attribute the scan to}
        {--tenant= : The tenant discriminator, when the definition uses tenancy}
        {--sync : Process the scan inline instead of queueing it}';

    protected $description = 'Scan one duplicate definition and publish a new generation';

    public function handle(
        DefinitionRegistry $registry,
        ScopeManager $scopes,
        ScanCoordinator $coordinator,
    ): int {
        $definitionId = (string) $this->argument('definition');

        try {
            $definition = $registry->get($definitionId);
        } catch (MergeDuplicatesException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $actor = (string) ($this->option('actor') ?? '');
        $tenant = $this->option('tenant');
        $panel = (string) ($this->option('panel') ?? 'cli');

        if ($actor === '') {
            $this->components->error(
                'An explicit --actor is required. A console command is not an implicit administrator.',
            );

            return self::FAILURE;
        }

        $resolver = new ServiceContextResolver(
            actorRef: $actor,
            panelId: $panel,
            tenant: is_string($tenant) ? $tenant : null,
        );

        try {
            $context = $scopes->resolveContext($definition, $resolver);
            $scan = $coordinator->start($definition, $context);
        } catch (MergeDuplicatesException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('sync')) {
            try {
                $scan = $coordinator->run($definition, $context, $scan);
            } catch (Throwable $exception) {
                $this->components->error('The scan failed: ' . $exception->getMessage());

                return self::FAILURE;
            }

            $this->components->info(sprintf(
                'Scan %s %s: %d chunks, %d records, %d memberships.',
                $scan->id,
                $scan->state->value,
                $scan->counter('chunks'),
                $scan->counter('scanned'),
                $scan->counter('indexed'),
            ));

            return self::SUCCESS;
        }

        ProcessScanChunk::dispatch($definition->id(), $context->toStorableArray(), $scan->id);

        $this->components->info("Scan {$scan->id} queued for definition [{$definition->id()}].");

        return self::SUCCESS;
    }
}
