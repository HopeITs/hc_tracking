@props(['notification', 'selectable' => false, 'compact' => false])

@php
    $presenter = app(\App\Notifications\NotificationPresenter::class);
    $isUnread = $notification->read_at === null;
    $isSnoozed = $notification->snoozed_until !== null && $notification->snoozed_until->isFuture();
    $quickLabels = __('notifications.quick.labels');
    $glyphTone = match ($notification->priority) {
        \App\Enums\NotificationPriority::Critical => 'bg-primary dark:bg-status-critical dark:text-surface-0 text-white shadow-[0_3px_10px_-3px_rgba(16,24,40,0.35)]',
        \App\Enums\NotificationPriority::Important => 'bg-status-warn-soft text-status-warn',
        \App\Enums\NotificationPriority::Info => 'bg-secondary-soft/70 text-secondary-dark dark:bg-white/10 dark:text-ink',
    };
    $priorityTone = match ($notification->priority) {
        \App\Enums\NotificationPriority::Critical => 'text-status-critical',
        \App\Enums\NotificationPriority::Important => 'text-status-warn',
        \App\Enums\NotificationPriority::Info => null,
    };
    $iconButton = 'inline-flex size-8 cursor-pointer items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-white hover:text-secondary-dark hover:shadow-[0_1px_2px_rgba(16,24,40,0.08)] focus-visible:outline-2 focus-visible:outline-secondary-medium dark:text-ink-muted dark:hover:bg-white/10 dark:hover:text-ink';
@endphp

<li
    {{ $attributes->class([
        'notification-row group/row relative flex rounded-xl transition-colors focus:outline-none max-sm:flex-wrap',
        'gap-3 [--glyph:2.25rem] [--row-px:0.5rem] [--row-py:0.625rem]' => $compact,
        'gap-3.5 [--glyph:2.5rem] [--row-px:0.75rem] [--row-py:0.875rem]' => ! $compact,
        'px-(--row-px) py-(--row-py) hover:bg-secondary-soft/35 data-active:bg-secondary-soft/55 dark:hover:bg-white/[0.04] dark:data-active:bg-white/[0.08]',
    ]) }}
    data-notification-row
    data-id="{{ $notification->id }}"
    data-priority="{{ $notification->priority->value }}"
    tabindex="-1"
    @if ($isUnread) data-unread @endif
>
    @if ($isUnread)
        <span class="absolute start-[calc(var(--row-px)/2-0.1875rem)] top-[calc(var(--row-py)+var(--glyph)/2-0.1875rem)] size-1.5 rounded-full bg-primary dark:bg-ink" aria-hidden="true" data-unread-marker></span>
    @endif

    <span class="group/glyph relative mt-0.5 size-(--glyph) shrink-0">
        <span @class([
            'absolute inset-0 flex items-center justify-center rounded-full transition-[opacity,transform] duration-200',
            $glyphTone,
            'opacity-60 saturate-50' => ! $isUnread,
            'group-hover/glyph:scale-90 group-hover/glyph:opacity-0 in-data-selecting:scale-90 in-data-selecting:opacity-0 group-has-[:checked]/glyph:opacity-0' => $selectable,
        ]) aria-hidden="true">
            <flux:icon :name="$presenter->moduleIcon($notification->module)" variant="mini" class="size-[1.125rem]" />
        </span>

        @if ($selectable)
            <span class="absolute inset-0 flex scale-90 items-center justify-center rounded-full border-2 border-secondary-light bg-white text-white opacity-0 transition-[opacity,transform,background-color,border-color] duration-200 group-hover/glyph:scale-100 group-hover/glyph:opacity-100 in-data-selecting:scale-100 in-data-selecting:opacity-100 group-has-[:checked]/glyph:scale-100 group-has-[:checked]/glyph:border-primary group-has-[:checked]/glyph:bg-primary group-has-[:checked]/glyph:opacity-100 dark:border-field-line dark:bg-surface-1" aria-hidden="true">
                <flux:icon.check variant="mini" class="size-4 scale-50 opacity-0 transition duration-200 group-has-[:checked]/glyph:scale-100 group-has-[:checked]/glyph:opacity-100" />
            </span>
            <input
                type="checkbox"
                wire:model.live="selected"
                value="{{ $notification->id }}"
                class="absolute inset-0 z-10 size-full cursor-pointer appearance-none rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium"
                aria-label="{{ __('notifications.select_notification') }}"
                data-row-select
            />
        @endif

        @if ($notification->kind === \App\Enums\NotificationKind::Action)
            <span class="pointer-events-none absolute -end-1 -bottom-1 z-[11] flex size-5 items-center justify-center rounded-full bg-secondary-dark text-[0.5625rem] font-bold tracking-tight text-white ring-2 ring-white transition-opacity group-has-[:checked]/glyph:opacity-0 dark:bg-ink dark:text-surface-0 dark:ring-surface-1" aria-hidden="true">{{ $notification->actor?->initials() ?? '·' }}</span>
        @else
            <span class="pointer-events-none absolute -end-1 -bottom-1 z-[11] flex size-5 items-center justify-center rounded-full bg-secondary-dark text-white ring-2 ring-white transition-opacity group-has-[:checked]/glyph:opacity-0 dark:bg-ink dark:text-surface-0 dark:ring-surface-1" aria-hidden="true">
                @if ($notification->kind === \App\Enums\NotificationKind::Alert)
                    <flux:icon.bell-alert variant="micro" class="size-3" />
                @else
                    <flux:icon.newspaper variant="micro" class="size-3" />
                @endif
            </span>
        @endif
    </span>

    <div class="min-w-0 flex-1 pt-px max-sm:basis-[calc(100%-var(--glyph)-1rem)]">
        <a href="{{ route('notifications.open', $notification->id) }}" class="block rounded-md focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-secondary-medium" data-notification-link>
            <p @class([
                'notification-sentence leading-snug text-pretty',
                'text-sm' => $compact,
                'text-[0.9375rem]' => ! $compact,
                'text-secondary-dark dark:text-ink' => $isUnread,
                'text-secondary-medium dark:text-ink-muted' => ! $isUnread,
            ]) @if ($isUnread) data-unread-sentence @endif>{{ $presenter->sentenceHtml($notification->data) }}</p>
            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-secondary-medium dark:text-ink-muted">
                @if ($priorityTone)
                    <span class="inline-flex items-center gap-1 font-bold {{ $priorityTone }}">
                        <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
                        {{ $notification->priority->label() }}
                    </span>
                    <span aria-hidden="true" class="text-secondary-light">·</span>
                @else
                    <span class="sr-only">{{ $notification->priority->label() }}</span>
                @endif
                <time datetime="{{ $notification->created_at?->toIso8601String() }}" title="{{ $notification->created_at?->translatedFormat(__('formats.datetime')) }}">{{ $notification->created_at?->diffForHumans() }}</time>
                <span aria-hidden="true" class="text-secondary-light">·</span>
                <span>{{ $presenter->moduleLabel($notification->module) }}</span>
                @if ($isSnoozed)
                    <span class="inline-flex items-center gap-1 rounded-full bg-secondary-soft/60 px-2 py-0.5 font-semibold text-secondary-dark dark:bg-white/10 dark:text-ink" data-snoozed-until>
                        <flux:icon.clock variant="micro" class="size-3" />
                        {{ __('notifications.snoozed_until', ['time' => $notification->snoozed_until->translatedFormat(__('formats.datetime'))]) }}
                    </span>
                @endif
            </p>
        </a>

        @if (! empty($notification->data['actions']))
            <div class="mt-2.5 flex flex-wrap gap-1.5" data-quick-actions>
                @foreach ($notification->data['actions'] as $action)
                    <button
                        type="button"
                        wire:click="runQuickAction('{{ $notification->id }}', '{{ $action['type'] }}')"
                        wire:loading.attr="disabled"
                        @class([
                            'inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-full px-3.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium disabled:opacity-50',
                            'bg-secondary-dark text-white hover:bg-secondary-medium dark:bg-ink dark:text-surface-0 dark:hover:bg-ink/85' => $loop->first,
                            'border border-secondary-soft text-secondary-dark hover:border-secondary-light hover:bg-white dark:border-line dark:text-ink dark:hover:bg-white/10' => ! $loop->first,
                        ])
                        data-quick-action="{{ $action['type'] }}"
                    >
                        @if ($loop->first)
                            <flux:icon.check variant="micro" class="size-3.5" />
                        @endif
                        {{ $quickLabels[str_replace('.', '_', $action['type'])] ?? $action['type'] }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div x-data="{ snoozing: false }" @class([
        'relative flex shrink-0 items-start gap-0.5 transition-opacity max-sm:-my-1 max-sm:ms-[calc(var(--glyph)+0.5rem)] lg:opacity-0 lg:group-hover/row:opacity-100 lg:group-focus-within/row:opacity-100 lg:group-data-active/row:opacity-100 lg:has-[[aria-expanded=true]]:opacity-100',
        'lg:absolute lg:end-1.5 lg:top-1.5 lg:z-20 lg:rounded-xl lg:bg-white lg:p-0.5 lg:shadow-[0_2px_10px_rgba(16,24,40,0.12)] dark:lg:bg-surface-1 dark:lg:ring-1 dark:lg:ring-line' => $compact,
    ])>
        @if ($isUnread)
            <button type="button" wire:click="markRead('{{ $notification->id }}')" class="{{ $iconButton }}" aria-label="{{ __('notifications.mark_read') }}" title="{{ __('notifications.mark_read') }}" data-toggle-read>
                <flux:icon.check variant="micro" />
            </button>
        @else
            <button type="button" wire:click="markUnread('{{ $notification->id }}')" class="{{ $iconButton }}" aria-label="{{ __('notifications.mark_unread') }}" title="{{ __('notifications.mark_unread') }}" data-toggle-read>
                <flux:icon.envelope variant="micro" />
            </button>
        @endif

        @if ($isSnoozed)
            <button type="button" wire:click="unsnooze('{{ $notification->id }}')" class="{{ $iconButton }}" aria-label="{{ __('notifications.unsnooze') }}" title="{{ __('notifications.unsnooze') }}" data-unsnooze>
                <flux:icon.arrow-uturn-left variant="micro" />
            </button>
        @else
            <button type="button" x-on:click="snoozing = ! snoozing" x-on:keydown.escape.stop="snoozing = false" x-bind:aria-expanded="snoozing.toString()" class="{{ $iconButton }}" aria-label="{{ __('notifications.snooze') }}" title="{{ __('notifications.snooze') }}" data-snooze-toggle>
                <flux:icon.clock variant="micro" />
            </button>

            <div
                x-show="snoozing"
                x-cloak
                x-transition:enter="transition duration-150 ease-out"
                x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                x-transition:leave="transition duration-100 ease-in"
                x-transition:leave-end="opacity-0 scale-95"
                x-on:click.outside="snoozing = false"
                x-on:keydown.escape.stop="snoozing = false"
                class="absolute end-0 top-9 z-30 w-48 origin-top-right rounded-xl bg-white p-1 text-sm shadow-[0_12px_28px_rgba(16,24,40,0.14),0_2px_6px_rgba(16,24,40,0.08)] rtl:origin-top-left dark:bg-surface-3 dark:shadow-raised dark:ring-1 dark:ring-line"
                role="menu"
            >
                <p class="type-label px-2.5 pt-1.5 pb-1 text-secondary-medium dark:text-ink-muted">{{ __('notifications.snooze') }}</p>
                @foreach (\App\Notifications\Inbox::SnoozePresets as $preset)
                    <button type="button" role="menuitem" wire:click="snooze('{{ $notification->id }}', '{{ $preset }}')" x-on:click="snoozing = false" class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-2.5 py-2 text-start hover:bg-secondary-soft/60 focus-visible:bg-secondary-soft/60 focus-visible:outline-none dark:hover:bg-white/10 dark:focus-visible:bg-white/10">
                        <flux:icon.clock variant="micro" class="size-3.5 text-secondary-light" />
                        {{ __("notifications.snooze_presets.{$preset}") }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</li>
