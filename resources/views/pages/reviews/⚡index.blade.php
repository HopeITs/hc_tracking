<?php

use App\Models\HrEmployee;
use App\Models\ProposalReviewer;
use App\Support\ArabicText;
use App\Support\Desk\DeskWork;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.my_reviews')] class extends Component {
    /**
     * A review waiting longer than this many days turns red, the same line
     * the Desk's My reviews panel draws.
     */
    public const int LongWaitDays = 7;

    /** awaiting (waiting on me), done (mine that no longer wait) or asked (reviews I wait on). */
    #[Url(except: 'awaiting')]
    public string $state = 'awaiting';

    #[Url(except: '')]
    public string $search = '';

    /**
     * The button pressed; an unknown value from a stale link (the old "all")
     * reads as awaiting.
     */
    #[Computed]
    public function mode(): string
    {
        return in_array($this->state, ['done', 'asked'], true) ? $this->state : 'awaiting';
    }

    public function setState(string $state): void
    {
        $this->state = in_array($state, ['done', 'asked'], true) ? $state : 'awaiting';
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    /**
     * Every review asked of the viewer that matches the search. Waiting and
     * done split it with no overlap.
     *
     * @return Collection<int, ProposalReviewer>
     */
    #[Computed]
    public function mine(): Collection
    {
        $employeeId = auth()->user()?->employee_id;

        return $employeeId === null
            ? new Collection
            : $this->searched(ProposalReviewer::query()->forEmployee((int) $employeeId));
    }

    /**
     * Other people's unreviewed rows on proposals of the viewer's projects,
     * exactly the reviews the Desk's Waiting on others panel lists
     * (DeskWork::waitingReviews), matching the search.
     *
     * @return Collection<int, ProposalReviewer>
     */
    #[Computed]
    public function asked(): Collection
    {
        $user = auth()->user();

        return $user?->employee_id === null ? new Collection : $this->searched(DeskWork::waitingReviews($user));
    }

    /**
     * How many sit behind each button. They follow the search but not the
     * pressed button, so a click shows exactly that many.
     *
     * @return array{awaiting: int, done: int, asked: int}
     */
    #[Computed]
    public function counts(): array
    {
        $awaiting = $this->mine->filter(fn (ProposalReviewer $review): bool => $review->isAwaitingReview($review->proposal))->count();

        return ['awaiting' => $awaiting, 'done' => $this->mine->count() - $awaiting, 'asked' => $this->asked->count()];
    }

    /**
     * The rows of the pressed button, oldest ask first (as on the Desk).
     * Anyone may open the page: it never reaches beyond the viewer's own
     * rows, and each proposal opens through ProposalPolicy.
     *
     * @return Collection<int, ProposalReviewer>
     */
    #[Computed]
    public function reviews(): Collection
    {
        if ($this->mode === 'asked') {
            return $this->asked;
        }

        return $this->mine
            ->filter(fn (ProposalReviewer $review): bool => $review->isAwaitingReview($review->proposal) === ($this->mode === 'awaiting'))
            ->values();
    }

    /**
     * The reviewers' names on Asked by me, one HR query.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function reviewerNames(): Collection
    {
        if ($this->mode !== 'asked' || $this->reviews->isEmpty()) {
            return new Collection;
        }

        return HrEmployee::query()
            ->whereKey($this->reviews->pluck('employee_id')->unique()->values()->all())
            ->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);
    }

    /**
     * Load a query oldest ask first with its proposal and projects, then keep
     * the rows whose proposal or project name holds every search word, read
     * as the other pages read it (ArabicText::containsTyped: tashkeel, alef
     * forms and the wrong keyboard layout do not matter).
     *
     * @param  Builder<ProposalReviewer>  $query
     * @return Collection<int, ProposalReviewer>
     */
    private function searched(Builder $query): Collection
    {
        $term = Like::term($this->search);

        return $query
            ->with(['proposal:id,slug,name,status', 'proposal.projects:projects.id,projects.slug,projects.name'])
            ->oldest('created_at')
            ->oldest('id')
            ->get()
            ->when($term !== '', fn (Collection $reviews) => $reviews->filter(fn (ProposalReviewer $review): bool => $review->proposal->projects
                ->pluck('name')
                ->prepend($review->proposal->name)
                ->contains(fn (string $name): bool => ArabicText::containsTyped($name, $term))))
            ->values();
    }
};
?>

@php
    $mode = $this->mode;
    $asked = $mode === 'asked';
    $viewer = auth()->user();
    $today = \Carbon\CarbonImmutable::today();
    $counts = $this->counts;
    $tabs = [
        ['key' => 'awaiting', 'label' => __('proposals.my_reviews_awaiting'), 'tone' => 'text-status-critical'],
        ['key' => 'done', 'label' => __('proposals.my_reviews_done'), 'tone' => 'text-status-good'],
        ['key' => 'asked', 'label' => __('proposals.my_reviews_asked'), 'tone' => 'text-secondary-dark dark:text-ink'],
    ];
@endphp

<x-desk.register
    class="@container/directory"
    heading-id="my-reviews-heading"
    :title="__('titles.my_reviews')"
    :count="$this->reviews->count()"
    data-my-reviews
>
    <x-slot:search>
        <x-desk.register-search :label="__('proposals.my_reviews_search')" :placeholder="__('proposals.my_reviews_search_placeholder')" wire:model.live.debounce.300ms="search" data-test="review-search" />
    </x-slot:search>

    <div class="mb-4 flex flex-wrap items-stretch gap-2" data-test="review-tabs">
        @foreach ($tabs as $tab)
            <button
                type="button"
                wire:click="setState('{{ $tab['key'] }}')"
                wire:key="review-tab-{{ $tab['key'] }}"
                aria-pressed="{{ $mode === $tab['key'] ? 'true' : 'false' }}"
                data-test="review-tab-{{ $tab['key'] }}"
                @class([
                    'flex min-h-14 flex-1 basis-40 cursor-pointer items-center gap-3 rounded-xl border px-4 py-2 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                    'border-secondary-dark bg-white shadow-card ring-1 ring-secondary-dark dark:border-ink dark:bg-surface-2 dark:ring-ink' => $mode === $tab['key'],
                    'border-secondary-soft bg-white hover:border-secondary-light dark:border-line dark:bg-surface-1' => $mode !== $tab['key'],
                ])
            >
                <span class="text-2xl font-bold leading-none tabular-nums {{ $tab['tone'] }}">{{ $counts[$tab['key']] }}</span>
                <span class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ $tab['label'] }}</span>
            </button>
        @endforeach
    </div>

    @if ($this->reviews->isEmpty())
        @if (trim($search) !== '')
            <x-ui.empty-state icon="magnifying-glass" :heading="__('desk.register.nothing_matches')" :body="__('proposals.my_reviews_search_empty_body')">
                <x-slot:action>
                    <x-ui.button tone="ghost" size="sm" wire:click="clearSearch" data-test="reset-filters">{{ __('common.reset_filters') }}</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state
                icon="check-circle"
                :heading="match ($mode) { 'done' => __('proposals.my_reviews_none_done'), 'asked' => __('proposals.my_reviews_none_asked'), default => __('proposals.my_reviews_none_awaiting') }"
                :body="$asked ? __('proposals.my_reviews_asked_empty_body') : __('proposals.my_reviews_empty_body')"
            />
        @endif
    @else
        <x-ui.data-table :caption="__('titles.my_reviews')">
            <x-slot:head>
                <th>{{ __('common.proposal') }}</th>
                <th class="w-48!">{{ __('proposals.my_reviews_stage') }}</th>
                <th class="w-28!">{{ __('proposals.my_reviews_waiting') }}</th>
                <th class="w-44!">{{ $asked ? __('proposals.my_reviews_reviewer') : __('proposals.my_reviews_your_review') }}</th>
                <th class="w-28!"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->reviews as $review)
                @php
                    $isAwaiting = $review->isAwaitingReview($review->proposal);
                    $project = $review->proposal->projects->sortBy('id')->first();
                    $waitedDays = $review->created_at === null ? 0 : (int) $review->created_at->startOfDay()->diffInDays($today);
                    $isLong = $isAwaiting && $waitedDays > $this::LongWaitDays;
                    $askedOn = $review->created_at?->translatedFormat(__('formats.date'));
                @endphp
                <tr wire:key="review-{{ $review->id }}" data-review-row="{{ $isAwaiting ? 'awaiting' : 'done' }}" @if ($isLong) data-review-long @endif>
                    <x-ui.data-table.cell>
                        <span class="block font-semibold" dir="auto">{{ $review->proposal->name }}</span>
                        @if ($project !== null)
                            <span class="mt-0.5 block text-xs text-secondary-medium dark:text-ink-muted" dir="auto" data-review-project>{{ $project->name }}</span>
                        @endif
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell :label="__('proposals.my_reviews_stage')">
                        {{ $review->stage->reviewStatus()->label() }}
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell :label="__('proposals.my_reviews_waiting')">
                        @if ($isAwaiting)
                            <time datetime="{{ $review->created_at?->toIso8601String() }}" title="{{ __('proposals.my_reviews_asked_on_date', ['date' => $askedOn]) }}" @class(['whitespace-nowrap tabular-nums', 'inline-flex rounded-full bg-status-critical-soft px-2 py-0.5 text-xs font-bold text-status-critical dark:bg-brand dark:text-white' => $isLong]) data-review-waiting="{{ $waitedDays }}">{{ trans_choice('proposals.my_reviews_waiting_days', $waitedDays, ['count' => $waitedDays]) }}</time>
                        @else
                            <span class="text-secondary-medium dark:text-ink-muted" title="{{ __('proposals.my_reviews_asked_on_date', ['date' => $askedOn]) }}">—</span>
                        @endif
                    </x-ui.data-table.cell>

                    <x-ui.data-table.cell :label="$asked ? __('proposals.my_reviews_reviewer') : __('proposals.my_reviews_your_review')">
                        @if ($asked)
                            <span class="block font-medium" dir="auto"><bdi>{{ $this->reviewerNames->get($review->employee_id, '—') }}</bdi></span>
                            <span class="block text-xs text-secondary-medium dark:text-ink-muted">{{ $isAwaiting ? __('proposals.reviewer_not_seen') : __('proposals.my_reviews_closed') }}</span>
                        @elseif ($isAwaiting)
                            {{-- Dark red text on the dark ground reads under 3:1, so a long wait fills the pill in dark mode. --}}
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold whitespace-nowrap',
                                'bg-status-critical-soft text-status-critical dark:bg-brand dark:text-white' => $isLong,
                                'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => ! $isLong,
                            ])>{{ __('proposals.my_reviews_waiting_on_you') }}</span>
                        @elseif ($review->seen_at !== null)
                            <span class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.reviewer_seen_on', ['date' => $review->seen_at->translatedFormat(__('formats.date'))]) }}</span>
                        @else
                            <span class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.my_reviews_closed') }}</span>
                        @endif
                    </x-ui.data-table.cell>

                    {{-- A text button, not the register eye (user decision 2026-11-03): reviewing is this page's one job. --}}
                    <x-ui.data-table.cell class="@3xl:text-end @max-3xl:[&>span]:w-full">
                        @if ($asked)
                            <x-ui.button size="sm" class="min-h-10 touch-manipulation @max-3xl:w-full" :href="\App\Support\ProposalStoryLink::reviewers($viewer, $review->proposal)" wire:navigate :aria-label="__('proposals.my_reviews_open_name', ['name' => $review->proposal->name])" data-review-action>{{ __('proposals.my_reviews_open') }}</x-ui.button>
                        @elseif ($isAwaiting)
                            <x-ui.button tone="primary" size="sm" class="min-h-10 touch-manipulation @max-3xl:w-full" :href="\App\Support\ProposalStoryLink::review($viewer, $review->proposal)" wire:navigate :aria-label="__('proposals.my_reviews_review_name', ['name' => $review->proposal->name])" data-review-action>{{ __('desk.review_action') }}</x-ui.button>
                        @else
                            <x-ui.button size="sm" class="min-h-10 touch-manipulation @max-3xl:w-full" :href="\App\Support\ProposalStoryLink::review($viewer, $review->proposal, waiting: false)" wire:navigate :aria-label="__('proposals.my_reviews_open_name', ['name' => $review->proposal->name])" data-review-action>{{ __('proposals.my_reviews_open') }}</x-ui.button>
                        @endif
                    </x-ui.data-table.cell>
                </tr>
            @endforeach
        </x-ui.data-table>
    @endif
</x-desk.register>
