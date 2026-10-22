/**
 * Penamaan divisi untuk TAMPILAN.
 *
 * Nilai yang disimpan di database tetap KODE (FNA, PM, SAD, ...) supaya data
 * lama, filter, dan mapping user tidak berubah. Hanya label yang dibaca user
 * yang diganti nama panjang.
 *
 * Dipakai bersama oleh: Input Realisasi KPI, Dashboard Divisi, Division Detail.
 */

export const DIVISION_DISPLAY = {
  ADO: 'ADO',
  'After Sales': 'After Sales',
  FNA: 'Finance & Accounting',
  'HRGA & Lgl': 'HRGA & Legal',
  IT: 'IT',
  LND: 'Logistic & Distribution',
  Marcomm: 'Marcomm',
  NPD: 'New Product Dev',
  OPR: 'Operation',
  PMO: 'PMO',
  PPIC: 'Supply Chain',
  PUR: 'Purchasing',
  QM: 'Quality Management',
  RTL: 'Homestory',
  Sales: 'Sales',
  Showroom: 'Showroom',
  SAD: 'Sales Admin',
  TAX: 'TAX',
  PM: 'Product Management',
};

// Kode divisi -> nama tampilan. Kode yang tidak ada di peta dikembalikan apa adanya.
export function divisiName(code) {
  const key = String(code === null || code === undefined ? '' : code).trim();
  return DIVISION_DISPLAY[key] || key;
}

// Opsi dropdown / string -> kode divisi (nilai yang disimpan di DB).
export function divisiCode(opt) {
  if (!opt) return '';
  if (typeof opt === 'object') return opt.code || opt.id || opt.label || '';
  return String(opt);
}
