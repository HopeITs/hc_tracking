/**
 * The top bar (components/desk/top-bar).
 *
 * hcTopMenu drives the Help and avatar menus as real menus: the arrow keys,
 * Home and End move between items, Esc closes and gives focus back to the
 * button, Tab closes. Opening with the keyboard (Arrow Down) focuses the
 * first item; a click leaves focus on the button.
 *
 * <html class="dk-scrolled"> is set while the page is scrolled, so the bar
 * lifts off the page with a shadow only when there is something under it.
 */

const items = (menu) => [...menu.querySelectorAll('[role="menuitem"], [role="menuitemcheckbox"]')].filter((item) => item.offsetParent !== null);

function syncScrolled() {
    document.documentElement.classList.toggle('dk-scrolled', window.scrollY > 2);
}

let scrollFrame = 0;

window.addEventListener('scroll', () => {
    cancelAnimationFrame(scrollFrame);
    scrollFrame = requestAnimationFrame(syncScrolled);
}, { passive: true });

document.addEventListener('livewire:navigated', syncScrolled);
syncScrolled();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcTopMenu', () => ({
        open: false,

        toggle() {
            this.open ? this.close() : this.show(false);
        },

        show(focusFirst) {
            this.open = true;

            if (focusFirst) {
                this.$nextTick(() => items(this.$refs.menu)[0]?.focus());
            }
        },

        close(returnFocus = false) {
            if (! this.open) {
                return;
            }

            this.open = false;

            if (returnFocus) {
                this.$refs.trigger.focus();
            }
        },

        navigate(event) {
            const list = items(this.$refs.menu);
            const at = list.indexOf(document.activeElement);
            const go = (index) => {
                event.preventDefault();
                list[(index + list.length) % list.length]?.focus();
            };

            switch (event.key) {
                case 'ArrowDown':
                    go(at + 1);
                    break;
                case 'ArrowUp':
                    go(at < 0 ? list.length - 1 : at - 1);
                    break;
                case 'Home':
                    go(0);
                    break;
                case 'End':
                    go(list.length - 1);
                    break;
                case 'Tab':
                    this.close();
                    break;
            }
        },

        // The tour hands focus back to the Help button when it ends, not to the hidden menu item.
        startTour() {
            this.close();
            window.dispatchEvent(new CustomEvent('hc-tour-start', { detail: { opener: this.$refs.trigger } }));
        },
    }));
});
