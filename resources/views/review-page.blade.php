{{--
    Duplicate review page.

    Deliberately plain semantic markup with utility classes, like the banner, so
    it renders identically on both supported Filament majors. Every value is
    escaped. Staleness and state are conveyed by text as well as by tone, never
    by colour alone, and the layout reflows rather than relying on a fixed width.
--}}
@php
    /** @var \Nagi\FilamentMergeDuplicates\Scanning\ReviewState $state */
    /** @var list<\Nagi\FilamentMergeDuplicates\Scanning\ReviewGroup> $groups */
    $translation = 'filament-merge-duplicates::merge-duplicates.review.';
@endphp

<div class="fi-merge-duplicates-review space-y-6" data-state="{{ $state->value }}">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-gray-950 dark:text-white">
            {{ trans($translation . 'heading', ['label' => $definitionLabel]) }}
        </h1>

        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ trans_choice($translation . 'groups_total', $total, ['count' => $total]) }}
        </p>
    </header>

    @if ($bannerView !== null)
        {!! $bannerView !!}
    @endif

    @if ($canScan)
        <div>
            {{ $this->confirmScanAction }}
        </div>
    @endif

    @if ($groups !== [])
        <section class="space-y-4" aria-label="{{ trans($translation . 'groups_heading') }}">
            @foreach ($groups as $group)
                <article class="space-y-3 rounded-xl p-4 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $group->ruleLabel }}
                            </h2>

                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                {{ trans_choice($translation . 'records', $group->memberCount, ['count' => $group->memberCount]) }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @if ($group->hasStaleMember())
                                <span class="inline-flex items-center rounded-md bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30">
                                    {{ trans($translation . 'stale_badge') }}
                                </span>
                            @endif

                            @unless ($group->isReviewable())
                                <span class="inline-flex items-center rounded-md bg-danger-50 px-2 py-1 text-xs font-medium text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30">
                                    {{ trans($translation . 'not_reviewable_badge') }}
                                </span>
                            @endunless
                        </div>
                    </div>

                    <ul class="space-y-2">
                        @foreach ($group->members as $member)
                            <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-950 dark:text-white">
                                <span class="fi-merge-value font-medium">{{ $member->title }}</span>

                                <span class="fi-merge-value text-xs text-gray-500 dark:text-gray-400">#{{ $member->recordId->value }}</span>

                                @if ($member->missing)
                                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                        {{ trans($translation . 'member_missing') }}
                                    </span>
                                @endif

                                @if ($member->retired)
                                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                        {{ trans($translation . 'member_retired') }}
                                    </span>
                                @endif

                                @if ($member->changed)
                                    <span class="inline-flex items-center rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                                        {{ trans($translation . 'member_changed') }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @unless ($group->showsAllMembers())
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            {{ trans($translation . 'showing_of', ['shown' => count($group->members), 'total' => $group->memberCount]) }}
                        </p>
                    @endunless

                    @php($usable = $group->usableMembers())
                    @if ($canMerge && count($usable) >= 2)
                        <div>
                            <a
                                href="{{ \Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateMergePage::urlForPair($definitionId, $usable[0]->recordId->value, $usable[1]->recordId->value) }}"
                                class="fi-btn inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-950/10 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:ring-white/20 dark:hover:bg-white/5"
                            >
                                {{ trans($translation . 'compare') }}
                            </a>
                        </div>
                    @endif
                </article>
            @endforeach
        </section>

        @if ($lastPage > 1)
            <nav
                class="flex items-center justify-between gap-3"
                aria-label="{{ trans($translation . 'pagination') }}"
            >
                <button
                    type="button"
                    wire:click="goToPage({{ $page - 1 }})"
                    @disabled($page <= 1)
                    class="fi-btn inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-950/10 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50 dark:ring-white/20 dark:hover:bg-white/5"
                >
                    {{ trans($translation . 'previous') }}
                </button>

                <span class="text-sm text-gray-600 dark:text-gray-400">
                    {{ trans($translation . 'page_of', ['page' => $page, 'last' => $lastPage]) }}
                </span>

                <button
                    type="button"
                    wire:click="goToPage({{ $page + 1 }})"
                    @disabled($page >= $lastPage)
                    class="fi-btn inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 ring-gray-950/10 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50 dark:ring-white/20 dark:hover:bg-white/5"
                >
                    {{ trans($translation . 'next') }}
                </button>
            </nav>
        @endif
    @elseif ($state === \Nagi\FilamentMergeDuplicates\Scanning\ReviewState::Empty)
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ trans($translation . 'empty_body') }}
        </p>
    @elseif ($state === \Nagi\FilamentMergeDuplicates\Scanning\ReviewState::NeverScanned)
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ trans($translation . 'never_scanned_body') }}
        </p>
    @endif

    <x-filament-actions::modals />
</div>
