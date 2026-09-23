@props(['notification', 'selectable' => false])

@php
    $presenter = app(\App\Notifications\NotificationPresenter::class);
    $isUnread = $notification->read_at === null;
    $quickLabels = __('notifications.quick.labels');
    $dotClasses = match ($notification->priority) {
        \App\Enums\NotificationPriority::Critical => 'bg-primary',
        \App\Enums\NotificationPriority::Important => 'bg-status-warn',
        \App\Enums\NotificationPriority::Info => 'bg-secondary-light dark:bg-secondary-medium',
    };
@endphp

<li
    {{ $attributes->class('group relative flex gap-3 rounded-lg px-2 py-2.5 hover:bg-secondary-soft/50 dark:hover:bg-white/5') }}
    data-notification-row
    data-priority="{{ $notification->priority->value }}"
    @if ($isUnread) data-unread @endif
>
    @if ($selectable)
        <input type="checkbox" wire:model.live="selected" value="{{ $notification->id }}" class="mt-2 size-4 shrink-0 accent-primary" aria-label="{{ __('notifications.select_notification') }}" />
    @endif

    <span class="relative mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary-soft text-[11px] font-bold text-secondary-dark dark:bg-white/10 dark:text-white" aria-hidden="true">
        @if ($notification->kind === \App\Enums\NotificationKind::Action)
            {{ $notification->actor?->initials() ?? '·' }}
        @elseif ($notification->kind === \App\Enums\NotificationKind::Alert)
            <flux:icon.bell-alert variant="micro" />
        @else
            <flux:icon.newspaper variant="micro" />
        @endif
        <span class="absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-white dark:ring-secondary-dark {{ $dotClasses }}"></span>
    </span>

    <div class="min-w-0 flex-1">
        <a href="{{ route('notifications.open', $notification->id) }}" class="block focus:outline-none" data-notification-link>
            <p @class([
                'text-sm leading-snug',
                'font-semibold text-secondary-dark dark:text-white' => $isUnread,
                'text-secondary-medium dark:text-secondary-soft' => ! $isUnread,
            ])>{{ $presenter->sentence($notification->data) }}</p>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-secondary-medium dark:text-secondary-soft">
                <time datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->diffForHumans() }}</time>
                <span aria-hidden="true">·</span>
                <span>{{ $presenter->moduleLabel($notification->module) }}</span>
                <span class="sr-only">{{ $notification->priority->label() }}</span>
            </p>
        </a>

        @if (! empty($notification->data['actions']))
            <div class="mt-2 flex flex-wrap gap-2" data-quick-actions>
                @foreach ($notification->data['actions'] as $action)
                    <button
                        type="button"
                        wire:click="runQuickAction('{{ $notification->id }}', '{{ $action['type'] }}')"
                        wire:loading.attr="disabled"
                        class="inline-flex min-h-8 cursor-pointer items-center rounded-full border border-secondary-soft px-3 text-xs font-semibold text-secondary-dark hover:border-primary hover:text-status-critical disabled:opacity-50 dark:border-secondary-medium dark:text-white"
                        data-quick-action="{{ $action['type'] }}"
                    >{{ $quickLabels[str_replace('.', '_', $action['type'])] ?? $action['type'] }}</button>
                @endforeach
            </div>
        @endif
    </div>

    <div x-data="{ snoozing: false }" class="relative flex shrink-0 items-start gap-0.5 lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">
        @if ($isUnread)
            <button type="button" wire:click="markRead('{{ $notification->id }}')" class="inline-flex size-8 items-center justify-center rounded-md text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.mark_read') }}">
                <flux:icon.check variant="micro" />
            </button>
        @else
            <button type="button" wire:click="markUnread('{{ $notification->id }}')" class="inline-flex size-8 items-center justify-center rounded-md text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.mark_unread') }}">
                <flux:icon.envelope variant="micro" />
            </button>
        @endif

        <button type="button" x-on:click="snoozing = ! snoozing" x-bind:aria-expanded="snoozing.toString()" class="inline-flex size-8 items-center justify-center rounded-md text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.snooze') }}">
            <flux:icon.clock variant="micro" />
        </button>

        <div x-show="snoozing" x-cloak x-on:click.outside="snoozing = false" class="absolute end-0 top-9 z-10 w-40 rounded-lg bg-white py-1 text-sm shadow-lg dark:bg-secondary-dark dark:ring-1 dark:ring-secondary-medium">
            @foreach (\App\Notifications\Inbox::SnoozePresets as $preset)
                <button type="button" wire:click="snooze('{{ $notification->id }}', '{{ $preset }}')" x-on:click="snoozing = false" class="block w-full px-3 py-2 text-start hover:bg-secondary-soft/60 dark:hover:bg-white/10">{{ __("notifications.snooze_presets.{$preset}") }}</button>
            @endforeach
        </div>
    </div>
</li>
