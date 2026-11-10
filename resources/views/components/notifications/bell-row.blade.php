@props(['notification'])

{{--
    One line in the bell panel, drawn like an Ask bar row: the module's sidebar
    icon in a ring on the section's thread, an unread dot on that ring (red only
    when critical; the priority itself is read out to screen readers), the
    sentence cut to one line and a muted "where · when" line. The full sentence
    is the link's title; snooze and quick actions live on the full page.
--}}

@php
    $presenter = app(\App\Notifications\NotificationPresenter::class);
    $isUnread = $notification->read_at === null;
    $isCritical = $notification->priority === \App\Enums\NotificationPriority::Critical;
    $sentence = $presenter->sentenceHtml($notification->data);
    $plainSentence = html_entity_decode(strip_tags((string) $sentence), ENT_QUOTES);
@endphp

<li
    {{ $attributes->class(['dk-nb-row', 'dk-nb-unread' => $isUnread, 'dk-nb-crit' => $isUnread && $isCritical]) }}
    data-bell-row
    data-id="{{ $notification->id }}"
    @if ($isUnread) data-unread @endif
>
    <a href="{{ route('notifications.open', $notification->id) }}" class="dk-nb-link" title="{{ $plainSentence }}" data-notification-link>
        <span class="dk-nb-glyph" aria-hidden="true">
            <flux:icon :name="$presenter->moduleIcon($notification->module)" variant="outline" class="size-3.5" />
            <i class="dk-nb-dot"></i>
        </span>
        <span class="dk-nb-text">
            <span class="dk-nb-line notification-sentence">{{ $sentence }}</span>
            <span class="dk-nb-meta">
                @if ($isUnread)
                    <span class="sr-only">{{ __('notifications.tabs.unread') }} ·</span>
                @endif
                <span class="sr-only">{{ $notification->priority->label() }} ·</span>
                <span>{{ $presenter->moduleLabel($notification->module) }}</span>
                <span aria-hidden="true">·</span>
                <time datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->diffForHumans() }}</time>
            </span>
        </span>
        <kbd class="dk-nb-ret" aria-hidden="true"><flux:icon.arrow-turn-down-left variant="micro" /></kbd>
    </a>

    @if ($isUnread)
        <button type="button" wire:click="markRead('{{ $notification->id }}')" x-on:click="$el.closest('[data-bell-row]').classList.remove('dk-nb-unread', 'dk-nb-crit')" class="dk-nb-tick" aria-label="{{ __('notifications.mark_read') }}" title="{{ __('notifications.mark_read') }}" data-toggle-read>
            <flux:icon.check variant="micro" />
        </button>
    @endif
</li>
