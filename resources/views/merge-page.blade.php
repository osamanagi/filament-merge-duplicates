{{--
    Merge preview page.

    Plain semantic markup with utility classes, for the same cross-major reason
    as the banner and the review page. Every value that came from a record is
    escaped; the comparison is stacked and reflows, and choices are real radio
    inputs with labels so keyboard and screen-reader use is possible without a
    pointer.
--}}
@php
    $translation = 'filament-merge-duplicates::merge-duplicates.merge.';
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
        <section class="space-y-3 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ trans($translation . 'survivor_heading') }}
            </h2>

            <p class="text-xs text-gray-600 dark:text-gray-400">
                {{ trans($translation . 'survivor_hint') }}
            </p>

            <fieldset class="space-y-2">
                <legend class="sr-only">{{ trans($translation . 'survivor_heading') }}</legend>

                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-950 dark:text-white">
                    <input
                        type="radio"
                        name="fi-merge-survivor"
                        value="{{ $survivorValue }}"
                        wire:click="setSurvivor('{{ $survivorValue }}')"
                        @checked(true)
                        class="text-primary-600 focus-visible:ring-2 focus-visible:ring-primary-500"
                    >
                    <span>{{ trans($translation . 'keep', ['title' => $survivorTitle]) }}</span>
                </label>

                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-950 dark:text-white">
                    <input
                        type="radio"
                        name="fi-merge-survivor"
                        value="{{ $sourceValue }}"
                        wire:click="setSurvivor('{{ $sourceValue }}')"
                        class="text-primary-600 focus-visible:ring-2 focus-visible:ring-primary-500"
                    >
                    <span>{{ trans($translation . 'keep', ['title' => $sourceTitle]) }}</span>
                </label>
            </fieldset>
        </section>

        <section class="space-y-3" aria-label="{{ trans($translation . 'fields_heading') }}">
            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ trans($translation . 'fields_heading') }}
            </h2>

            @foreach ($differences as $difference)
                <div class="space-y-2 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $difference['label'] }}</h3>

                        @if ($difference['requiresChoice'])
                            <span class="inline-flex items-center rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                                {{ trans($translation . 'choice_required') }}
                            </span>
                        @elseif ($difference['proposedFromSource'])
                            <span class="inline-flex items-center rounded-md bg-info-50 px-2 py-0.5 text-xs font-medium text-info-700 dark:bg-info-400/10 dark:text-info-400">
                                {{ trans($translation . 'proposed_from_source') }}
                            </span>
                        @endif
                    </div>

                    <dl class="grid gap-2 sm:grid-cols-2">
                        <div class="space-y-1">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans($translation . 'survivor_value', ['title' => $survivorTitle]) }}</dt>
                            <dd class="break-words text-sm text-gray-950 dark:text-white">{{ $difference['survivorValue'] }}</dd>
                        </div>

                        <div class="space-y-1">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans($translation . 'source_value', ['title' => $sourceTitle]) }}</dt>
                            <dd class="break-words text-sm text-gray-950 dark:text-white">{{ $difference['sourceValue'] }}</dd>
                        </div>
                    </dl>

                    @if ($difference['requiresChoice'])
                        <fieldset class="flex flex-wrap gap-4 pt-1">
                            <legend class="sr-only">{{ trans($translation . 'choose_for', ['label' => $difference['label']]) }}</legend>

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
                </div>
            @endforeach
        </section>

        @if ($relations !== [])
            <section class="space-y-2" aria-label="{{ trans($translation . 'relations_heading') }}">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ trans($translation . 'relations_heading') }}
                </h2>

                <ul class="space-y-1">
                    @foreach ($relations as $relation)
                        <li class="text-sm text-gray-950 dark:text-white">
                            {{ $relation['summary'] }}
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

        <p class="text-sm text-warning-700 dark:text-warning-400">
            {{ $retirementWarning }}
        </p>

        <div class="flex flex-wrap items-center gap-3">
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

            <a href="{{ $reviewUrl }}" class="text-sm font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400">
                {{ trans('filament-merge-duplicates::merge-duplicates.actions.back_to_review') }}
            </a>
        </div>
    @endif
</div>
