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
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $review)
                <li wire:key="rv-{{ $review->id }}">
                    <div>
                        <strong>@if ($review->proposal !== null)<a href="{{ route('proposals.show', $review->proposal) }}" wire:navigate>{{ $review->proposal->name }}</a>@else — @endif</strong>
                        <span class="dk-is">{{ $review->stage->label() }} · {{ $review->slot->label() }}</span>
                    </div>
                    <span class="dk-small dk-muted dk-num" title="{{ $review->created_at?->translatedFormat(__('formats.date_long')) }}">{{ __('desk.asked_on', ['when' => \App\Support\Desk\RelativeDay::for($review->created_at)]) }}</span>
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
