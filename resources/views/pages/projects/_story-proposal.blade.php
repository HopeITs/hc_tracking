{{--
    The chosen proposal, embedded under its row on the project's Funding tab
    (pages::proposals.show in embedded mode), so every action and dialog it
    had works here. It opens compact (lifecycle, attention list, vitals);
    a deep link with ?ptab= opens it in full on that tab. The proposal rows
    above it are the switcher (?proposal=).
--}}
@php
    $embeddedId = $this->embeddedProposalId;
@endphp

<div class="dk-story-proposal" id="proposal" data-test="story-proposal" data-proposal-id="{{ $embeddedId }}">
    {{-- The child reads only the id in mount() and loads its own proposal; the already loaded row is enough. --}}
    <livewire:pages::proposals.show :proposal="$this->attachedProposals->firstWhere('id', $embeddedId)" :embedded="true" :compact="request()->query('ptab') === null" :key="'story-proposal-'.$embeddedId" />
</div>
