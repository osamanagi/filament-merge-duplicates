{{--
    Merge audit page.

    One history entry, rendered with Filament's components: the operation is a
    section, each recorded field is a key/value row, and the values keep the
    panel's own typography. Every value is escaped and shown as text - rich text is
    never rendered - and isolated in a `<bdi>` so a value keeps its own reading
    direction in an Arabic panel.
--}}
@php
    $translation = 'filament-merge-duplicates::merge-duplicates.audit.';
@endphp

<div class="fi-merge-duplicates-audit fi-merge-page">
    <header class="fi-merge-header">
        <h1 class="fi-header-heading">
            {{ trans($translation . 'heading') }}
        </h1>

        <p class="fi-section-header-description">
            {{ trans($translation . 'operation_label') }}:
            <bdi class="fi-merge-value fi-merge-mono">{{ $operation }}</bdi>
        </p>
    </header>

    @if ($errorCode !== null)
        <x-filament::callout
            color="danger"
            icon="heroicon-m-x-circle"
            :heading="trans($translation . 'unreadable_title')"
            :description="trans($translation . 'unreadable_description')"
        >
            <x-slot name="footer">
                <p class="text-xs">{{ trans('filament-merge-duplicates::merge-duplicates.labels.reason_code', ['code' => $errorCode]) }}</p>
            </x-slot>
        </x-filament::callout>
    @elseif ($entries === [])
        <x-filament::callout
            color="gray"
            icon="heroicon-m-information-circle"
            :description="trans($translation . 'empty')"
        />
    @else
        <x-filament::section :compact="true">
            <dl class="fi-merge-audit-list">
                @foreach ($entries as $key => $value)
                    <div class="fi-merge-audit-entry">
                        <dt class="fi-merge-audit-entry__key">{{ $key }}</dt>
                        <dd class="fi-merge-audit-entry__value fi-merge-value">{{ $this->formatValue($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    @endif
</div>
