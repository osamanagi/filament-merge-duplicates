<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Merging\AuditWriter;
use Nagi\FilamentMergeDuplicates\Merging\SurvivorRecommender;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;

/**
 * Two decisions that a reviewer sees but cannot verify by eye: which record a merge
 * proposes to keep, and whether stored history can be read at all. The second one
 * must fail loudly - an unreadable payload reported as an empty history would tell a
 * reviewer that nothing happened when a merge may have been committed.
 */
it('recommends the older record when both creation dates are known', function () {
    $older = new Contact(['id' => 9, 'created_at' => new DateTimeImmutable('2026-01-01T00:00:00+00:00')]);
    $newer = new Contact(['id' => 2, 'created_at' => new DateTimeImmutable('2026-06-01T00:00:00+00:00')]);

    $recommendation = (new SurvivorRecommender)->recommend($newer, $older);

    expect($recommendation->survivorId->value)->toBe('9')
        ->and($recommendation->survivorId->type)->toBe(RecordIdType::Int)
        ->and($recommendation->sourceId->value)->toBe('2')
        ->and($recommendation->reason)->toContain('older');
});

it('falls back to the stable id order when the dates cannot decide', function (mixed $firstCreated, mixed $secondCreated) {
    $first = new Contact(['id' => 10, 'created_at' => $firstCreated]);
    $second = new Contact(['id' => 2, 'created_at' => $secondCreated]);

    $recommendation = (new SurvivorRecommender)->recommend($first, $second);

    // Numeric order, not lexical: 2 wins over 10.
    expect($recommendation->survivorId->value)->toBe('2')
        ->and($recommendation->sourceId->value)->toBe('10')
        ->and($recommendation->reason)->toContain('stable record ID order');
})->with([
    'no dates' => [null, null],
    'the same date' => [new DateTimeImmutable('2026-01-01T00:00:00+00:00'), new DateTimeImmutable('2026-01-01T00:00:00+00:00')],
    'only one date' => [new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null],
]);

it('round trips an audit payload through the application key', function () {
    $writer = new AuditWriter;

    $payload = $writer->encode(['field' => 'phone', 'choice' => 'source']);

    expect($payload)->not->toContain('phone')
        ->and($writer->decode($payload))->toBe(['field' => 'phone', 'choice' => 'source']);
});

it('refuses a payload it cannot decrypt with the current key', function () {
    expect(fn () => (new AuditWriter)->decode('not-a-ciphertext'))
        ->toThrow(function (DomainConflict $exception): void {
            expect($exception->errorCode())->toBe('domain_conflict')
                ->and($exception->getMessage())->toContain('cannot be decrypted');
        });
});

it('refuses a payload that decrypts to something that is not a history entry', function (string $plaintext) {
    $payload = Crypt::encryptString($plaintext);

    expect(fn () => (new AuditWriter)->decode($payload))->toThrow(DomainConflict::class);
})->with([
    'not json' => ['this is not json'],
    'json scalar' => ['123'],
    'json string' => ['"a string"'],
]);
