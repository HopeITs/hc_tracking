/**
 * The horizon band: the whole plan squeezed into one strip above the Gantt,
 * like MS Project's Timeline view. Records are packed into a few lanes so
 * overlapping work stacks instead of hiding, and the band's own time scale is
 * picked so its labels never collide.
 */

/**
 * Pack spans into as few lanes as possible (at most maxLanes); once every
 * lane is taken, the span joins the lane that frees up first.
 *
 * @param {{start: number, end: number}[]} items
 * @param {number} maxLanes
 * @param {number} gap minimum free days between two spans in one lane
 */
export function packLanes(items, maxLanes, gap) {
    const laneEnds = [];
    const placed = [];

    for (const item of [...items].sort((a, b) => a.start - b.start || b.end - a.end)) {
        let lane = laneEnds.findIndex((end) => end + gap < item.start);

        if (lane === -1 && laneEnds.length < maxLanes) {
            lane = laneEnds.length;
            laneEnds.push(item.end);
        } else if (lane === -1) {
            lane = laneEnds.indexOf(Math.min(...laneEnds));
            laneEnds[lane] = Math.max(laneEnds[lane], item.end);
        } else {
            laneEnds[lane] = item.end;
        }

        placed.push({ ...item, lane });
    }

    return { lanes: Math.max(1, laneEnds.length), items: placed };
}

/** The coarsest calendar unit whose cells are still wide enough to label. */
export function horizonUnit(pixelsPerDay) {
    if (pixelsPerDay * 30 >= 34) {
        return 'month';
    }

    if (pixelsPerDay * 91 >= 34) {
        return 'quarter';
    }

    return 'year';
}
