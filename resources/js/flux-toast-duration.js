/**
 * Flux::toast() hardcodes a 5s default duration (vendor/livewire/flux/src/Concerns/
 * InteractsWithComponents.php) and no call site in this app overrides it, so every
 * toast in the app lives for 5s. The app wants 3s instead, and there is no config
 * knob for it.
 *
 * Intercepting the `toast-show` event never works here: Livewire's own dispatch()
 * captures its own reference to the real `EventTarget.prototype.dispatchEvent`
 * before any page script runs, so nothing dispatched through it is observable by
 * patching that prototype or listening on `document`/`window` afterwards. Instead,
 * this watches for a toast dialog actually landing in the DOM and, after 3s,
 * clicks its own close button — the same thing a user closing it early does, so
 * it stays inside Flux's normal dismissal path rather than fighting it. Flux's own
 * 5s timer still fires later and finds nothing left to remove, which is harmless.
 */
const DISMISS_AFTER_MS = 3000;
const DIALOG_SELECTOR = '[data-flux-toast-dialog]';

function dismissEarly(dialog) {
    setTimeout(() => dialog.querySelector('ui-close button')?.click(), DISMISS_AFTER_MS);
}

new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
            if (!(node instanceof HTMLElement)) {
                continue;
            }

            if (node.matches(DIALOG_SELECTOR)) {
                dismissEarly(node);
            }

            node.querySelectorAll?.(DIALOG_SELECTOR).forEach(dismissEarly);
        }
    }
}).observe(document.documentElement, { childList: true, subtree: true });
