/**
 * xlsxReport.js — generator file .xlsx minimal TANPA dependency (zero npm package).
 *
 * Dipakai halaman "Input Realisasi KPI" untuk fitur "Export Excel": file
 * benar-benar .xlsx (OOXML = ZIP berisi XML), dibuka Excel/LibreOffice tanpa
 * peringatan, kolom rapi + header bold berwarna + warna status per baris.
 *
 * Isi paket (minimum yang dibutuhkan Excel):
 *   [Content_Types].xml, _rels/.rels, xl/workbook.xml,
 *   xl/_rels/workbook.xml.rels, xl/styles.xml, xl/worksheets/sheet1.xml
 *
 * ZIP ditulis manual dengan metode STORE (tanpa kompresi) + CRC32 — tetap ZIP
 * valid per spec (method 0), jadi tidak perlu lib deflate.
 *
 * Pemakaian:
 *   import {downloadXlsx, XLSX_STYLE, statusStyleForColor} from '../utils/xlsxReport';
 *   downloadXlsx({
 *     fileName: 'Report.xlsx',
 *     sheetName: 'Sheet1',
 *     columns: [{title: 'Indikator', width: 38}],
 *     rows: [[{v: 'Judul', s: XLSX_STYLE.TITLE}], [{v: 'Header', s: XLSX_STYLE.HEADER}]],
 *   });
 *
 * Sel boleh: (a) nilai primitif (string/number, gaya default) atau
 * (b) objek {v, s} dengan s = indeks gaya dari XLSX_STYLE.
 * Baris "header" dikenali otomatis (sel pertama bergaya HEADER) untuk
 * freeze-pane + AutoFilter.
 */

export const XLSX_STYLE = {
  DEFAULT: 0,
  TITLE: 1,
  META: 2,
  HEADER: 3,
  CELL: 4,
  NUM: 5,
  PCT: 6,
  TOTAL_LABEL: 7,
  TOTAL_NUM: 8,
  TOTAL_PCT: 9,
  STATUS_BLUE: 10,
  STATUS_GREEN: 11,
  STATUS_YELLOW: 12,
  STATUS_RED: 13,
  STATUS_GREY: 14,
  CENTER: 15,
};

/** Kelas warna status (status-blue/status-green/... dari Vue) -> gaya sel. */
export const statusStyleForColor = (colorClass) => {
  const map = {
    'status-blue': XLSX_STYLE.STATUS_BLUE,
    'status-green': XLSX_STYLE.STATUS_GREEN,
    'status-yellow': XLSX_STYLE.STATUS_YELLOW,
    'status-red': XLSX_STYLE.STATUS_RED,
    'status-grey': XLSX_STYLE.STATUS_GREY,
  };
  return map[colorClass] || XLSX_STYLE.STATUS_GREY;
};

const XLSX_MIME =
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

// --- util XML ---------------------------------------------------------------

const escapeXml = (s) =>
  String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');

/** 0 -> A, 25 -> Z, 26 -> AA */
const colName = (idx) => {
  let i = idx + 1;
  let s = '';
  while (i > 0) {
    const m = (i - 1) % 26;
    s = String.fromCharCode(65 + m) + s;
    i = Math.floor((i - 1) / 26);
  }
  return s;
};

const sheetNameSafe = (name) =>
  String(name || 'Sheet1')
    .replace(/[[\]:*?/\\]/g, '_')
    .slice(0, 31) || 'Sheet1';

// --- XML statis -------------------------------------------------------------

const CONTENT_TYPES = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>`;

const ROOT_RELS = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>`;

const WORKBOOK_RELS = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`;

// Gaya sel: 16 cellXfs (lihat XLSX_STYLE). Font 0 sengaja tanpa color supaya
// tidak butuh part theme (paket minimal tetap valid).
const STYLES_XML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="0.0&quot;%&quot;"/></numFmts><fonts count="10"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF1F4E79"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF303030"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF1D4ED8"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF15803D"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFA16207"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFB91C1C"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF64748B"/><name val="Calibri"/></font></fonts><fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEFF3F8"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFEF9C3"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFEF2F2"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right><top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="16"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="164" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="5" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="6" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="7" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="8" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="9" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>`;

// --- sheet XML --------------------------------------------------------------

const cellXml = (ref, cell) => {
  if (cell === null || cell === undefined) return '';
  const obj = typeof cell === 'object' ? cell : {v: cell};
  const style = obj.s ? ` s="${obj.s}"` : '';
  const v = obj.v;
  if (v === null || v === undefined || v === '') {
    // Sel bergaya tetap ditulis walau kosong (biar pita abu baris TOTAL utuh).
    return obj.s ? `<c r="${ref}"${style}/>` : '';
  }
  if (typeof v === 'number' && isFinite(v)) {
    return `<c r="${ref}"${style}><v>${v}</v></c>`;
  }
  return `<c r="${ref}"${style} t="inlineStr"><is><t xml:space="preserve">${escapeXml(
    v,
  )}</t></is></c>`;
};

const sheetXml = ({columns, rows}) => {
  const width = Math.max(columns.length, 1);
  const cols = columns.length
    ? `<cols>${columns
        .map(
          (c, i) =>
            `<col min="${i + 1}" max="${i + 1}" width="${
              c.width || 14
            }" customWidth="1"/>`,
        )
        .join('')}</cols>`
    : '';
  const headerIdx = rows.findIndex(
    (r) =>
      Array.isArray(r) &&
      r.some((c) => c && typeof c === 'object' && c.s === XLSX_STYLE.HEADER),
  );
  const freezeAt = headerIdx >= 0 ? headerIdx + 1 : 0;
  const sheetView = freezeAt
    ? `<sheetViews><sheetView workbookViewId="0"><pane ySplit="${freezeAt}" topLeftCell="A${
        freezeAt + 1
      }" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A${
        freezeAt + 1
      }" sqref="A${freezeAt + 1}"/></sheetView></sheetViews>`
    : '';
  const autoFilter =
    headerIdx >= 0 && rows.length > headerIdx + 1
      ? `<autoFilter ref="A${headerIdx + 1}:${colName(width - 1)}${
          rows.length
        }"/>`
      : '';
  const body = rows
    .map((r, ri) => {
      const cells = (r || [])
        .slice(0, width)
        .map((c, ci) => cellXml(`${colName(ci)}${ri + 1}`, c))
        .join('');
      const ht = ri === headerIdx ? ' ht="30" customHeight="1"' : '';
      return `<row r="${ri + 1}"${ht}>${cells}</row>`;
    })
    .join('');
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">${sheetView}<sheetFormatPr defaultRowHeight="15"/>${cols}<sheetData>${body}</sheetData>${autoFilter}<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>`;
};

const workbookXml = (
  sheetName,
) => `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="${escapeXml(
  sheetNameSafe(sheetName),
)}" sheetId="1" r:id="rId1"/></sheets></workbook>`;

// --- ZIP (store, no compression) + CRC32 ------------------------------------

const CRC_TABLE = (() => {
  const t = new Uint32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c >>> 0;
  }
  return t;
})();

const crc32 = (bytes) => {
  let c = 0xffffffff;
  for (let i = 0; i < bytes.length; i++) {
    c = CRC_TABLE[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
  }
  return (c ^ 0xffffffff) >>> 0;
};

const dosDateTime = (d = new Date()) => ({
  time:
    ((d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1)) &
    0xffff,
  date:
    (((d.getFullYear() - 1980) << 9) |
      ((d.getMonth() + 1) << 5) |
      d.getDate()) &
    0xffff,
});

/** files: [{name, data: string|Uint8Array}] -> Blob (application/zip). */
const zipStore = (files) => {
  const enc = new TextEncoder();
  const {time, date} = dosDateTime();
  const parts = [];
  const central = [];
  let offset = 0;
  for (const f of files) {
    const nameBytes = enc.encode(f.name);
    const data = typeof f.data === 'string' ? enc.encode(f.data) : f.data;
    const crc = crc32(data);
    const local = new Uint8Array(30 + nameBytes.length);
    const dv = new DataView(local.buffer);
    dv.setUint32(0, 0x04034b50, true);
    dv.setUint16(4, 20, true);
    dv.setUint16(6, 0x0800, true);
    dv.setUint16(8, 0, true);
    dv.setUint16(10, time, true);
    dv.setUint16(12, date, true);
    dv.setUint32(14, crc, true);
    dv.setUint32(18, data.length, true);
    dv.setUint32(22, data.length, true);
    dv.setUint16(26, nameBytes.length, true);
    dv.setUint16(28, 0, true);
    local.set(nameBytes, 30);
    parts.push(local, data);
    central.push({nameBytes, crc, size: data.length, offset});
    offset += local.length + data.length;
  }
  const cd = [];
  let cdSize = 0;
  for (const e of central) {
    const buf = new Uint8Array(46 + e.nameBytes.length);
    const dv = new DataView(buf.buffer);
    dv.setUint32(0, 0x02014b50, true);
    dv.setUint16(4, 20, true);
    dv.setUint16(6, 20, true);
    dv.setUint16(8, 0x0800, true);
    dv.setUint16(10, 0, true);
    dv.setUint16(12, time, true);
    dv.setUint16(14, date, true);
    dv.setUint32(16, e.crc, true);
    dv.setUint32(20, e.size, true);
    dv.setUint32(24, e.size, true);
    dv.setUint16(28, e.nameBytes.length, true);
    dv.setUint16(30, 0, true);
    dv.setUint16(32, 0, true);
    dv.setUint16(34, 0, true);
    dv.setUint16(36, 0, true);
    dv.setUint32(38, 0, true);
    dv.setUint32(42, e.offset, true);
    buf.set(e.nameBytes, 46);
    cd.push(buf);
    cdSize += buf.length;
  }
  const eocd = new Uint8Array(22);
  const dv2 = new DataView(eocd.buffer);
  dv2.setUint32(0, 0x06054b50, true);
  dv2.setUint16(4, 0, true);
  dv2.setUint16(6, 0, true);
  dv2.setUint16(8, files.length, true);
  dv2.setUint16(10, files.length, true);
  dv2.setUint32(12, cdSize, true);
  dv2.setUint32(16, offset, true);
  dv2.setUint16(20, 0, true);
  return new Blob([...parts, ...cd, eocd], {type: 'application/zip'});
};

/** Bangun .xlsx -> Blob dengan mime spreadsheetml. */
export const xlsxBlob = ({sheetName = 'Sheet1', columns = [], rows = []}) =>
  new Blob(
    [
      zipStore([
        {name: '[Content_Types].xml', data: CONTENT_TYPES},
        {name: '_rels/.rels', data: ROOT_RELS},
        {name: 'xl/workbook.xml', data: workbookXml(sheetName)},
        {name: 'xl/_rels/workbook.xml.rels', data: WORKBOOK_RELS},
        {name: 'xl/styles.xml', data: STYLES_XML},
        {
          name: 'xl/worksheets/sheet1.xml',
          data: sheetXml({sheetName, columns, rows}),
        },
      ]),
    ],
    {type: XLSX_MIME},
  );

/** Generate + auto-download (tanpa dialog print). */
export const downloadXlsx = ({fileName, sheetName, columns, rows}) => {
  const blob = xlsxBlob({sheetName, columns, rows});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = fileName || 'report.xlsx';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(() => URL.revokeObjectURL(url), 1000);
};
