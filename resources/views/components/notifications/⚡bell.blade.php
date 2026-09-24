<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Livewire\Concerns\ManagesInbox;
use App\Models\UserNotification;
use App\Notifications\NotificationPresenter;
use App\Notifications\UnreadCounts;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use ManagesInbox;

    /** Which slice of the inbox the panel shows: all, unread or alerts. */
    public string $tab = 'all';

    /** The module chip the panel is narrowed to, '' for every module. */
    public string $module = '';

    /**
     * Whether the panel has been opened on this page. The bell renders on
     * every page (twice: mobile and desktop header), so its rows are only
     * queried once somebody actually opens it; until then only the cached
     * unread count is read.
     */
    public bool $opened = false;

    /**
     * @return array{total: int, critical: int, modules: array<string, int>}
     */
    #[Computed]
    public function counts(): array
    {
        return app(UnreadCounts::class)->for(auth()->user());
    }

    /**
     * The latest 30 rows, bucketed: pinned (unread critical), today, yesterday, earlier.
     *
     * @return Collection<string, Collection<int, UserNotification>>
     */
    #[Computed]
    public function sections(): Collection
    {
        if (! $this->opened) {
            return collect();
        }

        $order = ['pinned', 'today', 'yesterday', 'earlier'];

        return UserNotification::query()
            ->forUser(auth()->user())
            ->visible()
            ->when($this->tab === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($this->tab === 'alerts', fn ($query) => $query->whereIn('kind', [NotificationKind::Alert->value, NotificationKind::Digest->value]))
            ->when($this->module !== '', fn ($query) => $query->where('module', $this->module))
            ->with('actor:id,hr_user_id')
            ->orderByRaw('case when read_at is null and priority = ? then 0 else 1 end', [NotificationPriority::Critical->value])
            ->latest()
            ->limit(30)
            ->get()
            ->groupBy(fn (UserNotification $notification): string => match (true) {
                $notification->read_at === null && $notification->priority === NotificationPriority::Critical => 'pinned',
                $notification->created_at->isToday() => 'today',
                $notification->created_at->isYesterday() => 'yesterday',
                default => 'earlier',
            })
            ->sortBy(fn (Collection $rows, string $section): int => (int) array_search($section, $order, true));
    }

    public function loadPanel(): void
    {
        $this->opened = true;
        unset($this->sections);
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['all', 'unread', 'alerts'], true) ? $tab : 'all';
        unset($this->sections);
    }

    public function showModule(string $module): void
    {
        $this->module = $this->module === $module ? '' : $module;
        unset($this->sections);
    }

    #[On('notification-received')]
    public function refreshInbox(): void
    {
        unset($this->counts, $this->sections);
        $this->dispatch('notification-counts', counts: $this->counts);
    }
}; ?>

@php
    $presenter = app(NotificationPresenter::class);
    $total = $this->counts['total'];
    $critical = $this->counts['critical'];
    $chipModules = $this->counts['modules'];

    if ($module !== '' && ! array_key_exists($module, $chipModules)) {
        $chipModules[$module] = 0;
    }
@endphp

<div
    x-data="notificationBell"
    x-on:keydown.escape.window="close(true)"
    x-on:click.outside="close(false)"
    x-on:inbox-undoable.window="showUndo($event.detail)"
    class="relative"
    data-notification-bell
>
    <button
        type="button"
        x-ref="button"
        x-on:click="toggle()"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="dialog"
        aria-label="{{ trans_choice('notifications.bell_label', $total, ['count' => $total]) }}"
        x-bind:class="open && 'bg-secondary-soft text-secondary-dark dark:bg-white/10 dark:text-white'"
        class="relative inline-flex size-9 cursor-pointer touch-manipulation items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark max-lg:size-11 dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white"
        data-test="notification-bell-button"
    >
        <span x-bind:class="pulse && 'notification-bell-ring'" class="inline-flex">
            <flux:icon.bell variant="mini" />
        </span>

        @if ($total > 0)
            <span
                @class([
                    'absolute -end-0.5 -top-0.5 inline-flex min-w-[1.125rem] items-center justify-center rounded-full px-1 text-[10px] font-bold leading-[1.125rem] tabular-nums text-white ring-2 ring-white dark:ring-secondary-dark',
                    'bg-primary' => $critical > 0,
                    'bg-secondary-dark dark:bg-secondary-medium' => $critical === 0,
                ])
                data-unread-count
                @if ($critical > 0) data-critical-unread @endif
            >{{ $total > 99 ? '99+' : $total }}</span>
        @endif
    </button>

    {{-- From sm the panel hangs from the bell, its end edge lined up with
         the bell's so it opens towards the page rather than the window edge.
         It stays short: the list scrolls inside it. --}}
    <div
        x-show="open"
        x-cloak
        x-ref="panel"
        tabindex="-1"
        x-transition:enter="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:duration-0"
        x-transition:enter-start="opacity-0 -translate-y-2 scale-[0.97] blur-[2px]"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100 blur-0"
        x-transition:leave="transition duration-150 ease-in motion-reduce:duration-0"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-[0.98]"
        role="dialog"
        aria-label="{{ __('notifications.inbox') }}"
        class="fixed inset-x-3 top-16 z-50 flex max-h-[calc(100dvh-5rem)] origin-top-right flex-col overflow-hidden rounded-2xl bg-white text-secondary-dark shadow-[0_24px_48px_-12px_rgba(16,24,40,0.22),0_4px_10px_rgba(16,24,40,0.08)] ring-1 ring-secondary-soft/70 focus:outline-none sm:absolute sm:inset-x-auto sm:end-0 sm:top-full sm:mt-2 sm:w-[26rem] rtl:origin-top-left dark:bg-secondary-dark dark:text-white dark:ring-secondary-medium"
        data-notification-panel
    >
        <div class="flex items-start justify-between gap-3 px-5 pt-5">
            <div class="min-w-0">
                <p class="text-lg leading-tight font-bold">{{ __('notifications.inbox') }}</p>
                <p class="mt-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                    @if ($total > 0)
                        <span class="tabular-nums">{{ __('notifications.unread_count', ['count' => $total]) }}</span>
                        @if ($critical > 0)
                            <span aria-hidden="true" class="text-secondary-light">·</span>
                            <span class="text-status-critical tabular-nums">{{ $critical }} {{ __('notifications.brief.critical') }}</span>
                        @endif
                    @else
                        {{ __('notifications.brief.caught_up') }}
                    @endif
                </p>
            </div>
            <div class="-me-1.5 flex items-center gap-0.5">
                <a href="{{ route('notifications.index') }}" wire:navigate class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white" aria-label="{{ __('notifications.full_page') }}" title="{{ __('notifications.full_page') }}">
                    <flux:icon.arrows-pointing-out variant="mini" class="size-4" />
                </a>
                <a href="{{ route('notification-settings.edit') }}" wire:navigate class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white" aria-label="{{ __('notifications.settings_link') }}" title="{{ __('notifications.settings_link') }}">
                    <flux:icon.cog-6-tooth variant="mini" class="size-4" />
                </a>
            </div>
        </div>

        <div class="px-5 pt-4">
            <div class="grid grid-cols-3 gap-1 rounded-xl bg-secondary-soft/50 p-1 text-sm dark:bg-white/[0.06]" role="tablist">
                @foreach (['all', 'unread', 'alerts'] as $key)
                    <button
                        type="button"
                        role="tab"
                        wire:click="showTab('{{ $key }}')"
                        aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        aria-controls="notification-panel-{{ $this->getId() }}"
                        @class([
                            'inline-flex min-h-8 cursor-pointer items-center justify-center gap-1.5 rounded-lg px-2 font-semibold transition-[background-color,color,box-shadow] duration-200',
                            'bg-white text-secondary-dark shadow-[0_1px_3px_rgba(16,24,40,0.12)] dark:bg-secondary-medium dark:text-white' => $tab === $key,
                            'text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => $tab !== $key,
                        ])
                    >
                        {{ __("notifications.tabs.{$key}") }}
                        @if ($key === 'unread' && $total > 0)
                            <span @class([
                                'inline-flex min-w-4.5 items-center justify-center rounded-full px-1 text-[0.625rem] leading-4.5 font-bold tabular-nums',
                                'bg-primary text-white' => $critical > 0,
                                'bg-secondary-dark text-white dark:bg-white dark:text-secondary-dark' => $critical === 0,
                            ])>{{ $total > 99 ? '99+' : $total }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        @if (count($chipModules) > 1 || $module !== '')
            <div class="relative mt-3">
                <div class="flex gap-1.5 overflow-x-auto px-5 pb-1 [scrollbar-width:none] [mask-image:linear-gradient(to_right,transparent,black_1.25rem,black_calc(100%-1.25rem),transparent)] [&::-webkit-scrollbar]:hidden" data-bell-modules>
                    @foreach ($chipModules as $chip => $chipCount)
                        <button
                            type="button"
                            wire:click="showModule('{{ $chip }}')"
                            wire:key="bell-chip-{{ $chip }}"
                            aria-pressed="{{ $module === $chip ? 'true' : 'false' }}"
                            @class([
                                'inline-flex min-h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-full border ps-2.5 pe-3 text-xs font-semibold whitespace-nowrap transition-colors',
                                'border-secondary-dark bg-secondary-dark text-white dark:border-white dark:bg-white dark:text-secondary-dark' => $module === $chip,
                                'border-secondary-soft text-secondary-dark hover:border-secondary-light dark:border-secondary-medium dark:text-white dark:hover:border-secondary-light' => $module !== $chip,
                            ])
                            data-bell-module="{{ $chip }}"
                        >
                            <flux:icon :name="$presenter->moduleIcon($chip)" variant="micro" class="size-3.5" />
                            {{ $presenter->moduleLabel($chip) }}
                            @if ($chipCount > 0)
                                <span class="tabular-nums opacity-70">{{ $chipCount }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <div id="notification-panel-{{ $this->getId() }}" role="tabpanel" class="mt-2 max-h-[min(19rem,calc(100dvh-16rem))] min-h-0 overflow-y-auto overscroll-contain px-2.5 pb-3 [scrollbar-width:thin]" wire:loading.class="opacity-60" wire:target="showTab,showModule">
            @if (! $opened)
                <div class="space-y-1 px-2 py-2" role="status" data-panel-loading>
                    <span class="sr-only">{{ __('notifications.loading') }}</span>
                    @foreach ([0.9, 0.7, 0.8] as $width)
                        <div class="flex gap-3 py-2.5" aria-hidden="true">
                            <span class="size-9 shrink-0 animate-pulse rounded-full bg-secondary-soft/70 dark:bg-white/10"></span>
                            <span class="flex-1 space-y-2 pt-1">
                                <span class="block h-3 animate-pulse rounded-full bg-secondary-soft/70 dark:bg-white/10" style="width: {{ $width * 100 }}%"></span>
                                <span class="block h-2.5 w-1/3 animate-pulse rounded-full bg-secondary-soft/50 dark:bg-white/[0.07]"></span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @else
                @forelse ($this->sections as $section => $rows)
                    @if ($section === 'pinned')
                        <div class="mt-2 rounded-xl bg-status-critical-soft/60 p-1 pt-2.5 dark:bg-primary/12" data-bell-pinned>
                            <p class="flex items-center gap-2 px-2.5 pb-0.5 text-xs font-bold text-status-critical">
                                <span class="notification-beacon relative size-1.5 rounded-full bg-primary text-primary" aria-hidden="true"></span>
                                {{ __("notifications.sections.{$section}") }}
                            </p>
                            <ol class="notification-thread">
                                @foreach ($rows as $notification)
                                    <x-notifications.row :notification="$notification" :compact="true" wire:key="bell-{{ $notification->id }}" style="--row-order: {{ $loop->index }}" />
                                @endforeach
                            </ol>
                        </div>
                    @else
                        <p class="type-label px-2.5 pt-4 pb-1 text-secondary-medium dark:text-secondary-soft">{{ __("notifications.sections.{$section}") }}</p>
                        <ol class="notification-thread">
                            @foreach ($rows as $notification)
                                <x-notifications.row :notification="$notification" :compact="true" wire:key="bell-{{ $notification->id }}" style="--row-order: {{ $loop->index }}" />
                            @endforeach
                        </ol>
                    @endif
                @empty
                    <div class="flex flex-col items-center px-6 py-12 text-center" data-bell-empty>
                        <svg viewBox="0 0 160 64" class="h-14 w-36 text-secondary-soft dark:text-secondary-medium" fill="none" aria-hidden="true">
                            <path d="M8 32H62M98 32H152" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="1 7" />
                            <circle cx="20" cy="32" r="4" fill="currentColor" />
                            <circle cx="140" cy="32" r="4" fill="currentColor" />
                            <circle cx="80" cy="32" r="17" class="fill-status-good-soft stroke-status-good" stroke-width="1.5" />
                            <path d="M72.5 32.5L77.5 37.5L88 27" class="stroke-status-good" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <p class="mt-4 text-base font-bold">{{ __('notifications.empty') }}</p>
                        <p class="mt-1 max-w-60 text-xs text-pretty text-secondary-medium dark:text-secondary-soft">{{ __('notifications.empty_body') }}</p>
                    </div>
                @endforelse
            @endif
        </div>

        <div class="relative flex items-center justify-between gap-3 border-t border-secondary-soft/80 px-5 py-3 text-sm dark:border-secondary-medium">
            <button type="button" wire:click="markAllRead" @disabled($total === 0) class="inline-flex cursor-pointer items-center gap-1.5 font-semibold text-secondary-medium transition-colors hover:text-secondary-dark disabled:cursor-default disabled:opacity-40 dark:text-secondary-soft dark:hover:text-white">
                <flux:icon.check-circle variant="micro" />
                {{ __('notifications.mark_all_read') }}
            </button>
            <a href="{{ route('notifications.index') }}" wire:navigate class="group/all inline-flex items-center gap-1 font-semibold text-status-critical">
                {{ __('notifications.view_all') }}
                <flux:icon.arrow-right variant="micro" class="size-4 transition-transform group-hover/all:translate-x-0.5 rtl:rotate-180 rtl:group-hover/all:-translate-x-0.5" />
            </a>

            <template x-if="undo">
                <div
                    x-transition:enter="transition duration-200 ease-out"
                    x-transition:enter-start="opacity-0 translate-y-1"
                    class="absolute inset-0 flex items-center justify-between gap-3 bg-secondary-dark px-5 text-white dark:bg-white dark:text-secondary-dark"
                    aria-live="polite"
                    data-bell-undo
                >
                    <span x-text="undo.message" class="truncate font-semibold"></span>
                    <button type="button" x-on:click="undoNow()" class="inline-flex min-h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-full bg-white/15 px-3 font-bold hover:bg-white/25 dark:bg-secondary-dark/10 dark:hover:bg-secondary-dark/20">
                        <flux:icon.arrow-uturn-left variant="micro" class="size-3.5" />
                        {{ __('notifications.undo.action') }}
                    </button>
                </div>
            </template>
        </div>
    </div>
</div>
