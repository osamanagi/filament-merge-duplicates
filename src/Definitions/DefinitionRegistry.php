<?php

namespace Nagi\FilamentMergeDuplicates\Definitions;

use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition as DuplicateDefinitionContract;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * The resolved set of definitions the package may operate on.
 *
 * Definitions are referenced by stable ID everywhere else — panels, jobs, the
 * CLI and the UI — so no call site ever handles a model class or a closure.
 * Definitions are resolved through the container, which means a queued worker
 * can resolve one without loading panel middleware, and no closure is ever
 * serialised into a job.
 */
final class DefinitionRegistry
{
    /**
     * @var array<string, class-string<DuplicateDefinitionContract>|callable(): DuplicateDefinitionContract>
     */
    private array $sources = [];

    /**
     * @var array<string, DuplicateDefinitionContract>
     */
    private array $resolved = [];

    /**
     * @param  class-string<DuplicateDefinitionContract>|callable(): DuplicateDefinitionContract|DuplicateDefinitionContract  $definition
     *
     * @throws InvalidConfiguration
     */
    public function register(DuplicateDefinitionContract | string | callable $definition): void
    {
        $instance = $this->instantiate($definition);
        $id = $instance->id();

        if ($id === '') {
            throw InvalidConfiguration::for('<unknown>', 'a definition must declare a non-empty ID');
        }

        if (array_key_exists($id, $this->sources)) {
            throw InvalidConfiguration::for(
                $id,
                'the definition ID is already registered. IDs must be unique so that scans, dismissals and the retirement ledger cannot be confused.',
            );
        }

        if ($definition instanceof DuplicateDefinitionContract) {
            $this->sources[$id] = static fn (): DuplicateDefinitionContract => $definition;
        } elseif (is_string($definition)) {
            $this->sources[$id] = $definition;
        } else {
            $this->sources[$id] = $definition;
        }

        $this->resolved[$id] = $instance;
    }

    /**
     * @param  iterable<int, class-string<DuplicateDefinitionContract>|callable(): DuplicateDefinitionContract|DuplicateDefinitionContract>  $definitions
     *
     * @throws InvalidConfiguration
     */
    public function registerMany(iterable $definitions): void
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->sources);
    }

    /**
     * @throws InvalidConfiguration when the ID is not registered
     */
    public function get(string $id): DuplicateDefinitionContract
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (! $this->has($id)) {
            throw InvalidConfiguration::for($id, 'no definition is registered under this ID');
        }

        return $this->resolved[$id] = $this->instantiate($this->sources[$id]);
    }

    /**
     * @return array<string, DuplicateDefinitionContract>
     */
    public function all(): array
    {
        $definitions = [];

        foreach (array_keys($this->sources) as $id) {
            $definitions[$id] = $this->get($id);
        }

        return $definitions;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->sources);
    }

    /**
     * Removes every registration. Intended for tests.
     */
    public function flush(): void
    {
        $this->sources = [];
        $this->resolved = [];
    }

    private function instantiate(DuplicateDefinitionContract | string | callable $definition): DuplicateDefinitionContract
    {
        $instance = match (true) {
            $definition instanceof DuplicateDefinitionContract => $definition,
            is_string($definition) => app($definition),
            default => $definition(),
        };

        if (! $instance instanceof DuplicateDefinitionContract) {
            throw InvalidConfiguration::for(
                '<unknown>',
                'a registered definition must implement the DuplicateDefinition contract',
            );
        }

        return $instance;
    }
}
