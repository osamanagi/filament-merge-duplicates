<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Exceptions\StalePreview;
use Nagi\FilamentMergeDuplicates\Models\PreviewRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;

/**
 * Stores merge plans server-side.
 *
 * The browser only ever receives the opaque operation ID. The payload is
 * encrypted with the application key, tied to the actor, panel and scope that
 * requested it, and expires. A token replayed by a different actor, scope or
 * panel is rejected without disclosing any of the original plan.
 */
final class PreviewStore
{
    public function __construct(private readonly int $ttlMinutes = 15) {}

    /**
     * @throws JsonException
     */
    public function put(MergePlan $plan): PreviewRecord
    {
        $payload = json_encode($plan->toPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return PreviewRecord::on($plan->connection)->create([
            'operation_id' => $plan->operationId,
            'scope_id' => $this->scopeIdFor($plan),
            'definition_id' => $plan->definitionId,
            'panel_id' => $plan->panelId,
            'actor_ref' => $plan->actorRef,
            'payload_hash' => hash('sha256', $payload),
            'plan_payload' => Crypt::encryptString($payload),
            'expires_at' => $plan->expiresAt,
        ]);
    }

    /**
     * @throws RecordUnavailable|ForbiddenOperation|StalePreview|DomainConflict
     */
    public function find(string $operationId, DuplicateContext $context): MergePlan
    {
        $record = PreviewRecord::on($context->connection)
            ->where('operation_id', $operationId)
            ->first();

        if ($record === null) {
            throw new RecordUnavailable('This merge preview no longer exists. Please review again.');
        }

        // Actor, panel and scope binding is checked before anything is decrypted,
        // so a stolen token reveals nothing at all.
        if ($record->actor_ref !== $context->actorRef || $record->panel_id !== $context->panelId) {
            throw new ForbiddenOperation('This merge preview belongs to a different operator.');
        }

        if ($record->expires_at->isPast()) {
            throw new StalePreview('This merge preview has expired. Please review again.');
        }

        try {
            $payload = Crypt::decryptString($record->plan_payload);
        } catch (DecryptException $exception) {
            throw new DomainConflict(
                'This merge preview cannot be read, most likely because the application key changed. Please review again.',
                previous: $exception,
            );
        }

        if (hash('sha256', $payload) !== $record->payload_hash) {
            throw new DomainConflict('This merge preview failed its integrity check. Please review again.');
        }

        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidConfiguration('The stored merge preview is not a valid plan.');
        }

        if (($decoded['scope_hash'] ?? null) !== $context->scopeHash) {
            throw new ForbiddenOperation('This merge preview belongs to a different scope.');
        }

        return MergePlan::fromPayload($decoded);
    }

    /**
     * Prunes expired previews. Previews have short retention; the merge ledger
     * deliberately does not.
     */
    public function pruneExpired(?string $connection = null): int
    {
        // The package tables follow `merge-duplicates.connection`, which is not
        // necessarily the application's default connection.
        $connection ??= (new PreviewRecord)->getConnectionName();

        return PreviewRecord::on($connection)
            ->where('expires_at', '<', now())
            ->delete();
    }

    public function ttlMinutes(): int
    {
        return $this->ttlMinutes;
    }

    private function scopeIdFor(MergePlan $plan): string
    {
        $scope = ScopeRecord::on($plan->connection)
            ->where('definition_id', $plan->definitionId)
            ->where('scope_hash', $plan->scopeHash)
            ->first();

        if ($scope === null) {
            throw new RecordUnavailable('The scope for this merge preview no longer exists.');
        }

        return (string) $scope->id;
    }
}
