/**
 * The Desk's free cell grid (pages/desk/⚡index, components/desk/panel).
 *
 * Move: the whole panel header is the handle. A mouse picks the panel up
 * after a few pixels of travel; touch and pen pick it up after a 400ms press
 * and hold (so a swipe still scrolls). While it is carried the other panels
 * slide out of the way (FLIP), showing exactly where it will land; dropping
 * asks the host page to movePanel() to that place. Esc puts everything back.
 *
 * Resize: handles on the inline-end and inline-start edges (width), the top
 * and bottom edges (height) and the bottom inline-end corner (both). The card
 * itself snaps to whole cells as you pull, with a "6 × 4" badge, and the others
 * slide aside, so what you see is what is saved; releasing asks the host page
 * to resizePanelTo(panel, cols, rows). Esc cancels. RTL-aware throughout.
 * Pulling the top edge scrolls the page with the card, so its bottom stays put.
 * Pulling the inline-start edge pins the card's column (--col), so its far edge
 * stays put; a pin that no longer fits is dropped and the card flows in order.
 *
 * Delegated from document so panels Livewire re-renders keep working.
 */

const moveThreshold = 6;
const holdDelay = 400;
const minCols = 3; // Panels::MinCols
const interactive = 'a, button, input, select, textarea, label, [role="menu"], [contenteditable], [data-panel-search]';

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const isPhone = () => window.matchMedia('(max-width: 760px)').matches;

function panelOf(target) {
    return target instanceof Element ? target.closest('[data-desk-grid] > .dk-panel') : null;
}

function panelsIn(grid) {
    return [...grid.querySelectorAll(':scope > .dk-panel')];
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
        panel.style.transition = 'transform .32s cubic-bezier(.2, .9, .25, 1.12)';
        panel.style.transform = '';
    });
}

/** Keep the carried panel under the pointer wherever its slot moved to. */
function follow(x, y) {
    const { panel, grabX, grabY } = move;

    panel.style.transform = '';
    const slot = panel.getBoundingClientRect();

    panel.style.transform = `translate(${x - grabX - slot.left}px, ${y - grabY - slot.top}px) scale(1.02)`;
}

function pickUp() {
    const { panel, grid } = move;

    move.active = true;
    move.order = panelsIn(grid);
    panel.classList.add('dk-dragging');
    grid.classList.add('dk-moving');
    document.documentElement.classList.add('dk-moving-doc');
    follow(move.x, move.y);
    autoScroll();
}

function reflow(x, y) {
    const { panel, grid } = move;

    panel.style.pointerEvents = 'none';
    const target = panelOf(document.elementFromPoint(x, y));

    panel.style.pointerEvents = '';

    if (! target || target === panel || target.closest('[data-desk-grid]') !== grid) {
        return;
    }

    const box = target.getBoundingClientRect();
    const { rtl } = metrics(grid);
    const along = rtl ? box.right - x : x - box.left;
    const after = box.width > 0 && along > box.width / 2;
    const reference = after ? target.nextElementSibling : target;

    if (reference === panel || (after ? target.nextElementSibling === panel : target.previousElementSibling === panel && ! after)) {
        return;
    }

    flip(grid, () => grid.insertBefore(panel, reference));
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
        follow(move.x, move.y);
    }

    move.frame = requestAnimationFrame(autoScroll);
}

function settle(panel) {
    panel.style.transition = reducedMotion() ? '' : 'transform .26s cubic-bezier(.2, .9, .25, 1.12)';
    panel.style.transform = '';
    setTimeout(() => {
        panel.style.transition = '';
    }, 300);
}

function endMove(commit) {
    if (! move) {
        return;
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

    const { panel, grid, order } = current;
    const position = panelsIn(grid).indexOf(panel);

    panel.classList.remove('dk-dragging');
    grid.classList.remove('dk-moving');
    document.documentElement.classList.remove('dk-moving-doc');

    if (! commit) {
        flip(grid, () => order.forEach((item) => grid.append(item)));
        settle(panel);

        return;
    }

    settle(panel);

    if (position !== current.from) {
        seenHint();
        hostOf(panel)?.movePanel(panel.dataset.panel, position);
    }
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
        from: panelsIn(grid).indexOf(panel),
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
        reflow(event.clientX, event.clientY);
        follow(event.clientX, event.clientY);
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

function setCells(panel, cols, rows, col) {
    panel.style.setProperty('--col', col ?? 'auto');
    panel.style.setProperty('--span', cols);
    panel.style.setProperty('--rows', rows);
    panel.dataset.cols = cols;
    panel.dataset.rows = rows;
}

/**
 * While data-resizing is on, the panel does not fit its rows (desk-filters.js):
 * that round trip would re-render it at its saved size mid-pull. Lift it once
 * the new size is saved (or dropped) and let the panel fit again.
 */
function fitAgain(panel) {
    delete panel.dataset.resizing;
    panel.dispatchEvent(new CustomEvent('dk-panel-resized'));
}

function applyResize(cols, rows) {
    const { panel, grid, badge, edge, m, endLine, pinned } = resize;
    const col = edge === 'start' ? endLine - cols : (pinned !== null && pinned + cols <= 13 ? pinned : null);
    const grownRows = rows - resize.rows;

    badge.textContent = `${cols} × ${rows}`;

    if (cols === resize.cols && rows === resize.rows) {
        return;
    }

    resize.cols = cols;
    resize.rows = rows;
    resize.col = col;
    flip(grid, () => setCells(panel, cols, rows, col), panel);

    if (edge === 'top' && grownRows) {
        window.scrollBy(0, grownRows * (m.rowUnit + m.rowGap));
    }
}

document.addEventListener('pointerdown', (event) => {
    const handle = event.target instanceof Element ? event.target.closest('[data-resize]') : null;
    const panel = handle ? panelOf(handle) : null;

    if (! panel || event.button !== 0 || isPhone()) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const grid = panel.closest('[data-desk-grid]');
    const badge = document.createElement('b');
    const m = metrics(grid);
    const box = panel.getBoundingClientRect();
    const gridBox = grid.getBoundingClientRect();
    const fromStart = m.rtl ? gridBox.right - box.right : box.left - gridBox.left;
    const startCols = Number(panel.dataset.cols) || 4;
    const pinned = parseInt(getComputedStyle(panel).getPropertyValue('--col'), 10);

    badge.className = 'dk-resize-badge';
    panel.append(badge);

    resize = {
        panel,
        grid,
        badge,
        edge: handle.dataset.resize,
        m,
        startX: event.clientX,
        startY: event.clientY,
        startCols,
        endLine: Math.round(fromStart / (m.column + m.colGap)) + 1 + startCols,
        pinned: Number.isNaN(pinned) ? null : pinned,
        startRows: Number(panel.dataset.rows) || 4,
    };
    resize.cols = resize.startCols;
    resize.rows = resize.startRows;
    resize.col = resize.pinned;

    try {
        handle.setPointerCapture(event.pointerId);
    } catch {
        // Capture is a nicety; document listeners still see the drag.
    }

    panel.dataset.resizing = '';
    panel.classList.add('dk-resizing');
    document.documentElement.classList.add('dk-resizing-doc');
    document.documentElement.dataset.resizeEdge = resize.edge;
    applyResize(resize.cols, resize.rows);
}, true);

document.addEventListener('pointermove', (event) => {
    if (! resize) {
        return;
    }

    event.preventDefault();

    const { m, edge, startX, startY, startCols, startRows, endLine } = resize;
    const inline = (event.clientX - startX) * (m.rtl ? -1 : 1);
    const block = event.clientY - startY;
    const stepsX = Math.round(inline / (m.column + m.colGap));
    const stepsY = Math.round(block / (m.rowUnit + m.rowGap));

    let cols = startCols;
    let rows = startRows;

    if (edge === 'end' || edge === 'corner') {
        cols = startCols + stepsX;
    } else if (edge === 'start') {
        cols = startCols - stepsX;
    }

    if (edge === 'bottom' || edge === 'corner') {
        rows = startRows + stepsY;
    } else if (edge === 'top') {
        rows = startRows - stepsY;
    }

    applyResize(Math.max(minCols, Math.min(edge === 'start' ? endLine - 1 : 12, cols)), Math.max(2, Math.min(8, rows)));
});

function endResize(commit) {
    if (! resize) {
        return;
    }

    const { panel, grid, badge, cols, rows, col, startCols, startRows } = resize;

    resize = null;
    badge.remove();
    panel.classList.remove('dk-resizing');
    document.documentElement.classList.remove('dk-resizing-doc');
    delete document.documentElement.dataset.resizeEdge;

    if (cols === startCols && rows === startRows) {
        panel.style.removeProperty('--col');
        fitAgain(panel);

        return;
    }

    if (! commit) {
        flip(grid, () => {
            setCells(panel, startCols, startRows);
            panel.style.removeProperty('--col');
        }, panel);
        fitAgain(panel);

        return;
    }

    seenHint();

    const saving = hostOf(panel)?.resizePanelTo(panel.dataset.panel, cols, rows, col);

    saving?.then(() => panel.style.removeProperty('--col'));
    Promise.resolve(saving).finally(() => fitAgain(panel));
}

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
