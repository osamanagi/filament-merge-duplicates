<?php

namespace Nagi\FilamentMergeDuplicates\Data;

use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * The outcome of validating one definition.
 *
 * Detection and merge are graded separately: a definition may be perfectly
 * usable for detection while being blocked for merge, which is how the package
 * keeps unsupported cases visible instead of failing generically. All blockers
 * are collected before anything is thrown so a developer sees every problem at
 * once.
 */
final class ConfigurationReport
{
    /**
     * @param  list<ConfigurationIssue>  $issues
     */
    public function __construct(
        public readonly string $definitionId,
        public readonly bool $detectionCapable,
        public readonly bool $mergeCapable,
        public readonly array $issues,
    ) {}

    /**
     * @return list<ConfigurationIssue>
     */
    public function blockers(): array
    {
        return array_values(array_filter($this->issues, static fn (ConfigurationIssue $issue): bool => $issue->isBlocker()));
    }

    /**
     * @return list<ConfigurationIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ConfigurationIssue $issue): bool => ! $issue->isBlocker()));
    }

    public function hasBlockers(): bool
    {
        return $this->blockers() !== [];
    }

    /**
     * @throws InvalidConfiguration
     */
    public function throwIfInvalid(): void
    {
        $blockers = $this->blockers();

        if ($blockers === []) {
            return;
        }

        $summary = implode('; ', array_map(
            static fn (ConfigurationIssue $issue): string => $issue->message,
            $blockers,
        ));

        throw InvalidConfiguration::for($this->definitionId, $summary);
    }

    /**
     * @return array{definition_id: string, detection_capable: bool, merge_capable: bool, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'detection_capable' => $this->detectionCapable,
            'merge_capable' => $this->mergeCapable,
            'issues' => array_map(static fn (ConfigurationIssue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
