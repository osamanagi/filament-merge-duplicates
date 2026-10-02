<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Nagi\FilamentMergeDuplicates\Contracts\WriterGuard;
use Nagi\FilamentMergeDuplicates\Data\RecordId;

/**
 * Records the parent IDs it was asked about. Fixture only: it never blocks, so
 * it must never be used in an application.
 */
final class RecordingWriterGuard implements WriterGuard
{
    /**
     * @var list<string>
     */
    public array $checked = [];

    public function assertAcceptsNewChildren(
        string $connection,
        string $modelAlias,
        string $ownershipDomain,
        RecordId $parentId,
    ): void {
        $this->checked[] = $parentId->encode();
    }
}
