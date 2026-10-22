<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Relations;

use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeTooLarge;
use Nagi\FilamentMergeDuplicates\Exceptions\UnsupportedRelation;
use Nagi\FilamentMergeDuplicates\Relations\HasManyTransfer;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * The transfer adapter moves declared children one model at a time so the host's
 * casts, mutators and observers keep running. Two things must therefore abort a
 * merge rather than half-finish it: a relation above the configured cap, and a
 * child whose write did not actually change the foreign key.
 */
it('refuses an inventory above the configured cap', function () {
    [$survivor, $source] = transferPair();
    noteFor($source, 'Call back');

    expect(fn () => (new HasManyTransfer(maxChildrenPerMerge: 0))->inventory(
        transferDefinition(),
        new CompleteHasMany('childNotes'),
        $source,
    ))->toThrow(MergeTooLarge::class);

    expect(fn () => (new HasManyTransfer(maxChildrenPerMerge: 0))->childrenFor(new CompleteHasMany('childNotes'), $source))
        ->toThrow(MergeTooLarge::class);
});

it('refuses to transfer more children than the configured cap', function () {
    [$survivor, $source] = transferPair();
    noteFor($source, 'Call back');

    expect(fn () => (new HasManyTransfer(maxChildrenPerMerge: 0))->transfer(
        new CompleteHasMany('childNotes'),
        $survivor,
        $source,
    ))->toThrow(MergeTooLarge::class);
});

it('rejects relations that reference an alternate parent key', function () {
    [$survivor, $source] = transferPair();
    expect(fn () => (new HasManyTransfer)->transfer(new CompleteHasMany('referenceNotes'), $survivor, $source))
        ->toThrow(UnsupportedRelation::class, 'parent primary key');
});

it('aborts when a child write does not move the foreign key', function () {
    [$survivor, $source] = transferPair();
    $note = noteFor($source, 'Call back');

    // A host observer that restores the original parent: the write succeeds, so
    // only a re-read can detect that the child did not move.
    Note::saving(function (Note $saving) use ($note): void {
        if ($saving->getKey() === $note->getKey()) {
            $saving->contact_id = $note->getOriginal('contact_id');
        }
    });

    expect(fn () => (new HasManyTransfer)->transfer(new CompleteHasMany('childNotes'), $survivor, $source))
        ->toThrow(function (DomainConflict $exception): void {
            expect($exception->getMessage())->toContain('did not move to the survivor');
        });

    Note::flushEventListeners();
});

it('moves every declared child and reports what it moved', function () {
    [$survivor, $source] = transferPair();
    noteFor($source, 'One');
    noteFor($source, 'Two');

    $result = (new HasManyTransfer)->transfer(new CompleteHasMany('childNotes'), $survivor, $source);

    expect($result['count'])->toBe(2)
        ->and($result['moved'])->toHaveCount(2)
        ->and(Note::where('contact_id', $survivor->getKey())->count())->toBe(2);
});

it('bounds hydrated children before rejecting an oversized inventory', function () {
    [$survivor, $source] = transferPair();
    foreach (range(1, 8) as $number) {
        noteFor($source, "Child {$number}");
    }
    $loaded = 0;
    Note::retrieved(function () use (&$loaded): void {
        $loaded++;
    });

    try {
        expect(fn () => (new HasManyTransfer(2))->inventory(transferDefinition(), new CompleteHasMany('childNotes'), $source))
            ->toThrow(MergeTooLarge::class);
        expect($loaded)->toBe(3);
    } finally {
        Note::flushEventListeners();
    }
});

/**
 * @return array{0: Contact, 1: Contact}
 */
function transferPair(): array
{
    return [
        Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Survivor']),
        Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Source']),
    ];
}

function noteFor(Contact $contact, string $body): Note
{
    return Note::create(['contact_id' => $contact->getKey(), 'body' => $body]);
}

function transferDefinition(): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-relations',
        'model' => Contact::class,
        'relations' => [new CompleteHasMany('childNotes')],
    ]);
}
