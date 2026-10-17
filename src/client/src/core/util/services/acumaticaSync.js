/**
 * Sync application data ke Acumatica Case (Description field, tab Details)
 * Dipanggil setelah aksi Recruitment (save, shortlist, interview, hire, dll)
 *
 * @param {boolean} [awaitResult=false] - Jika true, return promise dengan hasil sync
 * @param {number} [candidateId] - Opsional: id candidate yang di-sync. Dikirim di body
 *        supaya backend bisa resolve vacancy_id-nya → auto-close HIRED jalan dari Hire.
 * @returns {Promise<object>|void}
 */
export function syncToAcumatica(awaitResult, candidateId) {
  try {
    const base = window.appGlobal?.baseUrl || '';
    const baseUrl = base.replace('/index.php', '') || '';
    const url = baseUrl + '/proxy/sync_application_to_acumatica.php';
    const payload = candidateId
      ? JSON.stringify({candidate_id: candidateId})
      : '{}';

    if (awaitResult) {
      // Pakai fetch agar bisa dapet response
      return fetch(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: payload,
      }).then((r) => r.json());
    }

    // Default: pakai sendBeacon (fire-and-forget, untuk trigger otomatis)
    const blob = new Blob([payload], {type: 'application/json'});
    navigator.sendBeacon(url, blob);
  } catch (e) {
    // silent
  }
}
