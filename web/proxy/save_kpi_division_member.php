<?php
require_once __DIR__ . '/_config.php';
/**
 * save_kpi_division_member.php
 *
 * POST /proxy/save_kpi_division_member.php
 * Body: { emp, id?, divisi, cabang?, emp_number }
 * 2026-10-14: is_pic dihapus dari payload (PIC tidak dipakai). Kolom DB
 * `is_pic` TETAP ada tapi tidak lagi ditulis (EDIT tidak menyentuhnya →
 * nilai lama terjaga; INSERT pakai DEFAULT 0).
 *
 * Tambah / edit mapping member-ke-divisi(+cabang). NULL/'' cabang = 'Semua
 * Cabang' utk divisi itu. Admin-only. Unique (emp_number, divisi, cabang) —
 * insert baru (tanpa id) akan gagal bila duplikat (dijaga di backend).
 *
 * Cabang '' (kosong) diperlakukan sebagai NULL (semua cabang).
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST; $dbname = PROXY_DB_NAME; $user = PROXY_DB_USER; $pass = PROXY_DB_PASS;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'Koneksi Database Gagal: '.$e->getMessage()]); exit;
}

$raw = file_get_contents('php://input');
$d = json_decode($raw, true);
if (!is_array($d)) {
    echo json_encode(['status'=>'error','message'=>'Format data salah.']); exit;
}
$emp       = (int)($d['emp'] ?? 0);
$id        = (int)($d['id'] ?? 0);
$divisi    = trim((string)($d['divisi'] ?? ''));
$cabang    = trim((string)($d['cabang'] ?? ''));
$empNumber = (int)($d['emp_number'] ?? 0);

// '' => NULL = semua cabang
if ($cabang === '') $cabang = null;

if ($divisi === '' || $empNumber <= 0) {
    echo json_encode(['status'=>'error','message'=>'Divisi dan Emp Number wajib diisi.']); exit;
}

try {
    // Admin-only
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    if (!$isAdmin) {
        echo json_encode(['status'=>'error','message'=>'Anda tidak berhak mengelola member KPI.']); exit;
    }

    if ($id > 0) {
        // EDIT: update mapping ini
        $upd = $pdo->prepare(
            "UPDATE ohrm_kpi_division_member
             SET divisi = :divisi, cabang = :cabang, emp_number = :emp
             WHERE id = :id"
        );
        $upd->execute([
            ':divisi' => $divisi,
            ':cabang' => $cabang,
            ':emp'    => $empNumber,
            ':id'     => $id,
        ]);
        echo json_encode(['status'=>'success','message'=>'Mapping member diperbarui.','data'=>['id'=>$id]]);
    } else {
        // ADD: cegah duplikasi (emp_number, divisi, cabang)
        $chk = $pdo->prepare(
            "SELECT id FROM ohrm_kpi_division_member
             WHERE emp_number = :emp AND divisi = :divisi
               AND ((:cabang IS NULL AND cabang IS NULL) OR cabang = :cabang)
             LIMIT 1"
        );
        $chk->execute([':emp'=>$empNumber, ':divisi'=>$divisi, ':cabang'=>$cabang]);
        if ($chk->fetchColumn()) {
            echo json_encode(['status'=>'error','message'=>'Mapping sudah ada (emp + divisi + cabang tersebut).']); exit;
        }
        $ins = $pdo->prepare(
            "INSERT INTO ohrm_kpi_division_member (divisi, cabang, emp_number)
             VALUES (:divisi, :cabang, :emp)"
        );
        $ins->execute([
            ':divisi' => $divisi,
            ':cabang' => $cabang,
            ':emp'    => $empNumber,
        ]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['status'=>'success','message'=>'Mapping member ditambahkan.','data'=>['id'=>$newId]]);
    }
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal menyimpan member: '.$e->getMessage()]);
}