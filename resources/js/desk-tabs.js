/**
 * Desk tabs (pages/desk/_tabs.blade.php). On My desk the Desk is the browser:
 * a page opened from a panel, a button, the sidebar, a notification or the Ask
 * bar loads as a tab above the grid instead of replacing the Desk. Each tab is
 * the real page in a same-origin frame, rendered without its own frame
 * (App\Support\Desk\DeskTab), so every form, modal and permission works exactly
 * as on the page itself. resources/js/desk-tab-child.js is the frame's side.
 *
 * Speed:
 *  - a link is warmed the moment it is pressed: its frame starts loading
 *    hidden and the click, a tenth of a second later, adopts it. Nothing is
 *    loaded on hover: a pointer crossing the sidebar or a list of rows asked
 *    the server for a whole page per link it passed, and those pages stood
 *    in line ahead of the one the person then opened;
 *  - a tab, once loaded, stays alive: switching is instant and keeps the
 *    page's scroll, filters and half-typed forms;
 *  - only MaxLive frames stay alive; the least recently used sleeps (its
 *    frame is dropped) and loads again when it is next shown;
 *  - after a reload only the tab in front loads; the rest wait to be shown.
 *
 * Links: a plain click opens a tab (or shows the tab already open on that
 * page); Ctrl/⌘ or the middle button opens one in the background; Shift keeps
 * the browser's own behaviour. A link to the Desk shows the Desk tab. Opening
 * is intercepted before Livewire sees the press, so wire:navigate does not
 * fetch a page that is then thrown away; livewire:navigate catches the rest
 * (Enter on a link, a component's redirect).
 *
 * Keyboard: Alt+0 the Desk, Alt+1…9 a tab, Alt+W closes the tab in front;
 * in the strip, arrows move between tabs and Delete closes one. Tabs are
 * kept per user in this browser (localStorage), never on the server.
 */

const MaxLive = 6;
const MaxTabs = 24;
const WarmTtl = 4000;
/* How long the pointer rests on a link before its tab frame starts loading. */
const HoverIntentMs = 120;
/* Links that react to hover or focus themselves keep those events. */
const HoverListeners = ['x-on:mouseenter', '@mouseenter', 'x-on:focus', '@focus'];
const NotForTabs = /\/(download|export|print|preview|avatar|logout)(\/|$)|^\/(livewire|broadcasting|storage)\//;

let firstLoad = true;
document.addEventListener('livewire:navigating', () => {
    firstLoad = false;
});

/* Desk tabs are always on (user decision 2026-11-10): a browser that once turned them off forgets it. */
try {
    window.localStorage.removeItem('hc-desk-tabs-off');
} catch {
    // Storage blocked: nothing was kept.
}

function isTyping(target) {
    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('hcDeskTabs', (config) => ({
        tabs: [],
        active: 'desk',
        menu: null,
        menuStyle: '',
        dragging: null,
        announce: '',
        texts: config.texts,
        frames: new Map(),
        warm: null,
        hoverTimer: null,
        lastShown: new Map(),
        deskStale: false,
        seq: 0,
        listeners: [],

        init() {
            this.storeKey = `hc-desk-tabs:${config.user}`;
            this.restore();

            window.hcDeskTabs = {
                open: (url, options = {}) => this.open(url, options),
                desk: () => this.activate('desk'),
                close: (id) => this.close(id ?? this.active),
                closeAll: () => this.closeAll(),
                list: () => this.tabs.map(({ id, title, url }) => ({ id, title, url, active: this.active === id })),
                activate: (id) => this.activate(id),
                activeTab: () => this.tabs.find((tab) => tab.id === this.active) ?? null,
            };

            this.listen(document, 'mouseenter', (event) => this.onHover(event), true);
            this.listen(document, 'focus', (event) => this.onHover(event), true);
            this.listen(document, 'mouseleave', (event) => this.onUnhover(event), true);
            this.listen(document, 'blur', (event) => this.onUnhover(event), true);
            this.listen(document, 'mousedown', (event) => this.onPress(event), true);
            this.listen(document, 'click', (event) => this.onClick(event), true);
            this.listen(document, 'auxclick', (event) => this.onAuxClick(event), true);
            // A press that turned into dragging the link (onto the Desk, desk-drop.js) is not an open.
            this.listen(document, 'dragstart', () => this.dropWarm(), true);
            this.listen(document, 'livewire:navigate', (event) => this.onNavigate(event));
            this.listen(document, 'keydown', (event) => this.onKey(event));
            this.listen(window, 'message', (event) => this.onMessage(event));

            this.$watch('active', () => this.syncDocument());
            this.syncDocument();
        },

        destroy() {
            this.listeners.forEach(([target, type, handler, capture]) => target.removeEventListener(type, handler, capture));
            clearTimeout(this.hoverTimer);
            this.dropWarm();
            this.frames.forEach((frame) => frame.remove());
            this.frames.clear();
            document.documentElement.classList.remove('dk-tab-active');

            if (window.hcDeskTabs?.open) {
                delete window.hcDeskTabs;
            }
        },

        listen(target, type, handler, capture = false) {
            target.addEventListener(type, handler, capture);
            this.listeners.push([target, type, handler, capture]);
        },

        /* ── URLs ─────────────────────────────────────────────────── */

        /** The page a link or URL stands for, without the tab marker or a one-off create request. */
        clean(href) {
            const url = new URL(href, window.location.href);

            url.searchParams.delete(config.param);
            url.searchParams.delete('quick');
            url.hash = '';

            return url;
        },

        keyOf(href) {
            const url = this.clean(href);

            return url.pathname + url.search;
        },

        frameUrl(href) {
            const url = new URL(href, window.location.href);

            url.searchParams.set(config.param, '1');

            return url.toString();
        },

        isDesk(href) {
            const url = new URL(href, window.location.href);

            return url.origin === window.location.origin && url.pathname === config.desk && ! url.search;
        },

        /** Whether a link opens as a tab: same site, a page (not a file), no target, not marked native. */
        tabLink(event) {
            if (! (event.target instanceof Element)) {
                return null;
            }

            const link = event.target.closest('a[href]');

            if (! link || link.closest('[data-desk-native], [data-desk-tabs], .dk-tabs-bar') || link.hasAttribute('download')) {
                return null;
            }

            if (link.target && link.target !== '_self') {
                return null;
            }

            const url = new URL(link.href, window.location.href);

            if (url.origin !== window.location.origin || ! ['http:', 'https:'].includes(url.protocol) || NotForTabs.test(url.pathname)) {
                return null;
            }

            if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) {
                return null;
            }

            return { link, url };
        },

        /** What a page is called before its frame says: its sidebar name, else the link's own text. */
        guessTitle(url, link = null) {
            const page = this.pageOf(url);
            const text = link?.getAttribute('title') || link?.textContent?.replace(/\s+/g, ' ').trim() || '';

            if (text && text.length <= 60) {
                return text;
            }

            return page?.label ?? this.texts.loading;
        },

        pageOf(href) {
            const path = this.clean(href).pathname;
            let best = null;

            Object.entries(config.pages).forEach(([prefix, page]) => {
                if ((path === prefix || path.startsWith(`${prefix}/`)) && (! best || prefix.length > best.prefix.length)) {
                    best = { prefix, ...page };
                }
            });

            return best;
        },

        /* ── Opening links ────────────────────────────────────────── */

        /*
         * Resting on a link that opens as a tab warms its frame after HoverIntentMs, so the click
         * usually finds the page loaded. Every wire:navigate link also carries .hover (see
         * AppServiceProvider::prefetchNavigateLinksOnHover), whose fetch would replace the Desk and is
         * thrown away here: it is stopped before it reaches the link, unless the link listens to
         * hover or focus itself.
         */
        onHover(event) {
            const found = this.tabLink(event);

            if (! found || found.link !== event.target) {
                return;
            }

            if (! HoverListeners.some((name) => found.link.hasAttribute(name))) {
                event.stopPropagation();
            }

            if (this.isDesk(found.url) || found.link.hasAttribute('data-drag-through')) {
                return;
            }

            clearTimeout(this.hoverTimer);
            this.hoverTimer = setTimeout(() => this.warmUp(found.url), HoverIntentMs);
        },

        onUnhover(event) {
            if (event.target instanceof Element && event.target.matches('a[href]')) {
                clearTimeout(this.hoverTimer);
            }
        },

        /* The press, before Livewire's own (which would fetch the page to replace the Desk): stop it, and warm the frame. */
        onPress(event) {
            if (event.button !== 0 || event.shiftKey || event.altKey) {
                return;
            }

            const found = this.tabLink(event);

            if (! found) {
                return;
            }

            event.stopPropagation();

            // A button tile's press may be the start of carrying it (desk-grid.js): it loads on the click instead.
            if (! event.ctrlKey && ! event.metaKey && ! this.isDesk(found.url) && ! found.link.hasAttribute('data-drag-through')) {
                this.warmUp(found.url);
            }
        },

        onClick(event) {
            // A click that ends a drag (desk-grid.js swallows it) or that something else already handled is not an open.
            if (event.defaultPrevented || event.button !== 0 || event.shiftKey || event.altKey) {
                return;
            }

            const found = this.tabLink(event);

            if (! found) {
                return;
            }

            event.preventDefault();

            // A click a script made (the Ask bar's Enter) would also start Livewire's own navigation: this tab is the answer.
            if (! event.isTrusted) {
                event.stopPropagation();
            }

            window.dispatchEvent(new CustomEvent('dk-ask-close'));
            window.hcSideNavClose?.();

            if (this.isDesk(found.url)) {
                this.activate('desk');

                return;
            }

            this.open(found.url, { background: event.ctrlKey || event.metaKey, link: found.link });
        },

        onAuxClick(event) {
            if (event.button !== 1) {
                return;
            }

            const found = this.tabLink(event);

            if (found && ! this.isDesk(found.url)) {
                event.preventDefault();
                this.open(found.url, { background: true, link: found.link });
            }
        },

        /* Enter on a wire:navigate link, or a component that redirects with navigate: the same, as a tab. */
        onNavigate(event) {
            const url = event.detail?.url;

            if (! url || event.detail?.history || url.origin !== window.location.origin || NotForTabs.test(url.pathname)) {
                return;
            }

            event.preventDefault();

            if (this.isDesk(url)) {
                this.activate('desk');
            } else {
                this.open(url.toString());
            }
        },

        /**
         * Start loading a page in a hidden frame on the press, so the click
         * that follows finds it on its way. One at a time; a press that never
         * became a click (a drag, a release elsewhere) drops it after WarmTtl.
         */
        warmUp(href) {
            const key = this.keyOf(href);

            if (this.tabs.some((tab) => tab.key === key) || this.warm?.key === key) {
                return;
            }

            this.dropWarm();

            const frame = this.makeFrame(`warm-${++this.seq}`, href);

            frame.classList.add('dk-tabframe-warm');
            this.warm = { key, href, frame, loaded: false, timer: setTimeout(() => this.dropWarm(), WarmTtl) };
            frame.addEventListener('load', () => {
                if (this.warm?.frame === frame) {
                    this.warm.loaded = true;
                }
            }, { once: true });
        },

        dropWarm() {
            if (! this.warm) {
                return;
            }

            clearTimeout(this.warm.timer);
            this.warm.frame.remove();
            this.warm = null;
        },

        /**
         * Open a page as a tab, or show the tab already on it. A link that
         * asks for a create form (?quick=create) on a page already open asks
         * that tab to open its form.
         */
        open(href, { background = false, link = null, title = null } = {}) {
            const key = this.keyOf(href);
            const quick = new URL(href, window.location.href).searchParams.get('quick') === 'create';
            const existing = this.tabs.find((tab) => tab.key === key);

            if (existing) {
                if (quick) {
                    this.postTo(existing.id, { hcTab: 'quick-create' });
                }

                if (! background) {
                    this.activate(existing.id);
                }

                return existing.id;
            }

            if (this.tabs.length >= MaxTabs) {
                this.close(this.leastRecent()?.id);
            }

            const url = this.clean(href);
            const page = this.pageOf(url);
            const tab = {
                id: `t${Date.now().toString(36)}${(++this.seq).toString(36)}`,
                key,
                url: url.pathname + url.search,
                title: title ?? this.guessTitle(url, link),
                icon: page?.icon ?? 'file',
                loading: false,
                live: false,
            };

            const index = this.active === 'desk' ? this.tabs.length : this.tabs.findIndex((each) => each.id === this.active) + 1;

            this.tabs.splice(index, 0, tab);

            if (this.warm?.key === key) {
                this.adopt(tab, this.warm, href);
            }

            if (background) {
                this.ensureFrame(tab, href);
                this.say('opened_background', tab.title);
            } else {
                this.activate(tab.id, href);
            }

            this.save();

            return tab.id;
        },

        /** Take over the warmed frame as this tab's own. */
        adopt(tab, warm, href) {
            clearTimeout(warm.timer);
            this.warm = null;

            const { frame } = warm;

            frame.classList.remove('dk-tabframe-warm');
            frame.id = `dk-tabpanel-${tab.id}`;
            frame.setAttribute('aria-labelledby', `dk-tab-${tab.id}`);
            frame.dataset.tab = tab.id;
            this.frames.set(tab.id, frame);
            tab.live = true;
            tab.loading = ! warm.loaded;

            if (new URL(href, window.location.href).searchParams.get('quick') === 'create' && warm.loaded) {
                this.postTo(tab.id, { hcTab: 'quick-create' });
            }

            if (warm.loaded) {
                this.readFrame(tab, frame);
            } else {
                frame.addEventListener('load', () => this.loaded(tab.id), { once: true });
            }
        },

        /* ── Frames ───────────────────────────────────────────────── */

        makeFrame(id, href) {
            const frame = document.createElement('iframe');

            frame.className = 'dk-tabframe';
            frame.name = `dk-tab-${id}`;
            frame.setAttribute('role', 'tabpanel');
            frame.title = this.texts.loading;
            // Shown only once it has drawn, so a tab never flashes an empty white frame.
            frame.addEventListener('load', () => frame.classList.add('dk-ready'));
            frame.src = this.frameUrl(href);
            this.$refs.frames.append(frame);

            return frame;
        },

        ensureFrame(tab, href = null) {
            if (this.frames.has(tab.id)) {
                return this.frames.get(tab.id);
            }

            const frame = this.makeFrame(tab.id, href ?? tab.url);

            frame.id = `dk-tabpanel-${tab.id}`;
            frame.setAttribute('aria-labelledby', `dk-tab-${tab.id}`);
            frame.dataset.tab = tab.id;
            frame.dataset.watched = '1';
            frame.addEventListener('load', () => this.loaded(tab.id));
            this.frames.set(tab.id, frame);
            tab.live = true;
            tab.loading = true;
            this.sleepExtra(tab.id);

            return frame;
        },

        loaded(id) {
            const tab = this.tabs.find((each) => each.id === id);
            const frame = this.frames.get(id);

            if (! tab || ! frame) {
                return;
            }

            tab.loading = false;
            this.readFrame(tab, frame);

            // A frame that loads again later (a form posted inside it) reports itself too.
            if (! frame.dataset.watched) {
                frame.dataset.watched = '1';
                frame.addEventListener('load', () => this.loaded(id));
            }
        },

        /** Read a frame's own title and address (same origin), as a fallback to its messages. */
        readFrame(tab, frame) {
            try {
                const doc = frame.contentDocument;
                const where = frame.contentWindow.location;

                if (doc?.title) {
                    tab.title = this.shortTitle(doc.title);
                    frame.title = tab.title;
                }

                if (where?.href && where.origin === window.location.origin && ! this.isDesk(where.href)) {
                    this.moveTab(tab, where.href);
                }
            } catch {
                // A frame on another origin (it should never be): keep what the tab had.
            }

            this.save();
        },

        moveTab(tab, href) {
            const url = this.clean(href);

            tab.url = url.pathname + url.search;
            tab.key = tab.url;
            tab.icon = this.pageOf(url)?.icon ?? tab.icon;
        },

        shortTitle(title) {
            const cut = title.lastIndexOf(' - ');

            return (cut > 0 ? title.slice(0, cut) : title).trim() || this.texts.untitled;
        },

        /** Keep at most MaxLive frames: the least recently shown sleep until they are shown again (never the one being shown). */
        sleepExtra(keep) {
            const live = this.tabs.filter((tab) => tab.live && tab.id !== keep && tab.id !== this.active);

            const shown = 1 + (this.active !== 'desk' && this.active !== keep ? 1 : 0);

            while (live.length + shown > MaxLive && live.length > 0) {
                const oldest = live.sort((a, b) => (this.lastShown.get(a.id) ?? 0) - (this.lastShown.get(b.id) ?? 0)).shift();

                this.frames.get(oldest.id)?.remove();
                this.frames.delete(oldest.id);
                oldest.live = false;
                oldest.loading = false;
            }
        },

        postTo(id, message) {
            const frame = this.frames.get(id);

            if (! frame) {
                return;
            }

            const send = () => frame.contentWindow?.postMessage(message, window.location.origin);

            frame.contentDocument?.readyState === 'complete' ? send() : frame.addEventListener('load', send, { once: true });
        },

        /* ── Tabs ─────────────────────────────────────────────────── */

        activate(id, href = null) {
            if (id !== 'desk' && ! this.tabs.some((tab) => tab.id === id)) {
                return;
            }

            const was = this.active;

            if (id !== 'desk') {
                const tab = this.tabs.find((each) => each.id === id);

                this.ensureFrame(tab, href);
                this.lastShown.set(id, Date.now());
                this.sleepExtra(id);
            }

            this.active = id;
            this.menu = null;
            this.frames.forEach((frame, tabId) => frame.classList.toggle('dk-on', tabId === id));

            // Back on the Desk after something was done in a tab: its panels read again, in one round trip (IsDeskPanel).
            // A tab that was only read leaves the Desk as it is, so coming back costs nothing.
            if (id === 'desk' && was !== 'desk' && this.deskStale) {
                this.deskStale = false;
                window.Livewire?.dispatch('dk-desk-refresh');
            }

            this.$nextTick(() => {
                this.$refs.strip?.querySelector(`[data-tab="${id}"]`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });

                if (id !== 'desk') {
                    this.frames.get(id)?.focus({ preventScroll: true });
                }
            });

            this.save();
        },

        close(id) {
            const at = this.tabs.findIndex((tab) => tab.id === id);

            if (at < 0) {
                return;
            }

            const [tab] = this.tabs.splice(at, 1);

            this.frames.get(id)?.remove();
            this.frames.delete(id);
            this.lastShown.delete(id);
            this.say('closed', tab.title);

            if (this.active === id) {
                const next = this.tabs[at] ?? this.tabs[at - 1];

                this.activate(next ? next.id : 'desk');
            }

            this.save();
        },

        closeOthers(id) {
            this.tabs.filter((tab) => tab.id !== id).forEach((tab) => this.close(tab.id));
        },

        closeAll() {
            [...this.tabs].forEach((tab) => this.close(tab.id));
            this.activate('desk');
        },

        reload(id) {
            const tab = this.tabs.find((each) => each.id === id);
            const frame = this.frames.get(id);

            if (! tab) {
                return;
            }

            if (! frame) {
                this.activate(id);

                return;
            }

            tab.loading = true;

            try {
                frame.contentWindow.location.reload();
            } catch {
                frame.src = this.frameUrl(tab.url);
            }
        },

        duplicate(id) {
            const tab = this.tabs.find((each) => each.id === id);

            if (! tab) {
                return;
            }

            const copy = { ...tab, id: `t${Date.now().toString(36)}${(++this.seq).toString(36)}`, key: `${tab.key}#${this.seq}`, live: false, loading: false };

            this.tabs.splice(this.tabs.indexOf(tab) + 1, 0, copy);
            this.activate(copy.id);
        },

        /** The page moves out of the Desk into a browser tab of its own, as a full page; the Desk stays where it is. */
        popOut(id) {
            const tab = this.tabs.find((each) => each.id === id);

            if (! tab) {
                return;
            }

            window.open(tab.url, '_blank', 'noopener');
            this.close(id);
        },

        async addToDesk(id) {
            const tab = this.tabs.find((each) => each.id === id);

            if (tab) {
                await window.hcDeskItems?.toggle({ url: tab.url, placed: true });
            }
        },

        leastRecent() {
            return [...this.tabs].filter((tab) => tab.id !== this.active).sort((a, b) => (this.lastShown.get(a.id) ?? 0) - (this.lastShown.get(b.id) ?? 0))[0];
        },

        openMenu(id, event) {
            this.menu = id;
            this.menuStyle = `top:${Math.min(event.clientY, window.innerHeight - 320)}px;left:${Math.min(event.clientX, window.innerWidth - 260)}px`;
        },

        say(what, title) {
            this.announce = (this.texts[what] ?? '').replace('__T__', title);
        },

        /** Whether the tab in front is still drawing (the frames show a page's outline meanwhile). */
        activeLoading() {
            return this.tabs.find((tab) => tab.id === this.active)?.loading ?? false;
        },

        /** The page under the tabs: hidden while a tab is in front, so its scroll and panels stay as they were. */
        syncDocument() {
            document.documentElement.classList.toggle('dk-tab-active', this.active !== 'desk');
            this.syncChrome();
        },

        /**
         * The sidebar and the top bar's "where am I" follow the tab in front: its page lights up
         * (and its group in the icon rail), and the crumb reads "Your desk › page". Back on the
         * Desk, both return to what the server drew. Only classes and text move: nothing reloads.
         */
        syncChrome() {
            const tab = this.active === 'desk' ? null : this.tabs.find((each) => each.id === this.active);
            const side = document.querySelector('.dk-side');

            side?.querySelectorAll('[data-tab-lit]').forEach((el) => {
                el.classList.remove(el.dataset.tabLit);
                el.removeAttribute('data-tab-lit');
            });
            side?.querySelectorAll('[data-tab-dimmed]').forEach((el) => {
                el.classList.add(el.dataset.tabDimmed);
                el.removeAttribute('data-tab-dimmed');
            });

            const where = document.querySelector('[data-top-where]');
            const group = where?.querySelector('.dk-where-g span');
            const page = where?.querySelector('.dk-where-p a');

            if (group && page && where.dataset.deskGroup === undefined) {
                where.dataset.deskGroup = group.textContent;
                where.dataset.deskPage = page.textContent;
            }

            if (! tab) {
                if (group && page && where.dataset.deskGroup !== undefined) {
                    group.textContent = where.dataset.deskGroup;
                    page.textContent = where.dataset.deskPage;
                    page.setAttribute('aria-current', 'page');
                }

                return;
            }

            if (group && page) {
                group.textContent = where.dataset.deskPage;
                page.textContent = tab.title;
            }

            if (! side) {
                return;
            }

            const path = tab.url.split('?')[0].replace(/\/$/, '');
            let best = '';
            const links = [...side.querySelectorAll('a.dk-side-a[href]')].map((link) => {
                let linkPath = '';

                try {
                    linkPath = new URL(link.href).pathname.replace(/\/$/, '');
                } catch {
                    // Not a page link.
                }

                if (linkPath !== '' && ! this.isDesk(linkPath) && (path === linkPath || path.startsWith(linkPath + '/')) && linkPath.length > best.length) {
                    best = linkPath;
                }

                return [link, linkPath];
            });

            if (best === '') {
                return;
            }

            side.querySelectorAll('.dk-side-a.dk-on, .dk-side-gb.dk-has-on').forEach((el) => {
                const cls = el.classList.contains('dk-on') ? 'dk-on' : 'dk-has-on';

                el.classList.remove(cls);
                el.dataset.tabDimmed = cls;
            });

            links.filter(([, linkPath]) => linkPath === best).forEach(([link]) => {
                link.classList.add('dk-on');
                link.dataset.tabLit = 'dk-on';

                const groupButton = link.closest('.dk-side-g')?.querySelector('.dk-side-gb');

                if (groupButton && groupButton !== link && ! groupButton.classList.contains('dk-has-on')) {
                    groupButton.classList.add('dk-has-on');
                    groupButton.dataset.tabLit = 'dk-has-on';
                }
            });
        },

        /* ── Storage ──────────────────────────────────────────────── */

        save(now = false) {
            clearTimeout(this.saveTimer);
            // A tab that moved to another page or got its title: the sidebar and crumb follow it.
            this.syncChrome();

            const write = () => {
                try {
                    window.localStorage.setItem(this.storeKey, JSON.stringify({
                        tabs: this.tabs.map(({ id, url, title, icon }) => ({ id, url, title, icon })),
                        active: this.active,
                    }));
                } catch {
                    // Storage blocked or full: the tabs last for this page only.
                }
            };

            now ? write() : (this.saveTimer = setTimeout(write, 150));
        },

        restore() {
            let stored = null;

            try {
                stored = JSON.parse(window.localStorage.getItem(this.storeKey) ?? 'null');
            } catch {
                stored = null;
            }

            if (! Array.isArray(stored?.tabs)) {
                return;
            }

            this.tabs = stored.tabs
                .filter((tab) => typeof tab?.url === 'string' && typeof tab?.id === 'string' && tab.url.startsWith('/') && ! this.isDesk(tab.url))
                .slice(0, MaxTabs)
                .map((tab) => ({ id: tab.id, url: tab.url, key: this.keyOf(tab.url), title: String(tab.title ?? this.texts.untitled), icon: this.pageOf(tab.url)?.icon ?? 'file', loading: false, live: false }));

            // Only a reload of the Desk itself comes back on the tab that was in front; arriving at the Desk shows the Desk.
            const reloaded = firstLoad && performance.getEntriesByType?.('navigation')?.[0]?.type === 'reload';

            if (reloaded && this.tabs.some((tab) => tab.id === stored.active)) {
                this.$nextTick(() => this.activate(stored.active));
            }
        },

        /* ── Messages from the frames (desk-tab-child.js) ─────────── */

        onMessage(event) {
            if (event.origin !== window.location.origin || typeof event.data?.hcTab !== 'string') {
                return;
            }

            const entry = [...this.frames.entries()].find(([, frame]) => frame.contentWindow === event.source);
            const tab = entry ? this.tabs.find((each) => each.id === entry[0]) : null;
            const data = event.data;

            switch (data.hcTab) {
                case 'state':
                    if (tab) {
                        tab.loading = false;
                        tab.title = this.shortTitle(String(data.title ?? tab.title));
                        entry[1].title = tab.title;

                        if (typeof data.url === 'string') {
                            this.isDesk(data.url) ? this.close(tab.id) : this.moveTab(tab, data.url);
                        }

                        this.save();
                    }
                    break;
                case 'loading':
                    if (tab) {
                        tab.loading = true;
                    }
                    break;
                case 'changed':
                    this.deskStale = true;
                    break;
                case 'open':
                    if (typeof data.url === 'string' && new URL(data.url, window.location.href).origin === window.location.origin) {
                        this.isDesk(data.url) ? this.activate('desk') : this.open(data.url, { background: Boolean(data.background) });
                    }
                    break;
                case 'desk':
                    this.activate('desk');
                    break;
                case 'reveal':
                    if (tab && this.active !== tab.id) {
                        this.activate(tab.id);
                    }
                    break;
                case 'key':
                    this.shortcut(String(data.key ?? ''));
                    break;
                case 'ask':
                    window.dispatchEvent(new CustomEvent('dk-ask-open'));
                    break;
                case 'desk-changed':
                    window.dispatchEvent(new CustomEvent('dk-desk-changed'));
                    break;
                default:
                    break;
            }
        },

        /* ── Keyboard ─────────────────────────────────────────────── */

        onKey(event) {
            if (! event.altKey || event.ctrlKey || event.metaKey || isTyping(event.target)) {
                return;
            }

            const key = event.code === 'KeyW' ? 'w' : /^Digit[0-9]$/.test(event.code) ? event.code.slice(5) : null;

            if (key !== null && this.shortcut(key)) {
                event.preventDefault();
            }
        },

        /** Alt+0 the Desk, Alt+1…9 a tab, Alt+W closes the tab in front. True when it did something. */
        shortcut(key) {
            if (key === 'w') {
                if (this.active === 'desk') {
                    return false;
                }

                this.close(this.active);

                return true;
            }

            if (key === '0') {
                this.activate('desk');

                return true;
            }

            const tab = this.tabs[Number(key) - 1];

            if (tab) {
                this.activate(tab.id);

                return true;
            }

            return false;
        },

        onStripKey(event) {
            const ids = ['desk', ...this.tabs.map((tab) => tab.id)];
            const at = ids.indexOf(this.active);
            const rtl = getComputedStyle(this.$refs.strip).direction === 'rtl';
            const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[event.key];

            if (step) {
                event.preventDefault();
                this.activate(ids[(at + step + ids.length) % ids.length]);
                this.$nextTick(() => this.$refs.strip.querySelector(`[data-tab="${this.active}"] [role="tab"]`)?.focus());
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                this.activate(event.key === 'Home' ? 'desk' : ids.at(-1));
                this.$nextTick(() => this.$refs.strip.querySelector(`[data-tab="${this.active}"] [role="tab"]`)?.focus());
            } else if ((event.key === 'Delete' || event.key === 'Backspace') && this.active !== 'desk') {
                event.preventDefault();
                this.close(this.active);
                this.$nextTick(() => this.$refs.strip.querySelector(`[data-tab="${this.active}"] [role="tab"]`)?.focus());
            }
        },

        scrollStrip(event) {
            if (Math.abs(event.deltaY) > Math.abs(event.deltaX)) {
                this.$refs.strip.scrollLeft += event.deltaY;
            }
        },

        /* ── Reordering by drag ───────────────────────────────────── */

        startDrag(event, id) {
            if (event.button !== 0) {
                return;
            }

            const startX = event.clientX;
            let moved = false;

            const move = (each) => {
                if (! moved && Math.abs(each.clientX - startX) < 6) {
                    return;
                }

                moved = true;
                this.dragging = id;

                const over = document.elementFromPoint(each.clientX, each.clientY)?.closest('.dk-tab[data-tab]');
                const target = over?.dataset.tab;

                if (! target || target === id || target === 'desk') {
                    return;
                }

                const from = this.tabs.findIndex((tab) => tab.id === id);
                const to = this.tabs.findIndex((tab) => tab.id === target);

                if (from >= 0 && to >= 0) {
                    const [tab] = this.tabs.splice(from, 1);

                    this.tabs.splice(to, 0, tab);
                }
            };

            const end = () => {
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', end);
                document.removeEventListener('pointercancel', end);

                if (moved) {
                    this.dragging = null;
                    this.save();
                }
            };

            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', end);
            document.addEventListener('pointercancel', end);
        },
    }));
});
