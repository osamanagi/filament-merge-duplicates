{{--
    Merge preview page.

    Everything Filament provides a component for uses it: the panels are
    `x-filament::section`, the verdicts and side labels are `x-filament::badge`,
    the notices are `x-filament::callout`, the actions are `x-filament::button`
    laid out by `x-filament::actions`, and the choices are a
    `x-filament::fieldset` of `x-filament::input.radio` controls. They carry their
    own padding, palette and dark mode, and they exist with the same names and props
    on both supported majors - which is what lets one release line serve Filament 4
    and 5 without a second set of views.

    The package stylesheet then only adds the comparison itself, which Filament has
    no component for: one row per value, tinted with the panel's own success and
    danger colours, carrying the sign and the words (`+ Kept`, `− Not kept`,
    `= Same`, `· Empty`) so the meaning never rests on colour alone.

    Every value that came from a record is escaped and isolated in a `<bdi>`, so it
    keeps its own reading direction inside an Arabic panel.
--}}
@php
    $translation = 'filament-merge-duplicates::merge-duplicates.merge.';

    $statusColor = [
        'identical' => 'success',
        'different' => 'danger',
        'source_only' => 'info',
        'survivor_only' => 'info',
        'empty' => 'gray',
    ];

    $statusIcon = [
        'identical' => 'heroicon-m-check-circle',
        'different' => 'heroicon-m-exclamation-triangle',
        'source_only' => 'heroicon-m-arrow-path',
        'survivor_only' => 'heroicon-m-information-circle',
        'empty' => 'heroicon-m-minus-circle',
    ];

    $statusKeys = [
        'identical' => 'status_identical',
        'different' => 'status_different',
        'source_only' => 'status_source_only',
        'survivor_only' => 'status_survivor_only',
        'empty' => 'status_empty',
    ];
@endphp

<div class="fi-merge-duplicates-merge fi-merge-page">
    <header class="fi-merge-header">
        <h1 class="fi-header-heading">
            {{ trans($translation . 'heading', ['label' => $definitionLabel]) }}
        </h1>

        @if (! $merged && $configurationError === null)
            <p class="fi-section-header-description">{{ trans($translation . 'survivor_reason', ['reason' => $survivorReason]) }}</p>
        @endif

        @if ($matchReasons !== [] && ! $merged && $configurationError === null)
            <div class="fi-merge-badges">
                <span class="fi-section-header-description">{{ trans($translation . 'match_reasons_heading') }}</span>

                @foreach ($matchReasons as $reason)
                    <x-filament::badge color="info" icon="heroicon-m-link">
                        {{ $reason }}
                    </x-filament::badge>
                @endforeach
            </div>
        @endif
    </header>

    @if ($configurationError !== null)
        <x-filament::callout
            color="danger"
            icon="heroicon-m-x-circle"
            :heading="trans($translation . 'configuration_title')"
            :description="trans($translation . 'configuration_description')"
        >
            <x-slot name="footer">
                <p class="fi-merge-note">{{ trans('filament-merge-duplicates::merge-duplicates.labels.reason_code', ['code' => $configurationError]) }}</p>
            </x-slot>
        </x-filament::callout>
    @elseif ($merged)
        <x-filament::callout
            color="success"
            icon="heroicon-m-check-circle"
            :heading="trans($translation . 'succeeded_title')"
            :description="trans($translation . 'succeeded_body', ['title' => $mergedSurvivorTitle])"
        >
            <x-slot name="footer">
                <p class="fi-merge-note">
                    {{ trans('filament-merge-duplicates::merge-duplicates.actions.audit_reference') }}:
                    <bdi class="fi-merge-value fi-merge-mono">{{ $mergedOperationId }}</bdi>
                </p>
            </x-slot>
        </x-filament::callout>

        <x-filament::actions alignment="start">
            @if ($auditUrl !== null)
                <x-filament::button
                    tag="a"
                    :href="$auditUrl"
                    color="gray"
                    :outlined="true"
                    icon="heroicon-m-clipboard-document-list"
                >
                    {{ trans('filament-merge-duplicates::merge-duplicates.actions.view_audit') }}
                </x-filament::button>
            @endif

            <x-filament::button tag="a" :href="$reviewUrl" color="gray" :outlined="true">
                {{ trans('filament-merge-duplicates::merge-duplicates.actions.back_to_review') }}
            </x-filament::button>
        </x-filament::actions>
    @else
        <x-filament::section
            :heading="trans($translation . 'survivor_heading')"
            :description="trans($translation . 'survivor_hint')"
            :compact="true"
        >
            <x-filament::fieldset :label="trans($translation . 'survivor_heading')" :label-hidden="true" :contained="false">
                <div class="fi-merge-choice">
                    <label class="fi-merge-choice-card">
                        <x-filament::input.radio
                            name="fi-merge-survivor"
                            value="{{ $survivorValue }}"
                            wire:click="setSurvivor('{{ $survivorValue }}')"
                            :checked="true"
                        />

                        <span>
                            <span class="fi-section-header-heading">
                                {{ trans($translation . 'keep', ['title' => $survivorTitle]) }}
                            </span>
                            <bdi class="fi-section-header-description fi-merge-value">#{{ $survivorValue }}</bdi>
                        </span>
                    </label>

                    <label class="fi-merge-choice-card">
                        <x-filament::input.radio
                            name="fi-merge-survivor"
                            value="{{ $sourceValue }}"
                            wire:click="setSurvivor('{{ $sourceValue }}')"
                        />

                        <span>
                            <span class="fi-section-header-heading">
                                {{ trans($translation . 'keep', ['title' => $sourceTitle]) }}
                            </span>
                            <bdi class="fi-section-header-description fi-merge-value">#{{ $sourceValue }}</bdi>
                        </span>
                    </label>
                </div>
            </x-filament::fieldset>
        </x-filament::section>

        <section class="fi-merge-group" aria-label="{{ trans($translation . 'fields_heading') }}">
            <div class="fi-merge-group">
                <h2 class="fi-section-header-heading">{{ trans($translation . 'fields_heading') }}</h2>

                <div class="fi-merge-badges">
                    <x-filament::badge color="gray">
                        {{ trans($translation . 'summary_compared', ['count' => $comparisonSummary['total']]) }}
                    </x-filament::badge>

                    @if ($comparisonSummary['identical'] > 0)
                        <x-filament::badge color="success" icon="heroicon-m-check-circle">
                            {{ trans($translation . 'summary_identical', ['count' => $comparisonSummary['identical']]) }}
                        </x-filament::badge>
                    @endif

                    @if ($comparisonSummary['different'] > 0)
                        <x-filament::badge color="danger" icon="heroicon-m-exclamation-triangle">
                            {{ trans($translation . 'summary_different', ['count' => $comparisonSummary['different']]) }}
                        </x-filament::badge>
                    @endif

                    @if ($comparisonSummary['oneSided'] > 0)
                        <x-filament::badge color="info" icon="heroicon-m-arrow-path">
                            {{ trans($translation . 'summary_one_sided', ['count' => $comparisonSummary['oneSided']]) }}
                        </x-filament::badge>
                    @endif

                    @if ($comparisonSummary['empty'] > 0)
                        <x-filament::badge color="gray" icon="heroicon-m-minus-circle">
                            {{ trans($translation . 'summary_empty', ['count' => $comparisonSummary['empty']]) }}
                        </x-filament::badge>
                    @endif
                </div>
            </div>

            @foreach ($differences as $difference)
                @php
                    // Which side survives this field right now: the operator's choice if
                    // they made one, the proposal otherwise. Derived from the live choice
                    // state, so it is never stale after a rebuild.
                    $choice = $choices[$difference['field']] ?? null;
                    $keptSide = $difference['requiresChoice']
                        ? ($choice === 'source' ? 'source' : 'survivor')
                        : ($difference['proposedFromSource'] ? 'source' : 'survivor');

                    $keptValue = $keptSide === 'survivor' ? $difference['survivorValue'] : $difference['sourceValue'];
                    $droppedValue = $keptSide === 'survivor' ? $difference['sourceValue'] : $difference['survivorValue'];
                    $keptTitle = $keptSide === 'survivor' ? $survivorTitle : $sourceTitle;
                    $droppedTitle = $keptSide === 'survivor' ? $sourceTitle : $survivorTitle;
                @endphp

                {{-- `contained` keeps the row tint flush with the card edge, which is what
                     makes two values read as one comparison rather than two boxes. --}}
                <x-filament::section :contained="false">
                    <x-slot name="heading">
                        <div class="fi-merge-badges">
                            <span>{{ $difference['label'] }}</span>

                            <x-filament::badge :color="$statusColor[$difference['status']]" :icon="$statusIcon[$difference['status']]">
                                {{ trans($translation . $statusKeys[$difference['status']]) }}
                            </x-filament::badge>

                            @if ($difference['requiresChoice'])
                                <x-filament::badge color="warning" icon="heroicon-m-cursor-arrow-rays">
                                    {{ trans($translation . 'choice_required') }}
                                </x-filament::badge>
                            @endif

                            @if ($difference['audited'])
                                <x-filament::badge color="gray" icon="heroicon-m-document-text">
                                    {{ trans($translation . 'audited_marker') }}
                                </x-filament::badge>
                            @endif
                        </div>
                    </x-slot>

                    @if ($difference['status'] === 'identical')
                        <div class="fi-merge-diff-row fi-merge-diff-row--same">
                            <span class="fi-merge-diff-side">
                                <span class="fi-merge-diff-sign" aria-hidden="true">=</span>
                                <x-filament::badge color="success">{{ trans($translation . 'side_same') }}</x-filament::badge>
                            </span>
                            <bdi class="fi-merge-diff-value fi-merge-value">{{ $difference['survivorValue'] }}</bdi>
                        </div>
                    @elseif ($difference['status'] === 'different')
                        <div class="fi-merge-diff-row fi-merge-diff-row--kept">
                            <span class="fi-merge-diff-side">
                                <span class="fi-merge-diff-sign" aria-hidden="true">+</span>
                                <x-filament::badge color="success">{{ trans($translation . 'side_kept') }}</x-filament::badge>
                                <span class="fi-merge-diff-side__note">{{ trans($translation . 'value_on', ['title' => $keptTitle]) }}</span>
                            </span>
                            <bdi class="fi-merge-diff-value fi-merge-value">{{ $keptValue }}</bdi>
                        </div>

                        <div class="fi-merge-diff-row fi-merge-diff-row--dropped">
                            <span class="fi-merge-diff-side">
                                <span class="fi-merge-diff-sign" aria-hidden="true">−</span>
                                <x-filament::badge color="danger">{{ trans($translation . 'side_dropped') }}</x-filament::badge>
                                <span class="fi-merge-diff-side__note">{{ trans($translation . 'value_on', ['title' => $droppedTitle]) }}</span>
                            </span>
                            <bdi class="fi-merge-diff-value fi-merge-value">{{ $droppedValue }}</bdi>
                        </div>
                    @elseif ($difference['status'] === 'source_only')
                        <div class="fi-merge-diff-row fi-merge-diff-row--kept">
                            <span class="fi-merge-diff-side">
                                <span class="fi-merge-diff-sign" aria-hidden="true">+</span>
                                <x-filament::badge color="success">{{ trans($translation . 'side_added') }}</x-filament::badge>
                                <span class="fi-merge-diff-side__note">{{ trans($translation . 'value_on', ['title' => $sourceTitle]) }}</span>
                            </span>
                            <bdi class="fi-merge-diff-value fi-merge-value">{{ $difference['sourceValue'] }}</bdi>
                        </div>

                        <p class="fi-merge-diff-note">
                            {{ trans($translation . 'will_transfer', ['title' => $survivorTitle]) }}
                        </p>
                    @elseif ($difference['status'] === 'survivor_only')
                        <div class="fi-merge-diff-row fi-merge-diff-row--kept">
                            <span class="fi-merge-diff-side">
                                <span class="fi-merge-diff-sign" aria-hidden="true">=</span>
                                <x-filament::badge color="success">{{ trans($translation . 'side_kept') }}</x-filament::badge>
                                <span class="fi-merge-diff-side__note">{{ trans($translation . 'value_on', ['title' => $survivorTitle]) }}</span>
                            </span>
                            <bdi class="fi-merge-diff-value fi-merge-value">{{ $difference['survivorValue'] }}</bdi>
                        </div>
                    @else
                        <div class="fi-merge-diff-row fi-merge-diff-row--quiet">
                            <span class="fi-merge-diff-side">
                                <span class="fi-merge-diff-sign" aria-hidden="true">·</span>
                                <x-filament::badge color="gray">{{ trans($translation . 'side_empty') }}</x-filament::badge>
                            </span>
                            <span class="fi-merge-diff-value"></span>
                        </div>
                    @endif

                    @if ($difference['requiresChoice'])
                        <div class="fi-merge-diff-note">
                            <x-filament::fieldset :label="trans($translation . 'choose_for', ['label' => $difference['label']])" :label-hidden="true" :contained="false">
                                <div class="fi-merge-choice-options">
                                    <label class="fi-merge-choice-option">
                                        <x-filament::input.radio
                                            name="fi-merge-choice-{{ $difference['field'] }}"
                                            value="survivor"
                                            wire:click="setChoice('{{ $difference['field'] }}', 'survivor')"
                                            :checked="$choice === 'survivor'"
                                        />

                                        <span>{{ trans($translation . 'keep_survivor_value') }}</span>
                                    </label>

                                    <label class="fi-merge-choice-option">
                                        <x-filament::input.radio
                                            name="fi-merge-choice-{{ $difference['field'] }}"
                                            value="source"
                                            wire:click="setChoice('{{ $difference['field'] }}', 'source')"
                                            :checked="$choice === 'source'"
                                        />

                                        <span>{{ trans($translation . 'take_source_value') }}</span>
                                    </label>
                                </div>
                            </x-filament::fieldset>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </section>

        @if ($relations !== [])
            <x-filament::section :heading="trans($translation . 'relations_heading')" :compact="true">
                <ul class="fi-merge-group">
                    @foreach ($relations as $relation)
                        <li class="fi-merge-choice-option">
                            <x-filament::badge :color="$relation['isNoOp'] ? 'gray' : 'info'">
                                {{ $relation['label'] }}
                            </x-filament::badge>

                            <span>{{ $relation['summary'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @if ($fatalBlockers !== [])
            <x-filament::callout
                color="danger"
                icon="heroicon-m-no-symbol"
                :heading="trans($translation . 'blockers_heading')"
            >
                <x-slot name="footer">
                    <ul class="fi-merge-blockers">
                        @foreach ($fatalBlockers as $blocker)
                            <li>{{ $blocker }}</li>
                        @endforeach
                    </ul>
                </x-slot>
            </x-filament::callout>
        @endif

        <x-filament::callout
            color="warning"
            icon="heroicon-m-exclamation-triangle"
            :description="$retirementWarning"
        />

        <x-filament::actions alignment="start">
            <x-filament::button
                wire:click="confirm"
                wire:loading.attr="disabled"
                :disabled="! $canConfirm"
                icon="heroicon-m-check"
            >
                {{ trans($translation . 'confirm') }}
            </x-filament::button>

            @if ($canDismiss)
                <x-filament::button
                    wire:click="dismiss"
                    wire:loading.attr="disabled"
                    color="gray"
                    :outlined="true"
                >
                    {{ trans($translation . 'not_duplicates') }}
                </x-filament::button>
            @endif

            <x-filament::link :href="$reviewUrl">
                {{ trans('filament-merge-duplicates::merge-duplicates.actions.back_to_review') }}
            </x-filament::link>
        </x-filament::actions>
    @endif
</div>
