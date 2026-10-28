<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\ProposalReviewer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * My reviews: the proposals and concept notes still waiting on the viewer's
 * own review (ProposalReviewer::awaitingReview: not marked seen, and the
 * proposal is still in that stage), one row per proposal, oldest ask first.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, ProposalReviewer>
     */
    #[Computed]
    public function rows(): Collection
    {
        $employeeId = auth()->user()->employee_id;

        if ($employeeId === null) {
            return new Collection;
        }

        return ProposalReviewer::query()
            ->forEmployee((int) $employeeId)
            ->awaitingReview()
            ->with('proposal:id,slug,name,status')
            ->oldest('created_at')
            ->get()
            ->unique('proposal_id')
            ->values();
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="reviews" register="reviews.index" :size="$size" :index="$index" :total="$total" :count="$rows->isNotEmpty() ? $rows->count() : null">
    @if ($pg['total'] > 0)
        <ul class="dk-rv">
            @foreach ($pg['slice'] as $review)
                @php
                    $waited = $review->created_at === null ? 0 : (int) $review->created_at->startOfDay()->diffInDays(\Carbon\CarbonImmutable::today());
                    $long = $waited > 7;
                @endphp
                <li wire:key="rv-{{ $review->id }}" @class(['dk-long' => $long])>
                    <span class="dk-rv-n" title="{{ $review->proposal?->name }}">{{ $review->proposal?->name ?? '—' }}</span>
                    <span class="dk-pill dk-act dk-rv-s">{{ $review->stage->label() }}</span>
                    <span @class(['dk-rv-m dk-is', 'dk-bad' => $long]) title="{{ $review->created_at?->translatedFormat(__('formats.date_long')) }}">{{ __('desk.asked_on', ['when' => \App\Support\Desk\RelativeDay::for($review->created_at)]) }}@if ($long) · {{ __('desk.review_waiting_long') }}@endif · {{ $review->slot->label() }}</span>
                    @if ($review->proposal !== null)
                        <a href="{{ route('proposals.show', $review->proposal) }}" wire:navigate @class(['dk-btn dk-sm dk-rv-a', 'dk-primary' => $long])>{{ __('desk.review_action') }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.reviews.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
