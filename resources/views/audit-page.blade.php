{{--
    Merge audit page.

    One history entry as plain text. The payload contains only declared audit
    fields, choices and moved child identifiers; every value is escaped and rich
    text is shown as text, never rendered.
--}}
@php
    $translation = 'filament-merge-duplicates::merge-duplicates.audit.';
@endphp

<div class="fi-merge-duplicates-audit space-y-6">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-gray-950 dark:text-white">
            {{ trans($translation . 'heading') }}
        </h1>

        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ trans($translation . 'operation_label') }}:
            <span class="font-mono">{{ $operation }}</span>
        </p>
    </header>

    @if ($errorCode !== null)
        <div class="rounded-xl bg-danger-50 p-4 text-sm text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400" role="alert">
            <p class="font-semibold">{{ trans($translation . 'unreadable_title') }}</p>
            <p>{{ trans($translation . 'unreadable_description') }}</p>
            <p class="text-xs">{{ trans('filament-merge-duplicates::merge-duplicates.labels.reason_code', ['code' => $errorCode]) }}</p>
        </div>
    @elseif ($entries === [])
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ trans($translation . 'empty') }}
        </p>
    @else
        <dl class="space-y-3">
            @foreach ($entries as $key => $value)
                <div class="fi-merge-keyvalue">
                    <dt class="fi-merge-keyvalue__key">
                        {{ $key }}
                    </dt>
                    <dd class="fi-merge-value whitespace-pre-wrap break-words text-sm text-gray-950 dark:text-white">{{ $this->formatValue($value) }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
