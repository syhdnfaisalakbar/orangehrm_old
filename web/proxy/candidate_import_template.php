<?php
/**
 * candidate_import_template.php — unduh template Excel upload kandidat.
 *
 * GET (tanpa parameter). Isi file:
 *   Sheet 1 "Kandidat"       : header + 2 baris contoh
 *   Sheet 2 "Petunjuk"       : aturan pengisian per kolom
 *   Sheet 3 "Daftar Vacancy" : Case ID + nama vacancy yang ada di database
 *                              (supaya bisa copy-paste ke kolom Vacancy)
 *
 * Template dibuat dinamis agar daftar vacancy selalu mengikuti data terbaru.
 */

require_once __DIR__ . '/_config.php';
require_once __DIR__ . '/_xlsx_writer.php';
require_once __DIR__ . '/_candidate_import_lib.php';

$fail = static function (string $message, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
};

// ---------------------------------------------------------------- data vacancy
$vacancyRows = [['Case ID', 'Vacancy', 'Status']];
try {
    $pdo = candidate_import_pdo();
    foreach (candidate_import_fetch_vacancies($pdo) as $v) {
        $status = $v['status'] === 1 ? 'Active' : 'Closed';
        if ($v['rejected'] === 1) {
            $status = 'Rejected';
        }
        $vacancyRows[] = [$v['case_id'] !== '' ? $v['case_id'] : (string) $v['id'], $v['name'], $status];
    }
} catch (Throwable $e) {
    $vacancyRows[] = ['-', 'Gagal memuat daftar vacancy: ' . $e->getMessage(), '-'];
}

// ---------------------------------------------------------------- sheet kandidat
$headers = [
    'First Name', 'Middle Name', 'Last Name', 'Email', 'Contact Number',
    'Vacancy', 'Date of Application', 'Notes', 'Consent to Keep Data', 'Resume',
];

$contoh = [
    [
        'Budi', 'Santoso', 'Pratama', 'budi.pratama@example.com', '081234567890',
        '0000008722', date('Y-m-d'), 'Melamar via iklan jobstreet', 'Yes',
        'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz/view',
    ],
    [
        'Siti', '', 'Aminah', 'siti.aminah@example.com', '',
        '0000008722 - Business Application Staff', '', '', 'No', '',
    ],
];

// ---------------------------------------------------------------- sheet petunjuk
$petunjuk = [
    ['Kolom', 'Wajib?', 'Keterangan'],
    ['First Name', 'Wajib', 'Maksimal 30 karakter.'],
    ['Middle Name', 'Opsional', 'Maksimal 30 karakter, boleh dikosongkan.'],
    ['Last Name', 'Wajib', 'Maksimal 30 karakter.'],
    ['Email', 'Wajib', 'Harus email valid, maksimal 100 karakter. Dipakai untuk mendeteksi duplikat.'],
    ['Contact Number', 'Opsional', 'Nomor HP/telepon, maksimal 30 karakter.'],
    ['Vacancy', 'Wajib', 'Isi Case ID (contoh 0000008722) ATAU nama vacancy persis seperti di sheet "Daftar Vacancy" ATAU ID vacancy.'],
    ['Date of Application', 'Opsional', 'Format YYYY-MM-DD (contoh 2026-11-12). Kalau kosong dipakai tanggal hari ini.'],
    ['Notes', 'Opsional', 'Catatan/komentar bebas, ikut tersimpan di profil kandidat.'],
    ['Consent to Keep Data', 'Opsional', 'Isi Yes atau No. Kalau kosong dianggap No.'],
    ['Resume', 'Opsional', 'Link file CV (http/https). Google Drive: set akses "Anyone with the link". Link akan diunduh lalu disimpan sebagai lampiran kandidat.'],
    ['', '', ''],
    ['Aturan penting', '', ''],
    ['1', '', 'Baris pertama HARUS header (jangan dihapus/di-rename). Data mulai baris 2.'],
    ['2', '', 'Satu baris = satu kandidat untuk satu vacancy.'],
    ['3', '', 'Email yang sudah pernah melamar di vacancy yang sama akan DILEWATI (tidak dobel), dicatat di ringkasan.'],
    ['4', '', 'Baris yang datanya tidak valid tidak disimpan dan ditampilkan alasannya di pratinjau.'],
    ['5', '', 'Maksimal 2000 baris per upload. Format file .xlsx (bukan .xls).'],
    ['6', '', 'Setelah file diunggah, akan muncul pratinjau per baris. Klik Simpan untuk memasukkan ke sistem.'],
];

$bin = xlsx_build([
    ['name' => 'Kandidat', 'cols' => [18, 16, 18, 30, 18, 42, 18, 34, 20, 46], 'rows' => array_merge([$headers], $contoh)],
    ['name' => 'Petunjuk', 'cols' => [26, 12, 110], 'rows' => $petunjuk],
    ['name' => 'Daftar Vacancy', 'cols' => [16, 60, 12], 'rows' => $vacancyRows],
]);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_upload_kandidat.xlsx"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: no-store');
echo $bin;
