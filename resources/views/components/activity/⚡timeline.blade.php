<?php

use App\Models\Activity;
use App\Notifications\ActivityPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /**
     * Morph key => record ids whose activity this timeline shows.
     *
     * @var array<string, list<int>>
     */
    #[Locked]
    public array $subjects = [];

    public int $limit = 20;

    /**
     * One row more than shown, so "load more" needs no count query.
     *
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function entries(): Collection
    {
        $subjects = array_filter($this->subjects);

        if ($subjects === []) {
            return collect();
        }

        return Activity::query()
            ->where(function (Builder $query) use ($subjects): void {
                foreach ($subjects as $type => $ids) {
                    $query->orWhere(fn (Builder $query) => $query->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            })
            ->with('actor:id,hr_user_id')
            ->latest('id')
            ->limit($this->limit + 1)
            ->get();
    }

    public function loadMore(): void
    {
        $this->limit += 20;
    }
}; ?>

@php
    $presenter = app(ActivityPresenter::class);
@endphp

<div data-activity-timeline>
    @if ($this->entries->isEmpty())
        <p class="py-6 text-sm text-secondary-medium dark:text-ink-muted">{{ __('activity.empty') }}</p>
    @else
        <ol class="relative ms-1.5 border-s border-secondary-soft dark:border-line">
            @foreach ($this->entries->take($limit) as $activity)
                <li class="relative ps-5 pb-5 last:pb-0" wire:key="activity-{{ $activity->id }}">
                    <span @class([
                        'absolute -start-[5px] top-1.5 size-2.5 rounded-full ring-4 ring-white dark:ring-surface-1',
                        'bg-primary dark:bg-ink' => $activity->shouldNotify(),
                        'bg-secondary-light dark:bg-ink-faint' => ! $activity->shouldNotify(),
                    ]) aria-hidden="true"></span>
                    <p class="text-sm font-semibold leading-snug">{{ $presenter->sentence($activity) }}</p>
                    <p class="mt-0.5 text-xs text-secondary-medium dark:text-ink-muted">
                        <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->translatedFormat(__('formats.date')) }} · {{ $activity->created_at?->format('H:i') }}</time>
                    </p>
                    @if ($activity->changes !== [])
                        <dl class="mt-2 grid gap-1 text-xs">
                            @foreach ($presenter->changes($activity) as $change)
                                <div class="flex flex-wrap gap-x-1.5">
                                    <dt class="font-semibold text-secondary-dark dark:text-ink">{{ $change['field'] }}:</dt>
                                    <dd class="text-secondary-medium dark:text-ink-muted">
                                        <span class="line-through decoration-secondary-light">{{ $change['from'] }}</span>
                                        <span class="inline-block rtl:-scale-x-100" aria-hidden="true">→</span>
                                        <span class="sr-only">{{ __('activity.changed_to') }}</span>
                                        <span class="font-medium text-secondary-dark dark:text-ink">{{ $change['to'] }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </li>
            @endforeach
        </ol>

        @if ($this->entries->count() > $limit)
            <button type="button" wire:click="loadMore" class="mt-3 text-sm font-semibold text-status-critical hover:underline" data-timeline-more>{{ __('activity.load_more') }}</button>
        @endif
    @endif
</div>
