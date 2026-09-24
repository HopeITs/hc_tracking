@props(['module', 'count' => 0])

{{-- The count stays live (notification-counts), and so does what a screen
     reader hears: the visible number plus a hidden "unread" suffix, rather than
     an aria-label frozen at page load. --}}
<span
    x-data="{ count: {{ (int) $count }} }"
    x-on:notification-counts.window="count = $event.detail.counts.modules[@js($module)] ?? 0"
    x-show="count > 0"
    @if ((int) $count === 0) style="display: none" @endif
    class="ms-auto inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-secondary-soft px-1.5 text-[10px] font-bold leading-5 tabular-nums text-secondary-dark in-data-current:bg-white/25 in-data-current:text-white in-data-flux-sidebar-collapsed-desktop:hidden dark:bg-white/10 dark:text-white"
    data-module-badge="{{ $module }}"
><span x-text="count > 99 ? '99+' : count">{{ match (true) { (int) $count > 99 => '99+', (int) $count > 0 => (int) $count, default => '' } }}</span><span class="sr-only"> {{ __('notifications.unread_suffix') }}</span></span>
