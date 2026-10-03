<?php

namespace Nagi\FilamentMergeDuplicates\Definitions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition as DuplicateDefinitionContract;
use Nagi\FilamentMergeDuplicates\Data\ConfigurationIssue;
use Nagi\FilamentMergeDuplicates\Data\ConfigurationReport;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\UnsupportedRelation;
use Nagi\FilamentMergeDuplicates\Relations\RelationType;
use Throwable;

/**
 * Inspects a definition and reports every problem at once.
 *
 * Detection and merge are graded separately, so an unsupported relation or a
 * missing writer guard leaves detection working and merge blocked with an
 * explanation instead of failing generically. Configuration errors are
 * collected, never thrown one at a time, because a developer fixing a
 * definition should see the whole list.
 */
final class DefinitionValidator
{
    private const ID_PATTERN = '/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/';

    /**
     * Cast fragments that cannot participate in a lossless scalar comparison.
     */
    private const BLOCKED_CAST_FRAGMENTS = [
        'json',
        'array',
        'object',
        'collection',
        'encrypted',
        'hashed',
        'float',
        'double',
        'real',
    ];

    private const BLOCKED_COLUMN_TYPES = ['json', 'jsonb'];

    public function validate(DuplicateDefinitionContract $definition): ConfigurationReport
    {
        $detectionIssues = [];

        try {
            $id = $definition->id();
        } catch (Throwable $exception) {
            return new ConfigurationReport('<unknown>', false, false, [
                ConfigurationIssue::blocker(
                    (new InvalidConfiguration($exception->getMessage()))->errorCode(),
                    'The definition ID could not be read: ' . $exception->getMessage(),
                    'id',
                ),
            ]);
        }

        if ($id === '' || preg_match(self::ID_PATTERN, $id) !== 1) {
            $detectionIssues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                "The definition ID [{$id}] must be a non-empty lowercase identifier using letters, digits, dots, dashes or underscores.",
                'id',
            );
        }

        $this->checkModel($definition, $detectionIssues);

        // Only an unusable model stops the remaining checks: everything else is
        // collected so a developer sees the whole list in one pass.
        if (! $this->modelIsUsable($definition)) {
            return new ConfigurationReport($id, false, false, $detectionIssues);
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = $this->read($definition, 'model');
        $model = new $modelClass;

        $this->checkIdentity($definition, $detectionIssues);
        $this->checkRules($definition, $model, $detectionIssues);
        $this->checkFields($definition, $model, $detectionIssues);

        $detectionCapable = ! $this->hasBlocker($detectionIssues);

        if (! $detectionCapable) {
            return new ConfigurationReport($id, false, false, $detectionIssues);
        }

        $mergeIssues = [];
        $this->checkRelations($definition, $model, $mergeIssues);
        $this->checkMergeCapability($definition, $model, $mergeIssues);
        $this->checkConnection($definition, $model, $mergeIssues);

        return new ConfigurationReport(
            $id,
            true,
            ! $this->hasBlocker($mergeIssues),
            [...$detectionIssues, ...$mergeIssues],
        );
    }

    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    private function checkModel(DuplicateDefinitionContract $definition, array &$issues): void
    {
        $model = null;

        try {
            $model = $definition->model();
        } catch (Throwable $exception) {
            $issues[] = ConfigurationIssue::blocker('invalid_configuration', $exception->getMessage(), 'model');

            return;
        }

        if ($model === '' || ! class_exists($model)) {
            $issues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                'The declared model class does not exist.',
                'model',
            );

            return;
        }

        if (! is_subclass_of($model, Model::class)) {
            $issues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                "The declared model [{$model}] is not an Eloquent model.",
                'model',
            );

            return;
        }

        try {
            RecordId::assertSupportedKey(new $model);
        } catch (InvalidConfiguration $exception) {
            $issues[] = ConfigurationIssue::blocker($exception->errorCode(), $exception->getMessage(), 'model');
        }
    }

    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    private function checkIdentity(DuplicateDefinitionContract $definition, array &$issues): void
    {
        foreach (['revision' => 'revision', 'label' => 'label', 'ownershipDomain' => 'ownershipDomain'] as $method => $path) {
            $value = $this->read($definition, $method);

            if (! is_string($value) || trim($value) === '') {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The definition must declare a non-empty [{$method}].",
                    $path,
                );
            }
        }
    }

    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    private function checkRules(DuplicateDefinitionContract $definition, Model $model, array &$issues): void
    {
        $rules = $this->read($definition, 'matchingRules');

        if (! is_array($rules) || $rules === []) {
            $issues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                'A definition needs at least one matching rule before detection can run.',
                'matchingRules',
            );

            return;
        }

        $seen = [];

        foreach ($rules as $rule) {
            $ruleId = $this->read($rule, 'id');

            if (! is_string($ruleId) || $ruleId === '') {
                $issues[] = ConfigurationIssue::blocker('invalid_configuration', 'Every matching rule needs a non-empty ID.', 'matchingRules');

                continue;
            }

            if (isset($seen[$ruleId])) {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The matching rule ID [{$ruleId}] is declared more than once.",
                    'matchingRules',
                );

                continue;
            }

            $seen[$ruleId] = true;

            $fields = $this->read($rule, 'fieldNames');

            if (! is_array($fields) || $fields === []) {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The matching rule [{$ruleId}] declares no fields.",
                    "matchingRules.{$ruleId}",
                );

                continue;
            }

            foreach ($fields as $field) {
                if (! is_string($field) || $field === '') {
                    $issues[] = ConfigurationIssue::blocker('invalid_configuration', "The matching rule [{$ruleId}] declares an invalid field name.", "matchingRules.{$ruleId}");

                    continue;
                }

                if (! $this->columnExists($model, $field)) {
                    $issues[] = ConfigurationIssue::blocker(
                        'invalid_configuration',
                        "The matching rule [{$ruleId}] reads [{$field}], which is not a column on [{$model->getTable()}].",
                        "matchingRules.{$ruleId}",
                    );
                }
            }

            try {
                $rule->signature();
            } catch (Throwable $exception) {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The matching rule [{$ruleId}] cannot be signed: {$exception->getMessage()}",
                    "matchingRules.{$ruleId}",
                );
            }
        }
    }

    /**
     * A merge field must read something. A real column is the normal case; an
     * accessor or a cast is accepted because the field is then still readable
     * and comparable rather than permanently null.
     */
    private function fieldResolvesToAValue(Model $model, string $name): bool
    {
        return Schema::hasColumn($model->getTable(), $name)
            || $model->hasGetMutator($name)
            || $model->hasCast($name);
    }

    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    private function checkFields(DuplicateDefinitionContract $definition, Model $model, array &$issues): void
    {
        $fields = $this->read($definition, 'fields');

        if (! is_array($fields)) {
            $issues[] = ConfigurationIssue::blocker('invalid_configuration', 'The field allowlist must be an array.', 'fields');

            return;
        }

        $forbidden = $this->forbiddenFieldNames($definition, $model);

        // Eloquent builds its cast map around one key name, so a model with a
        // composite or unnamed key cannot be inspected at all. The identity check
        // has already reported that as the problem, so the field checks stop here
        // instead of turning a clear blocker into a runtime error.
        $keyName = $model->getKeyName();

        if (! is_string($keyName) || $keyName === '') {
            return;
        }

        $casts = $model->getCasts();
        $seen = [];

        foreach ($fields as $field) {
            $name = $this->read($field, 'name');

            if (! is_string($name) || $name === '') {
                $issues[] = ConfigurationIssue::blocker('invalid_configuration', 'Every merge field needs a non-empty name.', 'fields');

                continue;
            }

            if (isset($seen[$name])) {
                $issues[] = ConfigurationIssue::blocker('invalid_configuration', "The merge field [{$name}] is declared more than once.", 'fields');

                continue;
            }

            $seen[$name] = true;

            if (in_array($name, $forbidden, true)) {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The field [{$name}] may not be merged generically: it is a key, scope, timestamp, soft-delete, credential or relationship column.",
                    "fields.{$name}",
                );

                continue;
            }

            // Eloquent resolves a missing attribute through a same-named method,
            // so a field that shadows a method would silently read a relation or
            // an accessor instead of the column.
            if (method_exists($model, $name)) {
                $shadowedModel = $model::class;

                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The field [{$name}] shadows a method on [{$shadowedModel}], so reading it would not return the column.",
                    "fields.{$name}",
                );

                continue;
            }

            $cast = $casts[$name] ?? null;

            if (! $this->fieldResolvesToAValue($model, $name)) {
                // A field that resolves to nothing would be read as null on every
                // record, so a typo would silently drop it from the merge instead
                // of failing.
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The field [{$name}] is not a column on [{$model->getTable()}] and the model exposes no accessor for it, so the merge would silently ignore it.",
                    "fields.{$name}",
                );

                continue;
            }

            if (is_string($cast) && $this->isUnsupportedCast($cast)) {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The field [{$name}] uses the unsupported cast [{$cast}] and cannot be compared or chosen losslessly.",
                    "fields.{$name}",
                );

                continue;
            }

            if ($this->blockedColumnType($model, $name)) {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The field [{$name}] uses an unsupported column type and cannot be merged unless a future adapter exists.",
                    "fields.{$name}",
                );
            }
        }
    }

    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    private function checkRelations(DuplicateDefinitionContract $definition, Model $model, array &$issues): void
    {
        $relations = $this->read($definition, 'relations');

        if (! is_array($relations)) {
            $issues[] = ConfigurationIssue::blocker('invalid_configuration', 'The relation list must be an array.', 'relations');

            return;
        }

        $seen = [];

        foreach ($relations as $relation) {
            $name = $this->read($relation, 'name');
            $type = $this->read($relation, 'type');

            if (! is_string($name) || $name === '') {
                $issues[] = ConfigurationIssue::blocker('invalid_configuration', 'Every relation strategy needs a non-empty name.', 'relations');

                continue;
            }

            if (isset($seen[$name])) {
                $issues[] = ConfigurationIssue::blocker('invalid_configuration', "The relation [{$name}] is declared more than once.", "relations.{$name}");

                continue;
            }

            $seen[$name] = true;

            if (! $type instanceof RelationType) {
                $issues[] = ConfigurationIssue::blocker('invalid_configuration', "The relation [{$name}] declares an unknown relation type.", "relations.{$name}");

                continue;
            }

            if (! $type->isSupportedInV1()) {
                $issues[] = ConfigurationIssue::blocker(
                    (new UnsupportedRelation("{$name}"))->errorCode(),
                    "The relation [{$name}] is a {$type->value} relation, which v1 cannot transfer. The merge is blocked rather than guessed.",
                    "relations.{$name}",
                );

                continue;
            }

            if (! method_exists($model, $name)) {
                $modelClass = $model::class;

                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The declared relation [{$name}] does not exist on [{$modelClass}].",
                    "relations.{$name}",
                );

                continue;
            }

            if (! $relation->ownsCompleteInventory()) {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The relation [{$name}] does not declare complete foreign-key coverage. A filtered relation cannot prove that every child is accounted for.",
                    "relations.{$name}",
                );

                continue;
            }

            try {
                $resolved = $model->{$name}();

                if (! $resolved instanceof Relation) {
                    $issues[] = ConfigurationIssue::blocker('invalid_configuration', "The declared relation [{$name}] is not an Eloquent relation.", "relations.{$name}");

                    continue;
                }

                $relatedConnection = $resolved->getRelated()->getConnectionName()
                    ?? (string) config('database.default');

                if ($relatedConnection !== $definition->connection()) {
                    $relatedClass = $resolved->getRelated()::class;

                    $issues[] = ConfigurationIssue::blocker(
                        'invalid_configuration',
                        "The relation [{$name}] targets [{$relatedClass}] on connection [{$relatedConnection}] while the definition runs on [{$definition->connection()}]. Cross-connection merges are unsupported, because the child writes could not be rolled back with the rest of the merge.",
                        "relations.{$name}",
                    );
                }
            } catch (Throwable $exception) {
                $issues[] = ConfigurationIssue::blocker(
                    'invalid_configuration',
                    "The declared relation [{$name}] could not be resolved: {$exception->getMessage()}",
                    "relations.{$name}",
                );
            }
        }
    }

    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    private function checkMergeCapability(DuplicateDefinitionContract $definition, Model $model, array &$issues): void
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $issues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                'Merge requires the model to use soft deletes, because the source is retired rather than deleted. Detection stays available.',
                'model',
            );
        }

        if (! $definition->acknowledgesCompleteReferenceInventory()) {
            $issues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                'The definition must acknowledge that its inbound reference inventory is complete and tested before merging is enabled.',
                'relations',
            );
        }

        if ($definition->validator() === null) {
            $issues[] = ConfigurationIssue::blocker('invalid_configuration', 'Merge requires a validator; the plugin never reuses form validation implicitly.', 'validator');
        }

        if ($definition->retirementStrategy() === null) {
            $issues[] = ConfigurationIssue::blocker('invalid_configuration', 'Merge requires an explicit retirement strategy.', 'retirementStrategy');
        } elseif ($definition->retirementStrategy()->requiresSoftDeletes() && ! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $issues[] = ConfigurationIssue::blocker('invalid_configuration', 'The configured retirement strategy requires soft deletes on the model.', 'retirementStrategy');
        }

        if ($definition->writerGuard() === null) {
            $issues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                'Merge requires a writer guard so concurrent host writers cannot attach children to a retired source.',
                'writerGuard',
            );
        }
    }

    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    private function checkConnection(DuplicateDefinitionContract $definition, Model $model, array &$issues): void
    {
        try {
            $connection = $definition->connection();
        } catch (Throwable $exception) {
            $issues[] = ConfigurationIssue::blocker('invalid_configuration', $exception->getMessage(), 'connection');

            return;
        }

        if ($connection !== ($model->getConnectionName() ?? (string) config('database.default'))) {
            $issues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                "The definition connection [{$connection}] does not match the model connection. Cross-connection merges are unsupported.",
                'connection',
            );
        }

        try {
            $driver = DB::connection($connection)->getDriverName();
        } catch (Throwable $exception) {
            $issues[] = ConfigurationIssue::blocker(
                'invalid_configuration',
                "The connection [{$connection}] could not be resolved: {$exception->getMessage()}",
                'connection',
            );

            return;
        }

        $supported = (array) config('merge-duplicates.supported_merge_drivers', ['mysql', 'pgsql']);

        if (! in_array($driver, $supported, true)) {
            // A configuration is still valid: whether the current engine can
            // execute a merge is an environment property, not a definition
            // error. Execution refuses unsupported engines rather than implying
            // equivalent row-lock guarantees.
            $issues[] = ConfigurationIssue::warning(
                'unsupported_engine',
                "The [{$driver}] driver is not a supported merge engine. Detection still works, but merging is refused on this connection.",
                'connection',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function forbiddenFieldNames(DuplicateDefinitionContract $definition, Model $model): array
    {
        $forbidden = array_values(array_unique(array_map(
            static fn(mixed $name): string => (string) $name,
            (array) config('merge-duplicates.forbidden_field_names', []),
        )));

        // A model with a composite or unnamed key is refused elsewhere; the key
        // name is skipped here rather than turned into a string, because turning
        // an array into a string would fail the whole validation instead of
        // reporting the one problem the definition has.
        $keyName = $model->getKeyName();

        if (is_string($keyName) && $keyName !== '') {
            $forbidden[] = $keyName;
        }

        foreach ([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()] as $column) {
            if (is_string($column) && $column !== '') {
                $forbidden[] = $column;
            }
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'getDeletedAtColumn')) {
            $forbidden[] = $model->getDeletedAtColumn();
        }

        // Scope keys are declared by the definition, because their names are
        // application specific. They must never be part of a generic scalar
        // merge.
        foreach ((array) $this->read($definition, 'scopeKeys') as $scopeKey) {
            if (is_string($scopeKey) && $scopeKey !== '') {
                $forbidden[] = $scopeKey;
            }
        }

        foreach ((array) $this->read($definition, 'relations') as $relation) {
            $name = $this->read($relation, 'name');

            if (! is_string($name) || ! method_exists($model, $name)) {
                continue;
            }

            try {
                $resolved = $model->{$name}();

                if ($resolved instanceof Relation && method_exists($resolved, 'getForeignKeyName')) {
                    $forbidden[] = $resolved->getForeignKeyName();
                }
            } catch (Throwable) {
                // A relation that cannot be resolved is already reported elsewhere.
            }
        }

        return array_values(array_unique($forbidden));
    }

    private function isUnsupportedCast(string $cast): bool
    {
        $cast = strtolower($cast);

        if (enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            return false;
        }

        foreach (self::BLOCKED_CAST_FRAGMENTS as $fragment) {
            if (str_contains($cast, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function blockedColumnType(Model $model, string $field): bool
    {
        if (! $this->tableExists($model)) {
            return false;
        }

        try {
            $columns = $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        } catch (Throwable) {
            return false;
        }

        foreach ($columns as $column) {
            if (($column['name'] ?? null) !== $field) {
                continue;
            }

            $type = strtolower((string) ($column['type'] ?? ''));

            foreach (self::BLOCKED_COLUMN_TYPES as $blocked) {
                if (str_contains($type, $blocked)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function tableExists(Model $model): bool
    {
        try {
            return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the declared model can be used for model-derived checks.
     */
    private function modelIsUsable(DuplicateDefinitionContract $definition): bool
    {
        $model = $this->read($definition, 'model');

        return is_string($model) && class_exists($model) && is_subclass_of($model, Model::class);
    }

    private function columnExists(Model $model, string $field): bool
    {
        if ($field === $model->getKeyName()) {
            return true;
        }

        if (! $this->tableExists($model)) {
            return true;
        }

        try {
            return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $field);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    private function hasBlocker(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isBlocker()) {
                return true;
            }
        }

        return false;
    }

    private function read(mixed $subject, string $member): mixed
    {
        if (is_array($subject)) {
            return $subject[$member] ?? null;
        }

        if (is_object($subject) && method_exists($subject, $member)) {
            try {
                return $subject->{$member}();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
