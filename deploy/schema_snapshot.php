<?php
/**
 * schema_snapshot.php — snapshot skema DB (tabel + kolom) dalam format deterministik
 * untuk dibandingkan antara DB lokal dan DB produksi.
 *
 * Tujuan: sebelum deploy kode ke prod, pastikan skema prod SUDAH cocok dengan
 * kode baru (tabel & kolom custom). Output di-diff, bukan di-apply.
 *
 * Pemakaian (di server prod / lokal):
 *   php deploy/schema_snapshot.php --host=127.0.0.1 --port=3306 --db=orangehrm \
 *       --user=orangehrm --pass=xxx > schema_prod.txt
 *
 * Atau lewat env supaya password tidak tampil di history:
 *   OHRM_DB_PASS=xxx php deploy/schema_snapshot.php --db=orangehrm > schema_prod.txt
 *
 * Output: satu baris per kolom => TABEL \t KOLOM \t TIPE \t NULLABLE \t DEFAULT
 */
$opts = getopt('', ['host::', 'port::', 'db::', 'user::', 'pass::']);
$host = $opts['host'] ?? getenv('OHRM_DB_HOST') ?: '127.0.0.1';
$port = $opts['port'] ?? getenv('OHRM_DB_PORT') ?: '3306';
$db   = $opts['db']   ?? getenv('OHRM_DB_NAME') ?: 'orangehrm';
$user = $opts['user'] ?? getenv('OHRM_DB_USER') ?: 'orangehrm';
$pass = $opts['pass'] ?? getenv('OHRM_DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "CONNECT FAIL: " . $e->getMessage() . "\n");
    exit(1);
}

$sql = "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
               COALESCE(COLUMN_DEFAULT, 'NULL') AS COL_DEFAULT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = :db
        ORDER BY TABLE_NAME, COLUMN_NAME";
$st = $pdo->prepare($sql);
$st->execute([':db' => $db]);

foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['TABLE_NAME'], "\t", $r['COLUMN_NAME'], "\t", $r['COLUMN_TYPE'],
        "\t", $r['IS_NULLABLE'], "\t", $r['COL_DEFAULT'], "\n";
}
