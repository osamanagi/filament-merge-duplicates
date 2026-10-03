{{--
    Duplicate review page.

    Filament's components do the surfaces: `x-filament::section` per suggested
    group, `x-filament::badge` for the counts and the member states,
    `x-filament::button` for the actions, `x-filament::callout` for the empty
    states, and the panel's own heading and description classes for typography. The
    package stylesheet only adds the member list separators.

    Every record title and identifier is escaped and isolated in a `<bdi>`, so it
    keeps its own reading direction inside an Arabic panel.
--}}
@php
    $translation = 'filament-merge-duplicates::merge-duplicates.review.';
@endphp

<div class="fi-merge-duplicates-review fi-merge-page">
    <header class="fi-merge-header">
        <h1 class="fi-header-heading">
            {{ trans($translation . 'heading', ['label' => $definitionLabel]) }}
        </h1>

        <p class="fi-section-header-description">
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
        <section class="fi-merge-groups" aria-label="{{ trans($translation . 'groups_heading') }}">
            @foreach ($groups as $group)
                <x-filament::section :compact="true">
                    <x-slot name="heading">
                        <div class="fi-merge-badges">
                            <span>{{ $group->ruleLabel }}</span>

                            <x-filament::badge color="gray">
                                {{ trans_choice($translation . 'records', $group->memberCount, ['count' => $group->memberCount]) }}
                            </x-filament::badge>

                            @if ($group->hasStaleMember())
                                <x-filament::badge color="warning" icon="heroicon-m-clock">
                                    {{ trans($translation . 'stale_badge') }}
                                </x-filament::badge>
                            @endif

                            @unless ($group->isReviewable())
                                <x-filament::badge color="danger" icon="heroicon-m-no-symbol">
                                    {{ trans($translation . 'not_reviewable_badge') }}
                                </x-filament::badge>
                            @endunless
                        </div>
                    </x-slot>

                    <ul class="fi-merge-member-list">
                        @foreach ($group->members as $member)
                            <li class="fi-merge-member">
                                <bdi class="fi-merge-member__title fi-merge-value">{{ $member->title }}</bdi>

                                <bdi class="fi-merge-member__id fi-merge-value">#{{ $member->recordId->value }}</bdi>

                                @if ($member->missing)
                                    <x-filament::badge color="gray">{{ trans($translation . 'member_missing') }}</x-filament::badge>
                                @endif

                                @if ($member->retired)
                                    <x-filament::badge color="gray">{{ trans($translation . 'member_retired') }}</x-filament::badge>
                                @endif

                                @if ($member->changed)
                                    <x-filament::badge color="warning">{{ trans($translation . 'member_changed') }}</x-filament::badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @unless ($group->showsAllMembers())
                        <p class="fi-section-header-description">
                            {{ trans($translation . 'showing_of', ['shown' => count($group->members), 'total' => $group->memberCount]) }}
                        </p>
                    @endunless

                    @php($usable = $group->usableMembers())
                    @if ($canMerge && count($usable) >= 2)
                        <x-filament::actions alignment="start">
                            <x-filament::button
                                tag="a"
                                :href="\Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateMergePage::urlForPair($definitionId, $usable[0]->recordId->value, $usable[1]->recordId->value)"
                                size="sm"
                                icon="heroicon-m-arrows-right-left"
                            >
                                {{ trans($translation . 'compare') }}
                            </x-filament::button>
                        </x-filament::actions>
                    @endif
                </x-filament::section>
            @endforeach
        </section>

        @if ($lastPage > 1)
            <x-filament::actions alignment="between">
                <x-filament::button
                    wire:click="goToPage({{ $page - 1 }})"
                    :disabled="$page <= 1"
                    color="gray"
                    :outlined="true"
                    size="sm"
                >
                    {{ trans($translation . 'previous') }}
                </x-filament::button>

                <span class="fi-section-header-description">
                    {{ trans($translation . 'page_of', ['page' => $page, 'last' => $lastPage]) }}
                </span>

                <x-filament::button
                    wire:click="goToPage({{ $page + 1 }})"
                    :disabled="$page >= $lastPage"
                    color="gray"
                    :outlined="true"
                    size="sm"
                >
                    {{ trans($translation . 'next') }}
                </x-filament::button>
            </x-filament::actions>
        @endif
    @elseif ($state === \Nagi\FilamentMergeDuplicates\Scanning\ReviewState::Empty)
        <x-filament::callout
            color="success"
            icon="heroicon-m-check-circle"
            :description="trans($translation . 'empty_body')"
        />
    @elseif ($state === \Nagi\FilamentMergeDuplicates\Scanning\ReviewState::NeverScanned)
        <x-filament::callout
            color="gray"
            icon="heroicon-m-information-circle"
            :description="trans($translation . 'never_scanned_body')"
        />
    @endif

    <x-filament-actions::modals />
</div>
