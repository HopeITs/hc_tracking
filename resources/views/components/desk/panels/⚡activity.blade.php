<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Activity;
use App\Notifications\ActivityPresenter;
use App\Notifications\NotificationPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * What happened: the latest changes from the activity log (the same
 * records the activity page lists to activity-log.view holders), narrowed
 * to one module from the picker. Money modules (proposals, donors,
 * agreements, transfers, accounts, donor reports) are left out for anyone
 * who may not view them, as the artifact does.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * Money module => the module whose view permission opens it.
     */
    private const array MoneyModules = [
        'proposals' => 'proposals',
        'donors' => 'donors',
        'funding-agreements' => 'funding-agreements',
        'donor-reports' => 'funding-agreements',
        'transfers' => 'transfers',
        'accounts' => 'accounts',
    ];

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function rows(): Collection
    {
        return Activity::query()
            ->with('actor:id,hr_user_id')
            ->whereNotIn('module', $this->hiddenModules())
            ->when($this->filter !== 'all', fn (Builder $query) => $query->where('module', $this->filter))
            ->latest()
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function modules(): array
    {
        return array_values(Activity::query()->whereNotIn('module', $this->hiddenModules())->distinct()->orderBy('module')->pluck('module')->map(fn ($module): string => (string) $module)->all());
    }

    /**
     * @return list<string>
     */
    private function hiddenModules(): array
    {
        return array_keys(array_filter(self::MoneyModules, fn (string $permission): bool => ! $this->canView($permission)));
    }
};
?>

@php
    $pg = $this->paginate($this->rows, 6);
    $presenter = app(ActivityPresenter::class);
    $modulePresenter = app(NotificationPresenter::class);
@endphp

<x-desk.panel id="activity" register="activity.index" :size="$size" :index="$index" :total="$total">
    <x-slot:tools>
        <label class="dk-sr" for="ps-activity-f">{{ __('desk.module') }}</label>
        <select class="dk-mini" id="ps-activity-f" wire:change="setFilter($event.target.value)">
            <option value="all" @selected($filter === 'all')>{{ __('desk.all_modules') }}</option>
            @foreach ($this->modules as $module)
                <option value="{{ $module }}" @selected($filter === $module)>{{ $modulePresenter->moduleLabel($module) }}</option>
            @endforeach
        </select>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ol class="dk-hist">
            @foreach ($pg['slice'] as $activity)
                <li wire:key="a-{{ $activity->id }}">
                    <span class="dk-av dk-sm">{{ $activity->actor?->initials() ?? '·' }}</span>
                    <span>{{ $presenter->sentence($activity) }}<span class="dk-is"> · {{ \App\Support\Desk\RelativeDay::for($activity->created_at) }}</span></span>
                </li>
            @endforeach
        </ol>
    @else
        <p class="dk-pempty">{{ __('desk.panel.activity.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
