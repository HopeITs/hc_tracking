<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Livewire\Concerns\ManagesInbox;
use App\Models\UserNotification;
use App\Notifications\UnreadCounts;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use ManagesInbox;

    /** Which slice of the inbox the panel shows: all, unread or alerts. */
    public string $tab = 'all';

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
        $order = ['pinned', 'today', 'yesterday', 'earlier'];

        return UserNotification::query()
            ->forUser(auth()->user())
            ->visible()
            ->when($this->tab === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($this->tab === 'alerts', fn ($query) => $query->whereIn('kind', [NotificationKind::Alert->value, NotificationKind::Digest->value]))
            ->with('actor:id,name')
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

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['all', 'unread', 'alerts'], true) ? $tab : 'all';
        unset($this->sections);
    }

    #[On('notification-received')]
    public function refreshInbox(): void
    {
        unset($this->counts, $this->sections);
        $this->dispatch('notification-counts', counts: $this->counts);
    }
}; ?>

<div
    x-data="notificationBell"
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    class="relative"
    data-notification-bell
>
    <button
        type="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="dialog"
        aria-label="{{ trans_choice('notifications.bell_label', $this->counts['total'], ['count' => $this->counts['total']]) }}"
        x-bind:class="pulse && 'motion-safe:animate-pulse'"
        class="relative inline-flex size-9 touch-manipulation items-center justify-center rounded-lg text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark max-lg:size-11 dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white"
        data-test="notification-bell-button"
    >
        <flux:icon.bell variant="mini" />

        @if ($this->counts['total'] > 0)
            <span
                @class([
                    'absolute -end-0.5 -top-0.5 inline-flex min-w-[1.125rem] items-center justify-center rounded-full px-1 text-[10px] font-bold leading-[1.125rem] tabular-nums text-white',
                    'bg-primary' => $this->counts['critical'] > 0,
                    'bg-secondary-dark dark:bg-secondary-medium' => $this->counts['critical'] === 0,
                ])
                data-unread-count
                @if ($this->counts['critical'] > 0) data-critical-unread @endif
            >{{ $this->counts['total'] > 99 ? '99+' : $this->counts['total'] }}</span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        role="dialog"
        aria-label="{{ __('notifications.inbox') }}"
        class="fixed inset-x-3 top-16 z-50 flex flex-col rounded-xl bg-white text-secondary-dark shadow-[0_12px_32px_rgba(16,24,40,0.14),0_2px_6px_rgba(16,24,40,0.08)] sm:absolute sm:inset-x-auto sm:end-0 sm:top-full sm:mt-2 sm:w-[26rem] dark:bg-secondary-dark dark:text-white dark:ring-1 dark:ring-secondary-medium"
    >
        <div class="flex items-center justify-between gap-3 px-4 pt-4">
            <p class="text-base font-bold">{{ __('notifications.inbox') }}</p>
            <a href="{{ route('notification-settings.edit') }}" wire:navigate class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.settings_link') }}">
                <flux:icon.cog-6-tooth variant="mini" />
            </a>
        </div>

        <div class="flex gap-5 px-4 pt-2 text-sm" role="tablist">
            @foreach (['all', 'unread', 'alerts'] as $key)
                <button
                    type="button"
                    role="tab"
                    wire:click="showTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'min-h-9 border-b-2 font-semibold transition-colors',
                        'border-primary text-secondary-dark dark:text-white' => $tab === $key,
                        'border-transparent text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => $tab !== $key,
                    ])
                >{{ __("notifications.tabs.{$key}") }}</button>
            @endforeach
        </div>

        <div class="max-h-[min(32rem,70vh)] overflow-y-auto px-2 pb-2">
            @forelse ($this->sections as $section => $rows)
                <p class="type-label px-2 pt-4 pb-1">{{ __("notifications.sections.{$section}") }}</p>
                <ol class="space-y-0.5">
                    @foreach ($rows as $notification)
                        <x-notifications.row :notification="$notification" wire:key="bell-{{ $notification->id }}" />
                    @endforeach
                </ol>
            @empty
                <p class="px-4 py-12 text-center text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.empty') }}</p>
            @endforelse
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-secondary-soft px-4 py-3 text-sm dark:border-secondary-medium">
            <button type="button" wire:click="markAllRead" class="font-semibold text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white">{{ __('notifications.mark_all_read') }}</button>
            <a href="{{ route('notifications.index') }}" wire:navigate class="font-semibold text-status-critical hover:underline">{{ __('notifications.view_all') }}</a>
        </div>
    </div>
</div>
