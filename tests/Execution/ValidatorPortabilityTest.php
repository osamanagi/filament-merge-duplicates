<?php

use Nagi\FilamentMergeDuplicates\Definitions\DefinitionValidator;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\SoftDeleteRetirementStrategy;

/*
|--------------------------------------------------------------------------
| C02 - configuration checks read the schema, and schemas differ
|--------------------------------------------------------------------------
|
| A field's column type is what the database says, not what the model casts, and
| engines disagree about it: SQLite stores a json column as text, MySQL and
| PostgreSQL have a real json type. The check is therefore proven on the engines
| that can express it, rather than asserted against a portable stand-in.
*/

it('refuses a field stored in a json column', function (string $engine) {
    $this->bootEngine($engine);

    $definition = new ConfigurableDefinition([
        'id' => 'fixture-contacts',
        'model' => Contact::class,
        'scopeKeys' => ['tenant_id'],
        'acknowledgesCompleteReferenceInventory' => true,
        'validator' => new PassThroughMergeValidator,
        'retirementStrategy' => new SoftDeleteRetirementStrategy,
        'writerGuard' => new RecordingWriterGuard,
        'matchingRules' => [ExactRule::make('reference')->fields(['reference'])],
        'fields' => [MergeField::make('payload')],
    ]);

    $report = (new DefinitionValidator)->validate($definition);

    expect(implode(' ', array_map(
        static fn ($issue): string => $issue->message,
        $report->blockers(),
    )))->toContain('unsupported column type')
        ->and($report->hasBlockers())->toBeTrue();
})->with('engines');
