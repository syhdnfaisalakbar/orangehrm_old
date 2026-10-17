/**
 * kpiReportPdf.js — generator PDF minimal TANPA dependency (zero npm package).
 *
 * Dipakai halaman Division Detail untuk fitur "Export PDF": menghasilkan file
 * .pdf yang langsung ter-download (tanpa dialog print browser).
 *
 * Ruang lingkup sengaja sempit: laporan TABEL berkelompok, A4 landscape,
 * font standar PDF (Helvetica / Helvetica-Bold) sehingga tidak perlu
 * embed font. Isi file dibangun sebagai string ASCII murni supaya panjang
 * string == panjang byte (penting untuk tabel xref PDF).
 *
 * Struktur file PDF:Catalog → Pages → [Page + Contents] + Font.
 * Content stream TIDAK dikompres (biar mudah diverifikasi & dibaca).
 */

const PAGE = {width: 842, height: 595}; // A4 landscape (pt)
const MARGIN = {top: 28, right: 24, bottom: 34, left: 24};
const CELL_FONT_SIZE = 8.5;
const HEAD_FONT_SIZE = 8.5;
const TITLE_FONT_SIZE = 14;
const META_FONT_SIZE = 9;
const ROW_H = 14;
const ROW_H_2LINE = 22;

// Lebar karakter Helvetica (satuan 1/1000 em) — nilai AFM standar untuk ASCII.
// Dipakai hanya untuk estimasi lebar teks (wrap/truncate/align), bukan render.
const HELVETICA_WIDTHS = {
  ' ': 278,
  '!': 278,
  '"': 355,
  '#': 556,
  $: 556,
  '%': 889,
  '&': 667,
  "'": 191,
  '(': 333,
  ')': 333,
  '*': 389,
  '+': 584,
  ',': 278,
  '-': 333,
  '.': 278,
  '/': 278,
  ':': 278,
  ';': 278,
  '<': 584,
  '=': 584,
  '>': 584,
  '?': 556,
  '@': 1015,
  '[': 278,
  '\\': 278,
  ']': 278,
  '^': 469,
  _: 556,
  '`': 333,
  '{': 334,
  '|': 260,
  '}': 334,
  '~': 584,
  A: 667,
  B: 667,
  C: 722,
  D: 722,
  E: 667,
  F: 611,
  G: 778,
  H: 722,
  I: 278,
  J: 500,
  K: 667,
  L: 556,
  M: 833,
  N: 722,
  O: 778,
  P: 667,
  Q: 778,
  R: 722,
  S: 667,
  T: 611,
  U: 722,
  V: 667,
  W: 944,
  X: 667,
  Y: 667,
  Z: 611,
  a: 556,
  b: 556,
  c: 500,
  d: 556,
  e: 556,
  f: 278,
  g: 556,
  h: 556,
  i: 222,
  j: 222,
  k: 500,
  l: 222,
  m: 833,
  n: 556,
  o: 556,
  p: 556,
  q: 556,
  r: 333,
  s: 500,
  t: 278,
  u: 556,
  v: 500,
  w: 722,
  x: 500,
  y: 500,
  z: 500,
};
for (let d = 0; d <= 9; d += 1) {
  HELVETICA_WIDTHS[String(d)] = 556;
}

/**
 * Transliterasi karakter non-ASCII ke padanan ASCII supaya file tetap
 * byte-safe dengan font standar (WinAnsi) tanpa embed font.
 */
export function toAscii(value) {
  const str = value === null || value === undefined ? '' : String(value);
  return (
    str
      .replace(/[\u2018\u2019\u201B\u2032]/g, "'")
      .replace(/[\u201C\u201D\u2033]/g, '"')
      .replace(/[\u2013\u2014\u2212]/g, '-')
      .replace(/[\u2022\u00B7]/g, '-')
      .replace(/\u2026/g, '...')
      .replace(/\u00A0/g, ' ')
      .replace(/[\u2265]/g, '>=')
      .replace(/[\u2264]/g, '<=')
      // sisa karakter di luar ASCII printable -> '?'
      .replace(/[^\x20-\x7E]/g, '?')
  );
}

function textWidth(text, size, bold) {
  const clean = toAscii(text);
  let total = 0;
  for (let i = 0; i < clean.length; i += 1) {
    const w = HELVETICA_WIDTHS[clean[i]];
    total += w === undefined ? 556 : w;
  }
  const widthPt = (total / 1000) * size;
  // Font bold sedikit lebih lebar — dilebihkan supaya teks tidak melewati kolom.
  return bold ? widthPt * 1.06 : widthPt;
}

function ellipsize(text, size, maxWidth, bold) {
  const clean = toAscii(text);
  if (textWidth(clean, size, bold) <= maxWidth) return clean;
  const ell = '...';
  let out = '';
  for (let i = 0; i < clean.length; i += 1) {
    const next = out + clean[i];
    if (textWidth(next + ell, size, bold) > maxWidth) break;
    out = next;
  }
  return out.trimEnd() + ell;
}

/** Bungkus teks jadi maksimal `maxLines` baris di dalam lebar kolom. */
function wrapText(text, size, maxWidth, maxLines) {
  const clean = toAscii(text).replace(/\s+/g, ' ').trim();
  if (!clean) return [''];
  const words = clean.split(' ');
  const lines = [];
  let current = '';
  let leftover = false;
  for (const word of words) {
    const candidate = current ? current + ' ' + word : word;
    if (textWidth(candidate, size, false) <= maxWidth) {
      current = candidate;
      continue;
    }
    if (current) lines.push(current);
    if (lines.length === maxLines) {
      leftover = true;
      current = '';
      break;
    }
    current = word;
  }
  if (!leftover && current && lines.length < maxLines) lines.push(current);

  // Baris yang tetap terlalu lebar (mis. satu kata panjang) -> potong pakai ellipsis.
  const clipped = lines.slice(0, maxLines).map((line) => {
    if (textWidth(line, size, false) <= maxWidth) return line;
    let out = '';
    for (let i = 0; i < line.length; i += 1) {
      const next = out + line[i];
      if (textWidth(next + '...', size, false) > maxWidth) break;
      out = next;
    }
    return out + '...';
  });

  // Masih ada kata yang tak tertampung -> tandai ellipsis di baris terakhir.
  if (leftover && clipped.length) {
    clipped[clipped.length - 1] = ellipsize(
      clipped[clipped.length - 1] + '...',
      size,
      maxWidth,
      false,
    );
  }
  return clipped;
}

function pdfString(text) {
  return toAscii(text)
    .replace(/\\/g, '\\\\')
    .replace(/\(/g, '\\(')
    .replace(/\)/g, '\\)');
}

function num(value) {
  return Number(value.toFixed(2)).toString();
}

/**
 * Builder konten (content stream) satu halaman.
 */
function createPageWriter(width, height) {
  const ops = [];
  return {
    ops,
    text(x, y, text, opts = {}) {
      const size = opts.size || CELL_FONT_SIZE;
      const bold = !!opts.bold;
      const font = bold ? '/F2' : '/F1';
      const value = pdfString(text);
      if (value === '') return;
      let posX = x;
      if (opts.align === 'center') {
        posX = x + ((opts.width || 0) - textWidth(text, size, bold)) / 2;
      } else if (opts.align === 'right') {
        posX = x + (opts.width || 0) - textWidth(text, size, bold);
      }
      ops.push(
        `BT ${font} ${num(size)} Tf ${num(posX)} ${num(
          height - y,
        )} Td (${value}) Tj ET`,
      );
    },
    rect(x, y, w, h, fill) {
      ops.push(
        `q ${num(fill[0])} ${num(fill[1])} ${num(fill[2])} rg ${num(x)} ${num(
          height - y - h,
        )} ${num(w)} ${num(h)} re f Q`,
      );
    },
    line(x1, y1, x2, y2, gray = 0.65, lineWidth = 0.5) {
      ops.push(
        `q ${num(gray)} G ${num(lineWidth)} w ${num(x1)} ${num(
          height - y1,
        )} m ${num(x2)} ${num(height - y2)} l S Q`,
      );
    },
  };
}

/**
 * Bangun file PDF laporan tabel berkelompok.
 *
 * @param {Object} options
 * @param {string} options.title            Judul laporan (halaman pertama)
 * @param {string[]} [options.info]         Baris info filter (mis. "Divisi: IT")
 * @param {string} [options.note]           Catatan kecil di bawah judul
 * @param {Array} options.columns           [{label, width, align}]
 * @param {Array} options.groups            [{label, rows: string[][], total?: string[]}]
 * @param {string} [options.footer]         Teks footer kiri tiap halaman
 * @returns {Uint8Array} isi file PDF
 */
export function buildGroupedTablePdf(options) {
  const {
    title = 'Laporan',
    info = [],
    note = '',
    columns = [],
    groups = [],
    footer = '',
  } = options || {};

  const tableWidth = columns.reduce((sum, col) => sum + col.width, 0);
  const left = MARGIN.left;
  const right = Math.min(left + tableWidth, PAGE.width - MARGIN.right);
  const colX = [];
  let cursor = left;
  for (const col of columns) {
    colX.push(cursor);
    cursor += col.width;
  }

  const pages = [];
  let writer = createPageWriter(PAGE.width, PAGE.height);
  let y = MARGIN.top;
  let pageIndex = 0;

  // SETIAP halaman WAJIB memakai writer BARU. Bug lama (2026-10-09): startPage()
  // push objek `writer` yang SAMA → semua halaman berisi SELURUH laporan (stream
  // content identik di tiap halaman), baris yang panjang keluar lewat batas,
  // dan footer nomor halaman menumpuk → output "berantakan".
  const startPage = () => {
    writer = createPageWriter(PAGE.width, PAGE.height);
    pages.push(writer);
    pageIndex += 1;
    y = MARGIN.top;
  };

  const drawTableHead = () => {
    writer.rect(left, y, right - left, ROW_H, [0.945, 0.953, 0.961]);
    columns.forEach((col, i) => {
      if (col.align === 'right') {
        writer.text(colX[i], y + 9.5, col.label, {
          bold: true,
          size: HEAD_FONT_SIZE,
          align: 'right',
          width: col.width - 8,
        });
      } else {
        writer.text(colX[i] + 4, y + 9.5, col.label, {
          bold: true,
          size: HEAD_FONT_SIZE,
        });
      }
    });
    writer.line(left, y + ROW_H, right, y + ROW_H, 0.55, 0.6);
    y += ROW_H;
  };

  const drawGroupLabel = (label) => {
    writer.rect(left, y, right - left, ROW_H, [0.972, 0.98, 0.988]);
    writer.text(left + 4, y + 9.5, label, {bold: true, size: CELL_FONT_SIZE});
    writer.line(left, y + ROW_H, right, y + ROW_H, 0.75, 0.4);
    y += ROW_H;
  };

  const drawRow = (cells, opts = {}) => {
    const bold = !!opts.bold;
    // Baris dengan kolom pertama panjang -> 2 baris teks.
    const firstWidth = columns[0].width - 8;
    const lines =
      opts.wrap === false
        ? [ellipsize(cells[0], CELL_FONT_SIZE, firstWidth, bold)]
        : wrapText(cells[0], CELL_FONT_SIZE, firstWidth, 2);
    const rowHeight = lines.length > 1 ? ROW_H_2LINE : ROW_H;

    if (y + rowHeight > PAGE.height - MARGIN.bottom) {
      writer.line(left, y, right, y, 0.55, 0.6);
      startPage();
      drawTableHead();
    }

    lines.forEach((line, i) => {
      writer.text(colX[0] + 4, y + 9.5 + i * 10, line, {
        bold,
        size: CELL_FONT_SIZE,
      });
    });
    for (let c = 1; c < columns.length; c += 1) {
      writer.text(colX[c] + 4, y + 9.5, cells[c], {
        bold,
        size: CELL_FONT_SIZE,
        align: columns[c].align === 'right' ? 'right' : 'left',
        width: columns[c].width - 8,
      });
    }
    y += rowHeight;
    writer.line(left, y, right, y, 0.8, 0.4);
  };

  // ---------- halaman pertama: judul + info ----------
  startPage();
  writer.text(left, y + 12, title, {bold: true, size: TITLE_FONT_SIZE});
  y += 20;
  info.forEach((line) => {
    writer.text(left, y + 9, line, {size: META_FONT_SIZE});
    y += 13;
  });
  if (note) {
    writer.text(left, y + 9, note, {size: META_FONT_SIZE});
    y += 13;
  }
  y += 6;
  drawTableHead();

  // ---------- baris data ----------
  groups.forEach((group) => {
    if (group.label) {
      if (y + ROW_H * 2 > PAGE.height - MARGIN.bottom) {
        startPage();
        drawTableHead();
      }
      drawGroupLabel(group.label);
    }
    (group.rows || []).forEach((cells) => drawRow(cells));
    if (group.total) drawRow(group.total, {bold: true, wrap: false});
  });

  writer.line(left, y, right, y, 0.55, 0.6);

  // ---------- footer tiap halaman (nomor halaman) ----------
  const totalPages = pages.length;
  pages.forEach((pageWriter, i) => {
    const footY = PAGE.height - MARGIN.bottom + 16;
    if (footer) {
      pageWriter.text(left, footY, footer, {
        size: 7.5,
        align: 'left',
      });
    }
    pageWriter.text(left, footY, `Halaman ${i + 1} dari ${totalPages}`, {
      size: 7.5,
      align: 'right',
      width: right - left,
    });
  });

  // ---------- serialisasi PDF ----------
  const objects = []; // index 0 -> obj 1
  const addObject = (body) => {
    objects.push(body);
    return objects.length;
  };

  const fontRegular = addObject(
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
  );
  const fontBold = addObject(
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
  );

  const pageObjectNumbers = [];
  const contentObjectNumbers = [];
  pages.forEach((pageWriter) => {
    const stream = pageWriter.ops.join('\n');
    const contentNumber = addObject(
      `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
    );
    contentObjectNumbers.push(contentNumber);
    pageObjectNumbers.push(0); // placeholder, diisi setelah kita tahu nomor objek page
  });

  const resources = `<< /Font << /F1 ${fontRegular} 0 R /F2 ${fontBold} 0 R >> >>`;
  const pageNumbers = [];
  pages.forEach((_pageWriter, i) => {
    const pageNumber = addObject(
      `<< /Type /Page /Parent PAGES_REF /MediaBox [0 0 ${PAGE.width} ${PAGE.height}] /Resources ${resources} /Contents ${contentObjectNumbers[i]} 0 R >>`,
    );
    pageNumbers.push(pageNumber);
  });

  const pagesNumber = addObject(''); // diisi setelah kids diketahui
  const catalogNumber = addObject(
    `<< /Type /Catalog /Pages ${pagesNumber} 0 R >>`,
  );

  // objek Pages + perbaiki referensi parent
  const kids = pageNumbers.map((n) => `${n} 0 R`).join(' ');
  objects[
    pagesNumber - 1
  ] = `<< /Type /Pages /Kids [${kids}] /Count ${pages.length} >>`;
  pageNumbers.forEach((n) => {
    objects[n - 1] = objects[n - 1].replace('PAGES_REF', `${pagesNumber} 0 R`);
  });

  let file = '%PDF-1.4\n';
  const offsets = [];
  objects.forEach((body, i) => {
    offsets.push(file.length);
    file += `${i + 1} 0 obj\n${body}\nendobj\n`;
  });

  const xrefOffset = file.length;
  file += `xref\n0 ${objects.length + 1}\n`;
  file += '0000000000 65535 f \n';
  offsets.forEach((offset) => {
    file += `${String(offset).padStart(10, '0')} 00000 n \n`;
  });
  file += `trailer\n<< /Size ${
    objects.length + 1
  } /Root ${catalogNumber} 0 R >>\n`;
  file += `startxref\n${xrefOffset}\n%%EOF\n`;

  const bytes = new Uint8Array(file.length);
  for (let i = 0; i < file.length; i += 1) {
    bytes[i] = file.charCodeAt(i) & 0xff;
  }
  return bytes;
}

export default buildGroupedTablePdf;
