    // ── Symbol substitution ─────────────────────────────────────────────────────
    // Applied as a markdown preprocessor so it works with any marked version.
    // Longer/more-specific patterns are listed before shorter overlapping ones.
    const SYMBOL_SUBS = [
      // Must come before their shorter prefixes
      [/---/g,      '\u2014'],   // em dash
      [/--/g,       '\u2013'],   // en dash
      [/\.\.\./g,   '\u2026'],   // ellipsis
      [/<=>/g,      '\u21D4'],   // left-right double arrow
      [/=>/g,       '\u21D2'],   // right double arrow
      [/<=/g,       '\u21D0'],   // left double arrow
      [/->/g,       '\u2192'],   // right arrow
      [/<-/g,       '\u2190'],   // left arrow
      [/\+-/g,      '\u00B1'],   // plus-minus
      [/\(c\)/gi,   '\u00A9'],   // copyright
      [/\(r\)/gi,   '\u00AE'],   // registered
      [/\(tm\)/gi,  '\u2122'],   // trademark
      [/\(p\)/gi,   '\u2117'],   // sound-recording copyright
      [/\(e\)/gi,   '\u20AC'],   // euro
      [/\(deg\)/gi, '\u00B0'],   // degree
      [/\(1\/2\)/g, '\u00BD'],   // one-half
      [/\(1\/4\)/g, '\u00BC'],   // one-quarter
      [/\(3\/4\)/g, '\u00BE'],   // three-quarters
      [/\(x\)/gi,   '\u00D7'],   // multiplication sign
      [/!=|\/=/g,   '\u2260'],   // not equal
      [/>=/g,       '\u2265'],   // greater-or-equal
    ];

    function applySymbols(md) {
      // Split on fenced code blocks and inline code spans — leave those untouched.
      const parts = md.split(/(```[\s\S]*?```|`[^`]*`)/g);
      return parts.map((chunk, i) => {
        if (i % 2 === 1) return chunk; // inside code → leave untouched
        // Process line by line so table separator rows (|---|---|) are never touched.
        return chunk.split('\n').map(line => {
          // A table separator line only contains |, -, :, and spaces (may be indented).
          if (/^\s*\|[\s|:\-]+\|?\s*$/.test(line)) return line;
          for (const [re, ch] of SYMBOL_SUBS) line = line.replace(re, ch);
          return line;
        }).join('\n');
      }).join('');
    }

    function parseWiki(md) {
      return marked.parse(applySymbols(md));
    }

    // ── Toast ───────────────────────────────────────────────────────────────────
    let _toastTimer;

    function showToast(msg, type = 'success', duration = 3000) {
      const el = document.getElementById('toast');
      el.textContent = msg;
      el.className = type + ' show';
      clearTimeout(_toastTimer);
      _toastTimer = setTimeout(() => el.classList.remove('show'), duration);
    }

    // ── Icons ───────────────────────────────────────────────────────────────────
    const ICON_EDIT = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    const ICON_VIEW = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const ICON_PLUS = '<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';

    // ── ODT download (client-side generation) ───────────────────────────────────
    function odtXmlEsc(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function odtInline(text) {
      // Decode a handful of HTML entities that may come from applySymbols / inline HTML
      function decEnt(s) {
        return s.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>')
                .replace(/&quot;/g,'"').replace(/&apos;/g,"'").replace(/&nbsp;/g,'\u00a0')
                .replace(/&#(\d+);/g,(_,n)=>String.fromCodePoint(+n))
                .replace(/&#x([0-9a-f]+);/gi,(_,h)=>String.fromCodePoint(parseInt(h,16)));
      }
      let out = ''; let i = 0;
      while (i < text.length) {
        // HTML tags
        if (text[i] === '<') {
          const tag = text.slice(i).match(/^<(\/?)(strong|b|em|i|code|s|del|strike|u|ins|mark|br)(\s[^>]*)?\/?>|^<a(\s[^>]*)?>|^<\/a>/i);
          if (tag) {
            const full = tag[0];
            const closing = full.startsWith('</');
            const tagName = (tag[1] !== undefined ? tag[2] : (full.match(/<(a|br)/i)||[])[1]||'').toLowerCase();
            if (tagName === 'br') { out += '<text:line-break/>'; }
            else if (!closing) {
              const style = {strong:'C_Bold',b:'C_Bold',em:'C_Italic',i:'C_Italic',
                            code:'C_Code',s:'C_Strike',del:'C_Strike',strike:'C_Strike',
                            u:'C_Under',ins:'C_Under',mark:'C_Mark',a:''}[tagName];
              if (style) out += '<text:span text:style-name="'+style+'">';
              else if (tagName === 'a') out += '<text:span>'; // plain span for links
            } else {
              out += '</text:span>';
            }
            i += full.length; continue;
          }
          // HTML entity starting with &
          const ent = text.slice(i).match(/^&([a-zA-Z]+|#\d+|#x[0-9a-fA-F]+);/);
          if (ent) { out += odtXmlEsc(decEnt(ent[0])); i += ent[0].length; continue; }
        }
        if (text[i] === '&') {
          const ent = text.slice(i).match(/^&([a-zA-Z]+|#\d+|#x[0-9a-fA-F]+);/);
          if (ent) { out += odtXmlEsc(decEnt(ent[0])); i += ent[0].length; continue; }
        }
        if (text.startsWith('**', i)) {
          const j = text.indexOf('**', i + 2);
          if (j !== -1) { out += '<text:span text:style-name="C_Bold">' + odtInline(text.slice(i+2,j)) + '</text:span>'; i = j+2; continue; }
        }
        if (text.startsWith('__', i)) {
          const j = text.indexOf('__', i + 2);
          if (j !== -1) { out += '<text:span text:style-name="C_Bold">' + odtInline(text.slice(i+2,j)) + '</text:span>'; i = j+2; continue; }
        }
        if (text.startsWith('~~', i)) {
          const j = text.indexOf('~~', i + 2);
          if (j !== -1) { out += '<text:span text:style-name="C_Strike">' + odtInline(text.slice(i+2,j)) + '</text:span>'; i = j+2; continue; }
        }
        if (text[i] === '*' && !text.startsWith('**', i)) {
          const j = text.indexOf('*', i + 1);
          if (j !== -1 && !text.startsWith('**', j)) { out += '<text:span text:style-name="C_Italic">' + odtInline(text.slice(i+1,j)) + '</text:span>'; i = j+1; continue; }
        }
        if (text[i] === '_' && !text.startsWith('__', i)) {
          const j = text.indexOf('_', i + 1);
          if (j !== -1 && !text.startsWith('__', j)) { out += '<text:span text:style-name="C_Italic">' + odtInline(text.slice(i+1,j)) + '</text:span>'; i = j+1; continue; }
        }
        if (text[i] === '`') {
          const j = text.indexOf('`', i + 1);
          if (j !== -1) { out += '<text:span text:style-name="C_Code">' + odtXmlEsc(text.slice(i+1,j)) + '</text:span>'; i = j+1; continue; }
        }
        if (text[i] === '!' && text[i+1] === '[') {
          const m = text.slice(i).match(/^!\[([^\]]*)\]\([^)]*\)/);
          if (m) { if (m[1]) out += odtXmlEsc('['+m[1]+']'); i += m[0].length; continue; }
        }
        if (text[i] === '[') {
          const m = text.slice(i).match(/^\[([^\]]*)\]\([^)]*\)/);
          if (m) { out += odtInline(m[1]); i += m[0].length; continue; }
        }
        out += odtXmlEsc(text[i]); i++;
      }
      return out;
    }

    function odtBody(md, inQuote) {
      // Pre-process: collect GFM tables and blockquotes into token objects.
      function parseBlocks(rawLines) {
        const tokens = [];
        let j = 0;
        while (j < rawLines.length) {
          // HTML table block: collect lines until </table>
          // Strip inline code spans before testing so `<table>` inside backticks is ignored.
          if (/<table(\s[^>]*)?>/.test(rawLines[j].replace(/`[^`]*`/g, ''))) {
            let html = '';
            while (j < rawLines.length) {
              html += rawLines[j] + ' ';
              j++;
              if (/<\/table>/i.test(html)) break;
            }
            tokens.push({ type: 'htmltable', html: html.trim() });
            continue;
          }
          // Blockquote: one or more consecutive lines starting with >
          if (/^>/.test(rawLines[j])) {
            const qLines = [];
            while (j < rawLines.length && /^>/.test(rawLines[j])) {
              qLines.push(rawLines[j].replace(/^>\s?/, '')); j++;
            }
            tokens.push({ type: 'quote', content: qLines.join('\n') });
            continue;
          }
          // A GFM table needs at least 3 lines: header | sep | body...
          // Header: contains |   Sep: only |, -, :, space
          if (j + 2 < rawLines.length
              && /\|/.test(rawLines[j])
              && /^\s*\|?[\s|:\-]+\|?\s*$/.test(rawLines[j+1])) {
            const splitCells = line => line.replace(/^\|/,'').replace(/\|$/,'').split('|').map(c => c.trim());
            const headers = splitCells(rawLines[j]);
            let k = j + 2;
            const rows = [];
            while (k < rawLines.length && /\|/.test(rawLines[k])
                   && !/^\s*\|?[\s|:\-]+\|?\s*$/.test(rawLines[k])) {
              rows.push(splitCells(rawLines[k])); k++;
            }
            tokens.push({ type: 'table', headers, rows });
            j = k;
          } else {
            tokens.push({ type: 'line', text: rawLines[j] }); j++;
          }
        }
        return tokens;
      }
      const S_BODY = inQuote ? 'P_Quote' : 'P_Body';
      const S_PRE  = inQuote ? 'P_QuotePre' : 'P_Pre';
      const S_LI   = inQuote ? 'P_QuoteLi' : 'P_Li';

      let tableCounter = 0;

      function odtHtmlTable(html) {
        // Parse all <tr> rows from the HTML table
        const parsedRows = [];
        const rowRe = /<tr[^>]*>([\s\S]*?)<\/tr>/gi;
        let rowMatch;
        while ((rowMatch = rowRe.exec(html)) !== null) {
          const cells = [];
          const cellRe = /<(td|th)([^>]*)>([\s\S]*?)<\/\1>/gi;
          let cellMatch;
          while ((cellMatch = cellRe.exec(rowMatch[1])) !== null) {
            const tag = cellMatch[1].toLowerCase();
            const attrs = cellMatch[2];
            const content = cellMatch[3].trim();
            const cM = attrs.match(/colspan\s*=\s*["']?(\d+)["']?/i);
            const rM = attrs.match(/rowspan\s*=\s*["']?(\d+)["']?/i);
            cells.push({
              isHeader: tag === 'th',
              content,
              colspan: cM ? Math.max(1, parseInt(cM[1])) : 1,
              rowspan: rM ? Math.max(1, parseInt(rM[1])) : 1
            });
          }
          if (cells.length > 0) parsedRows.push(cells);
        }
        if (parsedRows.length === 0) return '';

        const numRows = parsedRows.length;
        // Track which column positions are occupied by rowspans from above rows
        const occupancy = Array.from({length: numRows + 5}, () => new Set());
        const placement = [];
        let numCols = 0;

        for (let r = 0; r < numRows; r++) {
          placement[r] = [];
          let col = 0, cellIdx = 0;
          while (true) {
            // Drain all columns occupied by rowspans before placing the next cell
            while (occupancy[r].has(col)) {
              placement[r].push({ covered: true });
              col++;
            }
            if (cellIdx >= parsedRows[r].length) break;
            const cell = parsedRows[r][cellIdx++];
            placement[r].push({ ...cell, covered: false });
            // Immediately add covered cells for colspan within the same row
            for (let dc = 1; dc < cell.colspan; dc++) {
              placement[r].push({ covered: true });
            }
            // Mark future rows' columns as occupied by this cell's rowspan
            for (let dr = 1; dr < cell.rowspan; dr++) {
              for (let dc = 0; dc < cell.colspan; dc++) {
                occupancy[r + dr].add(col + dc);
              }
            }
            col += cell.colspan;
            numCols = Math.max(numCols, col);
          }
          // Fill any remaining occupied positions at the end of this row
          while (col < numCols) { placement[r].push({ covered: true }); col++; }
        }
        // Ensure every row has exactly numCols entries
        for (let r = 0; r < numRows; r++) {
          while (placement[r].length < numCols) placement[r].push({ covered: true });
        }

        tableCounter++;
        const tname = 'Tbl' + tableCounter;
        let t = '<table:table table:name="' + odtXmlEsc(tname) + '" table:style-name="T_Table">';
        for (let c = 0; c < numCols; c++) t += '<table:table-column table:style-name="T_Col"/>';
        for (const row of placement) {
          t += '<table:table-row>';
          for (const cell of row) {
            if (cell.covered) {
              t += '<table:covered-table-cell/>';
            } else {
              const cs = cell.isHeader ? 'T_CellH' : 'T_Cell';
              const ps = cell.isHeader ? 'P_TH' : 'P_TD';
              let attrs = ' office:value-type="string"';
              if (cell.colspan > 1) attrs += ' table:number-columns-spanned="' + cell.colspan + '"';
              if (cell.rowspan > 1) attrs += ' table:number-rows-spanned="' + cell.rowspan + '"';
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

      function odtTable(headers, rows) {
        tableCounter++;
        const tname = 'Tbl' + tableCounter;
        const cols = headers.length;
        let t = '<table:table table:name="'+odtXmlEsc(tname)+'" table:style-name="T_Table">';
        for (let c = 0; c < cols; c++) t += '<table:table-column table:style-name="T_Col"/>';
        // header row
        t += '<table:table-header-rows><table:table-row>';
        for (const h of headers)
          t += '<table:table-cell table:style-name="T_CellH" office:value-type="string"><text:p text:style-name="P_TH">'+odtInline(h)+'</text:p></table:table-cell>';
        t += '</table:table-row></table:table-header-rows>';
        // body rows
        for (const row of rows) {
          t += '<table:table-row>';
          for (let c = 0; c < cols; c++) {
            const cell = row[c] !== undefined ? row[c] : '';
            t += '<table:table-cell table:style-name="T_Cell" office:value-type="string"><text:p text:style-name="P_TD">'+odtInline(cell)+'</text:p></table:table-cell>';
          }
          t += '</table:table-row>';
        }
        t += '</table:table>';
        return t;
      }

      const rawLines = md.replace(/\r\n/g,'\n').replace(/\r/g,'\n').split('\n');
      const tokens = parseBlocks(rawLines);
      const lines = tokens.map(t =>
        t.type === 'line'      ? t.text :
        t.type === 'table'     ? '\x00TABLE\x00' + JSON.stringify({h:t.headers,r:t.rows}) :
        t.type === 'htmltable' ? '\x00HTMLTABLE\x00' + t.html :
        /* quote */               '\x00QUOTE\x00' + t.content
      );

      let out = '', i = 0, inList = false, lstType = '', inCode = false, codeBuf = [];
      while (i < lines.length) {
        const line = lines[i];
        if (/^```/.test(line)) {
          if (inCode) {
            inCode = false;
            if (inList) { out += '</text:list>'; inList = false; }
            for (const cl of codeBuf) out += '<text:p text:style-name="'+S_PRE+'">' + odtXmlEsc(cl) + '</text:p>';
            codeBuf = [];
          } else {
            if (inList) { out += '</text:list>'; inList = false; }
            inCode = true;
          }
          i++; continue;
        }
        if (inCode) { codeBuf.push(line); i++; continue; }
        const isUL = /^(\s*)[-*+]\s+(.*)$/.exec(line);
        const isOL = !isUL && /^\d+\.\s+(.*)$/.exec(line);
        if (inList && !isUL && !isOL && line.trim() !== '') { out += '</text:list>'; inList = false; }
        if (line.startsWith('\x00TABLE\x00')) {
          const td = JSON.parse(line.slice(7));
          out += odtTable(td.h, td.r);
        } else if (line.startsWith('\x00HTMLTABLE\x00')) {
          if (inList) { out += '</text:list>'; inList = false; }
          out += odtHtmlTable(line.slice(11));
        } else if (line.startsWith('\x00QUOTE\x00')) {
          if (inList) { out += '</text:list>'; inList = false; }
          out += odtBody(line.slice(7), true);
        } else {
          const hd = /^(#{1,6})\s+(.+)$/.exec(line);
          if (hd) {
            const lvl = hd[1].length;
            out += '<text:h text:style-name="P_H'+lvl+'" text:outline-level="'+lvl+'">'+odtInline(hd[2])+'</text:h>';
          } else if (/^(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
            out += '<text:p text:style-name="P_HR"/>';
          } else if (isUL) {
            if (!inList || lstType !== 'ul') { if (inList) out += '</text:list>'; out += '<text:list text:style-name="LS_Bullet">'; inList = true; lstType = 'ul'; }
            out += '<text:list-item><text:p text:style-name="'+S_LI+'">'+odtInline(isUL[2])+'</text:p></text:list-item>';
          } else if (isOL) {
            if (!inList || lstType !== 'ol') { if (inList) out += '</text:list>'; out += '<text:list text:style-name="LS_Number">'; inList = true; lstType = 'ol'; }
            out += '<text:list-item><text:p text:style-name="'+S_LI+'">'+odtInline(isOL[1])+'</text:p></text:list-item>';
          } else if (line.trim() === '') {
            // blank
          } else {
            const pl = [line];
            while (i+1 < lines.length && lines[i+1].trim() !== ''
              && !/^#{1,6}\s/.test(lines[i+1]) && !/^```/.test(lines[i+1])
              && !/^(\s*)[-*+]\s/.test(lines[i+1]) && !/^\d+\.\s/.test(lines[i+1])
              && !/^(-{3,}|\*{3,}|_{3,})\s*$/.test(lines[i+1])
              && !lines[i+1].startsWith('\x00TABLE\x00')
              && !lines[i+1].startsWith('\x00HTMLTABLE\x00')
              && !lines[i+1].startsWith('\x00QUOTE\x00')
            ) { i++; pl.push(lines[i]); }
            out += '<text:p text:style-name="'+S_BODY+'">'+odtInline(pl.join(' '))+'</text:p>';
          }
        }
        i++;
      }
      if (inList) out += '</text:list>';
      return out;
    }

    function buildOdtManifest() { /* not used in flat-ODT mode */ }

    function buildOdtContent(md) {
      const xmlns =
        ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        +' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        +' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
        +' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
        +' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        +' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"';
      const astyles =
        '<style:style style:name="P_Body" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.1cm" fo:margin-bottom="0.1cm"/>'
        +'<style:text-properties fo:font-size="12pt"/>'
        +'</style:style>'
        +'<style:style style:name="P_H1" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.5cm" fo:margin-bottom="0.25cm"/>'
        +'<style:text-properties fo:font-size="18pt" fo:font-weight="bold" fo:color="#8A0808"/>'
        +'</style:style>'
        +'<style:style style:name="P_H2" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.4cm" fo:margin-bottom="0.2cm"/>'
        +'<style:text-properties fo:font-size="15pt" fo:font-weight="bold" fo:color="#8A0808"/>'
        +'</style:style>'
        +'<style:style style:name="P_H3" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.35cm" fo:margin-bottom="0.15cm"/>'
        +'<style:text-properties fo:font-size="13pt" fo:font-weight="bold" fo:color="#8A0808"/>'
        +'</style:style>'
        +'<style:style style:name="P_H4" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.3cm" fo:margin-bottom="0.1cm"/>'
        +'<style:text-properties fo:font-size="12pt" fo:font-weight="bold" fo:color="#8A0808"/>'
        +'</style:style>'
        +'<style:style style:name="P_H5" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.25cm" fo:margin-bottom="0.1cm"/>'
        +'<style:text-properties fo:font-size="11pt" fo:font-weight="bold"/>'
        +'</style:style>'
        +'<style:style style:name="P_H6" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.2cm" fo:margin-bottom="0.1cm"/>'
        +'<style:text-properties fo:font-size="10pt" fo:font-weight="bold"/>'
        +'</style:style>'
        +'<style:style style:name="P_Pre" style:family="paragraph">'
        +'<style:paragraph-properties fo:background-color="#f5f5f5" fo:padding="0.1cm" fo:margin-top="0cm" fo:margin-bottom="0cm"/>'
        +'<style:text-properties style:font-name="Liberation Mono" fo:font-family="Liberation Mono" fo:font-size="10pt"/>'
        +'</style:style>'
        +'<style:style style:name="P_HR" style:family="paragraph">'
        +'<style:paragraph-properties fo:border-bottom="0.05cm solid #cccccc" fo:padding-bottom="0.15cm" fo:margin-bottom="0.15cm"/>'
        +'</style:style>'
        +'<style:style style:name="P_Li" style:family="paragraph">'
        +'<style:text-properties fo:font-size="12pt"/>'
        +'</style:style>'
        +'<style:style style:name="C_Bold" style:family="text">'
        +'<style:text-properties fo:font-weight="bold"/>'
        +'</style:style>'
        +'<style:style style:name="C_Italic" style:family="text">'
        +'<style:text-properties fo:font-style="italic"/>'
        +'</style:style>'
        +'<style:style style:name="C_Code" style:family="text">'
        +'<style:text-properties style:font-name="Liberation Mono" fo:font-family="Liberation Mono" fo:font-size="10pt" fo:background-color="#f0f0f0"/>'
        +'</style:style>'
        +'<text:list-style style:name="LS_Bullet">'
        +'<text:list-level-style-bullet text:level="1" text:bullet-char="&#x2022;">'
        +'<style:list-level-properties text:space-before="0.5cm" text:min-label-width="0.5cm"/>'
        +'</text:list-level-style-bullet>'
        +'</text:list-style>'
        +'<text:list-style style:name="LS_Number">'
        +'<text:list-level-style-number text:level="1" style:num-format="1" style:num-suffix=".">'
        +'<style:list-level-properties text:space-before="0.5cm" text:min-label-width="0.5cm"/>'
        +'</text:list-level-style-number>'
        +'</text:list-style>'
        +'<style:style style:name="T_Table" style:family="table">'
        +'<style:table-properties style:width="16cm" fo:margin-top="0.3cm" fo:margin-bottom="0.3cm" table:border-model="collapsing"/>'
        +'</style:style>'
        +'<style:style style:name="T_Col" style:family="table-column">'
        +'<style:table-column-properties style:column-width="4cm"/>'
        +'</style:style>'
        +'<style:style style:name="T_CellH" style:family="table-cell">'
        +'<style:table-cell-properties fo:border="0.05cm solid #dddddd" fo:background-color="#f5f5f5" fo:padding="0.12cm"/>'
        +'</style:style>'
        +'<style:style style:name="T_Cell" style:family="table-cell">'
        +'<style:table-cell-properties fo:border="0.05cm solid #dddddd" fo:padding="0.12cm"/>'
        +'</style:style>'
        +'<style:style style:name="P_TH" style:family="paragraph">'
        +'<style:text-properties fo:font-weight="bold" fo:font-size="11pt"/>'
        +'</style:style>'
        +'<style:style style:name="P_TD" style:family="paragraph">'
        +'<style:text-properties fo:font-size="11pt"/>'
        +'</style:style>'
        +'<style:style style:name="P_Quote" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-left="0.8cm" fo:padding-left="0.3cm" fo:border-left="0.1cm solid #cccccc" fo:margin-top="0.05cm" fo:margin-bottom="0.05cm"/>'
        +'<style:text-properties fo:font-size="12pt" fo:color="#555555"/>'
        +'</style:style>'
        +'<style:style style:name="P_QuotePre" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-left="0.8cm" fo:padding-left="0.3cm" fo:border-left="0.1cm solid #cccccc" fo:background-color="#f5f5f5" fo:padding="0.1cm" fo:margin-top="0cm" fo:margin-bottom="0cm"/>'
        +'<style:text-properties style:font-name="Liberation Mono" fo:font-family="Liberation Mono" fo:font-size="10pt" fo:color="#555555"/>'
        +'</style:style>'
        +'<style:style style:name="P_QuoteLi" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-left="0.8cm"/>'
        +'<style:text-properties fo:font-size="12pt" fo:color="#555555"/>'
        +'</style:style>'
        +'<style:style style:name="C_Strike" style:family="text">'
        +'<style:text-properties style:text-line-through-style="solid"/>'
        +'</style:style>'
        +'<style:style style:name="C_Under" style:family="text">'
        +'<style:text-properties style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"/>'
        +'</style:style>'
        +'<style:style style:name="C_Mark" style:family="text">'
        +'<style:text-properties fo:background-color="#ffff00"/>'
        +'</style:style>';
      return '\x3C?xml version="1.0" encoding="UTF-8"?>'
        +'<office:document'
        +xmlns
        +' office:version="1.3"'
        +' office:mimetype="application/vnd.oasis.opendocument.text">'
        +'<office:font-face-decls>'
        +'<style:font-face style:name="Liberation Mono" svg:font-family="&apos;Liberation Mono&apos;" style:font-family-generic="modern" style:font-pitch="fixed"/>'
        +'</office:font-face-decls>'
        +'<office:automatic-styles>'+astyles+'</office:automatic-styles>'
        +'<office:body><office:text>'
        +odtBody(applySymbols(md))
        +'</office:text></office:body>'
        +'</office:document>';
    }

    async function downloadOdt() {
      if (!rawMd) return;
      const btn = document.getElementById('odt-btn');
      btn.disabled = true;
      try {
        const xml = buildOdtContent(rawMd);
        const blob = new Blob([xml], {type: 'application/vnd.oasis.opendocument.text-flat-xml'});
        const filename = currentPage.split('/').pop() + '.fodt';
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
        showToast('Downloaded: ' + filename);
      } catch(e) {
        console.error('ODT error:', e);
        showToast('Error generating ODT: ' + e.message, 'error');
      } finally {
        btn.disabled = false;
      }
    }

    // ── Backup & Restore ────────────────────────────────────────────────────────
    async function downloadBackup() {
      const btn = document.getElementById('backup-btn');
      btn.disabled = true;
      try {
        const res = await apiFetch('?action=backup');
        if (!res || !res.ok) {
          const data = await res.json().catch(() => ({}));
          showToast(data.error || 'Error generating backup', 'error');
          return;
        }
        // Derive filename from Content-Disposition header or build a fallback
        let filename = 'wkw-backup.txt';
        const cd = res.headers.get('Content-Disposition') || '';
        const m  = cd.match(/filename="?([^";\s]+)"?/);
        if (m) filename = m[1];

        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        showToast('Backup downloaded: ' + filename);
      } catch {
        showToast('Connection error during backup', 'error');
      } finally {
        btn.disabled = false;
      }
    }

    function restoreBackup() {
      document.getElementById('restore-input').click();
    }

    document.getElementById('restore-input').addEventListener('change', async function () {
      const file = this.files[0];
      if (!file) return;

      const confirmed = confirm(
        '⚠️ RESTORE BACKUP\n\n' +
        'This will permanently DELETE the entire pages/ directory and replace ALL wiki content with the uploaded backup.\n\n' +
        'This operation CANNOT be undone.\n\n' +
        'Are you sure you want to continue?'
      );
      this.value = ''; // reset input regardless
      if (!confirmed) return;

      const btn = document.getElementById('restore-btn');
      btn.disabled = true;
      const fd = new FormData();
      fd.append('backup', file);
      try {
        const res = await apiFetch('?action=restore', { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (res && res.ok) {
          showToast('Backup restored — ' + data.pages + ' page(s) recovered.', 'success', 5000);
          load(currentPage);
        } else {
          showToast(data.error || 'Error restoring backup', 'error');
        }
      } catch {
        showToast('Connection error during restore', 'error');
      } finally {
        btn.disabled = false;
      }
    });

    // ── Users management panel ──────────────────────────────────────────────────
    let usersOpen = false;

    async function toggleUsersPanel() {
      const overlay = document.getElementById('users-overlay');
      const panel   = document.getElementById('users-panel');
      usersOpen = !usersOpen;
      overlay.style.display = panel.style.display = usersOpen ? 'block' : 'none';
      if (!usersOpen) {
        document.getElementById('users-save-status').textContent = '';
        document.getElementById('users-admin-pass').value = '';
        document.getElementById('users-guest-pass').value = '';
        return;
      }
      // Pre-load current usernames from server
      const res = await apiFetch('?action=get-users');
      if (!res || !res.ok) {
        document.getElementById('users-save-status').textContent = 'Could not load users.';
        return;
      }
      const data = await res.json();
      document.getElementById('users-admin-name').value = data.adminUser || '';
      document.getElementById('users-guest-name').value = data.guestUser || '';
      document.getElementById('users-admin-pass').value = '';
      document.getElementById('users-guest-pass').value = '';
      document.getElementById('users-save-status').textContent = '';
      document.getElementById('guest-login-enabled').checked = data.guestLoginEnabled !== false;
    }

    document.getElementById('users-form').addEventListener('submit', async e => {
      e.preventDefault();
      const statusEl = document.getElementById('users-save-status');
      statusEl.textContent = 'Saving…';

      const adminUser = document.getElementById('users-admin-name').value.trim().toLowerCase();
      const guestUser = document.getElementById('users-guest-name').value.trim().toLowerCase();
      const adminPass = document.getElementById('users-admin-pass').value;
      const guestPass = document.getElementById('users-guest-pass').value;

      if (!/^[a-z0-9_]{2,32}$/.test(adminUser)) {
        statusEl.textContent = 'Admin username: 2–32 chars, only a-z, 0-9, _';
        return;
      }
      if (!/^[a-z0-9_]{2,32}$/.test(guestUser)) {
        statusEl.textContent = 'Guest username: 2–32 chars, only a-z, 0-9, _';
        return;
      }
      if (adminUser === guestUser) {
        statusEl.textContent = 'Admin and guest usernames must be different.';
        return;
      }

      const adminHash = adminPass ? await sha256(adminPass) : null;
      const guestHash = guestPass ? await sha256(guestPass) : null;
      const guestLoginEnabled = document.getElementById('guest-login-enabled').checked;

      try {
        const res = await apiFetch('?action=save-users', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ adminUser, adminHash, guestUser, guestHash, guestLoginEnabled })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          const currentUser = getUser();
          toggleUsersPanel();
          // If admin renamed themselves, force re-login
          if (currentUser !== adminUser) {
            showToast('Admin username changed — please sign in again.', 'success', 4000);
            setTimeout(logout, 500);
          } else {
            showToast('Users updated successfully.');
          }
        } else {
          statusEl.textContent = data.error || 'Error saving users.';
        }
      } catch {
        statusEl.textContent = 'Connection error.';
      }
    });

    // ── Settings panel ──────────────────────────────────────────────────────────
    let settingsOpen = false;

    async function toggleSettingsPanel() {
      const overlay = document.getElementById('settings-overlay');
      const panel   = document.getElementById('settings-panel');
      settingsOpen = !settingsOpen;
      overlay.style.display = panel.style.display = settingsOpen ? 'block' : 'none';
      if (!settingsOpen) {
        document.getElementById('settings-save-status').textContent = '';
        return;
      }
      // Load current settings and available templates in parallel
      const [sRes, tRes] = await Promise.all([
        apiFetch('?action=get-settings'),
        apiFetch('?action=get-templates'),
      ]);
      if (!sRes || !sRes.ok || !tRes || !tRes.ok) {
        document.getElementById('settings-save-status').textContent = 'Could not load settings.';
        return;
      }
      const settings  = await sRes.json();
      const templates = await tRes.json();
      document.getElementById('settings-wiki-name').value = settings.wikiName || '';
      const sel = document.getElementById('settings-theme');
      sel.innerHTML = '';
      for (const t of (templates.templates || [])) {
        const opt = document.createElement('option');
        opt.value = t;
        opt.textContent = t;
        if (t === settings.theme) opt.selected = true;
        sel.appendChild(opt);
      }
      document.getElementById('settings-save-status').textContent = '';
    }

    document.getElementById('settings-form').addEventListener('submit', async e => {
      e.preventDefault();
      const statusEl = document.getElementById('settings-save-status');
      statusEl.textContent = 'Saving\u2026';
      const wikiName = document.getElementById('settings-wiki-name').value.trim();
      const theme    = document.getElementById('settings-theme').value;
      if (!wikiName) {
        statusEl.textContent = 'Wiki name cannot be empty.';
        return;
      }
      try {
        const res = await apiFetch('?action=save-settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ wikiName, theme }),
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          toggleSettingsPanel();
          showToast('Settings saved. Reloading\u2026', 'success', 2000);
          setTimeout(() => location.reload(), 1500);
        } else {
          statusEl.textContent = data.error || 'Error saving settings.';
        }
      } catch {
        statusEl.textContent = 'Connection error.';
      }
    });

    // ── Base & routing helpers ──────────────────────────────────────────────────
    const BASE = window.WKW_BASE;

    function getPage() {
      const p = decodeURIComponent(location.pathname);
      return (p.length > BASE.length ? p.slice(BASE.length) : '') || 'index';
    }

    function navigate(page, replace) {
      const url = BASE + (page === 'index' ? '' : page);
      replace ? history.replaceState(null, '', url) : history.pushState(null, '', url);
      load(page);
    }

    // ── Auth helpers ────────────────────────────────────────────────────────────
    function getToken() {
      return sessionStorage.getItem('wkw_token');
    }

    function getRole() {
      return sessionStorage.getItem('wkw_role');
    }

    function getUser() {
      return sessionStorage.getItem('wkw_user');
    }

    async function sha256(msg) {
      // crypto.subtle requires a secure context (HTTPS/localhost).
      // Fall back to a pure-JS SHA-256 so the app works over plain HTTP too.
      if (typeof crypto !== 'undefined' && crypto.subtle) {
        const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(msg));
        return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
      }
      return sha256Fallback(msg);
    }

    // Pure-JS SHA-256 (RFC 6234 / FIPS 180-4) — used when crypto.subtle is unavailable.
    function sha256Fallback(msg) {
      const K = [
        0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
        0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
        0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
        0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
        0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
        0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
        0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
        0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2,
      ];
      const bytes = new TextEncoder().encode(msg);
      const bits  = bytes.length * 8;
      const padLen = ((bytes.length % 64) < 56 ? 56 : 120) - (bytes.length % 64);
      const buf    = new Uint8Array(bytes.length + padLen + 8);
      buf.set(bytes);
      buf[bytes.length] = 0x80;
      const dv = new DataView(buf.buffer);
      dv.setUint32(buf.length - 4, bits >>> 0,  false);
      dv.setUint32(buf.length - 8, Math.floor(bits / 2**32), false);

      let [h0,h1,h2,h3,h4,h5,h6,h7] =
        [0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];

      const rotr = (x, n) => (x >>> n) | (x << (32 - n));
      for (let i = 0; i < buf.length; i += 64) {
        const w = new Uint32Array(64);
        for (let j = 0; j < 16; j++) w[j] = dv.getUint32(i + j * 4, false);
        for (let j = 16; j < 64; j++) {
          const s0 = rotr(w[j-15],7)  ^ rotr(w[j-15],18) ^ (w[j-15] >>> 3);
          const s1 = rotr(w[j-2], 17) ^ rotr(w[j-2], 19) ^ (w[j-2]  >>> 10);
          w[j] = (w[j-16] + s0 + w[j-7] + s1) >>> 0;
        }
        let [a,b,c,d,e,f,g,h] = [h0,h1,h2,h3,h4,h5,h6,h7];
        for (let j = 0; j < 64; j++) {
          const S1  = rotr(e,6) ^ rotr(e,11) ^ rotr(e,25);
          const ch  = (e & f) ^ (~e & g);
          const t1  = (h + S1 + ch + K[j] + w[j]) >>> 0;
          const S0  = rotr(a,2) ^ rotr(a,13) ^ rotr(a,22);
          const maj = (a & b) ^ (a & c) ^ (b & c);
          const t2  = (S0 + maj) >>> 0;
          [h,g,f,e,d,c,b,a] = [g,f,e,(d+t1)>>>0,c,b,a,(t1+t2)>>>0];
        }
        h0=(h0+a)>>>0; h1=(h1+b)>>>0; h2=(h2+c)>>>0; h3=(h3+d)>>>0;
        h4=(h4+e)>>>0; h5=(h5+f)>>>0; h6=(h6+g)>>>0; h7=(h7+h)>>>0;
      }
      return [h0,h1,h2,h3,h4,h5,h6,h7].map(n => n.toString(16).padStart(8,'0')).join('');
    }

    async function apiFetch(url, opts = {}) {
      opts.headers = {
        ...(opts.headers || {}),
        'Authorization': 'Bearer ' + getToken()
      };
      const res = await fetch(url, opts);
      if (res.status === 401) {
        logout();
        return res;
      }
      return res;
    }

    function logout() {
      sessionStorage.clear();
      showLogin();
    }

    function showLogin() {
      document.getElementById('login-screen').style.display = 'flex';
      document.getElementById('wiki-screen').style.display = 'none';
      document.getElementById('login-error').textContent = '';
      document.getElementById('login-pass').value = '';
    }

    function showWiki() {
      document.getElementById('login-screen').style.display = 'none';
      document.getElementById('wiki-screen').style.display = '';
      document.getElementById('user-badge').textContent = getUser();
      const isAdmin = getRole() === 'admin';
      const isGuest = getRole() === 'guest';
      document.getElementById('toc-btn').style.display = isGuest ? 'none' : '';
      document.getElementById('edit-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('index-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('users-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('backup-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('restore-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('settings-btn').style.display = isAdmin ? '' : 'none';
      if (isGuest) {
        document.getElementById('wiki-screen').classList.add('guest-mode');
        document.getElementById('home-btn').style.display = '';
        document.getElementById('top-btn').style.display = '';
      }
    }

    // ── Login form ──────────────────────────────────────────────────────────────
    document.getElementById('login-form').addEventListener('submit', async e => {
      e.preventDefault();
      const user = document.getElementById('login-user').value.trim();
      const pass = document.getElementById('login-pass').value;
      const errEl = document.getElementById('login-error');
      errEl.textContent = '';
      if (!user || !pass) {
        errEl.textContent = 'Please fill in all fields';
        return;
      }

      const hash = await sha256(pass);
      try {
        const res = await fetch('?action=login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            user,
            hash
          })
        });
        const data = await res.json();
        if (res.ok) {
          sessionStorage.setItem('wkw_token', data.token);
          sessionStorage.setItem('wkw_role', data.role);
          sessionStorage.setItem('wkw_user', user);
          showWiki();
          route();
        } else {
          errEl.textContent = data.error || 'Authentication error';
        }
      } catch {
        errEl.textContent = 'Connection error';
      }
    });

    // ── Wiki ────────────────────────────────────────────────────────────────────
    let currentPage = 'index';
    let rawMd = '';
    let editing = false;
    let isNewPage = false;

    async function load(page) {
      currentPage = page;
      editing = false;
      closeToc();
      document.getElementById('editor').style.display = 'none';
      document.getElementById('content').style.display = '';
      document.getElementById('edit-btn').innerHTML = ICON_EDIT;
      document.getElementById('edit-btn').title = 'Edit';
      document.getElementById('save-status').textContent = '';

      const res = await apiFetch('?page=' + encodeURIComponent(page));
      if (!res) return;
      rawMd = await res.text();
      const isAdmin = getRole() === 'admin';
      if (res.status === 404 && isAdmin) {
        document.getElementById('delete-btn').style.display = 'none';
        document.getElementById('odt-btn').style.display = 'none';
        document.getElementById('content').innerHTML =
          '<p style="color:#888;margin-bottom:.75rem">This page does not exist yet.</p>' +
          '<button class="btn btn-primary" title="Create page" aria-label="Create page" onclick="createPage()">' + ICON_PLUS + '</button>';
      } else {
        document.getElementById('delete-btn').style.display = isAdmin ? '' : 'none';
        document.getElementById('odt-btn').style.display = '';
        document.getElementById('content').innerHTML = parseWiki(rawMd);
        addHeadingIds();
        buildInlineToc();
      }

      const parts = page === 'index' ? [] : page.split('/');
      let nav = '<a href="" onclick="navigate(\'index\');return false;">Home</a>';
      parts.forEach((p, i) => {
        const t = parts.slice(0, i + 1).join('/');
        nav += ' &rsaquo; <a href="' + BASE + t + '" onclick="navigate(\'' + t + '\');return false;">' + p + '</a>';
      });
      document.getElementById('nav').innerHTML = nav;

      function resolvePath(base, rel) {
        const parts = (base + rel).split('/');
        const out = [];
        for (const p of parts) {
          if (p === '..') out.pop();
          else if (p !== '.') out.push(p);
        }
        return out.join('/');
      }

      document.querySelectorAll('#content a[href]').forEach(a => {
        const h = a.getAttribute('href');
        if (h && !h.startsWith('http') && !h.startsWith('#') && !h.startsWith('mailto:')) {
          const base = page.includes('/') ? page.slice(0, page.lastIndexOf('/') + 1) : '';
          const target = resolvePath(base, h);
          a.href = BASE + target;
          a.addEventListener('click', e => {
            e.preventDefault();
            navigate(target);
          });
        }
      });

      const h1 = document.querySelector('#content h1');
      document.title = (h1 ? h1.textContent : page) + ' — Wiki';
      window.scrollTo(0, 0);
    }

    function createPage() {
      rawMd = '';
      isNewPage = true;
      openEdit();
    }

    // ── TOC panel ────────────────────────────────────────────────────────────────
    function slugify(text) {
      return text.toLowerCase().replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-') || 'heading';
    }

    function addHeadingIds() {
      const seen = {};
      document.querySelectorAll('#content h1, #content h2, #content h3').forEach(h => {
        let slug = slugify(h.textContent);
        if (seen[slug]) {
          seen[slug]++;
          slug += '-' + seen[slug];
        } else {
          seen[slug] = 1;
        }
        h.id = slug;
      });
    }

    function buildInlineToc() {
      const existing = document.getElementById('toc-inline');
      if (existing) existing.remove();
      if (getRole() !== 'guest') return;
      const headings = document.querySelectorAll('#content h1, #content h2, #content h3');
      if (headings.length < 2) return;
      let html = '<ul>';
      headings.forEach(h => {
        const cls = 'toc-' + h.tagName.toLowerCase();
        const id = h.id;
        html += '<li><a class="' + cls + '" href="#' + id + '" onclick="document.getElementById(\'' + id + '\').scrollIntoView({behavior:\'smooth\'});return false;">' + h.textContent + '</a></li>';
      });
      html += '</ul>';
      const div = document.createElement('div');
      div.id = 'toc-inline';
      const content = document.getElementById('content');
      div.innerHTML = html;
      content.insertBefore(div, content.firstChild);
    }

    let tocOpen = false;

    function closeToc() {
      if (!tocOpen) return;
      tocOpen = false;
      document.getElementById('toc-overlay').style.display = 'none';
      document.getElementById('toc-panel').style.display = 'none';
    }

    function toggleToc() {
      const overlay = document.getElementById('toc-overlay');
      const panel = document.getElementById('toc-panel');
      tocOpen = !tocOpen;
      overlay.style.display = panel.style.display = tocOpen ? 'block' : 'none';
      if (!tocOpen) return;

      const headings = document.querySelectorAll('#content h1, #content h2, #content h3');
      if (!headings.length) {
        document.getElementById('toc-list').innerHTML = '<p style="color:#888;font-size:.85rem;padding:.25rem 0">No headings on this page.</p>';
        return;
      }
      let html = '<ul>';
      headings.forEach(h => {
        const cls = 'toc-' + h.tagName.toLowerCase();
        const id = h.id;
        html += '<li><a class="' + cls + '" href="#' + id + '" onclick="document.getElementById(\'' + id + '\').scrollIntoView({behavior:\'smooth\'});closeToc();return false;">' + h.textContent + '</a></li>';
      });
      html += '</ul>';
      document.getElementById('toc-list').innerHTML = html;
    }

    // ── Page index panel ────────────────────────────────────────────────────────
    let indexOpen = false;
    async function toggleIndex() {
      const overlay = document.getElementById('index-overlay');
      const panel = document.getElementById('index-panel');
      indexOpen = !indexOpen;
      overlay.style.display = panel.style.display = indexOpen ? 'block' : 'none';
      if (!indexOpen) return;

      const res = await apiFetch(BASE + '?action=index');
      if (!res || !res.ok) return;
      const data = await res.json();

      // Build tree from flat list
      const tree = {};
      for (const page of data.pages) {
        const parts = page.split('/');
        let node = tree;
        for (const p of parts) {
          node[p] = node[p] || {};
          node = node[p];
        }
      }

      function renderTree(node, prefix) {
        const keys = Object.keys(node).sort();
        if (!keys.length) return '';
        let html = '<ul>';
        for (const key of keys) {
          const path = prefix ? prefix + '/' + key : key;
          const hasChildren = Object.keys(node[key]).length > 0;
          html += '<li>';
          html += '<a href="' + BASE + (path === 'index' ? '' : path) + '" onclick="navigate(\'' + path + '\');toggleIndex();return false;">' + key + '</a>';
          if (hasChildren) html += renderTree(node[key], path);
          html += '</li>';
        }
        html += '</ul>';
        return html;
      }

      document.getElementById('index-tree').innerHTML = renderTree(tree, '');
    }

    async function deletePage() {
      if (!confirm('Delete page "' + currentPage + '"? This action cannot be undone.')) return;
      const res = await apiFetch('?action=delete', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          page: currentPage
        })
      });
      if (res && res.ok) {
        showToast('Page \u201c' + currentPage + '\u201d deleted.');
        navigate('index', true);
      } else {
        const data = await res.json().catch(() => ({}));
        showToast(data.error || 'Error deleting page', 'error');
      }
    }

    function toggleEdit() {
      editing ? cancelEdit() : openEdit();
    }

    function openEdit() {
      editing = true;
      document.getElementById('editor-area').value = rawMd;
      document.getElementById('content').style.display = 'none';
      document.getElementById('editor').style.display = 'flex';
      document.getElementById('edit-btn').innerHTML = ICON_VIEW;
      document.getElementById('edit-btn').title = 'View';
      document.getElementById('editor-area').focus();
    }

    function cancelEdit() {
      editing = false;
      isNewPage = false;
      document.getElementById('editor').style.display = 'none';
      document.getElementById('content').style.display = '';
      document.getElementById('edit-btn').innerHTML = ICON_EDIT;
      document.getElementById('edit-btn').title = 'Edit';
    }

    async function save() {
      const status = document.getElementById('save-status');
      const content = document.getElementById('editor-area').value;
      status.textContent = 'Saving…';
      const res = await apiFetch('?action=save', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          page: currentPage,
          content
        })
      });
      if (res && res.ok) {
        const wasNew = isNewPage;
        rawMd = content;
        status.textContent = '';
        cancelEdit();
        document.getElementById('content').innerHTML = parseWiki(rawMd);
        addHeadingIds();
        buildInlineToc();
        if (getRole() === 'admin') document.getElementById('delete-btn').style.display = '';
        document.getElementById('odt-btn').style.display = '';
        showToast(wasNew ? 'Page \u201c' + currentPage + '\u201d created.' : 'Page \u201c' + currentPage + '\u201d saved.');
      } else {
        const data = await res.json().catch(() => ({}));
        status.textContent = '';
        showToast(data.error || 'Could not save the page. Please try again.', 'error');
      }
    }

    document.addEventListener('keydown', e => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's' && editing) {
        e.preventDefault();
        save();
      }
      if (e.key === 'Escape' && editing) {
        cancelEdit();
      }
    });

    // ── Router ──────────────────────────────────────────────────────────────────
    function route() {
      if (!getToken()) {
        showLogin();
        return;
      }
      showWiki();
      load(getPage());
    }
    window.addEventListener('popstate', route);
    route();
