/**
 * The Desk's cell grid (pages/desk/⚡index, components/desk/panel). Its rows
 * are always flush (desk-rows.js): the cards fill every row's twelve columns
 * and share its height, so there is never a hole between them.
 *
 * Move: the whole panel header is the handle. A mouse picks the panel up
 * after a few pixels of travel; touch and pen pick it up after a 400ms press
 * and hold (so a swipe still scrolls). The card is carried as it is: over
 * another card the two trade places, each keeping its own size, and nothing
 * else moves. The swapped card slides (FLIP) to show it; dropping asks the host
 * page to arrangePanels(). Esc puts everything back.
 *
 * Resize: every card can be pulled from all four edges and the corner
 * (desk-rows.js resized). An end edge with cards after it takes their columns
 * and, once they have no room, wraps them to the next row; a start edge
 * between cards trades columns with the neighbour; one on the Desk's side,
 * pulled inward, lets the next card
 * slide into the freed columns. The top edge trades height with the row
 * above, and on the first row grows the row upward: the page scrolls by the
 * same amount, so the card's bottom stays where it was on screen. The bottom
 * edge makes the whole row taller. The cards snap to whole cells as you pull,
 * with a "6 × 4" badge; releasing asks the host page to arrangePanels(). Esc
 * cancels. RTL-aware throughout.
 *
 * Delegated from document so panels Livewire re-renders keep working.
 */

import { inserted, resized, rowsIn, sameLayout, swapped } from './desk-rows';

const moveThreshold = 6;
const holdDelay = 400;
const interactive = 'a, button, input, select, textarea, label, [role="menu"], [contenteditable], [data-panel-search]';

/* One calm glide for every card that makes room: no overshoot, so nothing wobbles while a card is pulled or carried. */
const glide = '.22s cubic-bezier(.25, .8, .25, 1)';
/* A pulled edge changes cell only once the pointer is well past the halfway line, so a hand resting near it does not make the cards flick back and forth. */
const stickiness = 0.65;

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const isPhone = () => window.matchMedia('(max-width: 760px)').matches;
/* Below 1280px widths collapse to fixed steps (desk-grid.css), so only heights are pulled there. */
const isNarrow = () => window.matchMedia('(max-width: 1279px)').matches;

function panelOf(target) {
    return target instanceof Element ? target.closest('[data-desk-grid] > .dk-panel') : null;
}

function panelsIn(grid) {
    return [...grid.querySelectorAll(':scope > .dk-panel')];
}

/** The grid's layout as desk-rows.js reads it: its cards in order, at their current cells. */
function layoutOf(grid) {
    return panelsIn(grid).map((panel) => ({ panel: panel.dataset.panel, cols: Number(panel.dataset.cols) || 4, rows: Number(panel.dataset.rows) || 4 }));
}

function setCells(panel, cols, rows) {
    panel.style.setProperty('--span', cols);
    panel.style.setProperty('--rows', rows);
    panel.dataset.cols = cols;
    panel.dataset.rows = rows;
}

/**
 * Put the grid's cards in the layout's order and at its cells. They are
 * re-inserted before whatever follows the last card, so Livewire's loop
 * markers around them stay where they were.
 */
function lay(grid, layout) {
    const cards = panelsIn(grid);
    const byId = new Map(cards.map((panel) => [panel.dataset.panel, panel]));
    const anchor = cards.at(-1)?.nextSibling ?? null;
    let slot = cards[0] ?? null;

    /* Only a card out of place is moved: re-inserting a node restarts its animations, so the rest are left alone. */
    layout.forEach(({ panel, cols, rows }) => {
        const card = byId.get(panel);

        if (! card) {
            return;
        }

        if (card === slot) {
            slot = nextCard(slot);
        } else {
            grid.insertBefore(card, slot ?? anchor);
        }

        if (Number(card.dataset.cols) !== cols || Number(card.dataset.rows) !== rows) {
            setCells(card, cols, rows);
        }
    });
}

function nextCard(card) {
    let next = card.nextElementSibling;

    while (next && ! next.classList.contains('dk-panel')) {
        next = next.nextElementSibling;
    }

    return next;
}

/**
 * The Desk page's Livewire component. The panel sits inside its own component
 * too, so start from the grid (owned by the page). Note the doubled backslash:
 * '[wire\:id]' would collapse to the invalid selector '[wire:id]' and closest()
 * would throw.
 */
function hostOf(panel) {
    const host = panel.closest('[data-desk-grid]')?.closest('[wire\\:id]');

    return host ? window.Livewire?.find(host.getAttribute('wire:id')) : null;
}

function metrics(grid) {
    const styles = getComputedStyle(grid);
    const columns = styles.gridTemplateColumns.split(' ').length || 12;
    const colGap = parseFloat(styles.columnGap) || 0;
    const rowGap = parseFloat(styles.rowGap) || colGap;
    const rowUnit = parseFloat(styles.gridAutoRows) || 104;
    const column = (grid.clientWidth - colGap * (columns - 1)) / columns;

    return { columns, colGap, rowGap, rowUnit, column, rtl: styles.direction === 'rtl' };
}

function seenHint() {
    try {
        window.localStorage.setItem('dk-hint-seen', '1');
    } catch {
        // Storage blocked: the hint simply shows again next visit.
    }
}

/* ── Move ─────────────────────────────────────────────────────────── */

let move = null;
let swallowClick = false;

/** Slide every panel but the carried one from where it was to where it now sits. */
function flip(grid, mutate, carried = move?.panel) {
    const others = panelsIn(grid).filter((panel) => panel !== carried);
    const before = new Map(others.map((panel) => [panel, panel.getBoundingClientRect()]));

    mutate();

    if (reducedMotion()) {
        return;
    }

    others.forEach((panel) => {
        panel.style.transition = 'none';
        panel.style.transform = '';
    });

    others.forEach((panel) => {
        const was = before.get(panel);
        const now = panel.getBoundingClientRect();
        const dx = was.left - now.left;
        const dy = was.top - now.top;

        if (dx || dy) {
            panel.style.transform = `translate(${dx}px, ${dy}px)`;
        }
    });

    grid.getBoundingClientRect();

    others.forEach((panel) => {
        panel.style.transition = `transform ${glide}`;
        panel.style.transform = '';
    });
}

/** Keep the carried panel under the pointer wherever its slot moved to, even when the slot made it smaller. */
function follow(x, y) {
    const { panel } = move;

    panel.style.transform = '';
    const slot = panel.getBoundingClientRect();
    const grabX = Math.min(move.grabX, slot.width - 24);
    const grabY = Math.min(move.grabY, slot.height - 24);

    panel.style.transform = `translate(${x - grabX - slot.left}px, ${y - grabY - slot.top}px) scale(1.02)`;
}

function pickUp() {
    const { panel, grid } = move;

    move.active = true;
    move.layout = layoutOf(grid);
    move.placed = move.layout;
    panel.classList.add('dk-dragging');
    grid.classList.add('dk-moving');
    document.documentElement.classList.add('dk-moving-doc');
    follow(move.x, move.y);
    autoScroll();
}

/**
 * Which card a drop at (x, y) would trade places with. The other cards stay
 * still while the card is carried (moving them under the pointer made them
 * flick); the one under it is only ringed, and the swap happens on drop.
 */
function reflow(x, y) {
    const { panel, grid } = move;

    panel.style.pointerEvents = 'none';
    let target = panelOf(document.elementFromPoint(x, y));

    panel.style.pointerEvents = '';

    if (target === panel || target?.closest('[data-desk-grid]') !== grid) {
        target = null;
    }

    /* The outer quarter of a card's inline edges drops beside it; its middle swaps with it. */
    let place = 'swap';

    if (target) {
        const box = target.getBoundingClientRect();
        const along = metrics(grid).rtl ? box.right - x : x - box.left;
        const edge = Math.min(box.width / 4, 120);

        place = along < edge ? 'before' : along > box.width - edge ? 'after' : 'swap';
    }

    if (target === move.target && place === move.place) {
        return;
    }

    move.target?.classList.remove('dk-drop', 'dk-drop-before', 'dk-drop-after');
    target?.classList.add(place === 'swap' ? 'dk-drop' : `dk-drop-${place}`);
    move.target = target;
    move.place = place;

    if (! target) {
        move.placed = move.layout;
    } else if (place === 'swap') {
        move.placed = swapped(move.layout, panel.dataset.panel, target.dataset.panel);
    } else {
        move.placed = inserted(move.layout, panel.dataset.panel, target.dataset.panel, place);
    }
}

function autoScroll() {
    if (! move?.active) {
        return;
    }

    const edge = 72;
    const { y } = move;
    const speed = y < edge ? -(edge - y) / 3 : y > window.innerHeight - edge ? (y - (window.innerHeight - edge)) / 3 : 0;

    if (speed) {
        window.scrollBy(0, speed);
    }

    /* The pointer is read on every event but the Desk is touched once per frame at most. */
    if (move.dirty) {
        move.dirty = false;
        reflow(move.x, move.y);
    }

    if (speed || move.moved !== `${move.x},${move.y}`) {
        move.moved = `${move.x},${move.y}`;
        follow(move.x, move.y);
    }

    move.frame = requestAnimationFrame(autoScroll);
}

function settle(panel) {
    panel.style.transition = reducedMotion() ? '' : `transform ${glide}`;
    panel.style.transform = '';
    setTimeout(() => {
        panel.style.transition = '';
    }, 300);
}

function endMove(commit) {
    if (! move) {
        return;
    }

    if (commit && move.active && move.dirty) {
        move.dirty = false;
        reflow(move.x, move.y);
    }

    const current = move;

    move = null;
    clearTimeout(current.hold);

    if (! current.active) {
        return;
    }

    cancelAnimationFrame(current.frame);
    swallowClick = true;
    setTimeout(() => {
        swallowClick = false;
    }, 0);

    const { panel, grid, layout, placed } = current;

    current.target?.classList.remove('dk-drop', 'dk-drop-before', 'dk-drop-after');
    panel.classList.remove('dk-dragging');
    grid.classList.remove('dk-moving');
    document.documentElement.classList.remove('dk-moving-doc');

    if (! commit || sameLayout(layout, placed)) {
        settle(panel);

        return;
    }

    /* The carried card glides from under the pointer into its new slot, the swapped one slides into the old slot. */
    const from = panel.getBoundingClientRect();

    flip(grid, () => lay(grid, placed), panel);
    panel.style.transition = 'none';
    panel.style.transform = '';
    const to = panel.getBoundingClientRect();

    panel.style.transform = `translate(${from.left - to.left}px, ${from.top - to.top}px)`;
    panel.getBoundingClientRect();
    settle(panel);
    seenHint();
    save(grid, layout, placed);
}

/**
 * Ask the host page to keep the new arrangement, then let every card whose
 * cells changed fit its rows again (desk-filters.js).
 */
function save(grid, before, after) {
    const changed = panelsIn(grid).filter((panel) => {
        const was = before.find((entry) => entry.panel === panel.dataset.panel);
        const now = after.find((entry) => entry.panel === panel.dataset.panel);

        return ! was || ! now || was.cols !== now.cols || was.rows !== now.rows;
    });
    const saving = hostOf(grid.querySelector(':scope > .dk-panel'))?.arrangePanels(after.map(({ panel, cols, rows }) => ({ panel, cols, rows })));

    Promise.resolve(saving).finally(() => {
        panelsIn(grid).forEach((card) => delete card.dataset.resizing);
        changed.forEach(fitAgain);
    });
}

document.addEventListener('pointerdown', (event) => {
    const handle = event.target instanceof Element ? event.target.closest('[data-drag]') : null;
    const panel = handle ? panelOf(handle) : null;

    if (! panel || event.button !== 0 || move || resize || event.target.closest(interactive)) {
        return;
    }

    const grid = panel.closest('[data-desk-grid]');
    const box = panel.getBoundingClientRect();

    move = {
        panel,
        grid,
        pointer: event.pointerType,
        startX: event.clientX,
        startY: event.clientY,
        x: event.clientX,
        y: event.clientY,
        grabX: event.clientX - box.left,
        grabY: event.clientY - box.top,
        active: false,
        hold: 0,
        frame: 0,
    };

    if (event.pointerType !== 'mouse') {
        move.hold = setTimeout(() => move && ! move.active && pickUp(), holdDelay);
    }
});

document.addEventListener('pointermove', (event) => {
    if (move) {
        move.x = event.clientX;
        move.y = event.clientY;
        const travelled = Math.hypot(event.clientX - move.startX, event.clientY - move.startY);

        if (! move.active) {
            if (move.pointer === 'mouse' && travelled > moveThreshold) {
                pickUp();
            } else if (move.pointer !== 'mouse' && travelled > 10) {
                endMove(false);
            }

            return;
        }

        event.preventDefault();
        move.dirty = true;
    }
});

// Once a panel is held on touch, the finger moves it instead of the page.
document.addEventListener('touchmove', (event) => {
    if (move?.active || resize) {
        event.preventDefault();
    }
}, { passive: false });

document.addEventListener('click', (event) => {
    if (swallowClick) {
        event.preventDefault();
        event.stopPropagation();
        swallowClick = false;
    }
}, true);

document.addEventListener('contextmenu', (event) => {
    if (move) {
        event.preventDefault();
    }
});

/* ── Resize ───────────────────────────────────────────────────────── */

let resize = null;

/**
 * While data-resizing is on, the panel does not fit its rows (desk-filters.js):
 * that round trip would re-render it at its saved size mid-pull. Lift it once
 * the new size is saved (or dropped) and let the panel fit again.
 */
function fitAgain(panel) {
    delete panel.dataset.resizing;
    panel.dispatchEvent(new CustomEvent('dk-panel-resized'));
}

/** A card's cell box in grid pixels, its transform left out. */
function boxOf(panel) {
    return { left: panel.offsetLeft, top: panel.offsetTop, right: panel.offsetLeft + panel.offsetWidth, bottom: panel.offsetTop + panel.offsetHeight };
}

const pixelsOf = (cells, unit, gap) => cells * unit + (cells - 1) * gap;
const cellsOf = (pixels, unit, gap) => (pixels + gap) / (unit + gap);

/** Past what the card can reach, the outline gives a little, like a rubber band, instead of stopping dead. */
function giving(size, min, max) {
    if (size > max) {
        return max + Math.min(28, (size - max) * 0.2);
    }

    if (size < min) {
        return min - Math.min(28, (min - size) * 0.2);
    }

    return size;
}

/** Whole cells reached, kept until the outline is clearly in the next cell. */
function settled(cells, current, min, max) {
    const reached = Math.abs(cells - current) > stickiness ? Math.round(cells) : current;

    return Math.max(min, Math.min(max, reached));
}

/** The fewest and most cells a card can reach along one axis (desk-rows.js decides). */
function reach(layout, index, edge, axis, start) {
    const far = [99, -99].map((steps) => resized(layout, index, edge, axis === 'x' ? steps : 0, axis === 'y' ? steps : 0)[index][axis === 'x' ? 'cols' : 'rows']);

    return [Math.min(start, ...far), Math.max(start, ...far)];
}

document.addEventListener('pointerdown', (event) => {
    const handle = event.target instanceof Element ? event.target.closest('[data-resize]') : null;
    const panel = handle ? panelOf(handle) : null;

    if (! panel || event.button !== 0 || isPhone()) {
        return;
    }

    const grid = panel.closest('[data-desk-grid]');
    const edge = handle.dataset.resize;

    event.preventDefault();
    event.stopPropagation();

    if (isNarrow() && ! ['top', 'bottom'].includes(edge)) {
        return;
    }

    const layout = layoutOf(grid);
    const index = panelsIn(grid).indexOf(panel);
    const m = metrics(grid);
    const box = boxOf(panel);
    const gridBox = grid.getBoundingClientRect();
    const inline = ['end', 'corner'].includes(edge) ? 'end' : edge === 'start' ? 'start' : null;
    const sideX = inline === null ? null : (inline === 'end') !== m.rtl ? 'right' : 'left';
    const sideY = ['bottom', 'corner'].includes(edge) ? 'bottom' : edge === 'top' ? 'top' : null;
    const ghost = document.createElement('div');
    const badge = document.createElement('b');

    badge.className = 'dk-resize-badge';
    badge.textContent = `${layout[index].cols} × ${layout[index].rows}`;
    ghost.className = 'dk-resize-ghost';
    ghost.append(badge);
    grid.append(ghost);

    resize = {
        panel,
        grid,
        ghost,
        badge,
        edge,
        index,
        layout,
        placed: layout,
        wanted: { cols: layout[index].cols, rows: layout[index].rows },
        firstRow: rowsIn(layout)[0].includes(index),
        scrolled: 0,
        m,
        inline,
        sideX,
        sideY,
        /* Where the pointer sits from the edge it took, so the outline does not jump to the pointer. */
        offsetX: sideX ? box[sideX] - (event.clientX - gridBox.left) : 0,
        offsetY: sideY ? box[sideY] - (event.clientY - gridBox.top) : 0,
        cols: reach(layout, index, edge, 'x', layout[index].cols),
        rows: reach(layout, index, edge, 'y', layout[index].rows),
        clientX: event.clientX,
        clientY: event.clientY,
        frame: 0,
    };

    drawOutline(box);

    try {
        handle.setPointerCapture(event.pointerId);
    } catch {
        // Capture is a nicety; document listeners still see the drag.
    }

    panelsIn(grid).forEach((card) => (card.dataset.resizing = ''));
    panel.classList.add('dk-resizing');
    document.documentElement.classList.add('dk-resizing-doc');
    document.documentElement.dataset.resizeEdge = edge;
}, true);

// The pointer is read on every event but the Desk is touched once per frame at most.
document.addEventListener('pointermove', (event) => {
    if (! resize) {
        return;
    }

    event.preventDefault();
    resize.clientX = event.clientX;
    resize.clientY = event.clientY;
    resize.frame ||= requestAnimationFrame(pull);
});

/**
 * Where the pulled edges are now: the fixed edges stay on the card's cell
 * box and the pulled ones follow the pointer (giving a little past what the
 * card can reach), with the whole cells that outline covers. Stickiness is
 * kept against the cells last asked for, not the cells the card got: a card
 * that wraps its neighbours down jumps wider than asked, and measuring from
 * there would let a resting hand wrap and unwrap them.
 */
function outline() {
    const { panel, grid, m, sideX, sideY, offsetX, offsetY } = resize;
    const gridBox = grid.getBoundingClientRect();
    const box = boxOf(panel);
    const rect = { ...box };
    let { cols, rows } = resize.wanted;

    if (sideX) {
        const fixed = sideX === 'right' ? box.left : box.right;
        const pointer = resize.clientX - gridBox.left + offsetX;
        const size = giving(sideX === 'right' ? pointer - fixed : fixed - pointer, pixelsOf(resize.cols[0], m.column, m.colGap), pixelsOf(resize.cols[1], m.column, m.colGap));

        rect.left = sideX === 'right' ? fixed : fixed - size;
        rect.right = sideX === 'right' ? fixed + size : fixed;
        cols = settled(cellsOf(size, m.column, m.colGap), cols, ...resize.cols);
    }

    if (sideY) {
        const fixed = sideY === 'bottom' ? box.top : box.bottom;
        const pointer = resize.clientY - gridBox.top + offsetY;
        const size = giving(sideY === 'bottom' ? pointer - fixed : fixed - pointer, pixelsOf(resize.rows[0], m.rowUnit, m.rowGap), pixelsOf(resize.rows[1], m.rowUnit, m.rowGap));

        rect.top = sideY === 'bottom' ? fixed : fixed - size;
        rect.bottom = sideY === 'bottom' ? fixed + size : fixed;
        rows = settled(cellsOf(size, m.rowUnit, m.rowGap), rows, ...resize.rows);
    }

    return { rect, cols, rows };
}

function drawOutline(rect) {
    const { ghost } = resize;

    ghost.style.transform = `translate3d(${rect.left}px, ${rect.top}px, 0)`;
    ghost.style.width = `${rect.right - rect.left}px`;
    ghost.style.height = `${rect.bottom - rect.top}px`;
}

/**
 * One frame of a pull: the outline follows the pointer, and when it covers
 * other whole cells the cards glide to the new arrangement under it.
 */
function pull() {
    if (! resize) {
        return;
    }

    resize.frame = 0;

    const { panel, grid, badge, edge, index, layout, m, inline, sideY } = resize;
    const { cols, rows } = outline();
    const start = layout[index];

    resize.wanted = { cols, rows };
    const stepsX = inline === 'start' ? start.cols - cols : cols - start.cols;
    const stepsY = sideY === 'top' ? start.rows - rows : rows - start.rows;
    const placed = resized(layout, index, edge, inline ? stepsX : 0, sideY ? stepsY : 0);

    if (! sameLayout(placed, resize.placed)) {
        const grown = placed[index].rows - resize.placed[index].rows;

        resize.placed = placed;
        badge.textContent = `${placed[index].cols} × ${placed[index].rows}`;
        flip(grid, () => lay(grid, placed), panel);

        if (edge === 'top' && resize.firstRow && grown) {
            scrollWith(grown * (m.rowUnit + m.rowGap));
        }
    }

    drawOutline(outline().rect);
}

/** The first row grown from its top: scroll by what it grew, so its bottom stays where it was on screen and its top follows the pointer. */
function scrollWith(by) {
    const before = window.scrollY;

    window.scrollTo({ top: before + by, behavior: 'instant' });
    resize.scrolled += window.scrollY - before;
}

function endResize(commit) {
    if (! resize) {
        return;
    }

    /* A frame still waiting holds the last pointer position: apply it first, so what is saved is what was under the pointer. */
    if (commit && resize.frame) {
        cancelAnimationFrame(resize.frame);
        pull();
    }

    const current = resize;
    const { panel, grid, ghost, layout, placed } = current;

    cancelAnimationFrame(current.frame);
    panel.classList.remove('dk-resizing');
    document.documentElement.classList.remove('dk-resizing-doc');
    delete document.documentElement.dataset.resizeEdge;

    if (! commit || sameLayout(layout, placed)) {
        flip(grid, () => lay(grid, layout), panel);
        window.scrollTo({ top: window.scrollY - current.scrolled, behavior: 'instant' });
        panelsIn(grid).forEach(fitAgain);
    } else {
        seenHint();
        save(grid, layout, placed);
    }

    /* The outline lands on the card's final cells and fades, so the release reads as one motion. */
    ghost.classList.add('dk-landing');
    drawOutline(boxOf(panel));
    resize = null;
    setTimeout(() => ghost.remove(), reducedMotion() ? 0 : 240);
}

/* ── Entrance ─────────────────────────────────────────────────────── */

/*
 * The cards rise in once, when the Desk opens (desk.css dk-rise). After that
 * html.dk-desk-settled turns the entrance off, so a card Livewire or a drag
 * moves in the page never fades out and in again.
 */
function settleEntrance() {
    clearTimeout(settleEntrance.timer);
    settleEntrance.timer = setTimeout(() => document.documentElement.classList.add('dk-desk-settled'), 1200);
}

settleEntrance();
document.addEventListener('livewire:navigating', () => document.documentElement.classList.remove('dk-desk-settled'));
document.addEventListener('livewire:navigated', settleEntrance);

/* ── Shared release / cancel ──────────────────────────────────────── */

document.addEventListener('pointerup', () => {
    endResize(true);
    endMove(true);
});

document.addEventListener('pointercancel', () => {
    endResize(false);
    endMove(false);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && (resize || move?.active)) {
        event.stopPropagation();
        endResize(false);
        endMove(false);
    }
}, true);
