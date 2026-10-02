<?php

namespace Nagi\FilamentMergeDuplicates\Filament\Pages;

use Filament\Pages\Page;
use Filament\Panel;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Filament\Concerns\HasDuplicateSuggestions;
use Nagi\FilamentMergeDuplicates\Merging\AuditReader;

/**
 * One merge history entry.
 *
 * Reading history is its own ability, checked by AuditReader before anything is
 * decrypted, and the entry must belong to the acting scope. The payload holds
 * only declared audit fields and the moved child identifiers; this page renders
 * whatever it holds as text and escapes it, so a history entry can never become
 * a stored script.
 */
final class DuplicateAuditPage extends Page
{
    use HasDuplicateSuggestions;

    protected static ?string $slug = 'merge-duplicates/{definition}/audit/{operation}';

    protected string $view = 'filament-merge-duplicates::audit-page';

    public string $definition = '';

    public string $operation = '';

    /**
     * @var array<string, mixed>
     */
    public array $entries = [];

    public ?string $errorCode = null;

    public function mount(string $definition, string $operation): void
    {
        $this->definition = $definition;
        $this->operation = $operation;

        if (! in_array($definition, $this->panelDefinitionIds(), true)) {
            abort(404);
        }

        try {
            $this->duplicateContext();
        } catch (MissingContext) {
            abort(403);
        }

        try {
            $this->entries = app(AuditReader::class)->forOperation(
                $this->duplicateContext(),
                $this->duplicateDefinition(),
                $this->operation,
            );
        } catch (ForbiddenOperation) {
            abort(403);
        } catch (RecordUnavailable) {
            abort(404);
        } catch (DomainConflict $exception) {
            // The entry exists but cannot be read with the current key: that is
            // an explicit failure, not an empty history.
            $this->errorCode = $exception->errorCode();
        }
    }

    public function duplicateDefinitionId(): string
    {
        if ($this->definition === '') {
            throw InvalidConfiguration::for(
                '<unknown>',
                'The audit page was opened without a definition.',
            );
        }

        return $this->definition;
    }

    public static function urlForOperation(string $definitionId, string $operationId): string
    {
        return self::getUrl([
            'definition' => $definitionId,
            'operation' => $operationId,
        ]);
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'merge-duplicates.audit';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return (string) trans('filament-merge-duplicates::merge-duplicates.audit.title');
    }

    /**
     * A history value as plain text. Arrays and objects become JSON text; the
     * view escapes the result, so a stored value can never become markup.
     */
    public function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }
}
