/**
 * A page inside a Desk tab (resources/js/desk-tabs.js is the Desk's side).
 * The page is the real one, drawn without its own frame; this keeps it that
 * way and tells the Desk what it needs:
 *  - every navigation inside the tab keeps ?_tab=1, so the next page is drawn
 *    without a frame too (App\Support\Desk\DeskTab); a link to the Desk shows
 *    the Desk's own tab instead of a Desk inside a tab;
 *  - the tab's title and address follow the page (state), its spinner the
 *    navigation (loading), and Back inside a hidden tab brings it to the front;
 *  - Ctrl/⌘ or middle click on a link opens it as another Desk tab, in the
 *    background, as a browser would;
 *  - the Desk's keys (Alt+0…9, Alt+W, Ctrl/⌘ K and "/" for the Ask bar)
 *    work while the tab has focus;
 *  - the Desk can ask the page to open its create form (quick-create).
 *
 * Nothing here runs on a page that is not in a Desk tab.
 */

const param = '_tab';
/* Livewire calls that only change what the page shows (paging, sorting, a re-render), never a record. */
const ReadOnlyCalls = new Set(['$refresh', '$commit', '$set', '$sync', 'gotoPage', 'nextPage', 'previousPage', 'setPage', 'resetPage', 'sortBy', 'sort', 'applyFilters', 'clearFilters', 'setFilter', 'fitRows']);

function deskParent() {
    try {
        return window.top !== window.self && window.parent.location.origin === window.location.origin && window.parent.document.querySelector('[data-desk-tabs]')
            ? window.parent
            : null;
    } catch {
        return null;
    }
}

function tell(message) {
    deskParent()?.postMessage({ ...message }, window.location.origin);
}

function isTyping(target) {
    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

function withoutMarker(href) {
    const url = new URL(href, window.location.href);

    url.searchParams.delete(param);

    return url;
}

function isDesk(url) {
    return url.pathname === (document.querySelector('meta[name="desk-home"]')?.content ?? '/home') && ! withoutMarker(url).search;
}

function report() {
    // After desk-ask.js has taken ?quick=create off the address, so the tab never remembers it.
    requestAnimationFrame(() => tell({ hcTab: 'state', title: document.title, url: withoutMarker(window.location.href).toString() }));
}

if (deskParent()) {
    document.documentElement.classList.add('dk-in-tab');

    // The address keeps the marker after an in-tab replaceState too (filters written to the URL by #[Url]).
    if (new URL(window.location.href).searchParams.get(param) !== '1') {
        const url = new URL(window.location.href);

        url.searchParams.set(param, '1');
        window.history.replaceState(window.history.state, '', url);
    }

    document.addEventListener('livewire:navigate', (event) => {
        const url = event.detail?.url;

        if (! url || url.origin !== window.location.origin) {
            return;
        }

        if (isDesk(url)) {
            event.preventDefault();
            tell({ hcTab: 'desk' });

            return;
        }

        if (url.searchParams.get(param) !== '1' && ! event.detail?.history) {
            event.preventDefault();

            const marked = new URL(url);

            marked.searchParams.set(param, '1');
            window.Livewire?.navigate(marked.toString());
        }
    });

    // Something was done on this page (a Livewire action, not a mere read of it): the Desk reads itself again when it is next shown.
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('commit', ({ commit, succeed }) => {
            succeed(() => {
                if ((commit?.calls ?? []).some((call) => ! ReadOnlyCalls.has(call.method))) {
                    tell({ hcTab: 'changed' });
                }
            });
        });
    });

    document.addEventListener('livewire:navigating', () => tell({ hcTab: 'loading' }));
    document.addEventListener('livewire:navigated', report);
    window.addEventListener('load', report);
    window.addEventListener('popstate', () => tell({ hcTab: 'reveal' }));

    // A plain link (not wire:navigate) to another page keeps the marker as well.
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (! link || link.hasAttribute('download') || (link.target && link.target !== '_self')) {
            return;
        }

        const url = new URL(link.href, window.location.href);

        if (url.origin !== window.location.origin) {
            return;
        }

        if (event.ctrlKey || event.metaKey) {
            event.preventDefault();
            event.stopPropagation();
            tell({ hcTab: 'open', url: withoutMarker(url).toString(), background: true });

            return;
        }

        if (event.button === 0 && ! event.shiftKey && ! event.altKey && isDesk(url)) {
            event.preventDefault();
            event.stopPropagation();
            tell({ hcTab: 'desk' });

            return;
        }

        if (! link.hasAttribute('wire:navigate') && url.searchParams.get(param) !== '1' && ! url.hash) {
            url.searchParams.set(param, '1');
            link.href = url.toString();
        }
    }, true);

    document.addEventListener('auxclick', (event) => {
        const link = event.button === 1 && event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (link && new URL(link.href, window.location.href).origin === window.location.origin && ! link.hasAttribute('download')) {
            event.preventDefault();
            tell({ hcTab: 'open', url: withoutMarker(link.href).toString(), background: true });
        }
    }, true);

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && ! event.altKey && ! event.shiftKey && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            tell({ hcTab: 'ask' });

            return;
        }

        if (isTyping(event.target)) {
            return;
        }

        if (event.key === '/' && ! event.ctrlKey && ! event.metaKey && ! event.altKey) {
            event.preventDefault();
            tell({ hcTab: 'ask' });

            return;
        }

        if (event.altKey && ! event.ctrlKey && ! event.metaKey && (event.code === 'KeyW' || /^Digit[0-9]$/.test(event.code))) {
            event.preventDefault();
            tell({ hcTab: 'key', key: event.code === 'KeyW' ? 'w' : event.code.slice(5) });
        }
    });

    // Light or dark chosen on the Desk reaches every tab at once (Flux keeps the choice in localStorage).
    window.addEventListener('storage', (event) => {
        if (event.key !== 'flux.appearance' || ! event.newValue) {
            return;
        }

        const appearance = event.newValue.replace(/"/g, '');
        const dark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

        if (window.Flux) {
            window.Flux.appearance = appearance;
        }

        document.documentElement.classList.toggle('dark', dark);
    });

    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin || event.source !== window.parent) {
            return;
        }

        if (event.data?.hcTab === 'quick-create') {
            document.querySelector('[data-quick-create]')?.click();
        }
    });
}
