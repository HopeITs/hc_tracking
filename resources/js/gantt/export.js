import { toIso } from './timescale';

/**
 * Getting a Gantt out of the app for donors and meetings.
 *
 * - CSV opens straight in Excel (UTF-8 BOM, so Arabic names survive) with
 *   every column MS Project exports: WBS, dates, duration, % complete,
 *   predecessors, float, critical, baseline and variance.
 * - Print lays the whole chart (every row, no virtualization) on landscape
 *   pages; the browser's "Save as PDF" makes the PDF.
 */

function csvCell(value) {
    const text = value === null || value === undefined ? '' : String(value);

    return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

export function exportCsv(chart, filename) {
    const labels = chart.labels.csv ?? {};
    const header = [
        labels.wbs ?? 'WBS',
        labels.name ?? 'Name',
        labels.kind ?? 'Type',
        labels.start ?? 'Start',
        labels.finish ?? 'Finish',
        labels.duration ?? 'Duration (working days)',
        labels.progress ?? '% complete',
        labels.predecessors ?? 'Predecessors',
        labels.owner ?? 'Owner',
        labels.status ?? 'Status',
        labels.float ?? 'Total float',
        labels.critical ?? 'Critical',
        labels.baselineStart ?? 'Baseline start',
        labels.baselineFinish ?? 'Baseline finish',
        labels.variance ?? 'Finish variance',
    ];

    const lines = [header.map(csvCell).join(',')];

    for (const row of chart.rows) {
        lines.push([
            row.wbs,
            `${'  '.repeat(row.depth)}${row.name}`,
            chart.labels.kinds?.[row.kind] ?? row.kind,
            row.startDay === null ? '' : toIso(row.startDay),
            row.endDay === null ? '' : toIso(row.endDay),
            row.kind === 'group' ? '' : chart.duration(row),
            row.kind === 'group' ? '' : row.progress,
            row.predecessors ?? '',
            row.owner ?? '',
            row.statusLabel ?? '',
            row.float ?? '',
            row.critical ? '✔' : '',
            row.baselineStartDay === null ? '' : toIso(row.baselineStartDay),
            row.baselineEndDay === null ? '' : toIso(row.baselineEndDay),
            chart.variance(row) ?? '',
        ].map(csvCell).join(','));
    }

    const blob = new Blob(['﻿', lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function printChart(chart) {
    const root = chart.$root;
    const done = () => {
        chart.printing = false;
        root.classList.remove('gantt-printing');
        document.documentElement.classList.remove('gantt-print-mode');
        window.removeEventListener('afterprint', done);
    };

    chart.printing = true;
    root.classList.add('gantt-printing');
    document.documentElement.classList.add('gantt-print-mode');
    window.addEventListener('afterprint', done);

    // Let Alpine render every row before the print snapshot is taken.
    chart.$nextTick(() => requestAnimationFrame(() => window.print()));
}
