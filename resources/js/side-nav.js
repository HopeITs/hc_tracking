/**
 * The application sidebar (components/desk/side-nav).
 *
 * Desktop: an icon rail that expands to labels. The state lives on
 * <html class="dk-nav-open"> (set before first paint by partials/head from
 * localStorage "hc-nav"), so wire:navigate, which keeps <html>, never
 * flashes the other state. On the rail, hovering a group shows its links in a
 * fly-out beside the rail; hovering a plain link shows its label in a tip.
 * Clicking a group on the rail expands the sidebar and opens that group.
 *
 * Expanded, each group folds open in place; the groups a person opened are
 * remembered (localStorage "hc-nav-groups"), and the group holding the
 * current page always opens.
 *
 * Pins: the star beside a link pins that page to the top of the sidebar
 * (POST nav/pins, stored on the user). The sidebar has no keyboard shortcuts.
 *
 * Below 900px the rail is hidden; the top bar's menu button lays the full
 * sidebar over the page (<html class="dk-nav-mobile">). Esc, the scrim or
 * following a link closes it.
 */

const desktop = () => window.matchMedia('(min-width: 900px)').matches;

function closeMobile() {
    document.documentElement.classList.remove('dk-nav-mobile');
}

function readGroups() {
    try {
        const saved = JSON.parse(localStorage.getItem('hc-nav-groups') ?? '[]');

        return Array.isArray(saved) ? saved : [];
    } catch {
        return [];
    }
}

window.hcSideNavClose = closeMobile;

window.hcSideNavOpenMobile = () => {
    document.documentElement.classList.add('dk-nav-mobile');
};

document.addEventListener('livewire:navigating', closeMobile);

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.documentElement.classList.contains('dk-nav-mobile')) {
        closeMobile();
    }
});

document.addEventListener('alpine:init', () => {
    window.Alpine.store('hcNav', {
        groups: readGroups(),

        isOpen(group) {
            return this.groups.includes(group);
        },

        remember(group, open) {
            this.groups = open ? [...new Set([...this.groups, group])] : this.groups.filter((name) => name !== group);

            try {
                localStorage.setItem('hc-nav-groups', JSON.stringify(this.groups));
            } catch {
                // Without storage the groups simply start closed next time.
            }
        },
    });

    window.Alpine.data('hcSideNav', (config = {}) => ({
        expanded: document.documentElement.classList.contains('dk-nav-open'),
        pins: config.pins ?? [],
        pages: config.pages ?? {},
        context: config.context ?? false,
        tipText: '',
        tipTop: 0,
        tipInset: 0,
        tipSide: 'left',
        fly: null,
        flyStyle: {},
        flyAnchor: null,
        flyTimer: null,
        hideTimer: null,

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

        /*
         * The unread counts changed in the bell (mark all read, one read, a new
         * arrival): redraw every badge and dot from the fresh per-module counts,
         * since the sidebar itself is only rendered once per page.
         */
        syncCounts(modules) {
            const label = (count) => (count > 99 ? '99+' : String(count));
            const countOf = (module) => Number(modules[module] ?? 0);
            const show = (host, count, before = null) => {
                let badge = host.querySelector(':scope > [data-side-badge]');

                if (count <= 0) {
                    badge?.remove();

                    return;
                }

                if (! badge) {
                    badge = document.createElement('span');
                    badge.className = 'dk-side-badge dk-num';
                    badge.setAttribute('data-side-badge', '');
                    host.insertBefore(badge, before);
                }

                badge.textContent = label(count);
            };

            this.$root.querySelectorAll('a[data-side-module]').forEach((link) => {
                show(link, countOf(link.dataset.sideModule));
            });

            this.$root.querySelectorAll('[data-side-group-modules]').forEach((toggle) => {
                const total = toggle.dataset.sideGroupModules
                    .split(',')
                    .filter(Boolean)
                    .reduce((sum, module) => sum + countOf(module), 0);

                show(toggle, total, toggle.querySelector('.dk-side-gchev'));
            });

            Object.values(this.pages).forEach((page) => {
                page.count = page.module ? countOf(page.module) : 0;
            });
        },

        rail() {
            return ! this.expanded && desktop();
        },

        toggle() {
            this.expanded = ! this.expanded;
            this.untip();
            this.unfly();

            // The width switches at once: the sidebar never animates.
            document.documentElement.classList.toggle('dk-nav-open', this.expanded);

            try {
                localStorage.setItem('hc-nav', this.expanded ? 'open' : 'rail');
            } catch {
                // Private windows may refuse storage; the toggle still works for this page.
            }
        },

        /*
         * A group row opens its links. On the collapsed desktop rail the links
         * have no room, so the click first expands the sidebar, then opens it.
         */
        openGroup(group, name) {
            if (this.rail()) {
                this.toggle();
                group.open = true;
                this.$store.hcNav.remember(name, true);

                return;
            }

            group.open = ! group.open;
            this.$store.hcNav.remember(name, group.open);
        },

        async pin(route) {
            const was = [...this.pins];
            this.pins = this.pins.includes(route) ? this.pins.filter((name) => name !== route) : [...this.pins, route];

            try {
                const response = await fetch(config.pinUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ route }),
                });

                if (! response.ok) {
                    throw new Error(String(response.status));
                }

                this.pins = (await response.json()).pins ?? this.pins;
            } catch {
                this.pins = was;
            }
        },

        hover(event) {
            const target = event.target instanceof Element ? event.target : null;
            const flyer = target?.closest('[data-fly]');

            clearTimeout(this.hideTimer);

            if (flyer && this.rail()) {
                this.untip();
                clearTimeout(this.flyTimer);
                this.flyTimer = setTimeout(() => this.showFly(flyer.dataset.fly, flyer), this.fly ? 0 : 120);

                return;
            }

            this.tip(event);
        },

        /*
         * The fly-out opens beside the icon it belongs to: its title row lines
         * up with the icon, a pointer on its edge aims at the icon's center,
         * and the icon stays highlighted while it is open. Near the bottom of
         * the screen the panel moves up, and the pointer still finds the icon.
         */
        showFly(name, anchor) {
            const box = anchor.getBoundingClientRect();
            const rtl = document.documentElement.dir === 'rtl';
            const center = box.top + box.height / 2;
            const side = rtl
                ? { right: `${window.innerWidth - box.left + 10}px`, left: 'auto' }
                : { left: `${box.right + 10}px`, right: 'auto' };

            this.flyAnchor?.classList.remove('dk-flying');
            this.flyAnchor = anchor;
            anchor.classList.add('dk-flying');

            // An object, not a string: a string binding replaces the whole style attribute and wipes x-show's display:none on the other fly-outs.
            const place = (height) => {
                const top = Math.max(8, Math.min(center - 26, window.innerHeight - height - 8));

                this.flyStyle = { ...side, top: `${top}px`, '--dk-fly-caret': `${center - top}px` };
            };

            place(0);
            this.fly = name;
            this.$nextTick(() => {
                const panel = this.$root.querySelector(`[data-side-fly="${name}"]`);

                if (panel) {
                    place(panel.offsetHeight);
                }
            });
        },

        keepFly() {
            clearTimeout(this.hideTimer);
        },

        leave() {
            clearTimeout(this.flyTimer);
            this.untip();
            this.hideTimer = setTimeout(() => this.unfly(), 220);
        },

        unfly() {
            this.fly = null;
            this.flyAnchor?.classList.remove('dk-flying');
            this.flyAnchor = null;
        },

        tip(event) {
            const link = event.target instanceof Element ? event.target.closest('[data-label]') : null;

            if (! link || ! this.rail()) {
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
