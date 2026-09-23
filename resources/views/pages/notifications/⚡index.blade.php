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
            ->visible()
            ->when($this->module !== '', fn (Builder $query) => $query->where('module', $this->module))
            ->when(in_array($this->kind, NotificationKind::values(), true), fn (Builder $query) => $query->where('kind', $this->kind))
            ->when(in_array($this->priority, NotificationPriority::values(), true), fn (Builder $query) => $query->where('priority', $this->priority))
            ->when($this->state === 'unread', fn (Builder $query) => $query->whereNull('read_at'))
            ->when($this->state === 'read', fn (Builder $query) => $query->whereNotNull('read_at'))
            ->when($term !== '', fn (Builder $query) => $query->where('data', 'like', '%'.addcslashes($term, '%_\\').'%'));
    }

    /**
     * One row more than shown, so hasMore needs no second query.
     *
     * @return Collection<int, UserNotification>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->filteredQuery()->with('actor:id,name')->latest()->limit($this->perPage + 1)->get();
    }

    /**
     * @return Collection<string, Collection<int, UserNotification>> date label => rows
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->rows->take($this->perPage)->groupBy(
            fn (UserNotification $notification): string => $notification->created_at->translatedFormat(__('formats.date')),
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

    #[Computed]
    public function unreadCount(): int
    {
        return app(UnreadCounts::class)->for(auth()->user())['total'];
    }

    public function loadMore(): void
    {
        $this->perPage += self::PageSize;
    }

    public function markSelectedRead(): void
    {
        app(Inbox::class)->markManyRead(auth()->user(), array_values(array_filter($this->selected, 'is_string')));
        $this->selected = [];
        $this->dispatch('notification-received');
    }

    #[On('notification-received')]
    public function refreshList(): void
    {
        unset($this->rows, $this->days, $this->hasMore, $this->unreadCount);
    }
}; ?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="notifications-heading">
    <x-ui.page-header heading-id="notifications-heading" :title="__('notifications.inbox')" :count="$this->unreadCount">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('notifications.search')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('notifications.filters.module')" wire:model.live="module" :value="$module">
                <flux:select.option value="">{{ __('notifications.filters.all_modules') }}</flux:select.option>
                @foreach ($this->moduleOptions as $option)
                    <flux:select.option :value="$option" :wire:key="'notification-module-'.$option">{{ app(NotificationPresenter::class)->moduleLabel($option) }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('notifications.filters.kind')" wire:model.live="kind" :value="$kind">
                <flux:select.option value="">{{ __('notifications.filters.all_kinds') }}</flux:select.option>
                @foreach (NotificationKind::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('notifications.filters.priority')" wire:model.live="priority" :value="$priority">
                <flux:select.option value="">{{ __('notifications.filters.all_priorities') }}</flux:select.option>
                @foreach (NotificationPriority::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('notifications.filters.state')" wire:model.live="state" :value="$state">
                @foreach (['all', 'unread', 'read'] as $option)
                    <flux:select.option :value="$option">{{ __("notifications.states.{$option}") }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button tone="ghost" icon="check" wire:click="markSelectedRead" :disabled="$selected === []">{{ __('notifications.mark_selected_read') }}</x-ui.button>
                <x-ui.button tone="primary" icon="check-circle" wire:click="markAllRead">{{ __('notifications.mark_all_read') }}</x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('notification-missing'))
        <p class="flex items-center gap-2 text-sm font-semibold text-status-critical" role="status">
            <span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>
            {{ __('notifications.item_no_longer_exists') }}
        </p>
    @endif

    @forelse ($this->days as $day => $rows)
        <div class="grid gap-1 @4xl/directory:grid-cols-[10rem_minmax(0,1fr)]" wire:key="inbox-day-{{ $loop->index }}">
            <p class="type-label pt-3">{{ $day }}</p>
            <ol class="border-s border-secondary-soft ps-3 dark:border-secondary-medium">
                @foreach ($rows as $notification)
                    <x-notifications.row :notification="$notification" :selectable="true" wire:key="inbox-{{ $notification->id }}" />
                @endforeach
            </ol>
        </div>
    @empty
        <x-ui.empty-state icon="bell" :heading="__('notifications.empty')" :body="__('notifications.empty_body')" />
    @endforelse

    @if ($this->hasMore)
        <div x-intersect="$wire.loadMore()" data-load-more class="py-6 text-center text-sm text-secondary-medium dark:text-secondary-soft">
            <button type="button" wire:click="loadMore" class="font-semibold hover:underline">{{ __('notifications.load_more') }}</button>
        </div>
    @endif
</section>
