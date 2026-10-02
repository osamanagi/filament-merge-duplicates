<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Scanning;

use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Scanning\DirectPairMatcher;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * A manual pair merge must be backed by a real configured match, not by a
 * developer's assertion, so the matcher is checked directly.
 */
function matcherDefinition(): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-matcher',
        'model' => Contact::class,
        'matchingRules' => [ExactRule::make('reference')->fields(['reference'])],
        'fields' => [MergeField::make('reference')],
    ]);
}

it('matches two records that share a rule digest', function () {
    $first = new Contact(['reference' => 'SAME']);
    $second = new Contact(['reference' => 'SAME']);

    expect(app(DirectPairMatcher::class)->matches(matcherDefinition(), $first, $second))->toBeTrue();
});

it('does not match records whose rule values differ', function () {
    $first = new Contact(['reference' => 'SAME']);
    $second = new Contact(['reference' => 'DIFFERENT']);

    expect(app(DirectPairMatcher::class)->matches(matcherDefinition(), $first, $second))->toBeFalse();
});

it('never matches two records that both produce no key', function () {
    $first = new Contact(['reference' => null]);
    $second = new Contact(['reference' => '   ']);

    expect(app(DirectPairMatcher::class)->matches(matcherDefinition(), $first, $second))->toBeFalse();
});
