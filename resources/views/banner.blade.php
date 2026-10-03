{{--
    Duplicate banner.

    A single Filament callout: its colour, icon, padding and dark mode are the
    panel's, and the wording still carries the state on its own, so nothing depends
    on the tone alone. The host's scan control is rendered as given - Blade escapes
    a plain string and leaves markup built by the host alone - and the note about
    which records were counted is read by screen readers only, through Filament's
    own `fi-sr-only` class.
--}}
@php
    $tone = $banner->tone;

    $icon = [
        'danger' => 'heroicon-m-exclamation-circle',
        'warning' => 'heroicon-m-exclamation-triangle',
        'success' => 'heroicon-m-check-circle',
    ][$tone] ?? 'heroicon-m-information-circle';

    $color = in_array($tone, ['danger', 'warning', 'success', 'info', 'gray'], true) ? $tone : 'gray';
@endphp

<x-filament::callout
    class="fi-merge-duplicates-banner"
    role="status"
    :data-state="$banner->state->value"
    :color="$color"
    :icon="$icon"
    :heading="$banner->title"
    :description="$banner->description"
>
    <x-slot name="footer">
        <div class="fi-merge-group">
            <p class="text-xs">
                {{ $banner->lastScanLabel() }}

                @if ($banner->lastCompletedAt !== null)
                    <span class="fi-sr-only">.</span>{{ $banner->staleNotice() }}
                @endif
            </p>

            @if ($banner->failureCode !== null)
                <p class="text-xs font-medium">{{ $banner->failureLabel() }}</p>
            @endif

            @if ($banner->groupsCount > 0)
                <p class="fi-sr-only">{{ $banner->countsVisibleOnlyNotice() }}</p>
            @endif

            <div class="fi-merge-badges">
                @if ($banner->showReviewAction && ($reviewUrl ?? null) !== null)
                    <x-filament::button tag="a" :href="$reviewUrl" size="sm" color="gray" :outlined="true">
                        {{ $banner->reviewActionLabel() }}
                    </x-filament::button>
                @endif

                @if ($banner->showScanAction && ($scanAction ?? null) !== null)
                    {{ $scanAction }}
                @endif

                @if ($banner->showRetryAction && ($scanAction ?? null) !== null)
                    {{ $scanAction }}
                @endif
            </div>
        </div>
    </x-slot>
</x-filament::callout>
