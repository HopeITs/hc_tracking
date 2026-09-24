/**
 * Shared page-scroll lock for hand-rolled Alpine popovers (see
 * x-ui.multi-select-popover), mirroring the lock Flux's own ui-dropdown
 * applies to <html> while a menu is open, so a custom popover blocks page
 * scroll the same way a Flux dropdown does instead of letting scroll chain
 * through to the page behind it.
 */

let count = 0;
let restore = null;

function lockPageScroll() {
    count += 1;

    if (count > 1) {
        return;
    }

    const html = document.documentElement;
    restore = { overflow: html.style.overflow, paddingRight: html.style.paddingRight };
    html.style.paddingRight = `${window.innerWidth - html.clientWidth}px`;
    html.style.overflow = 'hidden';
}

function unlockPageScroll() {
    count = Math.max(0, count - 1);

    if (count > 0 || restore === null) {
        return;
    }

    const html = document.documentElement;
    html.style.overflow = restore.overflow;
    html.style.paddingRight = restore.paddingRight;
    restore = null;
}

window.hcLockPageScroll = lockPageScroll;
window.hcUnlockPageScroll = unlockPageScroll;

// A wire:navigate swap can remove a still-open popover without firing its
// close handlers, which would otherwise leave the lock stuck forever.
document.addEventListener('livewire:navigate', () => {
    count = 0;
    unlockPageScroll();
});
