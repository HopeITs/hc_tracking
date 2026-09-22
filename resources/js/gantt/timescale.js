/**
 * Calendar maths for the Gantt engine.
 *
 * Every date is an integer "day number" (days since 1970-01-01 UTC) so bars
 * never drift across daylight-saving changes and width is plain arithmetic.
 */

export const DAY_MS = 86_400_000;

export function toDay(iso) {
    if (!iso) {
        return null;
    }

    const [year, month, day] = iso.split('-').map(Number);

    return Math.round(Date.UTC(year, month - 1, day) / DAY_MS);
}

export function toDate(dayNumber) {
    return new Date(dayNumber * DAY_MS);
}

export function toIso(dayNumber) {
    return toDate(dayNumber).toISOString().slice(0, 10);
}

/** 0 = Sunday … 6 = Saturday, like Date#getUTCDay. */
export function weekday(dayNumber) {
    return (dayNumber + 4) % 7;
}

/** Selectable working-week presets; the value lists the non-working weekdays. */
export const WEEKEND_PRESETS = {
    'sat-sun': [6, 0],
    'fri-sat': [5, 6],
    fri: [5],
    sun: [0],
    none: [],
};

/** Pixels per day, and which calendar units the two header tiers show. */
export const ZOOM_LEVELS = {
    day: { ppd: 36, top: 'month', bottom: 'day' },
    week: { ppd: 16, top: 'month', bottom: 'week' },
    month: { ppd: 5, top: 'year', bottom: 'month' },
    quarter: { ppd: 2, top: 'year', bottom: 'quarter' },
    year: { ppd: 0.7, top: 'none', bottom: 'year' },
};

function startOfUnit(dayNumber, unit, weekStart) {
    const date = toDate(dayNumber);
    const year = date.getUTCFullYear();
    const month = date.getUTCMonth();

    switch (unit) {
        case 'day':
            return dayNumber;
        case 'week':
            return dayNumber - ((weekday(dayNumber) - weekStart + 7) % 7);
        case 'month':
            return Math.round(Date.UTC(year, month, 1) / DAY_MS);
        case 'quarter':
            return Math.round(Date.UTC(year, month - (month % 3), 1) / DAY_MS);
        default:
            return Math.round(Date.UTC(year, 0, 1) / DAY_MS);
    }
}

function nextUnit(dayNumber, unit) {
    const date = toDate(dayNumber);
    const year = date.getUTCFullYear();
    const month = date.getUTCMonth();

    switch (unit) {
        case 'day':
            return dayNumber + 1;
        case 'week':
            return dayNumber + 7;
        case 'month':
            return Math.round(Date.UTC(year, month + 1, 1) / DAY_MS);
        case 'quarter':
            return Math.round(Date.UTC(year, month + 3, 1) / DAY_MS);
        default:
            return Math.round(Date.UTC(year + 1, 0, 1) / DAY_MS);
    }
}

/**
 * The header cells for one tier between two day numbers.
 *
 * @returns {{key: string, start: number, days: number, label: string}[]}
 */
export function unitCells(from, to, unit, locale, weekStart) {
    if (unit === 'none') {
        return [];
    }

    const formatters = {
        day: new Intl.DateTimeFormat(locale, { day: 'numeric', timeZone: 'UTC' }),
        week: new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }),
        month: new Intl.DateTimeFormat(locale, { month: 'short', timeZone: 'UTC' }),
        monthYear: new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }),
        year: new Intl.DateTimeFormat(locale, { year: 'numeric', timeZone: 'UTC' }),
    };

    const cells = [];

    for (let cursor = startOfUnit(from, unit, weekStart); cursor <= to; cursor = nextUnit(cursor, unit)) {
        const end = nextUnit(cursor, unit);
        const date = toDate(cursor);
        let label;

        switch (unit) {
            case 'day':
                label = formatters.day.format(date);
                break;
            case 'week':
                label = formatters.week.format(date);
                break;
            case 'month':
                label = formatters.month.format(date);
                break;
            case 'quarter':
                label = `Q${Math.floor(date.getUTCMonth() / 3) + 1}`;
                break;
            default:
                label = formatters.year.format(date);
        }

        cells.push({ key: `${unit}-${cursor}`, start: cursor, days: end - cursor, label });
    }

    return cells;
}

/** The top tier uses a longer "March 2027" label when it holds months. */
export function topCells(from, to, unit, locale, weekStart) {
    if (unit !== 'month') {
        return unitCells(from, to, unit, locale, weekStart);
    }

    const format = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' });

    return unitCells(from, to, unit, locale, weekStart).map((cell) => ({ ...cell, label: format.format(toDate(cell.start)) }));
}

/** Working days between two day numbers, both inclusive. */
export function workingDays(start, end, weekend) {
    let count = 0;

    for (let day = start; day <= end; day++) {
        if (!weekend.includes(weekday(day))) {
            count++;
        }
    }

    return count;
}
