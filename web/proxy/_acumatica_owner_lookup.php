<?php
/**
 * _acumatica_owner_lookup.php — penerjemah OwnerID Acumatica -> nama user.
 *
 * LATAR MASALAH
 *   Approval task di Acumatica = CRActivity. Field yang dibaca aplikasi adalah
 *   Owner (di database Acumatica: CRActivity.OwnerID). Isinya ID user, BUKAN
 *   nama; contoh nyata: case 0000008730 punya task "Approval" dengan Owner
 *   "202263", sehingga kolom Owner di tabel FPTK menampilkan angka.
 *
 *   API kontrak Default/25.200.001 TIDAK mengekspos:
 *     - field nama pada Task/Activity ($select=OwnerEmployeeName -> 500,
 *       $expand=Owner -> "not a navigation property"),
 *     - entity Users/User/UserInfo (HTTP 404).
 *   Entity Case punya OwnerEmployeeName, tapi itu nama owner CASE (bukan owner
 *   task) dan hanya bisa dipinjam kalau ID tersebut kebetulan memiliki Case.
 *
 * SOLUSI (dipilih Pak 2026-11-25): buat Acumatica menyediakan sumbernya.
 *   IT/Acumatica membuat entity atau Generic Inquiry yang mengembalikan
 *   pasangan (ID owner, nama), lalu di web/proxy/config.php diisi:
 *       'acumatica' => ['owner_lookup' => [
 *           'entity'     => 'Users',        // nama entity / GI
 *           'id_field'   => 'OwnerID',
 *           'name_field' => 'DisplayName',
 *       ]],
 *   Nama di-cache ke tabel ohrm_acumatica_owner_name lewat
 *   web/proxy/sync_acumatica_owner_names.php (atau otomatis di akhir sync
 *   manpower terjadwal). Pembacaan tabel FPTK memakai cache itu.
 *
 *   Entity kosong = fitur mati, perilaku lama (ID apa adanya).
 */

require_once __DIR__ . '/_config.php';

if (!function_exists('acumaticaOwnerLookupEnabled')) {
    function acumaticaOwnerLookupEnabled(): bool
    {
        return defined('ACUMATICA_OWNER_LOOKUP_ENTITY')
            && trim((string)ACUMATICA_OWNER_LOOKUP_ENTITY) !== '';
    }

    /** Pastikan tabel cache ada (idempotent, tanpa ALTER). */
    function acumaticaOwnerNameEnsureTable(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `ohrm_acumatica_owner_name` (
                `owner_id`   VARCHAR(32)  NOT NULL,
                `owner_name` VARCHAR(150) NOT NULL,
                `source`     VARCHAR(20)  NOT NULL DEFAULT 'acumatica',
                `updated_at` DATETIME     NULL,
                PRIMARY KEY (`owner_id`),
                KEY `idx_owner_name` (`owner_name`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    }

    /**
     * Nama owner dari cache lokal. null = belum diketahui.
     * Dipakai saat MENAMPILKAN (get_manpower_requests.php) dan saat MENYIMPAN
     * hasil sync (save_manpower.php::resolveTaskOwnerName).
     */
    function acumaticaOwnerNameCached(?PDO $pdo, $ownerId): ?string
    {
        if (!$pdo instanceof PDO) {
            return null;
        }
        $id = trim((string)$ownerId);
        if ($id === '') {
            return null;
        }
        try {
            $st = $pdo->prepare(
                "SELECT owner_name FROM ohrm_acumatica_owner_name WHERE owner_id = :oid LIMIT 1"
            );
            $st->execute([':oid' => $id]);
            $nm = $st->fetchColumn();
            if (is_string($nm) && trim($nm) !== '') {
                return trim($nm);
            }
        } catch (Throwable $e) {
            // tabel belum ada -> anggap belum diketahui
        }
        return null;
    }

    /** Ambil nilai skalar dari bentuk Acumatica {value: ...} atau skalar biasa. */
    function acumaticaOwnerFieldValue(array $row, string $field): string
    {
        if (!isset($row[$field])) {
            return '';
        }
        $v = $row[$field];
        if (is_array($v)) {
            $v = $v['value'] ?? null;
        }
        return is_scalar($v) ? trim((string)$v) : '';
    }

    /**
     * Tarik daftar (OwnerID, nama) dari entity/GI yang dikonfigurasi lalu simpan
     * ke cache. Aman dipanggil walau fitur belum dikonfigurasi (return disabled).
     *
     * @param string|null $cookie Cookie header Acumatica yang sudah login.
     *                            null = login sendiri (pakai/acumaticaActivitySession).
     * @return array{status:string,entity:string,rows:int,http:int,message:string}
     */
    function acumaticaOwnerNamesSync(PDO $pdo, ?string $cookie = null): array
    {
        $entityName = trim((string)(defined('ACUMATICA_OWNER_LOOKUP_ENTITY') ? ACUMATICA_OWNER_LOOKUP_ENTITY : ''));
        if ($entityName === '') {
            return [
                'status'  => 'disabled',
                'entity'  => '',
                'rows'    => 0,
                'http'    => 0,
                'message' => 'owner_lookup.entity belum diisi di config.php — fitur nama owner belum aktif.',
            ];
        }
        $idField   = trim((string)(defined('ACUMATICA_OWNER_LOOKUP_ID_FIELD') ? ACUMATICA_OWNER_LOOKUP_ID_FIELD : ''));
        $nameField = trim((string)(defined('ACUMATICA_OWNER_LOOKUP_NAME_FIELD') ? ACUMATICA_OWNER_LOOKUP_NAME_FIELD : ''));

        $base = rtrim((string)ACUMATICA_BASE_URL, '/');
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'http://' . $base;
        }
        if ($base === '' || $base === 'http://') {
            return ['status' => 'error', 'entity' => $entityName, 'rows' => 0, 'http' => 0,
                    'message' => 'ACUMATICA_BASE_URL kosong.'];
        }
        $entityRoot = $base . '/entity/Default/25.200.001';

        require_once __DIR__ . '/_acumatica_activity.php';

        $loginNote = '';
        if ($cookie === null || trim($cookie) === '') {
            $sess = acumaticaActivitySession($base);
            if (empty($sess['ok'])) {
                return ['status' => 'error', 'entity' => $entityName, 'rows' => 0, 'http' => 0,
                        'message' => 'Login Acumatica gagal: ' . (string)($sess['message'] ?? '')];
            }
            $cookie = (string)$sess['cookie'];
            $loginNote = 'login=' . (string)($sess['source'] ?? '');
        }

        acumaticaOwnerNameEnsureTable($pdo);
        $upsert = $pdo->prepare(
            "INSERT INTO ohrm_acumatica_owner_name (owner_id, owner_name, source, updated_at)
             VALUES (:oid, :nm, 'acumatica', NOW())
             ON DUPLICATE KEY UPDATE owner_name = VALUES(owner_name), source = 'acumatica', updated_at = NOW()"
        );

        // --- Deteksi field otomatis (2026-11-25) -------------------------
        // Nama kolom pada entity/GI tidak selalu sama (OwnerID vs ContactID,
        // DisplayName vs FullName). Ambil 1 baris tanpa $select untuk melihat
        // kunci yang benar-benar tersedia, lalu pilih field ID & nama.
        $keys   = [];
        $sample = [];
        $probe  = acumaticaCurlJson(
            $entityRoot . '/' . rawurlencode($entityName) . '?$top=1',
            'GET', null, $cookie
        );
        $http = (int)$probe['http'];
        if ($http < 200 || $http >= 300) {
            return [
                'status'  => 'error',
                'entity'  => $entityName,
                'rows'    => 0,
                'http'    => $http,
                'message' => 'Entity "' . $entityName . '" tidak bisa dibaca (HTTP ' . $http . '): '
                    . substr(preg_replace('/\s+/', ' ', (string)$probe['body']), 0, 200)
                    . ' — pastikan GI/entity sudah ditambahkan ke Web Service Endpoint "Default" lalu Publish.',
                'detected' => ['id' => '', 'name' => '', 'keys' => []],
            ];
        }
        $pjson = json_decode($probe['body'], true);
        $prow  = [];
        if (is_array($pjson)) {
            if (isset($pjson['value'][0]) && is_array($pjson['value'][0])) {
                $prow = $pjson['value'][0];
            } elseif (isset($pjson[0]) && is_array($pjson[0])) {
                $prow = $pjson[0];
            }
        }
        foreach (array_keys($prow) as $k) {
            if (in_array($k, ['id', 'rowNumber', 'note', 'custom', '_links'], true)) {
                continue;
            }
            $keys[] = $k;
        }
        $pick = static function (array $available, array $candidates): string {
            $map = [];
            foreach ($available as $a) {
                $map[strtolower($a)] = $a;
            }
            foreach ($candidates as $c) {
                if (isset($map[strtolower($c)])) {
                    return $map[strtolower($c)];
                }
            }
            return '';
        };
        $fieldNote = '';
        if ($idField === '' || !in_array($idField, $keys, true)) {
            $auto = $pick($keys, ['OwnerID', 'ContactID', 'UserID', 'Username', 'EmployeeID', 'AcctCD']);
            if ($idField !== '' && $auto !== '') {
                $fieldNote .= ' id_field "' . $idField . '" tidak ada, pakai "' . $auto . '".';
            }
            $idField = $auto;
        }
        if ($nameField === '' || !in_array($nameField, $keys, true)) {
            $auto = $pick($keys, ['DisplayName', 'FullName', 'OwnerName', 'EmployeeName', 'Name', 'Username', 'Description']);
            if ($nameField !== '' && $auto !== '') {
                $fieldNote .= ' name_field "' . $nameField . '" tidak ada, pakai "' . $auto . '".';
            }
            $nameField = $auto;
        }
        if ($idField === '' || $nameField === '') {
            return [
                'status'  => 'error',
                'entity'  => $entityName,
                'rows'    => 0,
                'http'    => $http,
                'message' => 'Field ID/nama tidak ditemukan di entity "' . $entityName
                    . '". Kolom tersedia: ' . implode(', ', array_slice($keys, 0, 25))
                    . '. Isi id_field/name_field di config.php.',
                'detected' => ['id' => $idField, 'name' => $nameField, 'keys' => $keys],
            ];
        }
        // contoh 3 baris (untuk diagnosa cepat di respons endpoint)
        $pjson2 = json_decode($probe['body'], true);
        $prows  = [];
        if (is_array($pjson2)) {
            $prows = $pjson2['value'] ?? (array_is_list($pjson2) ? $pjson2 : []);
        }
        foreach (array_slice($prows, 0, 3) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $sample[] = [
                'id'   => acumaticaOwnerFieldValue($r, $idField),
                'name' => acumaticaOwnerFieldValue($r, $nameField),
            ];
        }

        $pageSize = 500;
        $maxRows  = 20000;
        $skip     = 0;
        $stored   = 0;
        $http     = 0;
        $seen     = 0;
        while ($skip < $maxRows) {
            $url = $entityRoot . '/' . rawurlencode($entityName)
                 . '?$select=' . rawurlencode($idField . ',' . $nameField)
                 . '&$top=' . $pageSize . '&$skip=' . $skip;
            $res = acumaticaCurlJson($url, 'GET', null, $cookie);
            $http = (int)$res['http'];
            if ($http < 200 || $http >= 300) {
                // Halaman pertama gagal = konfigurasi/entity salah. Halaman
                // berikutnya gagal = sudahi saja, data yang masuk tetap dipakai.
                if ($stored === 0) {
                    return ['status' => 'error', 'entity' => $entityName, 'rows' => 0, 'http' => $http,
                            'message' => 'Gagal membaca entity: HTTP ' . $http . ' '
                                . substr(preg_replace('/\s+/', ' ', (string)$res['body']), 0, 200)];
                }
                break;
            }
            $json = json_decode($res['body'], true);
            $rows = [];
            if (is_array($json)) {
                if (isset($json['value']) && is_array($json['value'])) {
                    $rows = $json['value'];
                } elseif (array_is_list($json)) {
                    $rows = $json;
                }
            }
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $oid = acumaticaOwnerFieldValue($row, $idField);
                $nm  = acumaticaOwnerFieldValue($row, $nameField);
                $seen++;
                if ($oid === '' || $nm === '') {
                    continue;
                }
                try {
                    $upsert->execute([':oid' => $oid, ':nm' => mb_substr($nm, 0, 150)]);
                    $stored++;
                } catch (Throwable $e) {
                    // baris bermasalah dilewati
                }
            }
            if (count($rows) < $pageSize) {
                break;
            }
            $skip += $pageSize;
        }

        return [
            'status'  => 'ok',
            'entity'  => $entityName,
            'rows'    => $stored,
            'http'    => $http,
            'message' => 'Cache nama owner diperbarui: ' . $stored . ' baris dari ' . $seen . ' baris entity ('
                . $idField . ' -> ' . $nameField . ')' . $fieldNote
                . ($loginNote !== '' ? ' | ' . $loginNote : ''),
            'detected' => ['id' => $idField, 'name' => $nameField, 'keys' => array_slice($keys, 0, 30)],
            'sample'   => $sample,
        ];
    }

    /** Kapan cache terakhir diperbarui (untuk diagnosa). */
    function acumaticaOwnerNamesSyncedAt(PDO $pdo): ?string
    {
        try {
            $v = $pdo->query("SELECT MAX(updated_at) FROM ohrm_acumatica_owner_name")->fetchColumn();
            return is_string($v) && $v !== '' ? $v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Terjemahkan OwnerID Acumatica -> nama. Satu pintu untuk semua pemanggil
     * (get_manpower_requests.php, save_manpower.php, scheduler).
     *
     * Urutan: cache ohrm_acumatica_owner_name (hasil harvest/GI) -> tabel mapping
     * karyawan ohrm_employee_acumatica_owner.
     *
     * PENTING: TIDAK ada fallback ke nama owner Case. Owner Task dan owner Case
     * adalah dua orang berbeda; fallback lama membuat task milik Indra Maulana
     * Rizky (202263) tampil sebagai NOVA SUSETYOHARSI. Kalau belum diketahui,
     * kembalikan null supaya pemanggil menampilkan ID apa adanya.
     *
     * @return string|null null = belum diketahui
     */
    function acumaticaOwnerNameResolve(?PDO $pdo, $ownerId): ?string
    {
        $id = trim((string)$ownerId);
        if ($id === '' || !preg_match('/^\d+$/', $id)) {
            return null;
        }
        $fromCache = acumaticaOwnerNameCached($pdo, $id);
        if ($fromCache !== null) {
            return $fromCache;
        }
        if (!$pdo instanceof PDO) {
            return null;
        }
        try {
            $st = $pdo->prepare(
                "SELECT owner_name FROM ohrm_employee_acumatica_owner WHERE owner_id = :oid LIMIT 1"
            );
            $st->execute([':oid' => $id]);
            $nm = $st->fetchColumn();
            if (is_string($nm) && trim($nm) !== '') {
                return trim($nm);
            }
        } catch (Throwable $e) {
            // tabel mapping belum ada
        }
        return null;
    }

    /**
     * Kumpulkan pasangan (OwnerID -> nama) dari entity yang MEMANG mengirim nama
     * owner: Case, Lead, Opportunity, Contact (semuanya punya OwnerEmployeeName).
     * Ini jalan tanpa IT/Acumatica karena entity Task hanya mengirim ID numerik
     * dan entity user (Users/User/EPEmployee) tidak dipublish di endpoint Default.
     *
     * Hasil ditulis ke ohrm_acumatica_owner_name dengan source='record'. Baris
     * source='manual' TIDAK pernah ditimpa.
     *
     * Throttle 12 jam (file _cache/owner_harvest.txt) supaya klik Sync di UI tidak
     * selalu membayar ~26 request. $force=true untuk endpoint manual/scheduler.
     *
     * @return array{status:string,rows:int,sources:array,message:string}
     */
    function acumaticaOwnerNamesHarvest(PDO $pdo, ?string $cookie = null, bool $force = false): array
    {
        $cacheDir = __DIR__ . '/_cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $marker = $cacheDir . '/owner_harvest.txt';
        if (!$force && is_file($marker) && (time() - (int)@file_get_contents($marker)) < 43200) {
            return [
                'status'  => 'throttled',
                'rows'    => 0,
                'sources' => [],
                'message' => 'Harvest nama owner dilewati: cache terakhir < 12 jam.',
            ];
        }

        $base = rtrim((string)ACUMATICA_BASE_URL, '/');
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'http://' . $base;
        }
        $entityRoot = $base . '/entity/Default/25.200.001';

        require_once __DIR__ . '/_acumatica_activity.php';

        $loginNote = '';
        if ($cookie === null || trim($cookie) === '') {
            $sess = acumaticaActivitySession($base);
            if (empty($sess['ok'])) {
                return ['status' => 'error', 'rows' => 0, 'sources' => [],
                        'message' => 'Login Acumatica gagal: ' . (string)($sess['message'] ?? '')];
            }
            $cookie = (string)$sess['cookie'];
            $loginNote = 'login=' . (!empty($sess['reused']) ? 'cache' : 'baru');
        }

        acumaticaOwnerNameEnsureTable($pdo);
        $upsert = $pdo->prepare(
            "INSERT INTO ohrm_acumatica_owner_name (owner_id, owner_name, source, updated_at)
             VALUES (:oid, :nm, 'record', NOW())
             ON DUPLICATE KEY UPDATE
                owner_name = IF(source = 'manual', owner_name, VALUES(owner_name)),
                source     = IF(source = 'manual', source, 'record'),
                updated_at = NOW()"
        );

        $sources = [];
        $stored = 0;
        foreach (['Case', 'Lead', 'Opportunity', 'Contact'] as $entity) {
            $skip = 0;
            $seen = 0;
            $pages = 0;
            while ($pages < 15) {
                $url = $entityRoot . '/' . $entity . '?' . http_build_query([
                    '$select' => 'Owner,OwnerEmployeeName',
                    '$top'    => 1000,
                    '$skip'   => $skip,
                ]);
                $res = acumaticaCurlJson($url, 'GET', null, $cookie);
                $http = (int)$res['http'];
                if ($http < 200 || $http >= 300) {
                    break;
                }
                $json = json_decode((string)$res['body'], true);
                $rows = [];
                if (is_array($json)) {
                    $rows = $json['value'] ?? (array_is_list($json) ? $json : []);
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $oid = acumaticaOwnerFieldValue($row, 'Owner');
                    $nm  = acumaticaOwnerFieldValue($row, 'OwnerEmployeeName');
                    if ($oid === '' || $nm === '' || !preg_match('/^\d+$/', $oid)) {
                        continue;
                    }
                    $seen++;
                    try {
                        $upsert->execute([':oid' => $oid, ':nm' => mb_substr($nm, 0, 150)]);
                        $stored++;
                    } catch (Throwable $e) {
                        // baris bermasalah dilewati
                    }
                }
                $pages++;
                if (count($rows) < 1000) {
                    break;
                }
                $skip += 1000;
            }
            $sources[$entity] = ['http' => $http ?? 0, 'rows' => $seen];
        }

        @file_put_contents($marker, (string)time());

        return [
            'status'  => 'ok',
            'rows'    => $stored,
            'sources' => $sources,
            'message' => 'Harvest nama owner: ' . $stored . ' pasangan ID->nama dari Case/Lead/Opportunity/Contact.'
                . ($loginNote !== '' ? ' | ' . $loginNote : ''),
        ];
    }
}
