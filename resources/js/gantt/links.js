/**
 * Orthogonal dependency arrows, drawn the way MS Project does: leave the
 * predecessor's edge horizontally, turn once (or double back through the gap
 * between the rows when there is no room), and enter the successor's edge
 * horizontally where the arrowhead sits.
 *
 * @param {number} x1 predecessor anchor x
 * @param {number} y1 predecessor row centre
 * @param {number} x2 successor anchor x
 * @param {number} y2 successor row centre
 * @param {'start'|'end'} fromSide which edge of the predecessor the link leaves
 * @param {'start'|'end'} toSide which edge of the successor the link enters
 * @param {number} rowHeight
 * @returns {string} SVG path data
 */
export function linkPath(x1, y1, x2, y2, fromSide, toSide, rowHeight) {
    const gap = 9;
    const out = fromSide === 'end' ? x1 + gap : x1 - gap;
    const into = toSide === 'start' ? x2 - gap : x2 + gap;
    const elbow = (turnX) => `M${x1},${y1} H${turnX} V${y2} H${x2}`;

    if (fromSide === 'start' && toSide === 'start') {
        return elbow(Math.min(out, into));
    }

    if (fromSide === 'end' && toSide === 'end') {
        return elbow(Math.max(out, into));
    }

    const roomToTurn = fromSide === 'end' ? into >= out : into <= out;

    if (roomToTurn) {
        return elbow(out);
    }

    // No room: double back through the gap between the two rows.
    const midY = y2 >= y1 ? y2 - rowHeight / 2 : y2 + rowHeight / 2;

    return `M${x1},${y1} H${out} V${midY} H${into} V${y2} H${x2}`;
}

/**
 * A funding route, drawn like a transit line: it drops straight down (or up)
 * from the proposal, turns through one rounded corner and runs level into the
 * start of the bar it funds.
 *
 * @param {number} x1 proposal anchor x
 * @param {number} y1 proposal anchor y
 * @param {number} x2 funded bar's start x
 * @param {number} y2 funded row centre
 * @param {number} radius corner radius
 * @returns {string} SVG path data
 */
export function routePath(x1, y1, x2, y2, radius = 10) {
    if (Math.abs(x2 - x1) < 1) {
        return `M${x1},${y1} V${y2}`;
    }

    if (Math.abs(y2 - y1) < 1) {
        return `M${x1},${y1} H${x2}`;
    }

    const down = y2 > y1 ? 1 : -1;
    const right = x2 > x1 ? 1 : -1;
    const corner = Math.min(radius, Math.abs(y2 - y1), Math.abs(x2 - x1));

    return `M${x1},${y1} V${y2 - down * corner} Q${x1},${y2} ${x1 + right * corner},${y2} H${x2}`;
}
