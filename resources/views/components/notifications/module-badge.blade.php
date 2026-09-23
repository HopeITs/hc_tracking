@props(['module', 'count' => 0])

<span
    x-data="{ count: {{ (int) $count }} }"
    x-on:notification-counts.window="count = $event.detail.counts.modules[@js($module)] ?? 0"
    x-show="count > 0"
    x-text="count > 99 ? '99+' : count"
    @if ((int) $count === 0) style="display: none" @endif
    class="ms-auto inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-secondary-soft px-1.5 text-[10px] font-bold leading-5 tabular-nums text-secondary-dark in-data-current:bg-white/25 in-data-current:text-white in-data-flux-sidebar-collapsed-desktop:hidden dark:bg-white/10 dark:text-white"
    data-module-badge="{{ $module }}"
    aria-label="{{ trans_choice('notifications.unread_in_module', (int) $count, ['count' => (int) $count]) }}"
>{{ match (true) { (int) $count > 99 => '99+', (int) $count > 0 => (int) $count, default => '' } }}</span>
