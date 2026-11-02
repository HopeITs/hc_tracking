/**
 * The Desk hero (pages/desk/⚡index). deskHint: the first-visit "drag to move · pull edges to resize" bubble,
 *   dismissed for good in this browser (dk-hint-seen).
 * Storage may be blocked, so every read and write is guarded.
 */
const hintKey = 'dk-hint-seen';

function read(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Not kept (private window): applies until the page reloads.
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('deskHint', () => ({
        shown: read(hintKey) !== '1',

        dismiss() {
            this.shown = false;
            write(hintKey, '1');
        },
    }));
});
