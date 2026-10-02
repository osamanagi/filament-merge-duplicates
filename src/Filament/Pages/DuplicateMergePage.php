<?php

namespace Nagi\FilamentMergeDuplicates\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeDuplicatesException;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Exceptions\StalePreview;
use Nagi\FilamentMergeDuplicates\Filament\Concerns\HasDuplicateSuggestions;
use Nagi\FilamentMergeDuplicates\Merging\FieldDifference;
use Nagi\FilamentMergeDuplicates\Merging\FieldResolution;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Merging\MergePreviewService;
use Nagi\FilamentMergeDuplicates\Merging\RelationImpact;
use Nagi\FilamentMergeDuplicates\Scanning\DismissalService;

/**
 * The pair comparison and confirmation surface.
 *
 * Every value shown here comes from a server-side plan. The two records are
 * re-read through the definition's scope and visibility rules, the plan carries
 * a fingerprint of the inputs, and confirmation re-runs the whole validation
 * inside the executor's transaction. The browser is trusted for exactly two
 * things: which record the operator wants to keep, and a per-field choice - both
 * of which are validated against the plan's allowlist.
 *
 * A rebuilt plan always gets a new operation identifier, so changing the
 * survivor or refreshing a stale preview invalidates the previous token rather
 * than reusing it.
 */
final class DuplicateMergePage extends Page
{
    use HasDuplicateSuggestions;

    protected static ?string $slug = 'merge-duplicates/{definition}/compare/{first}/{second}';

    protected string $view = 'filament-merge-duplicates::merge-page';

    public string $definition = '';

    public string $first = '';

    public string $second = '';

    /**
     * The opaque identifier of the current plan. Replaced whenever the plan is
     * rebuilt, which is what makes an older operator click a no-op.
     */
    public string $operationId = '';

    public string $survivorValue = '';

    public string $sourceValue = '';

    public string $survivorTitle = '';

    public string $sourceTitle = '';

    public string $survivorReason = '';

    /**
     * @var array<string, string> field name => 'survivor' or 'source'
     */
    public array $choices = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $differences = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $relations = [];

    /**
     * @var list<string>
     */
    public array $fatalBlockers = [];

    /**
     * @var list<string>
     */
    public array $choiceFields = [];

    /**
     * @var list<string>
     */
    public array $matchReasons = [];

    public bool $merged = false;

    public string $mergedOperationId = '';

    public string $mergedSurvivorTitle = '';

    public ?string $configurationError = null;

    public function mount(string $definition, string $first, string $second): void
    {
        $this->definition = $definition;
        $this->first = $first;
        $this->second = $second;

        if (! in_array($definition, $this->panelDefinitionIds(), true)) {
            abort(404);
        }

        try {
            $this->duplicateContext();
        } catch (MissingContext) {
            abort(403);
        }

        if (! $this->canMergeDuplicates()) {
            abort(403);
        }

        $this->rebuildPreview();
    }

    public function duplicateDefinitionId(): string
    {
        if ($this->definition === '') {
            throw InvalidConfiguration::for(
                '<unknown>',
                'The merge page was opened without a definition.',
            );
        }

        return $this->definition;
    }

    public static function urlForPair(string $definitionId, string $first, string $second): string
    {
        return self::getUrl([
            'definition' => $definitionId,
            'first' => $first,
            'second' => $second,
        ]);
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'merge-duplicates.compare';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return (string) trans('filament-merge-duplicates::merge-duplicates.merge.title');
    }

    /**
     * Choosing the other record rebuilds the plan, so the proposal and its
     * fingerprint describe the new arrangement and the old token is dead.
     */
    public function setSurvivor(string $value): void
    {
        if (! in_array($value, [$this->first, $this->second], true)) {
            return;
        }

        if ($value !== $this->survivorValue) {
            $this->rebuildPreview($value);
        }
    }

    /**
     * A field choice is only accepted for a field the plan actually asks about,
     * and only for one of the two records. Anything else is ignored rather than
     * trusted.
     */
    public function setChoice(string $field, string $choice): void
    {
        if (! in_array($field, $this->choiceFields, true)) {
            return;
        }

        if (! in_array($choice, ['survivor', 'source'], true)) {
            return;
        }

        $this->choices[$field] = $choice;
    }

    /**
     * Confirms the preview through the completed executor.
     *
     * Success is announced only after the executor returns a committed result.
     * A stale preview rebuilds the plan instead of retrying the old one, and any
     * failure is reported by its sanitized code: the page never claims a merge
     * happened when the database did not.
     */
    public function confirm(): void
    {
        if ($this->merged || $this->configurationError !== null) {
            return;
        }

        if (! $this->canConfirm()) {
            Notification::make()
                ->title((string) trans('filament-merge-duplicates::merge-duplicates.merge.choices_required'))
                ->warning()
                ->send();

            return;
        }

        try {
            $result = app(MergeExecutor::class)->execute(
                $this->duplicateContext(),
                $this->duplicateDefinition(),
                $this->operationId,
                $this->choices,
            );
        } catch (StalePreview) {
            Notification::make()
                ->title((string) trans('filament-merge-duplicates::merge-duplicates.merge.stale'))
                ->warning()
                ->send();

            $this->rebuildPreview($this->survivorValue);

            return;
        } catch (ForbiddenOperation) {
            abort(403);
        } catch (MergeDuplicatesException $exception) {
            Notification::make()
                ->title((string) trans('filament-merge-duplicates::merge-duplicates.merge.failed', [
                    'code' => $exception->errorCode(),
                ]))
                ->danger()
                ->send();

            return;
        }

        $this->merged = true;
        $this->mergedOperationId = $result->operationId;
        $this->mergedSurvivorTitle = $this->survivorTitle;

        Notification::make()
            ->title((string) trans('filament-merge-duplicates::merge-duplicates.merge.succeeded', [
                'title' => $this->survivorTitle,
            ]))
            ->success()
            ->send();
    }

    /**
     * "Not duplicates" dismisses this pair only. Overlapping buckets stay
     * visible, and dismissing is a separate ability from merging.
     */
    public function dismiss(): void
    {
        try {
            /** @var array{0: Model, 1: Model} $pair */
            $pair = app(MergePreviewService::class)->models(
                $this->duplicateDefinition(),
                $this->duplicateContext(),
                $this->first,
                $this->second,
            );

            app(DismissalService::class)->dismiss(
                $this->duplicateContext(),
                $this->duplicateDefinition(),
                $pair[0],
                $pair[1],
            );
        } catch (ForbiddenOperation) {
            abort(403);
        } catch (RecordUnavailable) {
            abort(404);
        }

        $this->redirect(DuplicateReviewPage::urlForDefinition($this->definition));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'definitionLabel' => $this->duplicateDefinition()->label(),
            'survivorValue' => $this->survivorValue,
            'sourceValue' => $this->sourceValue,
            'survivorTitle' => $this->survivorTitle,
            'sourceTitle' => $this->sourceTitle,
            'survivorReason' => $this->survivorReason,
            'differences' => $this->differences,
            'relations' => $this->relations,
            'fatalBlockers' => $this->fatalBlockers,
            'choiceFields' => $this->choiceFields,
            'matchReasons' => $this->matchReasons,
            'choices' => $this->choices,
            'canConfirm' => $this->canConfirm(),
            'canDismiss' => $this->canDismissDuplicates(),
            'merged' => $this->merged,
            'mergedOperationId' => $this->mergedOperationId,
            'mergedSurvivorTitle' => $this->mergedSurvivorTitle,
            'configurationError' => $this->configurationError,
            'retirementWarning' => (string) trans('filament-merge-duplicates::merge-duplicates.merge.retirement_warning', [
                'title' => $this->sourceTitle,
            ]),
            'reviewUrl' => DuplicateReviewPage::urlForDefinition($this->definition),
            'auditUrl' => $this->merged && $this->canViewDuplicateAudit()
                ? DuplicateAuditPage::urlForOperation($this->definition, $this->mergedOperationId)
                : null,
        ];
    }

    private function canConfirm(): bool
    {
        if ($this->merged || $this->configurationError !== null || $this->fatalBlockers !== []) {
            return false;
        }

        foreach ($this->choiceFields as $field) {
            $choice = $this->choices[$field] ?? null;

            if ($choice !== 'survivor' && $choice !== 'source') {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds a plan and stores its display shape. A rebuild always clears the
     * previous choices and token, so nothing from the old proposal can be
     * replayed against the new one.
     */
    private function rebuildPreview(?string $survivorValue = null): void
    {
        try {
            $plan = app(MergePreviewService::class)->preview(
                $this->duplicateDefinition(),
                $this->duplicateContext(),
                $this->first,
                $this->second,
                $survivorValue,
            );
        } catch (RecordUnavailable) {
            abort(404);
        } catch (ForbiddenOperation) {
            abort(403);
        } catch (InvalidConfiguration $exception) {
            $this->configurationError = $exception->errorCode();

            return;
        }

        $this->operationId = $plan->operationId;
        $this->survivorValue = $plan->survivorId->value;
        $this->sourceValue = $plan->sourceId->value;
        $this->survivorTitle = $plan->survivorTitle;
        $this->sourceTitle = $plan->sourceTitle;
        $this->survivorReason = $plan->survivorReason;
        $this->fatalBlockers = $plan->fatalBlockers();

        // A pair that no longer directly matches a configured rule is not a
        // mergeable suggestion, however the page was reached. It becomes a
        // blocker, so the proposal is still shown but confirmation stays off.
        if (! app(MergePreviewService::class)->directlyMatches(
            $this->duplicateDefinition(),
            $this->duplicateContext(),
            $this->first,
            $this->second,
        )) {
            $this->fatalBlockers[] = 'domain_conflict: these records do not directly match a configured rule, so they cannot be merged.';
        }
        $this->choiceFields = $plan->choiceFields();
        $this->matchReasons = $plan->matchReasons;
        $this->differences = $this->displayDifferences($plan->differences);
        $this->relations = $this->displayRelations($plan->relations);
        $this->choices = [];
        $this->merged = false;
        $this->mergedOperationId = '';
        $this->mergedSurvivorTitle = '';
        $this->configurationError = null;
    }

    /**
     * @param  list<FieldDifference>  $differences
     * @return list<array<string, mixed>>
     */
    private function displayDifferences(array $differences): array
    {
        $display = [];

        foreach ($differences as $difference) {
            $display[] = [
                'field' => $difference->field,
                'label' => $difference->label,
                'requiresChoice' => $difference->resolution->requiresChoice(),
                'proposedFromSource' => $difference->resolution === FieldResolution::TakeSource,
                'survivorValue' => $this->displayValue($difference->survivorValue),
                'sourceValue' => $this->displayValue($difference->sourceValue),
                'proposedValue' => $this->displayValue($difference->proposedValue),
                'audited' => $difference->audited,
            ];
        }

        return $display;
    }

    /**
     * @param  list<RelationImpact>  $relations
     * @return list<array<string, mixed>>
     */
    private function displayRelations(array $relations): array
    {
        $display = [];

        foreach ($relations as $relation) {
            $display[] = [
                'relation' => $relation->relation,
                'label' => $relation->label,
                'movingCount' => $relation->movingCount,
                'resultingCount' => $relation->resultingCount,
                'isNoOp' => $relation->isNoOp(),
                'summary' => $relation->summary,
            ];
        }

        return $display;
    }

    /**
     * A record value as plain text for the comparison grid. Rich text, arrays
     * and dates are rendered as text, never as markup, and the view escapes the
     * result again.
     */
    private function displayValue(mixed $value): string
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

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }
}
