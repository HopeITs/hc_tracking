<?php

use App\Models\ProposalReviewer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.my_reviews')] class extends Component {
    #[Url(except: 'awaiting')]
    public string $state = 'awaiting';

    /**
     * The viewer's own reviewer rows, the ones still waiting on them first,
     * then newest first. Anyone may open the page: it never reaches beyond
     * the viewer's own rows, and each proposal opens through ProposalPolicy.
     *
     * @return Collection<int, ProposalReviewer>
     */
    #[Computed]
    public function reviews(): Collection
    {
        $employeeId = auth()->user()?->employee_id;

        if ($employeeId === null) {
            return new Collection;
        }

        return ProposalReviewer::query()
            ->forEmployee((int) $employeeId)
            ->when($this->state !== 'all', fn ($query) => $query->awaitingReview())
            ->with('proposal:id,slug,name,status')
            ->latest('id')
            ->get()
            ->sortBy(fn (ProposalReviewer $review): int => $review->isAwaitingReview($review->proposal) ? 0 : 1)
            ->values();
    }
};
?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="my-reviews-heading" data-my-reviews>
    <x-ui.page-header heading-id="my-reviews-heading" :title="__('titles.my_reviews')" :count="$this->reviews->count()">
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('common.state')" wire:model.live="state" :value="$state === 'all' ? 'all' : 'awaiting'">
                <option value="awaiting">{{ __('proposals.my_reviews_awaiting') }}</option>
                <option value="all">{{ __('proposals.my_reviews_all') }}</option>
            </x-ui.header-filter-select>
        </x-slot:filters>
    </x-ui.page-header>

    @if ($this->reviews->isEmpty())
        <x-ui.empty-state
            icon="check-circle"
            :heading="$state === 'all' ? __('proposals.my_reviews_none') : __('proposals.my_reviews_none_awaiting')"
            :body="__('proposals.my_reviews_empty_body')"
        />
    @else
        <x-ui.data-table :caption="__('titles.my_reviews')">
            <x-slot:head>
                <th>{{ __('common.proposal') }}</th>
                <th class="w-40!">{{ __('proposals.my_reviews_stage') }}</th>
                <th class="w-44!">{{ __('common.status') }}</th>
                <th class="w-36!">{{ __('proposals.my_reviews_asked_on') }}</th>
                <th class="w-44!">{{ __('common.state') }}</th>
                <th class="w-14!"></th>
            </x-slot:head>

            @foreach ($this->reviews as $review)
                @php
                    $isAwaiting = $review->isAwaitingReview($review->proposal);
                @endphp
                <tr wire:key="review-{{ $review->id }}" data-review-row="{{ $isAwaiting ? 'awaiting' : 'done' }}">
                    <x-ui.data-table.cell>
                        <span class="font-semibold">{{ $review->proposal->name }}</span>
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell :label="__('proposals.my_reviews_stage')">
                        {{ $review->stage->label() }}
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell :label="__('common.status')">
                        <x-ui.enum-badge :case="$review->proposal->status" />
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell :label="__('proposals.my_reviews_asked_on')">
                        <time datetime="{{ $review->created_at?->toIso8601String() }}" class="whitespace-nowrap">{{ $review->created_at?->translatedFormat(__('formats.date')) }}</time>
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell :label="__('common.state')">
                        @if ($isAwaiting)
                            <span class="inline-flex items-center rounded-full bg-status-critical-soft px-2 py-0.5 text-[11px] font-bold whitespace-nowrap text-primary dark:text-status-critical">{{ __('proposals.my_reviews_waiting_on_you') }}</span>
                        @elseif ($review->seen_at !== null)
                            <span class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.reviewer_seen_on', ['date' => $review->seen_at->translatedFormat(__('formats.date'))]) }}</span>
                        @else
                            <span class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.my_reviews_closed') }}</span>
                        @endif
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell class="@3xl:text-end">
                        <x-ui.button tone="ghost" size="sm" icon="eye" :href="route('proposals.show', $review->proposal)" wire:navigate :aria-label="__('common.view_name', ['name' => $review->proposal->name])" />
                    </x-ui.data-table.cell>
                </tr>
            @endforeach
        </x-ui.data-table>
    @endif
</section>
