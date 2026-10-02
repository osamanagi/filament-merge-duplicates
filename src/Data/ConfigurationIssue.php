<?php

namespace Nagi\FilamentMergeDuplicates\Data;

/**
 * One actionable configuration problem.
 *
 * The code is stable and machine readable; the message is for developers and
 * never contains secrets or raw matched values.
 */
final class ConfigurationIssue
{
    public function __construct(
        public readonly IssueSeverity $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $path = null,
    ) {}

    public static function blocker(string $code, string $message, ?string $path = null): self
    {
        return new self(IssueSeverity::Blocker, $code, $message, $path);
    }

    public static function warning(string $code, string $message, ?string $path = null): self
    {
        return new self(IssueSeverity::Warning, $code, $message, $path);
    }

    public function isBlocker(): bool
    {
        return $this->severity === IssueSeverity::Blocker;
    }

    /**
     * @return array{severity: string, code: string, message: string, path: string|null}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
        ];
    }
}
