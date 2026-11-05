/**
 * Desk rows: the rules that keep the Desk free of holes, shared by the Desk
 * grid (desk-grid.js) and the Customize sheet's map (desk-customize.js).
 * They mirror App\Support\Desk\Panels (rowsIn, fitRow, flush), which reads
 * every saved layout flush, so a preview here is exactly what gets saved.
 *
 * A layout is an ordered list of { panel, cols, rows }. Its cards, in order,
 * fill rows of exactly twelve columns, and every card in a row has the row's
 * height. Every card can be pulled from every edge:
 *  - the end edge with cards after it takes columns from them, the nearest
 *    first, and once they have no room left they wrap to the next row, the
 *    last first (growing a card pushes what follows it down);
 *  - the start edge between two neighbours trades columns between them;
 *  - an inline edge on the Desk's side frees columns when pulled inward, and
 *    the card next to it in order slides into them (the one after it for the
 *    end side, the one before it for the start side), its old row closing up;
 *  - the top edge trades height with the row above (the card's bottom stays
 *    put); on the first row it makes the row taller or shorter from the top;
 *  - the bottom edge makes the whole row taller or shorter.
 */

export const MaxCols = 12;
export const MinCols = 3;
export const MinRows = 2;
export const MaxRows = 8;

const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

/** The layout cut into rows of indices: a card that would pass column twelve starts the next row. */
export function rowsIn(entries) {
    const rows = [];
    let row = [];
    let used = 0;

    entries.forEach((entry, index) => {
        if (row.length && used + entry.cols > MaxCols) {
            rows.push(row);
            row = [];
            used = 0;
        }

        row.push(index);
        used += entry.cols;
    });

    if (row.length) {
        rows.push(row);
    }

    return rows;
}

/** One row's widths scaled to exactly twelve columns, in proportion and never under MinCols. */
export function fitRow(cols) {
    const total = cols.reduce((sum, width) => sum + width, 0);

    if (total === MaxCols || total === 0) {
        return [...cols];
    }

    const shares = cols.map((width) => (width * MaxCols) / total);
    const fitted = shares.map((share) => Math.max(MinCols, Math.floor(share)));
    const sum = () => fitted.reduce((all, width) => all + width, 0);
    const pick = (score) => score.indexOf(Math.max(...score));

    while (sum() < MaxCols) {
        fitted[pick(shares.map((share, i) => share - fitted[i]))]++;
    }

    while (sum() > MaxCols) {
        fitted[pick(shares.map((share, i) => (fitted[i] > MinCols ? fitted[i] - share : -Infinity)))]--;
    }

    return fitted;
}

/** The layout with no hole: rows filled to twelve columns, each at its tallest card's height. */
export function flush(entries) {
    const flushed = entries.map((entry) => ({ ...entry }));

    rowsIn(entries).forEach((row) => {
        const height = Math.max(...row.map((i) => entries[i].rows));
        const widths = fitRow(row.map((i) => entries[i].cols));

        row.forEach((i, k) => {
            flushed[i].cols = widths[k];
            flushed[i].rows = height;
        });
    });

    return flushed;
}

/**
 * The layout with one edge of a card pulled by whole cells (stepsX toward
 * inline-end, stepsY downward), every card in reach kept within the grid's
 * limits. A pull the Desk cannot follow (past its own side, or freeing fewer
 * than MinCols with nothing to slide in) leaves the layout as it was.
 */
export function resized(entries, index, edge, stepsX, stepsY) {
    const next = entries.map((entry) => ({ ...entry }));
    const rows = rowsIn(entries);
    const r = rows.findIndex((row) => row.includes(index));
    const row = rows[r] ?? [];
    const k = row.indexOf(index);

    if (k < 0) {
        return next;
    }

    /* The boundary between two neighbours: the one before it takes what the one after gives. */
    const split = (before, after) => {
        const both = entries[before].cols + entries[after].cols;
        const cols = clamp(entries[before].cols + stepsX, MinCols, both - MinCols);

        next[before].cols = cols;
        next[after].cols = both - cols;
    };

    /* An edge on the Desk's side pulled inward: the next card in order (after it, or before it) slides into the freed columns at the row's height. */
    const slideIn = (mover, moverRow, inward) => {
        const freed = Math.min(inward, entries[index].cols - MinCols);

        if (freed < MinCols || mover < 0 || mover >= entries.length || row.length >= MaxCols / MinCols) {
            return;
        }

        const left = moverRow.filter((i) => i !== mover);

        next[index].cols = entries[index].cols - freed;
        next[mover].cols = freed;
        next[mover].rows = next[index].rows;
        fitRow(left.map((i) => entries[i].cols)).forEach((cols, i) => (next[left[i]].cols = cols));
    };

    if (edge === 'bottom' || edge === 'corner') {
        const height = clamp(entries[index].rows + stepsY, MinRows, MaxRows);

        row.forEach((i) => (next[i].rows = height));
    } else if (edge === 'top' && r === 0) {
        const height = clamp(entries[index].rows - stepsY, MinRows, MaxRows);

        row.forEach((i) => (next[i].rows = height));
    } else if (edge === 'top') {
        const above = rows[r - 1];
        const own = entries[index].rows;
        const over = entries[above[0]].rows;
        const grown = clamp(-stepsY, Math.max(MinRows - own, over - MaxRows), Math.min(MaxRows - own, over - MinRows));

        row.forEach((i) => (next[i].rows = own + grown));
        above.forEach((i) => (next[i].rows = over - grown));
    }

    /*
     * The end edge with cards after it: the card takes the columns it is pulled
     * to and the cards after it give them, the nearest first, down to MinCols.
     * With no room left they wrap to the start of the next row like text, the
     * last first, each once it would be more than half squeezed out; the card
     * then takes their columns too.
     */
    const grow = () => {
        const after = row.slice(k + 1);
        const room = MaxCols - row.slice(0, k).reduce((sum, i) => sum + entries[i].cols, 0);
        let cols = clamp(entries[index].cols + stepsX, MinCols, room);
        let kept = after.length;

        while (kept && kept * MinCols - (room - cols) > MinCols / 2) {
            kept--;
        }

        cols = kept ? Math.min(cols, room - kept * MinCols) : room;

        const widths = after.slice(0, kept).map((i) => entries[i].cols);
        let spare = room - cols - widths.reduce((sum, width) => sum + width, 0);

        widths.forEach((width, j) => {
            const give = Math.min(width - MinCols, Math.max(0, -spare));

            widths[j] -= give;
            spare += give;
        });

        if (kept) {
            widths[0] += spare;
        }

        next[index].cols = cols;
        widths.forEach((width, j) => (next[after[j]].cols = width));

        const wrapped = after.slice(kept);

        if (wrapped.length) {
            const flowed = rows.map((each) => each.map((i) => next[i]));

            flowed[r] = flowed[r].slice(0, k + 1 + kept);
            wrapped.reverse().forEach((i) => pushDown(flowed, r, next[i]));
            wrap(flowed, r + 1);
        }
    };

    if (edge === 'end' || edge === 'corner') {
        if (k < row.length - 1) {
            grow();
        } else {
            slideIn(index + 1, rows[r + 1] ?? [], -stepsX);
        }
    } else if (edge === 'start') {
        if (k > 0) {
            split(row[k - 1], row[k]);
        } else {
            slideIn(index - 1, rows[r - 1] ?? [], stepsX);
        }
    }

    return next;
}

/**
 * The layout with one card dropped on another: the two trade places and each
 * keeps its own size. Every other card stays exactly where it was.
 */
export function swapped(entries, panel, target) {
    const next = entries.map((entry) => ({ ...entry }));
    const from = next.findIndex((entry) => entry.panel === panel);
    const to = next.findIndex((entry) => entry.panel === target);

    if (from < 0 || to < 0 || from === to) {
        return next;
    }

    [next[from], next[to]] = [next[to], next[from]];

    return next;
}

/**
 * The layout with one card dropped beside another ('before' or 'after' it):
 * the card joins that row keeping its own width, the row shares its twelve
 * columns again (fitRow) at its own height, and the row it left closes up.
 * A row that now holds a fifth card pushes its last one (never the dropped
 * card) to the start of the next row, at that row's height, and so on down;
 * past the last row it opens a new one.
 */
export function inserted(entries, panel, target, place) {
    const rows = rowsIn(entries).map((row) => row.map((i) => ({ ...entries[i] })));
    const from = rows.findIndex((row) => row.some((entry) => entry.panel === panel));
    const source = rows[from];
    const carried = source.find((entry) => entry.panel === panel);
    const sameRow = source.some((entry) => entry.panel === target);

    source.splice(source.indexOf(carried), 1);

    if (! sameRow) {
        if (source.length) {
            fitRow(source.map((entry) => entry.cols)).forEach((cols, i) => (source[i].cols = cols));
        } else {
            rows.splice(from, 1);
        }
    }

    const at = rows.findIndex((candidate) => candidate.some((entry) => entry.panel === target));
    const row = rows[at];

    row.splice(row.findIndex((entry) => entry.panel === target) + (place === 'after' ? 1 : 0), 0, { ...carried, rows: row[0]?.rows ?? carried.rows });
    wrap(rows, at, panel);

    return rows.flat();
}

/** Put a card first in the row after rows[r], at that row's height; past the last row it opens a new one. */
function pushDown(rows, r, entry) {
    if (r === rows.length - 1) {
        rows.push([]);
    }

    entry.rows = rows[r + 1][0]?.rows ?? entry.rows;
    rows[r + 1].unshift(entry);
}

/**
 * From rows[from] down, a row holding more than four cards pushes its last
 * one (never the card named keep) to the start of the next row, and so on
 * down. Every row then shares its twelve columns again (fitRow).
 */
function wrap(rows, from, keep = null) {
    for (let r = from; r < rows.length && rows[r].length > MaxCols / MinCols; r++) {
        const full = rows[r];

        while (full.length > MaxCols / MinCols) {
            pushDown(rows, r, full.splice(full.at(-1).panel === keep ? -2 : -1, 1)[0]);
        }
    }

    rows.forEach((each) => fitRow(each.map((entry) => entry.cols)).forEach((cols, i) => (each[i].cols = cols)));
}

/** Whether two layouts place the same cards, in the same order, at the same sizes. */
export function sameLayout(a, b) {
    return a.length === b.length && a.every((entry, i) => entry.panel === b[i].panel && entry.cols === b[i].cols && entry.rows === b[i].rows);
}
