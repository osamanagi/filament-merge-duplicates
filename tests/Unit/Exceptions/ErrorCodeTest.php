<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Exceptions;

use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeDuplicatesException;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeTooLarge;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Exceptions\RetryExhausted;
use Nagi\FilamentMergeDuplicates\Exceptions\StalePreview;
use Nagi\FilamentMergeDuplicates\Exceptions\UnsupportedRelation;

/**
 * The error code is the only part of a failure that is ever persisted, shown in a
 * page or put in a notification, so the codes are part of the public contract:
 * docs/errors.md documents them, a host may match on them, and renaming one is a
 * breaking change for whoever reacts to it.
 */
dataset('error codes', [
    [ForbiddenOperation::class, 'forbidden_operation'],
    [MissingContext::class, 'missing_context'],
    [RecordUnavailable::class, 'record_unavailable'],
    [RetryExhausted::class, 'retry_exhausted'],
    [StalePreview::class, 'stale_preview'],
    [DomainConflict::class, 'domain_conflict'],
    [InvalidConfiguration::class, 'invalid_configuration'],
    [MergeTooLarge::class, 'merge_too_large'],
    [UnsupportedRelation::class, 'unsupported_relation'],
]);

it('exposes a stable error code for every failure', function (string $class, string $code) {
    $exception = new $class('Something went wrong.');

    expect($exception)->toBeInstanceOf(MergeDuplicatesException::class)
        ->and($exception->errorCode())->toBe($code)
        ->and($exception->getMessage())->toBe('Something went wrong.');
})->with('error codes');

it('names the definition and the reason for a configuration failure', function () {
    $exception = InvalidConfiguration::for('shop-customers', 'the model is not soft-deletable');

    expect($exception->errorCode())->toBe('invalid_configuration')
        ->and($exception->getMessage())->toContain('shop-customers')
        ->and($exception->getMessage())->toContain('the model is not soft-deletable');
});

it('names the offending relation for an unsupported one', function () {
    expect((new UnsupportedRelation('comments'))->getMessage())->toContain('comments');
});

it('names the relation for a merge that is too large', function () {
    expect((new MergeTooLarge('order_lines'))->getMessage())->toContain('order_lines');
});
