{{--
    Duplicate banner.

    Uses plain semantic markup and utility classes rather than Filament Blade
    components, so it renders identically on both supported majors and carries no
    version-specific dependency. Every value is escaped; the state is conveyed by
    an icon and its own wording, never by colour alone.
--}}
@php
    $toneClasses = [
        'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400 dark:ring-info-400/30',
        'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30',
        'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30',
        'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30',
    ][$banner->tone] ?? 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10';
@endphp

<div
    @class(['fi-merge-duplicates-banner rounded-xl p-4 ring-1', $toneClasses])
    role="status"
    data-state="{{ $banner->state->value }}"
>
    <div class="flex flex-wrap items-start gap-3">
        <span aria-hidden="true" class="mt-0.5 shrink-0">
            @switch($banner->tone)
                @case('danger')
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3a1 1 0 102 0V7zm-1 7a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                    @break
                @case('warning')
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.5 3.5a1.7 1.7 0 013 0l6 10.4A1.7 1.7 0 0116 16.5H4a1.7 1.7 0 01-1.5-2.6l6-10.4zM10 7a1 1 0 00-1 1v3a1 1 0 002 0V8a1 1 0 00-1-1zm0 7a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                    @break
                @case('success')
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.7-9.3a1 1 0 00-1.4-1.4L9 10.6 7.7 9.3a1 1 0 00-1.4 1.4l2 2a1 1 0 001.4 0l4-4z" clip-rule="evenodd" /></svg>
                    @break
                @default
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 10-2 0 1 1 0 002 0zm-1 9a1 1 0 01-1-1v-3a1 1 0 112 0v3a1 1 0 01-1 1z" clip-rule="evenodd" /></svg>
            @endswitch
        </span>

        <div class="min-w-0 flex-1 space-y-1">
            <p class="text-sm font-semibold">{{ $banner->title }}</p>

            <p class="text-sm">{{ $banner->description }}</p>

            <p class="text-xs opacity-80">
                {{ $banner->lastScanLabel() }}

                @if ($banner->lastCompletedAt !== null)
                    <span class="sr-only">.</span>{{ $banner->staleNotice() }}
                @endif
            </p>

            @if ($banner->failureCode !== null)
                <p class="text-xs font-medium">{{ $banner->failureLabel() }}</p>
            @endif

            @if ($banner->groupsCount > 0)
                <p class="sr-only">{{ $banner->countsVisibleOnlyNotice() }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($banner->showReviewAction && ($reviewUrl ?? null) !== null)
                <a
                    href="{{ $reviewUrl }}"
                    class="fi-btn inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-950/10 hover:bg-white/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:ring-white/20"
                >
                    {{ $banner->reviewActionLabel() }}
                </a>
            @endif

            @if ($banner->showScanAction && ($scanAction ?? null) !== null)
                {{ $scanAction }}
            @endif

            @if ($banner->showRetryAction && ($scanAction ?? null) !== null)
                {{ $scanAction }}
            @endif
        </div>
    </div>
</div>
