/**
 * In-page preview for Word and Excel files (`officePreview` Alpine component).
 *
 * Browsers cannot render DOCX/XLSX natively, so the file is fetched from the
 * authenticated download route and rendered client-side — nothing is sent to
 * a third-party viewer. Both libraries are imported lazily: pages that never
 * open an Office preview never download them.
 *
 * Spreadsheet cells are written with textContent, never innerHTML, so cell
 * values can never inject markup into the page.
 */

const MAX_SHEET_ROWS = 500;
const MAX_SHEET_COLUMNS = 50;

async function renderDocx(buffer, container) {
    const { renderAsync } = await import('docx-preview');

    await renderAsync(buffer, container, null, {
        className: 'docx-preview',
        inWrapper: true,
        ignoreWidth: false,
        breakPages: true,
        experimental: false,
        useBase64URL: true,
    });
}

function buildSheetTable(XLSX, sheet) {
    const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false, defval: '', blankrows: false });
    const table = document.createElement('table');
    table.className = 'sheet-preview-table';

    const body = document.createElement('tbody');
    rows.slice(0, MAX_SHEET_ROWS).forEach((row, rowIndex) => {
        const tr = document.createElement('tr');
        const header = document.createElement('th');
        header.textContent = String(rowIndex + 1);
        tr.appendChild(header);

        row.slice(0, MAX_SHEET_COLUMNS).forEach((value) => {
            const cell = document.createElement(rowIndex === 0 ? 'th' : 'td');
            cell.textContent = value === null || value === undefined ? '' : String(value);
            tr.appendChild(cell);
        });

        body.appendChild(tr);
    });
    table.appendChild(body);

    return { table, totalRows: rows.length };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('officePreview', (config) => ({
        status: 'loading',
        sheets: [],
        activeSheet: 0,
        truncated: false,
        workbook: null,
        XLSX: null,

        async init() {
            try {
                const response = await fetch(config.url, { credentials: 'same-origin' });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const buffer = await response.arrayBuffer();

                if (config.type === 'docx') {
                    await renderDocx(buffer, this.$refs.canvas);
                } else {
                    this.XLSX = await import('xlsx');
                    this.workbook = this.XLSX.read(buffer, { type: 'array', cellDates: true });
                    this.sheets = this.workbook.SheetNames;
                    this.showSheet(0);
                }

                this.status = 'ready';
            } catch (error) {
                console.error('Office preview failed', error);
                this.status = 'error';
            }
        },

        showSheet(index) {
            this.activeSheet = index;
            const { table, totalRows } = buildSheetTable(this.XLSX, this.workbook.Sheets[this.sheets[index]]);
            this.truncated = totalRows > MAX_SHEET_ROWS;
            this.$refs.canvas.replaceChildren(table);
        },
    }));
});
