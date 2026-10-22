<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Merging\MergePreviewService;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\PreviewRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PolicyPermissions;

afterEach(function () {
    Contact::flushEventListeners();
    Note::flushEventListeners();
    $this->resetEngines();
});

function mergeAuthorizationSnapshot(): array
{
    $snapshot = [];
    foreach (['fixture_contacts', 'fixture_notes', 'filament_merge_duplicates_merges'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $snapshot;
}

it('refuses a preview when any affected record or pair policy denies it', function (string $engine, string $denial) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition(['authorizer' => $permissions->authorizer()]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);
    $child = $this->addNote($source, 'child');
    match ($denial) {
        'survivor-view' => $permissions->deny('view', $survivor),
        'source-view' => $permissions->deny('view', $source),
        'survivor-update' => $permissions->deny('update', $survivor),
        'source-delete' => $permissions->deny('delete', $source),
        'pair' => $permissions->pair = false,
        'child-view' => $permissions->deny('view', $child),
        'child-update' => $permissions->deny('update', $child),
        'child-transfer' => $permissions->deny('transfer', $child),
        'target' => $permissions->targetId = 'not-this-survivor',
    };
    $before = mergeAuthorizationSnapshot();

    expect(fn () => $this->planFor($context, $definition, $survivor, $source))
        ->toThrow(ForbiddenOperation::class);
    expect(PreviewRecord::count())->toBe(0)
        ->and(mergeAuthorizationSnapshot())->toBe($before);
})->with('engines')->with(['survivor-view', 'source-view', 'survivor-update', 'source-delete', 'pair',
    'child-view', 'child-update', 'child-transfer', 'target']);

it('rechecks record permissions at execution after a valid preview', function (string $engine, string $denial) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition(['authorizer' => $permissions->authorizer()]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME', 'notes' => 'before']);
    $source = $this->makeContact(['reference' => 'SAME', 'notes' => 'selected']);
    $child = $this->addNote($source, 'child');
    $plan = $this->planFor($context, $definition, $survivor, $source);
    match ($denial) {
        'survivor-view' => $permissions->deny('view', $survivor),
        'source-view' => $permissions->deny('view', $source),
        'survivor-update' => $permissions->deny('update', $survivor),
        'source-delete' => $permissions->deny('delete', $source),
        'pair' => $permissions->pair = false,
        'child-view' => $permissions->deny('view', $child),
        'child-update' => $permissions->deny('update', $child),
        'child-transfer' => $permissions->deny('transfer', $child),
    };
    $before = mergeAuthorizationSnapshot();

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['notes' => 'source']))
        ->toThrow(ForbiddenOperation::class);
    expect(mergeAuthorizationSnapshot())->toBe($before);
})->with('engines')->with(['survivor-view', 'source-view', 'survivor-update', 'source-delete', 'pair',
    'child-view', 'child-update', 'child-transfer']);

it('uses the selected survivor and source roles when rebuilding a preview', function (string $engine) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition(['authorizer' => $permissions->authorizer()]);
    $context = $this->contextFor($definition);
    $first = $this->makeContact(['reference' => 'SAME']);
    $second = $this->makeContact(['reference' => 'SAME']);
    $permissions->deny('update', $second);
    $permissions->deny('delete', $first);
    $service = app(MergePreviewService::class);
    $valid = $service->preview($definition, $context, (string) $first->id, (string) $second->id, (string) $first->id);

    expect($valid->survivorId->value)->toBe((string) $first->id)
        ->and(fn () => $service->preview($definition, $context, (string) $first->id, (string) $second->id, (string) $second->id))
        ->toThrow(ForbiddenOperation::class);
    expect(PreviewRecord::count())->toBe(1);
})->with('engines');

it('rolls back the whole merge if a later child loses transfer permission', function (string $engine) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition(['authorizer' => $permissions->authorizer()]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME', 'notes' => 'before']);
    $source = $this->makeContact(['reference' => 'SAME', 'notes' => 'selected']);
    $first = $this->addNote($source, 'first');
    $second = $this->addNote($source, 'second');
    $plan = $this->planFor($context, $definition, $survivor, $source);
    $before = mergeAuthorizationSnapshot();
    Note::saved(function (Note $saved) use ($first, $second, $permissions): void {
        if ($saved->getKey() === $first->getKey()) {
            $permissions->deny('transfer', $second);
        }
    });

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['notes' => 'source']))
        ->toThrow(ForbiddenOperation::class);
    expect(mergeAuthorizationSnapshot())->toBe($before);
})->with('engines');

it('rechecks retirement permission after host observers run', function (string $engine) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition(['authorizer' => $permissions->authorizer()]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);
    $this->addNote($source, 'child');
    $plan = $this->planFor($context, $definition, $survivor, $source);
    $before = mergeAuthorizationSnapshot();
    Note::saved(fn () => $permissions->deny('delete', $source));

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(ForbiddenOperation::class);
    expect(mergeAuthorizationSnapshot())->toBe($before);
})->with('engines');

it('requires current record view permission to read an idempotent replay', function (string $engine) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition(['authorizer' => $permissions->authorizer()]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);
    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId);
    $permissions->deny('delete', $source);
    $permissions->deny('update', $survivor);

    expect(app(MergeExecutor::class)->execute($context, $definition, $plan->operationId)->replayed)->toBeTrue();
    $permissions->deny('view', $source);
    $before = mergeAuthorizationSnapshot();
    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))->toThrow(ForbiddenOperation::class);
    expect(mergeAuthorizationSnapshot())->toBe($before)->and(MergeRecord::count())->toBe(1);
})->with('engines');

it('checks soft-deleted children when their transfer is declared', function (string $engine) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition([
        'authorizer' => $permissions->authorizer(),
        'relations' => [new CompleteHasMany('childNotes', includesSoftDeletedChildren: true)],
    ]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);
    $child = $this->addNote($source, 'trashed');
    $child->delete();
    $permissions->deny('transfer', $child);

    expect(fn () => $this->planFor($context, $definition, $survivor, $source))->toThrow(ForbiddenOperation::class);
    expect($child->fresh()->contact_id)->toBe($source->getKey())->and(PreviewRecord::count())->toBe(0);
})->with('engines');

it('locks affected children before the first model write', function (string $engine) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition(['authorizer' => $permissions->authorizer()]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME', 'notes' => 'before']);
    $source = $this->makeContact(['reference' => 'SAME', 'notes' => 'selected']);
    $child = $this->addNote($source, 'child');
    $plan = $this->planFor($context, $definition, $survivor, $source);
    $blocked = false;
    Contact::saving(function () use ($engine, $child, &$blocked): void {
        $probe = DB::connection("{$engine}_probe");
        $probe->beginTransaction();

        try {
            $probe->table('fixture_notes')->where('id', $child->id)->lockForUpdate()->first();
        } catch (QueryException) {
            $blocked = true;
        } finally {
            $probe->rollBack();
        }
    });

    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['notes' => 'source']);
    expect($blocked)->toBeTrue()
        ->and($child->fresh()->contact_id)->toBe($survivor->getKey())
        ->and($source->fresh()->trashed())->toBeTrue();
})->with('engines');

it('does not disclose replay data when a parent has disappeared from the scope', function (string $engine) {
    $this->bootEngine($engine);
    $permissions = new PolicyPermissions;
    $definition = $this->makeDefinition(['authorizer' => $permissions->authorizer()]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);
    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId);
    DB::table('fixture_contacts')->where('id', $survivor->id)->update(['tenant_id' => 'tenant-b']);
    $before = mergeAuthorizationSnapshot();

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(ForbiddenOperation::class);
    expect(mergeAuthorizationSnapshot())->toBe($before);
})->with('engines');
