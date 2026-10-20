<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Livewire\Concerns\ManagesInbox;
use App\Models\UserNotification;
use App\Notifications\NotificationPresenter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Notifications: the latest thirty notifications in the viewer's inbox
 * (snoozed ones hidden), unread first marked, with "Mark all read". Each
 * row opens through notifications.open, which marks it read.
 */
new class extends Component {
    use IsDeskPanel;
    use ManagesInbox;

    #[On('notification-received')]
    public function refreshRows(): void
    {
        unset($this->rows);
    }

    /**
     * @return Collection<int, UserNotification>
     */
    #[Computed]
    public function rows(): Collection
    {
        return UserNotification::query()
            ->forUser(auth()->user())
            ->visible()
            ->latest()
            ->limit(30)
            ->get();
    }
};
?>

@php
    $rows = $this->rows;
    $unread = $rows->whereNull('read_at')->count();
    $pg = $this->paginate($rows, 5);
    $presenter = app(NotificationPresenter::class);
@endphp

<x-desk.panel id="inbox" register="notifications.index" :size="$size" :index="$index" :total="$total" :count="$unread > 0 ? $unread : null">
    @if ($unread > 0)
        <x-slot:tools>
            <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="markAllRead">{{ __('desk.mark_all_read') }}</button>
        </x-slot:tools>
    @endif

    @if ($pg['total'] > 0)
        <ul class="dk-inbox">
            @foreach ($pg['slice'] as $notification)
                <li wire:key="n-{{ $notification->id }}" @class(['dk-read' => $notification->read_at !== null])>
                    <span class="dk-ud"></span>
                    <a href="{{ route('notifications.open', $notification->id) }}">{{ $presenter->sentenceHtml($notification->data) }}<span class="dk-is"> · {{ \App\Support\Desk\RelativeDay::for($notification->created_at) }}</span></a>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.inbox.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
