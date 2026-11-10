/**
 * Pinned and folded panels on My desk (pages/desk/⚡index, components/desk/panel).
 *
 * The Desk's saved flags ({ pinned, folded }, App\Support\Desk\Panels::flagsFor)
 * arrive as JSON in [data-desk-flags] and live in the Alpine store `deskFlags`,
 * which the panels' menus read and change (desk-filters.js pin() / fold()).
 *
 * A panel folds into a small 2×2 tile in two ways:
 *  - "empty": it has nothing to show at all (its x-desk.panel sets data-empty
 *    only when no filter or search narrowed it), so it folds by itself;
 *  - "user": its owner chose Collapse in its menu (saved, so it stays folded).
 * A folded tile leaves the rows: it is drawn after every open card (CSS order),
 * and the open cards close up their rows (desk-rows.js flush) on screen only.
 * Their saved cells (data-cols / data-rows) are left alone, so a drag, a
 * resize or Done in Customize saves the arrangement the person made, and an
 * unfolded panel comes back exactly where it was.
 *
 * Only this file writes data-folded and data-pinned on the cards, and only
 * when they change, so watching those attributes cannot loop.
 */

import { flip, layoutOf, panelsIn } from './desk-grid';
import { flush } from './desk-rows';

function readFlags() {
    try {
        const flags = JSON.parse(document.querySelector('[data-desk-flags]')?.textContent ?? '{}');

        return { pinned: Array.isArray(flags.pinned) ? flags.pinned : [], folded: Array.isArray(flags.folded) ? flags.folded : [] };
    } catch {
        return { pinned: [], folded: [] };
    }
}

function setAttr(el, name, value) {
    if (value === null) {
        if (el.hasAttribute(name)) {
            el.removeAttribute(name);
        }
    } else if (el.getAttribute(name) !== value) {
        el.setAttribute(name, value);
    }
}

function setVar(el, name, value) {
    if (el.style.getPropertyValue(name) !== value) {
        el.style.setProperty(name, value);
    }
}

/** How a card is folded: 'user', 'empty' or null. Buttons and drop slots never fold. */
function foldOf(card, flags) {
    if (card.classList.contains('dk-tile') || card.classList.contains('dk-drop-slot')) {
        return null;
    }

    if (flags.folded.includes(card.dataset.panel)) {
        return 'user';
    }

    // An empty panel folds by itself, unless it was opened for this visit (an empty tile's click).
    return card.hasAttribute('data-empty') && ! flags.peeked.includes(card.dataset.panel) ? 'empty' : null;
}

export function applyFolds() {
    const grid = document.querySelector('[data-desk-grid]');
    const flags = window.Alpine?.store('deskFlags');

    // While a card is carried or pulled, desk-grid.js owns every cell; the folds are laid again once it lets go.
    if (! grid || ! flags || document.documentElement.matches('.dk-moving-doc, .dk-resizing-doc, .dk-dropping')) {
        return;
    }

    const cards = panelsIn(grid);
    const folds = cards.map((card) => foldOf(card, flags));
    const changed = cards.some((card, i) => (card.getAttribute('data-folded') ?? null) !== folds[i]);

    const lay = () => {
        cards.forEach((card, i) => {
            setAttr(card, 'data-folded', folds[i]);
            setAttr(card, 'data-pinned', flags.pinned.includes(card.dataset.panel) ? '' : null);
        });

        // The open cards close up their rows on screen; a folded one keeps its saved cells for when it opens again.
        const entries = layoutOf(grid);
        const open = entries.filter((entry, i) => folds[i] === null);
        const shown = new Map(flush(open).map((entry) => [entry.panel, entry]));

        cards.forEach((card) => {
            const cell = shown.get(card.dataset.panel);

            setVar(card, '--span', String(cell ? cell.cols : card.dataset.cols));
            setVar(card, '--rows', String(cell ? cell.rows : card.dataset.rows));
        });
    };

    // A panel folding or opening glides the cards to their new places (desk-grid.js flip); the first lay-out is instant.
    if (changed && grid.dataset.foldsLaid === '1') {
        flip(grid, lay, null);
    } else {
        lay();
    }

    grid.dataset.foldsLaid = '1';
}

let queued = false;

function queueApply() {
    if (queued) {
        return;
    }

    queued = true;
    requestAnimationFrame(() => {
        queued = false;
        applyFolds();
    });
}

let watchers = [];

function watch() {
    watchers.forEach((watcher) => watcher.disconnect());
    watchers = [];

    const grid = document.querySelector('[data-desk-grid]');
    const script = document.querySelector('[data-desk-flags]');

    if (! grid || ! window.Alpine) {
        return;
    }

    const store = window.Alpine.store('deskFlags');
    Object.assign(store, readFlags());

    // Cards added, moved or re-drawn, a panel that became empty or not, or a fold lost to a re-render.
    const cards = new MutationObserver(queueApply);
    cards.observe(grid, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-empty', 'data-folded', 'data-pinned', 'style'] });
    watchers.push(cards);

    // The Desk re-rendered its saved flags (a pin, a fold, a removal).
    if (script) {
        const flags = new MutationObserver(() => Object.assign(store, readFlags()));
        flags.observe(script, { childList: true, characterData: true, subtree: true });
        watchers.push(flags);
    }

    applyFolds();
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('deskFlags', { pinned: [], folded: [], peeked: [] });
    window.Alpine.effect(() => {
        const store = window.Alpine.store('deskFlags');

        // Read both lists so any change to either lays the Desk out again.
        void store.pinned.length;
        void store.folded.length;
        void store.peeked.length;
        queueApply();
    });
});

document.addEventListener('livewire:navigated', watch);

// A drag or a pull ended: once desk-grid.js has let go of the cells, lay the folds again.
['pointerup', 'dragend'].forEach((type) => document.addEventListener(type, () => setTimeout(queueApply, 400)));
