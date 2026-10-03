{{--
    Merge preview page.

    Plain semantic markup, for the same cross-major reason as the banner and the
    review page: no Filament Blade component and no version-specific API, so one
    release line renders the same on Filament 4 and 5. The comparison's own visual
    language lives in the package stylesheet rather than in utility classes,
    because the panel's compiled CSS only carries the utilities its own components
    use.

    The comparison reads as a diff:

    - a green row is a value that survives the merge,
    - a red row is a value that does not,
    - every row also carries the sign and the word for it (`+ Kept`, `− Not kept`,
      `= Same`, `· Empty`), so the meaning never depends on colour alone - which is
      also what keeps it usable in forced-colours mode and for a colour-blind
      reader.

    Every value that came from a record is escaped, every value keeps its own
    reading direction, and the choices are real radio inputs inside labels, so the
    whole page works without a pointer.
--}}
@php
    $translation = 'filament-merge-duplicates::merge-duplicates.merge.';

    $chipClasses = [
        'identical' => 'fi-merge-chip--same',
        'different' => 'fi-merge-chip--different',
        'source_only' => 'fi-merge-chip--soft',
        'survivor_only' => 'fi-merge-chip--soft',
        'empty' => 'fi-merge-chip--muted',
    ];

    $statusKeys = [
        'identical' => 'status_identical',
        'different' => 'status_different',
        'source_only' => 'status_source_only',
        'survivor_only' => 'status_survivor_only',
        'empty' => 'status_empty',
    ];
@endphp

<div class="fi-merge-duplicates-merge space-y-6">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-gray-950 dark:text-white">
            {{ trans($translation . 'heading', ['label' => $definitionLabel]) }}
        </h1>

        @if (! $merged && $configurationError === null)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ trans($translation . 'survivor_reason', ['reason' => $survivorReason]) }}
            </p>
        @endif

        @if ($matchReasons !== [] && ! $merged && $configurationError === null)
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                    {{ trans($translation . 'match_reasons_heading') }}
                </span>

                @foreach ($matchReasons as $reason)
                    <span class="fi-merge-chip fi-merge-chip--soft">
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.7-9.3a1 1 0 00-1.4-1.4L9 10.6 7.7 9.3a1 1 0 00-1.4 1.4l2 2a1 1 0 001.4 0l4-4z" clip-rule="evenodd" /></svg>
                        {{ $reason }}
                    </span>
                @endforeach
            </div>
        @endif
    </header>

    @if ($configurationError !== null)
        <div class="rounded-xl bg-danger-50 p-4 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400">
            <p class="font-semibold">{{ trans($translation . 'configuration_title') }}</p>
            <p>{{ trans($translation . 'configuration_description') }}</p>
            <p class="text-xs">{{ trans('filament-merge-duplicates::merge-duplicates.labels.reason_code', ['code' => $configurationError]) }}</p>
        </div>
    @elseif ($merged)
        <section class="space-y-2 rounded-xl bg-success-50 p-4 ring-1 ring-success-600/20 dark:bg-success-400/10" role="status">
            <h2 class="text-sm font-semibold text-success-800 dark:text-success-300">
                {{ trans($translation . 'succeeded_title') }}
            </h2>

            <p class="text-sm text-success-800 dark:text-success-300">
                {{ trans($translation . 'succeeded_body', ['title' => $mergedSurvivorTitle]) }}
            </p>

            <p class="text-xs text-success-800 dark:text-success-300">
                {{ trans('filament-merge-duplicates::merge-duplicates.actions.audit_reference') }}:
                <span class="font-mono">{{ $mergedOperationId }}</span>
            </p>

            <div class="flex flex-wrap gap-2 pt-1">
                @if ($auditUrl !== null)
                    <a href="{{ $auditUrl }}" class="fi-btn inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-success-600/30 hover:bg-white/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                        {{ trans('filament-merge-duplicates::merge-duplicates.actions.view_audit') }}
                    </a>
                @endif

                <a href="{{ $reviewUrl }}" class="fi-btn inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-success-600/30 hover:bg-white/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                    {{ trans('filament-merge-duplicates::merge-duplicates.actions.back_to_review') }}
                </a>
            </div>
        </section>
    @else
        <section class="fi-merge-panel space-y-3">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ trans($translation . 'survivor_heading') }}
                </h2>

                <p class="text-xs text-gray-600 dark:text-gray-400">
                    {{ trans($translation . 'survivor_hint') }}
                </p>
            </div>

            <fieldset class="space-y-2">
                <legend class="fi-merge-visually-hidden">{{ trans($translation . 'survivor_heading') }}</legend>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-gray-950/10 hover:bg-gray-50 has-[:checked]:ring-2 has-[:checked]:ring-primary-500 dark:ring-white/15 dark:hover:bg-white/5">
                    <input
                        type="radio"
                        name="fi-merge-survivor"
                        value="{{ $survivorValue }}"
                        wire:click="setSurvivor('{{ $survivorValue }}')"
                        @checked(true)
                        class="mt-0.5 text-primary-600 focus-visible:ring-2 focus-visible:ring-primary-500"
                    >
                    <span class="min-w-0 space-y-0.5">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">
                            {{ trans($translation . 'keep', ['title' => $survivorTitle]) }}
                        </span>
                        <span class="fi-merge-value block text-xs text-gray-500 dark:text-gray-400">#{{ $survivorValue }}</span>
                    </span>
                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-gray-950/10 hover:bg-gray-50 has-[:checked]:ring-2 has-[:checked]:ring-primary-500 dark:ring-white/15 dark:hover:bg-white/5">
                    <input
                        type="radio"
                        name="fi-merge-survivor"
                        value="{{ $sourceValue }}"
                        wire:click="setSurvivor('{{ $sourceValue }}')"
                        class="mt-0.5 text-primary-600 focus-visible:ring-2 focus-visible:ring-primary-500"
                    >
                    <span class="min-w-0 space-y-0.5">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">
                            {{ trans($translation . 'keep', ['title' => $sourceTitle]) }}
                        </span>
                        <span class="fi-merge-value block text-xs text-gray-500 dark:text-gray-400">#{{ $sourceValue }}</span>
                    </span>
                </label>
            </fieldset>
        </section>

        <section class="space-y-3" aria-label="{{ trans($translation . 'fields_heading') }}">
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ trans($translation . 'fields_heading') }}
            </h2>

            <div class="fi-merge-summary">
                <span class="fi-merge-chip fi-merge-chip--muted">
                    {{ trans($translation . 'summary_compared', ['count' => $comparisonSummary['total']]) }}
                </span>

                @if ($comparisonSummary['identical'] > 0)
                    <span class="fi-merge-chip fi-merge-chip--same">
                        {{ trans($translation . 'summary_identical', ['count' => $comparisonSummary['identical']]) }}
                    </span>
                @endif

                @if ($comparisonSummary['different'] > 0)
                    <span class="fi-merge-chip fi-merge-chip--different">
                        {{ trans($translation . 'summary_different', ['count' => $comparisonSummary['different']]) }}
                    </span>
                @endif

                @if ($comparisonSummary['oneSided'] > 0)
                    <span class="fi-merge-chip fi-merge-chip--soft">
                        {{ trans($translation . 'summary_one_sided', ['count' => $comparisonSummary['oneSided']]) }}
                    </span>
                @endif

                @if ($comparisonSummary['empty'] > 0)
                    <span class="fi-merge-chip fi-merge-chip--muted">
                        {{ trans($translation . 'summary_empty', ['count' => $comparisonSummary['empty']]) }}
                    </span>
                @endif
            </div>

            @foreach ($differences as $difference)
                @php
                    // Which side survives this field right now: the operator's choice if
                    // they made one, the proposal otherwise. Derived from the live choice
                    // state so it is never stale after a rebuild.
                    $keptSide = $difference['requiresChoice']
                        ? (($choices[$difference['field']] ?? null) === 'source' ? 'source' : 'survivor')
                        : ($difference['proposedFromSource'] ? 'source' : 'survivor');
                @endphp

                <article class="fi-merge-diff">
                    <div class="fi-merge-diff__head">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $difference['label'] }}</h3>

                        <span @class(['fi-merge-chip', $chipClasses[$difference['status']]])>
                            @switch($difference['status'])
                                @case('identical')
                                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
                                    @break
                                @case('different')
                                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v9a1 1 0 11-2 0V3a1 1 0 011-1zm0 14a1.25 1.25 0 100-2.5A1.25 1.25 0 0010 16z" clip-rule="evenodd" /></svg>
                                    @break
                                @default
                                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3a1 1 0 102 0V7zm-1 7a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                            @endswitch

                            {{ trans($translation . $statusKeys[$difference['status']]) }}
                        </span>

                        @if ($difference['audited'])
                            <span class="fi-merge-chip fi-merge-chip--muted">{{ trans($translation . 'audited_marker') }}</span>
                        @endif
                    </div>

                    @if ($difference['status'] === 'identical')
                        <dl class="fi-merge-diff__rows">
                            <div class="fi-merge-diff-row fi-merge-diff-row--same">
                                <dt class="fi-merge-diff-row__side">
                                    <span class="fi-merge-diff-row__gutter" aria-hidden="true">=</span>
                                    <span class="fi-merge-chip fi-merge-chip--same">{{ trans($translation . 'side_same') }}</span>
                                </dt>
                                <dd class="fi-merge-diff-row__value fi-merge-value">{{ $difference['survivorValue'] }}</dd>
                            </div>
                        </dl>
                    @elseif ($difference['status'] === 'different')
                        <dl class="fi-merge-diff__rows">
                            <div class="fi-merge-diff-row fi-merge-diff-row--kept">
                                <dt class="fi-merge-diff-row__side">
                                    <span class="fi-merge-diff-row__gutter" aria-hidden="true">+</span>
                                    <span class="fi-merge-chip fi-merge-chip--same">{{ trans($translation . 'side_kept') }}</span>
                                    <span class="fi-merge-diff-row__provenance">{{ trans($keptSide === 'survivor' ? $translation . 'survivor_value' : $translation . 'source_value', ['title' => $keptSide === 'survivor' ? $survivorTitle : $sourceTitle]) }}</span>
                                </dt>
                                <dd class="fi-merge-diff-row__value fi-merge-value">{{ $keptSide === 'survivor' ? $difference['survivorValue'] : $difference['sourceValue'] }}</dd>
                            </div>

                            <div class="fi-merge-diff-row fi-merge-diff-row--dropped">
                                <dt class="fi-merge-diff-row__side">
                                    <span class="fi-merge-diff-row__gutter" aria-hidden="true">−</span>
                                    <span class="fi-merge-chip fi-merge-chip--different">{{ trans($translation . 'side_dropped') }}</span>
                                    <span class="fi-merge-diff-row__provenance">{{ trans($keptSide === 'survivor' ? $translation . 'source_value' : $translation . 'survivor_value', ['title' => $keptSide === 'survivor' ? $sourceTitle : $survivorTitle]) }}</span>
                                </dt>
                                <dd class="fi-merge-diff-row__value fi-merge-value">{{ $keptSide === 'survivor' ? $difference['sourceValue'] : $difference['survivorValue'] }}</dd>
                            </div>
                        </dl>
                    @elseif ($difference['status'] === 'source_only')
                        <dl class="fi-merge-diff__rows">
                            <div class="fi-merge-diff-row fi-merge-diff-row--kept">
                                <dt class="fi-merge-diff-row__side">
                                    <span class="fi-merge-diff-row__gutter" aria-hidden="true">+</span>
                                    <span class="fi-merge-chip fi-merge-chip--same">{{ trans($translation . 'side_added') }}</span>
                                    <span class="fi-merge-diff-row__provenance">{{ trans($translation . 'source_value', ['title' => $sourceTitle]) }}</span>
                                </dt>
                                <dd class="fi-merge-diff-row__value fi-merge-value">{{ $difference['sourceValue'] }}</dd>
                            </div>
                        </dl>

                        <p class="fi-merge-diff__note">
                            {{ trans($translation . 'will_transfer', ['title' => $survivorTitle]) }}
                        </p>
                    @elseif ($difference['status'] === 'survivor_only')
                        <dl class="fi-merge-diff__rows">
                            <div class="fi-merge-diff-row fi-merge-diff-row--kept">
                                <dt class="fi-merge-diff-row__side">
                                    <span class="fi-merge-diff-row__gutter" aria-hidden="true">=</span>
                                    <span class="fi-merge-chip fi-merge-chip--same">{{ trans($translation . 'side_kept') }}</span>
                                    <span class="fi-merge-diff-row__provenance">{{ trans($translation . 'survivor_value', ['title' => $survivorTitle]) }}</span>
                                </dt>
                                <dd class="fi-merge-diff-row__value fi-merge-value">{{ $difference['survivorValue'] }}</dd>
                            </div>
                        </dl>
                    @else
                        <dl class="fi-merge-diff__rows">
                            <div class="fi-merge-diff-row fi-merge-diff-row--muted">
                                <dt class="fi-merge-diff-row__side">
                                    <span class="fi-merge-diff-row__gutter" aria-hidden="true">·</span>
                                    <span class="fi-merge-chip fi-merge-chip--muted">{{ trans($translation . 'side_empty') }}</span>
                                </dt>
                                <dd class="fi-merge-diff-row__value"></dd>
                            </div>
                        </dl>
                    @endif

                    @if ($difference['requiresChoice'])
                        <fieldset class="fi-merge-choice">
                            <legend class="fi-merge-visually-hidden">{{ trans($translation . 'choose_for', ['label' => $difference['label']]) }}</legend>

                            <span class="fi-merge-chip fi-merge-chip--different">{{ trans($translation . 'choice_required') }}</span>

                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-950 dark:text-white">
                                <input
                                    type="radio"
                                    name="fi-merge-choice-{{ $difference['field'] }}"
                                    value="survivor"
                                    wire:click="setChoice('{{ $difference['field'] }}', 'survivor')"
                                    @checked(($choices[$difference['field']] ?? null) === 'survivor')
                                    class="text-primary-600 focus-visible:ring-2 focus-visible:ring-primary-500"
                                >
                                <span>{{ trans($translation . 'keep_survivor_value') }}</span>
                            </label>

                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-950 dark:text-white">
                                <input
                                    type="radio"
                                    name="fi-merge-choice-{{ $difference['field'] }}"
                                    value="source"
                                    wire:click="setChoice('{{ $difference['field'] }}', 'source')"
                                    @checked(($choices[$difference['field']] ?? null) === 'source')
                                    class="text-primary-600 focus-visible:ring-2 focus-visible:ring-primary-500"
                                >
                                <span>{{ trans($translation . 'take_source_value') }}</span>
                            </label>
                        </fieldset>
                    @endif
                </article>
            @endforeach
        </section>

        @if ($relations !== [])
            <section class="fi-merge-panel space-y-2" aria-label="{{ trans($translation . 'relations_heading') }}">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ trans($translation . 'relations_heading') }}
                </h2>

                <ul class="space-y-2">
                    @foreach ($relations as $relation)
                        <li class="flex flex-wrap items-center gap-2 text-sm text-gray-950 dark:text-white">
                            <span @class(['fi-merge-chip', $relation['isNoOp'] ? 'fi-merge-chip--muted' : 'fi-merge-chip--soft'])>
                                {{ $relation['label'] }}
                            </span>
                            <span>{{ $relation['summary'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($fatalBlockers !== [])
            <section class="space-y-2 rounded-xl bg-danger-50 p-4 ring-1 ring-danger-600/20 dark:bg-danger-400/10" role="alert">
                <h2 class="text-sm font-semibold text-danger-800 dark:text-danger-300">
                    {{ trans($translation . 'blockers_heading') }}
                </h2>

                <ul class="list-inside list-disc space-y-1 text-sm text-danger-700 dark:text-danger-400">
                    @foreach ($fatalBlockers as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="fi-merge-actions">
            <button
                type="button"
                wire:click="confirm"
                wire:loading.attr="disabled"
                @disabled(! $canConfirm)
                class="fi-btn inline-flex items-center rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:focus-visible:ring-offset-gray-900"
            >
                {{ trans($translation . 'confirm') }}
            </button>

            @if ($canDismiss)
                <button
                    type="button"
                    wire:click="dismiss"
                    wire:loading.attr="disabled"
                    class="fi-btn inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-950/10 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50 dark:ring-white/20 dark:hover:bg-white/5"
                >
                    {{ trans($translation . 'not_duplicates') }}
                </button>
            @endif

            <p class="fi-merge-actions__note text-warning-700 dark:text-warning-400">
                {{ $retirementWarning }}
            </p>

            <a href="{{ $reviewUrl }}" class="text-sm font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400">
                {{ trans('filament-merge-duplicates::merge-duplicates.actions.back_to_review') }}
            </a>
        </div>
    @endif
</div>
