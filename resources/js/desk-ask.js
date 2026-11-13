/**
 * The Ask bar's palette (components/desk/⚡ask). Opening, closing and the
 * highlighted row live here so the keyboard never waits on the server.
 *
 * Two layers answer what is typed:
 *  - instantly, on every key, the index shipped with the page
 *    (App\Support\Desk\AskCommands, searched by ask-index.js): pages,
 *    "New …" actions, putting things on or off My desk, Desk tabs and the
 *    Desk's own commands. Enter runs the highlighted row; Alt+Enter puts a
 *    page or action on the Desk instead of opening it;
 *  - after a short pause, Livewire's answers to questions and the records
 *    found by name.
 * One highlight runs across both (it is drawn here, not by either layer).
 *
 * Opens from the bar (dk-ask-open), Ctrl/⌘ K, or "/" when nobody is typing;
 * closes on Esc, the scrim, or after a row is chosen.
 */

import { search } from './ask-index';

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
        instant: [],
        instantRecent: false,
        instantCorrected: null,
        flash: '',
        texts: {},
        config: null,
        typed: '',

        init() {
            this.config = readIndex();
            this.texts = this.config?.texts ?? {};

            // A new result list (Livewire morph) starts the highlight at the top again, and a morph never takes the highlight away.
            this.$watch('$wire.query', () => {
                this.selected = 0;
                this.$nextTick(() => this.sync());
            });

            const observer = new MutationObserver(() => this.sync());

            this.$nextTick(() => {
                const list = document.querySelector('.dk-pal-list');

                if (list) {
                    observer.observe(list, { childList: true, subtree: true });
                }
            });

            window.addEventListener('dk-desk-changed', (event) => {
                if (this.config && event.detail?.item) {
                    const placed = new Set(this.config.index.placed);

                    event.detail.placed ? placed.add(event.detail.item) : placed.delete(event.detail.item);
                    this.config.index.placed = [...placed];
                }
            });
        },

        show() {
            if (this.open) {
                return;
            }

            this.open = true;
            this.selected = 0;
            this.flash = '';
            this.type(document.getElementById('dk-pal-q')?.value ?? '');
            window.hcLockPageScroll?.();
            // The input is teleported to <body>, so wait for x-show to paint it before focusing.
            this.$nextTick(() => requestAnimationFrame(() => document.getElementById('dk-pal-q')?.focus()));
        },

        hide() {
            if (! this.open) {
                return;
            }

            this.open = false;
            this.reset();
            window.hcUnlockPageScroll?.();
            document.querySelector('[data-desk-ask]')?.focus();
        },

        /** Closing forgets what was typed: the next open starts from an empty box. */
        reset() {
            const input = document.getElementById('dk-pal-q');

            if (input) {
                input.value = '';
            }

            this.typed = '';
            this.instant = [];
            this.selected = 0;
            this.flash = '';

            if (this.$wire.query !== '') {
                this.$wire.$set('query', '');
            }
        },

        /**
         * A page or "New …" action row can be carried out of the palette on My desk: onto the tab bar to
         * open it as a tab, or onto Quick access to keep it there (resources/js/desk-drop.js reads this).
         */
        carry(row) {
            if (! row.href || (row.kind !== 'page' && row.kind !== 'action') || ! document.querySelector('[data-desk-tabs]')) {
                return null;
            }

            return JSON.stringify({ key: row.key, kind: row.kind, href: row.href, label: row.label, icon: row.icon });
        },

        /** The instant rows for what is in the box, on every key. */
        type(value) {
            this.typed = value;

            if (! this.config) {
                this.instant = [];

                return;
            }

            const tabs = window.hcDeskTabs?.list?.() ?? [];
            const rows = search(value, {
                index: this.config.index,
                texts: this.texts,
                recent: recentFor(this.config.user),
                tabs,
                onDesk: Boolean(document.querySelector('[data-desk-grid]')),
                canCustomize: Boolean(document.querySelector('[data-desk-customize]')),
                dark: document.documentElement.classList.contains('dark'),
                otherLanguage: this.config.otherLanguage,
            });

            this.instant = rows;
            this.instantRecent = value.trim() === '' && rows.length > 0;
            this.instantCorrected = rows[0]?.corrected ?? null;
            this.selected = 0;
            this.$nextTick(() => this.sync());
        },

        onGlobalKey(event) {
            // Ctrl K (⌘ K on a Mac) opens or closes Ask from anywhere, even from inside a field.
            if ((event.ctrlKey || event.metaKey) && ! event.altKey && ! event.shiftKey && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.open ? this.hide() : this.show();

                return;
            }

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

        /** Every row that can be chosen, in the order shown (instant first, then the server's). */
        rows() {
            return this.$refs.list ? [...this.$refs.list.querySelectorAll('[data-pi]')].filter((row) => row.offsetParent !== null) : [];
        },

        /** Draw the highlight on the selected row only. */
        sync() {
            const rows = this.rows();

            if (this.selected >= rows.length) {
                this.selected = Math.max(0, rows.length - 1);
            }

            rows.forEach((row, at) => {
                if (row.classList.contains('dk-sel') !== (at === this.selected)) {
                    row.classList.toggle('dk-sel', at === this.selected);
                }
            });

            this.$refs.list?.querySelectorAll('.dk-sel').forEach((row) => {
                if (! rows.includes(row)) {
                    row.classList.remove('dk-sel');
                }
            });
        },

        move(step) {
            const rows = this.rows();

            if (rows.length === 0) {
                return;
            }

            this.selected = Math.min(Math.max(this.selected + step, 0), rows.length - 1);
            this.sync();
            rows[this.selected]?.scrollIntoView({ block: 'nearest' });
        },

        choose(event) {
            const row = this.rows()[this.selected];

            if (! row) {
                return;
            }

            const instant = row.dataset.askRow ? this.instant.find((each) => each.key === row.dataset.askRow) : null;

            // Alt+Enter: put the page or action on the Desk instead of opening it.
            if (instant && event?.altKey && (instant.kind === 'page' || instant.kind === 'action') && instant.item) {
                this.runRow({ ...instant, kind: 'add', label: `“${instant.label}”` });

                return;
            }

            // Shift+Enter on a page: open it as a full page, not in a Desk tab.
            if (instant?.href && event?.shiftKey) {
                remember(this.config?.user, instant.key);
                this.hide();
                window.location.assign(instant.href);

                return;
            }

            row.click();
        },

        // A suggestion chip asks its question as if it had been typed.
        ask(question) {
            const input = document.getElementById('dk-pal-q');

            this.$wire.set('query', question);

            if (input) {
                input.value = question;
                input.focus();
            }

            this.type(question);
        },

        /** Run an instant row. Ctrl/⌘ or middle click on a page keeps the browser's new tab. */
        async runRow(row, event = null) {
            if (event && row.href && (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1)) {
                return;
            }

            event?.preventDefault();
            remember(this.config?.user, row.key);

            switch (row.kind) {
                case 'page':
                case 'action':
                    this.hide();
                    window.Livewire?.navigate(row.href);
                    break;
                case 'tab':
                    this.hide();
                    window.hcDeskTabs?.activate(row.tab);
                    break;
                case 'close-tab':
                    window.hcDeskTabs?.close(row.tab);
                    this.type(this.typed);
                    break;
                case 'close-all':
                    this.hide();
                    window.hcDeskTabs?.closeAll();
                    break;
                case 'add':
                    await this.place(row);
                    break;
                case 'remove':
                    await this.unplace(row);
                    break;
                case 'command':
                    this.command(row);
                    break;
                default:
                    break;
            }
        },

        /** Put a page or card on My desk, or a button in Quick access; on the Desk itself it lands with its Undo. */
        async place(row) {
            const name = row.label.replace(/^.*?“(.*)”.*$/, '$1');
            const quick = /^[ag]-/.test(row.item ?? '');

            if (this.config.index.placed.includes(row.item)) {
                this.say((quick ? this.texts.quick_already : this.texts.already).replace('__P__', name));
                window.hcDeskTabs?.desk();
                document.getElementById(`dk-panel-${row.item}`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

                return;
            }

            try {
                await window.hcDeskItems.placeItem(row.item);
                this.config.index.placed = [...this.config.index.placed, row.item];
                row.done = true;
                this.instant = [...this.instant];
                window.hcDeskTabs?.desk();
                this.say((quick ? this.texts.quick_placed : this.texts.placed).replace('__P__', name), true);
            } catch {
                this.say(this.texts.failed);
            }
        },

        async unplace(row) {
            const name = row.label.replace(/^.*?“(.*)”.*$/, '$1');
            const quick = /^[ag]-/.test(row.item ?? '');

            try {
                await window.hcDeskItems.toggle({ item: row.item, placed: false });
                this.config.index.placed = this.config.index.placed.filter((item) => item !== row.item);
                row.done = true;
                this.instant = [...this.instant];
                this.say((quick ? this.texts.quick_unplaced : this.texts.unplaced).replace('__P__', name), true);
            } catch {
                this.say(this.texts.failed);
            }
        },

        command(row) {
            this.hide();

            switch (row.command) {
                case 'theme':
                    window.hcToggleAppearance?.();
                    break;
                case 'language':
                    document.querySelector('[data-locale-toggle] button[name="locale"]')?.click();
                    break;
                case 'customize':
                    window.hcDeskTabs?.desk();
                    window.dispatchEvent(new CustomEvent('dk-customize-open', { detail: { pane: 'desk' } }));
                    break;
                case 'layout':
                    window.hcDeskTabs?.desk();
                    window.dispatchEvent(new CustomEvent('dk-customize-open', { detail: { pane: 'layouts', layout: row.layout } }));
                    break;
                case 'tour':
                    window.dispatchEvent(new CustomEvent('hc-tour-start', { detail: { opener: document.querySelector('[data-desk-ask]') } }));
                    break;
                case 'desk':
                    window.hcDeskTabs ? window.hcDeskTabs.desk() : window.Livewire?.navigate(this.config.desk);
                    break;
                default:
                    break;
            }
        },

        /** A short line in the palette; a done action closes it a moment later. */
        say(text, thenClose = false) {
            this.flash = text;
            clearTimeout(this.flashTimer);
            this.flashTimer = setTimeout(() => {
                this.flash = '';

                if (thenClose) {
                    this.hide();
                }
            }, thenClose ? 900 : 2400);
        },
    }));
});

/** The index shipped with the page (components/desk/ask-index), read once per page. */
function readIndex() {
    try {
        const node = document.querySelector('[data-ask-index]');

        return node ? JSON.parse(node.textContent) : null;
    } catch {
        return null;
    }
}

/** What this person picked in the Ask bar, and how often: kept in this browser only. */
function recentFor(user) {
    try {
        return JSON.parse(window.localStorage.getItem(`hc-ask-recent:${user}`) ?? '{}') ?? {};
    } catch {
        return {};
    }
}

function remember(user, key) {
    if (! user || ! key) {
        return;
    }

    try {
        const recent = recentFor(user);
        const seen = recent[key] ?? { n: 0 };

        recent[key] = { n: Math.min(seen.n + 1, 50), t: Date.now() };

        const kept = Object.fromEntries(Object.entries(recent).sort((a, b) => b[1].t - a[1].t).slice(0, 40));

        window.localStorage.setItem(`hc-ask-recent:${user}`, JSON.stringify(kept));
    } catch {
        // Storage blocked: nothing is remembered, everything still works.
    }
}

