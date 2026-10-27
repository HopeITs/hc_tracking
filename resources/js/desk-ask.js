/**
 * The Ask bar's palette (components/desk/⚡ask). Opening, closing and the
 * highlighted row live here so the keyboard never waits on the server;
 * Livewire only refreshes the results as the query changes.
 *
 * Opens from the bar (dk-ask-open) or "/" when nobody is typing; closes on
 * Esc, the scrim, or after a row is chosen.
 */

function isTyping(target) {
    return target instanceof HTMLElement
        && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

/**
 * An Ask action ("new proposal") opens a register with ?quick=create: once
 * the page is in, press its own create button (data-quick-create) so the form
 * opens with the page's usual permissions and state, then drop the flag from
 * the address so Back and refresh do not open it again.
 */
document.addEventListener('livewire:navigated', () => {
    const url = new URL(window.location.href);

    if (url.searchParams.get('quick') !== 'create') {
        return;
    }

    url.searchParams.delete('quick');
    window.history.replaceState(window.history.state, '', url);
    requestAnimationFrame(() => document.querySelector('[data-quick-create]')?.click());
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcDeskAsk', () => ({
        open: false,
        selected: 0,

        init() {
            // A new result list (Livewire morph) starts the highlight at the top again.
            this.$watch('$wire.query', () => {
                this.selected = 0;
            });
        },

        show() {
            if (this.open) {
                return;
            }

            this.open = true;
            this.selected = 0;
            window.hcLockPageScroll?.();
            // The input is teleported to <body>, so wait for x-show to paint it before focusing.
            this.$nextTick(() => requestAnimationFrame(() => document.getElementById('dk-pal-q')?.focus()));
        },

        hide() {
            if (! this.open) {
                return;
            }

            this.open = false;
            window.hcUnlockPageScroll?.();
            document.querySelector('[data-desk-ask]')?.focus();
        },

        onGlobalKey(event) {
            // Keys typed while the palette is still opening land in its input instead of being lost.
            const input = document.getElementById('dk-pal-q');

            if (this.open && input && event.target !== input && event.key.length === 1 && ! event.metaKey && ! event.ctrlKey && ! event.altKey) {
                event.preventDefault();
                input.value += event.key;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();

                return;
            }

            if (event.key === '/' && ! this.open && ! isTyping(event.target) && ! event.metaKey && ! event.ctrlKey && ! event.altKey) {
                event.preventDefault();
                this.show();
            }
        },

        rows() {
            return this.$refs.list ? [...this.$refs.list.querySelectorAll('[data-pi]')] : [];
        },

        move(step) {
            const count = this.rows().length;

            if (count === 0) {
                return;
            }

            this.selected = Math.min(Math.max(this.selected + step, 0), count - 1);
            this.rows()[this.selected]?.scrollIntoView({ block: 'nearest' });
        },

        choose() {
            this.rows()[this.selected]?.click();
        },

        // A suggestion chip asks its question as if it had been typed.
        ask(question) {
            const input = document.getElementById('dk-pal-q');

            this.$wire.set('query', question);

            if (input) {
                input.value = question;
                input.focus();
            }
        },
    }));
});
