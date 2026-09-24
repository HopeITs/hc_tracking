<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Livewire\Concerns\ManagesInbox;
use App\Models\UserNotification;
use App\Notifications\Inbox;
use App\Notifications\NotificationPresenter;
use App\Notifications\UnreadCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.notifications')] class extends Component {
    use ManagesInbox;

    private const PageSize = 25;

    /** How many unread critical notifications the attention block holds at most. */
    private const PinnedLimit = 20;

    #[Url(except: '')]
    public string $module = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: 'all')]
    public string $state = 'all';

    #[Url(except: '')]
    public string $search = '';

    public int $perPage = self::PageSize;

    /** @var list<string> */
    public array $selected = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['module', 'kind', 'priority', 'state', 'search'], true)) {
            $this->perPage = self::PageSize;
            $this->selected = [];
        }
    }

    /**
     * @return Builder<UserNotification>
     */
    private function filteredQuery(): Builder
    {
        $term = trim($this->search);

        return UserNotification::query()
            ->forUser(auth()->user())
            ->when(
                $this->state === 'snoozed',
                fn (Builder $query) => $query->where('snoozed_until', '>', now()),
                fn (Builder $query) => $query->visible(),
            )
            ->when($this->module !== '', fn (Builder $query) => $query->where('module', $this->module))
            ->when(in_array($this->kind, NotificationKind::values(), true), fn (Builder $query) => $query->where('kind', $this->kind))
            ->when(in_array($this->priority, NotificationPriority::values(), true), fn (Builder $query) => $query->where('priority', $this->priority))
            ->when($this->state === 'unread', fn (Builder $query) => $query->whereNull('read_at'))
            ->when($this->state === 'read', fn (Builder $query) => $query->whereNotNull('read_at'))
            ->when($term !== '', fn (Builder $query) => $query->where('search_text', 'like', '%'.addcslashes($term, '%_\\').'%'));
    }

    /**
     * Unread critical rows lift out of the timeline into their own block,
     * unless the reader has already narrowed by priority or is looking at
     * read or snoozed rows.
     */
    #[Computed]
    public function showsPinned(): bool
    {
        return in_array($this->state, ['all', 'unread'], true) && $this->priority === '';
    }

    /**
     * @return Collection<int, UserNotification>
     */
    #[Computed]
    public function pinned(): Collection
    {
        if (! $this->showsPinned) {
            return collect();
        }

        return $this->filteredQuery()
            ->whereNull('read_at')
            ->where('priority', NotificationPriority::Critical->value)
            ->with('actor:id,name')
            ->latest()
            ->limit(self::PinnedLimit)
            ->get();
    }

    /**
     * One row more than shown, so hasMore needs no second query.
     *
     * @return Collection<int, UserNotification>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->filteredQuery()
            ->when($this->showsPinned, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereNotNull('read_at')->orWhere('priority', '!=', NotificationPriority::Critical->value),
            ))
            ->with('actor:id,name')
            ->latest()
            ->limit($this->perPage + 1)
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, UserNotification>> Y-m-d => rows
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->rows->take($this->perPage)->groupBy(
            fn (UserNotification $notification): string => $notification->created_at->toDateString(),
        );
    }

    #[Computed]
    public function hasMore(): bool
    {
        return $this->rows->count() > $this->perPage;
    }

    /**
     * Modules present in my inbox, for the module filter.
     *
     * @return list<string>
     */
    #[Computed]
    public function moduleOptions(): array
    {
        return UserNotification::query()->forUser(auth()->user())->distinct()->orderBy('module')->pluck('module')->all();
    }

    /**
     * Unread counts per module, priority and kind, plus how many are snoozed:
     * the numbers beside every filter in the rail.
     *
     * @return array{total: int, critical: int, modules: array<string, int>, kinds: array<string, int>, priorities: array<string, int>, snoozed: int}
     */
    #[Computed]
    public function railCounts(): array
    {
        $user = auth()->user();
        $counts = app(UnreadCounts::class)->for($user);

        $rows = UserNotification::query()
            ->forUser($user)
            ->visible()
            ->whereNull('read_at')
            ->toBase()
            ->selectRaw('kind, priority, count(*) as aggregate')
            ->groupBy('kind', 'priority')
            ->get();

        $kinds = [];
        $priorities = [];

        foreach ($rows as $row) {
            $kinds[$row->kind] = ($kinds[$row->kind] ?? 0) + (int) $row->aggregate;
            $priorities[$row->priority] = ($priorities[$row->priority] ?? 0) + (int) $row->aggregate;
        }

        return [
            ...$counts,
            'kinds' => $kinds,
            'priorities' => $priorities,
            'snoozed' => UserNotification::query()->forUser($user)->where('snoozed_until', '>', now())->count(),
        ];
    }

    #[Computed]
    public function unreadCount(): int
    {
        return $this->railCounts['total'];
    }

    #[Computed]
    public function filtersActive(): bool
    {
        return $this->module !== '' || $this->kind !== '' || $this->priority !== '' || trim($this->search) !== '';
    }

    public function loadMore(): void
    {
        $this->perPage += self::PageSize;
    }

    public function clearFilters(): void
    {
        $this->reset('module', 'kind', 'priority', 'search', 'selected');
        $this->perPage = self::PageSize;
    }

    /**
     * Jump straight to what needs me: unread critical notifications.
     */
    public function showCritical(): void
    {
        $this->reset('module', 'kind', 'search', 'selected');
        $this->state = 'unread';
        $this->priority = NotificationPriority::Critical->value;
        $this->perPage = self::PageSize;
    }

    public function markSelectedRead(): void
    {
        $this->markIdsRead($this->selected);
        $this->selected = [];
    }

    public function snoozeSelected(string $preset): void
    {
        $this->snoozeIds($this->selected, $preset);
        $this->selected = [];
    }

    #[On('notification-received')]
    public function refreshList(): void
    {
        unset($this->rows, $this->days, $this->hasMore, $this->pinned, $this->railCounts, $this->unreadCount, $this->moduleOptions);
    }
}; ?>

@php
    $presenter = app(NotificationPresenter::class);
    $counts = $this->railCounts;
    $shownIds = $this->pinned->pluck('id')->merge($this->rows->take($perPage)->pluck('id'))->values()->all();
    $emptyState = match (true) {
        $this->filtersActive => 'filtered',
        in_array($state, ['unread', 'read', 'snoozed'], true) => $state,
        default => null,
    };
    $statusDots = [
        'all' => 'bg-secondary-light',
        'unread' => 'bg-primary',
        'read' => 'bg-secondary-soft dark:bg-secondary-medium',
        'snoozed' => 'bg-secondary-dark dark:bg-white',
    ];
    $priorityDots = [
        '' => 'bg-secondary-light',
        'critical' => 'bg-primary',
        'important' => 'bg-status-warn',
        'info' => 'bg-secondary-soft dark:bg-secondary-medium',
    ];
    $railHeading = 'type-label mb-2 px-2 text-secondary-medium dark:text-secondary-soft';
    $railItem = 'group/item relative flex min-h-9 w-full cursor-pointer items-center gap-3 rounded-lg px-2 text-start text-sm transition-colors focus-visible:outline-2 focus-visible:outline-secondary-medium';
    $railActive = 'font-bold text-secondary-dark dark:text-white';
    $railIdle = 'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white';
    $threadList = 'relative before:absolute before:start-[calc(0.5rem+0.5rem-0.5px)] before:top-4 before:bottom-4 before:w-px before:bg-secondary-soft dark:before:bg-secondary-medium';
    $dotBase = 'relative z-[1] size-2.5 rounded-full ring-4 ring-white transition-transform duration-300 dark:ring-secondary-dark';
@endphp

<section
    x-data="notificationInbox"
    x-on:keydown.window="onKey($event)"
    x-on:inbox-undoable.window="showUndo($event.detail)"
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="notifications-heading"
    data-notification-inbox
>
    <x-ui.page-header heading-id="notifications-heading" :title="__('notifications.inbox')" :count="$this->unreadCount">
        <x-slot:filters>
            <div class="@3xl:col-span-2 @6xl:col-span-3">
                <x-ui.input type="search" icon="magnifying-glass" :label="__('notifications.search')" wire:model.live.debounce.300ms="search" clearable x-ref="search" data-inbox-search />
            </div>
        </x-slot:filters>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button tone="ghost" icon="cog-6-tooth" :href="route('notification-settings.edit')" wire:navigate :aria-label="__('notifications.settings_link')" :title="__('notifications.settings_link')" />
                <x-ui.button tone="primary" icon="check-circle" wire:click="markAllRead" :disabled="$this->unreadCount === 0">{{ __('notifications.mark_all_read') }}</x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-8 @4xl/directory:grid-cols-[15rem_minmax(0,1fr)] @6xl/directory:grid-cols-[16.5rem_minmax(0,1fr)] @6xl/directory:gap-14">
        {{-- Rail: the brief, then every filter as one click. --}}
        <aside x-data="{ filtersOpen: false }" class="min-w-0 @4xl/directory:sticky @4xl/directory:top-6 @4xl/directory:self-start" aria-label="{{ __('notifications.rail.label') }}">
            <div class="flex items-end justify-between gap-4 @4xl/directory:block">
                <div data-inbox-brief>
                    @if ($counts['total'] > 0)
                        <p class="flex items-baseline gap-3">
                            <span wire:key="brief-{{ $counts['total'] }}" x-data="countUp({{ $counts['total'] }})" x-text="display" class="text-6xl leading-none font-extrabold tracking-[-0.04em] tabular-nums">{{ $counts['total'] }}</span>
                            <span class="text-sm font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('notifications.brief.unread') }}</span>
                        </p>
                    @else
                        <p class="flex items-center gap-2.5 text-xl font-bold">
                            <span class="flex size-8 items-center justify-center rounded-full bg-status-good-soft text-status-good"><flux:icon.check variant="mini" class="size-4" /></span>
                            {{ __('notifications.brief.caught_up') }}
                        </p>
                    @endif

                    <dl class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                        @if ($counts['critical'] > 0)
                            <div>
                                <dt class="sr-only">{{ __('notifications.brief.critical') }}</dt>
                                <dd>
                                    <button type="button" wire:click="showCritical" class="group/brief inline-flex cursor-pointer items-baseline gap-1.5 rounded-md text-status-critical focus-visible:outline-2 focus-visible:outline-secondary-medium" data-brief-critical>
                                        <span class="text-lg font-extrabold tabular-nums">{{ $counts['critical'] }}</span>
                                        <span class="font-semibold group-hover/brief:underline group-hover/brief:underline-offset-4">{{ __('notifications.brief.critical') }}</span>
                                    </button>
                                </dd>
                            </div>
                        @endif
                        @if (($counts['kinds']['alert'] ?? 0) > 0)
                            <div>
                                <dt class="sr-only">{{ __('notifications.brief.alerts') }}</dt>
                                <dd>
                                    <button type="button" wire:click="$set('kind', 'alert')" class="group/brief inline-flex cursor-pointer items-baseline gap-1.5 rounded-md focus-visible:outline-2 focus-visible:outline-secondary-medium">
                                        <span class="text-lg font-extrabold tabular-nums">{{ $counts['kinds']['alert'] }}</span>
                                        <span class="font-semibold text-secondary-medium group-hover/brief:underline group-hover/brief:underline-offset-4 dark:text-secondary-soft">{{ __('notifications.brief.alerts') }}</span>
                                    </button>
                                </dd>
                            </div>
                        @endif
                        @if ($counts['snoozed'] > 0)
                            <div>
                                <dt class="sr-only">{{ __('notifications.brief.snoozed') }}</dt>
                                <dd>
                                    <button type="button" wire:click="$set('state', 'snoozed')" class="group/brief inline-flex cursor-pointer items-baseline gap-1.5 rounded-md focus-visible:outline-2 focus-visible:outline-secondary-medium">
                                        <span class="text-lg font-extrabold tabular-nums">{{ $counts['snoozed'] }}</span>
                                        <span class="font-semibold text-secondary-medium group-hover/brief:underline group-hover/brief:underline-offset-4 dark:text-secondary-soft">{{ __('notifications.brief.snoozed') }}</span>
                                    </button>
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <button type="button" x-on:click="filtersOpen = ! filtersOpen" x-bind:aria-expanded="filtersOpen.toString()" class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-secondary-soft px-3.5 text-sm font-semibold @4xl/directory:hidden dark:border-secondary-medium">
                    <flux:icon.funnel variant="micro" />
                    {{ __('notifications.filters_toggle') }}
                    @if ($this->filtersActive || $state !== 'all')
                        <span class="size-1.5 rounded-full bg-primary" aria-hidden="true"></span>
                    @endif
                </button>
            </div>

            <div
                x-bind:class="filtersOpen ? 'grid' : 'hidden'"
                class="mt-6 hidden gap-7 border-t border-secondary-soft pt-6 @xl/directory:grid-cols-2 @4xl/directory:grid! @4xl/directory:grid-cols-1 dark:border-secondary-medium"
            >
                <div>
                    <p class="{{ $railHeading }}">{{ __('notifications.rail.status') }}</p>
                    <ul class="{{ $threadList }}">
                        @foreach (['all', 'unread', 'read', 'snoozed'] as $option)
                            @php
                                $isActive = $state === $option;
                                $optionCount = match ($option) {
                                    'unread' => $counts['total'],
                                    'snoozed' => $counts['snoozed'],
                                    default => 0,
                                };
                            @endphp
                            <li>
                                <button type="button" wire:click="$set('state', '{{ $option }}')" @class([$railItem, $isActive ? $railActive : $railIdle]) aria-pressed="{{ $isActive ? 'true' : 'false' }}" data-rail-state="{{ $option }}">
                                    <span @class([$dotBase, $statusDots[$option], 'scale-[1.35]' => $isActive, 'opacity-60 group-hover/item:opacity-100' => ! $isActive])></span>
                                    <span class="min-w-0 flex-1 truncate">{{ __("notifications.states.{$option}") }}</span>
                                    @if ($optionCount > 0)
                                        <span class="text-xs font-bold tabular-nums">{{ $optionCount }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="{{ $railHeading }}">{{ __('notifications.rail.priority') }}</p>
                    <ul class="{{ $threadList }}">
                        @foreach (['', ...NotificationPriority::values()] as $option)
                            @php
                                $isActive = $priority === $option;
                                $optionCount = $option === '' ? 0 : ($counts['priorities'][$option] ?? 0);
                            @endphp
                            <li>
                                <button type="button" wire:click="$set('priority', '{{ $option }}')" @class([$railItem, $isActive ? $railActive : $railIdle]) aria-pressed="{{ $isActive ? 'true' : 'false' }}">
                                    <span @class([$dotBase, $priorityDots[$option], 'scale-[1.35]' => $isActive, 'opacity-60 group-hover/item:opacity-100' => ! $isActive])></span>
                                    <span class="min-w-0 flex-1 truncate">{{ $option === '' ? __('notifications.rail.all') : NotificationPriority::from($option)->label() }}</span>
                                    @if ($optionCount > 0)
                                        <span @class(['text-xs font-bold tabular-nums', 'text-status-critical' => $option === 'critical'])>{{ $optionCount }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="{{ $railHeading }}">{{ __('notifications.rail.type') }}</p>
                    <ul>
                        @foreach (['' => 'squares-2x2', 'action' => 'user', 'alert' => 'bell-alert', 'digest' => 'newspaper'] as $option => $icon)
                            @php
                                $isActive = $kind === $option;
                                $optionCount = $option === '' ? 0 : ($counts['kinds'][$option] ?? 0);
                            @endphp
                            <li>
                                <button type="button" wire:click="$set('kind', '{{ $option }}')" @class([$railItem, $isActive ? $railActive : $railIdle]) aria-pressed="{{ $isActive ? 'true' : 'false' }}">
                                    <flux:icon :name="$icon" variant="micro" @class(['size-4 shrink-0', 'text-primary' => $isActive])/>
                                    <span class="min-w-0 flex-1 truncate">{{ $option === '' ? __('notifications.rail.all') : NotificationKind::from($option)->label() }}</span>
                                    @if ($optionCount > 0)
                                        <span class="text-xs font-bold tabular-nums">{{ $optionCount }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if (count($this->moduleOptions) > 1 || $module !== '')
                    <div>
                        <p class="{{ $railHeading }}">{{ __('notifications.rail.modules') }}</p>
                        <ul>
                            <li>
                                <button type="button" wire:click="$set('module', '')" @class([$railItem, $module === '' ? $railActive : $railIdle]) aria-pressed="{{ $module === '' ? 'true' : 'false' }}">
                                    <flux:icon.squares-2x2 variant="micro" @class(['size-4 shrink-0', 'text-primary' => $module === ''])/>
                                    <span class="min-w-0 flex-1 truncate">{{ __('notifications.filters.all_modules') }}</span>
                                </button>
                            </li>
                            @foreach ($this->moduleOptions as $option)
                                @php
                                    $isActive = $module === $option;
                                    $optionCount = $counts['modules'][$option] ?? 0;
                                @endphp
                                <li wire:key="rail-module-{{ $option }}">
                                    <button type="button" wire:click="$set('module', '{{ $option }}')" @class([$railItem, $isActive ? $railActive : $railIdle]) aria-pressed="{{ $isActive ? 'true' : 'false' }}" data-rail-module="{{ $option }}">
                                        <flux:icon :name="$presenter->moduleIcon($option)" variant="micro" @class(['size-4 shrink-0', 'text-primary' => $isActive])/>
                                        <span class="min-w-0 flex-1 truncate">{{ $presenter->moduleLabel($option) }}</span>
                                        @if ($optionCount > 0)
                                            <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-secondary-soft/70 px-1.5 text-[0.6875rem] leading-5 font-bold tabular-nums dark:bg-white/10">{{ $optionCount }}</span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <button type="button" x-on:click="help = true" class="hidden cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-semibold text-secondary-medium hover:text-secondary-dark lg:inline-flex dark:text-secondary-soft dark:hover:text-white">
                    <kbd class="inline-flex size-5 items-center justify-center rounded border border-secondary-soft font-sans text-[0.6875rem] dark:border-secondary-medium">?</kbd>
                    {{ __('notifications.keyboard.hint') }}
                </button>
            </div>
        </aside>

        {{-- Feed --}}
        <div class="relative min-w-0" x-bind:data-selecting="$wire.selected.length > 0 ? '' : null" data-inbox-feed>
            @if ($selected !== [])
                <div class="sticky top-[4.75rem] z-20 mb-4 flex lg:top-3 flex-wrap items-center gap-x-2 gap-y-1 rounded-2xl bg-secondary-dark py-2 ps-4 pe-2 text-sm text-white shadow-[0_12px_28px_rgba(16,24,40,0.18),0_2px_6px_rgba(16,24,40,0.1)] dark:bg-white dark:text-secondary-dark" role="toolbar" aria-label="{{ trans_choice('notifications.selected_count', count($selected), ['count' => count($selected)]) }}" data-selection-bar>
                    <span class="me-auto font-bold tabular-nums">{{ trans_choice('notifications.selected_count', count($selected), ['count' => count($selected)]) }}</span>
                    @if (count($selected) < count($shownIds))
                        <button type="button" x-on:click="$wire.set('selected', @js($shownIds))" class="inline-flex min-h-9 cursor-pointer items-center rounded-lg px-3 font-semibold hover:bg-white/10 dark:hover:bg-secondary-dark/10">{{ __('notifications.select_all_shown') }}</button>
                    @endif
                    <button type="button" wire:click="markSelectedRead" class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 font-semibold hover:bg-white/10 dark:hover:bg-secondary-dark/10">
                        <flux:icon.check variant="micro" />
                        {{ __('notifications.mark_selected_read') }}
                    </button>
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" class="inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 font-semibold hover:bg-white/10 dark:hover:bg-secondary-dark/10">
                            <flux:icon.clock variant="micro" />
                            {{ __('notifications.snooze') }}
                            <flux:icon.chevron-down variant="micro" class="size-3.5" />
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity.duration.120ms x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false" class="absolute end-0 top-11 z-30 w-48 rounded-xl bg-white p-1 text-secondary-dark shadow-[0_12px_28px_rgba(16,24,40,0.16)] dark:bg-secondary-dark dark:text-white dark:ring-1 dark:ring-secondary-medium" role="menu">
                            @foreach (Inbox::SnoozePresets as $preset)
                                <button type="button" role="menuitem" wire:click="snoozeSelected('{{ $preset }}')" x-on:click="open = false" class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-start hover:bg-secondary-soft/60 dark:hover:bg-white/10">{{ __("notifications.snooze_presets.{$preset}") }}</button>
                            @endforeach
                        </div>
                    </div>
                    <button type="button" wire:click="$set('selected', [])" class="inline-flex size-9 cursor-pointer items-center justify-center rounded-lg hover:bg-white/10 dark:hover:bg-secondary-dark/10" aria-label="{{ __('notifications.clear_selection') }}" title="{{ __('notifications.clear_selection') }}">
                        <flux:icon.x-mark variant="micro" />
                    </button>
                </div>
            @endif

            @if (session('notification-missing'))
                <p class="mb-4 flex items-center gap-2 text-sm font-semibold text-status-critical" role="status">
                    <span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>
                    {{ __('notifications.item_no_longer_exists') }}
                </p>
            @endif

            @if ($this->filtersActive || $state !== 'all')
                <div class="mb-5 flex flex-wrap items-center gap-2 text-sm" data-active-filters>
                    @foreach (array_filter([
                        'state' => $state !== 'all' ? __("notifications.states.{$state}") : null,
                        'priority' => $priority !== '' && NotificationPriority::tryFrom($priority) ? NotificationPriority::from($priority)->label() : null,
                        'kind' => $kind !== '' && NotificationKind::tryFrom($kind) ? NotificationKind::from($kind)->label() : null,
                        'module' => $module !== '' ? $presenter->moduleLabel($module) : null,
                        'search' => trim($search) !== '' ? '“'.trim($search).'”' : null,
                    ]) as $property => $label)
                        <button type="button" wire:click="$set('{{ $property }}', '{{ $property === 'state' ? 'all' : '' }}')" class="group/chip inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-full bg-secondary-soft/60 ps-3 pe-2 font-semibold transition-colors hover:bg-secondary-dark hover:text-white dark:bg-white/10 dark:hover:bg-white dark:hover:text-secondary-dark" wire:key="chip-{{ $property }}">
                            {{ $label }}
                            <flux:icon.x-mark variant="micro" class="size-3.5 opacity-60 group-hover/chip:opacity-100" />
                        </button>
                    @endforeach
                    @if ($this->filtersActive)
                        <button type="button" wire:click="clearFilters" class="ms-1 cursor-pointer font-semibold text-status-critical hover:underline hover:underline-offset-4">{{ __('notifications.clear_filters') }}</button>
                    @endif
                </div>
            @endif

            @if ($this->pinned->isNotEmpty())
                <section class="mb-8 rounded-2xl bg-status-critical-soft/60 p-1.5 pt-3 dark:bg-primary/12" aria-labelledby="inbox-pinned-heading" data-inbox-pinned>
                    <div class="flex items-center gap-2.5 px-3 pb-1">
                        <span class="notification-beacon relative size-2 rounded-full bg-primary text-primary" aria-hidden="true"></span>
                        <h2 id="inbox-pinned-heading" class="text-sm font-bold text-status-critical">{{ __('notifications.sections.pinned') }}</h2>
                        <span class="text-xs font-bold text-status-critical tabular-nums">{{ $this->pinned->count() }}</span>
                    </div>
                    <ol class="notification-thread">
                        @foreach ($this->pinned as $notification)
                            <x-notifications.row :notification="$notification" :selectable="true" wire:key="inbox-pinned-{{ $notification->id }}" style="--row-order: {{ $loop->index }}" />
                        @endforeach
                    </ol>
                </section>
            @endif

            @forelse ($this->days as $day => $rows)
                @php
                    $date = \Carbon\CarbonImmutable::parse($day);
                    $dayLabel = match (true) {
                        $date->isToday() => __('notifications.sections.today'),
                        $date->isYesterday() => __('notifications.sections.yesterday'),
                        default => $date->translatedFormat('l'),
                    };
                    $dayUnread = $rows->whereNull('read_at')->pluck('id')->values()->all();
                    $dayIds = $rows->pluck('id')->values()->all();
                @endphp
                <section class="group/day mb-8 last:mb-0" wire:key="inbox-day-{{ $day }}" aria-labelledby="inbox-day-{{ $day }}">
                    <div class="z-10 -mx-1 flex lg:sticky lg:top-0 flex-wrap items-baseline gap-x-3 gap-y-1 bg-white/90 px-1 pt-1 pb-2 backdrop-blur-sm dark:bg-secondary-dark/90">
                        <h2 id="inbox-day-{{ $day }}" class="text-lg font-bold">{{ $dayLabel }}</h2>
                        <span class="text-sm text-secondary-medium tabular-nums dark:text-secondary-soft">{{ $date->translatedFormat(__('formats.date')) }}</span>
                        <span class="text-xs font-semibold text-secondary-light">{{ trans_choice('notifications.day_count', $rows->count(), ['count' => $rows->count()]) }}</span>
                        <div class="ms-auto flex items-center gap-1 transition-opacity lg:opacity-0 lg:group-hover/day:opacity-100 lg:group-focus-within/day:opacity-100">
                            <button type="button" x-on:click="$wire.set('selected', [...new Set([...$wire.selected, ...@js($dayIds)])])" class="inline-flex min-h-8 cursor-pointer items-center rounded-lg px-2.5 text-xs font-semibold text-secondary-medium hover:bg-secondary-soft/50 hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white">{{ __('notifications.select_day') }}</button>
                            @if ($dayUnread !== [])
                                <button type="button" x-on:click="$wire.markIdsRead(@js($dayUnread))" class="inline-flex min-h-8 cursor-pointer items-center gap-1 rounded-lg px-2.5 text-xs font-semibold text-secondary-medium hover:bg-secondary-soft/50 hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white" data-mark-day-read>
                                    <flux:icon.check variant="micro" class="size-3.5" />
                                    {{ __('notifications.mark_day_read') }}
                                </button>
                            @endif
                        </div>
                    </div>
                    <ol class="notification-thread">
                        @foreach ($rows as $notification)
                            <x-notifications.row :notification="$notification" :selectable="true" wire:key="inbox-{{ $notification->id }}" style="--row-order: {{ $loop->index }}" />
                        @endforeach
                    </ol>
                </section>
            @empty
                @if ($this->pinned->isEmpty())
                    <div class="flex flex-col items-center px-6 py-20 text-center" data-inbox-empty>
                        <svg viewBox="0 0 160 64" class="h-16 w-40 text-secondary-soft dark:text-secondary-medium" fill="none" aria-hidden="true">
                            <path d="M8 32H62M98 32H152" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="1 7" />
                            <circle cx="20" cy="32" r="4" fill="currentColor" />
                            <circle cx="140" cy="32" r="4" fill="currentColor" />
                            <circle cx="80" cy="32" r="17" @class(['fill-status-good-soft stroke-status-good' => $emptyState === null || $emptyState === 'unread', 'fill-white stroke-secondary-light dark:fill-secondary-dark' => ! ($emptyState === null || $emptyState === 'unread')]) stroke-width="1.5" />
                            @if ($emptyState === null || $emptyState === 'unread')
                                <path d="M72.5 32.5L77.5 37.5L88 27" class="stroke-status-good" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
                            @else
                                <circle cx="80" cy="32" r="3" class="fill-secondary-light" />
                            @endif
                        </svg>
                        <p class="mt-6 text-xl font-bold">{{ $emptyState ? __("notifications.empty_states.{$emptyState}.heading") : __('notifications.empty') }}</p>
                        <p class="mt-2 max-w-sm text-sm text-pretty text-secondary-medium dark:text-secondary-soft">{{ $emptyState ? __("notifications.empty_states.{$emptyState}.body") : __('notifications.empty_body') }}</p>
                        @if ($this->filtersActive)
                            <x-ui.button tone="ghost" icon="x-mark" wire:click="clearFilters" class="mt-5">{{ __('notifications.clear_filters') }}</x-ui.button>
                        @endif
                    </div>
                @endif
            @endforelse

            @if ($this->hasMore)
                <div x-intersect="$wire.loadMore()" data-load-more class="flex items-center gap-4 py-8 text-sm text-secondary-medium dark:text-secondary-soft">
                    <span class="h-px flex-1 bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                    <button type="button" wire:click="loadMore" class="inline-flex cursor-pointer items-center gap-2 font-semibold hover:text-secondary-dark dark:hover:text-white">
                        <span wire:loading.remove wire:target="loadMore">{{ __('notifications.load_more') }}</span>
                        <span wire:loading wire:target="loadMore">{{ __('notifications.loading') }}</span>
                    </button>
                    <span class="h-px flex-1 bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                </div>
            @endif
        </div>
    </div>

    {{-- Undo: the last read or snooze, for a few seconds. --}}
    <div class="pointer-events-none fixed inset-x-0 bottom-6 z-40 flex justify-center px-4" aria-live="polite">
        <template x-if="undo">
            <div
                x-transition:enter="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
                x-transition:enter-start="opacity-0 translate-y-3 scale-95"
                class="pointer-events-auto relative flex items-center gap-4 overflow-hidden rounded-full bg-secondary-dark py-2 ps-4 pe-2 text-sm text-white shadow-[0_16px_36px_rgba(16,24,40,0.24),0_2px_6px_rgba(16,24,40,0.12)] dark:bg-white dark:text-secondary-dark"
                data-inbox-undo
            >
                <span class="flex size-5 items-center justify-center rounded-full bg-white/15 dark:bg-secondary-dark/10"><flux:icon.check variant="micro" class="size-3.5" /></span>
                <span x-text="undo.message" class="font-semibold"></span>
                <button type="button" x-on:click="undoNow()" class="inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-full bg-white/15 px-3.5 font-bold hover:bg-white/25 dark:bg-secondary-dark/10 dark:hover:bg-secondary-dark/20">
                    <flux:icon.arrow-uturn-left variant="micro" class="size-3.5" />
                    {{ __('notifications.undo.action') }}
                </button>
                <button type="button" x-on:click="dismissUndo()" class="inline-flex size-8 cursor-pointer items-center justify-center rounded-full hover:bg-white/15 dark:hover:bg-secondary-dark/10" aria-label="{{ __('notifications.toast.dismiss') }}">
                    <flux:icon.x-mark variant="micro" />
                </button>
                <span x-bind:key="undo.stamp" class="absolute inset-x-0 bottom-0 h-0.5 origin-left bg-white/40 motion-safe:animate-[hc-toast-countdown_6s_linear_both] rtl:origin-right dark:bg-secondary-dark/30" aria-hidden="true"></span>
            </div>
        </template>
    </div>

    {{-- Keyboard shortcuts --}}
    <div x-show="help" x-cloak x-transition.opacity.duration.150ms class="fixed inset-0 z-50 flex items-end justify-center bg-secondary-dark/20 p-4 backdrop-blur-[2px] sm:items-center" x-on:click.self="help = false" x-on:keydown.escape.window="help = false">
        <div role="dialog" aria-modal="true" aria-labelledby="inbox-shortcuts-heading" class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-[0_24px_48px_rgba(16,24,40,0.22)] dark:bg-secondary-dark dark:ring-1 dark:ring-secondary-medium" x-trap.noscroll="help">
            <div class="flex items-center justify-between">
                <h2 id="inbox-shortcuts-heading" class="text-base font-bold">{{ __('notifications.keyboard.hint') }}</h2>
                <button type="button" x-on:click="help = false" class="inline-flex size-8 cursor-pointer items-center justify-center rounded-lg text-secondary-medium hover:bg-secondary-soft/60 dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.keyboard.close') }}"><flux:icon.x-mark variant="micro" /></button>
            </div>
            <dl class="mt-4 grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2.5 text-sm">
                @foreach ([['j', 'next'], ['k', 'previous'], ['Enter', 'open'], ['e', 'toggle_read'], ['s', 'snooze'], ['x', 'select'], ['/', 'search'], ['Esc', 'clear'], ['?', 'help']] as [$key, $label])
                    <dt><kbd class="inline-flex min-w-7 items-center justify-center rounded-md border border-b-2 border-secondary-soft px-1.5 py-0.5 font-sans text-xs font-bold dark:border-secondary-medium">{{ $key }}</kbd></dt>
                    <dd class="text-secondary-medium dark:text-secondary-soft">{{ __("notifications.keyboard.{$label}") }}</dd>
                @endforeach
            </dl>
        </div>
    </div>
</section>
