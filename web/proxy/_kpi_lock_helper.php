<?php
/**
 * _kpi_lock_helper.php — kunci bobot & target untuk bulan yang sudah lewat.
 *
 * Latar (permintaan Pak 2026-11-23): bobot/target disimpan di definisi indikator
 * (ohrm_kpi_setup) = satu nilai untuk semua bulan. Akibatnya mengubah bobot ikut
 * menghitung ulang KPI Ach bulan-bulan sebelumnya.
 *
 * Cara kerja: setiap kali bobot/target sebuah indikator DIUBAH, semua baris
 * realisasi dengan periode < bulan berjalan diisi snapshot NILAI LAMA
 * (bobot_snap / target_snap). Kolom itu hanya diisi kalau masih NULL, jadi
 * perubahan berikutnya tidak menimpa snapshot pertama (nilai saat itu memang
 * angka yang berlaku untuk bulan tersebut). Bulan berjalan dan bulan sesudahnya
 * dibiarkan NULL => memakai definisi terbaru.
 *
 * Dipakai oleh: update_setup_edit_request.php (approve pengajuan edit) dan
 * save_setup_kpi.php (admin mengubah definisi langsung).
 */

if (!function_exists('kpiLockPastPeriods')) {
    /**
     * @param PDO   $pdo
     * @param int   $setupId  id ohrm_kpi_setup
     * @param mixed $oldBobot bobot SEBELUM perubahan
     * @param mixed $oldTarget target SEBELUM perubahan
     * @return int jumlah baris realisasi yang dikunci
     */
    function kpiLockPastPeriods(PDO $pdo, int $setupId, $oldBobot, $oldTarget): int
    {
        if ($setupId <= 0) {
            return 0;
        }
        // Bulan berjalan dihitung dari waktu server (periode disimpan 'YYYY-MM').
        $curYm = date('Y-m');
        $st = $pdo->prepare(
            "UPDATE ohrm_kpi_realisasi
                SET bobot_snap  = COALESCE(bobot_snap, :b),
                    target_snap = COALESCE(target_snap, :t)
              WHERE setup_id = :sid
                AND periode < :cur"
        );
        $st->execute([
            ':b'   => $oldBobot,
            ':t'   => $oldTarget,
            ':sid' => $setupId,
            ':cur' => $curYm,
        ]);
        return $st->rowCount();
    }

    /**
     * Kunci hanya jika bobot atau target benar-benar berubah.
     * @return int jumlah baris yang dikunci (0 kalau tidak ada perubahan)
     */
    function kpiLockIfChanged(PDO $pdo, int $setupId, $oldBobot, $oldTarget, $newBobot, $newTarget): int
    {
        $sameBobot  = ($oldBobot !== null && $newBobot !== null && (float)$oldBobot === (float)$newBobot);
        $sameTarget = ($oldTarget !== null && $newTarget !== null && (float)$oldTarget === (float)$newTarget);
        if ($sameBobot && $sameTarget) {
            return 0;
        }
        return kpiLockPastPeriods($pdo, $setupId, $oldBobot, $oldTarget);
    }
}
