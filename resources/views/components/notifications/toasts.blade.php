{{--
    The in-page notification toasts, driven by the `notificationToasts` Alpine
    component in resources/js/notifications.js. The JSON block below carries
    the translated strings that script also uses for desktop popups.
--}}
@php
    $labels = [
        'open' => __('notifications.toast.open'),
        'mark_read' => __('notifications.mark_read'),
        'critical' => __('notifications.toast.critical'),
        'important' => __('notifications.toast.important'),
        'more' => __('notifications.toast.more'),
        'now' => __('notifications.toast.now'),
        'view_all' => __('notifications.view_all'),
    ];
@endphp

<script type="application/json" id="hc-notification-labels">{!! json_encode($labels, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>

<section
    x-data="notificationToasts"
    aria-label="{{ __('notifications.toast.region') }}"
    class="pointer-events-none fixed inset-x-3 bottom-3 z-[60] flex flex-col items-end gap-3 sm:inset-x-auto sm:end-5 sm:bottom-5"
    data-notification-toasts
>
    <template x-for="item in items" :key="item.id">
        <article
            x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none"
            x-transition:enter-start="translate-y-3 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition duration-150 ease-in motion-reduce:transition-none"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            x-on:mouseenter="pause(item)"
            x-on:mouseleave="resume(item)"
            x-on:focusin="pause(item)"
            x-on:focusout="resume(item)"
            x-bind:role="item.sticky ? 'alert' : 'status'"
            x-bind:data-priority="item.priority"
            class="pointer-events-auto relative w-full overflow-hidden rounded-2xl bg-white text-secondary-dark shadow-[0_18px_40px_-12px_rgba(16,24,40,0.28),0_2px_6px_rgba(16,24,40,0.08)] ring-1 ring-secondary-soft sm:w-[23rem] dark:bg-surface-3 dark:shadow-raised dark:text-ink dark:ring-line"
            data-notification-toast
        >
            <span x-show="item.sticky" class="absolute inset-y-0 start-0 w-1 bg-primary dark:bg-status-critical" aria-hidden="true"></span>

            <div class="flex gap-3 py-4 ps-4 pe-2">
                <img x-bind:src="item.icon || @js(asset('app-icons/icon-192.png'))" alt="" class="size-10 shrink-0 rounded-xl" width="40" height="40" data-no-dim />

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span
                            class="size-2 shrink-0 rounded-full"
                            x-bind:class="item.sticky ? 'bg-primary dark:bg-status-critical motion-safe:animate-pulse' : 'bg-status-warn'"
                            aria-hidden="true"
                        ></span>
                        <p class="min-w-0 truncate text-[11px] font-semibold uppercase tracking-[0.1em] text-secondary-medium dark:text-ink-muted" x-text="item.module_label"></p>
                        <span x-show="item.sticky" class="shrink-0 text-[11px] font-bold uppercase tracking-[0.1em] text-status-critical">{{ __('notifications.toast.critical') }}</span>
                        <span class="ms-auto shrink-0 text-[11px] text-secondary-light" x-text="ago(item)" data-toast-time></span>
                    </div>

                    <p class="mt-1 line-clamp-3 text-sm font-semibold leading-snug" x-text="item.text"></p>

                    <div class="mt-3 flex items-center gap-4 text-sm">
                        <button type="button" x-on:click="open(item)" class="cursor-pointer font-semibold text-status-critical hover:underline focus-visible:underline focus-visible:outline-none" data-toast-open>
                            {{ __('notifications.toast.open') }}
                        </button>
                        <button type="button" x-show="item.read_url" x-on:click="read(item)" class="cursor-pointer font-medium text-secondary-medium hover:text-secondary-dark focus-visible:underline focus-visible:outline-none dark:text-ink-muted dark:hover:text-ink" data-toast-read>
                            {{ __('notifications.mark_read') }}
                        </button>
                    </div>
                </div>

                <button
                    type="button"
                    x-on:click="dismiss(item.id)"
                    class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-secondary-light hover:bg-secondary-soft/60 hover:text-secondary-dark dark:hover:bg-white/10 dark:hover:text-ink"
                    aria-label="{{ __('notifications.toast.dismiss') }}"
                >
                    <flux:icon.x-mark variant="micro" />
                </button>
            </div>

            <div x-show="! item.sticky" class="absolute inset-x-0 bottom-0 h-0.5 bg-secondary-soft/60 dark:bg-white/10" aria-hidden="true">
                <div class="hc-toast-countdown h-full origin-left bg-primary dark:bg-ink-muted rtl:origin-right" x-bind:style="{ animationPlayState: item.paused ? 'paused' : 'running' }"></div>
            </div>
        </article>
    </template>
</section>
