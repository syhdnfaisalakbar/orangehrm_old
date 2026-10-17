<?php
/**
 * upload_bukti.php
 *
 * POST (multipart/form-data):
 *   setup_id, divisi, periode, file  <-- $_FILES['file']
 *
 * Menyimpan file bukti (attachment) indikator KPI ke direktori
 *   web/uploads/bukti/<fn>
 * dan mengembalikan path relatif (dibangun frontend jadi URL:
 *   baseUrl + "/" + path  →  misal .../web/uploads/bukti/<fn>).
 * Respons: { status, data: { path } }
 */
header('Content-Type: application/json');

function fail($msg) { echo json_encode(['status' => 'error', 'message' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Gunakan POST.');

$setupId = isset($_POST['setup_id']) ? (int)$_POST['setup_id'] : 0;
$divisi  = isset($_POST['divisi']) ? trim((string)$_POST['divisi']) : '';
$periode = isset($_POST['periode']) ? trim((string)$_POST['periode']) : '';

if ($setupId <= 0 || $divisi === '' || $periode === '') fail('Parameter setup_id/divisi/periode wajib.');
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('Tidak ada file terupload.');

$f = $_FILES['file'];
// Ukuran maks 10 MB
if ($f['size'] > 10 * 1024 * 1024) fail('Ukuran file maksimal 10 MB.');

// Whitelist ekstensi
$allowed = ['pdf','png','jpg','jpeg','gif','xlsx','xls','docx','doc','csv','txt','pptx','ppt'];
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed, true)) fail('Tipe file tidak diizinkan. Gunakan: ' . implode(', ', $allowed));

// Sanitasi nama divisi utk folder (hanya a-z0-9_-)
$folder = preg_replace('/[^a-z0-9\-_]/i', '_', $divisi);
$folder = $folder === '' ? 'lampiran' : $folder;

$uploadDir = dirname(__DIR__) . '/uploads/bukti/' . $folder;
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) fail('Gagal membuat folder upload.');

$fn = 'kpi_' . $setupId . '_' . preg_replace('/[^0-9]/', '', $periode) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$dest = $uploadDir . '/' . $fn;

if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Gagal menyimpan file.');

echo json_encode([
    'status' => 'success',
    'data'   => ['path' => 'uploads/bukti/' . $folder . '/' . $fn],
]);