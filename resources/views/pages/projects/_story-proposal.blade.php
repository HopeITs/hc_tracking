{{--
    The Proposal block of the Story (Tracking Desk stage 4): the proposal page
    itself, embedded (pages::proposals.show in embedded mode), so every tab,
    action and dialog it had works here. Always open, never folded. With
    several linked proposals a switcher picks which one (?proposal=); the
    tab rides on ?ptab=.
--}}
@php
    $embeddedId = $this->embeddedProposalId;
    $switchable = $this->attachedProposals->filter(fn ($proposal) => auth()->user()?->can('view', $proposal));
@endphp

<div class="dk-block dk-story-proposal" id="proposal" data-test="story-proposal" data-proposal-id="{{ $embeddedId }}">
    <div class="dk-block-h">
        <h3>{{ __('story.facts.proposal') }}</h3>
        @if ($switchable->count() > 1)
            <nav class="dk-acts dk-start" aria-label="{{ __('story.proposal_switch') }}" data-test="story-proposal-switch">
                @foreach ($switchable as $option)
                    <a
                        @class(['dk-btn dk-sm', 'dk-primary' => $option->id === $embeddedId])
                        href="{{ $this->proposalStoryUrl($option->id) }}"
                        wire:navigate
                        wire:key="story-proposal-switch-{{ $option->id }}"
                        @if ($option->id === $embeddedId) aria-current="true" @endif
                        dir="auto"
                    >{{ $option->name }}</a>
                @endforeach
            </nav>
        @endif
    </div>

    {{-- The child reads only the id in mount() and loads its own proposal; the already loaded row is enough. --}}
    <livewire:pages::proposals.show :proposal="$this->attachedProposals->firstWhere('id', $embeddedId)" :embedded="true" :key="'story-proposal-'.$embeddedId" />
</div>
