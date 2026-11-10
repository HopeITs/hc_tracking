<?php

use App\Enums\NotificationPriority;
use App\Livewire\Concerns\ManagesInbox;
use App\Models\UserNotification;
use App\Notifications\UnreadCounts;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use ManagesInbox;

    /** Which slice of the inbox the panel shows: all or unread. */
    public string $tab = 'all';

    /**
     * Whether the panel has been opened on this page. The bell renders on
     * every page, so its rows are only queried once somebody actually opens
     * it; until then only the cached unread count is read.
     */
    public bool $opened = false;

    /**
     * Whether the panel's settings menu offers the notification rules; the
     * top bar decides, so the bell itself never loads the viewer's permissions.
     */
    #[Locked]
    public bool $canManageRules = false;

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
            ->with('actor:id,hr_user_id,employee_id')
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
        $this->tab = $tab === 'unread' ? 'unread' : 'all';
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
    $total = $this->counts['total'];
    $critical = $this->counts['critical'];
    $panelId = 'notification-panel-'.$this->getId();
@endphp

<div
    x-data="notificationBell"
    x-on:keydown.escape.window="close(true)"
    x-on:click.outside="close(false)"
    x-on:resize.window="place()"
    x-on:inbox-undoable.window="showUndo($event.detail)"
    class="relative flex"
    data-notification-bell
>
    <button
        type="button"
        x-ref="button"
        x-on:click="toggle()"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="dialog"
        aria-controls="{{ $panelId }}-dialog"
        aria-label="{{ trans_choice('notifications.bell_label', $total, ['count' => $total]) }}"
        x-bind:class="open && 'bg-white text-secondary-dark shadow-sm dark:bg-surface-3 dark:text-ink dark:shadow-raised'"
        class="relative inline-flex size-8 cursor-pointer touch-manipulation items-center justify-center rounded-full text-secondary-medium transition-colors hover:bg-white hover:text-secondary-dark hover:shadow-sm max-lg:size-11 dark:text-ink-muted dark:hover:bg-surface-3 dark:hover:text-ink dark:hover:shadow-raised"
        data-top-bar-action
        data-test="notification-bell-button"
    >
        <span x-bind:class="pulse && 'notification-bell-ring'" class="inline-flex">
            <flux:icon.bell variant="outline" class="size-4.5!" />
        </span>

        {{-- Only a red dot on the bell, like the sidebar rail (user decision 2026-11-10); the exact number is
             in the button's label and inside the panel. data-unread-count carries it for the app badge. --}}
        @if ($total > 0)
            <span
                class="absolute size-2 rounded-full bg-primary ring-2 ring-white dark:bg-status-critical dark:ring-surface-1"
                aria-hidden="true"
                data-unread-count="{{ $total }}"
                @if ($critical > 0) data-critical-unread @endif
            ></span>
        @endif
    </button>

    {{-- The panel hangs centred under the bell (desk-bell.css); place() nudges
         it back inside the window when the bell sits near an edge. Below 640px
         it is a full-width sheet under the top bar. --}}
    <div
        x-show="open"
        x-cloak
        x-ref="panel"
        id="{{ $panelId }}-dialog"
        tabindex="-1"
        x-transition:enter="dk-menu-in"
        x-transition:enter-start="dk-menu-from"
        x-transition:leave="dk-menu-out"
        x-transition:leave-end="dk-menu-from"
        role="dialog"
        aria-label="{{ __('notifications.inbox') }}"
        class="dk-nb"
        data-notification-panel
    >
        <div class="dk-nb-h">
            <div class="dk-nb-head">
                <p class="dk-nb-title">{{ __('notifications.inbox') }}</p>
                <p class="dk-nb-figure" aria-live="polite">
                    @if ($total > 0)
                        <strong>{{ $total > 999 ? '999+' : $total }}</strong>
                        <span>{{ __('notifications.bell.unread') }}</span>
                        @if ($critical > 0)
                            <span class="dk-nb-critical" data-bell-critical><i aria-hidden="true"></i>{{ __('notifications.bell.critical_count', ['count' => $critical]) }}</span>
                        @endif
                    @else
                        <span class="dk-nb-calm">{{ __('notifications.brief.caught_up') }}</span>
                    @endif
                </p>
            </div>

            <div class="dk-nb-acts">
                @if ($total > 0)
                    <button type="button" wire:click="markAllRead" x-on:click="$refs.panel.querySelectorAll('.dk-nb-unread').forEach((row) => row.classList.remove('dk-nb-unread', 'dk-nb-crit'))" class="dk-nb-ic dk-tip" aria-label="{{ __('notifications.mark_all_read') }}" data-tip="{{ __('notifications.mark_all_read') }}" data-bell-mark-all>
                        <flux:icon name="nav-check-check" class="size-4" />
                    </button>
                @endif

                @if ($canManageRules)
                    <div class="relative" x-data="{ menu: false }" x-on:click.outside="menu = false" x-on:keydown.escape.stop="menu = false; $refs.gear.focus()">
                        <button type="button" x-ref="gear" x-on:click="menu = ! menu" x-bind:aria-expanded="menu.toString()" aria-haspopup="menu" class="dk-nb-ic dk-tip" aria-label="{{ __('notifications.bell.settings') }}" data-tip="{{ __('notifications.bell.settings') }}" data-bell-settings>
                            <flux:icon name="nav-settings" class="size-4" />
                        </button>
                        <div x-show="menu" x-cloak role="menu" class="dk-nb-menu" x-transition:enter="dk-menu-in" x-transition:enter-start="dk-menu-from" x-transition:leave="dk-menu-out" x-transition:leave-end="dk-menu-from">
                            <a role="menuitem" href="{{ route('notification-settings.edit') }}" wire:navigate>
                                <flux:icon name="nav-bell-ring" class="size-4" />
                                {{ __('notifications.settings_link') }}
                            </a>
                            <a role="menuitem" href="{{ route('admin.notification-settings.edit') }}" wire:navigate data-bell-rules>
                                <flux:icon name="nav-sliders-horizontal" class="size-4" />
                                {{ __('navigation.notification_settings') }}
                            </a>
                        </div>
                    </div>
                @else
                    <a href="{{ route('notification-settings.edit') }}" wire:navigate class="dk-nb-ic dk-tip" aria-label="{{ __('notifications.settings_link') }}" data-tip="{{ __('notifications.settings_link') }}" data-bell-settings>
                        <flux:icon name="nav-settings" class="size-4" />
                    </a>
                @endif
            </div>
        </div>

        <div class="dk-nb-tabs" role="tablist" aria-label="{{ __('notifications.inbox') }}" x-on:keydown.arrow-left.prevent="switchTab($event)" x-on:keydown.arrow-right.prevent="switchTab($event)">
            @foreach (['all', 'unread'] as $key)
                <button
                    type="button"
                    role="tab"
                    wire:click="showTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    aria-controls="{{ $panelId }}"
                    tabindex="{{ $tab === $key ? '0' : '-1' }}"
                    class="dk-nb-tab"
                >
                    {{ __("notifications.tabs.{$key}") }}
                    @if ($key === 'unread' && $total > 0)
                        <small>{{ $total > 99 ? '99+' : $total }}</small>
                    @endif
                </button>
            @endforeach
        </div>

        <div id="{{ $panelId }}" role="tabpanel" class="dk-nb-list" wire:loading.class="dk-nb-busy" wire:target="showTab">
            @if (! $opened)
                <div class="dk-nb-skel" role="status" data-panel-loading>
                    <span class="sr-only">{{ __('notifications.loading') }}</span>
                    @foreach ([88, 64, 76] as $width)
                        <span class="dk-nb-skel-row" aria-hidden="true">
                            <i></i>
                            <span><b style="width: {{ $width }}%"></b><b></b></span>
                        </span>
                    @endforeach
                </div>
            @else
                @forelse ($this->sections as $section => $rows)
                    <p class="dk-nb-sec" @if ($section === 'pinned') data-bell-pinned @endif>{{ __("notifications.sections.{$section}") }}</p>
                    <ol class="dk-nb-thread" x-on:keydown.arrow-down.prevent="moveRow($event, 1)" x-on:keydown.arrow-up.prevent="moveRow($event, -1)">
                        @foreach ($rows as $notification)
                            <x-notifications.bell-row :notification="$notification" wire:key="bell-{{ $notification->id }}" />
                        @endforeach
                    </ol>
                @empty
                    <div class="dk-nb-empty" data-bell-empty>
                        <span aria-hidden="true"><flux:icon.check variant="outline" /></span>
                        <p>{{ __('notifications.empty') }}</p>
                        <small>{{ __('notifications.empty_body') }}</small>
                    </div>
                @endforelse
            @endif
        </div>

        <div class="dk-nb-foot">
            <a href="{{ route('notifications.index') }}" wire:navigate class="dk-nb-all">
                {{ __('notifications.view_all') }}
                <flux:icon.arrow-right variant="micro" class="dk-flip" />
            </a>

            <template x-if="undo">
                <div class="dk-nb-undo" aria-live="polite" data-bell-undo>
                    <span x-text="undo.message"></span>
                    <button type="button" x-on:click="undoNow()">
                        <flux:icon.arrow-uturn-left variant="micro" />
                        {{ __('notifications.undo.action') }}
                    </button>
                </div>
            </template>
        </div>
    </div>
</div>
