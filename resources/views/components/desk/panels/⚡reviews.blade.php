<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\ProposalReviewer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * My reviews: the proposals and concept notes the viewer is named to
 * review (the My reviews page's own query), unread first.
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
            ->with('proposal:id,slug,name,status')
            ->orderByRaw('seen_at is not null')
            ->latest('id')
            ->get();
    }
};
?>

@php
    $rows = $this->rows;
    $unread = $rows->whereNull('seen_at')->count();
    $pg = $this->paginate($rows, 5);
@endphp

<x-desk.panel id="reviews" register="reviews.index" :size="$size" :index="$index" :total="$total" :count="$unread > 0 ? $unread : null">
    @if ($pg['total'] > 0)
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $review)
                <li wire:key="rv-{{ $review->id }}">
                    <div>
                        <strong>@if ($review->proposal !== null)<a href="{{ route('proposals.show', $review->proposal) }}" wire:navigate>{{ $review->proposal->name }}</a>@else — @endif</strong>
                        <span class="dk-is">{{ $review->stage->label() }} · {{ $review->slot->label() }}</span>
                    </div>
                    @if ($review->seen_at !== null)
                        <span class="dk-pill dk-good">{{ __('desk.read') }}</span>
                    @else
                        <span class="dk-pill dk-warn">{{ __('desk.not_read') }}</span>
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
