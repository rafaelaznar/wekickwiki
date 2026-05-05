/**
 * WKW Plugin: table-colrowspan
 *
 * Renders HTML <table> blocks that contain colspan and/or rowspan attributes
 * as proper ODT table elements in the flat-ODT (.fodt) export.
 *
 * Without this plugin, HTML tables with merged cells are silently omitted
 * from the exported file (the base wiki only handles plain GFM tables).
 *
 * Hook: wkw.odt.htmlTable
 *   @param {string}   current          Accumulated ODT XML (pass-through if non-empty)
 *   @param {string}   html             Raw HTML of the <table> block
 *   @param {object}   ctx
 *   @param {function} ctx.odtXmlEsc    XML-escape a string
 *   @param {function} ctx.odtInline    Convert inline markdown/HTML to ODT span elements
 *   @param {function} ctx.nextTableName Returns the next unique table name ('Tbl1', 'Tbl2', …)
 * @returns {string} ODT XML for the table
 */
WKW.registerPlugin({
    id:          'table-colrowspan',
    name:        'Table colspan / rowspan (ODT)',
    version:     '1.0.0',
    description: 'Renders HTML <table> blocks with colspan and rowspan in the ODT export.',
    author:      'WeKickWiki',
    hooks:       ['wkw.odt.htmlTable'],
}, {
    'wkw.odt.htmlTable': function (current, html, ctx) {
        // If another handler already produced output, leave it untouched.
        if (current !== '') return current;

        const { odtXmlEsc, odtInline, nextTableName } = ctx;

        // ── Parse all <tr> rows from the HTML table ───────────────────────────
        const parsedRows = [];
        const rowRe = /<tr[^>]*>([\s\S]*?)<\/tr>/gi;
        let rowMatch;
        while ((rowMatch = rowRe.exec(html)) !== null) {
            const cells = [];
            const cellRe = /<(td|th)([^>]*)>([\s\S]*?)<\/\1>/gi;
            let cellMatch;
            while ((cellMatch = cellRe.exec(rowMatch[1])) !== null) {
                const tag     = cellMatch[1].toLowerCase();
                const attrs   = cellMatch[2];
                const content = cellMatch[3].trim();
                const cM = attrs.match(/colspan\s*=\s*["']?(\d+)["']?/i);
                const rM = attrs.match(/rowspan\s*=\s*["']?(\d+)["']?/i);
                cells.push({
                    isHeader: tag === 'th',
                    content,
                    colspan: cM ? Math.max(1, parseInt(cM[1])) : 1,
                    rowspan: rM ? Math.max(1, parseInt(rM[1])) : 1,
                });
            }
            if (cells.length > 0) parsedRows.push(cells);
        }
        if (parsedRows.length === 0) return current;

        const numRows = parsedRows.length;

        // ── Compute cell placement accounting for rowspan / colspan ───────────
        // occupancy[r] = Set of column indices already claimed by a rowspan above.
        const occupancy = Array.from({ length: numRows + 5 }, () => new Set());
        const placement = [];
        let numCols = 0;

        for (let r = 0; r < numRows; r++) {
            placement[r] = [];
            let col = 0, cellIdx = 0;
            while (true) {
                // Skip columns already occupied by a rowspan from an earlier row.
                while (occupancy[r].has(col)) {
                    placement[r].push({ covered: true });
                    col++;
                }
                if (cellIdx >= parsedRows[r].length) break;
                const cell = parsedRows[r][cellIdx++];
                placement[r].push({ ...cell, covered: false });
                // Mark cells to the right that are covered by this cell's colspan.
                for (let dc = 1; dc < cell.colspan; dc++) {
                    placement[r].push({ covered: true });
                }
                // Mark cells below that are covered by this cell's rowspan.
                for (let dr = 1; dr < cell.rowspan; dr++) {
                    for (let dc = 0; dc < cell.colspan; dc++) {
                        occupancy[r + dr].add(col + dc);
                    }
                }
                col += cell.colspan;
                numCols = Math.max(numCols, col);
            }
            // Fill any trailing covered positions to keep all rows equal width.
            while (col < numCols) { placement[r].push({ covered: true }); col++; }
        }
        // Pad every row to exactly numCols entries.
        for (let r = 0; r < numRows; r++) {
            while (placement[r].length < numCols) placement[r].push({ covered: true });
        }

        // ── Emit ODT XML ──────────────────────────────────────────────────────
        const tname = nextTableName();
        let t = '<table:table table:name="' + odtXmlEsc(tname) + '" table:style-name="T_Table">';
        for (let c = 0; c < numCols; c++) t += '<table:table-column table:style-name="T_Col"/>';
        for (const row of placement) {
            t += '<table:table-row>';
            for (const cell of row) {
                if (cell.covered) {
                    t += '<table:covered-table-cell/>';
                } else {
                    const cs  = cell.isHeader ? 'T_CellH' : 'T_Cell';
                    const ps  = cell.isHeader ? 'P_TH'    : 'P_TD';
                    let attrs = ' office:value-type="string"';
                    if (cell.colspan > 1) attrs += ' table:number-columns-spanned="' + cell.colspan + '"';
                    if (cell.rowspan > 1) attrs += ' table:number-rows-spanned="'    + cell.rowspan + '"';
                    t += '<table:table-cell table:style-name="' + cs + '"' + attrs + '>';
                    t += '<text:p text:style-name="' + ps + '">' + odtInline(cell.content) + '</text:p>';
                    t += '</table:table-cell>';
                }
            }
            t += '</table:table-row>';
        }
        t += '</table:table>';
        return t;
    }
});
