<?php

use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityPresenter;
use App\Notifications\NotificationPresenter;
use App\Notifications\SubjectLinks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.activity_log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $module = '';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['module', 'actor', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Activity::query()
            ->with('actor:id,hr_user_id')
            ->when($this->module !== '', fn (Builder $query) => $query->where('module', $this->module))
            ->when(ctype_digit($this->actor), fn (Builder $query) => $query->where('actor_id', (int) $this->actor))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('event', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('properties', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($this->validDate($this->from), fn (Builder $query) => $query->where('created_at', '>=', CarbonImmutable::parse($this->from)->startOfDay()))
            ->when($this->validDate($this->to), fn (Builder $query) => $query->where('created_at', '<=', CarbonImmutable::parse($this->to)->endOfDay()))
            ->latest('id')
            ->paginate(50);
    }

    /**
     * Records on this page that still exist, one query per type.
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function existing(): array
    {
        $idsByType = [];

        foreach ($this->activities->items() as $activity) {
            $idsByType[$activity->subject_type][] = $activity->subject_id;
        }

        return app(SubjectLinks::class)->existing($idsByType);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function actorOptions(): Collection
    {
        return User::query()
            ->whereIn('id', Activity::query()->select('actor_id')->whereNotNull('actor_id')->distinct())
            ->orderByName()
            ->get(['id', 'hr_user_id']);
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}; ?>

@php
    $presenter = app(ActivityPresenter::class);
    $links = app(SubjectLinks::class);
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="activity-log-heading">
    <x-ui.page-header heading-id="activity-log-heading" :title="__('activity.log_title')" :count="$this->activities->total()">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('activity.filters.search')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('activity.filters.module')" wire:model.live="module" :value="$module">
                <flux:select.option value="">{{ __('activity.filters.all_modules') }}</flux:select.option>
                @foreach (array_keys(config('notifications.modules')) as $option)
                    <flux:select.option :value="$option">{{ app(NotificationPresenter::class)->moduleLabel($option) }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('activity.filters.actor')" wire:model.live="actor" :value="$actor" searchable>
                <flux:select.option value="">{{ __('activity.filters.all_actors') }}</flux:select.option>
                @foreach ($this->actorOptions as $person)
                    <flux:select.option :value="(string) $person->id" :wire:key="'activity-actor-'.$person->id">{{ $person->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.input type="date" :label="__('activity.filters.from')" wire:model.live="from" />
            <x-ui.input type="date" :label="__('activity.filters.to')" wire:model.live="to" />
        </x-slot:filters>
    </x-ui.page-header>

    @if ($this->activities->isEmpty())
        <x-ui.empty-state icon="clock" :heading="__('activity.empty')" />
    @else
        <ol class="relative ms-1.5 border-s border-secondary-soft dark:border-secondary-medium">
            @foreach ($this->activities as $activity)
                @php
                    $exists = in_array($activity->subject_id, $this->existing[$activity->subject_type] ?? [], true);
                    $url = $exists ? $links->routeFor($activity->subject_type, $activity->subject_id) : null;
                @endphp
                <li class="relative grid gap-1 ps-5 pb-5 @3xl/directory:grid-cols-[minmax(0,1fr)_auto]" wire:key="log-{{ $activity->id }}">
                    <span class="absolute -start-[5px] top-1.5 size-2.5 rounded-full bg-primary ring-4 ring-white dark:ring-secondary-dark" aria-hidden="true"></span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold leading-snug">{{ $presenter->sentence($activity) }}</p>
                        <p class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-secondary-medium dark:text-secondary-soft">
                            <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->translatedFormat(__('formats.date')) }} · {{ $activity->created_at?->format('H:i') }}</time>
                            <span aria-hidden="true">·</span>
                            <span>{{ app(NotificationPresenter::class)->moduleLabel($activity->module) }}</span>
                        </p>
                    </div>
                    <div class="text-xs">
                        @if ($url !== null)
                            <a href="{{ $url }}" wire:navigate class="font-semibold text-status-critical hover:underline">{{ __('activity.open_record') }}</a>
                        @elseif (! $exists)
                            <span class="text-secondary-medium dark:text-secondary-soft">{{ __('activity.record_gone') }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        <flux:pagination :paginator="$this->activities" />
    @endif
</section>
