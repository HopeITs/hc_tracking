{{--
    Overrides Flux's toast (vendor/livewire/flux/stubs/.../toast/index.blade.php)
    with markup the app owns, styled in resources/css/flux-overrides.css by its
    hc-toast-* classes. Flux's script still fills it: the heading/text <slot>s,
    the link/action <template>s, the data-flux-toast-* hooks, data-variant on
    the dialog and <ui-close> must all stay.

    A paper card in one row: the variant's badge, the words, at most one
    button, the close X, and the time-left line along the bottom. CSS shows
    the badge icon matching data-variant; a toast without a variant ("") reads
    as neutral information.
--}}
@props([
    'position' => 'bottom end',
    'invert' => false,
])

<ui-toast x-data x-on:toast-show.document="! $el.closest('ui-toast-group') && $el.showToast($event.detail)" popover="manual" position="{{ $position }}" wire:ignore>
    <template>
        <div {{ $attributes->only(['class'])->class('hc-toast') }} data-variant="" data-flux-toast-dialog>
            <div class="hc-toast-card">
                <span class="hc-toast-badge" aria-hidden="true">
                    <svg class="hc-toast-icon" data-icon="success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round">
                        <path class="hc-toast-tick" d="M5.5 12.5l4.25 4.25L18.5 8" pathLength="1" />
                    </svg>
                    <svg class="hc-toast-icon" data-icon="alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round">
                        <path d="M12 6.75v6.5" />
                        <path d="M12 17.25h.01" />
                    </svg>
                    <svg class="hc-toast-icon" data-icon="info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round">
                        <path d="M12 6.75h.01" />
                        <path d="M12 10.75v6.5" />
                    </svg>
                </span>

                <div class="hc-toast-body">
                    <p class="hc-toast-heading"><slot name="heading"></slot></p>
                    <p class="hc-toast-text"><slot name="text"></slot></p>
                </div>

                <div class="hc-toast-actions">
                    <template name="link">
                        <a class="hc-toast-button"><slot name="text"></slot></a>
                    </template>

                    <template name="action">
                        <div class="hc-toast-action" data-flux-toast-action>
                            <button type="button" class="hc-toast-button" data-flux-toast-action-button>
                                <span data-flux-toast-action-label></span>
                                <span class="hc-toast-spinner" data-flux-loading-indicator aria-hidden="true"></span>
                            </button>

                            <a class="hc-toast-button" data-flux-toast-action-link>
                                <span data-flux-toast-action-label></span>
                            </a>
                        </div>
                    </template>
                </div>

                <ui-close class="hc-toast-close">
                    <button type="button" aria-label="{{ __('common.close') }}">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true">
                            <path d="M5.75 5.75l8.5 8.5M14.25 5.75l-8.5 8.5" />
                        </svg>
                    </button>
                </ui-close>

                <span class="hc-toast-timer" aria-hidden="true"></span>
            </div>
        </div>
    </template>
</ui-toast>
