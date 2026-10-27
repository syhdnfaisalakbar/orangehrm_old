<?php
/**
 * _quota_upload_lib.php — aturan parsing & validasi upload Excel kuota.
 *
 * Dipakai bersama oleh preview_quota_upload.php dan save_quota_upload.php supaya
 * tampilan pratinjau dan hasil simpan TIDAK mungkin berbeda aturan.
 *
 * Kolom Excel yang diharapkan (urutan bebas, nama case-insensitive):
 *   branch | department | planned | year
 *
 * Catatan kompatibilitas: kolom "total person" (nama template lama) tetap diterima
 * sebagai alias "planned" supaya file yang sudah beredar tidak langsung ditolak.
 * Di kode, nama kanonik yang dipakai adalah `planned`; `total_person` hanya alias
 * keluaran untuk bundle lama.
 */

/** Alias header yang diterima (sudah dinormalisasi: huruf kecil, tanpa spasi/simbol). */
const QUOTA_HEADER_ALIASES = [
    'branch' => ['branch', 'cabang'],
    'department' => ['department', 'dept', 'departemen', 'divisi'],
    'planned' => [
        'planned',          // template baru
        'plan',
        'kuota',
        'quota',
        'totalperson',      // template lama
        'totperson',
        'total',
        'jumlah',
        'totalorang',
    ],
    'year' => ['year', 'tahun'],
];

function quota_normalize_header(string $value): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($value));
}

/**
 * Cari baris header di 10 baris pertama.
 *
 * @return array{row:int, map:array<string,int>}|null
 */
function quota_detect_header(array $rows): ?array
{
    $searched = 0;
    foreach ($rows as $rowNumber => $cells) {
        $searched++;
        if ($searched > 10) {
            break;
        }
        $map = [];
        foreach ($cells as $colIdx => $raw) {
            $norm = quota_normalize_header((string) $raw);
            foreach (QUOTA_HEADER_ALIASES as $field => $aliases) {
                if (in_array($norm, $aliases, true) && !isset($map[$field])) {
                    $map[$field] = (int) $colIdx;
                }
            }
        }
        if (count($map) === count(QUOTA_HEADER_ALIASES)) {
            return ['row' => (int) $rowNumber, 'map' => $map];
        }
    }
    return null;
}

/** "1.000" -> 1000, " 5 " -> 5, "abc" -> null. */
function quota_parse_int(string $value): ?int
{
    $v = trim($value);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^-?\d+$/', $v)) {
        return (int) $v;
    }
    // pemisah ribuan gaya Indonesia (1.000 / 1,000) — bukan desimal
    if (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $v)) {
        return (int) str_replace(['.', ','], '', $v);
    }
    return null;
}

/**
 * Validasi satu baris data.
 *
 * @param array<string,int> $map       hasil quota_detect_header()
 * @param array<string,bool> $seen     kunci dept|year yang sudah muncul di file ini
 * @return array baris hasil (selalu ada status + message)
 */
function quota_validate_row(array $cells, array $map, int $rowNumber, array &$seen, int $maxYear): array
{
    $get = static function (string $field) use ($cells, $map): string {
        if (!isset($map[$field])) {
            return '';
        }
        return trim((string) ($cells[$map[$field]] ?? ''));
    };

    $branch = $get('branch');
    $department = $get('department');
    $yearRaw = $get('year');
    $totalRaw = $get('planned');

    $result = [
        'row' => $rowNumber,
        'branch' => $branch,
        'department' => $department,
        'year' => $yearRaw,
        'planned' => $totalRaw,
        'total_person' => $totalRaw, // alias nama lama (dipakai bundle UI sebelumnya)
        'status' => 'ok',
        'message' => '',
    ];

    $errors = [];
    if ($department === '') {
        $errors[] = 'department kosong';
    } elseif (mb_strlen($department) > 100) {
        $errors[] = 'department lebih dari 100 karakter';
    }
    if (mb_strlen($branch) > 100) {
        $errors[] = 'branch lebih dari 100 karakter';
    }

    $year = null;
    if (!preg_match('/^\d{4}$/', $yearRaw)) {
        $errors[] = 'year harus 4 digit (contoh 2026)';
    } else {
        $year = (int) $yearRaw;
        if ($year < 2020 || $year > $maxYear) {
            $errors[] = 'year di luar rentang 2020..' . $maxYear;
        }
    }

    $total = quota_parse_int($totalRaw);
    if ($total === null) {
        $errors[] = 'planned harus angka bulat';
    } elseif ($total < 0) {
        $errors[] = 'planned tidak boleh negatif';
    } elseif ($total > 100000) {
        $errors[] = 'planned tidak wajar (> 100000)';
    }

    if (!$errors && $year !== null && $total !== null) {
        $key = mb_strtolower($department) . '|' . $year;
        if (isset($seen[$key])) {
            $errors[] = 'duplikat department+tahun dengan baris ' . $seen[$key];
        } else {
            $seen[$key] = $rowNumber;
        }
    }

    if ($errors) {
        $result['status'] = 'error';
        $result['message'] = implode('; ', $errors);
    } else {
        // dikirim balik ke UI dalam bentuk sudah bersih
        $result['department'] = $department;
        $result['year'] = $year;
        $result['planned'] = $total;
        $result['total_person'] = $total; // alias nama lama
    }

    return $result;
}
