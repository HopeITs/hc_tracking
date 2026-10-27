/**
 * The application sidebar (components/desk/side-nav).
 *
 * Desktop: an icon rail that expands to labels. The state lives on
 * <html class="dk-nav-open"> (set before first paint by partials/head from
 * localStorage "hc-nav"), so wire:navigate, which keeps <html>, never
 * flashes the other state. While collapsed, hovering or focusing a link
 * shows its label in a floating tip, because the rail's own scroll box
 * would clip a CSS tooltip.
 *
 * Below 900px the rail is hidden; the top bar's menu button lays the full
 * sidebar over the page (<html class="dk-nav-mobile">). Esc, the scrim or
 * following a link closes it.
 */

const desktop = () => window.matchMedia('(min-width: 900px)').matches;

function closeMobile() {
    document.documentElement.classList.remove('dk-nav-mobile');
}

window.hcSideNavClose = closeMobile;

window.hcSideNavOpenMobile = () => {
    document.documentElement.classList.add('dk-nav-mobile');
};

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.documentElement.classList.contains('dk-nav-mobile')) {
        closeMobile();
    }
});

document.addEventListener('livewire:navigating', closeMobile);

document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcSideNav', () => ({
        expanded: document.documentElement.classList.contains('dk-nav-open'),
        tipText: '',
        tipTop: 0,
        tipInset: 0,
        tipSide: 'left',

        // Keep the rail where it was scrolled: wire:navigate re-renders it on every page.
        init() {
            try {
                const saved = Number(sessionStorage.getItem('hc-nav-scroll') ?? 0);

                if (saved > 0) {
                    this.$refs.scroll.scrollTop = saved;
                }
            } catch {
                // Storage may be refused; the rail then starts at the top.
            }
        },

        remember() {
            try {
                sessionStorage.setItem('hc-nav-scroll', String(this.$refs.scroll.scrollTop));
            } catch {
                // Nothing to keep without storage.
            }
        },

        toggle() {
            this.expanded = ! this.expanded;
            this.untip();

            // Only a click animates the width; restoring the saved state on page load never does.
            const html = document.documentElement;
            html.classList.add('dk-nav-animate');
            html.classList.toggle('dk-nav-open', this.expanded);
            clearTimeout(this.animationTimer);
            this.animationTimer = setTimeout(() => html.classList.remove('dk-nav-animate'), 300);

            try {
                localStorage.setItem('hc-nav', this.expanded ? 'open' : 'rail');
            } catch {
                // Private windows may refuse storage; the toggle still works for this page.
            }
        },

        tip(event) {
            const link = event.target instanceof Element ? event.target.closest('[data-label]') : null;

            if (! link || this.expanded || ! desktop()) {
                this.untip();

                return;
            }

            const box = link.getBoundingClientRect();
            const rtl = document.documentElement.dir === 'rtl';

            this.tipText = link.dataset.label;
            this.tipTop = box.top + box.height / 2;
            this.tipSide = rtl ? 'right' : 'left';
            this.tipInset = rtl ? window.innerWidth - box.left + 10 : box.right + 10;
        },

        untip() {
            this.tipText = '';
        },
    }));
});
