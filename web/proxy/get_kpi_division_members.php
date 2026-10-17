<?php
require_once __DIR__ . '/_config.php';
/**
 * get_kpi_division_members.php
 *
 * GET /proxy/get_kpi_division_members.php?emp=<adminEmpNumber>
 *
 * Mengembalikan data untuk halaman admin "Pengelolaan Member KPI":
 *   - members: seluruh isi ohrm_kpi_division_member (divisi, cabang, emp,
 *              aktif) digabung dengan nama karyawan.
 *   Catatan 2026-10-14: is_pic (PIC) DIHAPUS dari response — kolomnya tidak
 *   pernah dipakai sistem (scoping/approval pakai divisi+cabang+aktif).
 *   - locations: daftar cabang dari ohrm_location (jumlah == opsi cabang).
 *   - companies/divisions: daftar divisi yang tersedia (dari ohrm_kpi_setup
 *              aktif + konstanta COMPANY_DIVISIONS palsu? -> pakai setup aktif,
 *              dan tambahkan apapun yang sudah dipakai di member).
 *   - employees: daftar karyawan utk autocomplete (emp_number + nama).
 *
 * Admin-only: selain admin, kosongkan data.
 */
header('Content-Type: application/json');

$host = PROXY_DB_HOST; $dbname = PROXY_DB_NAME; $user = PROXY_DB_USER; $pass = PROXY_DB_PASS;
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'Koneksi Database Gagal: '.$e->getMessage()]); exit;
}

$emp = isset($_GET['emp']) ? (int)$_GET['emp'] : 0;

try {
    // Admin-only
    $isAdmin = false;
    if ($emp > 0) {
        $st = $pdo->prepare("SELECT user_role_id FROM ohrm_user WHERE emp_number = ? AND deleted = 0 LIMIT 1");
        $st->execute([$emp]);
        $isAdmin = ((int)$st->fetchColumn() === 1);
    }
    if (!$isAdmin) {
        echo json_encode(['status'=>'success','data'=>['is_admin'=>false,'members'=>[],'locations'=>[],'divisions'=>[],'employees'=>[]]]); exit;
    }

    // Members
    $members = [];
    $st = $pdo->query(
        "SELECT m.id, m.divisi, m.cabang, m.emp_number, m.aktif,
                COALESCE(e.emp_firstname,'') f, COALESCE(e.emp_lastname,'') l
         FROM ohrm_kpi_division_member m
         LEFT JOIN hs_hr_employee e ON e.emp_number = m.emp_number
         ORDER BY m.divisi, m.cabang, m.emp_number"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $members[] = [
            'id'       => (int)$r['id'],
            'divisi'   => $r['divisi'],
            'cabang'   => $r['cabang'] !== null ? $r['cabang'] : null,
            'emp_number'=> (int)$r['emp_number'],
            'aktif'    => (int)$r['aktif'],
            'emp_name' => trim($r['f'] . ' ' . $r['l']),
            'all_cabang' => $r['cabang'] === null,
        ];
    }

    // Locations (cabang)
    $locations = [];
    foreach ($pdo->query("SELECT id, name FROM ohrm_location ORDER BY name") as $r)
        $locations[] = ['id'=>(int)$r['id'], 'name'=>$r['name']];

    // Divisions: distinct dari setup aktif + yang pernah dipakai member
    $divisions = [];
    foreach ($pdo->query("SELECT DISTINCT divisi FROM ohrm_kpi_setup WHERE aktif = 1 ORDER BY divisi") as $r)
        $divisions[] = $r['divisi'];
    foreach ($pdo->query("SELECT DISTINCT divisi FROM ohrm_kpi_division_member ORDER BY divisi") as $r)
        if (!in_array($r['divisi'], $divisions, true)) $divisions[] = $r['divisi'];

    // Employees (untuk autocomplete / select)
    $employees = [];
    $st = $pdo->query(
        "SELECT emp_number, emp_firstname, emp_lastname, emp_nick_name
         FROM hs_hr_employee
         WHERE emp_number IS NOT NULL
         ORDER BY emp_firstname, emp_lastname"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $employees[] = [
            'emp_number' => (int)$r['emp_number'],
            'label'      => trim($r['emp_firstname'] . ' ' . $r['emp_lastname']),
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data'   => [
            'is_admin'   => true,
            'members'    => $members,
            'locations'  => $locations,
            'divisions'  => $divisions,
            'employees'  => $employees,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Gagal mengambil data member: '.$e->getMessage()]);
}