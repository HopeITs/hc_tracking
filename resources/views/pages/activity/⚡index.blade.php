<?php

use App\Models\Activity;
use App\Models\Program;
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
     * The org's pulse right now, independent of the filters below: today's
     * count, the last 7 days, and today's busiest module and person — the
     * wire desk's cross-check strip.
     *
     * @return array{today: int, week: int, module: array{label: string, count: int}|null, actor: array{name: string, count: int}|null}
     */
    #[Computed]
    public function pulse(): array
    {
        $todayStart = CarbonImmutable::today();

        $today = Activity::query()->where('created_at', '>=', $todayStart)->count();
        $week = Activity::query()->where('created_at', '>=', $todayStart->subDays(6))->count();

        $busiestModule = Activity::query()
            ->where('created_at', '>=', $todayStart)
            ->selectRaw('module, count(*) as total')
            ->groupBy('module')
            ->orderByDesc('total')
            ->first();

        $busiestActor = Activity::query()
            ->where('created_at', '>=', $todayStart)
            ->whereNotNull('actor_id')
            ->selectRaw('actor_id, count(*) as total')
            ->groupBy('actor_id')
            ->orderByDesc('total')
            ->first();

        $actorName = $busiestActor === null ? null : User::query()->whereKey($busiestActor->actor_id)->value('name');

        return [
            'today' => $today,
            'week' => $week,
            'module' => $busiestModule === null ? null : [
                'label' => app(NotificationPresenter::class)->moduleLabel($busiestModule->module),
                'count' => (int) $busiestModule->total,
            ],
            'actor' => $actorName === null ? null : [
                'name' => $actorName,
                'count' => (int) $busiestActor->total,
            ],
        ];
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
     * Program names for every activity on this page that is tied to one —
     * the thread that ties a delivery event or a funding event back to the
     * program behind it.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function programs(): array
    {
        $programIds = collect($this->activities->items())
            ->pluck('program_id')
            ->filter()
            ->unique()
            ->values();

        if ($programIds->isEmpty()) {
            return [];
        }

        return Program::query()->whereIn('id', $programIds)->pluck('name', 'id')->all();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function actorOptions(): Collection
    {
        return User::query()
            ->whereIn('id', Activity::query()->select('actor_id')->whereNotNull('actor_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The wire's accent family for a module: funding events run teal, a risk
     * runs warn amber, everything else runs the neutral program-delivery ink.
     */
    public function moduleTone(string $module): string
    {
        return match ($module) {
            'donors', 'proposals', 'funding-agreements', 'transfers', 'accounts' => 'funding',
            'risks' => 'warn',
            default => 'neutral',
        };
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}; ?>

@php
    $presenter = app(ActivityPresenter::class);
    $notificationPresenter = app(NotificationPresenter::class);
    $links = app(SubjectLinks::class);
    $moduleIcons = config('notifications.module_icons');

    $toneClasses = fn (string $tone): string => match ($tone) {
        'funding' => 'bg-funding-soft text-funding',
        'warn' => 'bg-status-warn-soft text-status-warn',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
    };

    $dotClasses = fn (string $tone): string => match ($tone) {
        'funding' => 'bg-funding',
        'warn' => 'bg-status-warn',
        default => 'bg-secondary-light',
    };
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="activity-log-heading">
    <x-ui.page-header heading-id="activity-log-heading" :title="__('activity.log_title')" :count="$this->activities->total()">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('activity.filters.search')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('activity.filters.module')" wire:model.live="module" :value="$module">
                <flux:select.option value="">{{ __('activity.filters.all_modules') }}</flux:select.option>
                @foreach (array_keys(config('notifications.modules')) as $option)
                    <flux:select.option :value="$option">{{ $notificationPresenter->moduleLabel($option) }}</flux:select.option>
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

    {{-- The cross-check strip: the org's pulse right now, read the way a control room
         sweeps its instruments, independent of whatever the wire below is filtered to. --}}
    @php
        $pulse = $this->pulse;
    @endphp
    <div
        role="group"
        aria-label="{{ __('activity.pulse.heading') }}"
        class="relative grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-secondary-dark bg-secondary-soft text-white shadow-[0_8px_24px_-12px_rgba(20,21,22,0.45)] @2xl/directory:grid-cols-4 dark:border-secondary-medium dark:bg-secondary-medium/60"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 opacity-[0.12]"
            style="background-image: radial-gradient(rgba(255,255,255,0.9) 1px, transparent 1px); background-size: 14px 14px;"
        ></div>

        <div class="relative bg-secondary-dark p-4">
            <p class="type-label text-secondary-soft">{{ __('activity.pulse.today') }}</p>
            <p class="type-figure mt-2 text-white">{{ $pulse['today'] }}</p>
        </div>

        <div class="relative bg-secondary-dark p-4">
            <p class="type-label text-secondary-soft">{{ __('activity.pulse.week') }}</p>
            <p class="type-figure mt-2 text-white">{{ $pulse['week'] }}</p>
        </div>

        <div class="relative bg-secondary-dark p-4">
            <p class="type-label text-secondary-soft">{{ __('activity.pulse.busiest_module') }}</p>
            @if ($pulse['module'] !== null)
                <p class="mt-2 flex items-baseline gap-1.5">
                    <span class="type-heading truncate text-white">{{ $pulse['module']['label'] }}</span>
                    <span class="type-meta shrink-0 tabular-nums text-status-critical">{{ $pulse['module']['count'] }}</span>
                </p>
            @else
                <p class="type-heading mt-2 text-secondary-soft">{{ __('activity.pulse.quiet') }}</p>
            @endif
        </div>

        <div class="relative bg-secondary-dark p-4">
            <p class="type-label text-secondary-soft">{{ __('activity.pulse.top_actor') }}</p>
            @if ($pulse['actor'] !== null)
                <p class="mt-2 flex items-baseline gap-1.5">
                    <span class="type-heading truncate text-white">{{ $pulse['actor']['name'] }}</span>
                    <span class="type-meta shrink-0 tabular-nums text-status-critical">{{ $pulse['actor']['count'] }}</span>
                </p>
            @else
                <p class="type-heading mt-2 text-secondary-soft">{{ __('activity.pulse.quiet') }}</p>
            @endif
        </div>
    </div>

    @if ($this->activities->isEmpty())
        <x-ui.empty-state icon="signal" :heading="__('activity.empty')" />
    @else
        @php
            $days = collect($this->activities->items())->groupBy(fn ($activity) => $activity->created_at?->toDateString());
            $today = CarbonImmutable::today()->toDateString();
            $yesterday = CarbonImmutable::yesterday()->toDateString();
        @endphp

        <div class="flex flex-col" wire:loading.class="opacity-50" wire:target="module, actor, search, from, to, previousPage, nextPage, gotoPage">
            @foreach ($days as $day => $dayActivities)
                @php
                    $dayLabel = match ($day) {
                        $today => __('notifications.sections.today'),
                        $yesterday => __('notifications.sections.yesterday'),
                        default => $dayActivities->first()->created_at?->translatedFormat(__('formats.date_wire')),
                    };
                @endphp

                <div class="flex items-center gap-3 py-2.5">
                    <p class="type-label m-0 shrink-0 text-secondary-dark dark:text-white">{{ $dayLabel }}</p>
                    <span class="h-px min-w-0 flex-1 bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                    <span class="type-meta shrink-0 tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $dayActivities->count() }}</span>
                </div>

                <ol class="flex flex-col divide-y divide-secondary-soft border-b border-secondary-soft pb-2 dark:divide-secondary-medium dark:border-secondary-medium">
                    @foreach ($dayActivities as $activity)
                        @php
                            $tone = $this->moduleTone($activity->module);
                            $exists = in_array($activity->subject_id, $this->existing[$activity->subject_type] ?? [], true);
                            $url = $exists ? $links->routeFor($activity->subject_type, $activity->subject_id) : null;
                            $programName = $activity->program_id !== null ? ($this->programs[$activity->program_id] ?? null) : null;
                        @endphp
                        <li class="group flex items-start gap-3 py-3 @3xl/directory:items-center @3xl/directory:gap-4" wire:key="log-{{ $activity->id }}">
                            <time
                                datetime="{{ $activity->created_at?->toIso8601String() }}"
                                class="type-meta hidden w-14 shrink-0 tabular-nums text-secondary-medium dark:text-secondary-soft @3xl/directory:block"
                            >
                                {{ $activity->created_at?->format('H:i') }}
                            </time>

                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $toneClasses($tone) }}">
                                <flux:icon :name="$moduleIcons[$activity->module] ?? 'bell'" class="size-4" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm leading-snug font-medium">{{ $presenter->sentence($activity) }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-secondary-medium dark:text-secondary-soft">
                                    <span class="tabular-nums @3xl/directory:hidden">{{ $activity->created_at?->format('H:i') }}</span>
                                    <span aria-hidden="true" class="@3xl/directory:hidden">·</span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="size-1.5 shrink-0 rounded-full {{ $dotClasses($tone) }}" aria-hidden="true"></span>
                                        {{ $notificationPresenter->moduleLabel($activity->module) }}
                                    </span>
                                    @if ($programName !== null)
                                        <span aria-hidden="true">·</span>
                                        <a href="{{ route('programs.show', $activity->program_id) }}" wire:navigate class="inline-flex min-w-0 items-center gap-1 truncate font-medium text-secondary-dark hover:text-status-critical hover:underline dark:text-white">
                                            <flux:icon name="link" class="size-3 shrink-0" />
                                            <span class="truncate">{{ __('activity.linked_program', ['program' => $programName]) }}</span>
                                        </a>
                                    @endif
                                    @if ($url !== null)
                                        <span aria-hidden="true" class="@3xl/directory:hidden">·</span>
                                        <a href="{{ $url }}" wire:navigate class="font-semibold text-status-critical hover:underline @3xl/directory:hidden">{{ __('activity.open_record') }}</a>
                                    @elseif (! $exists)
                                        <span aria-hidden="true" class="@3xl/directory:hidden">·</span>
                                        <span class="@3xl/directory:hidden">{{ __('activity.record_gone') }}</span>
                                    @endif
                                </p>
                            </div>

                            <div class="hidden shrink-0 text-xs @3xl/directory:block">
                                @if ($url !== null)
                                    <a href="{{ $url }}" wire:navigate class="font-semibold text-status-critical hover:underline">{{ __('activity.open_record') }}</a>
                                @elseif (! $exists)
                                    <span class="text-secondary-medium dark:text-secondary-soft">{{ __('activity.record_gone') }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endforeach
        </div>

        <flux:pagination :paginator="$this->activities" />
    @endif
</section>
